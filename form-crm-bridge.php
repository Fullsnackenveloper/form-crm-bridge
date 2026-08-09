<?php
/**
 * Plugin Name: Form → CRM Bridge
 * Description: Forwards Gravity Forms and Elementor Pro submissions to a CRM through pluggable platform adapters (Neos, Lead Docket, HubSpot, generic webhook).
 * Version:     1.0.0
 * Author:      Mike Schermer
 * Requires PHP: 7.4
 * =========================================================================
 * DESIGN
 * =========================================================================
 * One shared core (capture → map → transport → log) plus thin adapters that
 * contain only what genuinely differs per platform: auth, payload shape,
 * and response interpretation. Adding a platform means adding one adapter
 * file — the core is inherited.
 *
 * SAFETY
 * - Safe to activate with nothing configured: logs a notice, does nothing.
 * - Every hook is wrapped in try/catch — the bridge can never break a
 *   form submission.
 * - 4xx responses abort immediately; 5xx and network errors retry with
 *   exponential backoff. Nothing is silently lost: exhausted submissions
 *   are logged with payload and error.
 *
 * CONFIG (wp-config.php)
 * - Adapters activate themselves when their own defines are present.
 *   See each file in adapters/ for its settings.
 * - Optional form filtering (applies to all adapters):
 *     define('FCB_GF_INCLUDE_IDS', '1,3');          // only these GF form IDs
 *     define('FCB_GF_EXCLUDE_IDS', '2');            // or skip these
 *     define('FCB_ELEMENTOR_INCLUDE_FORMS', 'Contact,Case Evaluation');
 *     define('FCB_ELEMENTOR_EXCLUDE_FORMS', 'Newsletter');
 * - Debug payload logging (off by default):
 *     define('FCB_DEBUG', true);
 * =========================================================================
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FCB_VERSION', '1.0.0');

require_once __DIR__ . '/core/logger.php';
require_once __DIR__ . '/core/mapper.php';
require_once __DIR__ . '/core/transport.php';
require_once __DIR__ . '/core/capture.php';

// -------------------------------------------------------------------------
// Adapter registry
// -------------------------------------------------------------------------

/**
 * Registered adapters. Each entry:
 *   'id'            => string, short name used in logs
 *   'is_configured' => callable(): bool   — whether this site has set it up
 *   'send'          => callable(array $fields, array $context): bool
 *
 * $fields  = normalized field array from fcb_map_fields()
 * $context = ['form_name' => string, 'page_url' => string, 'utm' => array]
 */
function fcb_adapters(): array {
    static $adapters = null;
    if ($adapters === null) {
        $adapters = apply_filters('fcb_register_adapters', []);
    }
    return $adapters;
}

// Load the built-in adapters. Each registers itself via the filter above.
require_once __DIR__ . '/adapters/webhook.php';
require_once __DIR__ . '/adapters/neos.php';
require_once __DIR__ . '/adapters/leaddocket.php';
require_once __DIR__ . '/adapters/hubspot.php';
