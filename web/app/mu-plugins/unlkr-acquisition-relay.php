<?php
/**
 * Plugin Name: Unlocker acquisition relay
 * Description: Relays one explicitly configured MetForm to the internal CRM.
 * Version: 1.0.0
 *
 * This relay deliberately has no WordPress admin screen.  It is disabled until
 * its complete server-side configuration is present, and never stores form
 * values, credentials, or advertising identifiers locally.
 */

if (!class_exists('Unlkr_Acquisition_Relay')) {
    final class Unlkr_Acquisition_Relay
    {
        const NEUTRAL_ERROR = 'Votre demande a bien été reçue, mais nous ne pouvons pas la transmettre pour le moment. Veuillez réessayer.';

        /** @var array<string, mixed>|null */
        private $last_delivery = null;

        public function __construct()
        {
            if (function_exists('add_action')) {
                add_action('metform_after_store_form_data', array($this, 'after_store'), 10, 4);
            }

            if (function_exists('add_filter')) {
                add_filter('rest_post_dispatch', array($this, 'rest_post_dispatch'), 10, 3);
            }

            if (function_exists('add_action')) {
                add_action('wp_footer', array($this, 'render_attempt_field_bootstrap'), 100);
            }
        }

        /**
         * MetForm calls this after its own validation, nonce/captcha checks and
         * entry persistence. Do not hook before its persistence pipeline.
         *
         * @param mixed $form_id
         * @param array<string, mixed> $form_data
         * @param mixed $settings
         * @param array<string, mixed> $attributes
         */
        public function after_store($form_id, $form_data, $settings = array(), $attributes = array())
        {
            $this->last_delivery = null;
            $config = $this->configuration();

            if (!$config['enabled'] || (int) $form_id !== (int) $config['form_id']) {
                return;
            }

            if (!$config['valid']) {
                $this->last_delivery = array('ok' => false, 'status' => 503, 'reason' => 'configuration');
                return;
            }

            $payload = $this->payload($form_data, $config);
            if ($payload === null) {
                $this->last_delivery = array('ok' => false, 'status' => 422, 'reason' => 'invalid_form_data');
                return;
            }

            $attempt_token = $this->attempt_token($form_data, $config);
            if ($attempt_token === null) {
                $this->last_delivery = array('ok' => false, 'status' => 422, 'reason' => 'invalid_attempt_token');
                return;
            }

            $submission_id = $this->submission_id($attempt_token);
            if ($submission_id === null) {
                $this->last_delivery = array('ok' => false, 'status' => 503, 'reason' => 'attempt_storage');
                return;
            }
            $payload['submission_id'] = $submission_id;

            $delivery = $this->deliver($config, $payload, $submission_id);
            $this->last_delivery = $delivery;

            // C1b returns 409 when a stable key was used with a changed payload.
            // Rotate the server-side mapping after 409/422 so a correction remains
            // possible. The compare-and-swap update is safe if duplicate requests
            // race each other; the opaque browser attempt token itself is unchanged.
            if (in_array((int) $delivery['status'], array(409, 422), true)) {
                $this->rotate_submission_id($attempt_token, $submission_id);
            }
        }

        /**
         * Makes a CRM delivery failure visible to the MetForm REST caller, while
         * preserving MetForm's own validation/nonces/captcha behaviour.  It is
         * intentionally limited to the configured insert route and form ID.
         *
         * @param mixed $response
         * @param mixed $server
         * @param mixed $request
         * @return mixed
         */
        public function rest_post_dispatch($response, $server, $request)
        {
            if ($this->last_delivery === null || !empty($this->last_delivery['ok']) || !is_object($request) || !method_exists($request, 'get_route')) {
                return $response;
            }

            $config = $this->configuration();
            $route = (string) $request->get_route();
            if (!preg_match('#^/metform/v1/entries/insert/(\\d+)$#', $route, $matches) || (int) $matches[1] !== (int) $config['form_id']) {
                return $response;
            }

            $status = (int) $this->last_delivery['status'];
            $status = $status === 422 || $status === 409 ? 422 : 503;
            if (class_exists('WP_REST_Response')) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => self::NEUTRAL_ERROR,
                ), $status);
            }

            return $response;
        }

        /** @return array<string, mixed> */
        public function configuration()
        {
            $field_names = array('email', 'name', 'phone', 'business_name', 'country', 'area', 'property_count_band', 'offer', 'ads_measurement', 'ads_sharing', 'marketing_opt_in', 'attempt_token');
            $fields = array();
            foreach ($field_names as $field_name) {
                $fields[$field_name] = trim((string) getenv('CRM_ACQUISITION_FIELD_' . strtoupper($field_name)));
            }

            $enabled = filter_var(getenv('CRM_ACQUISITION_RELAY_ENABLED'), FILTER_VALIDATE_BOOLEAN);
            $url = trim((string) getenv('CRM_ACQUISITION_API_URL'));
            $allowed_hosts = array_filter(array_map('trim', explode(',', (string) getenv('CRM_ACQUISITION_ALLOWED_HOSTS'))));
            $parts = parse_url($url);
            $url_is_allowed = is_array($parts)
                && isset($parts['scheme'], $parts['host'], $parts['path'])
                && strtolower((string) $parts['scheme']) === 'https'
                && $parts['path'] === '/api/v1/acquisition/requests'
                && (!isset($parts['port']) || (int) $parts['port'] === 443)
                && in_array(strtolower((string) $parts['host']), array_map('strtolower', $allowed_hosts), true)
                && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment']);

            $valid = $url_is_allowed
                && trim((string) getenv('CRM_ACQUISITION_SERVICE_TOKEN')) !== ''
                && ctype_digit((string) getenv('CRM_ACQUISITION_METFORM_FORM_ID'))
                && (int) getenv('CRM_ACQUISITION_METFORM_FORM_ID') > 0
                && trim((string) getenv('CRM_ACQUISITION_LANDING_KEY')) !== ''
                && trim((string) getenv('CRM_ACQUISITION_PRIVACY_NOTICE_VERSION')) !== ''
                // Deliberate deployment gate: this plugin does not infer CookieYes
                // state. An operator confirms the tested consent-field mapping.
                && getenv('CRM_ACQUISITION_CONSENT_GATE_CONFIRMED') === '1';
            foreach ($fields as $field) {
                $valid = $valid && $field !== '';
            }

            return array(
                'enabled' => $enabled,
                'valid' => $valid,
                'url' => $url,
                'token' => (string) getenv('CRM_ACQUISITION_SERVICE_TOKEN'),
                'form_id' => (int) getenv('CRM_ACQUISITION_METFORM_FORM_ID'),
                'landing_key' => trim((string) getenv('CRM_ACQUISITION_LANDING_KEY')),
                'notice_version' => trim((string) getenv('CRM_ACQUISITION_PRIVACY_NOTICE_VERSION')),
                'fields' => $fields,
            );
        }

        /**
         * Adds a random opaque attempt token only to the configured form. The
         * operator must first add the matching hidden MetForm input. No cookie,
         * PII, credential, or advertising identifier is written to the page.
         */
        public function render_attempt_field_bootstrap()
        {
            $config = $this->configuration();
            if (!$config['enabled'] || !$config['valid']) {
                return;
            }

            $form_id = (int) $config['form_id'];
            $field_name = function_exists('wp_json_encode')
                ? wp_json_encode($config['fields']['attempt_token'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                : json_encode($config['fields']['attempt_token']);
            ?>
<script>(function(){'use strict';var formId=<?php echo $form_id; ?>,fieldName=<?php echo $field_name; ?>,uuid=function(){if(window.crypto&&window.crypto.randomUUID){return window.crypto.randomUUID();}var a=new Uint8Array(16);window.crypto.getRandomValues(a);a[6]=(a[6]&15)|64;a[8]=(a[8]&63)|128;var h=[];for(var i=0;i<a.length;i++){h.push(('0'+a[i].toString(16)).slice(-2));}return h.slice(0,4).join('')+'-'+h.slice(4,6).join('')+'-'+h.slice(6,8).join('')+'-'+h.slice(8,10).join('')+'-'+h.slice(10,16).join('');},valid=/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i,attach=function(){var wrappers=document.querySelectorAll('[data-form-id]');for(var i=0;i<wrappers.length;i++){if(String(wrappers[i].getAttribute('data-form-id'))!==String(formId)){continue;}var inputs=wrappers[i].querySelectorAll('input[type="hidden"]');for(var j=0;j<inputs.length;j++){if(inputs[j].name===fieldName&&!valid.test(inputs[j].value)){inputs[j].value=uuid();}}}};if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',attach);}else{attach();}}());</script>
            <?php
        }

        /**
         * @param array<string, mixed> $form_data
         * @param array<string, mixed> $config
         * @return array<string, mixed>|null
         */
        public function payload($form_data, $config)
        {
            $values = array();
            foreach ($config['fields'] as $name => $field_key) {
                $values[$name] = $this->field_value($form_data, $field_key);
            }

            $email = $this->string_or_null($values['email']);
            $business_name = $this->string_or_null($values['business_name']);
            $country = strtoupper((string) $this->string_or_null($values['country']));
            $area = $this->string_or_null($values['area']);
            $property_count_band = $this->string_or_null($values['property_count_band']);
            $offer = $this->string_or_null($values['offer']);
            $ads_measurement = strtolower((string) $this->string_or_null($values['ads_measurement']));
            $ads_sharing = strtolower((string) $this->string_or_null($values['ads_sharing']));
            $marketing_opt_in = $this->boolean_or_null($values['marketing_opt_in']);

            if ($email === null || $business_name === null || !preg_match('/^[A-Z]{2}$/', $country) || $area === null
                || !in_array($property_count_band, array('1_9', '10_49', '50_99', '100_plus', 'unknown'), true)
                || !in_array($offer, array('split', 'delegation_g'), true)
                || !in_array($ads_measurement, array('granted', 'denied', 'unknown'), true)
                || !in_array($ads_sharing, array('granted', 'denied', 'unknown'), true)
                || $marketing_opt_in === null) {
                return null;
            }

            $phone = $this->string_or_null($values['phone']);
            $name = $this->string_or_null($values['name']);

            return array(
                'schema_version' => 1,
                'contact' => array('email' => $email, 'name' => $name, 'phone' => $phone),
                'business' => array(
                    'name' => $business_name,
                    'country' => $country,
                    'area' => $area,
                    'property_count_band' => $property_count_band,
                ),
                'offer' => $offer,
                'landing_key' => $config['landing_key'],
                'privacy' => array(
                    'notice_version' => $config['notice_version'],
                    'ads_measurement' => $ads_measurement,
                    'ads_sharing' => $ads_sharing,
                    'marketing_opt_in' => $marketing_opt_in,
                ),
            );
        }

        /**
         * @param array<string, mixed> $form_data
         * @return mixed
         */
        private function field_value($form_data, $field_key)
        {
            $source = isset($form_data[$field_key]) ? $form_data[$field_key] : (isset($form_data['fields']) && is_array($form_data['fields']) && isset($form_data['fields'][$field_key]) ? $form_data['fields'][$field_key] : null);
            if (is_array($source) && array_key_exists('value', $source)) {
                $source = $source['value'];
            }

            return is_scalar($source) ? $source : null;
        }

        /** @return string|null */
        private function string_or_null($value)
        {
            if (!is_string($value) && !is_numeric($value)) {
                return null;
            }

            $value = trim((string) $value);
            return $value === '' ? null : $value;
        }

        /** @return bool|null */
        private function boolean_or_null($value)
        {
            if (is_bool($value)) {
                return $value;
            }
            if (!is_string($value) && !is_numeric($value)) {
                return null;
            }

            $value = strtolower(trim((string) $value));
            if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($value, array('0', 'false', 'no', 'off'), true)) {
                return false;
            }

            return null;
        }

        /** @return string|null */
        private function attempt_token($form_data, $config)
        {
            $value = $this->string_or_null($this->field_value($form_data, $config['fields']['attempt_token']));
            return $value !== null && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) ? $value : null;
        }

        /**
         * Resolves an opaque browser attempt UUID to a distinct CRM idempotency
         * UUID. add_option is an INSERT with WordPress' unique option_name
         * constraint, so simultaneous requests share one durable mapping.
         *
         * @return string|null
         */
        private function submission_id($attempt_token)
        {
            if (!function_exists('get_option') || !function_exists('add_option')) {
                return null;
            }

            $option_name = $this->attempt_option_name($attempt_token);
            $stored = get_option($option_name, false);
            if ($this->is_uuid($stored)) {
                return $stored;
            }

            $submission_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4();
            if (add_option($option_name, $submission_id, '', 'no')) {
                return $submission_id;
            }

            $stored = get_option($option_name, false);
            return $this->is_uuid($stored) ? $stored : null;
        }

        /**
         * Atomically replaces the stored CRM UUID only when it still matches the
         * response that requested rotation. A second concurrent response cannot
         * overwrite the first rotation.
         */
        private function rotate_submission_id($attempt_token, $previous_id)
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
                return false;
            }

            $option_name = $this->attempt_option_name($attempt_token);
            $next_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4();
            $sql = $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $next_id,
                $option_name,
                $previous_id
            );
            $updated = $wpdb->query($sql);
            if ($updated && function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
            }

            return $updated === 1;
        }

        private function attempt_option_name($attempt_token)
        {
            return 'unlkr_acq_attempt_' . substr(hash('sha256', $attempt_token), 0, 40);
        }

        private function is_uuid($value)
        {
            return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
        }

        /** @return array<string, mixed> */
        public function deliver($config, $payload, $submission_id)
        {
            $body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
            // C1b rejects bodies larger than 32 KiB. Fail locally before any
            // transmission so the user receives the same neutral correction
            // path instead of a misleading partial delivery.
            if (!is_string($body) || strlen($body) > 32768) {
                return array('ok' => false, 'status' => 422, 'attempts' => 0);
            }

            $attempt = 0;
            do {
                $attempt++;
                $response = wp_safe_remote_post($config['url'], array(
                    'timeout' => 3,
                    'redirection' => 0,
                    'sslverify' => true,
                    'reject_unsafe_urls' => true,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $config['token'],
                        'Idempotency-Key' => $submission_id,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ),
                    'body' => $body,
                ));
                $status = $this->response_status($response);
                $retry = $attempt === 1 && ($status === 0 || $status === 429 || $status >= 500);
            } while ($retry);

            return array('ok' => $status === 200 || $status === 201, 'status' => $status, 'attempts' => $attempt);
        }

        /** @param mixed $response */
        private function response_status($response)
        {
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                return 0;
            }
            if (function_exists('wp_remote_retrieve_response_code')) {
                return (int) wp_remote_retrieve_response_code($response);
            }

            return is_array($response) && isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
        }

        private function uuid4()
        {
            $bytes = random_bytes(16);
            $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
            $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
            $hex = bin2hex($bytes);
            return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        }
    }

    new Unlkr_Acquisition_Relay();
}
