<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use webdna\cookiebotembeds\twig\Extension;

/**
 * `cookiebotSettingsLink()` (BR-22), rendered through a real Twig environment with autoescaping on,
 * as a site template would be.
 */
final class ExtensionTest extends TestCase
{
    private static function render(string $template): string
    {
        $twig = new Environment(new ArrayLoader(['t' => $template]), ['autoescape' => 'html']);
        $twig->addExtension(new Extension(fn(string $message): string => "[$message]"));
        return $twig->render('t');
    }

    public function testDefaultTextIsTranslatedAndTheLinkIsNotEscaped(): void
    {
        $this->assertSame(
            '<a href="javascript:Cookiebot.renew()">[Cookie settings]</a>',
            self::render('{{ cookiebotSettingsLink() }}'),
        );
    }

    public function testCustomTextIsEscaped(): void
    {
        $this->assertSame(
            '<a href="javascript:Cookiebot.renew()">Privacy &amp; &lt;cookies&gt;</a>',
            self::render('{{ cookiebotSettingsLink("Privacy & <cookies>") }}'),
        );
    }

    public function testAttributesAreRenderedAndHrefCannotBeReplaced(): void
    {
        $html = self::render('{{ cookiebotSettingsLink(null, {class: ["footer-link", "small"], data: {track: "x"}, href: "/elsewhere", title: "a\"b"}) }}');

        $this->assertSame(
            // Yii's Html helper orders attributes itself: class first, data-* last.
            '<a class="footer-link small" href="javascript:Cookiebot.renew()" title="a&quot;b" data-track="x">[Cookie settings]</a>',
            $html,
        );
    }
}
