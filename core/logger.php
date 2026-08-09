<?php
/**
 * Core: logging.
 * One place for all bridge output so "did this lead make it" is always
 * answerable from the logs. Debug-level output (full payloads) is gated
 * behind FCB_DEBUG so production logs stay quiet by default.
 */

if (!defined('ABSPATH')) {
    exit;
}

function fcb_log(string $adapter, string $message): void {
    error_log('[FCB ' . FCB_VERSION . '][' . $adapter . '] ' . $message);
}

/** Full-payload logging, only when FCB_DEBUG is defined true. */
function fcb_log_debug(string $adapter, string $message): void {
    if (defined('FCB_DEBUG') && FCB_DEBUG) {
        fcb_log($adapter, '[debug] ' . $message);
    }
}

/**
 * Records the final outcome of one submission → one adapter.
 * On failure the payload is included so the lead is recoverable by hand —
 * a submission may fail to deliver, but it never disappears.
 */
function fcb_log_outcome(string $adapter, bool $ok, string $form_name, array $payload, string $detail = ''): void {
    if ($ok) {
        fcb_log($adapter, 'Delivered. Form="' . $form_name . '"' . ($detail !== '' ? ' ' . $detail : ''));
        return;
    }
    fcb_log(
        $adapter,
        'FAILED. Form="' . $form_name . '" ' . $detail
        . ' | Recoverable payload: ' . wp_json_encode($payload)
    );
}
