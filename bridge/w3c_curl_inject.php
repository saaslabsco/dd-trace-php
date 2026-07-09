<?php

/**
 * W3C traceparent/tracestate for PHP 5.6 ddtrace (ext/php5/handlers_curl.c).
 *
 * Does NOT hook curl_exec — that would replace CurlIntegration's trace_function
 * posthook (PHP 5 allows one dispatch per function).
 *
 * Injects via curl_init / curl_setopt / curl_setopt_array so headers are stored
 * before ddtrace_curl_exec merges them with x-datadog-* at exec time.
 *
 * Limitation: traceparent uses the active span at inject time. For handles
 * created long before curl_exec, parent-id can be stale. A C patch to
 * ext/php5/handlers_curl.c is the durable fix for both W3C and curl spans.
 */
final class JustCallW3cCurlInject
{
    /** @var array<int|string, array<int, string>> */
    private static $httpHeaders = array();

    /** @var bool */
    private static $injecting = false;

    public static function register()
    {
        if (\PHP_VERSION_ID >= 70000) {
            return;
        }

        if (!\extension_loaded('curl') || !\function_exists('\\DDTrace\\hook_function')) {
            return;
        }

        if (\function_exists('ddtrace_config_distributed_tracing_enabled')
            && !\ddtrace_config_distributed_tracing_enabled()) {
            return;
        }

        // PHP 5: hook_function(name, ?prehook, ?posthook) — only one callback allowed.
        $onSetopt = function ($args) {
            if (self::$injecting || \count($args) < 3) {
                return;
            }

            $ch = $args[0];
            if (\defined('CURLOPT_HTTPHEADER') && $args[1] == \CURLOPT_HTTPHEADER && \is_array($args[2])) {
                self::injectIntoHandle($ch, $args[2]);
                return;
            }

            if (\defined('CURLOPT_URL') && $args[1] == \CURLOPT_URL) {
                self::injectIntoHandle($ch);
            }
        };

        $onSetoptArray = function ($args) {
            if (self::$injecting || \count($args) < 2 || !\is_array($args[1])) {
                return;
            }
            if (!\defined('CURLOPT_HTTPHEADER') || !isset($args[1][\CURLOPT_HTTPHEADER])) {
                return;
            }
            if (!\is_array($args[1][\CURLOPT_HTTPHEADER])) {
                return;
            }

            self::injectIntoHandle($args[0], $args[1][\CURLOPT_HTTPHEADER]);
        };

        \DDTrace\hook_function('curl_setopt', null, $onSetopt);
        \DDTrace\hook_function('curl_setopt_array', null, $onSetoptArray);

        // curl_init('http://…') never hits CURLOPT_URL setopt — inject after handle exists.
        \DDTrace\hook_function('curl_init', null, function ($args, $retval) {
            if ($retval && (\is_resource($retval) || \is_object($retval))) {
                self::injectIntoHandle($retval);
            }
        });
    }

    /**
     * @param resource|object $ch
     * @param array<int, string>|null $existing
     */
    private static function injectIntoHandle($ch, array $existing = null)
    {
        if (self::$injecting) {
            return;
        }

        $ctx = \DDTrace\current_context();
        if ($ctx['trace_id'] === '' || $ctx['trace_id'] === '0' || $ctx['span_id'] === '' || $ctx['span_id'] === '0') {
            return;
        }

        $id = self::handleId($ch);
        if ($existing === null) {
            $existing = isset(self::$httpHeaders[$id]) ? self::$httpHeaders[$id] : array();
        }

        $merged = self::mergeHeaders($existing, self::buildW3cHeaders($ctx));

        self::$injecting = true;
        \curl_setopt($ch, \CURLOPT_HTTPHEADER, $merged);
        self::$injecting = false;

        self::$httpHeaders[$id] = $merged;
    }

    /**
     * @param resource|object $ch
     * @return int|string
     */
    private static function handleId($ch)
    {
        return \is_resource($ch) ? (int) $ch : \spl_object_hash($ch);
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<int, string>
     */
    private static function buildW3cHeaders(array $ctx)
    {
        $traceHex = self::padHex(self::decToHex($ctx['trace_id']), 32);
        $spanHex = self::padHex(self::decToHex($ctx['span_id']), 16);

        return array(
            'traceparent: 00-' . $traceHex . '-' . $spanHex . '-01',
            self::buildTracestate($ctx, $spanHex),
        );
    }

    /**
     * @param array<string, mixed> $ctx
     * @param string $spanHex
     */
    private static function buildTracestate(array $ctx, $spanHex)
    {
        $parts = array('p:' . $spanHex);

        if (!empty($ctx['distributed_tracing_origin'])) {
            $parts[] = 'o:' . self::sanitizeTracestateValue($ctx['distributed_tracing_origin']);
        }

        if (!empty($ctx['distributed_tracing_propagated_tags']) && \is_array($ctx['distributed_tracing_propagated_tags'])) {
            foreach ($ctx['distributed_tracing_propagated_tags'] as $key => $value) {
                if (\strpos($key, '_dd.p.') !== 0) {
                    continue;
                }
                $parts[] = 't' . \substr($key, 6) . ':' . self::sanitizeTracestateValue($value);
            }
        }

        return 'tracestate: dd=' . \implode(';', $parts);
    }

    private static function sanitizeTracestateValue($value)
    {
        $value = \str_replace('=', '~', (string) $value);

        return \preg_replace('/[\x00-\x1f,;~]/', '_', $value);
    }

    /**
     * @param array<int, string> $headers
     * @param array<int, string> $w3cLines
     * @return array<int, string>
     */
    private static function mergeHeaders(array $headers, array $w3cLines)
    {
        $out = array();
        foreach ($headers as $line) {
            if (!\is_string($line)) {
                continue;
            }
            if (\stripos($line, 'traceparent:') === 0 || \stripos($line, 'tracestate:') === 0) {
                continue;
            }
            $out[] = $line;
        }

        return \array_merge($out, $w3cLines);
    }

    private static function decToHex($dec)
    {
        $dec = \ltrim((string) $dec, '0');
        if ($dec === '') {
            return '0';
        }

        if (\function_exists('gmp_init')) {
            return \gmp_strval(\gmp_init($dec, 10), 16);
        }

        if (\function_exists('bcdiv')) {
            $hex = '';
            while (\bccomp($dec, '0') > 0) {
                $rem = \bcmod($dec, '16');
                $hex = \dechex((int) $rem) . $hex;
                $dec = \bcdiv($dec, '16', 0);
            }

            return $hex === '' ? '0' : $hex;
        }

        if (\strlen($dec) < 18 && $dec <= (string) \PHP_INT_MAX) {
            return \dechex((int) $dec);
        }

        return self::decStrToHex($dec);
    }

    private static function decStrToHex($dec)
    {
        $hex = '';
        while ($dec !== '0') {
            $rem = 0;
            $next = '';
            $len = \strlen($dec);
            for ($i = 0; $i < $len; $i++) {
                $acc = $rem * 10 + (int) $dec[$i];
                if ($next !== '' || (int) ($acc / 16) > 0) {
                    $next .= (string) (int) ($acc / 16);
                }
                $rem = $acc % 16;
            }
            $hex = \dechex($rem) . $hex;
            $dec = $next === '' ? '0' : \ltrim($next, '0');
            if ($dec === '') {
                $dec = '0';
            }
        }

        return $hex === '' ? '0' : $hex;
    }

    private static function padHex($hex, $length)
    {
        if (\strlen($hex) > $length) {
            return \substr($hex, -$length);
        }

        return \str_pad($hex, $length, '0', \STR_PAD_LEFT);
    }
}
