<?php

namespace webdna\cookiebotembeds;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\models\Site;
use Throwable;
use webdna\cookiebotembeds\models\Settings;
use webdna\cookiebotembeds\models\SiteConfig;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Vendors;

/**
 * Cookiebot Embeds: third-party embeds and scripts wait for the visitor's Cookiebot consent.
 *
 * Configuration comes only from `config/cookiebot-embeds.php`; there is no CP settings page
 * (BR-27). The render hook arrives in task 3.1.
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

    public function init(): void
    {
        parent::init();
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
                Craft::error($warning, self::LOG_CATEGORY);
            }
        }
        return $this->vendors;
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
            Craft::error('Config could not be resolved, using the defaults: ' . $e->getMessage(), self::LOG_CATEGORY);
            return new SiteConfig();
        }

        foreach ($config->warnings as $warning) {
            Craft::warning("Site `$site->handle`: $warning", self::LOG_CATEGORY);
        }
        return $config;
    }
}
