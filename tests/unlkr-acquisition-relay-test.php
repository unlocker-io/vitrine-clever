<?php

// Minimal WordPress harness; it intentionally does not need Composer, DDEV or
// a database. It stubs only the WordPress HTTP/transient surface used by the MU-plugin.
$GLOBALS['unlkr_http_responses'] = array();
$GLOBALS['unlkr_http_requests'] = array();
$GLOBALS['unlkr_transients'] = array();

function add_action() {}
function add_filter() {}
function wp_generate_uuid4() { static $n = 0; $n++; return sprintf('00000000-0000-4000-8000-%012d', $n); }
function set_transient($key, $value) { $GLOBALS['unlkr_transients'][$key] = $value; return true; }
function get_transient($key) { return isset($GLOBALS['unlkr_transients'][$key]) ? $GLOBALS['unlkr_transients'][$key] : false; }
function delete_transient($key) { unset($GLOBALS['unlkr_transients'][$key]); return true; }
function wp_json_encode($value) { return json_encode($value); }
function wp_remote_post($url, $args) { $GLOBALS['unlkr_http_requests'][] = array($url, $args); return array_shift($GLOBALS['unlkr_http_responses']); }
function is_wp_error($value) { return $value === 'WP_Error'; }
function wp_remote_retrieve_response_code($value) { return is_array($value) && isset($value['response']['code']) ? $value['response']['code'] : 0; }
class WP_REST_Response { public $data; public $status; public function __construct($data, $status) { $this->data = $data; $this->status = $status; } }
class Unlkr_Test_Request { private $route; public function __construct($route) { $this->route = $route; } public function get_route() { return $this->route; } }

require dirname(__DIR__) . '/web/app/mu-plugins/unlkr-acquisition-relay.php';

function check($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function response($code) { return array('response' => array('code' => $code)); }
function env_reset() {
    foreach (array('CRM_ACQUISITION_RELAY_ENABLED', 'CRM_ACQUISITION_API_URL', 'CRM_ACQUISITION_ALLOWED_HOSTS', 'CRM_ACQUISITION_SERVICE_TOKEN', 'CRM_ACQUISITION_METFORM_FORM_ID', 'CRM_ACQUISITION_LANDING_KEY', 'CRM_ACQUISITION_PRIVACY_NOTICE_VERSION', 'CRM_ACQUISITION_FIELD_EMAIL', 'CRM_ACQUISITION_FIELD_NAME', 'CRM_ACQUISITION_FIELD_PHONE', 'CRM_ACQUISITION_FIELD_BUSINESS_NAME', 'CRM_ACQUISITION_FIELD_COUNTRY', 'CRM_ACQUISITION_FIELD_AREA', 'CRM_ACQUISITION_FIELD_PROPERTY_COUNT_BAND', 'CRM_ACQUISITION_FIELD_OFFER', 'CRM_ACQUISITION_FIELD_ADS_MEASUREMENT', 'CRM_ACQUISITION_FIELD_ADS_SHARING', 'CRM_ACQUISITION_FIELD_MARKETING_OPT_IN') as $key) { putenv($key); }
}
function configure() {
    env_reset();
    foreach (array(
        'CRM_ACQUISITION_RELAY_ENABLED=1',
        'CRM_ACQUISITION_API_URL=https://crm.example.test/api/v1/acquisition/requests',
        'CRM_ACQUISITION_ALLOWED_HOSTS=crm.example.test',
        'CRM_ACQUISITION_SERVICE_TOKEN=not-a-real-secret',
        'CRM_ACQUISITION_METFORM_FORM_ID=42',
        'CRM_ACQUISITION_LANDING_KEY=mountain-concierges',
        'CRM_ACQUISITION_PRIVACY_NOTICE_VERSION=2026-09',
        'CRM_ACQUISITION_FIELD_EMAIL=email', 'CRM_ACQUISITION_FIELD_NAME=name', 'CRM_ACQUISITION_FIELD_PHONE=phone',
        'CRM_ACQUISITION_FIELD_BUSINESS_NAME=business', 'CRM_ACQUISITION_FIELD_COUNTRY=country', 'CRM_ACQUISITION_FIELD_AREA=area',
        'CRM_ACQUISITION_FIELD_PROPERTY_COUNT_BAND=band', 'CRM_ACQUISITION_FIELD_OFFER=offer',
        'CRM_ACQUISITION_FIELD_ADS_MEASUREMENT=measure', 'CRM_ACQUISITION_FIELD_ADS_SHARING=sharing', 'CRM_ACQUISITION_FIELD_MARKETING_OPT_IN=marketing',
    ) as $entry) { putenv($entry); }
}
function form_data() { return array('email' => 'hello@example.test', 'name' => 'Alex', 'phone' => '', 'business' => 'Alpine Services', 'country' => 'fr', 'area' => 'Savoie', 'band' => '10_49', 'offer' => 'split', 'measure' => 'denied', 'sharing' => 'denied', 'marketing' => '0'); }

configure();
$relay = new Unlkr_Acquisition_Relay();
$config = $relay->configuration();
check($config['valid'], 'complete configuration is accepted');
$payload = $relay->payload(form_data(), $config);
check($payload['contact']['phone'] === null && $payload['landing_key'] === 'mountain-concierges', 'mapper emits null empty phone and server landing key');
putenv('CRM_ACQUISITION_ALLOWED_HOSTS=other.example.test');
check(!$relay->configuration()['valid'], 'host allowlist fails closed');
configure();

$GLOBALS['unlkr_http_responses'] = array(response(201));
$relay->after_store(42, form_data(), array(), array('entry_id' => 7));
check(count($GLOBALS['unlkr_http_requests']) === 1, 'first configured form is delivered');
$first = $GLOBALS['unlkr_http_requests'][0][1];
check($first['headers']['Idempotency-Key'] === json_decode($first['body'], true)['submission_id'], 'header and body share the stable UUID');
check($first['redirection'] === 0 && $first['timeout'] === 3, 'redirects disabled and timeout bounded');

$GLOBALS['unlkr_http_responses'] = array(response(200));
$relay->after_store(42, form_data(), array(), array('entry_id' => 7));
check($GLOBALS['unlkr_http_requests'][1][1]['headers']['Idempotency-Key'] === $first['headers']['Idempotency-Key'], 'same entry reuses UUID for 200 replay');
$GLOBALS['unlkr_http_responses'] = array(response(201));
$relay->after_store(41, form_data(), array(), array('entry_id' => 8));
check(count($GLOBALS['unlkr_http_requests']) === 2, 'unknown form remains inactive');

$GLOBALS['unlkr_http_responses'] = array('WP_Error', response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000099');
check($result['ok'] && $result['attempts'] === 2, 'network timeout gets exactly one retry');
$GLOBALS['unlkr_http_responses'] = array(response(429), response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000098');
check($result['ok'] && $result['attempts'] === 2, '429 gets exactly one retry');
$GLOBALS['unlkr_http_responses'] = array(response(500), response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000097');
check($result['ok'] && $result['attempts'] === 2, '5xx gets exactly one retry');
$before_oversize = count($GLOBALS['unlkr_http_requests']);
$result = $relay->deliver($config, array('oversize' => str_repeat('a', 32769)), '00000000-0000-4000-8000-000000000096');
check(!$result['ok'] && $result['attempts'] === 0 && count($GLOBALS['unlkr_http_requests']) === $before_oversize, 'oversized JSON stays local');

$GLOBALS['unlkr_http_responses'] = array(response(409));
$relay->after_store(42, form_data(), array(), array('entry_id' => 9));
$error = $relay->rest_post_dispatch('original', null, new Unlkr_Test_Request('/metform/v1/entries/insert/42'));
check($error instanceof WP_REST_Response && $error->status === 422 && $error->data['success'] === false, '409 returns neutral correctable response');
$source = file_get_contents(dirname(__DIR__) . '/web/app/mu-plugins/unlkr-acquisition-relay.php');
check(strpos($source, 'error_log') === false && strpos($source, 'CRM_ACQUISITION_SERVICE_TOKEN') !== false, 'relay does not log its secret');

echo "OK - 15 assertions\n";
