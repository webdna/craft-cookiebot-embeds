<?php

namespace webdna\cookiebotembeds\records;

use craft\db\ActiveRecord;
use webdna\cookiebotembeds\models\RewriteResult;
use webdna\cookiebotembeds\services\Config;

/**
 * One row of the report: a provider host seen on a site, with what the plugin did to it (spec
 * section 4, BR-23). The table is bounded by the providers a site uses, not by its pages, and holds
 * nothing about the visitor.
 *
 * @property int $id
 * @property int $siteId
 * @property string $host Lower-case, no port; empty for an inline script known only by a fingerprint
 * @property string|null $vendor Null when the host is unknown
 * @property string $category One of the four categories, or `infra`
 * @property string $action One of {@see self::ACTIONS}
 * @property string $kind One of {@see self::KINDS}
 * @property string $examplePath The most recent page it was seen on, no query string
 * @property string $dateCreated
 * @property string $dateUpdated Last seen
 * @property string $uid
 */
class Sighting extends ActiveRecord
{
    /** Carries the plugin prefix, so uninstall drops only what the plugin made (profile, Traps). */
    public const TABLE = '{{%cookiebotembeds_sightings}}';

    public const HOST_LENGTH = 255;
    public const VENDOR_LENGTH = 100;
    public const CATEGORY_LENGTH = 20;
    public const ACTION_LENGTH = 20;
    public const KIND_LENGTH = 10;
    public const PATH_LENGTH = 255;

    /** The report's unique key. */
    public const UNIQUE_KEY = ['siteId', 'host', 'action', 'kind'];

    public const ACTIONS = [
        RewriteResult::ACTION_GATED,
        RewriteResult::ACTION_LEFT,
        RewriteResult::ACTION_ALLOWED,
        RewriteResult::ACTION_ALREADY,
        RewriteResult::ACTION_SKIPPED,
    ];

    public const KINDS = [
        RewriteResult::KIND_SCRIPT,
        RewriteResult::KIND_INLINE,
        RewriteResult::KIND_IFRAME,
    ];

    public const CATEGORIES = Config::ENTRY_CATEGORIES;

    public static function tableName(): string
    {
        return self::TABLE;
    }

    /**
     * Maps one rewriter sighting to the row's identifying and descriptive columns, cut to fit the
     * table; the dates are the recorder's. Pure, so it is tested without a database.
     *
     * @param array{host: string, vendor: string|null, category: string, action: string, kind: string} $sighting
     * @param string $path The page's path; a query string or fragment is dropped
     * @return array{siteId: int, host: string, vendor: string|null, category: string, action: string, kind: string, examplePath: string}
     */
    public static function row(int $siteId, array $sighting, string $path): array
    {
        $vendor = $sighting['vendor'];

        return [
            'siteId' => $siteId,
            'host' => self::cut(strtolower($sighting['host']), self::HOST_LENGTH),
            'vendor' => $vendor === null || $vendor === '' ? null : self::cut($vendor, self::VENDOR_LENGTH),
            'category' => $sighting['category'],
            'action' => $sighting['action'],
            'kind' => $sighting['kind'],
            'examplePath' => self::examplePath($path),
        ];
    }

    /**
     * The path with any query string or fragment removed, given a leading slash, cut to fit.
     */
    public static function examplePath(string $path): string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', $path);

        return self::cut('/' . ltrim($path, '/'), self::PATH_LENGTH);
    }

    /** Cuts by characters, not bytes, so a multibyte value is never split mid-character. */
    private static function cut(string $value, int $length): string
    {
        return mb_substr($value, 0, $length, 'UTF-8');
    }
}
