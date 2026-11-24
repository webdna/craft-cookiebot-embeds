<?php

namespace webdna\cookiebotembeds\tests\unit;

use PHPUnit\Framework\TestCase;
use webdna\cookiebotembeds\utilities\Report;

/**
 * The report utility's rows (AC-7): site name, example link, last seen, and the config lines
 * shown for an unknown host.
 */
final class ReportUtilityTest extends TestCase
{
    /**
     * @return array{siteId: string, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string, dateUpdated: string}
     */
    private static function row(string $host, ?string $vendor, string $action = 'gated', string $kind = 'script', string $siteId = '1'): array
    {
        return [
            'siteId' => $siteId,
            'host' => $host,
            'vendor' => $vendor,
            'category' => 'marketing',
            'action' => $action,
            'kind' => $kind,
            'examplePath' => '/news/a-story',
            'dateUpdated' => '2026-11-23 10:15:00',
        ];
    }

    public function testPresentsARowWithItsSiteLinkAndLastSeen(): void
    {
        [$row] = Report::present(
            [self::row('www.youtube.com', 'YouTube', 'gated', 'iframe')],
            [1 => 'Main site'],
            [1 => 'https://www.example.com/'],
        );

        $this->assertSame('Main site', $row['site']);
        $this->assertSame('YouTube', $row['vendor']);
        $this->assertSame('https://www.example.com/news/a-story', $row['url']);
        $this->assertSame('2026-11-23T10:15:00+00:00', $row['lastSeen']->format(DATE_ATOM));
        $this->assertFalse($row['unknown']);
        $this->assertSame([], $row['configLines']);
    }

    /**
     * An unknown host shows the lines that allow it or name it (section 6, Screens).
     */
    public function testAnUnknownHostShowsItsConfigLines(): void
    {
        [$row] = Report::present([self::row('cdn.example.net', null)], [1 => 'Main'], [1 => 'https://www.example.com']);

        $this->assertTrue($row['unknown']);
        $this->assertSame([
            "'allow' => ['cdn.example.net'],",
            "'vendors' => [['match' => 'cdn.example.net', 'vendor' => 'Provider name', 'category' => 'marketing']],",
        ], $row['configLines']);
    }

    /**
     * The same rule as the log: allowed hosts, named providers and fingerprint-only inline
     * scripts are not unknown.
     */
    public function testOnlyUnlistedHostsAreUnknown(): void
    {
        $rows = Report::present([
            self::row('widgets.example.org', null, 'allowed'),
            self::row('www.youtube.com', 'YouTube'),
            self::row('', 'Some Pixel', 'gated', 'inline'),
            self::row('cdn.example.net', null, 'already'),
        ], [], []);

        $this->assertSame([false, false, false, true], array_column($rows, 'unknown'));
    }

    public function testASiteWithoutABaseUrlHasNoLinkAndAMissingSiteShowsItsId(): void
    {
        [$row] = Report::present([self::row('cdn.example.net', null, siteId: '7')], [], [7 => null]);

        $this->assertNull($row['url']);
        $this->assertSame('7', $row['site']);
    }

    public function testConfigLinesQuoteTheHost(): void
    {
        $this->assertSame("'allow' => ['it\\'s.example.net'],", Report::configLines("it's.example.net", 'statistics')[0]);
    }
}
