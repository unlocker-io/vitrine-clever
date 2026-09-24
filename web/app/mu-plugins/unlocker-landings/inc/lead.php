<?php

/**
 * The "démarrer" form: a REST endpoint that persists a lead as a private CPT
 * and forwards it to Brevo. Persistence always happens before the Brevo
 * call, and a Brevo failure never surfaces to the visitor -- the lead is
 * safe once it's in WordPress; `_brevo_status`/`_brevo_error` on the post
 * are the only trace of that failure.
 *
 * `validate_lead()` and `brevo_payload()` are pure (no WordPress call other
 * than `is_email()`) so they can be exercised outside WordPress -- see
 * tests/unlocker-landings-lead-test.php.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

const LEAD_POST_TYPE = 'unlocker_lead';
const LEAD_RATE_LIMIT_MAX = 5;
const LEAD_RATE_LIMIT_WINDOW_SECONDS = 600;
const LEAD_DEFAULT_BREVO_LIST_ID = 117;

/** @var string[] */
const LEAD_SIZE_OPTIONS = ['1–9', '10–24', '25–49', '50–99', '100+'];

/** @var string[] */
const LEAD_OFFER_OPTIONS = ['split', 'delegation'];

/** @var string[] */
const LEAD_PARCOURS_OPTIONS = ['start', 'demo'];

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

    foreach (['company' => 160, 'country' => 100, 'area' => 160] as $field => $max) {
        $value = trim((string) ($input[$field] ?? ''));

        if ($value === '') {
            $errors[$field] = 'required';
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = 'too_long';
        } else {
            $lead[$field] = $value;
        }
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

    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $field) {
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
        'country' => 'PAYS',
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

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
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
    }

    return new \WP_REST_Response(['ok' => true], 200);
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

// -- Front-end wiring: expose the endpoint & the two landing URLs to flow.js

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\inject_lead_form_config', 20);

function inject_lead_form_config(): void
{
    if (get_landing_template() === null || !wp_script_is('unlocker-landings-flow', 'enqueued')) {
        return;
    }

    $config = [
        'endpoint' => rest_url('unlocker-landings/v1/lead'),
        'split' => split_url(),
        'delegation' => delegation_url(),
    ];

    wp_add_inline_script(
        'unlocker-landings-flow',
        'window.unlockerLanding = ' . wp_json_encode($config) . ';',
        'before'
    );
}
