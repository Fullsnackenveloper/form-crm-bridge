<?php
/**
 * Core: transport.
 * One retry engine shared by every adapter. Policy (consistent everywhere):
 *   - 2xx  => success.
 *   - 401  => invoke the adapter's auth-refresh callback ONCE, then retry;
 *             a second 401 is a hard failure (rotated/revoked credential —
 *             that needs an operator, not a retry loop).
 *   - other 4xx => hard failure, no retry (retrying a malformed payload
 *             just multiplies the failure).
 *   - 5xx / network error / timeout => retry with exponential backoff.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * POST with retry policy.
 *
 * @param string        $adapter      Adapter id for logs.
 * @param string        $url          Endpoint.
 * @param array         $args         wp_remote_post args (headers/body/timeout).
 * @param callable|null $on_auth_fail fn(): ?array — refresh credentials and
 *                                    return replacement $args (or null to
 *                                    abort). Called at most once per send.
 * @param int           $max_attempts Bounded attempt count.
 *
 * @return array{ok: bool, code: int, body: string}
 */
function fcb_post_with_retry(string $adapter, string $url, array $args, ?callable $on_auth_fail = null, int $max_attempts = 5): array {

    $attempt       = 0;
    $auth_retried  = false;
    $last_code     = 0;
    $last_body     = '';

    if (!isset($args['timeout'])) {
        $args['timeout'] = 15;
    }

    while ($attempt < $max_attempts) {

        if ($attempt > 0) {
            $delay = (int) pow(2, $attempt); // 2s, 4s, 8s, 16s
            fcb_log($adapter, 'Retry ' . $attempt . '/' . ($max_attempts - 1) . ' in ' . $delay . 's.');
            sleep($delay);
        }

        $res = wp_remote_post($url, $args);

        // Network-level error — retriable.
        if (is_wp_error($res)) {
            fcb_log($adapter, 'Network error (attempt ' . ($attempt + 1) . '): ' . $res->get_error_message());
            $attempt++;
            continue;
        }

        $last_code = (int) wp_remote_retrieve_response_code($res);
        $last_body = (string) wp_remote_retrieve_body($res);

        // Success.
        if ($last_code >= 200 && $last_code < 300) {
            return ['ok' => true, 'code' => $last_code, 'body' => $last_body];
        }

        // 401 — one credential refresh, then one more try.
        if ($last_code === 401 && $on_auth_fail !== null && !$auth_retried) {
            fcb_log($adapter, '401 on attempt ' . ($attempt + 1) . ' — refreshing credentials.');
            $auth_retried = true;
            $fresh = $on_auth_fail();
            if ($fresh === null) {
                fcb_log($adapter, 'Credential refresh failed — aborting.');
                return ['ok' => false, 'code' => 401, 'body' => $last_body];
            }
            $args = array_merge($args, $fresh);
            $attempt++;
            continue;
        }

        // 4xx (including a second 401) — hard failure, do not retry.
        if ($last_code >= 400 && $last_code < 500) {
            fcb_log($adapter, 'Client error HTTP ' . $last_code . ' — not retrying. Body: ' . $last_body);
            return ['ok' => false, 'code' => $last_code, 'body' => $last_body];
        }

        // 5xx — retriable.
        fcb_log($adapter, 'Server error HTTP ' . $last_code . ' (attempt ' . ($attempt + 1) . '). Body: ' . $last_body);
        $attempt++;
    }

    fcb_log($adapter, 'All ' . $max_attempts . ' attempts failed. Last HTTP ' . $last_code . '.');
    return ['ok' => false, 'code' => $last_code, 'body' => $last_body];
}
