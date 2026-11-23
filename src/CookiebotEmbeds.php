<?php

namespace webdna\cookiebotembeds;

use craft\base\Plugin;

/**
 * Cookiebot Embeds: third-party embeds and scripts wait for the visitor's Cookiebot consent.
 *
 * Configuration comes only from `config/cookiebot-embeds.php`; there is no CP settings page
 * (BR-27). The render hook arrives in task 3.1.
 */
class CookiebotEmbeds extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public function init(): void
    {
        parent::init();
    }
}
