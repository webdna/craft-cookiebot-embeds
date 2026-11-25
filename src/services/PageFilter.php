<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use craft\helpers\FileHelper;
use Throwable;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\models\SiteConfig;

/**
 * The decisions the render hook makes about a page (BR-1, BR-2, BR-5), as pure functions the unit
 * tests can reach. The plugin class reads the request and response and passes the facts in;
 * nothing here calls `Craft::$app`.
 *
 * @internal Not part of the plugin's public API; it may change in any release.
 */
final class PageFilter
{
    /** Craft's site template mode (`craft\web\View::TEMPLATE_MODE_SITE`). */
    public const TEMPLATE_MODE_SITE = 'site';

    /**
     * BR-1: only page templates rendered in site mode for a site request. Control-panel and
     * console requests never are. An action request is not, unless it is a preview: Live Preview
     * pages are site pages (TN-14).
     */
    public static function isSitePage(bool $isConsole, bool $isCp, bool $isAction, bool $isPreview, string $templateMode): bool
    {
        if ($isConsole || $isCp || $templateMode !== self::TEMPLATE_MODE_SITE) {
            return false;
        }
        return !$isAction || $isPreview;
    }

    /**
     * BR-1: only HTML responses. Mirrors Craft's TemplateResponseFormatter, which runs after the
     * render: a Content-Type the template set (with `{% header %}`) wins; otherwise the type comes
     * from the template file's extension once `.twig` is removed, and is HTML when there is none.
     *
     * @param string|null $contentType The response's Content-Type header, null when none is set
     * @param string|null $templateFile The resolved template path, null when it did not resolve
     */
    public static function isHtml(?string $contentType, ?string $templateFile): bool
    {
        if ($contentType === null || trim($contentType) === '') {
            $file = strtolower($templateFile ?? '');
            if (str_ends_with($file, '.twig')) {
                $file = substr($file, 0, -5);
            }
            $contentType = FileHelper::getMimeTypeByExtension($file) ?? 'text/html';
        }
        return strtolower(trim(explode(';', $contentType, 2)[0])) === 'text/html';
    }

    /**
     * The distinct lower-case hosts of the given URLs; relative URLs, empty values and URLs with
     * no host are dropped.
     *
     * @param iterable<string|null> $urls
     * @return list<string>
     */
    public static function hosts(iterable $urls): array
    {
        $hosts = [];
        foreach ($urls as $url) {
            if ($url === null || $url === '') {
                continue;
            }
            $host = parse_url(str_starts_with($url, '//') ? "https:$url" : $url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[strtolower($host)] = true;
            }
        }
        return array_keys($hosts);
    }

    /**
     * BR-2, BR-5 and the B3 guardrail for one page: null when the page is left as it is
     * (disabled, nothing to gate, or the rewrite failed), otherwise the rewrite. The rewriter is
     * built only when it is needed. Never throws.
     *
     * @param Closure(): Rewriter $rewriter Builds the rewriter for this page's site
     * @param (Closure(string $message): void)|null $onError Told when the rewrite failed
     */
    public static function filter(string $html, SiteConfig $config, Closure $rewriter, ?Closure $onError = null): ?RewriteResult
    {
        if (!$config->enabled || !Rewriter::mightRewrite($html)) {
            return null;
        }
        try {
            return $rewriter()->rewrite($html);
        } catch (Throwable $e) {
            if ($onError !== null) {
                $onError('The page was served unchanged because the rewrite failed: ' . $e->getMessage());
            }
            return null;
        }
    }
}
