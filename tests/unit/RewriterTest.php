<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The rewriter against the section 7 fixtures (BR-4, BR-5, BR-10 to BR-16; TS-1 #1, TS-2 #1-2,
 * TS-4, TN-2 to TN-8, TN-17).
 *
 * Each `tests/fixtures/<name>.html` has its expected output in `<name>.expected.html`. Run with
 * `CBE_UPDATE_FIXTURES=1` to write the expected files after a deliberate change, then review the
 * diff of each pair before committing.
 */
final class RewriterTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures';

    private static function rewriter(bool $placeholders = true, string $mode = 'standard'): Rewriter
    {
        $config = (new Config())->resolve([
            'mode' => $mode,
            'allow' => ['widgets.example.org'],
            'fingerprints' => [
                ['pattern' => 'exampleLoader\(', 'vendor' => 'Example loader', 'category' => 'statistics'],
            ],
        ], 'default', ['default']);
        $vendors = new Vendors();

        // A stand-in for the real placeholder (task 2.3) that shows every value it was given.
        $placeholder = static fn(string $category, ?string $vendor, string $kind, ?string $width, ?string $height): string => sprintf(
            '<div data-cbe-placeholder class="cookieconsent-optout-%s">%s|%s|%sx%s</div>',
            $category,
            $vendor ?? 'unknown',
            $kind,
            $width ?? '-',
            $height ?? '-',
        );

        return new Rewriter(
            new Classifier($config, $vendors, ['www.example.com'], ['assets.example-cdn.net']),
            $vendors,
            $config,
            $placeholders ? $placeholder(...) : null,
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function fixtures(): array
    {
        $names = ['iframes', 'scripts-external', 'scripts-inline', 'fingerprint', 'already-marked', 'infra',
            'non-js-types', 'alpine-attributes', 'malformed', 'widget-body', 'benchmark', 'vue-mode', ];
        return array_combine($names, array_map(fn(string $name): array => [$name], $names));
    }

    /** A fixture named `vue-...` is rewritten in Vue mode (BR-19). */
    private static function modeFor(string $name): string
    {
        return str_starts_with($name, 'vue-') ? 'vue' : 'standard';
    }

    #[DataProvider('fixtures')]
    public function testFixturesRewriteToTheirExpectedOutput(string $name): void
    {
        $input = (string)file_get_contents(self::FIXTURES . "/$name.html");
        $expectedFile = self::FIXTURES . "/$name.expected.html";
        $output = self::rewriter(mode: self::modeFor($name))->rewrite($input)->html;

        if (getenv('CBE_UPDATE_FIXTURES') === '1') {
            file_put_contents($expectedFile, $output);
        }
        self::assertFileExists($expectedFile);
        self::assertSame((string)file_get_contents($expectedFile), $output);
    }

    /**
     * A rewritten page is already marked up, so rewriting it again changes nothing.
     */
    #[DataProvider('fixtures')]
    public function testRewritingIsIdempotent(string $name): void
    {
        $rewriter = self::rewriter(mode: self::modeFor($name));
        $once = $rewriter->rewrite((string)file_get_contents(self::FIXTURES . "/$name.html"))->html;

        self::assertSame($once, $rewriter->rewrite($once)->html);
    }

    /**
     * BR-4: outside the rewritten tags and inserted placeholders, every byte is the input's.
     */
    #[DataProvider('fixtures')]
    public function testOnlyRewrittenTagsChange(string $name): void
    {
        $input = (string)file_get_contents(self::FIXTURES . "/$name.html");
        $output = self::rewriter(placeholders: false, mode: self::modeFor($name))->rewrite($input)->html;

        // Undo, on both sides, the only three things the rewriter may do to a tag: append the
        // consent attributes, rename `src`, and add or replace `type` (compared without types).
        $normalise = function(string $html): string {
            $html = (string)preg_replace('~ data-(?:cookieconsent|cbe-consent)="\w+" data-cbe-gated="\w+"~', '', $html);
            $html = (string)preg_replace('~(?<=\s)(?:data-cookieblock-|data-cbe-)?src(?=\s*=)~i', 'src', $html);
            return (string)preg_replace('~\s(?:type|TYPE)=("[^"]*"|\'[^\']*\'|[^\s>]+)~', '', $html);
        };
        self::assertSame($normalise($input), $normalise($output));
    }

    public function testTheTs4FixturesAreUnchanged(): void
    {
        foreach (['infra', 'already-marked'] as $name) {
            $input = (string)file_get_contents(self::FIXTURES . "/$name.html");
            self::assertSame($input, self::rewriter()->rewrite($input)->html, $name);
        }
    }

    public function testAlreadyMarkedElementsAreRecordedAsAlready(): void
    {
        $result = self::rewriter()->rewrite((string)file_get_contents(self::FIXTURES . '/already-marked.html'));

        self::assertSame(
            [
                ['www.googletagmanager.com', 'already', 'inline'],
                ['static.hotjar.com', 'already', 'script'],
                ['cdn.example-unlisted.org', 'already', 'script'],
                ['www.youtube.com', 'already', 'iframe'],
                // Known only by its fingerprint, so recorded under its vendor's stand-in host.
                ['fingerprint:meta pixel', 'already', 'inline'],
            ],
            array_map(fn(array $s): array => [$s['host'], $s['action'], $s['kind']], $result->sightings),
        );
    }

    /**
     * The site's own tags are never recorded, even when a developer has marked them up.
     */
    public function testMarkedUpFirstPartyTagsAreNotRecorded(): void
    {
        $html = '<script src="/js/app.js" data-cookieconsent="ignore"></script>'
            . '<script src="https://www.example.com/a.js" type="text/plain" data-cookieconsent="statistics"></script>'
            . '<iframe src="/embed/form" data-cookieconsent="ignore"></iframe>'
            . '<script data-cookieconsent="ignore">window.siteSettings = {};</script>';

        self::assertSame([], self::rewriter()->rewrite($html)->sightings);
    }

    /**
     * Two trackers known only by their fingerprints stay two sightings, each under a stand-in host
     * made from its vendor, so the report's unique key keeps them apart.
     */
    public function testFingerprintOnlyTrackersAreSeparateSightings(): void
    {
        $html = "<script>exampleLoader('a');</script>"
            . "<script>fbq('init', '000000000000000');</script>";

        $sightings = self::rewriter()->rewrite($html)->sightings;

        self::assertSame(
            [
                ['fingerprint:example loader', 'Example loader', 'gated', 'inline'],
                ['fingerprint:meta pixel', 'Meta Pixel', 'gated', 'inline'],
            ],
            array_map(fn(array $s): array => [$s['host'], $s['vendor'], $s['action'], $s['kind']], $sightings),
        );
    }

    public function testSightingsCarryTheDecision(): void
    {
        $result = self::rewriter()->rewrite((string)file_get_contents(self::FIXTURES . '/scripts-external.html'));
        $byHost = [];
        foreach ($result->sightings as $sighting) {
            $byHost[$sighting['host']] = [$sighting['vendor'], $sighting['category'], $sighting['action'], $sighting['kind']];
        }

        self::assertSame(['gtag.js (GA4/Ads)', 'statistics', 'gated', 'script'], $byHost['www.googletagmanager.com']);
        self::assertSame([null, 'marketing', 'gated', 'script'], $byHost['cdn.example-unlisted.org']);
        self::assertSame([null, 'necessary', 'allowed', 'script'], $byHost['assets.example-cdn.net']);
        self::assertSame([null, 'necessary', 'allowed', 'script'], $byHost['widgets.example.org']);
        self::assertSame(['reCAPTCHA', 'necessary', 'left', 'script'], $byHost['www.google.com']);
        self::assertSame([null, 'marketing', 'gated', 'script'], $byHost['example.com.evil.net']);
        self::assertArrayNotHasKey('www.example.com', $byHost, 'first-party is not recorded');
        self::assertArrayNotHasKey('static.example.com', $byHost, 'first-party is not recorded');
    }

    public function testModulesAreSkippedAndRecorded(): void
    {
        $result = self::rewriter()->rewrite((string)file_get_contents(self::FIXTURES . '/non-js-types.html'));

        self::assertSame(
            [['cdn.example-unlisted.org', 'skipped', 'script'], ['cdn.example-unlisted.org', 'skipped', 'inline']],
            array_map(fn(array $s): array => [$s['host'], $s['action'], $s['kind']], $result->sightings),
        );
    }

    /**
     * TN-12: the same host many times is one sighting.
     */
    public function testRepeatedHostsAreOneSighting(): void
    {
        $html = str_repeat('<script src="https://cdn.example-unlisted.org/x.js"></script>', 500);
        $result = self::rewriter()->rewrite($html);

        self::assertCount(1, $result->sightings);
        self::assertSame(500, substr_count($result->html, 'data-cbe-gated="marketing"'));
    }

    /**
     * BR-5: a page with no script or iframe is returned as it is.
     */
    public function testAPageWithoutScriptsOrIframesIsReturnedAsItIs(): void
    {
        $html = "<!DOCTYPE html><html><body><img src=\"https://www.youtube.com/x.jpg\"></body></html>";
        $result = self::rewriter()->rewrite($html);

        self::assertSame($html, $result->html);
        self::assertSame([], $result->sightings);
    }

    /**
     * TN-17: a fragment with no head or body is all body, so a widget gets its placeholder.
     */
    public function testAFragmentIsTreatedAsBody(): void
    {
        $html = '<p>Hi</p><script src="https://cdn.example-unlisted.org/w.js"></script>';

        self::assertSame(
            '<p>Hi</p><div data-cbe-placeholder class="cookieconsent-optout-marketing">unknown|script|-x-</div>'
            . '<script type="text/plain" src="https://cdn.example-unlisted.org/w.js" data-cookieconsent="marketing" data-cbe-gated="marketing"></script>',
            self::rewriter()->rewrite($html)->html,
        );
    }

    /**
     * BR-16: a provider's iframe and its player script are one embed to the visitor, so only the
     * iframe's placeholder shows, whichever comes first on the page.
     */
    #[DataProvider('iframeAndPlayerScript')]
    public function testAWidgetScriptFromAnIframesProviderGetsNoPlaceholder(string $html): void
    {
        $output = self::rewriter()->rewrite($html)->html;

        self::assertSame(1, substr_count($output, 'data-cbe-placeholder'));
        self::assertStringContainsString('</iframe><div data-cbe-placeholder class="cookieconsent-optout-marketing">Vimeo|iframe|', $output);
        self::assertSame(2, substr_count($output, 'data-cookieconsent="marketing"'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function iframeAndPlayerScript(): array
    {
        $iframe = '<iframe src="https://player.vimeo.com/video/1" width="640" height="360"></iframe>';
        $script = '<script src="https://player.vimeo.com/api/player.js"></script>';
        return [
            'iframe first' => ["<body><p>$iframe</p>$script</body>"],
            'script first' => ["<body>$script<p>$iframe</p></body>"],
        ];
    }

    /**
     * BR-16: one placeholder per provider for its widget scripts; another provider still gets its own.
     */
    public function testWidgetScriptsGetOnePlaceholderPerProvider(): void
    {
        $html = '<body>'
            . '<script src="https://cdn.example-unlisted.org/a.js"></script>'
            . '<script src="https://cdn.example-unlisted.org/b.js"></script>'
            . '<script src="https://www.youtube.com/iframe_api"></script>'
            . '<script src="https://www.youtube.com/iframe_api?v=2"></script>'
            . '</body>';

        $output = self::rewriter()->rewrite($html)->html;

        self::assertSame(2, substr_count($output, 'data-cbe-placeholder'));
        self::assertSame(1, substr_count($output, '>unknown|script|-x-</div><script type="text/plain" src="https://cdn.example-unlisted.org/a.js"'));
        self::assertSame(1, substr_count($output, '>YouTube|script|-x-</div><script type="text/plain" src="https://www.youtube.com/iframe_api"'));
        self::assertSame(4, substr_count($output, 'type="text/plain"'));
    }

    /**
     * Placeholders are per page: the same rewriter on the next page starts again.
     */
    public function testProvidersSeenOnOnePageDoNotCarryToTheNext(): void
    {
        $rewriter = self::rewriter();
        $rewriter->rewrite('<body><iframe src="https://player.vimeo.com/video/1"></iframe></body>');

        $output = $rewriter->rewrite('<body><script src="https://player.vimeo.com/api/player.js"></script></body>')->html;

        self::assertStringContainsString('>Vimeo|script|-x-</div><script type="text/plain"', $output);
    }

    public function testWithoutARendererNoPlaceholderIsInserted(): void
    {
        $html = '<iframe src="https://www.youtube.com/embed/a"></iframe>';

        self::assertSame(
            '<iframe data-cookieblock-src="https://www.youtube.com/embed/a" data-cookieconsent="marketing" data-cbe-gated="marketing"></iframe>',
            self::rewriter(placeholders: false)->rewrite($html)->html,
        );
    }

    /**
     * TN-2: attribute positions survive `>` in quotes, single quotes, unquoted values and line
     * breaks; a tag that never closes is null.
     */
    public function testTagParsingKeepsExactOffsets(): void
    {
        $html = "<script data-a=\"x > y\" data-b='1' c=2\n  async src=/a.js>";
        $tag = Rewriter::parseTag($html, 0);

        self::assertNotNull($tag);
        self::assertSame(strlen($html), $tag['end']);
        self::assertSame(['data-a', 'data-b', 'c', 'async', 'src'], array_column($tag['attributes'], 'name'));
        self::assertSame(['x > y', '1', '2', null, '/a.js'], array_column($tag['attributes'], 'value'));
        foreach ($tag['attributes'] as $attribute) {
            self::assertStringStartsWith(substr($html, $attribute['nameStart'], $attribute['nameEnd'] - $attribute['nameStart']), substr($html, $attribute['start']));
        }
        self::assertSame("src=/a.js", substr($html, $tag['attributes'][4]['start'], $tag['attributes'][4]['end'] - $tag['attributes'][4]['start']));

        self::assertNull(Rewriter::parseTag('<iframe src="https://www.youtube.com/embed/a', 0));
        self::assertNull(Rewriter::parseTag('<iframe src="x"', 0));
    }

    public function testResultConstantsMatchTheDataModel(): void
    {
        self::assertSame(['gated', 'left', 'allowed', 'already', 'skipped'], [
            RewriteResult::ACTION_GATED, RewriteResult::ACTION_LEFT, RewriteResult::ACTION_ALLOWED,
            RewriteResult::ACTION_ALREADY, RewriteResult::ACTION_SKIPPED,
        ]);
        self::assertSame(['script', 'inline', 'iframe'], [RewriteResult::KIND_SCRIPT, RewriteResult::KIND_INLINE, RewriteResult::KIND_IFRAME]);
        self::assertTrue(Classifier::isAlreadyMarked('script', ['data-cookieconsent' => 'marketing']));
    }
}
