<?php
/**
 * Adapter: generic webhook.
 * POSTs the normalized submission as JSON to any URL. Useful three ways:
 *   1. Demo/testing — point it at webhook.site or a request bin and watch
 *      real submissions arrive, no CRM required.
 *   2. Integration glue — feed Zapier/Make/n8n or any custom endpoint.
 *   3. The reference adapter — the smallest possible example of the
 *      adapter contract when building a new platform adapter.
 *
 * CONFIG (wp-config.php):
 *   define('FCB_WEBHOOK_URL',    'https://webhook.site/your-id');  // required
 *   define('FCB_WEBHOOK_SECRET', 'shared-secret');                 // optional →
 *                                 sent as X-Bridge-Secret header so the
 *                                 receiver can verify the source
 */

if (!defined('ABSPATH')) {
    exit;
}

add_filter('fcb_register_adapters', function (array $adapters): array {

    $adapters[] = [
        'id'            => 'webhook',
        'is_configured' => function (): bool {
            return defined('FCB_WEBHOOK_URL') && trim((string) FCB_WEBHOOK_URL) !== '';
        },
        'send'          => 'fcb_webhook_send',
    ];

    return $adapters;
});

function fcb_webhook_send(array $fields, array $context): bool {

    // Drop empty fields so the receiver sees only real data.
    $payload = array_filter($fields, static fn($v) => $v !== '');

    $payload['_meta'] = array_filter([
        'form'         => $context['form_name'],
        'page_url'     => $context['page_url'],
        'utm_source'   => $context['utm']['source'],
        'utm_campaign' => $context['utm']['campaign'],
        'utm_medium'   => $context['utm']['medium'],
        'submitted_at' => gmdate('c'),
    ], static fn($v) => $v !== '');

    $headers = ['Content-Type' => 'application/json'];
    if (defined('FCB_WEBHOOK_SECRET') && trim((string) FCB_WEBHOOK_SECRET) !== '') {
        $headers['X-Bridge-Secret'] = FCB_WEBHOOK_SECRET;
    }

    fcb_log_debug('webhook', 'Payload: ' . wp_json_encode($payload));

    $result = fcb_post_with_retry('webhook', FCB_WEBHOOK_URL, [
        'headers' => $headers,
        'body'    => wp_json_encode($payload),
    ]);

    fcb_log_outcome(
        'webhook',
        $result['ok'],
        $context['form_name'],
        $payload,
        'HTTP ' . $result['code']
    );

    return $result['ok'];
}
