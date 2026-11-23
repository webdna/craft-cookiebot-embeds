<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

/**
 * Renders the placeholder shown in place of a gated embed (BR-17, BR-18). Pass `renderer()` to the
 * rewriter.
 *
 * The plugin's own template is rendered with a small Twig environment of its own, the same in
 * unit tests and on a live site; only its `t` filter differs. A site template named in
 * `placeholderTemplate` is rendered by the injected site renderer (Craft's view, in site mode).
 * Nothing here calls `Craft::$app`.
 *
 * Rendering never throws: a site template that fails is logged and the default is used, and if
 * the default fails too, no placeholder is inserted (the embed stays gated).
 */
final class Placeholder
{
    /** The plugin's template, under `src/templates`. */
    public const TEMPLATE = '_placeholder.twig';

    /** The translation category the default template's copy lives in. */
    public const TRANSLATION_CATEGORY = 'cookiebot-embeds';

    private ?Environment $twig = null;

    private bool $siteTemplateFailed = false;

    /** @var array<string, string> Rendered placeholders by their variables, per request */
    private array $rendered = [];

    /**
     * @param string|null $siteTemplate The site's `placeholderTemplate`; null uses the plugin's
     * @param (Closure(string $template, array<string, string|null> $variables): string)|null $renderSite
     *        Renders a site template; without it, a site template cannot be used
     * @param (Closure(string $message, string $category, array<string, mixed> $params): string)|null $translate
     *        Translates the default template's copy; without it, `{name}` params are filled in
     *        and the source text is used
     * @param (Closure(string $message): void)|null $onError Told about each render that failed
     * @param string|false $cache Where Twig keeps the compiled default template, or false
     */
    public function __construct(
        private readonly ?string $siteTemplate = null,
        private readonly ?Closure $renderSite = null,
        private readonly ?Closure $translate = null,
        private readonly ?Closure $onError = null,
        private readonly string|false $cache = false,
    ) {
    }

    /**
     * The placeholder for one gated element. Width and height are the iframe's attribute values
     * as the browser reads them (entity-decoded); the templates escape them.
     */
    public function render(string $category, ?string $vendor, string $kind, ?string $width, ?string $height): string
    {
        $variables = [
            'category' => $category,
            'vendor' => $vendor,
            'kind' => $kind,
            'width' => $width,
            'height' => $height,
        ];
        $key = serialize($variables);

        return $this->rendered[$key] ??= $this->renderUncached($variables);
    }

    /**
     * `render()` as the rewriter's placeholder callback.
     *
     * @return Closure(string, ?string, string, ?string, ?string): string
     */
    public function renderer(): Closure
    {
        return $this->render(...);
    }

    /**
     * @param array<string, string|null> $variables
     */
    private function renderUncached(array $variables): string
    {
        if ($this->siteTemplate !== null && $this->renderSite !== null && !$this->siteTemplateFailed) {
            try {
                return trim(($this->renderSite)($this->siteTemplate, $variables));
            } catch (Throwable $e) {
                // Not tried again this request, so a broken template is one log line per page.
                $this->siteTemplateFailed = true;
                $this->error("The placeholder template `$this->siteTemplate` failed, using the default: " . $e->getMessage());
            }
        }

        try {
            return trim($this->twig()->render(self::TEMPLATE, $variables));
        } catch (Throwable $e) {
            $this->error('The default placeholder failed, so none was inserted: ' . $e->getMessage());
            return '';
        }
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(new FilesystemLoader(dirname(__DIR__) . '/templates'), [
                'autoescape' => 'html',
                'strict_variables' => true,
                'cache' => $this->cache,
            ]);
            // The same call shape as Craft's `t` filter, so the template reads like any Craft one.
            $this->twig->addFilter(new TwigFilter('t', $this->translateFilter(...)));
        }
        return $this->twig;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function translateFilter(string $message, string $category = 'site', array $params = []): string
    {
        if ($this->translate !== null) {
            return ($this->translate)($message, $category, $params);
        }
        $pairs = [];
        foreach ($params as $name => $value) {
            $pairs['{' . $name . '}'] = is_scalar($value) ? (string)$value : '';
        }
        return strtr($message, $pairs);
    }

    private function error(string $message): void
    {
        if ($this->onError !== null) {
            ($this->onError)($message);
        }
    }
}
