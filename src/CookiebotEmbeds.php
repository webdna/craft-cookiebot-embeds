<?php

namespace webdna\cookiebotembeds;

use Craft;
use craft\base\FsInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\TemplateEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use craft\log\MonologTarget;
use craft\models\Site;
use craft\services\Gc;
use craft\services\Utilities;
use craft\web\Request as WebRequest;
use craft\web\Response as WebResponse;
use craft\web\View;
use DateTimeImmutable;
use Psr\Log\LogLevel;
use Throwable;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\models\Settings;
use webdna\cookiebotembeds\models\SiteConfig;
use webdna\cookiebotembeds\records\Sighting;
use webdna\cookiebotembeds\services\Banner;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Log;
use webdna\cookiebotembeds\services\PageFilter;
use webdna\cookiebotembeds\services\Placeholder;
use webdna\cookiebotembeds\services\Report;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;
use webdna\cookiebotembeds\twig\Extension;
use webdna\cookiebotembeds\utilities\Report as ReportUtility;
use webdna\cookiebotembeds\web\assets\activator\ActivatorAsset;
use yii\base\Event;

/**
 * Cookiebot Embeds: third-party embeds and scripts wait for the visitor's Cookiebot consent.
 *
 * Configuration comes only from `config/cookiebot-embeds.php`; there is no CP settings page
 * (BR-27). The render hook rewrites site pages (BR-1).
 *
 * @method Settings getSettings()
 */
class CookiebotEmbeds extends Plugin
{
    /** The log category for everything the plugin logs (BR-29). */
    public const LOG_CATEGORY = 'cookiebot-embeds';

    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    /**
     * Each site's resolved config, resolved once per request so its warnings are logged once.
     *
     * @var array<string, SiteConfig>
     */
    private array $siteConfigs = [];

    private ?Vendors $vendors = null;

    /** @var list<string>|null Every site's base URL host, gathered once per request */
    private ?array $siteHosts = null;

    /** @var list<string>|null Every filesystem's public URL host, gathered once per request */
    private ?array $filesystemHosts = null;

    private ?Log $log = null;

    private ?Report $report = null;

    /** The report throttle's generation, read from the cache once per request */
    private ?string $reportGeneration = null;

    public function init(): void
    {
        parent::init();

        // BR-29: the plugin's own log file, at info, so unknown hosts are recorded even where
        // Craft's default target only keeps warnings (production).
        Craft::getLogger()->dispatcher->targets[self::LOG_CATEGORY] = new MonologTarget([
            'name' => self::LOG_CATEGORY,
            'categories' => [self::LOG_CATEGORY],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
        ]);

        // BR-26: garbage collection runs from web requests and from `craft gc`, so this is
        // registered before the console return below.
        Event::on(Gc::class, Gc::EVENT_RUN, function(): void {
            $this->pruneReport();
        });

        // BR-25: the report, behind Craft's per-utility permission (admins by default).
        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, function(RegisterComponentTypesEvent $event): void {
            $event->types[] = ReportUtility::class;
        });

        // BR-22: registered for console requests too, so a template rendered by a queue job (an
        // email, say) that uses the function still compiles.
        try {
            Craft::$app->getView()->registerTwigExtension(new Extension());
        } catch (Throwable $e) {
            $this->logError('The Twig extension could not be registered: ' . $e->getMessage());
        }

        // BR-1: console requests render no pages for visitors.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return;
        }

        // Fired after endPage(), so head and body scripts registered with the view (SEOmatic,
        // {% js %}) are already in the output.
        Event::on(View::class, View::EVENT_AFTER_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event): void {
            $event->output = $this->filterPage($event);
        });

        // BR-19: the activator must be in the head, which is already written by the time the
        // page is filtered, so it is registered before the page renders.
        Event::on(View::class, View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE, function(TemplateEvent $event): void {
            $this->registerActivator($event);
        });
    }

    /**
     * The request, when this page template is a site page (BR-1); otherwise null.
     */
    private function sitePageRequest(TemplateEvent $event): ?WebRequest
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof WebRequest || !PageFilter::isSitePage(
            false,
            $request->getIsCpRequest(),
            $request->getIsActionRequest(),
            $request->getIsPreview(),
            $event->templateMode,
        )) {
            return null;
        }
        return $request;
    }

    /**
     * BR-19: in Vue mode, the activator in the head of every site page. Never throws (B3); a page
     * without a head simply has nowhere to put it (TN-17).
     */
    private function registerActivator(TemplateEvent $event): void
    {
        try {
            if ($this->sitePageRequest($event) === null) {
                return;
            }
            $config = $this->getSiteConfig(Craft::$app->getSites()->getCurrentSite());
            if ($config->enabled && $config->mode === 'vue') {
                Craft::$app->getView()->registerAssetBundle(ActivatorAsset::class);
            }
        } catch (Throwable $e) {
            $this->logError('The Vue mode activator could not be registered: ' . $e->getMessage());
        }
    }

    /**
     * The render hook's work for one page template (BR-1, BR-2, BR-3, BR-5). Reads the request
     * only to decide whether this is a site page, never its cookies, headers or user (BR-3).
     * Never throws: on any failure the original output is served (B3).
     */
    private function filterPage(TemplateEvent $event): string
    {
        $output = $event->output;
        try {
            $request = $this->sitePageRequest($event);
            if ($request === null) {
                return $output;
            }

            // The cheap checks first, so a disabled site or a page with nothing to gate (and no
            // banner to add) costs no provider list, no services and no template lookup.
            $site = Craft::$app->getSites()->getCurrentSite();
            $config = $this->getSiteConfig($site);
            if (!$config->enabled || (!$config->injectCookiebot && !Rewriter::mightRewrite($output))) {
                return $output;
            }

            $response = Craft::$app->getResponse();
            $contentType = $response instanceof WebResponse ? $response->getHeaders()->get('content-type') : null;
            $templateFile = Craft::$app->getView()->resolveTemplate($event->template, $event->templateMode);
            if (!PageFilter::isHtml(is_string($contentType) ? $contentType : null, $templateFile === false ? null : $templateFile)) {
                return $output;
            }

            $result = PageFilter::filter(
                $output,
                $config,
                fn(): Rewriter => $this->createRewriter($config),
                fn(string $message) => $this->logError($message),
            );
        } catch (Throwable $e) {
            $this->logError('The page was served unchanged: ' . $e->getMessage());
            return $output;
        }

        $html = $result === null ? $output : $this->afterRewrite($result, $config, $site, $request);

        // BR-21: after the rewrite, so the rewriter never sees the plugin's own script. A failure
        // costs only the banner.
        try {
            $html = Banner::insert(
                $html,
                $config,
                function(string $message) use ($site): void {
                    $this->getLog()->once(Log::WARNING, "Site `$site->handle`: $message");
                },
            ) ?? $html;
        } catch (Throwable $e) {
            $this->logError('The Cookiebot banner could not be added: ' . $e->getMessage());
        }
        return $html;
    }

    /**
     * Logging and recording for a rewritten page (BR-23, BR-24, BR-29); the rewritten HTML.
     */
    private function afterRewrite(RewriteResult $result, SiteConfig $config, Site $site, WebRequest $request): string
    {
        // A logging failure must not cost the page its rewrite.
        try {
            $this->getLog()->unknownHosts($result, $site->handle);
        } catch (Throwable $e) {
            $this->logError('Unknown hosts could not be logged: ' . $e->getMessage());
        }

        // BR-23, BR-24: recording never costs the page its rewrite either. The path is the only
        // part of the request read, and only for the report's example page.
        if ($config->report) {
            try {
                $this->getReport()->record($result, (int) $site->id, $request->getFullPath());
            } catch (Throwable $e) {
                $this->logError('The report could not be recorded: ' . $e->getMessage());
            }
        }
        return $result->html;
    }

    /**
     * The report recorder (BR-23, BR-24): each row claimed for an hour through Craft's cache,
     * written in one upsert, failures logged through the throttled log.
     */
    public function getReport(): Report
    {
        return $this->report ??= new Report(
            claim: fn(string $key, int $ttl): bool => Craft::$app->getCache()->add(
                self::LOG_CATEGORY . ':report:' . $this->reportGeneration() . ':' . md5($key),
                1,
                $ttl,
            ),
            upsert: fn(array $rows) => $this->upsertSightings($rows),
            onError: fn(string $message) => $this->logError($message),
        );
    }

    /**
     * The throttle's generation (see Report::GENERATION_KEY); `0` until the report is first cleared.
     */
    private function reportGeneration(): string
    {
        if ($this->reportGeneration === null) {
            $generation = Craft::$app->getCache()->get(Report::GENERATION_KEY);
            $this->reportGeneration = is_scalar($generation) ? (string) $generation : '0';
        }
        return $this->reportGeneration;
    }

    /**
     * One multi-row upsert. Yii's `upsert()` takes a single row, so this is its batch insert plus
     * the driver's conflict clause. The SQL is set raw: the batch insert has already quoted its
     * values inline, and `quoteSql()` over them would rewrite any `{{ }}` or `[[ ]]` in a stored path.
     *
     * @param non-empty-list<array{siteId: int, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string}> $rows
     */
    private function upsertSightings(array $rows): void
    {
        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new DateTimeImmutable());
        $columns = [...array_keys($rows[0]), 'hits', 'dateCreated', 'dateUpdated', 'uid'];
        $values = array_map(
            fn(array $row): array => [...array_values($row), 1, $now, $now, StringHelper::UUID()],
            $rows,
        );

        $params = [];
        $table = $db->getSchema()->getRawTableName(Sighting::TABLE);
        $sql = $db->getQueryBuilder()->batchInsert($table, $columns, $values, $params)
            . $db->quoteSql(Report::onConflictSql($db->getIsMysql()));
        $db->createCommand()->setRawSql($sql)->bindValues($params)->execute();
    }

    /**
     * BR-26: deletes rows not seen for 30 days. Run by Craft's garbage collection; never throws.
     */
    private function pruneReport(): void
    {
        try {
            Db::delete(Sighting::TABLE, [
                '<', 'dateUpdated', Db::prepareDateForDb(Report::pruneBefore(new DateTimeImmutable())),
            ]);
        } catch (Throwable $e) {
            $this->logError('Old report rows could not be pruned: ' . $e->getMessage());
        }
    }

    /**
     * The plugin's throttled log (BR-29): each distinct line at most once per hour, claimed
     * through Craft's cache. Writes in the `cookiebot-embeds` category.
     */
    public function getLog(): Log
    {
        return $this->log ??= new Log(
            claim: fn(string $key, int $ttl): bool => Craft::$app->getCache()->add(self::LOG_CATEGORY . ':log:' . md5($key), 1, $ttl),
            write: function(string $level, string $message): void {
                match ($level) {
                    Log::ERROR => Craft::error($message, self::LOG_CATEGORY),
                    Log::WARNING => Craft::warning($message, self::LOG_CATEGORY),
                    default => Craft::info($message, self::LOG_CATEGORY),
                };
            },
        );
    }

    /**
     * An error through the throttled log, falling back to Craft's logger if even that fails.
     */
    private function logError(string $message): void
    {
        try {
            $this->getLog()->once(Log::ERROR, $message);
        } catch (Throwable) {
            Craft::error($message, self::LOG_CATEGORY);
        }
    }

    /**
     * The rewriter for one site's config, with the hosts BR-6 steps 1 and 2 treat as first-party.
     */
    private function createRewriter(SiteConfig $config): Rewriter
    {
        $vendors = $this->getVendors();
        return new Rewriter(
            new Classifier($config, $vendors, $this->siteHosts(), $this->filesystemHosts()),
            $vendors,
            $config,
            $this->createPlaceholder($config)->renderer(),
        );
    }

    /**
     * BR-6 step 1: every site's base URL host. A base URL built from `@web` takes the host Craft
     * resolved for the request; the plugin never reads the Host header itself (BR-3).
     *
     * @return list<string>
     */
    private function siteHosts(): array
    {
        return $this->siteHosts ??= PageFilter::hosts(array_map(
            fn(Site $site): ?string => $site->getBaseUrl(),
            Craft::$app->getSites()->getAllSites(true),
        ));
    }

    /**
     * BR-6 step 2: the hosts of every filesystem's public URL.
     *
     * @return list<string>
     */
    private function filesystemHosts(): array
    {
        return $this->filesystemHosts ??= PageFilter::hosts(array_map(
            fn(FsInterface $fs): ?string => $fs->getRootUrl(),
            Craft::$app->getFs()->getAllFilesystems(),
        ));
    }

    /**
     * The site's resolved configuration (BR-27). Never throws: if resolution itself fails, the
     * defaults are used, which gate every unknown third party (fail closed).
     */
    public function getSiteConfig(Site $site): SiteConfig
    {
        return $this->siteConfigs[$site->handle] ??= $this->resolveSiteConfig($site);
    }

    /**
     * The shipped provider list (BR-28), loaded once per request; its warnings are logged then.
     * A missing or broken file leaves the list empty, so every third party fails closed (TN-13).
     */
    public function getVendors(): Vendors
    {
        if ($this->vendors === null) {
            $this->vendors = new Vendors();
            foreach ($this->vendors->warnings() as $warning) {
                $this->logError($warning);
            }
        }
        return $this->vendors;
    }

    /**
     * The placeholder renderer for one site's config (BR-17): its `placeholderTemplate` through
     * Craft's view in site mode, otherwise the plugin's own template with Craft's translations.
     * Failures are logged, never thrown. The render hook (task 3.1) passes `renderer()` to the
     * rewriter.
     */
    public function createPlaceholder(SiteConfig $config): Placeholder
    {
        return new Placeholder(
            siteTemplate: $config->placeholderTemplate,
            renderSite: fn(string $template, array $variables): string => Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE),
            translate: fn(string $message, string $category, array $params): string => Craft::t($category, $message, $params),
            onError: fn(string $message) => $this->logError($message),
            cache: Craft::$app->getPath()->getCompiledTemplatesPath() . DIRECTORY_SEPARATOR . self::LOG_CATEGORY,
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    private function resolveSiteConfig(Site $site): SiteConfig
    {
        try {
            $handles = array_values(array_map(
                fn(Site $s): string => $s->handle,
                Craft::$app->getSites()->getAllSites(true),
            ));
            $config = (new Config())->resolve($this->getSettings()->toArray(), $site->handle, $handles);
        } catch (Throwable $e) {
            $this->logError('Config could not be resolved, using the defaults: ' . $e->getMessage());
            return new SiteConfig();
        }

        foreach ($config->warnings as $warning) {
            try {
                $this->getLog()->once(Log::WARNING, "Site `$site->handle`: $warning");
            } catch (Throwable) {
                Craft::warning("Site `$site->handle`: $warning", self::LOG_CATEGORY);
            }
        }
        return $config;
    }
}
