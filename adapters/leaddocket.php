<?php
/**
 * Adapter: Lead Docket — creates an opportunity.
 *
 * CONFIG (wp-config.php):
 *   define('FCB_LD_ENDPOINT',     'https://YOURFIRM.leaddocket.com/opportunities/form/1');
 *   define('FCB_LD_SECURITY_KEY', '');  // optional — appended as ?apikey= when set
 *
 * PLATFORM NOTES
 * - Auth is a static security key rather than a token exchange: simpler,
 *   with no refresh lifecycle, but it means a long-lived secret sits in
 *   site configuration and rotation is a manual operation.
 * - The endpoint accepts form-urlencoded, not JSON.
 * - A 2xx response does NOT by itself mean the lead was accepted. The
 *   body carries a success flag; a 200 with success=false is a rejection
 *   and is treated as a hard failure (retrying will not change it).
 * - Opportunity records carry case type and source fields each firm
 *   configures itself, so mapping is more client-specific here than on
 *   the other platforms. Expect a mapping review per install rather than
 *   copying the last one.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('fcb_register_adapters', function (array $adapters): array {
    $adapters[] = [
        'id'            => 'leaddocket',
        'is_configured' => 'fcb_ld_is_configured',
        'send'          => 'fcb_ld_send',
    ];
    return $adapters;
});

function fcb_ld_is_configured(): bool {
    return defined('FCB_LD_ENDPOINT') && trim((string) FCB_LD_ENDPOINT) !== '';
}

function fcb_ld_build_body(array $fields, array $context): array {

    // Explicit first/last win; otherwise split the single name field.
    $first = $fields['first_name'];
    $last  = $fields['last_name'];
    if ($first === '' && $last === '' && $fields['full_name'] !== '') {
        [$first, $last] = fcb_split_name($fields['full_name']);
    }

    // Lead Docket takes UTMs as one packed string rather than discrete fields.
    $utm_parts = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $p) {
        $v = sanitize_text_field($_GET[$p] ?? '');
        if ($v !== '') {
            $utm_parts[] = $p . '=' . $v;
        }
    }

    return [
        'First'         => sanitize_text_field($first),
        'Last'          => sanitize_text_field($last),
        'Phone'         => sanitize_text_field($fields['phone']),
        'Email'         => sanitize_email($fields['email']),
        'Summary'       => sanitize_textarea_field($fields['message']),
        'UTM'           => sanitize_text_field(implode('&', $utm_parts)),
        'Current_Url'   => esc_url_raw($context['page_url']),
        'Referring_Url' => esc_url_raw($_SERVER['HTTP_REFERER'] ?? ''),
        'ClickId'       => sanitize_text_field($_GET['gclid'] ?? ''),
    ];
}

function fcb_ld_send(array $fields, array $context): bool {

    $url = FCB_LD_ENDPOINT;
    if (defined('FCB_LD_SECURITY_KEY') && trim((string) FCB_LD_SECURITY_KEY) !== '') {
        $url = add_query_arg('apikey', FCB_LD_SECURITY_KEY, $url);
    }

    $body = fcb_ld_build_body($fields, $context);
    fcb_log_debug('leaddocket', 'Payload: ' . wp_json_encode($body));

    $result = fcb_post_with_retry('leaddocket', $url, [
        'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
        'body'    => http_build_query($body),
    ]);

    // Transport reports HTTP-level success. Lead Docket also reports
    // application-level success in the body, so interpret that here.
    $ok     = $result['ok'];
    $detail = 'HTTP ' . $result['code'];

    if ($ok) {
        $json = json_decode($result['body'], true);
        if (empty($json['success'])) {
            $ok     = false;
            $detail .= ' — accepted by HTTP but rejected by Lead Docket: '
                     . ($json['message'] ?? substr((string) $result['body'], 0, 200));
        } else {
            $detail .= ' opportunityId=' . ($json['opportunityId'] ?? 'n/a');
        }
    }

    fcb_log_outcome('leaddocket', $ok, $context['form_name'], $body, $detail);
    return $ok;
}
