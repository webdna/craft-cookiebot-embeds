<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use webdna\cookiebotembeds\models\Settings;
use webdna\cookiebotembeds\models\SiteConfig;
use webdna\cookiebotembeds\services\Config;

/**
 * Config normalisation (BR-2, BR-27, TN-10) and multi-site keys.
 */
final class ConfigTest extends TestCase
{
    private const HANDLES = ['default', 'shop'];

    /**
     * @param array<array-key, mixed> $raw
     */
    private function resolve(array $raw, string $site = 'default'): SiteConfig
    {
        return (new Config())->resolve($raw, $site, self::HANDLES);
    }

    public function testEmptyConfigGivesTheDefaults(): void
    {
        $config = $this->resolve([]);

        self::assertEquals(new SiteConfig(), $config);
        self::assertTrue($config->enabled, 'BR-2: enabled by default');
        self::assertSame('standard', $config->mode);
        self::assertSame('consent.cookiebot.com', $config->cookiebotDomain);
        self::assertFalse($config->injectCookiebot);
        self::assertTrue($config->report);
        self::assertSame([], $config->warnings);
    }

    public function testTheSettingsModelsUnsetValuesGiveTheDefaults(): void
    {
        $config = $this->resolve((new Settings())->toArray());

        self::assertEquals(new SiteConfig(), $config);
    }

    public function testTheSettingsModelTakesAnyTypeWithoutThrowing(): void
    {
        $settings = new Settings();
        $settings->setAttributes([
            'enabled' => ['not', 'a', 'bool'],
            'allow' => 'example.com',
            'report' => new stdClass(),
            'unknownKey' => true,
        ], false);

        $config = $this->resolve($settings->toArray());

        self::assertTrue($config->enabled);
        self::assertSame([], $config->allow);
        self::assertTrue($config->report);
        self::assertCount(3, $config->warnings);
    }

    public function testValidValuesAreKeptAndNormalised(): void
    {
        $config = $this->resolve([
            'enabled' => false,
            'mode' => ' Vue ',
            'allow' => ['Booking.Example.com ', 'booking.example.com', 'pay.example.org'],
            'vendors' => [
                ['match' => 'Widgets.Example.org/embed', 'vendor' => 'Example Widgets', 'category' => 'Preferences'],
                ['match' => 'stats.example.net', 'category' => 'statistics'],
            ],
            'fingerprints' => [
                ['pattern' => 'exampleTracker\.init\(', 'vendor' => 'Example Tracker', 'category' => 'statistics'],
            ],
            'placeholderTemplate' => ' _partials/placeholder ',
            'injectCookiebot' => 'true',
            'cbid' => ' 00000000-0000-0000-0000-000000000000 ',
            'cookiebotDomain' => 'consent.cookiebot.eu',
            'report' => 0,
        ]);

        self::assertSame([], $config->warnings);
        self::assertFalse($config->enabled);
        self::assertSame('vue', $config->mode);
        self::assertSame(['booking.example.com', 'pay.example.org'], $config->allow);
        self::assertSame([
            ['match' => 'widgets.example.org/embed', 'vendor' => 'Example Widgets', 'category' => 'preferences'],
            ['match' => 'stats.example.net', 'vendor' => 'stats.example.net', 'category' => 'statistics'],
        ], $config->vendors);
        self::assertSame('~exampleTracker\.init\(~', $config->fingerprints[0]['pattern']);
        self::assertSame(1, preg_match($config->fingerprints[0]['pattern'], 'window.exampleTracker.init({id: 1})'));
        self::assertSame('_partials/placeholder', $config->placeholderTemplate);
        self::assertTrue($config->injectCookiebot);
        self::assertSame('00000000-0000-0000-0000-000000000000', $config->cbid);
        self::assertSame('consent.cookiebot.eu', $config->cookiebotDomain);
        self::assertFalse($config->report);
    }

    /**
     * TN-10: each invalid value is replaced by its default with one warning, and never throws.
     *
     * @param array<string, mixed> $raw
     */
    #[DataProvider('invalidConfig')]
    public function testInvalidValuesFallBackToTheDefaultWithOneWarning(array $raw, string $property, mixed $expected, string $warning): void
    {
        $config = $this->resolve($raw);

        self::assertSame($expected, $config->$property);
        self::assertCount(1, $config->warnings);
        self::assertStringContainsString($warning, $config->warnings[0]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string, mixed, string}>
     */
    public static function invalidConfig(): array
    {
        return [
            'unknown mode' => [['mode' => 'react'], 'mode', 'standard', '`mode` must be one of standard, vue; got "react"'],
            'string where an array is expected' => [['allow' => 'booking.example.com'], 'allow', [], '`allow` must be a list'],
            'keyed array where a list is expected' => [['vendors' => ['match' => 'example.org']], 'vendors', [], '`vendors` must be a list'],
            'unknown category' => [
                ['vendors' => [['match' => 'example.org', 'vendor' => 'Example', 'category' => 'advertising']]],
                'vendors', [], '`vendors[0].category` must be one of necessary, preferences, statistics, marketing, infra',
            ],
            'non-compiling fingerprint' => [
                ['fingerprints' => [['pattern' => 'exampleTracker(', 'vendor' => 'Example', 'category' => 'marketing']]],
                'fingerprints', [], '`fingerprints[0].pattern` is not a valid regular expression',
            ],
            'entry missing its match' => [['vendors' => [['vendor' => 'Example', 'category' => 'marketing']]], 'vendors', [], '`vendors[0].match`'],
            'entry not an array' => [['vendors' => ['example.org']], 'vendors', [], '`vendors[0]` must be an array'],
            'empty allow host' => [['allow' => ['  ']], 'allow', [], '`allow[0]`'],
            'bool not a bool' => [['enabled' => 'sometimes'], 'enabled', true, '`enabled` must be true or false'],
            'array for a bool' => [['injectCookiebot' => [true]], 'injectCookiebot', false, 'got array'],
            'unknown cookiebot domain' => [['cookiebotDomain' => 'consent.example.com'], 'cookiebotDomain', 'consent.cookiebot.com', '`cookiebotDomain`'],
            'cbid not a string' => [['cbid' => 12345], 'cbid', null, '`cbid` must be a string'],
            'object for a template' => [['placeholderTemplate' => new stdClass()], 'placeholderTemplate', null, 'got stdClass'],
        ];
    }

    public function testOneBadEntryDropsOnlyThatEntry(): void
    {
        $config = $this->resolve(['vendors' => [
            ['match' => 'good.example.org', 'vendor' => 'Good', 'category' => 'necessary'],
            ['match' => 'bad.example.org', 'vendor' => 'Bad', 'category' => 'nope'],
            ['match' => 'cdn.example.net', 'vendor' => 'CDN', 'category' => 'infra'],
        ]]);

        self::assertSame(['good.example.org', 'cdn.example.net'], array_column($config->vendors, 'match'));
        self::assertCount(1, $config->warnings);
    }

    public function testCompilePatternEscapesTheDelimiterAndRejectsBadPatterns(): void
    {
        $pattern = Config::compilePattern('a~b');
        self::assertNotNull($pattern);
        self::assertSame(1, preg_match($pattern, 'xa~by'));

        self::assertNull(Config::compilePattern('('));
        self::assertNull(Config::compilePattern(' '));
    }

    public function testPerSiteArraysResolveByHandleThenStarThenDefault(): void
    {
        $raw = [
            'enabled' => ['shop' => false],
            'mode' => ['*' => 'vue', 'shop' => 'standard'],
            'allow' => ['default' => ['booking.example.com'], 'shop' => ['pay.example.org']],
            'cbid' => ['*' => 'abc'],
        ];

        $default = $this->resolve($raw, 'default');
        self::assertTrue($default->enabled, 'not listed and no *: the default');
        self::assertSame('vue', $default->mode, 'not listed: the * value');
        self::assertSame(['booking.example.com'], $default->allow);
        self::assertSame('abc', $default->cbid);
        self::assertSame([], $default->warnings);

        $shop = $this->resolve($raw, 'shop');
        self::assertFalse($shop->enabled);
        self::assertSame('standard', $shop->mode);
        self::assertSame(['pay.example.org'], $shop->allow);
    }

    public function testAPerSiteValueIsStillValidated(): void
    {
        $config = $this->resolve(['mode' => ['default' => 'react']]);

        self::assertSame('standard', $config->mode);
        self::assertCount(1, $config->warnings);
    }

    public function testAnArrayWithAKeyThatIsNoSiteHandleIsNotPerSite(): void
    {
        // A typo in a handle makes the whole array an ordinary (invalid) value, which is reported.
        $config = $this->resolve(['enabled' => ['default' => false, 'shpo' => true]]);

        self::assertTrue($config->enabled);
        self::assertCount(1, $config->warnings);
    }

    public function testResolvingTwiceDoesNotCarryWarningsOver(): void
    {
        $service = new Config();
        $service->resolve(['mode' => 'react'], 'default', self::HANDLES);

        self::assertSame([], $service->resolve([], 'default', self::HANDLES)->warnings);
    }
}
