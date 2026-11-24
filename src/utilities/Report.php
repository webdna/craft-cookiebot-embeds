<?php

namespace webdna\cookiebotembeds\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use craft\models\Site;
use DateTime;
use DateTimeZone;
use webdna\cookiebotembeds\records\Sighting;
use webdna\cookiebotembeds\services\Log;

/**
 * The report (AC-7, BR-25): which providers the plugin has seen on each site and what it did with
 * them. Visible to users with access to this utility (admins by default); Craft's utilities page
 * enforces that.
 */
class Report extends Utility
{
    public static function displayName(): string
    {
        return Craft::t('cookiebot-embeds', 'Cookiebot Embeds');
    }

    public static function id(): string
    {
        return 'cookiebot-embeds';
    }

    public static function icon(): ?string
    {
        return dirname(__DIR__) . '/icon-mask.svg';
    }

    public static function contentHtml(): string
    {
        $sites = Craft::$app->getSites()->getAllSites(true);
        $siteNames = [];
        $siteUrls = [];
        foreach ($sites as $site) {
            /** @var Site $site */
            $siteNames[(int) $site->id] = $site->getName();
            $siteUrls[(int) $site->id] = $site->getBaseUrl();
        }

        $handle = Craft::$app->getRequest()->getQueryParam('site');
        $selected = is_string($handle) && $handle !== '' ? Craft::$app->getSites()->getSiteByHandle($handle, true) : null;

        $query = (new Query())
            ->from(Sighting::TABLE)
            ->orderBy(['siteId' => SORT_ASC, 'host' => SORT_ASC, 'action' => SORT_ASC, 'kind' => SORT_ASC]);
        if ($selected !== null) {
            $query->where(['siteId' => $selected->id]);
        }
        /** @var list<array{siteId: int|string, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string, dateUpdated: string}> $rows */
        $rows = $query->all();

        return Craft::$app->getView()->renderTemplate('cookiebot-embeds/_utility', [
            'rows' => self::present($rows, $siteNames, $siteUrls),
            'sites' => $sites,
            'selectedSite' => $selected?->handle,
            'multiSite' => count($sites) > 1,
        ]);
    }

    /**
     * The table's rows: each database row with its site's name, a link to its example page and,
     * for a host listed nowhere, the config lines that would allow or name it. Pure.
     *
     * @param list<array{siteId: int|string, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string, dateUpdated: string}> $rows
     * @param array<int, string> $siteNames Site ID => name
     * @param array<int, string|null> $siteUrls Site ID => base URL
     * @return list<array{site: string, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string, url: string|null, lastSeen: DateTime, unknown: bool, configLines: list<string>}>
     */
    public static function present(array $rows, array $siteNames, array $siteUrls): array
    {
        $presented = [];
        foreach ($rows as $row) {
            $siteId = (int) $row['siteId'];
            $baseUrl = $siteUrls[$siteId] ?? null;
            $unknown = Log::isUnknown($row);

            $presented[] = [
                'site' => $siteNames[$siteId] ?? (string) $siteId,
                'host' => $row['host'],
                'vendor' => $row['vendor'],
                'category' => $row['category'],
                'action' => $row['action'],
                'kind' => $row['kind'],
                'examplePath' => $row['examplePath'],
                'url' => $baseUrl === null || $baseUrl === '' ? null : rtrim($baseUrl, '/') . $row['examplePath'],
                // Stored in UTC; the template's date filter shows it in the system time zone.
                'lastSeen' => new DateTime($row['dateUpdated'], new DateTimeZone('UTC')),
                'unknown' => $unknown,
                'configLines' => $unknown ? self::configLines($row['host'], $row['category']) : [],
            ];
        }
        return $presented;
    }

    /**
     * The two ways to deal with an unknown host in `config/cookiebot-embeds.php`: allow it (never
     * gated) or name it under `vendors` with its category.
     *
     * @return list<string>
     */
    public static function configLines(string $host, string $category): array
    {
        $quoted = var_export($host, true);
        return [
            "'allow' => [$quoted],",
            "'vendors' => [['match' => $quoted, 'vendor' => 'Provider name', 'category' => " . var_export($category, true) . ']],',
        ];
    }
}
