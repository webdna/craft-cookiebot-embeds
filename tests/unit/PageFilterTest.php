<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use webdna\cookiebotembeds\models\SiteConfig;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\PageFilter;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The render hook's decisions (BR-1, BR-2, BR-5; TS-9 step 1, TN-1, TN-14, TN-17). The hook
 * itself only gathers the facts from Craft and calls these.
 */
final class PageFilterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private static function config(bool $enabled = true): SiteConfig
    {
        return (new Config())->resolve(['enabled' => $enabled], 'default', ['default']);
    }

    private static function rewriter(SiteConfig $config): Rewriter
    {
        $vendors = new Vendors();
        return new Rewriter(new Classifier($config, $vendors, ['www.example.com'], []), $vendors, $config);
    }

    /**
     * TS-9 step 1 (BR-2): with `enabled` false every fixture comes back byte for byte, and the
     * rewriter is never even built.
     */
    #[DataProvider('fixtures')]
    public function testDisabledLeavesEveryFixtureAsItIs(string $name): void
    {
        $html = (string)file_get_contents(self::FIXTURES . "/$name.html");
        $built = false;
        $result = PageFilter::filter($html, self::config(false), function() use (&$built): Rewriter {
            $built = true;
            return self::rewriter(self::config());
        });

        $this->assertNull($result);
        $this->assertFalse($built);
    }

    /**
     * Enabled, the hook serves exactly what the rewriter produces.
     */
    #[DataProvider('fixtures')]
    public function testEnabledServesTheRewrite(string $name): void
    {
        $html = (string)file_get_contents(self::FIXTURES . "/$name.html");
        $config = self::config();
        $result = PageFilter::filter($html, $config, fn(): Rewriter => self::rewriter($config));

        $this->assertNotNull($result);
        $this->assertSame(self::rewriter($config)->rewrite($html)->html, $result->html);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtures(): array
    {
        return RewriterTest::fixtures();
    }

    /**
     * BR-5: a page with no script or iframe is left without building anything.
     */
    public function testAPageWithNothingToGateBuildsNothing(): void
    {
        $result = PageFilter::filter('<p>Text and <img src="https://www.youtube.com/x.jpg"></p>', self::config(), function(): Rewriter {
            throw new RuntimeException('Should not be built');
        });

        $this->assertNull($result);
    }

    /**
     * B3: a rewrite that throws leaves the page as it was and is reported.
     */
    public function testAFailingRewriteServesThePageUnchanged(): void
    {
        $errors = [];
        $result = PageFilter::filter(
            '<script src="https://cdn.example.net/a.js"></script>',
            self::config(),
            fn(): Rewriter => throw new RuntimeException('boom'),
            function(string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        $this->assertNull($result);
        $this->assertSame(['The page was served unchanged because the rewrite failed: boom'], $errors);
    }

    /**
     * TN-17: a fragment with no head or body is still rewritten.
     */
    public function testAFragmentIsRewritten(): void
    {
        $config = self::config();
        $result = PageFilter::filter('<iframe src="https://www.youtube.com/embed/x"></iframe>', $config, fn(): Rewriter => self::rewriter($config));

        $this->assertNotNull($result);
        $this->assertStringContainsString('data-cookieblock-src="https://www.youtube.com/embed/x"', $result->html);
    }

    /**
     * BR-1, TN-1, TN-14.
     *
     * @return array<string, array{bool, bool, bool, bool, string, bool}>
     */
    public static function requests(): array
    {
        return [
            'site page' => [false, false, false, false, 'site', true],
            'live preview' => [false, false, false, true, 'site', true],
            'preview reached through an action' => [false, false, true, true, 'site', true],
            'control panel' => [false, true, false, false, 'cp', false],
            'control panel rendering a site template' => [false, true, false, false, 'site', false],
            'console' => [true, false, false, false, 'site', false],
            'action request' => [false, false, true, false, 'site', false],
            'cp template on a site request' => [false, false, false, false, 'cp', false],
        ];
    }

    #[DataProvider('requests')]
    public function testOnlySitePagesAreRewritten(bool $console, bool $cp, bool $action, bool $preview, string $mode, bool $expected): void
    {
        $this->assertSame($expected, PageFilter::isSitePage($console, $cp, $action, $preview, $mode));
    }

    /**
     * BR-1, TN-1: the response type as Craft will send it.
     *
     * @return array<string, array{string|null, string|null, bool}>
     */
    public static function contentTypes(): array
    {
        return [
            'plain template' => [null, '/templates/blog/_entry.twig', true],
            'html template' => [null, '/templates/index.html', true],
            'html.twig template' => [null, '/templates/page.html.twig', true],
            'unresolved template' => [null, null, true],
            'xml template' => [null, '/templates/sitemap.xml.twig', false],
            'json template' => [null, '/templates/api/feed.json.twig', false],
            'rss template' => [null, '/templates/feed.rss', false],
            'header says html' => ['text/html; charset=UTF-8', '/templates/feed.json.twig', true],
            'header says json' => ['application/json', '/templates/_entry.twig', false],
            'header in capitals' => ['TEXT/HTML', null, true],
            'empty header' => ['', '/templates/_entry.twig', true],
        ];
    }

    #[DataProvider('contentTypes')]
    public function testOnlyHtmlResponsesAreRewritten(?string $contentType, ?string $file, bool $expected): void
    {
        $this->assertSame($expected, PageFilter::isHtml($contentType, $file));
    }

    public function testHostsAreTheDistinctHostsOfAbsoluteUrls(): void
    {
        $this->assertSame(
            ['www.example.com', 'shop.example.org', 'assets.example-cdn.net'],
            PageFilter::hosts([
                'https://www.example.com/',
                'https://WWW.Example.com/en/',
                'http://shop.example.org:8080/',
                '//assets.example-cdn.net/images',
                '/relative',
                '',
                null,
                'not a url',
            ]),
        );
    }
}
