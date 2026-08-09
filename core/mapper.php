<?php
/**
 * Core: field mapping.
 * Normalizes raw form fields (from any supported form plugin) into one
 * canonical array that every adapter consumes. Alias lists absorb the
 * common naming variations so form builders don't need exact keys.
 *
 * Form-side setup:
 *   Gravity Forms: set the field's Admin Field Label to a primary key
 *                  (e.g. 'email', 'fullname').
 *   Elementor Pro: set the field's ID (Advanced tab) to a primary key.
 */

if (!defined('ABSPATH')) {
    exit;
}

function fcb_map_fields(array $raw): array {
    $fields = [
        'full_name'        => trim($raw['fullname']       ?? $raw['full_name']        ?? $raw['full-name']        ?? $raw['name']         ?? ''),
        'first_name'       => trim($raw['firstname']      ?? $raw['first_name']       ?? $raw['first-name']       ?? $raw['fname']        ?? ''),
        'last_name'        => trim($raw['lastname']       ?? $raw['last_name']        ?? $raw['last-name']        ?? $raw['lname']        ?? ''),
        'email'            => trim($raw['email']          ?? $raw['email_address']    ?? $raw['emailaddress']     ?? $raw['your-email']   ?? ''),
        'phone'            => trim($raw['phone']          ?? $raw['phone_number']     ?? $raw['phonenumber']      ?? $raw['tel']          ?? ''),
        'date_of_incident' => trim($raw['dateofincident'] ?? $raw['date_of_incident'] ?? $raw['date-of-incident'] ?? $raw['incidentdate'] ?? ''),
        'city'             => trim($raw['city']           ?? ''),
        'state'            => trim($raw['state']          ?? ''),
        'zip'              => trim($raw['zip']            ?? $raw['zipcode']          ?? $raw['zip_code']         ?? $raw['postalcode']   ?? ''),
        'message'          => trim($raw['message']        ?? $raw['synopsis']         ?? $raw['summary']          ?? $raw['comments']     ?? $raw['yourmessage'] ?? ''),
        'source'           => trim($raw['source']         ?? $raw['lead_source']      ?? $raw['leadsource']       ?? ''),
    ];

    /**
     * Site-specific extensions without editing core:
     * add_filter('fcb_mapped_fields', fn($f, $raw) => ..., 10, 2);
     */
    return apply_filters('fcb_mapped_fields', $fields, $raw);
}

/**
 * Splits a single full-name value into [first, last] for platforms that
 * require separate fields. "Jane" => ["Jane", ""], "Jane Q Smith" =>
 * ["Jane", "Q Smith"].
 */
function fcb_split_name(string $full): array {
    $full = trim(preg_replace('/\s+/', ' ', $full));
    if ($full === '') {
        return ['', ''];
    }
    $parts = explode(' ', $full, 2);
    return [$parts[0], $parts[1] ?? ''];
}

/**
 * Best-available display name: the explicit full_name field if present,
 * otherwise first + last combined.
 */
function fcb_full_name(array $fields): string {
    return $fields['full_name'] !== ''
        ? $fields['full_name']
        : trim($fields['first_name'] . ' ' . $fields['last_name']);
}
