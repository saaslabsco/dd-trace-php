<?php

namespace DDTrace\Integrations\Mysql;

use DDTrace\Integrations\Integration;
use DDTrace\SpanData;
use DDTrace\Tag;
use DDTrace\Type;

/**
 * Integration for the legacy mysql extension (removed in PHP 7).
 *
 * Uses trace_function / hook_function only — compatible with PHP 5.6.
 */
class MysqlIntegration extends Integration
{
    const NAME = 'mysql';
    const SYSTEM = 'mysql';
    const DEFAULT_HOST = 'localhost';

    /**
     * @return string
     */
    public function getName()
    {
        return self::NAME;
    }

    /**
     * @return int
     */
    public function init()
    {
        if (!extension_loaded('mysql')) {
            return Integration::NOT_AVAILABLE;
        }

        $integration = $this;

        \DDTrace\trace_function('mysql_connect', function (SpanData $span, $args, $result) use ($integration) {
            $server = empty($args[0]) ? self::DEFAULT_HOST : $args[0];
            $hostInfo = MysqlCommon::parseHostInfo($server);

            $integration->setDefaultAttributes($span, 'mysql_connect', 'mysql_connect');
            $integration->mergeMeta($span, $hostInfo);

            if ($result === false) {
                $integration->trackConnectError($span);
            } else {
                MysqlCommon::putLinkMeta($result, MysqlCommon::KEY_HOST_INFO, $hostInfo);
                MysqlCommon::setDefaultLink($result);
            }
        });

        \DDTrace\trace_function('mysql_pconnect', function (SpanData $span, $args, $result) use ($integration) {
            $server = empty($args[0]) ? self::DEFAULT_HOST : $args[0];
            $hostInfo = MysqlCommon::parseHostInfo($server);

            $integration->setDefaultAttributes($span, 'mysql_pconnect', 'mysql_pconnect');
            $integration->mergeMeta($span, $hostInfo);

            if ($result === false) {
                $integration->trackConnectError($span);
            } else {
                MysqlCommon::putLinkMeta($result, MysqlCommon::KEY_HOST_INFO, $hostInfo);
                MysqlCommon::setDefaultLink($result);
            }
        });

        \DDTrace\hook_function('mysql_select_db', function ($args) {
            $database = $args[0];
            $link = MysqlCommon::resolveLink($args, 1);
            if ($link && $database) {
                MysqlCommon::putLinkMeta($link, MysqlCommon::KEY_DATABASE_NAME, $database);
            }
        });

        \DDTrace\trace_function('mysql_query', function (SpanData $span, $args, $result) use ($integration) {
            $query = $args[0];
            $link = MysqlCommon::resolveLink($args, 1);

            $integration->setDefaultAttributes($span, 'mysql_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            MysqlCommon::applyConnectionInfo($span, $link);
            $integration->trackQueryError($span, $link, $result);
        });

        \DDTrace\trace_function('mysql_unbuffered_query', function (SpanData $span, $args, $result) use ($integration) {
            $query = $args[0];
            $link = MysqlCommon::resolveLink($args, 1);

            $integration->setDefaultAttributes($span, 'mysql_unbuffered_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            MysqlCommon::applyConnectionInfo($span, $link);
            $integration->trackQueryError($span, $link, $result);
        });

        \DDTrace\trace_function('mysql_db_query', function (SpanData $span, $args, $result) use ($integration) {
            $database = $args[0];
            $query = $args[1];
            $link = MysqlCommon::resolveLink($args, 2);

            $integration->setDefaultAttributes($span, 'mysql_db_query', $query);
            $integration->addTraceAnalyticsIfEnabled($span);
            if ($database) {
                $span->meta[Tag::DB_NAME] = $database;
            }
            MysqlCommon::applyConnectionInfo($span, $link);
            $integration->trackQueryError($span, $link, $result);
        });

        return Integration::LOADED;
    }

    /**
     * @param SpanData $span
     * @param string $name
     * @param string $resource
     */
    public function setDefaultAttributes(SpanData $span, $name, $resource)
    {
        $span->name = $name;
        $span->resource = $resource;
        $span->type = Type::SQL;
        Integration::handleInternalSpanServiceName($span, self::NAME);
        $span->meta[Tag::SPAN_KIND] = Tag::SPAN_KIND_VALUE_CLIENT;
        $span->meta[Tag::COMPONENT] = self::NAME;
        $span->meta[Tag::DB_SYSTEM] = self::SYSTEM;
    }

    /**
     * @param SpanData $span
     */
    public function trackConnectError(SpanData $span)
    {
        $error = \mysql_error();
        if ($error) {
            $this->setError($span, new \Exception($error));
        }
    }

    /**
     * @param SpanData $span
     * @param resource|null $link
     * @param mixed $result
     */
    public function trackQueryError(SpanData $span, $link, $result)
    {
        if ($result !== false) {
            return;
        }

        $error = $link ? \mysql_error($link) : \mysql_error();
        if ($error) {
            $this->setError($span, new \Exception($error));
        }
    }
}
