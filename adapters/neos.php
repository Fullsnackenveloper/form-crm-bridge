<?php
/**
 * Adapter: Neos — creates an intake.
 *
 * CONFIG (wp-config.php):
 *   define('FCB_NEOS_INTEGRATION_ID',  '');   // required
 *   define('FCB_NEOS_API_KEY',         '');   // required
 *   define('FCB_NEOS_COMPANY_ID',      '');   // required
 *   define('FCB_NEOS_SUBSCRIPTION_KEY','');   // required
 *   define('FCB_NEOS_ENV',       'staging');  // 'staging' | 'production'
 *   define('FCB_NEOS_CASE_TYPE', 'EXAMPLE CASE TYPE');  // firm-specific
 *   define('FCB_NEOS_SOURCE',    'Website');            // optional
 *
 * PLATFORM NOTES
 * - Auth is a bearer token from the login endpoint, cached as a transient
 *   until it nears expiry. Without caching every lead pays for an extra
 *   round trip and generates avoidable auth traffic against a partner API.
 * - Write permissions are scoped per tenant and DO NOT fail loudly at
 *   onboarding: the connection can authenticate cleanly while an
 *   individual field write returns 403. Verify write permission on every
 *   mapped field when onboarding a new tenant, not just that the token
 *   works.
 * - Onboarding requires a real partner API path (staging credentials,
 *   permission grants, then production). Longest lead time of the three
 *   adapters — start it early.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('fcb_register_adapters', function (array $adapters): array {
    $adapters[] = [
        'id'            => 'neos',
        'is_configured' => 'fcb_neos_is_configured',
        'send'          => 'fcb_neos_send',
    ];
    return $adapters;
});

function fcb_neos_is_configured(): bool {
    foreach (['FCB_NEOS_INTEGRATION_ID', 'FCB_NEOS_API_KEY', 'FCB_NEOS_COMPANY_ID', 'FCB_NEOS_SUBSCRIPTION_KEY'] as $const) {
        if (!defined($const) || trim((string) constant($const)) === '') {
            return false;
        }
    }
    return true;
}

function fcb_neos_base_url(): string {
    $env = defined('FCB_NEOS_ENV') ? FCB_NEOS_ENV : 'staging';
    return $env === 'production'
        ? 'https://partnerapi-neos.azure-api.net'
        : 'https://staging-partnerapi-neos.azure-api.net';
}

/**
 * Returns a cached bearer token, fetching a fresh one when needed.
 * Cached for 50 minutes. Returns '' on any failure.
 */
function fcb_neos_token(bool $force_refresh = false): string {

    if (!$force_refresh) {
        $cached = get_transient('fcb_neos_bearer_token');
        if (!empty($cached)) {
            return (string) $cached;
        }
    }

    $res = wp_remote_post(fcb_neos_base_url() . '/v1/login', [
        'headers' => [
            'Content-Type'              => 'application/json',
            'Cache-Control'             => 'no-cache',
            'Ocp-Apim-Subscription-Key' => FCB_NEOS_SUBSCRIPTION_KEY,
        ],
        'body'    => wp_json_encode([
            'companyId'     => FCB_NEOS_COMPANY_ID,
            'IntegrationId' => FCB_NEOS_INTEGRATION_ID,
            'ApiKey'        => FCB_NEOS_API_KEY,
        ]),
        'timeout' => 15,
    ]);

    if (is_wp_error($res)) {
        fcb_log('neos', 'Auth error: ' . $res->get_error_message());
        return '';
    }

    $code = (int) wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    if ($code !== 200 || empty($body['AccessToken'])) {
        fcb_log('neos', 'Auth failed. HTTP ' . $code . ': ' . wp_remote_retrieve_body($res));
        return '';
    }

    $token = (string) $body['AccessToken'];
    set_transient('fcb_neos_bearer_token', $token, 50 * MINUTE_IN_SECONDS);
    return $token;
}

function fcb_neos_build_payload(array $fields, array $context): array {

    $utm = $context['utm'];

    // Note field so intake staff see full context at a glance.
    $note_parts = array_filter([
        $fields['message']          ? 'Message: ' . $fields['message'] : '',
        $fields['date_of_incident'] ? 'Date of Incident: ' . $fields['date_of_incident'] : '',
        $context['form_name']       ? 'Form: ' . $context['form_name'] : '',
        $context['page_url']        ? 'Page: ' . $context['page_url'] : '',
        $utm['source']              ? 'UTM Source: ' . $utm['source'] : '',
        $utm['campaign']            ? 'UTM Campaign: ' . $utm['campaign'] : '',
        $utm['medium']              ? 'UTM Medium: ' . $utm['medium'] : '',
    ]);

    $payload = array_filter([
        'FullName'       => fcb_full_name($fields),
        'FirstName'      => $fields['first_name'],
        'LastName'       => $fields['last_name'],
        'Email'          => $fields['email'],
        'Phone'          => $fields['phone'],
        'DateOfIncident' => $fields['date_of_incident'],
        'City'           => $fields['city'],
        'State'          => $fields['state'],
        'ZipCode'        => $fields['zip'],
        'Synopsis'       => $fields['message'],
        'Note'           => implode("\n", $note_parts),
    ], static fn($v) => $v !== '');

    // Case type is firm-specific; keep it configurable rather than hardcoded.
    if (defined('FCB_NEOS_CASE_TYPE') && trim((string) FCB_NEOS_CASE_TYPE) !== '') {
        $payload['CaseType'] = FCB_NEOS_CASE_TYPE;
    }
    if (defined('FCB_NEOS_SOURCE') && trim((string) FCB_NEOS_SOURCE) !== '') {
        $payload['Source'] = FCB_NEOS_SOURCE;
    }

    $meta = array_filter([
        'formSource'  => $context['form_name'],
        'pageUrl'     => $context['page_url'],
        'utmSource'   => $utm['source'],
        'utmCampaign' => $utm['campaign'],
        'utmMedium'   => $utm['medium'],
    ], static fn($v) => $v !== '');
    if (!empty($meta)) {
        $payload['MetaData'] = $meta;
    }

    return $payload;
}

function fcb_neos_send(array $fields, array $context): bool {

    $token = fcb_neos_token();
    if ($token === '') {
        fcb_log('neos', 'Skipping — no valid token.');
        return false;
    }

    $payload = fcb_neos_build_payload($fields, $context);
    fcb_log_debug('neos', 'Payload: ' . wp_json_encode($payload));

    $args = [
        'headers' => [
            'Authorization'             => 'Bearer ' . $token,
            'Content-Type'              => 'application/json',
            'Ocp-Apim-Subscription-Key' => FCB_NEOS_SUBSCRIPTION_KEY,
        ],
        'body'    => wp_json_encode($payload),
    ];

    // On 401 the core transport calls this once: drop the cached token,
    // fetch a fresh one, and hand back replacement headers.
    $on_auth_fail = static function () use ($args) {
        delete_transient('fcb_neos_bearer_token');
        $fresh = fcb_neos_token(true);
        if ($fresh === '') {
            return null;
        }
        $headers = $args['headers'];
        $headers['Authorization'] = 'Bearer ' . $fresh;
        return ['headers' => $headers];
    };

    $result = fcb_post_with_retry('neos', fcb_neos_base_url() . '/v1/intakes/fromlead', $args, $on_auth_fail);

    $detail = 'HTTP ' . $result['code'];
    if ($result['ok']) {
        $detail .= ' intake=' . trim($result['body'], '"');
    } elseif ($result['code'] === 403) {
        // Distinctive Neos failure worth calling out in logs by name.
        $detail .= ' — 403 usually means the tenant lacks write permission on a mapped field, not a bad token.';
    }

    fcb_log_outcome('neos', $result['ok'], $context['form_name'], $payload, $detail);
    return $result['ok'];
}
