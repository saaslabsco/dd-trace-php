<?php

namespace DDTrace\Integrations\Mysql;

use DDTrace\Integrations\Mysqli\MysqliCommon;
use DDTrace\SpanData;
use DDTrace\Tag;

/**
 * Helpers for the legacy mysql extension (PHP 5 only).
 *
 * mysql_* uses link resources, not objects, so metadata cannot use ObjectKVStore.
 */
class MysqlCommon
{
    const KEY_DATABASE_NAME = 'database_name';
    const KEY_HOST_INFO = 'host_info';

    /**
     * @var array<int, array<string, mixed>>
     */
    private static $linkMeta = [];

    /**
     * @var resource|null
     */
    private static $defaultLink;

    /**
     * @param resource $link
     */
    public static function setDefaultLink($link)
    {
        if (is_resource($link)) {
            self::$defaultLink = $link;
        }
    }

    /**
     * @return resource|null
     */
    public static function getDefaultLink()
    {
        return self::$defaultLink;
    }

    /**
     * @param resource $link
     * @param string $key
     * @param mixed $value
     */
    public static function putLinkMeta($link, $key, $value)
    {
        $id = self::linkId($link);
        if ($id === null) {
            return;
        }

        if (!isset(self::$linkMeta[$id])) {
            self::$linkMeta[$id] = [];
        }

        self::$linkMeta[$id][$key] = $value;
    }

    /**
     * @param resource $link
     * @param string $key
     * @param mixed $default
     * @return mixed|null
     */
    public static function getLinkMeta($link, $key, $default = null)
    {
        $id = self::linkId($link);
        if ($id === null || !isset(self::$linkMeta[$id][$key])) {
            return $default;
        }

        return self::$linkMeta[$id][$key];
    }

    /**
     * @param array $args
     * @param int $linkIndex
     * @return resource|null
     */
    public static function resolveLink(array $args, $linkIndex)
    {
        if (isset($args[$linkIndex]) && $args[$linkIndex]) {
            return $args[$linkIndex];
        }

        return self::$defaultLink;
    }

    /**
     * @param string $hostString
     * @return array
     */
    public static function parseHostInfo($hostString)
    {
        return MysqliCommon::parseHostInfo($hostString);
    }

    /**
     * @param SpanData $span
     * @param resource|null $link
     */
    public static function applyConnectionInfo(SpanData $span, $link)
    {
        if (!$link) {
            return;
        }

        $hostInfo = self::getLinkMeta($link, self::KEY_HOST_INFO, []);
        foreach ($hostInfo as $tagName => $value) {
            $span->meta[$tagName] = $value;
        }

        $dbName = self::getLinkMeta($link, self::KEY_DATABASE_NAME);
        if ($dbName) {
            $span->meta[Tag::DB_NAME] = $dbName;
        }
    }

    /**
     * @param mixed $link
     * @return int|null
     */
    private static function linkId($link)
    {
        if (!$link || !is_resource($link)) {
            return null;
        }

        return (int) $link;
    }
}
