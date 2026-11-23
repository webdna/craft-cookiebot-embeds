<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use webdna\cookiebotembeds\services\Classifier;
use webdna\cookiebotembeds\services\Config;
use webdna\cookiebotembeds\services\Placeholder;
use webdna\cookiebotembeds\services\Rewriter;
use webdna\cookiebotembeds\services\Vendors;

/**
 * The placeholder (BR-17, BR-18; TS-5 step 0). The default template is rendered with the same
 * Twig environment a live site uses; only translation and the site renderer are stand-ins.
 */
final class PlaceholderTest extends TestCase
{
    private const TRANSLATIONS = __DIR__ . '/../../src/translations/en/cookiebot-embeds.php';

    /**
     * TS-5 step 0: the class Cookiebot toggles, the test hook, no style attribute, the renew link.
     */
    public function testTheDefaultPlaceholderHasWhatCookiebotAndTestsNeed(): void
    {
        $html = (new Placeholder())->render('marketing', 'YouTube', 'iframe', '560', '315');

        $this->assertStringStartsWith('<span ', $html);
        $this->assertMatchesRegularExpression('~^<span class="cbe-placeholder cookieconsent-optout-marketing"~', $html);
        $this->assertStringContainsString(' data-cbe-placeholder="marketing"', $html);
        $this->assertStringContainsString(' data-cbe-kind="iframe"', $html);
        $this->assertStringContainsString('<a class="cbe-placeholder__link" href="javascript:Cookiebot.renew()">Change cookie settings</a>', $html);
        $this->assertStringNotContainsStringIgnoringCase('style', $html);
        $this->assertStringEndsWith('</span>', $html);
        $this->assertStringNotContainsString("\n", $html);
    }

    public function testTheMessageNamesTheVendorAndCategory(): void
    {
        $this->assertStringContainsString(
            '<span class="cbe-placeholder__message">YouTube content is blocked until you accept marketing cookies.</span>',
            (new Placeholder())->render('marketing', 'YouTube', 'iframe', null, null),
        );
        $this->assertStringContainsString(
            '<span class="cbe-placeholder__message">This content is blocked until you accept statistics cookies.</span>',
            (new Placeholder())->render('statistics', null, 'script', null, null),
        );
    }

    /**
     * Section 6: the embed's size travels as data attributes only, and only when it was given.
     */
    public function testWidthAndHeightAreDataAttributesOnly(): void
    {
        $sized = (new Placeholder())->render('marketing', null, 'iframe', '100%', '0');
        $this->assertStringContainsString(' data-cbe-width="100%" data-cbe-height="0">', $sized);

        $unsized = (new Placeholder())->render('marketing', null, 'iframe', null, '');
        $this->assertStringNotContainsString('data-cbe-width', $unsized);
        $this->assertStringNotContainsString('data-cbe-height', $unsized);
    }

    /**
     * Width, height and the vendor name come from page and config text, so they are escaped.
     */
    public function testValuesAreEscaped(): void
    {
        $html = (new Placeholder())->render('marketing', '<b>Evil</b> & co', 'iframe', '1" onload="x()', null);

        $this->assertStringContainsString('data-cbe-width="1&quot; onload=&quot;x()"', $html);
        $this->assertStringContainsString('&lt;b&gt;Evil&lt;/b&gt; &amp; co content', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    /**
     * BR-17: the copy is translated in the `cookiebot-embeds` category, and every string the
     * template asks for is in the shipped translations file.
     */
    public function testEveryStringIsTranslatedFromTheShippedFile(): void
    {
        /** @var array<string, string> $translations */
        $translations = require self::TRANSLATIONS;
        $asked = [];
        $translate = function(string $message, string $category, array $params) use (&$asked, $translations): string {
            $asked[] = [$category, $message];
            $this->assertArrayHasKey($message, $translations, "Missing from the translations file: $message");
            return 'T(' . $message . ')';
        };

        $placeholder = new Placeholder(translate: $translate(...));
        $html = $placeholder->render('marketing', 'YouTube', 'iframe', null, null)
            . $placeholder->render('preferences', null, 'script', null, null);

        $this->assertStringContainsString('>T(Change cookie settings)</a>', $html);
        $this->assertSame(['cookiebot-embeds'], array_values(array_unique(array_column($asked, 0))));
        $this->assertContains('{vendor} content is blocked until you accept {category} cookies.', array_column($asked, 1));
        $this->assertContains('This content is blocked until you accept {category} cookies.', array_column($asked, 1));
        $this->assertContains('preferences', array_column($asked, 1));
    }

    /**
     * TS-5 step 1, unit half: a site template gets every variable and replaces the default.
     */
    public function testASiteTemplateReplacesTheDefault(): void
    {
        $calls = [];
        $renderSite = function(string $template, array $variables) use (&$calls): string {
            $calls[] = [$template, $variables];
            return "\n  <div class=\"site cookieconsent-optout-{$variables['category']}\">{$variables['vendor']}</div>\n";
        };

        $html = (new Placeholder('_partials/cookie-placeholder', $renderSite(...)))
            ->render('marketing', 'YouTube', 'iframe', '560', null);

        $this->assertSame('<div class="site cookieconsent-optout-marketing">YouTube</div>', $html);
        $this->assertSame([['_partials/cookie-placeholder', [
            'category' => 'marketing', 'vendor' => 'YouTube', 'kind' => 'iframe', 'width' => '560', 'height' => null,
        ]]], $calls);
    }

    /**
     * B3: nothing in the render path throws. A broken site template falls back to the default.
     */
    public function testABrokenSiteTemplateFallsBackToTheDefaultAndIsReported(): void
    {
        $errors = [];
        $placeholder = new Placeholder(
            siteTemplate: '_missing',
            renderSite: static fn(string $template, array $variables): string => throw new RuntimeException('Unable to find the template'),
            onError: static function(string $message) use (&$errors): void {
                $errors[] = $message;
            },
        );

        $html = $placeholder->render('marketing', null, 'iframe', null, null);
        $other = $placeholder->render('statistics', null, 'script', null, null);

        $this->assertStringStartsWith('<span class="cbe-placeholder cookieconsent-optout-marketing"', $html);
        $this->assertStringStartsWith('<span class="cbe-placeholder cookieconsent-optout-statistics"', $other);
        // Not retried for the rest of the request: one log line per page.
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('`_missing` failed, using the default: Unable to find the template', $errors[0]);
    }

    public function testASiteTemplateWithoutARendererIsNotUsed(): void
    {
        $html = (new Placeholder('_partials/cookie-placeholder'))->render('marketing', null, 'iframe', null, null);

        $this->assertStringStartsWith('<span class="cbe-placeholder', $html);
    }

    /**
     * The same embed on a page renders once per request.
     */
    public function testRendersAreMemoisedByTheirVariables(): void
    {
        $count = 0;
        $renderSite = function(string $template, array $variables) use (&$count): string {
            $count++;
            return '<div>' . $variables['category'] . '</div>';
        };
        $placeholder = new Placeholder('_p', $renderSite(...));

        $placeholder->render('marketing', 'YouTube', 'iframe', '560', '315');
        $placeholder->render('marketing', 'YouTube', 'iframe', '560', '315');
        $placeholder->render('marketing', 'YouTube', 'iframe', '640', '315');

        $this->assertSame(2, $count);
    }

    /**
     * TS-1 step 1 with the real renderer: the placeholder follows the gated iframe, sized from it.
     */
    public function testTheRewriterInsertsTheRealPlaceholder(): void
    {
        $config = (new Config())->resolve([], 'default', ['default']);
        $vendors = new Vendors();
        $rewriter = new Rewriter(
            new Classifier($config, $vendors, ['www.example.com'], []),
            $vendors,
            $config,
            (new Placeholder())->renderer(),
        );

        $html = $rewriter->rewrite('<p><iframe width="560" height="315" src="https://www.youtube.com/embed/abc123"></iframe></p>')->html;

        $this->assertMatchesRegularExpression(
            '~^<p><iframe [^>]*data-cookieblock-src="https://www\.youtube\.com/embed/abc123"[^>]*></iframe>'
            . '<span class="cbe-placeholder cookieconsent-optout-marketing" data-cbe-placeholder="marketing" data-cbe-kind="iframe" data-cbe-width="560" data-cbe-height="315">'
            . '<span class="cbe-placeholder__message">YouTube content is blocked until you accept marketing cookies\.</span> '
            . '<a class="cbe-placeholder__link" href="javascript:Cookiebot\.renew\(\)">Change cookie settings</a></span></p>$~',
            $html,
        );
    }
}
