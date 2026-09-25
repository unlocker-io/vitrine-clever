<?php

declare(strict_types=1);

/**
 * Integration tests for the crm-skip gate added to send_lead_to_crm(): a
 * `carte-t` lead must never reach the CRM while
 * UNLOCKER_LANDING_CRM_OFFER_CARTE_T is unset (the CRM doesn't accept that
 * offer yet), and Brevo delivery must be entirely unaffected by that gate --
 * it is called independently, earlier, from handle_lead_request().
 *
 * This is the first test file to exercise
 * send_lead_to_crm()/deliver_crm_payload()/send_lead_to_brevo(): previously
 * only the pure crm_payload()/validate_lead()/brevo_payload()/
 * lead_client_ip() functions were tested (see
 * tests/unlocker-landings-lead-test.php). No real network call is ever made
 * -- wp_remote_post()/wp_safe_remote_post() are stubbed below and every call
 * is captured into $GLOBALS['test_remote_calls'].
 *
 * Run with:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/unlocker-landings-crm-skip-test.php
 */

if (!function_exists('add_action')) {
    function add_action(...$args)
    {
    }
}

if (!function_exists('add_filter')) {
    function add_filter(...$args)
    {
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

$GLOBALS['test_post_meta'] = [];

function update_post_meta($postId, $key, $value)
{
    $GLOBALS['test_post_meta'][$postId][$key] = $value;
}

function delete_post_meta($postId, $key)
{
    unset($GLOBALS['test_post_meta'][$postId][$key]);
}

class Test_WP_Error
{
    public function get_error_code()
    {
        return 'stub_error';
    }
}

function is_wp_error($thing)
{
    return $thing instanceof Test_WP_Error;
}

$GLOBALS['test_remote_calls'] = [];
$GLOBALS['test_remote_response'] = ['status' => 200, 'body' => '{}'];

function wp_remote_post($url, $args)
{
    $GLOBALS['test_remote_calls'][] = ['url' => $url, 'args' => $args];

    return $GLOBALS['test_remote_response'];
}

function wp_safe_remote_post($url, $args)
{
    return wp_remote_post($url, $args);
}

function wp_remote_retrieve_response_code($response)
{
    return $response['status'];
}

function wp_remote_retrieve_body($response)
{
    return $response['body'];
}

function wp_json_encode($value)
{
    return json_encode($value);
}

function wp_generate_uuid4()
{
    return 'test-submission-uuid';
}

require dirname(__DIR__) . '/web/app/mu-plugins/unlocker-landings/inc/lead.php';

use function Unlocker\Landings\send_lead_to_brevo;
use function Unlocker\Landings\send_lead_to_crm;
use function Unlocker\Landings\validate_lead;

$assertions = 0;
$failures = 0;

function check(string $label, bool $condition): void
{
    global $assertions, $failures;

    $assertions++;

    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$label}\n");
    }
}

/**
 * Builds a valid lead (as validate_lead() would return it) for the given
 * offer, exactly like crmLead() does in tests/unlocker-landings-lead-test.php.
 *
 * @return array<string, string>
 */
function leadWithOffer(string $offer): array
{
    [, $lead] = validate_lead([
        'email' => 'contact@conciergerie-montagne.fr',
        'company' => 'Conciergerie des Cimes',
        'country' => 'FR',
        'area' => 'Chamonix, vallée de l’Arve',
        'size' => '10–24',
        'offer' => $offer,
        'parcours' => 'demo',
    ]);

    return $lead;
}

function configureCrmEnv(): void
{
    foreach ([
        'UNLOCKER_LANDING_CRM_ENABLED=1',
        'CRM_ACQUISITION_API_URL=https://crm.example.test/api/v1/acquisition/requests',
        'CRM_ACQUISITION_ALLOWED_HOSTS=crm.example.test',
        'CRM_ACQUISITION_SERVICE_TOKEN=test-token',
    ] as $entry) {
        putenv($entry);
    }
}

function resetCarteTEnv(): void
{
    putenv('UNLOCKER_LANDING_CRM_OFFER_CARTE_T');
}

function resetTestState(): void
{
    $GLOBALS['test_remote_calls'] = [];
    $GLOBALS['test_remote_response'] = ['status' => 200, 'body' => '{}'];
    $GLOBALS['test_post_meta'] = [];
}

// -- Scenario 1: carte-t, no CRM mapping -> the CRM is never called ---------

configureCrmEnv();
resetCarteTEnv();
resetTestState();

$lead1 = leadWithOffer('carte-t');
send_lead_to_crm(1, $lead1);

check('carte-t lead with no CRM mapping never calls the CRM', $GLOBALS['test_remote_calls'] === []);
check(
    'carte-t lead with no CRM mapping: _crm_status is skipped_offer_not_mapped',
    ($GLOBALS['test_post_meta'][1]['_crm_status'] ?? null) === 'skipped_offer_not_mapped'
);

// -- Scenario 2: carte-t, CRM mapping configured -> the CRM IS called -------

configureCrmEnv();
putenv('UNLOCKER_LANDING_CRM_OFFER_CARTE_T=carte_t');
resetTestState();

$lead2 = leadWithOffer('carte-t');
send_lead_to_crm(2, $lead2);

check('carte-t lead with CRM mapping configured: the CRM is called exactly once', count($GLOBALS['test_remote_calls']) === 1);

$sentBody2 = json_decode((string) ($GLOBALS['test_remote_calls'][0]['args']['body'] ?? ''), true);
check('carte-t lead with CRM mapping configured: offer field decodes to the configured value', ($sentBody2['offer'] ?? null) === 'carte_t');

resetCarteTEnv();

// -- Scenario 3: delegation (pre-existing offer) -> unaffected by the guard --

configureCrmEnv();
resetCarteTEnv();
resetTestState();

$lead3 = leadWithOffer('delegation');
send_lead_to_crm(3, $lead3);

check('delegation lead: the CRM is called (regression, the new guard does not affect pre-existing offers)', count($GLOBALS['test_remote_calls']) === 1);
check('delegation lead: _crm_status ends as sent', ($GLOBALS['test_post_meta'][3]['_crm_status'] ?? null) === 'sent');

// -- Scenario 4: Brevo independence -- same carte-t lead, env var still unset

configureCrmEnv();
resetCarteTEnv();
resetTestState();
putenv('UNLOCKER_LANDING_BREVO_API_KEY=test-brevo-key');

$lead4 = leadWithOffer('carte-t');
$GLOBALS['test_remote_response'] = ['status' => 201, 'body' => '{}'];
send_lead_to_brevo(4, $lead4);

check('Brevo independence: exactly one remote call was made (to Brevo) for the carte-t lead', count($GLOBALS['test_remote_calls']) === 1);
check('Brevo independence: the call hit the Brevo contacts endpoint', ($GLOBALS['test_remote_calls'][0]['url'] ?? null) === 'https://api.brevo.com/v3/contacts');
check('Brevo independence: _brevo_status ends as sent for the carte-t lead, proving Brevo fires regardless of the CRM skip', ($GLOBALS['test_post_meta'][4]['_brevo_status'] ?? null) === 'sent');

putenv('UNLOCKER_LANDING_BREVO_API_KEY');

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
