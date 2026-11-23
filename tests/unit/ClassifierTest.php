<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\cookiebotembeds\models\Classification;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The classifier: BR-6 order, BR-7 URL handling, BR-8 and BR-9, TN-8, TN-9 and TN-13.
 */
final class ClassifierTest extends TestCase
{
    private static function classifier(?Vendors $vendors = null): Classifier
    {
        $config = (new Config())->resolve([
            'allow' => ['widgets.example.org', 'example.net/booking/'],
            'vendors' => [
                ['match' => 'tracker.example.org', 'vendor' => 'Example tracker', 'category' => 'statistics'],
                // A site reclassifying a shipped provider wins over the shipped list (BR-6 step 4).
                ['match' => 'youtube.com', 'vendor' => 'YouTube, reclassified', 'category' => 'preferences'],
                // A site trying to gate Cookiebot and Tag Manager is ignored (BR-9, B3).
                ['match' => 'consent.cookiebot.com', 'vendor' => 'Cookiebot', 'category' => 'marketing'],
                ['match' => 'googletagmanager.com', 'vendor' => 'Tag Manager', 'category' => 'marketing'],
            ],
        ], 'default', ['default']);

        return new Classifier(
            $config,
            $vendors ?? new Vendors(),
            ['www.example.com', 'SHOP.example.co.uk:8443'],
            ['assets.example-cdn.net', 'Files.Example-Storage.net:443'],
        );
    }

    /**
     * @return array<string, array{string, string, string|null, string, string}>
     */
    public static function urls(): array
    {
        $first = Classification::SOURCE_FIRST_PARTY;
        return [
            // Relative and non-http URLs are first-party (BR-7).
            'root-relative' => ['/assets/app.js', '', null, 'necessary', $first],
            'relative' => ['assets/app.js', '', null, 'necessary', $first],
            'data:' => ['data:text/javascript,alert(1)', '', null, 'necessary', $first],
            'javascript:' => ['javascript:void(0)', '', null, 'necessary', $first],
            'about:blank' => ['about:blank', '', null, 'necessary', $first],
            'empty' => ['', '', null, 'necessary', $first],
            'http with no host' => ['https:///no-host.js', '', null, 'necessary', $first],

            // Step 1: the site's hosts, with or without www, and their subdomains (TN-9).
            'site host' => ['https://www.example.com/a.js', 'www.example.com', null, 'necessary', $first],
            'site apex' => ['https://example.com/a.js', 'example.com', null, 'necessary', $first],
            'site subdomain' => ['https://static.example.com/a.js', 'static.example.com', null, 'necessary', $first],
            'site host, upper case and port' => ['HTTPS://WWW.EXAMPLE.COM:8080/a.js', 'www.example.com', null, 'necessary', $first],
            'second site, given with a port' => ['https://shop.example.co.uk/a.js', 'shop.example.co.uk', null, 'necessary', $first],
            'site domain as a substring (TN-9)' => ['https://example.com.evil.net/a.js', 'example.com.evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'site domain as a suffix of a longer name' => ['https://notexample.com/a.js', 'notexample.com', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'site host as user info' => ['https://www.example.com@evil.net/a.js', 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'backslashes' => ['https:\\\\evil.net\\a.js', 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'protocol-relative backslashes' => ['\\\\evil.net/a.js', 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'entity-encoded slashes' => ['https:&#x2F;&#x2F;evil.net/a.js', 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'surrounding whitespace' => ["  https://evil.net/a.js\n", 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],
            'trailing dot' => ['https://evil.net./a.js', 'evil.net', null, 'marketing', Classification::SOURCE_UNKNOWN],

            // Step 2: filesystem hosts, exactly.
            'filesystem host' => ['https://assets.example-cdn.net/img/a.js', 'assets.example-cdn.net', null, 'necessary', Classification::SOURCE_FILESYSTEM],
            'filesystem host given with case and port' => ['https://files.example-storage.net/a.js', 'files.example-storage.net', null, 'necessary', Classification::SOURCE_FILESYSTEM],
            'filesystem host subdomain is not covered' => ['https://x.assets.example-cdn.net/a.js', 'x.assets.example-cdn.net', null, 'marketing', Classification::SOURCE_UNKNOWN],

            // Step 3: the allow list, by host or by host and path.
            'allowed host' => ['https://widgets.example.org/w.js', 'widgets.example.org', null, 'necessary', Classification::SOURCE_ALLOW],
            'allowed path, any case' => ['https://www.example.net/Booking/widget.js', 'www.example.net', null, 'necessary', Classification::SOURCE_ALLOW],
            'same host, other path' => ['https://www.example.net/other.js', 'www.example.net', null, 'marketing', Classification::SOURCE_UNKNOWN],

            // BR-9: infra is coded and beats the site's own override.
            'Cookiebot despite override' => ['https://consent.cookiebot.com/uc.js', 'consent.cookiebot.com', 'Cookiebot', 'infra', Classification::SOURCE_INFRA],
            'Cookiebot EU CDN' => ['https://consentcdn.cookiebot.eu/sdk/bc-v4.min.html', 'consentcdn.cookiebot.eu', 'Cookiebot', 'infra', Classification::SOURCE_INFRA],
            'GTM despite override' => ['https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXXX', 'www.googletagmanager.com', 'Google Tag Manager', 'infra', Classification::SOURCE_INFRA],
            'GTM noscript' => ['https://www.googletagmanager.com/ns.html?id=GTM-XXXXXXX', 'www.googletagmanager.com', 'Google Tag Manager', 'infra', Classification::SOURCE_INFRA],
            'gtag.js is not infra, so the site override applies' => ['https://www.googletagmanager.com/gtag/js?id=G-XXXXXXX', 'www.googletagmanager.com', 'Tag Manager', 'marketing', Classification::SOURCE_SITE],

            // Step 4: the site's vendors, before the shipped list.
            'site vendor' => ['https://tracker.example.org/t.js', 'tracker.example.org', 'Example tracker', 'statistics', Classification::SOURCE_SITE],
            'site reclassifies a shipped provider' => ['https://www.youtube.com/embed/abc', 'www.youtube.com', 'YouTube, reclassified', 'preferences', Classification::SOURCE_SITE],

            // Step 5: the shipped list; TN-8 protocol-relative, upper case and port.
            'shipped statistics' => ['https://www.google-analytics.com/analytics.js', 'www.google-analytics.com', 'Google Analytics', 'statistics', Classification::SOURCE_LIST],
            'shipped necessary is not gated' => ['https://www.google.com/recaptcha/api.js', 'www.google.com', 'reCAPTCHA', 'necessary', Classification::SOURCE_LIST],
            'protocol-relative (TN-8)' => ['//player.vimeo.com/video/1', 'player.vimeo.com', 'Vimeo', 'marketing', Classification::SOURCE_LIST],
            'upper case and port (TN-8)' => ['HTTPS://WWW.YOUTUBE-NOCOOKIE.COM:443/embed/abc', 'www.youtube-nocookie.com', 'YouTube (nocookie)', 'marketing', Classification::SOURCE_LIST],
            'http' => ['http://static.hotjar.com/c/hotjar-0.js', 'static.hotjar.com', 'Hotjar', 'statistics', Classification::SOURCE_LIST],

            // Step 6: fail closed.
            'unknown' => ['https://widgets.example-unlisted.org/loader.js', 'widgets.example-unlisted.org', null, 'marketing', Classification::SOURCE_UNKNOWN],
        ];
    }

    #[DataProvider('urls')]
    public function testUrlsAreClassifiedInTheBr6Order(string $url, string $host, ?string $vendor, string $category, string $source): void
    {
        $result = self::classifier()->classify($url);

        self::assertSame(
            ['host' => $host, 'vendor' => $vendor, 'category' => $category, 'source' => $source],
            ['host' => $result->host, 'vendor' => $result->vendor, 'category' => $result->category, 'source' => $result->source],
        );
        self::assertSame(in_array($category, ['preferences', 'statistics', 'marketing'], true), $result->isGated());
    }

    public function testAnAllowedListedProviderKeepsItsNameForTheReport(): void
    {
        $config = (new Config())->resolve(['allow' => ['trustpilot.com']], 'default', ['default']);
        $result = (new Classifier($config, new Vendors(), [], []))->classify('https://widget.trustpilot.com/bootstrap/v5/tp.widget.bootstrap.min.js');

        self::assertSame(['Trustpilot', 'necessary', Classification::SOURCE_ALLOW], [$result->vendor, $result->category, $result->source]);
        self::assertFalse($result->isGated());
    }

    /**
     * TN-13: with no shipped list every third party is unknown and gated, but infra still holds.
     */
    public function testWithoutTheShippedListEverythingFailsClosedButInfraHolds(): void
    {
        $classifier = new Classifier(
            (new Config())->resolve([], 'default', ['default']),
            new Vendors(sys_get_temp_dir() . '/cbe-no-such-file.json'),
            ['www.example.com'],
            [],
        );

        $youtube = $classifier->classify('https://www.youtube.com/embed/abc');
        $recaptcha = $classifier->classify('https://www.google.com/recaptcha/api.js');
        $cookiebot = $classifier->classify('https://consent.cookiebot.com/uc.js');
        $gtm = $classifier->classify('https://www.googletagmanager.com/gtm.js?id=GTM-XXXXXXX');

        self::assertSame(['marketing', Classification::SOURCE_UNKNOWN], [$youtube->category, $youtube->source]);
        self::assertSame(['marketing', Classification::SOURCE_UNKNOWN], [$recaptcha->category, $recaptcha->source]);
        self::assertSame(['infra', 'infra'], [$cookiebot->category, $gtm->category]);
        self::assertFalse($cookiebot->isGated());
        self::assertFalse($gtm->isGated());
        self::assertFalse($classifier->classify('/assets/app.js')->isGated());
    }

    public function testTheSameUrlGivesTheSameResult(): void
    {
        $classifier = self::classifier();

        self::assertSame($classifier->classify('https://evil.net/a.js'), $classifier->classify('https://evil.net/a.js'));
    }

    /**
     * @return array<string, array{string, array<string, string|null>, bool}>
     */
    public static function marked(): array
    {
        return [
            'ignore' => ['script', ['src' => 'https://evil.net/a.js', 'data-cookieconsent' => 'ignore'], true],
            'already marketing' => ['iframe', ['data-cookieblock-src' => 'https://evil.net/', 'data-cookieconsent' => 'marketing'], true],
            'bare attribute' => ['script', ['data-cookieconsent' => null], true],
            'text/plain' => ['script', ['type' => 'text/plain', 'src' => 'https://evil.net/a.js'], true],
            'text/plain, any case and spacing' => ['SCRIPT', ['type' => ' Text/Plain '], true],
            'text/plain on an iframe is not a mark' => ['iframe', ['type' => 'text/plain', 'src' => 'https://evil.net/'], false],
            'plain script' => ['script', ['src' => 'https://evil.net/a.js'], false],
            'text/javascript' => ['script', ['type' => 'text/javascript'], false],
            'bare type' => ['script', ['type' => null], false],
        ];
    }

    /**
     * @param array<string, string|null> $attributes
     */
    #[DataProvider('marked')]
    public function testAlreadyMarkedTagsAreRecognised(string $tag, array $attributes, bool $expected): void
    {
        self::assertSame($expected, Classifier::isAlreadyMarked($tag, $attributes));
    }

    public function testCookiebotIdsAreInfra(): void
    {
        self::assertTrue(Classifier::isInfraById(['id' => 'Cookiebot', 'src' => 'https://evil.net/uc.js']));
        self::assertTrue(Classifier::isInfraById(['id' => 'CookieDeclaration']));
        self::assertFalse(Classifier::isInfraById(['id' => 'cookiebot']));
        self::assertFalse(Classifier::isInfraById(['id' => null]));
        self::assertFalse(Classifier::isInfraById([]));
    }
}
