<?php

// Minimal WordPress harness; it needs neither Composer, DDEV nor a database.
// The option stubs model WordPress' atomic unique option_name insert and the
// fake wpdb models the compare-and-swap rotation used after a CRM 409/422.
$GLOBALS['unlkr_http_responses'] = array();
$GLOBALS['unlkr_http_requests'] = array();
$GLOBALS['unlkr_options'] = array();
$GLOBALS['unlkr_before_cas'] = null;

function add_action() {}
function add_filter() {}
function wp_generate_uuid4() { static $n = 0; $n++; return sprintf('00000000-0000-4000-8000-%012d', $n); }
function add_option($name, $value) { if (array_key_exists($name, $GLOBALS['unlkr_options'])) { return false; } $GLOBALS['unlkr_options'][$name] = $value; return true; }
function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['unlkr_options']) ? $GLOBALS['unlkr_options'][$name] : $default; }
function update_option($name, $value) { $GLOBALS['unlkr_options'][$name] = $value; return true; }
function delete_option($name) { unset($GLOBALS['unlkr_options'][$name]); return true; }
function wp_cache_delete() {}
function wp_json_encode($value) { return json_encode($value); }
function wp_safe_remote_post($url, $args) { $GLOBALS['unlkr_http_requests'][] = array($url, $args); return array_shift($GLOBALS['unlkr_http_responses']); }
function is_wp_error($value) { return $value === 'WP_Error'; }
function wp_remote_retrieve_response_code($value) { return is_array($value) && isset($value['response']['code']) ? $value['response']['code'] : 0; }
class WP_REST_Response { public $data; public $status; public function __construct($data, $status) { $this->data = $data; $this->status = $status; } }
class Unlkr_Test_Request { private $route; public function __construct($route) { $this->route = $route; } public function get_route() { return $this->route; } }
class Unlkr_Test_Wpdb {
    public $options = 'wp_options';
    public function prepare($sql) { $arguments = func_get_args(); array_shift($arguments); return serialize(array($sql, $arguments)); }
    public function query($sql) { list($statement, $arguments) = unserialize($sql); list($next, $name, $previous) = $arguments; if ($GLOBALS['unlkr_before_cas'] !== null) { $callback = $GLOBALS['unlkr_before_cas']; $GLOBALS['unlkr_before_cas'] = null; $callback($next, $name, $previous); } if (!isset($GLOBALS['unlkr_options'][$name]) || $GLOBALS['unlkr_options'][$name] !== $previous) { return 0; } $GLOBALS['unlkr_options'][$name] = $next; return 1; }
    public function esc_like($value) { return $value; }
    public function get_col($sql) { return array_values(array_filter(array_keys($GLOBALS['unlkr_options']), function ($name) { $record = isset($GLOBALS['unlkr_options'][$name]) ? json_decode($GLOBALS['unlkr_options'][$name], true) : null; return strpos($name, 'unlkr_acq_attempt_') === 0 && $name !== 'unlkr_acq_attempt_purge_after' && $name !== 'unlkr_acq_attempt_purge_lock' && is_array($record) && isset($record['expires_at']) && $record['expires_at'] <= time(); })); }
}
$wpdb = new Unlkr_Test_Wpdb();

require dirname(__DIR__) . '/web/app/mu-plugins/unlkr-acquisition-relay.php';

function check($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
function response($code) { return array('response' => array('code' => $code)); }
function body_at($request_index) { return json_decode($GLOBALS['unlkr_http_requests'][$request_index][1]['body'], true); }
function env_reset() {
    foreach (array('CRM_ACQUISITION_RELAY_ENABLED', 'CRM_ACQUISITION_API_URL', 'CRM_ACQUISITION_ALLOWED_HOSTS', 'CRM_ACQUISITION_SERVICE_TOKEN', 'CRM_ACQUISITION_METFORM_FORM_ID', 'CRM_ACQUISITION_LANDING_KEY', 'CRM_ACQUISITION_PRIVACY_NOTICE_VERSION', 'CRM_ACQUISITION_CONSENT_GATE_CONFIRMED', 'CRM_ACQUISITION_ATTEMPT_RETENTION_DAYS', 'CRM_ACQUISITION_FIELD_EMAIL', 'CRM_ACQUISITION_FIELD_NAME', 'CRM_ACQUISITION_FIELD_PHONE', 'CRM_ACQUISITION_FIELD_BUSINESS_NAME', 'CRM_ACQUISITION_FIELD_COUNTRY', 'CRM_ACQUISITION_FIELD_AREA', 'CRM_ACQUISITION_FIELD_PROPERTY_COUNT_BAND', 'CRM_ACQUISITION_FIELD_OFFER', 'CRM_ACQUISITION_FIELD_ADS_MEASUREMENT', 'CRM_ACQUISITION_FIELD_ADS_SHARING', 'CRM_ACQUISITION_FIELD_MARKETING_OPT_IN', 'CRM_ACQUISITION_FIELD_ATTEMPT_TOKEN') as $key) { putenv($key); }
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
        'CRM_ACQUISITION_CONSENT_GATE_CONFIRMED=1',
        'CRM_ACQUISITION_FIELD_EMAIL=email', 'CRM_ACQUISITION_FIELD_NAME=name', 'CRM_ACQUISITION_FIELD_PHONE=phone',
        'CRM_ACQUISITION_FIELD_BUSINESS_NAME=business', 'CRM_ACQUISITION_FIELD_COUNTRY=country', 'CRM_ACQUISITION_FIELD_AREA=area',
        'CRM_ACQUISITION_FIELD_PROPERTY_COUNT_BAND=band', 'CRM_ACQUISITION_FIELD_OFFER=offer',
        'CRM_ACQUISITION_FIELD_ADS_MEASUREMENT=measure', 'CRM_ACQUISITION_FIELD_ADS_SHARING=sharing', 'CRM_ACQUISITION_FIELD_MARKETING_OPT_IN=marketing', 'CRM_ACQUISITION_FIELD_ATTEMPT_TOKEN=unlkr_attempt',
    ) as $entry) { putenv($entry); }
}
function form_data($attempt) { return array('id' => 42, 'email' => 'hello@example.test', 'name' => 'Alex', 'phone' => '', 'business' => 'Alpine Services', 'country' => 'fr', 'area' => 'Savoie', 'band' => '10_49', 'offer' => 'split', 'measure' => 'denied', 'sharing' => 'denied', 'marketing' => '0', 'unlkr_attempt' => $attempt); }
function official_attributes() { return array('email_field_name' => 'email', 'file_data' => array(), 'file_upload_info' => array()); }

$attempt_a = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$attempt_b = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
$attempt_c = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
$attempt_d = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
$attempt_e = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
configure();
$relay = new Unlkr_Acquisition_Relay();
$config = $relay->configuration();
check($config['valid'], 'complete configuration plus explicit consent gate is accepted');
check($config['retention_seconds'] === 2592000, 'technical attempt-map retention defaults to 30 days');
$payload = $relay->payload(form_data($attempt_a), $config);
check($payload['contact']['phone'] === null && $payload['landing_key'] === 'mountain-concierges', 'mapper emits null empty phone and server landing key');
$invalid_format = form_data($attempt_a); $invalid_format['country'] = 'France';
check($relay->payload($invalid_format, $config) === null, 'C1b country and enum format stays strict locally');
putenv('CRM_ACQUISITION_CONSENT_GATE_CONFIRMED=0');
check(!$relay->configuration()['valid'], 'consent integration gate fails closed');
configure();
putenv('CRM_ACQUISITION_API_URL=https://crm.example.test:8443/api/v1/acquisition/requests');
check(!$relay->configuration()['valid'], 'unexpected TLS port fails closed');
configure();

$GLOBALS['unlkr_http_responses'] = array(response(201), response(201));
$relay->after_store(42, form_data($attempt_a), array(), official_attributes());
$relay->after_store(42, form_data($attempt_b), array(), official_attributes());
check(count($GLOBALS['unlkr_http_requests']) === 2, 'two prospects from one form are both delivered');
check(body_at(0)['submission_id'] !== body_at(1)['submission_id'], 'form_data id is ignored: distinct attempts receive distinct CRM UUIDs');
$first_id = body_at(0)['submission_id'];
$first_request = $GLOBALS['unlkr_http_requests'][0][1];
check($first_request['headers']['Idempotency-Key'] === $first_id && $first_request['redirection'] === 0 && $first_request['sslverify'] === true && $first_request['reject_unsafe_urls'] === true, 'safe HTTP request preserves key, TLS verification and redirect guard');

$GLOBALS['unlkr_http_responses'] = array(response(200));
$relay->after_store(42, form_data($attempt_a), array(), official_attributes());
check(body_at(2)['submission_id'] === $first_id, 'same attempt receives stable 200 replay UUID');

// Two independent relay instances simulate PHP workers handling a double click.
// add_option supplies the durable unique insert; C1b receives one idempotency key.
$GLOBALS['unlkr_http_responses'] = array(response(201), response(200));
(new Unlkr_Acquisition_Relay())->after_store(42, form_data($attempt_c), array(), official_attributes());
(new Unlkr_Acquisition_Relay())->after_store(42, form_data($attempt_c), array(), official_attributes());
check(body_at(3)['submission_id'] === body_at(4)['submission_id'], 'concurrent double-submit shares durable mapping');

$GLOBALS['unlkr_http_responses'] = array(response(409), response(201));
$relay->after_store(42, form_data($attempt_d), array(), official_attributes());
$conflict_id = body_at(5)['submission_id'];
$error = $relay->rest_post_dispatch('original', null, new Unlkr_Test_Request('/metform/v1/entries/insert/42'));
check($error instanceof WP_REST_Response && $error->status === 422 && $error->data['success'] === false, '409 is neutral and correctable');
check($relay->rest_post_dispatch('original', null, new Unlkr_Test_Request('/metform/v1/entries/insert/99')) === 'original', 'other REST route is untouched');
$relay->after_store(42, form_data($attempt_d), array(), official_attributes());
check(body_at(6)['submission_id'] !== $conflict_id, '409 atomically rotates next corrected submission UUID');

$GLOBALS['unlkr_http_responses'] = array(response(422), response(201));
$relay->after_store(42, form_data($attempt_e), array(), official_attributes());
$unprocessable_id = body_at(7)['submission_id'];
$relay->after_store(42, form_data($attempt_e), array(), official_attributes());
check(body_at(8)['submission_id'] !== $unprocessable_id, '422 atomically rotates next corrected submission UUID');

$attempt_f = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
$attempt_g = '12121212-1212-4121-8121-121212121212';
$attempt_h = '13131313-1313-4131-8131-131313131313';
$attempt_i = '14141414-1414-4141-8141-141414141414';
$expired_id = '99999999-9999-4999-8999-999999999999';
$winner_id = '88888888-8888-4888-8888-888888888888';
$expired_name = 'unlkr_acq_attempt_' . substr(hash('sha256', $attempt_f), 0, 40);
$GLOBALS['unlkr_options'][$expired_name] = json_encode(array('id' => $expired_id, 'created_at' => 1, 'expires_at' => 2));
$GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_after'] = time() + 3600;
$GLOBALS['unlkr_before_cas'] = function ($next, $name, $previous) use ($expired_name, $winner_id) { if ($name === $expired_name) { $GLOBALS['unlkr_options'][$name] = json_encode(array('id' => $winner_id, 'created_at' => time(), 'expires_at' => time() + 2592000)); } };
$GLOBALS['unlkr_http_responses'] = array(response(201));
$relay->after_store(42, form_data($attempt_f), array(), official_attributes());
check(body_at(9)['submission_id'] === $winner_id, 'expiration race rereads winner and never erases fresh mapping');
check(json_decode($GLOBALS['unlkr_options'][$expired_name], true)['expires_at'] > time(), 'winning attempt persists created and expiry timestamps');
$purge_name = 'unlkr_acq_attempt_' . substr(hash('sha256', $attempt_g), 0, 40);
$GLOBALS['unlkr_options'][$purge_name] = json_encode(array('id' => $expired_id, 'created_at' => 1, 'expires_at' => 2));
$GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_after'] = 0;
$GLOBALS['unlkr_http_responses'] = array(response(201));
$relay->after_store(42, form_data($attempt_h), array(), official_attributes());
check(!isset($GLOBALS['unlkr_options'][$purge_name]), 'bounded purge removes expired opaque mapping idempotently');

for ($index = 0; $index < 101; $index++) {
    $GLOBALS['unlkr_options']['unlkr_acq_attempt_backlog_' . $index] = json_encode(array('id' => $expired_id, 'created_at' => 1, 'expires_at' => 2));
}
$GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_after'] = 0;
$GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_lock'] = (string) (time() - 301) . ':orphaned';
$GLOBALS['unlkr_http_responses'] = array(response(201));
$relay->after_store(42, form_data($attempt_i), array(), official_attributes());
check($GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_lock'] === '0', 'stale purge lease is recovered and safely released');
check((int) $GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_after'] <= time() + 60, 'backlog drains in bounded one-minute batches rather than growing forever');

$GLOBALS['unlkr_http_responses'] = array('WP_Error', response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000099');
check($result['ok'] && $result['attempts'] === 2, 'network timeout gets exactly one retry');
$GLOBALS['unlkr_http_responses'] = array(response(429), response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000098');
check($result['ok'] && $result['attempts'] === 2, '429 gets exactly one retry');
$GLOBALS['unlkr_http_responses'] = array(response(500), response(201));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000097');
check($result['ok'] && $result['attempts'] === 2, '5xx gets exactly one retry');
$GLOBALS['unlkr_http_responses'] = array(response(400));
$result = $relay->deliver($config, array('submission_id' => 'a'), '00000000-0000-4000-8000-000000000095');
check(!$result['ok'] && $result['attempts'] === 1, '4xx does not retry');
$before_oversize = count($GLOBALS['unlkr_http_requests']);
$result = $relay->deliver($config, array('oversize' => str_repeat('a', 32769)), '00000000-0000-4000-8000-000000000096');
check(!$result['ok'] && $result['attempts'] === 0 && count($GLOBALS['unlkr_http_requests']) === $before_oversize, 'oversized JSON stays local');

putenv('CRM_ACQUISITION_RELAY_ENABLED=0');
$GLOBALS['unlkr_options'] = array();
$before_disabled = count($GLOBALS['unlkr_http_requests']);
$relay->after_store(42, form_data($attempt_a), array(), official_attributes());
check(count($GLOBALS['unlkr_http_requests']) === $before_disabled && !isset($GLOBALS['unlkr_options']['unlkr_acq_attempt_purge_after']), 'disabled relay neither sends nor schedules purge work');
$source = file_get_contents(dirname(__DIR__) . '/web/app/mu-plugins/unlkr-acquisition-relay.php');
check(strpos($source, 'error_log') === false && strpos($source, 'wp_safe_remote_post') !== false && strpos($source, 'wp_remote_post') === false && strpos($source, 'wp_schedule_event') === false, 'relay has no secret logs, uses safe HTTP and registers no cron');

echo "OK - 33 assertions\n";
