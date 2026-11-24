<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\cookiebotembeds\models\SiteConfig;
use webdna\cookiebotembeds\services\Banner;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The banner option (BR-21; TS-10 steps 1 to 3, TN-17).
 */
final class BannerTest extends TestCase
{
    private const CBID = '00000000-0000-0000-0000-000000000000';

    private const PAGE = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>T</title>\n</head>\n<body><p>Hi</p></body>\n</html>\n";

    /**
     * @param array<string, mixed> $raw
     */
    private static function config(array $raw = []): SiteConfig
    {
        return (new Config())->resolve($raw + ['injectCookiebot' => true, 'cbid' => self::CBID], 'default', ['default']);
    }

    /**
     * TS-10 step 1: uc.js is the head's first child, with the ID and none of async, defer or
     * data-blockingmode; the rest of the page is untouched.
     */
    public function testInsertsUcJsAsTheFirstChildOfHead(): void
    {
        $html = Banner::insert(self::PAGE, self::config());

        $tag = '<script id="Cookiebot" src="https://consent.cookiebot.com/uc.js" data-cbid="' . self::CBID . '" type="text/javascript"></script>';
        $this->assertSame(str_replace("<head>\n", "<head>$tag\n", self::PAGE), $html);
        $this->assertDoesNotMatchRegularExpression('~\b(async|defer|data-blockingmode)\b~i', $tag);
    }

    public function testCookiebotDomainChoosesTheEuHost(): void
    {
        $html = (string)Banner::insert(self::PAGE, self::config(['cookiebotDomain' => 'consent.cookiebot.eu']));

        $this->assertStringContainsString('<head><script id="Cookiebot" src="https://consent.cookiebot.eu/uc.js"', $html);
    }

    public function testAHeadWithAttributesAndUpperCaseStillGetsIt(): void
    {
        $page = '<HTML><HEAD profile="x"><title>T</title></HEAD><BODY></BODY></HTML>';

        $html = (string)Banner::insert($page, self::config());

        $this->assertStringStartsWith('<HTML><HEAD profile="x"><script id="Cookiebot"', $html);
    }

    /**
     * TS-10 step 2: a page that already loads uc.js, from either domain or protocol-relative, in
     * any attribute order, is left alone.
     */
    #[DataProvider('pagesWithUcJs')]
    public function testAPageThatAlreadyLoadsUcJsIsLeftAlone(string $script): void
    {
        $page = str_replace('</title>', "</title>\n$script", self::PAGE);

        $this->assertNull(Banner::insert($page, self::config()));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagesWithUcJs(): array
    {
        return [
            'own snippet' => [Banner::tag(self::CBID, 'consent.cookiebot.com')],
            'eu, async' => ['<script async data-cbid="x" src="https://consent.cookiebot.eu/uc.js"></script>'],
            'protocol-relative, upper case' => ['<SCRIPT SRC="//CONSENT.COOKIEBOT.COM/UC.JS"></SCRIPT>'],
            'in the body' => ['</head><body><script src="https://consent.cookiebot.com/uc.js?cbid=x"></script>'],
        ];
    }

    /**
     * The plugin's own head insertion is in the page after the first render, so a page cached and
     * filtered again (a static cache warmer, say) never gets a second copy.
     */
    public function testInsertingTwiceAddsOneCopy(): void
    {
        $once = (string)Banner::insert(self::PAGE, self::config());

        $this->assertNull(Banner::insert($once, self::config()));
        $this->assertSame(1, substr_count($once, 'uc.js'));
    }

    /**
     * TS-10 step 3: option on with no ID: nothing inserted, and the warning is raised (the plugin
     * logs it through the hourly throttle).
     */
    #[DataProvider('missingIds')]
    public function testNoIdInsertsNothingAndWarns(mixed $cbid): void
    {
        $warnings = [];
        $html = Banner::insert(self::PAGE, self::config(['cbid' => $cbid]), function(string $message) use (&$warnings): void {
            $warnings[] = $message;
        });

        $this->assertNull($html);
        $this->assertSame([Banner::MISSING_CBID], $warnings);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function missingIds(): array
    {
        return ['null' => [null], 'empty' => [''], 'blank' => ['   ']];
    }

    /**
     * TS-10 step 5 and BR-2: option off, or the plugin off, inserts nothing and never warns.
     */
    #[DataProvider('offConfigs')]
    public function testOffInsertsNothing(bool $enabled, bool $inject): void
    {
        $warned = false;
        $html = Banner::insert(self::PAGE, self::config(['enabled' => $enabled, 'injectCookiebot' => $inject, 'cbid' => null]), function() use (&$warned): void {
            $warned = true;
        });

        $this->assertNull($html);
        $this->assertFalse($warned);
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function offConfigs(): array
    {
        return [
            'option off' => [true, false],
            'plugin off' => [false, true],
        ];
    }

    public function testTheOptionIsOffByDefault(): void
    {
        $config = (new Config())->resolve(['cbid' => self::CBID], 'default', ['default']);

        $this->assertNull(Banner::insert(self::PAGE, $config));
    }

    /**
     * TN-17: no head means nowhere to put it, and nothing is said about it. `<header>` and a
     * `<head` that is only text after the body do not count.
     */
    #[DataProvider('pagesWithoutHead')]
    public function testAPageWithoutHeadIsSkippedSilently(string $page): void
    {
        $this->assertNull(Banner::insert($page, self::config()));
        $this->assertNull(Banner::headContentStart($page));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pagesWithoutHead(): array
    {
        return [
            'fragment' => ['<div><p>Hi</p></div>'],
            'header element' => ['<header><h1>Hi</h1></header>'],
            'custom element' => ['<head-line>Hi</head-line>'],
            'body first' => ['<body><pre>&lt;head&gt; <head> as text</pre></body>'],
            'empty' => [''],
        ];
    }

    public function testTheIdIsEscaped(): void
    {
        $this->assertStringContainsString(
            'data-cbid="a&quot;&gt;&lt;script&gt;"',
            Banner::tag('a"><script>', 'consent.cookiebot.com'),
        );
    }

    /**
     * BR-9: the inserted script is infra by its id, so a page that is rewritten after it would
     * leave it as it is.
     */
    public function testTheInsertedScriptIsLeftByTheRewriter(): void
    {
        $config = self::config(['allow' => []]);
        $vendors = new Vendors();
        $html = (string)Banner::insert(self::PAGE, $config);

        $rewritten = (new Rewriter(new Classifier($config, $vendors, ['www.example.com'], []), $vendors, $config))->rewrite($html);

        $this->assertSame($html, $rewritten->html);
    }
}
