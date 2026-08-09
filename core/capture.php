<?php
/**
 * Core: form capture.
 * Normalizes Gravity Forms and Elementor Pro submissions into one payload
 * shape and dispatches it to every configured adapter. Nothing downstream
 * needs to know which form plugin fired. Supporting another form plugin
 * later touches only this file.
 *
 * Every hook is wrapped in try/catch: a bridge failure logs and returns —
 * it can never break the visitor's form submission.
 */

if (!defined('ABSPATH')) {
    exit;
}

// -------------------------------------------------------------------------
// Dispatch
// -------------------------------------------------------------------------

/**
 * Sends one normalized submission through every adapter that reports
 * itself configured on this site.
 */
function fcb_dispatch(array $raw, string $form_name, string $page_url): void {

    $fields = fcb_map_fields($raw);

    if ($fields['email'] === '' && fcb_full_name($fields) === '') {
        fcb_log('core', 'Form "' . $form_name . '": no email or name found. '
            . 'Check the form field keys (GF Admin Field Label / Elementor field ID).');
    }

    $context = [
        'form_name' => $form_name,
        'page_url'  => $page_url,
        'utm'       => [
            'source'   => sanitize_text_field($_GET['utm_source']   ?? ''),
            'campaign' => sanitize_text_field($_GET['utm_campaign'] ?? ''),
            'medium'   => sanitize_text_field($_GET['utm_medium']   ?? ''),
        ],
    ];

    $configured = 0;
    foreach (fcb_adapters() as $adapter) {
        if (!call_user_func($adapter['is_configured'])) {
            continue;
        }
        $configured++;
        try {
            call_user_func($adapter['send'], $fields, $context);
        } catch (\Throwable $e) {
            fcb_log($adapter['id'], 'Adapter exception: ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    if ($configured === 0) {
        fcb_log('core', 'Submission received but no adapter is configured — nothing sent. '
            . 'See adapters/ for the wp-config.php defines each one needs.');
    }
}

// -------------------------------------------------------------------------
// Form filtering (applies to all adapters)
// -------------------------------------------------------------------------

function fcb_gf_form_allowed(int $form_id): bool {
    if (defined('FCB_GF_INCLUDE_IDS') && trim((string) FCB_GF_INCLUDE_IDS) !== '') {
        $include = array_map('intval', explode(',', FCB_GF_INCLUDE_IDS));
        return in_array($form_id, $include, true);
    }
    if (defined('FCB_GF_EXCLUDE_IDS') && trim((string) FCB_GF_EXCLUDE_IDS) !== '') {
        $exclude = array_map('intval', explode(',', FCB_GF_EXCLUDE_IDS));
        return !in_array($form_id, $exclude, true);
    }
    return true;
}

function fcb_elementor_form_allowed(string $form_name): bool {
    if (defined('FCB_ELEMENTOR_INCLUDE_FORMS') && trim((string) FCB_ELEMENTOR_INCLUDE_FORMS) !== '') {
        $include = array_map('trim', explode(',', FCB_ELEMENTOR_INCLUDE_FORMS));
        return in_array($form_name, $include, true);
    }
    if (defined('FCB_ELEMENTOR_EXCLUDE_FORMS') && trim((string) FCB_ELEMENTOR_EXCLUDE_FORMS) !== '') {
        $exclude = array_map('trim', explode(',', FCB_ELEMENTOR_EXCLUDE_FORMS));
        return !in_array($form_name, $exclude, true);
    }
    return true;
}

// -------------------------------------------------------------------------
// Gravity Forms
// -------------------------------------------------------------------------

add_action('gform_after_submission', 'fcb_gf_submission', 10, 2);

function fcb_gf_submission($entry, $form): void {
    try {
        if (!fcb_gf_form_allowed((int) $form['id'])) {
            return;
        }

        // Build key => value using adminLabel, falling back to sanitized label.
        $raw = [];
        foreach ($form['fields'] as $field) {
            $key = !empty($field->adminLabel)
                ? sanitize_key($field->adminLabel)
                : sanitize_key($field->label);
            $raw[$key] = rgar($entry, (string) $field->id);
        }

        fcb_dispatch(
            $raw,
            $form['title'] ?? 'Gravity Forms',
            esc_url_raw($entry['source_url'] ?? '')
        );

    } catch (\Throwable $e) {
        fcb_log('core', 'GF exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}

// -------------------------------------------------------------------------
// Elementor Pro
// -------------------------------------------------------------------------

add_action('elementor_pro/forms/new_record', 'fcb_elementor_submission', 10, 2);

function fcb_elementor_submission($record, $handler): void {
    try {
        $form_name = (string) $record->get_form_settings('form_name');

        if (!fcb_elementor_form_allowed($form_name)) {
            return;
        }

        $raw_fields = $record->get('fields');
        if (!is_array($raw_fields) || empty($raw_fields)) {
            fcb_log('core', 'Elementor form "' . $form_name . '": no fields found — skipping.');
            return;
        }

        // Flatten to id => value.
        $raw = [];
        foreach ($raw_fields as $id => $field) {
            $raw[$id] = isset($field['value']) ? (string) $field['value'] : '';
        }

        fcb_dispatch(
            $raw,
            $form_name !== '' ? $form_name : 'Elementor Pro',
            esc_url_raw($_SERVER['HTTP_REFERER'] ?? '')
        );

    } catch (\Throwable $e) {
        fcb_log('core', 'Elementor exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    }
}
