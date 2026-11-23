<?php

namespace webdna\cookiebotembeds\services;

use Closure;
use webdna\cookiebotembeds\models\Classification;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\models\SiteConfig;

/**
 * Gates third-party scripts and iframes in a page's HTML for Cookiebot (BR-4, BR-5, BR-10 to
 * BR-16).
 *
 * A pure service: no `Craft::$app`, no logging, never throws on any input. The page is never
 * parsed into a tree and re-serialised (BR-4). A small scanner finds `<script>` and `<iframe>`
 * start tags and reads their attributes with byte offsets, and only those tags are spliced. Every
 * other byte, Alpine's `@click` and `:src` included, is copied through unchanged.
 *
 * The scanner follows the browser where it matters: a script ends at the first `</script`
 * whatever the JavaScript says, and comments and `<textarea>` contents are not markup. A start tag
 * with no closing `>` is left alone and ends the scan (TN-3).
 *
 * @phpstan-type Attribute array{name: string, nameStart: int, nameEnd: int, start: int, end: int, value: string|null}
 * @phpstan-type Tag array{start: int, nameEnd: int, end: int, lastAttributeEnd: int, attributes: list<Attribute>}
 */
final class Rewriter
{
    /**
     * Every `type` a browser runs as a classic script (the HTML standard's JavaScript MIME type
     * essences), plus the empty type. Anything else is data or a template and is never touched
     * (BR-12). BR-12 names only the common two; the legacy ones run too, so they are gated too.
     */
    public const JS_TYPES = [
        '', 'text/javascript', 'application/javascript', 'application/ecmascript',
        'application/x-ecmascript', 'application/x-javascript', 'text/ecmascript',
        'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3',
        'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript',
        'text/x-ecmascript', 'text/x-javascript',
    ];

    /** Strictest first (BR-11). */
    private const STRICTNESS = ['marketing', 'statistics', 'preferences'];

    /**
     * URLs inside an inline script body. An absolute `http(s):` URL anywhere; a protocol-relative
     * `//host` only straight after a quote, so a `//comment.like.this` is not taken for a URL.
     * `\/` (JSON-escaped) counts as `/`.
     */
    private const INLINE_URL = '~(?:\bhttps?:|(?<=["\'`]))(?:\\\\?/){2}[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+(?::\d+)?(?:(?:\\\\?/)[^\s"\'`<>()\\\\]*)*~i';

    private const WHITESPACE = " \t\n\r\f";

    /** @var array<string, array{host: string, vendor: string|null, category: string, action: string, kind: string}> */
    private array $sightings = [];

    /**
     * @param Closure(string $category, ?string $vendor, string $kind, ?string $width, ?string $height): string|null $placeholder
     *        Renders the placeholder (BR-17; task 2.3). Null inserts none.
     */
    public function __construct(
        private readonly Classifier $classifier,
        private readonly Vendors $vendors,
        private readonly SiteConfig $config,
        private readonly ?Closure $placeholder = null,
    ) {
    }

    public function rewrite(string $html): RewriteResult
    {
        // BR-5: nothing to gate without a script or an iframe.
        if (stripos($html, '<script') === false && stripos($html, '<iframe') === false) {
            return new RewriteResult($html);
        }

        $this->sightings = [];
        /** @var list<array{int, int, string}> $edits [start, end, replacement], ascending */
        $edits = [];
        $length = strlen($html);
        // A page with a <head> or <html> starts outside the body; a fragment is all body (TN-17).
        $inBody = preg_match('~<(?:head|html)[\s>/]~i', $html) !== 1;
        $offset = 0;

        while ($offset < $length && preg_match('~<!--|<(script|iframe|textarea|body)(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $start = $m[0][1];

            if ($m[0][0] === '<!--') {
                $close = strpos($html, '-->', $start + 4);
                if ($close === false) {
                    break;
                }
                $offset = $close + 3;
                continue;
            }

            $name = strtolower($m[1][0]);
            $tag = self::parseTag($html, $start);
            if ($tag === null) {
                break;
            }

            if ($name === 'body') {
                $inBody = true;
                $offset = $tag['end'];
                continue;
            }

            // The element's end: just past `</name ...>`, or null when it is never closed.
            $closeStart = preg_match('~</' . $name . '(?=[\s/>])~i', $html, $c, PREG_OFFSET_CAPTURE, $tag['end']) === 1 ? $c[0][1] : null;
            $closeGt = $closeStart === null ? false : strpos($html, '>', $closeStart);
            $closeEnd = $closeGt === false ? null : $closeGt + 1;

            if ($name === 'textarea') {
                $offset = $closeEnd ?? $length;
                continue;
            }

            if ($name === 'script') {
                // An unclosed script runs to the end of the page in a browser: leave it (TN-3).
                if ($closeStart === null) {
                    break;
                }
                $body = substr($html, $tag['end'], $closeStart - $tag['end']);
                $edit = $this->script($html, $tag, $body, $inBody);
                if ($edit !== null) {
                    $edits[] = $edit;
                }
            } else {
                foreach ($this->iframe($html, $tag, $closeEnd) as $edit) {
                    $edits[] = $edit;
                }
            }

            // Script and iframe contents are text, not markup.
            $offset = $closeEnd ?? $tag['end'];
        }

        return new RewriteResult(self::apply($html, $edits), $this->takeSightings());
    }

    /**
     * @return list<array{host: string, vendor: string|null, category: string, action: string, kind: string}>
     */
    private function takeSightings(): array
    {
        $sightings = array_values($this->sightings);
        $this->sightings = [];
        return $sightings;
    }

    /**
     * Reads the start tag at `$start` (which points at its `<`) without decoding anything.
     * Attribute names are lower-cased; values are raw (entities intact, quotes removed), null for
     * a bare attribute. Offsets are into `$html`. Null when the tag never closes (TN-3).
     *
     * @return Tag|null
     */
    public static function parseTag(string $html, int $start): ?array
    {
        $length = strlen($html);
        $i = $start + 1;
        $i += strspn($html, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-', $i);
        $nameEnd = $i;
        $lastAttributeEnd = $nameEnd;
        $attributes = [];

        while ($i < $length) {
            $i += strspn($html, self::WHITESPACE . '/', $i);
            if ($i >= $length) {
                break;
            }
            if ($html[$i] === '>') {
                return ['start' => $start, 'nameEnd' => $nameEnd, 'end' => $i + 1, 'lastAttributeEnd' => $lastAttributeEnd, 'attributes' => $attributes];
            }

            // A name runs to whitespace, `/`, `>` or `=`; its first character may be anything.
            $attributeStart = $i;
            $i += 1 + strcspn($html, self::WHITESPACE . '/>=', $i + 1);
            $attributeNameEnd = $i;
            $value = null;

            $j = $i + strspn($html, self::WHITESPACE, $i);
            if ($j < $length && $html[$j] === '=') {
                $j++;
                $j += strspn($html, self::WHITESPACE, $j);
                if ($j >= $length) {
                    return null;
                }
                $quote = $html[$j];
                if ($quote === '"' || $quote === "'") {
                    $close = strpos($html, $quote, $j + 1);
                    if ($close === false) {
                        return null;
                    }
                    $value = substr($html, $j + 1, $close - $j - 1);
                    $i = $close + 1;
                } else {
                    $n = strcspn($html, self::WHITESPACE . '>', $j);
                    $value = substr($html, $j, $n);
                    $i = $j + $n;
                }
            }

            $attributes[] = [
                'name' => strtolower(substr($html, $attributeStart, $attributeNameEnd - $attributeStart)),
                'nameStart' => $attributeStart,
                'nameEnd' => $attributeNameEnd,
                'start' => $attributeStart,
                'end' => $i,
                'value' => $value,
            ];
            $lastAttributeEnd = $i;
        }

        return null;
    }

    /**
     * The first occurrence of each attribute, decoded, as the browser reads it.
     *
     * @param list<Attribute> $attributes
     * @return array<string, string|null>
     */
    private static function attributeMap(array $attributes): array
    {
        $map = [];
        foreach ($attributes as $attribute) {
            if (!array_key_exists($attribute['name'], $map)) {
                $map[$attribute['name']] = $attribute['value'] === null
                    ? null
                    : html_entity_decode($attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $map;
    }

    /**
     * @param Tag $tag
     * @return array{int, int, string}|null
     */
    private function script(string $html, array $tag, string $body, bool $inBody): ?array
    {
        $attributes = self::attributeMap($tag['attributes']);
        $src = array_key_exists('src', $attributes) ? trim($attributes['src'] ?? '') : null;
        $kind = $src === null ? RewriteResult::KIND_INLINE : RewriteResult::KIND_SCRIPT;

        // BR-8: already marked up; recorded, never touched.
        if (Classifier::isAlreadyMarked('script', $attributes)) {
            $found = $src === null ? $this->inlineDecision($body, record: false) : $this->classifier->classify($src);
            $this->see($found, RewriteResult::ACTION_ALREADY, $kind, always: true);
            return null;
        }

        // BR-12: data, templates and anything else a browser does not run.
        $type = strtolower(trim($attributes['type'] ?? ''));
        if ($type !== 'module' && !in_array($type, self::JS_TYPES, true)) {
            return null;
        }

        // BR-9: Cookiebot's own elements by id.
        if (Classifier::isInfraById($attributes)) {
            return null;
        }

        // An empty src loads nothing and runs nothing.
        if ($src === '') {
            return null;
        }

        $found = $src === null ? $this->inlineDecision($body) : $this->classifier->classify($src);
        if ($found === null || !$found->isGated()) {
            if ($found !== null) {
                $this->see($found, null, $kind);
            }
            return null;
        }

        // BR-13: Cookiebot would release a module as a classic script, which fails.
        if ($type === 'module') {
            $this->see($found, RewriteResult::ACTION_SKIPPED, $kind);
            return null;
        }

        $this->see($found, RewriteResult::ACTION_GATED, $kind);

        // BR-10: type replaced (or added first), consent attributes appended, nothing else moved.
        $edits = [];
        $typeAttribute = self::first($tag['attributes'], 'type');
        if ($typeAttribute !== null) {
            $edits[] = [$typeAttribute['start'], $typeAttribute['end'], 'type="text/plain"'];
        } else {
            $edits[] = [$tag['nameEnd'], $tag['nameEnd'], ' type="text/plain"'];
        }
        $edits[] = [$tag['lastAttributeEnd'], $tag['lastAttributeEnd'], self::consentAttributes($found->category)];
        $rewritten = self::apply(substr($html, 0, $tag['end']), $edits, from: $tag['start']);

        // BR-16: a widget in the body gets its placeholder just before it.
        $before = $inBody ? $this->renderPlaceholder($found, $kind, null, null) : '';

        return [$tag['start'], $tag['end'], $before . $rewritten];
    }

    /**
     * @param Tag $tag
     * @param int|null $after The offset just past `</iframe>`, or null when it is never closed
     * @return list<array{int, int, string}>
     */
    private function iframe(string $html, array $tag, ?int $after): array
    {
        $attributes = self::attributeMap($tag['attributes']);
        $src = trim($attributes['src'] ?? '');

        if (Classifier::isAlreadyMarked('iframe', $attributes)) {
            $url = $src !== '' ? $src : trim($attributes['data-cookieblock-src'] ?? '');
            $this->see($this->classifier->classify($url), RewriteResult::ACTION_ALREADY, RewriteResult::KIND_IFRAME, always: true);
            return [];
        }

        // BR-15: nothing loaded from elsewhere.
        if ($src === '' || strtolower($src) === 'about:blank' || array_key_exists('srcdoc', $attributes)) {
            return [];
        }

        $found = $this->classifier->classify($src);
        if (!$found->isGated()) {
            $this->see($found, null, RewriteResult::KIND_IFRAME);
            return [];
        }
        $this->see($found, RewriteResult::ACTION_GATED, RewriteResult::KIND_IFRAME);

        // BR-15: every `src` renamed (a browser would fall back to a second one), consent
        // attributes appended, nothing else moved.
        $edits = [];
        foreach ($tag['attributes'] as $attribute) {
            if ($attribute['name'] === 'src') {
                $edits[] = [$attribute['nameStart'], $attribute['nameEnd'], 'data-cookieblock-src'];
            }
        }
        $edits[] = [$tag['lastAttributeEnd'], $tag['lastAttributeEnd'], self::consentAttributes($found->category)];
        $result = [[$tag['start'], $tag['end'], self::apply(substr($html, 0, $tag['end']), $edits, from: $tag['start'])]];

        if ($after !== null) {
            $placeholder = $this->renderPlaceholder($found, RewriteResult::KIND_IFRAME, $attributes['width'] ?? null, $attributes['height'] ?? null);
            if ($placeholder !== '') {
                $result[] = [$after, $after, $placeholder];
            }
        }
        return $result;
    }

    /**
     * What decides an inline script (BR-11): a Cookiebot or Tag Manager URL if it names one (such a
     * body is never gated: BR-9, B3); otherwise the strictest gated category among its URLs and
     * fingerprints; otherwise the first third party it names, which is left; otherwise null.
     * With `$record`, every ungated third party it names is recorded too.
     */
    private function inlineDecision(string $body, bool $record = true): ?Classification
    {
        /** @var list<Classification> $gated */
        $gated = [];
        $ungated = [];

        if (preg_match_all(self::INLINE_URL, $body, $matches) > 0) {
            foreach (array_unique($matches[0]) as $url) {
                $found = $this->classifier->classify(str_replace('\\/', '/', $url));
                if ($found->category === 'infra') {
                    return $found;
                }
                if ($found->isGated()) {
                    $gated[] = $found;
                } elseif ($found->source !== Classification::SOURCE_FIRST_PARTY) {
                    $ungated[] = $found;
                }
            }
        }

        $fingerprints = [
            Classification::SOURCE_SITE => Vendors::matchingFingerprints($this->config->fingerprints, $body),
            Classification::SOURCE_LIST => $this->vendors->fingerprintsIn($body),
        ];
        foreach ($fingerprints as $source => $matched) {
            foreach ($matched as $fingerprint) {
                $found = new Classification('', $fingerprint['vendor'], $fingerprint['category'], $source);
                if ($found->category === 'infra') {
                    return $found;
                }
                $gated[] = $found;
            }
        }

        if ($record) {
            foreach ($ungated as $found) {
                $this->see($found, null, RewriteResult::KIND_INLINE);
            }
        }

        foreach (self::STRICTNESS as $category) {
            foreach ($gated as $found) {
                if ($found->category === $category) {
                    return $found;
                }
            }
        }
        return $ungated[0] ?? null;
    }

    /**
     * Records a sighting once per host, action and kind. With no action, it is `allowed` for the
     * site's allow list and filesystems and `left` for other ungated third parties; first-party
     * URLs are not recorded unless `$always`.
     */
    private function see(?Classification $found, ?string $action, string $kind, bool $always = false): void
    {
        $found ??= new Classification('', null, 'necessary', Classification::SOURCE_FIRST_PARTY);
        if ($found->source === Classification::SOURCE_FIRST_PARTY && !$always) {
            return;
        }
        $action ??= in_array($found->source, [Classification::SOURCE_ALLOW, Classification::SOURCE_FILESYSTEM], true)
            ? RewriteResult::ACTION_ALLOWED
            : RewriteResult::ACTION_LEFT;

        $this->sightings["$found->host|$action|$kind"] ??= [
            'host' => $found->host,
            'vendor' => $found->vendor,
            'category' => $found->category,
            'action' => $action,
            'kind' => $kind,
        ];
    }

    private function renderPlaceholder(Classification $found, string $kind, ?string $width, ?string $height): string
    {
        return $this->placeholder === null ? '' : ($this->placeholder)($found->category, $found->vendor, $kind, $width, $height);
    }

    private static function consentAttributes(string $category): string
    {
        // The second is the section 7 test hook: plugin-gated, not hand-gated.
        return sprintf(' data-cookieconsent="%1$s" data-cbe-gated="%1$s"', $category);
    }

    /**
     * @param list<Attribute> $attributes
     * @return Attribute|null
     */
    private static function first(array $attributes, string $name): ?array
    {
        foreach ($attributes as $attribute) {
            if ($attribute['name'] === $name) {
                return $attribute;
            }
        }
        return null;
    }

    /**
     * Applies non-overlapping `[start, end, replacement]` edits, in ascending order, to
     * `$html`, returning the result from offset `$from` onwards.
     *
     * @param list<array{int, int, string}> $edits
     */
    private static function apply(string $html, array $edits, int $from = 0): string
    {
        $out = '';
        $cursor = $from;
        foreach ($edits as [$start, $end, $replacement]) {
            $out .= substr($html, $cursor, $start - $cursor) . $replacement;
            $cursor = $end;
        }
        return $out . substr($html, $cursor);
    }
}
