<?php

/**
 * Request init hook — place at:
 *   /opt/datadog/dd-library/0.99.1/dd-trace-sources/bridge/custom_init.php
 *
 * php.ini:
 *   datadog.trace.request_init_hook = /opt/datadog/dd-library/0.99.1/dd-trace-sources/bridge/custom_init.php
 */

require_once __DIR__ . '/dd_wrap_autoloader.php';

if (PHP_SAPI !== 'cli') {
    $root = \DDTrace\root_span();
    if ($root) {
        $root->meta['span.kind'] = 'server';
        $root->meta['component'] = 'php';
    }
}

// W3C traceparent on outbound curl (PHP 5.6). Hooks curl_setopt/curl_init only —
// does not replace CurlIntegration's curl_exec trace_function.
require_once __DIR__ . '/w3c_curl_inject.php';
JustCallW3cCurlInject::register();

// Not in the pre-built _generated_integrations bundle — load explicitly.
require_once __DIR__ . '/../src/Integrations/Integrations/Mysql/MysqlCommon.php';
require_once __DIR__ . '/../src/Integrations/Integrations/Mysql/MysqlIntegration.php';

if (extension_loaded('mysql')) {
    $mysql = new \DDTrace\Integrations\Mysql\MysqlIntegration();
    $mysql->init();
}
