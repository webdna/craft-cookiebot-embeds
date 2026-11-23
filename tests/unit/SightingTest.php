<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\records\Sighting;

/**
 * The report record's mapping (spec section 4, BR-23): table name, the values the rewriter emits
 * fit their columns, and a sighting maps to a row cut to fit.
 */
final class SightingTest extends TestCase
{
    public function testTableCarriesThePluginPrefix(): void
    {
        self::assertSame('{{%cookiebotembeds_sightings}}', Sighting::tableName());
    }

    public function testUniqueKeyIsSiteHostActionKind(): void
    {
        self::assertSame(['siteId', 'host', 'action', 'kind'], Sighting::UNIQUE_KEY);
    }

    public function testEveryRewriterActionAndKindIsListedAndFitsItsColumn(): void
    {
        $constants = (new ReflectionClass(RewriteResult::class))->getConstants();
        $actions = array_values(array_filter($constants, fn($k) => str_starts_with($k, 'ACTION_'), ARRAY_FILTER_USE_KEY));
        $kinds = array_values(array_filter($constants, fn($k) => str_starts_with($k, 'KIND_'), ARRAY_FILTER_USE_KEY));

        self::assertEqualsCanonicalizing($actions, Sighting::ACTIONS);
        self::assertEqualsCanonicalizing($kinds, Sighting::KINDS);

        foreach (Sighting::ACTIONS as $action) {
            self::assertLessThanOrEqual(Sighting::ACTION_LENGTH, strlen($action), $action);
        }
        foreach (Sighting::KINDS as $kind) {
            self::assertLessThanOrEqual(Sighting::KIND_LENGTH, strlen($kind), $kind);
        }
    }

    public function testEveryCategoryFitsItsColumn(): void
    {
        self::assertContains('infra', Sighting::CATEGORIES);
        foreach (Sighting::CATEGORIES as $category) {
            self::assertLessThanOrEqual(Sighting::CATEGORY_LENGTH, strlen($category), $category);
        }
    }

    public function testMapsASightingToARow(): void
    {
        $row = Sighting::row(3, [
            'host' => 'www.youtube-nocookie.com',
            'vendor' => 'YouTube',
            'category' => 'marketing',
            'action' => RewriteResult::ACTION_GATED,
            'kind' => RewriteResult::KIND_IFRAME,
        ], '/news/a-story?utm_source=x#top');

        self::assertSame([
            'siteId' => 3,
            'host' => 'www.youtube-nocookie.com',
            'vendor' => 'YouTube',
            'category' => 'marketing',
            'action' => 'gated',
            'kind' => 'iframe',
            'examplePath' => '/news/a-story',
        ], $row);
    }

    public function testUnknownHostHasNullVendorAndFingerprintOnlyHasEmptyHost(): void
    {
        $unknown = Sighting::row(1, ['host' => 'Widgets.Example.ORG', 'vendor' => null, 'category' => 'marketing', 'action' => 'gated', 'kind' => 'script'], '/');
        self::assertSame('widgets.example.org', $unknown['host']);
        self::assertNull($unknown['vendor']);

        $fingerprint = Sighting::row(1, ['host' => '', 'vendor' => '', 'category' => 'statistics', 'action' => 'gated', 'kind' => 'inline'], '/');
        self::assertSame('', $fingerprint['host']);
        self::assertNull($fingerprint['vendor']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function paths(): array
    {
        return [
            'home, empty' => ['', '/'],
            'home, slash' => ['/', '/'],
            'no leading slash (Craft full path)' => ['news/a-story', '/news/a-story'],
            'query string' => ['/search?q=a+b', '/search'],
            'fragment' => ['/about#team', '/about'],
            'query only' => ['?token=abc', '/'],
            'multibyte kept' => ["/caf\u{e9}", "/caf\u{e9}"],
        ];
    }

    #[DataProvider('paths')]
    public function testExamplePath(string $path, string $expected): void
    {
        self::assertSame($expected, Sighting::examplePath($path));
    }

    public function testLongValuesAreCutByCharactersToFitTheirColumns(): void
    {
        $row = Sighting::row(1, [
            'host' => str_repeat('a', 300) . '.example.com',
            'vendor' => str_repeat("\u{e9}", 150),
            'category' => 'marketing',
            'action' => 'gated',
            'kind' => 'script',
        ], '/' . str_repeat("\u{e9}", 400));

        self::assertSame(Sighting::HOST_LENGTH, mb_strlen($row['host']));
        self::assertSame(Sighting::VENDOR_LENGTH, mb_strlen((string) $row['vendor']));
        self::assertTrue(mb_check_encoding((string) $row['vendor'], 'UTF-8'));
        self::assertSame(Sighting::PATH_LENGTH, mb_strlen($row['examplePath']));
        self::assertTrue(mb_check_encoding($row['examplePath'], 'UTF-8'));
    }
}
