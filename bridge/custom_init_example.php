<?php

/**
 * Example request init hook (same as bridge/custom_init.php).
 *
 * Copy to the server as bridge/custom_init.php next to dd_wrap_autoloader.php.
 */

require_once __DIR__ . '/dd_wrap_autoloader.php';

if (PHP_SAPI !== 'cli') {
    $root = \DDTrace\root_span();
    if ($root) {
        $root->meta['span.kind'] = 'server';
        $root->meta['component'] = 'php';
    }
}

// Not in the pre-built _generated_integrations bundle — load explicitly.
require_once __DIR__ . '/../src/Integrations/Integrations/Mysql/MysqlCommon.php';
require_once __DIR__ . '/../src/Integrations/Integrations/Mysql/MysqlIntegration.php';

if (extension_loaded('mysql')) {
    $mysql = new \DDTrace\Integrations\Mysql\MysqlIntegration();
    $mysql->init();
}
