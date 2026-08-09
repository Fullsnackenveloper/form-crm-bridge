<?php
/**
 * Adapter: HubSpot — creates a contact.
 *
 * CONFIG (wp-config.php):
 *   define('FCB_HUBSPOT_TOKEN', '');   // private app token, scoped to the portal
 *   define('FCB_HUBSPOT_VERIFY_WRITE', true);  // optional, see notes
 *
 * PLATFORM NOTES
 * - Silent field drops. HubSpot will accept a submission and discard
 *   properties it does not recognize WITHOUT raising an error. A 200 does
 *   not mean every field landed. Validation has to confirm the contents of
 *   the created record, not just the status code — which is what
 *   FCB_HUBSPOT_VERIFY_WRITE does: it reads back the created contact and
 *   logs any mapped property that did not persist.
 * - Enum / picklist formatting. Picklist properties must match HubSpot's
 *   INTERNAL values exactly, not the display labels. This is the single
 *   most common source of mapping bugs on this platform.
 * - Custom properties must exist in the portal before they can be written.
 *   Sending an undefined property is the usual cause of a silent drop.
 */

if (!defined('ABSPATH')) {
    exit;
}

const FCB_HUBSPOT_API = 'https://api.hubapi.com/crm/v3/objects/contacts';

add_filter('fcb_register_adapters', function (array $adapters): array {
    $adapters[] = [
        'id'            => 'hubspot',
        'is_configured' => 'fcb_hubspot_is_configured',
        'send'          => 'fcb_hubspot_send',
    ];
    return $adapters;
});

function fcb_hubspot_is_configured(): bool {
    return defined('FCB_HUBSPOT_TOKEN') && trim((string) FCB_HUBSPOT_TOKEN) !== '';
}

function fcb_hubspot_build_properties(array $fields, array $context): array {

    $first = $fields['first_name'];
    $last  = $fields['last_name'];
    if ($first === '' && $last === '' && $fields['full_name'] !== '') {
        [$first, $last] = fcb_split_name($fields['full_name']);
    }

    return array_filter([
        'firstname' => $first,
        'lastname'  => $last,
        'email'     => $fields['email'],
        'phone'     => $fields['phone'],
        'city'      => $fields['city'],
        'state'     => $fields['state'],
        'zip'       => $fields['zip'],
        'message'   => $fields['message'],
    ], static fn($v) => $v !== '');
}

/**
 * Reads back the created contact and reports any property that did not
 * persist. This is the specific control for HubSpot's silent field drops:
 * the write returns 200 either way, so the only way to know is to look.
 */
function fcb_hubspot_verify_write(string $contact_id, array $sent): void {

    $props = implode(',', array_keys($sent));
    $res = wp_remote_get(FCB_HUBSPOT_API . '/' . rawurlencode($contact_id) . '?properties=' . rawurlencode($props), [
        'headers' => ['Authorization' => 'Bearer ' . FCB_HUBSPOT_TOKEN],
        'timeout' => 15,
    ]);

    if (is_wp_error($res)) {
        fcb_log('hubspot', 'Write verification skipped — read-back failed: ' . $res->get_error_message());
        return;
    }

    $body   = json_decode(wp_remote_retrieve_body($res), true);
    $stored = $body['properties'] ?? [];

    $missing = [];
    foreach ($sent as $key => $value) {
        if (!isset($stored[$key]) || (string) $stored[$key] === '') {
            $missing[] = $key;
        }
    }

    if ($missing) {
        fcb_log('hubspot', 'Silent field drop — these properties did not persist on contact '
            . $contact_id . ': ' . implode(', ', $missing)
            . '. Confirm each exists in the portal and that picklist values match HubSpot internal values.');
    } else {
        fcb_log_debug('hubspot', 'Write verified — all mapped properties persisted.');
    }
}

function fcb_hubspot_send(array $fields, array $context): bool {

    $properties = fcb_hubspot_build_properties($fields, $context);
    if (empty($properties)) {
        fcb_log('hubspot', 'Nothing to send — no mapped properties had values.');
        return false;
    }

    $payload = ['properties' => $properties];
    fcb_log_debug('hubspot', 'Payload: ' . wp_json_encode($payload));

    $result = fcb_post_with_retry('hubspot', FCB_HUBSPOT_API, [
        'headers' => [
            'Authorization' => 'Bearer ' . FCB_HUBSPOT_TOKEN,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode($payload),
    ]);

    $detail     = 'HTTP ' . $result['code'];
    $contact_id = '';

    if ($result['ok']) {
        $json       = json_decode($result['body'], true);
        $contact_id = (string) ($json['id'] ?? '');
        $detail    .= $contact_id !== '' ? ' contact=' . $contact_id : '';
    } elseif ($result['code'] === 409) {
        // Duplicate email — the contact already exists. Not a bug to retry.
        $detail .= ' — contact already exists for this email (HubSpot rejects duplicates).';
    }

    fcb_log_outcome('hubspot', $result['ok'], $context['form_name'], $payload, $detail);

    // Verification runs after the outcome is recorded so a slow read-back
    // never delays or masks the delivery result.
    $verify = !defined('FCB_HUBSPOT_VERIFY_WRITE') || FCB_HUBSPOT_VERIFY_WRITE;
    if ($result['ok'] && $verify && $contact_id !== '') {
        fcb_hubspot_verify_write($contact_id, $properties);
    }

    return $result['ok'];
}
