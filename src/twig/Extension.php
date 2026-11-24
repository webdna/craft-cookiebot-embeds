<?php

namespace webdna\cookiebotembeds\twig;

use Closure;
use Craft;
use craft\helpers\Html;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use webdna\cookiebotembeds\CookiebotEmbeds;

/**
 * BR-22: `cookiebotSettingsLink(text = null, attrs = {})`, a link that reopens the Cookiebot
 * banner. The site places it (usually in the footer); the plugin never inserts it by itself.
 */
final class Extension extends AbstractExtension
{
    public const HREF = 'javascript:Cookiebot.renew()';

    public const DEFAULT_TEXT = 'Cookie settings';

    /** @var Closure(string $message): string */
    private readonly Closure $translate;

    /**
     * @param (Closure(string $message): string)|null $translate Translates the default text;
     *        Craft's translations in the plugin's category when null
     */
    public function __construct(?Closure $translate = null)
    {
        $this->translate = $translate ?? fn(string $message): string => Craft::t(CookiebotEmbeds::LOG_CATEGORY, $message);
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cookiebotSettingsLink', $this->settingsLink(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * The link. The text is escaped; attributes are rendered by Craft's Html helper (so `class`
     * may be a list and `data` a map). `href` is always Cookiebot's renew call.
     *
     * @param array<string, mixed> $attrs
     */
    public function settingsLink(?string $text = null, array $attrs = []): string
    {
        $text ??= ($this->translate)(self::DEFAULT_TEXT);
        $attrs = array_merge(['href' => self::HREF], $attrs, ['href' => self::HREF]);
        return Html::tag('a', Html::encode($text), $attrs);
    }
}
