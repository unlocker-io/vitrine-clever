<?php

/**
 * The "démarrer" form: a REST endpoint that persists a lead as a private CPT
 * and forwards it to Brevo, then to the CRM (C1b contract). Persistence
 * always happens first, then Brevo, then the CRM: a Brevo or CRM failure
 * never surfaces to the visitor -- the lead is safe once it's in WordPress;
 * `_brevo_status`/`_brevo_error` and `_crm_status`/`_crm_http_status` on the
 * post are the only trace of those failures.
 *
 * `validate_lead()`, `brevo_payload()` and `crm_payload()` are pure (no
 * WordPress call other than `is_email()`) so they can be exercised outside
 * WordPress -- see tests/unlocker-landings-lead-test.php.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

const LEAD_POST_TYPE = 'unlocker_lead';
const LEAD_RATE_LIMIT_MAX = 5;
const LEAD_RATE_LIMIT_WINDOW_SECONDS = 600;
const LEAD_DEFAULT_BREVO_LIST_ID = 117;
const CRM_DEFAULT_PRIVACY_NOTICE_VERSION = 'landing-montagne-2026-09';
const CRM_DEFAULT_LANDING_KEY_SPLIT = 'mountain_split';
const CRM_DEFAULT_LANDING_KEY_DELEGATION = 'mountain_delegation';
const CRM_API_PATH = '/api/v1/acquisition/requests';
const CRM_MAX_BODY_BYTES = 32768;

// Same shape as the touches producer's own handlePattern
// (unlkr-acquisition-touches.js) -- a handle failing this is never the
// producer's, so it is dropped rather than forwarded.
const CRM_VISITOR_HANDLE_PATTERN = '/^av1_[A-Za-z0-9_-]{43}$/';

/** @var string[] */
const LEAD_SIZE_OPTIONS = ['1–9', '10–24', '25–49', '50–99', '100+'];

/** @var string[] */
const LEAD_OFFER_OPTIONS = ['split', 'delegation', 'carte-g-t', 'carte-t'];

/** @var string[] */
const LEAD_PARCOURS_OPTIONS = ['start', 'demo'];

/**
 * Country allow-list for the "démarrer" form's select, and the single
 * source of truth mapping a country code to the label sent to Brevo's
 * `PAYS` attribute and used as the CRM's `business.country` still stays
 * on the ISO code -- only Brevo needs the human label.
 *
 * @var array<string, string>
 */
const LEAD_COUNTRY_LABELS = [
    'FR' => 'France',
    'CH' => 'Suisse',
    'BE' => 'Belgique',
    'IT' => 'Italie',
    'ES' => 'Espagne',
    'AD' => 'Andorre',
    'AT' => 'Autriche',
    'ZZ' => 'Autre pays',
];

/** @var string[] */
const LEAD_CONSENT_ADS_OPTIONS = ['granted', 'denied', 'unknown'];

// -- Validation & Brevo mapping (pure) -----------------------------------

/**
 * Validates and sanitizes a raw lead submission.
 *
 * Every string is trimmed first. A field over its max length is REJECTED
 * (an error, never a silent truncation) except the UTM/click-id
 * attribution fields, which are truncated per spec. Empty optional fields
 * are simply absent from the returned lead.
 *
 * @param array<string, mixed> $input
 * @return array{0: array<string, string>, 1: array<string, string>} [errors, lead]
 */
function validate_lead(array $input): array
{
    $errors = [];
    $lead = [];

    $email = trim((string) ($input['email'] ?? ''));

    if ($email === '') {
        $errors['email'] = 'required';
    } elseif (mb_strlen($email) > 254) {
        $errors['email'] = 'too_long';
    } elseif (!is_email($email)) {
        $errors['email'] = 'invalid';
    } else {
        $lead['email'] = $email;
    }

    foreach (['company' => 160, 'area' => 160] as $field => $max) {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value === '') {
            $errors[$field] = 'required';
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = 'too_long';
        } else {
            $lead[$field] = $value;
        }
    }

    $country = trim((string) ($input['country'] ?? ''));

    if ($country === '') {
        $errors['country'] = 'required';
    } elseif (!array_key_exists($country, LEAD_COUNTRY_LABELS)) {
        $errors['country'] = 'invalid_choice';
    } else {
        $lead['country'] = $country;
    }

    $size = trim((string) ($input['size'] ?? ''));

    if ($size === '') {
        $errors['size'] = 'required';
    } elseif (!in_array($size, LEAD_SIZE_OPTIONS, true)) {
        $errors['size'] = 'invalid_choice';
    } else {
        $lead['size'] = $size;
    }

    $offer = trim((string) ($input['offer'] ?? ''));

    if ($offer === '') {
        $errors['offer'] = 'required';
    } elseif (!in_array($offer, LEAD_OFFER_OPTIONS, true)) {
        $errors['offer'] = 'invalid_choice';
    } else {
        $lead['offer'] = $offer;
    }

    $parcours = trim((string) ($input['parcours'] ?? ''));

    if ($parcours === '') {
        $errors['parcours'] = 'required';
    } elseif (!in_array($parcours, LEAD_PARCOURS_OPTIONS, true)) {
        $errors['parcours'] = 'invalid_choice';
    } else {
        $lead['parcours'] = $parcours;
    }

    $phone = trim((string) ($input['phone'] ?? ''));

    if ($phone !== '') {
        if (mb_strlen($phone) > 40) {
            $errors['phone'] = 'too_long';
        } else {
            $lead['phone'] = $phone;
        }
    }

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'] as $field) {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value !== '') {
            $lead[$field] = mb_substr($value, 0, 200);
        }
    }

    foreach (['fbclid', 'gclid'] as $field) {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value !== '') {
            $lead[$field] = mb_substr($value, 0, 500);
        }
    }

    $pageUrl = trim((string) ($input['page_url'] ?? ''));

    if ($pageUrl !== '') {
        if (mb_strlen($pageUrl) > 500) {
            $errors['page_url'] = 'too_long';
        } elseif (filter_var($pageUrl, FILTER_VALIDATE_URL) === false) {
            $errors['page_url'] = 'invalid';
        } else {
            $lead['page_url'] = $pageUrl;
        }
    }

    // Never rejected: an absent or unrecognized value silently falls back to
    // 'unknown', matching the CRM's own default for an unmeasured consent.
    $consentAds = trim((string) ($input['consent_ads'] ?? ''));
    $lead['consent_ads'] = in_array($consentAds, LEAD_CONSENT_ADS_OPTIONS, true) ? $consentAds : 'unknown';

    // Optional and never rejected: an unparsable, too-future or too-old
    // value is simply dropped, so a clock-skewed or stale browser value
    // never blocks a lead's submission.
    $landedAt = trim((string) ($input['landed_at'] ?? ''));

    if ($landedAt !== '') {
        try {
            $parsedLandedAt = new \DateTimeImmutable($landedAt);
        } catch (\Exception $exception) {
            $parsedLandedAt = null;
        }

        if ($parsedLandedAt !== null) {
            $nowTimestamp = time();
            $landedAtTimestamp = $parsedLandedAt->getTimestamp();
            $isWithinBounds = $landedAtTimestamp <= $nowTimestamp + 300
                && $landedAtTimestamp >= $nowTimestamp - (30 * 86400);

            if ($isWithinBounds) {
                $lead['landed_at'] = $parsedLandedAt->format(\DateTimeInterface::ATOM);
            }
        }
    }

    // Optional and never rejected: an absent or malformed handle (wrong
    // producer, wrong version, tampered) is silently ignored rather than
    // failing the whole submission -- it's the C2c touches producer's own
    // identifier, not something this form can itself validate further.
    $visitorHandle = trim((string) ($input['visitor_handle'] ?? ''));

    if ($visitorHandle !== '' && preg_match(CRM_VISITOR_HANDLE_PATTERN, $visitorHandle) === 1) {
        $lead['visitor_handle'] = $visitorHandle;
    }

    return [$errors, $lead];
}

/**
 * Maps a validated lead to a Brevo `POST /v3/contacts` body. Attributes
 * that would be empty are omitted rather than sent blank.
 *
 * @param array<string, string> $lead
 */
function brevo_payload(array $lead, int $listId): array
{
    $map = [
        'company' => 'NOM_SOCIETE',
        'phone' => 'TELEPHONE',
        'size' => 'NUMBER_OF_PROPERTY',
        'area' => 'SECTEUR',
        'offer' => 'OFFRE',
        'parcours' => 'PARCOURS',
        'page_url' => 'LEAD_REFERER',
        'utm_source' => 'UTM_SOURCE',
        'utm_medium' => 'UTM_MEDIUM',
        'utm_campaign' => 'UTM_CAMPAIGN',
        'utm_content' => 'UTM_CONTENT',
        'utm_term' => 'UTM_TERM',
    ];

    $attributes = ['LEAD_TYPE' => 'landing_montagne'];

    // PAYS receives the human label, never the ISO code -- LEAD_COUNTRY_LABELS
    // is the single place mapping one to the other.
    $countryLabel = LEAD_COUNTRY_LABELS[$lead['country'] ?? ''] ?? '';

    if ($countryLabel !== '') {
        $attributes['PAYS'] = $countryLabel;
    }

    foreach ($map as $field => $attribute) {
        $value = $lead[$field] ?? '';

        if ($value !== '') {
            $attributes[$attribute] = $value;
        }
    }

    return [
        'email' => $lead['email'] ?? '',
        'updateEnabled' => true,
        'listIds' => [$listId],
        'attributes' => $attributes,
    ];
}

/**
 * Maps a validated lead to a CRM `POST /api/v1/acquisition/requests` body
 * (contract C1b). Only the keys the contract admits are ever emitted: no
 * extra field on `contact`/`business`/`privacy`. `attribution.touches.*` may
 * carry `utm_content`/`utm_term` in addition to `utm_source`/`utm_medium`/
 * `utm_campaign`/`campaign_external_id`/`click_ids`.
 *
 * @param array<string, string> $lead
 */
function crm_payload(array $lead, string $submissionId, string $landingKey, string $noticeVersion, string $now): array
{
    $consentAds = $lead['consent_ads'] ?? 'unknown';

    if (!in_array($consentAds, LEAD_CONSENT_ADS_OPTIONS, true)) {
        $consentAds = 'unknown';
    }

    $payload = [
        'schema_version' => 1,
        'submission_id' => $submissionId,
        'visitor_handle' => $lead['visitor_handle'] ?? null,
        'contact' => [
            'email' => $lead['email'] ?? '',
            'name' => null,
            'phone' => crm_normalize_phone((string) ($lead['phone'] ?? ''), (string) ($lead['country'] ?? '')),
        ],
        'business' => [
            'name' => $lead['company'] ?? '',
            'country' => $lead['country'] ?? '',
            'area' => $lead['area'] ?? '',
            'property_count_band' => crm_property_count_band((string) ($lead['size'] ?? '')),
        ],
        'offer' => crm_offer((string) ($lead['offer'] ?? '')),
        'landing_key' => $landingKey,
        'privacy' => [
            'notice_version' => $noticeVersion,
            'ads_measurement' => $consentAds,
            'ads_sharing' => $consentAds,
            'marketing_opt_in' => false,
        ],
    ];

    $attribution = crm_attribution($lead, $consentAds, $now);

    if ($attribution !== null) {
        $payload['attribution'] = $attribution;
    }

    return $payload;
}

/**
 * Normalizes a raw phone into the E.164-ish shape the CRM accepts, or null
 * when the result still isn't plausible. Never throws, never guesses beyond
 * the two documented rewrites (00-prefix, French trunk 0).
 */
function crm_normalize_phone(string $phone, string $country): ?string
{
    $phone = trim($phone);

    if ($phone === '') {
        return null;
    }

    $normalized = preg_replace('/[\s.\-()]+/', '', $phone);

    if (!is_string($normalized) || $normalized === '') {
        return null;
    }

    if (strpos($normalized, '00') === 0) {
        $normalized = '+' . substr($normalized, 2);
    }

    if ($country === 'FR' && preg_match('/^0([0-9]{9})$/', $normalized, $matches) === 1) {
        $normalized = '+33' . $matches[1];
    }

    if (strlen($normalized) <= 32 && preg_match('/^\+[1-9][0-9]{6,14}$/', $normalized) === 1) {
        return $normalized;
    }

    return null;
}

/**
 * @var array<string, string>
 */
const CRM_PROPERTY_COUNT_BANDS = [
    '1–9' => '1_9',
    '10–24' => '10_49',
    '25–49' => '10_49',
    '50–99' => '50_99',
    '100+' => '100_plus',
];

function crm_property_count_band(string $size): string
{
    return CRM_PROPERTY_COUNT_BANDS[$size] ?? 'unknown';
}

function crm_offer(string $offer): string
{
    if ($offer === 'delegation' || $offer === 'carte-g-t') {
        return 'delegation_g';
    }

    if ($offer === 'carte-t') {
        return crm_carte_t_offer_value() ?? '';
    }

    return 'split';
}

/**
 * The CRM doesn't accept a `carte-t` offer yet; this reads the value it will
 * accept the day it does, from an explicit env var, so nothing here needs to
 * change again at that point. Returns null (not '') when unset or blank --
 * callers use that null to decide whether to skip the CRM call entirely,
 * never to send a blank `offer` string.
 */
function crm_carte_t_offer_value(): ?string
{
    $value = trim((string) getenv('UNLOCKER_LANDING_CRM_OFFER_CARTE_T'));

    return $value !== '' ? $value : null;
}

/**
 * Builds the optional `attribution` object: present only when ads
 * measurement was granted AND there is at least one UTM or click id to
 * report, never otherwise.
 *
 * @param array<string, string> $lead
 * @return array{touches: array<int, array<string, mixed>>}|null
 */
function crm_attribution(array $lead, string $consentAds, string $now): ?array
{
    if ($consentAds !== 'granted') {
        return null;
    }

    $utmSource = crm_truncate((string) ($lead['utm_source'] ?? ''), 256);
    $utmMedium = crm_truncate((string) ($lead['utm_medium'] ?? ''), 256);
    $utmCampaign = crm_truncate((string) ($lead['utm_campaign'] ?? ''), 256);
    $utmContent = crm_truncate((string) ($lead['utm_content'] ?? ''), 256);
    $utmTerm = crm_truncate((string) ($lead['utm_term'] ?? ''), 256);
    // utm_id is the ad platform's own campaign id -- reported as
    // campaign_external_id, never sent to Brevo.
    $campaignExternalId = crm_truncate((string) ($lead['utm_id'] ?? ''), 120);
    $clickIds = crm_click_ids($lead);

    if ($utmSource === '' && $utmMedium === '' && $utmCampaign === '' && $utmContent === '' && $utmTerm === '' && $campaignExternalId === '' && $clickIds === []) {
        return null;
    }

    $touch = ['occurred_at' => $lead['landed_at'] ?? $now];

    if ($utmSource !== '') {
        $touch['utm_source'] = $utmSource;
    }

    if ($utmMedium !== '') {
        $touch['utm_medium'] = $utmMedium;
    }

    if ($utmCampaign !== '') {
        $touch['utm_campaign'] = $utmCampaign;
    }

    if ($utmContent !== '') {
        $touch['utm_content'] = $utmContent;
    }

    if ($utmTerm !== '') {
        $touch['utm_term'] = $utmTerm;
    }

    if ($campaignExternalId !== '') {
        $touch['campaign_external_id'] = $campaignExternalId;
    }

    if ($clickIds !== []) {
        $touch['click_ids'] = $clickIds;
    }

    return ['touches' => [$touch]];
}

/**
 * @param array<string, string> $lead
 * @return array<string, string>
 */
function crm_click_ids(array $lead): array
{
    $clickIds = [];

    foreach (['fbclid', 'gclid'] as $key) {
        $value = trim((string) ($lead[$key] ?? ''));

        if ($value !== '' && mb_strlen($value) <= 256 && preg_match('/^[A-Za-z0-9._~-]+$/', $value) === 1) {
            $clickIds[$key] = $value;
        }
    }

    return $clickIds;
}

function crm_truncate(string $value, int $max): string
{
    $value = trim($value);

    return $value === '' ? '' : mb_substr($value, 0, $max);
}

// -- WordPress wiring ------------------------------------------------------

add_action('init', __NAMESPACE__ . '\\register_lead_post_type');

function register_lead_post_type(): void
{
    register_post_type(LEAD_POST_TYPE, [
        'labels' => [
            'name' => 'Leads landing',
            'singular_name' => 'Lead landing',
            'menu_name' => 'Leads landing',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_rest' => false,
        'supports' => ['title'],
        'capability_type' => 'post',
        'map_meta_cap' => false,
        'capabilities' => [
            'edit_post' => 'manage_options',
            'read_post' => 'manage_options',
            'delete_post' => 'manage_options',
            'edit_posts' => 'manage_options',
            'edit_others_posts' => 'manage_options',
            'publish_posts' => 'manage_options',
            'read_private_posts' => 'manage_options',
            'delete_posts' => 'manage_options',
        ],
    ]);
}

add_filter('manage_' . LEAD_POST_TYPE . '_posts_columns', function (array $columns): array {
    $columns['ul_offer'] = 'Offre';
    $columns['ul_parcours'] = 'Parcours';
    $columns['ul_brevo_status'] = 'Statut Brevo';
    $columns['ul_crm_status'] = 'CRM';

    return $columns;
});

add_action('manage_' . LEAD_POST_TYPE . '_posts_custom_column', function (string $column, int $postId): void {
    $payload = get_post_meta($postId, '_lead_payload', true);
    $payload = is_array($payload) ? $payload : [];

    switch ($column) {
        case 'ul_offer':
            echo esc_html((string) ($payload['offer'] ?? ''));
            break;
        case 'ul_parcours':
            echo esc_html((string) ($payload['parcours'] ?? ''));
            break;
        case 'ul_brevo_status':
            echo esc_html((string) get_post_meta($postId, '_brevo_status', true));
            break;
        case 'ul_crm_status':
            echo esc_html((string) get_post_meta($postId, '_crm_status', true));
            break;
    }
}, 10, 2);

add_action('rest_api_init', __NAMESPACE__ . '\\register_lead_route');

function register_lead_route(): void
{
    register_rest_route('unlocker-landings/v1', '/lead', [
        'methods' => 'POST',
        'callback' => __NAMESPACE__ . '\\handle_lead_request',
        'permission_callback' => '__return_true',
    ]);
}

// The endpoint is public and pages may be cached: no nonce check.
function handle_lead_request(\WP_REST_Request $request): \WP_REST_Response
{
    $input = (array) ($request->get_json_params() ?? []);

    $website = trim((string) ($input['website'] ?? ''));

    if ($website !== '') {
        // Honeypot tripped: pretend success, persist and send nothing.
        return new \WP_REST_Response(['ok' => true], 200);
    }

    $ip = lead_client_ip($_SERVER);
    $rateLimitKey = lead_rate_limit_key($ip);

    if (is_lead_rate_limited($rateLimitKey)) {
        return new \WP_REST_Response(['ok' => false], 429);
    }

    [$errors, $lead] = validate_lead($input);

    if ($errors !== []) {
        return new \WP_REST_Response(['ok' => false, 'errors' => $errors], 422);
    }

    bump_lead_rate_limit($rateLimitKey);

    $postId = persist_lead($lead);

    if ($postId > 0) {
        send_lead_to_brevo($postId, $lead);
        send_lead_to_crm($postId, $lead);
    }

    return new \WP_REST_Response(['ok' => true], 200);
}

/**
 * Client IP as seen by the Clever Cloud edge proxy.
 *
 * REMOTE_ADDR is the proxy itself; the proxy appends the real client address
 * as the LAST X-Forwarded-For entry (entries on the left are client-supplied).
 *
 * @param array<string, mixed> $server
 */
function lead_client_ip(array $server): string
{
    $forwarded = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');

    if ($forwarded !== '') {
        $parts = array_map('trim', explode(',', $forwarded));
        $last = (string) end($parts);

        if (filter_var($last, FILTER_VALIDATE_IP) !== false) {
            return $last;
        }
    }

    return (string) ($server['REMOTE_ADDR'] ?? '');
}

function lead_rate_limit_key(string $ip): string
{
    return 'ul_lead_rl_' . hash('sha256', $ip . wp_salt());
}

function is_lead_rate_limited(string $key): bool
{
    return (int) get_transient($key) >= LEAD_RATE_LIMIT_MAX;
}

function bump_lead_rate_limit(string $key): void
{
    $count = get_transient($key);
    $next = $count === false ? 1 : ((int) $count) + 1;

    set_transient($key, $next, LEAD_RATE_LIMIT_WINDOW_SECONDS);
}

/**
 * @param array<string, string> $lead
 */
function persist_lead(array $lead): int
{
    $postId = wp_insert_post([
        'post_type' => LEAD_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => sprintf('%s — %s', $lead['company'] ?? '', $lead['email'] ?? ''),
    ], true);

    if (is_wp_error($postId)) {
        error_log('[unlocker-landings] lead: failed to persist post - ' . $postId->get_error_code());

        return 0;
    }

    update_post_meta($postId, '_lead_payload', $lead);
    update_post_meta($postId, '_brevo_status', 'pending');

    return $postId;
}

/**
 * @param array<string, string> $lead
 */
function send_lead_to_brevo(int $postId, array $lead): void
{
    $apiKey = brevo_api_key();

    if ($apiKey === '') {
        update_post_meta($postId, '_brevo_status', 'failed');
        update_post_meta($postId, '_brevo_error', 'missing_api_key');
        error_log('[unlocker-landings] brevo: missing API key');

        return;
    }

    $payload = brevo_payload($lead, brevo_list_id());

    $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
        'timeout' => 8,
        'headers' => [
            'api-key' => $apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
        update_post_meta($postId, '_brevo_status', 'failed');
        update_post_meta($postId, '_brevo_error', 'request_failed');
        error_log('[unlocker-landings] brevo: request failed - ' . $response->get_error_code());

        return;
    }

    $code = (int) wp_remote_retrieve_response_code($response);

    if ($code >= 200 && $code < 300) {
        update_post_meta($postId, '_brevo_status', 'sent');
        delete_post_meta($postId, '_brevo_error');

        return;
    }

    update_post_meta($postId, '_brevo_status', 'failed');
    update_post_meta($postId, '_brevo_error', 'http_' . $code);
    error_log(sprintf('[unlocker-landings] brevo: unexpected status %d', $code));
}

function brevo_api_key(): string
{
    $key = getenv('UNLOCKER_LANDING_BREVO_API_KEY');

    if ($key === false || trim((string) $key) === '') {
        $key = $_ENV['UNLOCKER_LANDING_BREVO_API_KEY'] ?? ($_SERVER['UNLOCKER_LANDING_BREVO_API_KEY'] ?? '');
    }

    $key = trim((string) $key);

    if ($key !== '') {
        return $key;
    }

    // Repli sur la clé déjà configurée en prod pour le plugin Brevo (mailin).
    return trim((string) get_option('sib_api_key_v3', ''));
}

function brevo_list_id(): int
{
    $env = getenv('UNLOCKER_LANDING_BREVO_LIST_ID');

    if ($env !== false && ctype_digit((string) $env)) {
        return (int) $env;
    }

    return LEAD_DEFAULT_BREVO_LIST_ID;
}

// -- CRM delivery (C1b contract) -------------------------------------------
//
// Reuses the env names already used by the (inactive) unlkr-acquisition-relay
// mu-plugin for the CRM endpoint itself (CRM_ACQUISITION_API_URL,
// CRM_ACQUISITION_ALLOWED_HOSTS, CRM_ACQUISITION_SERVICE_TOKEN) so a single
// set of secrets configures both; UNLOCKER_LANDING_CRM_ENABLED is this
// plugin's own switch and is independent of the relay's.

/**
 * @return array{enabled: bool, valid: bool, url: string, token: string, allow_insecure_for_tests: bool}
 */
function crm_configuration(): array
{
    $enabled = filter_var(getenv('UNLOCKER_LANDING_CRM_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    $url = trim((string) getenv('CRM_ACQUISITION_API_URL'));
    $allowedHosts = array_filter(array_map('trim', explode(',', (string) getenv('CRM_ACQUISITION_ALLOWED_HOSTS'))));
    $parts = parse_url($url);

    // Local-only escape hatch: contract validation still runs, only the
    // https/443 requirement is relaxed, and only for a plain HTTP capture
    // container reachable from inside the app container. Never true outside
    // an explicit local override file, and never committed as true anywhere.
    $allowInsecureForTests = defined('UNLOCKER_LANDING_CRM_ALLOW_INSECURE_FOR_TESTS')
        && UNLOCKER_LANDING_CRM_ALLOW_INSECURE_FOR_TESTS;

    $schemeIsAllowed = $allowInsecureForTests
        || (isset($parts['scheme']) && strtolower((string) $parts['scheme']) === 'https');
    $portIsAllowed = $allowInsecureForTests
        || !isset($parts['port'])
        || (int) $parts['port'] === 443;

    $urlIsAllowed = is_array($parts)
        && isset($parts['host'], $parts['path'])
        && $schemeIsAllowed
        && $parts['path'] === CRM_API_PATH
        && $portIsAllowed
        && in_array(strtolower((string) $parts['host']), array_map('strtolower', $allowedHosts), true)
        && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);

    $token = trim((string) getenv('CRM_ACQUISITION_SERVICE_TOKEN'));

    return [
        'enabled' => $enabled,
        'valid' => $urlIsAllowed && $token !== '',
        'url' => $url,
        'token' => $token,
        'allow_insecure_for_tests' => $allowInsecureForTests,
    ];
}

function crm_landing_key(string $offer): string
{
    if ($offer === 'delegation') {
        $value = trim((string) getenv('CRM_ACQUISITION_LANDING_KEY_DELEGATION'));

        return $value !== '' ? $value : CRM_DEFAULT_LANDING_KEY_DELEGATION;
    }

    $value = trim((string) getenv('CRM_ACQUISITION_LANDING_KEY_SPLIT'));

    return $value !== '' ? $value : CRM_DEFAULT_LANDING_KEY_SPLIT;
}

function crm_notice_version(): string
{
    $value = trim((string) getenv('UNLOCKER_LANDING_PRIVACY_NOTICE_VERSION'));

    return $value !== '' ? $value : CRM_DEFAULT_PRIVACY_NOTICE_VERSION;
}

function crm_generate_submission_id(): string
{
    return wp_generate_uuid4();
}

/**
 * Entry point called right after the Brevo attempt. The submission_id is
 * minted and persisted as `_crm_submission_id` BEFORE the request is sent,
 * so a crash mid-delivery still leaves a stable idempotency key behind for
 * `wp unlocker-landings crm-retry` to reuse.
 *
 * @param array<string, string> $lead
 */
function send_lead_to_crm(int $postId, array $lead): void
{
    $config = crm_configuration();

    if (!$config['enabled'] || !$config['valid']) {
        update_post_meta($postId, '_crm_status', 'disabled');

        return;
    }

    $offer = (string) ($lead['offer'] ?? '');

    if ($offer === 'carte-t' && crm_carte_t_offer_value() === null) {
        update_post_meta($postId, '_crm_status', 'skipped_offer_not_mapped');
        error_log('[unlocker-landings] crm: carte-t lead skipped - UNLOCKER_LANDING_CRM_OFFER_CARTE_T not configured');

        return;
    }

    $submissionId = crm_generate_submission_id();
    update_post_meta($postId, '_crm_submission_id', $submissionId);

    $payload = crm_payload(
        $lead,
        $submissionId,
        crm_landing_key($lead['offer'] ?? ''),
        crm_notice_version(),
        gmdate(\DateTimeInterface::ATOM)
    );

    deliver_crm_payload($postId, $config, $payload, $submissionId);
}

/**
 * Sends one CRM request (with the relay's single-retry policy) and records
 * the outcome on the lead post. Shared by the initial send and
 * `wp unlocker-landings crm-retry`.
 */
function deliver_crm_payload(int $postId, array $config, array $payload, string $submissionId): void
{
    $body = wp_json_encode($payload);

    if (!is_string($body) || strlen($body) > CRM_MAX_BODY_BYTES) {
        update_post_meta($postId, '_crm_status', 'failed');
        update_post_meta($postId, '_crm_http_status', 0);
        error_log('[unlocker-landings] crm: payload exceeds ' . CRM_MAX_BODY_BYTES . ' bytes');

        return;
    }

    // wp_safe_remote_post() deliberately refuses localhost/private targets;
    // the local-only capture container used to verify this delivery is such
    // a target, hence the (equally local-only) escape hatch to wp_remote_post().
    $sendRequest = $config['allow_insecure_for_tests'] ? 'wp_remote_post' : 'wp_safe_remote_post';

    $attempt = 0;
    $status = 0;
    $response = null;

    do {
        $attempt++;
        $response = $sendRequest($config['url'], [
            'timeout' => 3,
            'redirection' => 0,
            'sslverify' => !$config['allow_insecure_for_tests'],
            'reject_unsafe_urls' => !$config['allow_insecure_for_tests'],
            'headers' => [
                'Authorization' => 'Bearer ' . $config['token'],
                'Idempotency-Key' => $submissionId,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $body,
        ]);
        $status = crm_response_status($response);
        $retry = $attempt === 1 && ($status === 0 || $status === 429 || $status >= 500);
    } while ($retry);

    if ($status === 200 || $status === 201) {
        update_post_meta($postId, '_crm_status', 'sent');
        update_post_meta($postId, '_crm_http_status', $status);
        delete_post_meta($postId, '_crm_error');

        $leadId = crm_extract_lead_id($response);

        if ($leadId !== null) {
            update_post_meta($postId, '_crm_lead_id', $leadId);
        }

        return;
    }

    update_post_meta($postId, '_crm_status', 'failed');
    update_post_meta($postId, '_crm_http_status', $status);
    update_post_meta($postId, '_crm_error', 'http_' . $status);
    error_log(sprintf('[unlocker-landings] crm: unexpected status %d', $status));
}

/** @param mixed $response */
function crm_response_status($response): int
{
    if (is_wp_error($response)) {
        return 0;
    }

    return (int) wp_remote_retrieve_response_code($response);
}

/** @param mixed $response */
function crm_extract_lead_id($response): ?string
{
    if (is_wp_error($response)) {
        return null;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode((string) $body, true);

    return is_array($data) && isset($data['lead_id']) && is_scalar($data['lead_id'])
        ? (string) $data['lead_id']
        : null;
}

// -- WP-CLI: rejeu des leads CRM en échec ----------------------------------

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('unlocker-landings crm-retry', __NAMESPACE__ . '\\cli_crm_retry');
}

/**
 * `wp unlocker-landings crm-retry [--dry-run]` -- resends every lead whose
 * `_crm_status` is `failed`, reusing its existing `_crm_submission_id` (the
 * same Idempotency-Key), and prints a count per resulting status. No cron:
 * this is triggered by hand.
 *
 * @param array<int, string> $args
 * @param array<string, mixed> $assocArgs
 */
function cli_crm_retry(array $args, array $assocArgs): void
{
    $dryRun = array_key_exists('dry-run', $assocArgs);
    $config = crm_configuration();

    $query = new \WP_Query([
        'post_type' => LEAD_POST_TYPE,
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'meta_query' => [
            [
                'key' => '_crm_status',
                'value' => 'failed',
            ],
        ],
    ]);

    $counts = [];

    foreach ($query->posts as $postId) {
        $postId = (int) $postId;

        if ($dryRun) {
            $counts['would_retry'] = ($counts['would_retry'] ?? 0) + 1;

            continue;
        }

        if (!$config['enabled'] || !$config['valid']) {
            update_post_meta($postId, '_crm_status', 'disabled');
            $counts['disabled'] = ($counts['disabled'] ?? 0) + 1;

            continue;
        }

        $payload = get_post_meta($postId, '_lead_payload', true);
        $lead = is_array($payload) ? $payload : [];

        $offer = (string) ($lead['offer'] ?? '');

        if ($offer === 'carte-t' && crm_carte_t_offer_value() === null) {
            update_post_meta($postId, '_crm_status', 'skipped_offer_not_mapped');
            $counts['skipped_offer_not_mapped'] = ($counts['skipped_offer_not_mapped'] ?? 0) + 1;

            continue;
        }

        $submissionId = (string) get_post_meta($postId, '_crm_submission_id', true);

        if ($submissionId === '') {
            $submissionId = crm_generate_submission_id();
            update_post_meta($postId, '_crm_submission_id', $submissionId);
        }

        $crmPayload = crm_payload(
            $lead,
            $submissionId,
            crm_landing_key($lead['offer'] ?? ''),
            crm_notice_version(),
            gmdate(\DateTimeInterface::ATOM)
        );

        deliver_crm_payload($postId, $config, $crmPayload, $submissionId);

        $status = (string) get_post_meta($postId, '_crm_status', true);
        $counts[$status] = ($counts[$status] ?? 0) + 1;
    }

    if (class_exists('\\WP_CLI')) {
        $summary = $counts === [] ? 'nothing to retry' : implode(' ', array_map(
            static fn (string $status, int $count): string => "{$status}={$count}",
            array_keys($counts),
            $counts
        ));

        \WP_CLI::log($summary);
    }
}

// -- Front-end wiring: expose the endpoint & the "Retour à l'offre" URL maps to flow.js

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\inject_lead_form_config', 20);

function inject_lead_form_config(): void
{
    if (get_landing_template() === null || !wp_script_is('unlocker-landings-flow', 'enqueued')) {
        return;
    }

    $config = [
        'endpoint' => rest_url('unlocker-landings/v1/lead'),
        // Slug => absolute URL, both for validating an explicit `from` query
        // param and for matching document.referrer's path -- see
        // resolveBackHref() in flow.js. Never a free-form URL: this is what
        // stands between a `from` param and an open redirect.
        'fromSlugs' => from_slug_whitelist(),
        // offer => absolute URL, the last-resort fallback when neither of
        // the above resolved anything.
        'offerFallback' => offer_fallback_urls(),
        // Lets flow.js look up the C2c touches producer's own visitor
        // handle in sessionStorage (same site key, same storage key shape).
        // Empty when unset -- flow.js then never attempts the lookup.
        'siteKey' => trim((string) getenv('CRM_ACQUISITION_SITE_KEY')),
    ];

    wp_add_inline_script(
        'unlocker-landings-flow',
        'window.unlockerLanding = ' . wp_json_encode($config) . ';',
        'before'
    );
}
