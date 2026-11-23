<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The shipped provider list (BR-28), its fingerprints (BR-14), the coded infra rule (BR-9) and a
 * missing or broken list (TN-13).
 */
final class VendorsTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function rawList(): array
    {
        $data = json_decode((string)file_get_contents(Vendors::defaultPath()), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        /** @var array<string, mixed> $data */
        return $data;
    }

    private function listFile(string $contents): string
    {
        $file = (string)tempnam(sys_get_temp_dir(), 'cbe-vendors-');
        file_put_contents($file, $contents);
        $this->tempFiles[] = $file;
        return $file;
    }

    public function testTheShippedListLoadsWithoutWarnings(): void
    {
        $vendors = new Vendors();

        self::assertSame([], $vendors->warnings());
        self::assertGreaterThan(50, count($vendors->vendors()));
        self::assertGreaterThan(10, count($vendors->fingerprints()));
    }

    /**
     * BR-28: exactly `vendors` and `fingerprints`, each entry exactly its three keys, lower-case
     * trimmed matches, a known category, a vendor name, no duplicates, and every pattern compiles.
     */
    public function testTheShippedFileHasTheDocumentedShape(): void
    {
        $data = self::rawList();
        self::assertSame(['vendors', 'fingerprints'], array_keys($data));
        self::assertIsList($data['vendors']);
        self::assertIsList($data['fingerprints']);

        $seen = [];
        foreach ($data['vendors'] as $i => $entry) {
            self::assertIsArray($entry);
            self::assertSame(['match', 'vendor', 'category'], array_keys($entry), "vendors[$i]");
            self::assertSame(strtolower(trim($entry['match'])), $entry['match'], "vendors[$i].match is lower-case and trimmed");
            self::assertNotSame('', $entry['match'], "vendors[$i].match");
            self::assertNotSame('', trim($entry['vendor']), "vendors[$i].vendor");
            self::assertContains($entry['category'], Config::ENTRY_CATEGORIES, "vendors[$i].category");
            self::assertNotContains($entry['match'], $seen, "vendors[$i] is a duplicate");
            $seen[] = $entry['match'];
        }

        foreach ($data['fingerprints'] as $i => $entry) {
            self::assertIsArray($entry);
            self::assertSame(['pattern', 'vendor', 'category'], array_keys($entry), "fingerprints[$i]");
            self::assertNotNull(Config::compilePattern($entry['pattern']), "fingerprints[$i].pattern compiles");
            self::assertNotSame('', trim($entry['vendor']), "fingerprints[$i].vendor");
            self::assertContains($entry['category'], Config::ENTRY_CATEGORIES, "fingerprints[$i].category");
            self::assertNotSame('infra', $entry['category'], "fingerprints[$i]: infra is never fingerprinted");
        }
    }

    /**
     * First match wins, so an entry whose match contains an earlier entry's match is dead.
     */
    public function testNoEntryIsShadowedByAnEarlierOne(): void
    {
        $matches = array_column(self::rawList()['vendors'], 'match');
        foreach ($matches as $j => $later) {
            foreach (array_slice($matches, 0, $j) as $earlier) {
                self::assertStringNotContainsString($earlier, $later, "'$later' can never match: '$earlier' comes first");
            }
        }
    }

    /**
     * BR-9 is coded, and the file lists exactly the same infra entries for the cookiebot-check
     * skill's copy (BR-28).
     */
    public function testTheCodedInfraEntriesAreTheListedOnes(): void
    {
        $listed = array_values(array_filter(
            self::rawList()['vendors'],
            fn(array $entry): bool => $entry['category'] === 'infra',
        ));

        self::assertSame(Vendors::INFRA, $listed);
    }

    /**
     * @return array<string, array{string, string|null, string|null}>
     */
    public static function lookups(): array
    {
        return [
            'YouTube embed' => ['www.youtube.com/embed/abc123', 'YouTube', 'marketing'],
            'YouTube nocookie' => ['www.youtube-nocookie.com/embed/abc123', 'YouTube (nocookie)', 'marketing'],
            'upper-case host' => ['WWW.YOUTUBE.COM/embed/abc123', 'YouTube', 'marketing'],
            'Vimeo player' => ['player.vimeo.com/video/1', 'Vimeo', 'marketing'],
            'Google Maps embed' => ['www.google.com/maps/embed', 'Google Maps', 'marketing'],
            'gtag.js is statistics, not infra' => ['www.googletagmanager.com/gtag/js', 'gtag.js (GA4/Ads)', 'statistics'],
            'GTM container is infra' => ['www.googletagmanager.com/gtm.js', 'Google Tag Manager', 'infra'],
            'GTM noscript is infra' => ['www.googletagmanager.com/ns.html', 'Google Tag Manager', 'infra'],
            'Cookiebot uc.js is infra' => ['consent.cookiebot.com/uc.js', 'Cookiebot', 'infra'],
            'Cookiebot EU CDN is infra' => ['consentcdn.cookiebot.eu/sdk/bc-v4.min.html', 'Cookiebot', 'infra'],
            'reCAPTCHA is necessary' => ['www.google.com/recaptcha/api.js', 'reCAPTCHA', 'necessary'],
            'reCAPTCHA gstatic is necessary' => ['www.gstatic.com/recaptcha/releases/x/recaptcha__en.js', 'reCAPTCHA', 'necessary'],
            'Stripe is necessary' => ['js.stripe.com/v3/', 'Stripe', 'necessary'],
            'Hotjar is statistics' => ['static.hotjar.com/c/hotjar-0000000.js', 'Hotjar', 'statistics'],
            'Intercom is preferences' => ['widget.intercom.io/widget/example0', 'Intercom', 'preferences'],
            'unlisted host' => ['widgets.example.org/loader.js', null, null],
        ];
    }

    #[DataProvider('lookups')]
    public function testTheShippedListClassifiesKnownProviders(string $hostPath, ?string $vendor, ?string $category): void
    {
        $entry = (new Vendors())->vendor($hostPath);

        self::assertSame($vendor, $entry['vendor'] ?? null);
        self::assertSame($category, $entry['category'] ?? null);
    }

    public function testInfraIsOnlyCookiebotAndTagManager(): void
    {
        $vendors = new Vendors();

        self::assertSame('Cookiebot', $vendors->infra('consent.cookiebot.com/uc.js')['vendor'] ?? null);
        self::assertSame('Google Tag Manager', $vendors->infra('WWW.GOOGLETAGMANAGER.COM/gtm.js')['vendor'] ?? null);
        self::assertNull($vendors->infra('www.googletagmanager.com/gtag/js'));
        self::assertNull($vendors->infra('www.youtube.com/embed/abc123'));
        self::assertSame(['Cookiebot', 'CookieDeclaration'], Vendors::INFRA_IDS);
    }

    public function testFirstMatchTakesTheFirstEntryInOrder(): void
    {
        $entries = [
            ['match' => 'widgets.example.org', 'vendor' => 'Site widget', 'category' => 'necessary'],
            ['match' => 'example.org', 'vendor' => 'Example', 'category' => 'marketing'],
        ];

        self::assertSame('Site widget', Vendors::firstMatch($entries, 'widgets.example.org/a.js')['vendor'] ?? null);
        self::assertSame('Example', Vendors::firstMatch($entries, 'cdn.example.org/a.js')['vendor'] ?? null);
        self::assertNull(Vendors::firstMatch($entries, 'example.com/a.js'));
    }

    /**
     * Obfuscated or URL-free loaders as sites paste them, with made-up IDs (BR-30).
     *
     * @return array<string, array{string, string}>
     */
    public static function fingerprintSamples(): array
    {
        return [
            'Hotjar wrapper' => ['Hotjar', "(function(h,o,t,j,a,r){h.hj=h.hj||function(){(h.hj.q=h.hj.q||[]).push(arguments)};a=o.getElementsByTagName('head')[0];r=o.createElement('script');r.src=t+j;a.appendChild(r);})(window,document,u,v);"],
            'Hotjar settings' => ['Hotjar', 'window._hjSettings = {hjid: 1234567, hjsv: 6};'],
            'Clarity wrapper' => ['Microsoft Clarity', '(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};t=l.createElement(r);t.async=1;t.src=u+i;})(window, document, "clarity", "script", "abcdefghij");'],
            'Segment snippet' => ['Segment', '!function(){var analytics=window.analytics=window.analytics||[];analytics.SNIPPET_VERSION="4.15.3";analytics.load("EXAMPLEKEY");}();'],
            'Meta Pixel init' => ['Meta Pixel', "fbq('init', '000000000000000'); fbq('track', 'PageView');"],
            'Meta Pixel loader' => ['Meta Pixel', '!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};}(window,document,"script",u);'],
            'LinkedIn Insight' => ['LinkedIn Insight', '_linkedin_partner_id = "0000000"; window._linkedin_data_partner_ids = window._linkedin_data_partner_ids || [];'],
            'TikTok object' => ['TikTok Pixel', "!function (w, d, t) { w.TiktokAnalyticsObject=t; var ttq=w[t]=w[t]||[]; }(window, document, 'ttq');"],
            'TikTok load' => ['TikTok Pixel', "ttq.load('EXAMPLE000'); ttq.page();"],
            'Pinterest load' => ['Pinterest Tag', "pintrk('load', '0000000000000', {em: ''}); pintrk('page');"],
            'Microsoft UET' => ['Microsoft UET', '(function(w,d,t,r,u){var f;f=function(){var o={ti:"000000"};o.q=w[u],w[u]=new UET(o),w[u].push("pageLoad")}})(window,document,"script",s,"uetq");'],
            'HubSpot queue' => ['HubSpot', "var _hsq = window._hsq = window._hsq || []; _hsq.push(['setPath', '/']);"],
            'Snap Pixel' => ['Snap Pixel', "snaptr('init', '00000000-0000-0000-0000-000000000000', {});"],
            'X/Twitter pixel' => ['X/Twitter pixel', "twq('config','abcde');"],
            'Intercom settings' => ['Intercom', 'window.intercomSettings = { app_id: "example0" };'],
            'Drift load' => ['Drift', "drift.load('example000000');"],
            'Tawk.to' => ['Tawk.to', 'var Tawk_API=Tawk_API||{}, Tawk_LoadStart=new Date();'],
        ];
    }

    #[DataProvider('fingerprintSamples')]
    public function testShippedFingerprintsRecogniseTheirLoaders(string $vendor, string $body): void
    {
        $matches = (new Vendors())->fingerprintsIn($body);

        self::assertNotSame([], $matches);
        self::assertSame([$vendor], array_values(array_unique(array_column($matches, 'vendor'))));
    }

    public function testEveryShippedFingerprintHasASample(): void
    {
        $vendors = new Vendors();
        $matched = [];
        foreach (self::fingerprintSamples() as [, $body]) {
            foreach ($vendors->fingerprintsIn($body) as $fingerprint) {
                $matched[$fingerprint['pattern']] = true;
            }
        }

        foreach ($vendors->fingerprints() as $fingerprint) {
            self::assertArrayHasKey($fingerprint['pattern'], $matched, "{$fingerprint['pattern']} has no sample");
        }
    }

    /**
     * Tag Manager and the Consent Mode defaults must load before consent, so no fingerprint may
     * catch them (section 7, regression checks); nor a site's own inline code.
     *
     * @return array<string, array{string}>
     */
    public static function bodiesNoFingerprintMatches(): array
    {
        return [
            'GTM snippet' => ["(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-XXXXXXX');"],
            'Consent Mode defaults' => ["window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('consent', 'default', {ad_storage: 'denied', analytics_storage: 'denied', wait_for_update: 500});"],
            'Alpine component' => ["document.addEventListener('alpine:init', () => { Alpine.data('menu', () => ({ open: false })) })"],
            'a site variable that only contains a name' => ['var mySettings = {}; myintercomSettings = 1; doTwq("init");'],
        ];
    }

    #[DataProvider('bodiesNoFingerprintMatches')]
    public function testShippedFingerprintsLeaveTagManagerConsentDefaultsAndSiteCode(string $body): void
    {
        self::assertSame([], (new Vendors())->fingerprintsIn($body));
    }

    /**
     * TN-13: a missing file is a warning and an empty list (fail closed); BR-9 still holds.
     */
    public function testAMissingFileFailsClosedAndKeepsInfra(): void
    {
        $vendors = new Vendors(sys_get_temp_dir() . '/cbe-no-such-file.json');

        self::assertSame([], $vendors->vendors());
        self::assertSame([], $vendors->fingerprints());
        self::assertNull($vendors->vendor('www.youtube.com/embed/abc123'));
        self::assertSame([], $vendors->fingerprintsIn("fbq('init', '0');"));
        self::assertSame('infra', $vendors->infra('consent.cookiebot.com/uc.js')['category'] ?? null);
        self::assertCount(1, $vendors->warnings());
        self::assertStringContainsString('could not be read', $vendors->warnings()[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenFiles(): array
    {
        return [
            'empty' => ['', 'not valid JSON'],
            'invalid JSON' => ['{"vendors": [', 'not valid JSON'],
            'a list, not an object' => ['[]', 'must be an object'],
            'a string' => ['"vendors"', 'must be an object'],
        ];
    }

    #[DataProvider('brokenFiles')]
    public function testABrokenFileFailsClosedWithOneWarning(string $contents, string $warning): void
    {
        $vendors = new Vendors($this->listFile($contents));

        self::assertSame([], $vendors->vendors());
        self::assertSame([], $vendors->fingerprints());
        self::assertCount(1, $vendors->warnings());
        self::assertStringContainsString($warning, $vendors->warnings()[0]);
    }

    /**
     * BR-14: a fingerprint that does not compile is dropped with a warning, never thrown; a bad
     * provider entry is dropped on its own.
     */
    public function testBadEntriesAreDroppedOneByOne(): void
    {
        $vendors = new Vendors($this->listFile((string)json_encode([
            'vendors' => [
                ['match' => 'Widgets.Example.org', 'vendor' => 'Widget', 'category' => 'statistics'],
                ['match' => 'example.com', 'vendor' => 'Bad', 'category' => 'cookies'],
                ['match' => 'cdn.example.net', 'category' => 'marketing'],
            ],
            'fingerprints' => [
                ['pattern' => 'exampleTrack\(', 'vendor' => 'Example', 'category' => 'marketing'],
                ['pattern' => 'unclosed(', 'vendor' => 'Broken', 'category' => 'marketing'],
            ],
        ])));

        self::assertSame([
            ['match' => 'widgets.example.org', 'vendor' => 'Widget', 'category' => 'statistics'],
            ['match' => 'cdn.example.net', 'vendor' => 'cdn.example.net', 'category' => 'marketing'],
        ], $vendors->vendors());
        self::assertSame(['Example'], array_column($vendors->fingerprints(), 'vendor'));
        self::assertSame(['Example'], array_column($vendors->fingerprintsIn('window.exampleTrack(1)'), 'vendor'));

        self::assertCount(2, $vendors->warnings());
        self::assertStringContainsString('vendors.json `vendors[1].category`', $vendors->warnings()[0]);
        self::assertStringContainsString('vendors.json `fingerprints[1].pattern`', $vendors->warnings()[1]);
    }

    public function testAMissingKeyIsAWarningAndAnEmptyList(): void
    {
        $vendors = new Vendors($this->listFile('{"vendors": [{"match": "example.org", "vendor": "Example", "category": "marketing"}]}'));

        self::assertCount(1, $vendors->vendors());
        self::assertSame([], $vendors->fingerprints());
        self::assertCount(1, $vendors->warnings());
        self::assertStringContainsString('vendors.json `fingerprints` is missing', $vendors->warnings()[0]);
    }
}
