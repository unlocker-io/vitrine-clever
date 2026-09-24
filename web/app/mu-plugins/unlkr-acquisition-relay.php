<?php
/**
 * Plugin Name: Unlocker acquisition relay
 * Description: Relays one explicitly configured MetForm and produces consented acquisition continuity.
 * Version: 1.2.0
 *
 * This relay deliberately has no WordPress admin screen.  It is disabled until
 * its complete server-side configuration is present, and never stores form
 * values, credentials, or advertising identifiers locally.
 */

if (!class_exists('Unlkr_Acquisition_Relay')) {
    final class Unlkr_Acquisition_Relay
    {
        const NEUTRAL_ERROR = 'Votre demande a bien été reçue, mais nous ne pouvons pas la transmettre pour le moment. Veuillez réessayer.';
        const ATTEMPT_PREFIX = 'unlkr_acq_attempt_';
        const PURGE_LOCK_OPTION = 'unlkr_acq_attempt_purge_lock';
        const PURGE_CURSOR_OPTION = 'unlkr_acq_attempt_purge_cursor';
        const PURGE_HOOK = 'unlkr_acquisition_relay_purge';
        const PURGE_CONTINUATION_HOOK = 'unlkr_acquisition_relay_purge_continuation';
        const PURGE_BATCH = 500;
        const PURGE_LOCK_TTL = 300;
        const PURGE_CONTINUATION_DELAY = 60;

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
                add_action('wp_enqueue_scripts', array($this, 'enqueue_attempt_field_bootstrap'));
                add_action('wp_enqueue_scripts', array($this, 'enqueue_continuity_producer'));
                add_action('wp_enqueue_scripts', array($this, 'enqueue_touches_producer'));
                add_action('wp_head', array($this, 'render_attempt_bootstrap_configuration'), 100);
                add_action('wp_head', array($this, 'render_continuity_configuration'), 100);
                add_action('wp_head', array($this, 'render_touches_configuration'), 100);
                add_action('init', array($this, 'manage_purge_schedule'));
                add_action(self::PURGE_HOOK, array($this, 'run_scheduled_purge'));
                add_action(self::PURGE_CONTINUATION_HOOK, array($this, 'run_scheduled_purge'));
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

            $submission_id = $this->submission_id($attempt_token, $config['retention_seconds']);
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
                $this->rotate_submission_id($attempt_token, $submission_id, $config['retention_seconds']);
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
            $retention_days = getenv('CRM_ACQUISITION_ATTEMPT_RETENTION_DAYS');
            $retention_days = $retention_days === false || $retention_days === '' ? 30 : (ctype_digit((string) $retention_days) ? (int) $retention_days : 0);
            $retention_is_valid = $retention_days >= 1 && $retention_days <= 90;

            $valid = $url_is_allowed
                && trim((string) getenv('CRM_ACQUISITION_SERVICE_TOKEN')) !== ''
                && ctype_digit((string) getenv('CRM_ACQUISITION_METFORM_FORM_ID'))
                && (int) getenv('CRM_ACQUISITION_METFORM_FORM_ID') > 0
                && trim((string) getenv('CRM_ACQUISITION_LANDING_KEY')) !== ''
                && trim((string) getenv('CRM_ACQUISITION_PRIVACY_NOTICE_VERSION')) !== ''
                // Deliberate deployment gate: this plugin does not infer CookieYes
                // state. An operator confirms the tested consent-field mapping.
                && getenv('CRM_ACQUISITION_CONSENT_GATE_CONFIRMED') === '1'
                && $retention_is_valid;
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
                'retention_seconds' => $retention_days * 86400,
                'fields' => $fields,
            );
        }

        /** External same-origin asset; no inline script is required by CSP. */
        public function enqueue_attempt_field_bootstrap()
        {
            $config = $this->configuration();
            if (!$config['enabled'] || !$config['valid'] || !function_exists('wp_enqueue_script') || !function_exists('plugin_dir_url')) {
                return;
            }

            wp_enqueue_script('unlkr-acquisition-relay', plugin_dir_url(__FILE__) . 'unlkr-acquisition-relay.js', array(), '1.1.0', true);
        }

        /** Emits only public form metadata used by the external bootstrap asset. */
        public function render_attempt_bootstrap_configuration()
        {
            $config = $this->configuration();
            if (!$config['enabled'] || !$config['valid']) {
                return;
            }
            $escape = function_exists('esc_attr') ? 'esc_attr' : 'htmlspecialchars';
            echo '<meta name="unlkr-acquisition-relay" data-form-id="' . $escape((string) $config['form_id']) . '" data-attempt-field="' . $escape($config['fields']['attempt_token']) . '" data-attempt-retention-seconds="' . $escape((string) $config['retention_seconds']) . '">';
        }

        /**
         * Public, secret-free C2d configuration. Invalid or incomplete values
         * keep the producer disabled and prevent its asset from being loaded.
         *
         * @return array<string, mixed>
         */
        public function continuity_configuration()
        {
            $enabled = filter_var(getenv('CRM_ACQUISITION_CONTINUITY_ENABLED'), FILTER_VALIDATE_BOOLEAN);
            $url = trim((string) getenv('CRM_ACQUISITION_WEB_PREFERENCES_URL'));
            $allowed_hosts = array_filter(array_map('trim', explode(',', (string) getenv('CRM_ACQUISITION_WEB_ALLOWED_HOSTS'))));
            $parts = parse_url($url);
            $url_is_allowed = is_array($parts)
                && isset($parts['scheme'], $parts['host'], $parts['path'])
                && strtolower((string) $parts['scheme']) === 'https'
                && $parts['path'] === '/acquisition-web/preferences'
                && (!isset($parts['port']) || (int) $parts['port'] === 443)
                && in_array(strtolower((string) $parts['host']), array_map('strtolower', $allowed_hosts), true)
                && !isset($parts['user']) && !isset($parts['pass'])
                && !isset($parts['query']) && !isset($parts['fragment']);

            $origins = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) getenv('CRM_ACQUISITION_CONTINUITY_APP_ORIGINS'))))));
            $origins_are_valid = count($origins) > 0;
            foreach ($origins as $origin) {
                $origin_parts = parse_url($origin);
                $canonical = is_array($origin_parts)
                    && isset($origin_parts['scheme'], $origin_parts['host'])
                    && strtolower((string) $origin_parts['scheme']) === 'https'
                    && !isset($origin_parts['path']) && !isset($origin_parts['query']) && !isset($origin_parts['fragment'])
                    && !isset($origin_parts['user']) && !isset($origin_parts['pass'])
                    && (!isset($origin_parts['port']) || (int) $origin_parts['port'] > 0);
                $origins_are_valid = $origins_are_valid && $canonical;
            }

            $ttl = $this->bounded_integer_environment('CRM_ACQUISITION_CONTINUITY_TTL_SECONDS', 300, 60, 1800);
            $timeout = $this->bounded_integer_environment('CRM_ACQUISITION_CONTINUITY_TIMEOUT_MS', 3000, 500, 10000);
            $retries = $this->bounded_integer_environment('CRM_ACQUISITION_CONTINUITY_RETRIES', 1, 0, 2);
            $site_key = trim((string) getenv('CRM_ACQUISITION_SITE_KEY'));
            $notice_version = trim((string) getenv('CRM_ACQUISITION_CONTINUITY_NOTICE_VERSION'));

            return array(
                'enabled' => $enabled,
                'valid' => $url_is_allowed
                    && $origins_are_valid
                    && $ttl !== null
                    && $timeout !== null
                    && $retries !== null
                    && $site_key !== '' && strlen($site_key) <= 64
                    && $notice_version !== '' && strlen($notice_version) <= 64,
                'url' => $url,
                'site_key' => $site_key,
                'notice_version' => $notice_version,
                'app_origins' => $origins,
                'ttl_seconds' => $ttl,
                'timeout_ms' => $timeout,
                'retries' => $retries,
            );
        }

        /** External same-origin asset; it is independent of the MetForm relay. */
        public function enqueue_continuity_producer()
        {
            $config = $this->continuity_configuration();
            if (!$config['enabled'] || !$config['valid'] || !function_exists('wp_enqueue_script') || !function_exists('plugin_dir_url')) {
                return;
            }

            // The configuration meta is rendered in wp_head. Loading this asset
            // in the footer guarantees that it can read that meta; the script
            // recovers CookieYes' current state through getCkyConsent().
            wp_enqueue_script('unlkr-acquisition-continuity', plugin_dir_url(__FILE__) . 'unlkr-acquisition-continuity.js', array(), '1.0.1', true);
        }

        /** Emits no bearer, identity, URL, cookie value or personal data. */
        public function render_continuity_configuration()
        {
            $config = $this->continuity_configuration();
            if (!$config['enabled'] || !$config['valid']) {
                return;
            }
            $escape = function_exists('esc_attr') ? 'esc_attr' : 'htmlspecialchars';
            echo '<meta name="unlkr-acquisition-continuity" data-preferences-url="' . $escape($config['url'])
                . '" data-site-key="' . $escape($config['site_key'])
                . '" data-notice-version="' . $escape($config['notice_version'])
                . '" data-app-origins="' . $escape(implode(',', $config['app_origins']))
                . '" data-ttl-seconds="' . $escape((string) $config['ttl_seconds'])
                . '" data-timeout-ms="' . $escape((string) $config['timeout_ms'])
                . '" data-retries="' . $escape((string) $config['retries']) . '">';
        }

        /**
         * Public, secret-free C2c touches configuration. Invalid or incomplete
         * values keep the producer disabled and prevent its asset from being
         * loaded. Independent of continuity: it can be enabled on its own.
         *
         * @return array<string, mixed>
         */
        public function touches_configuration()
        {
            $enabled = filter_var(getenv('CRM_ACQUISITION_TOUCHES_ENABLED'), FILTER_VALIDATE_BOOLEAN);

            $preferences_url = trim((string) getenv('CRM_ACQUISITION_WEB_PREFERENCES_URL'));
            $allowed_hosts = array_filter(array_map('trim', explode(',', (string) getenv('CRM_ACQUISITION_WEB_ALLOWED_HOSTS'))));
            $preferences_parts = parse_url($preferences_url);
            $preferences_url_is_allowed = is_array($preferences_parts)
                && isset($preferences_parts['scheme'], $preferences_parts['host'], $preferences_parts['path'])
                && strtolower((string) $preferences_parts['scheme']) === 'https'
                && $preferences_parts['path'] === '/acquisition-web/preferences'
                && (!isset($preferences_parts['port']) || (int) $preferences_parts['port'] === 443)
                && in_array(strtolower((string) $preferences_parts['host']), array_map('strtolower', $allowed_hosts), true)
                && !isset($preferences_parts['user']) && !isset($preferences_parts['pass'])
                && !isset($preferences_parts['query']) && !isset($preferences_parts['fragment']);

            $touches_url = trim((string) getenv('CRM_ACQUISITION_WEB_TOUCHES_URL'));
            $touches_parts = parse_url($touches_url);
            $touches_url_is_allowed = is_array($touches_parts)
                && isset($touches_parts['scheme'], $touches_parts['host'], $touches_parts['path'])
                && strtolower((string) $touches_parts['scheme']) === 'https'
                && $touches_parts['path'] === '/acquisition-web/touches'
                && (!isset($touches_parts['port']) || (int) $touches_parts['port'] === 443)
                && in_array(strtolower((string) $touches_parts['host']), array_map('strtolower', $allowed_hosts), true)
                && !isset($touches_parts['user']) && !isset($touches_parts['pass'])
                && !isset($touches_parts['query']) && !isset($touches_parts['fragment']);

            $ttl = $this->bounded_integer_environment('CRM_ACQUISITION_TOUCHES_TTL_SECONDS', 300, 60, 1800);
            $timeout = $this->bounded_integer_environment('CRM_ACQUISITION_TOUCHES_TIMEOUT_MS', 3000, 500, 10000);
            $retries = $this->bounded_integer_environment('CRM_ACQUISITION_TOUCHES_RETRIES', 1, 0, 2);
            $site_key = trim((string) getenv('CRM_ACQUISITION_SITE_KEY'));
            $notice_version = trim((string) getenv('CRM_ACQUISITION_TOUCHES_NOTICE_VERSION'));
            $landing_key = trim((string) getenv('CRM_ACQUISITION_TOUCHES_LANDING_KEY'));

            return array(
                'enabled' => $enabled,
                'valid' => $preferences_url_is_allowed
                    && $touches_url_is_allowed
                    && $ttl !== null
                    && $timeout !== null
                    && $retries !== null
                    && $site_key !== '' && strlen($site_key) <= 64
                    && $notice_version !== '' && strlen($notice_version) <= 64
                    && $landing_key !== '' && strlen($landing_key) <= 64,
                'preferences_url' => $preferences_url,
                'touches_url' => $touches_url,
                'site_key' => $site_key,
                'notice_version' => $notice_version,
                'landing_key' => $landing_key,
                'ttl_seconds' => $ttl,
                'timeout_ms' => $timeout,
                'retries' => $retries,
            );
        }

        /** External same-origin asset; it is independent of continuity and the MetForm relay. */
        public function enqueue_touches_producer()
        {
            $config = $this->touches_configuration();
            if (!$config['enabled'] || !$config['valid'] || !function_exists('wp_enqueue_script') || !function_exists('plugin_dir_url')) {
                return;
            }

            wp_enqueue_script('unlkr-acquisition-touches', plugin_dir_url(__FILE__) . 'unlkr-acquisition-touches.js', array(), '1.0.0', true);
        }

        /** Emits no bearer, identity, URL, cookie value or personal data. */
        public function render_touches_configuration()
        {
            $config = $this->touches_configuration();
            if (!$config['enabled'] || !$config['valid']) {
                return;
            }
            $escape = function_exists('esc_attr') ? 'esc_attr' : 'htmlspecialchars';
            echo '<meta name="unlkr-acquisition-touches" data-preferences-url="' . $escape($config['preferences_url'])
                . '" data-touches-url="' . $escape($config['touches_url'])
                . '" data-site-key="' . $escape($config['site_key'])
                . '" data-notice-version="' . $escape($config['notice_version'])
                . '" data-landing-key="' . $escape($config['landing_key'])
                . '" data-ttl-seconds="' . $escape((string) $config['ttl_seconds'])
                . '" data-timeout-ms="' . $escape((string) $config['timeout_ms'])
                . '" data-retries="' . $escape((string) $config['retries']) . '">';
        }

        /** @return int|null */
        private function bounded_integer_environment($name, $default, $minimum, $maximum)
        {
            $raw = getenv($name);
            if ($raw === false || $raw === '') {
                return (int) $default;
            }
            if (!ctype_digit((string) $raw)) {
                return null;
            }
            $value = (int) $raw;
            return $value >= $minimum && $value <= $maximum ? $value : null;
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
         * UUID. A non-overwriting SQL insert plus WordPress' unique option_name
         * constraint makes simultaneous requests share one durable mapping.
         *
         * @return string|null
         */
        private function submission_id($attempt_token, $retention_seconds)
        {
            if (!function_exists('get_option')) {
                return null;
            }

            $option_name = $this->attempt_option_name($attempt_token);
            // A raw expired value is never deleted optimistically: that could
            // erase a fresh map inserted by a competing request. Replace it by
            // compare-and-swap, then always reread the winner.
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $raw_stored = get_option($option_name, false);
                $stored = $this->attempt_record($raw_stored);
                if ($stored !== null && $stored['expires_at'] > time()) {
                    return $stored['id'];
                }

                $submission_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4();
                $record = $this->encode_attempt_record($submission_id, $retention_seconds);
                if ($raw_stored === false) {
                    if ($this->insert_option_once($option_name, $record)) {
                        return $submission_id;
                    }
                } elseif ($this->compare_and_swap_option($option_name, $raw_stored, $record)) {
                    return $submission_id;
                }
            }

            $stored = $this->attempt_record(get_option($option_name, false));
            return $stored !== null && $stored['expires_at'] > time() ? $stored['id'] : null;
        }

        /**
         * Atomically replaces the stored CRM UUID only when it still matches the
         * response that requested rotation. A second concurrent response cannot
         * overwrite the first rotation.
         */
        private function rotate_submission_id($attempt_token, $previous_id, $retention_seconds)
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
                return false;
            }

            $option_name = $this->attempt_option_name($attempt_token);
            $current = get_option($option_name, false);
            $record = $this->attempt_record($current);
            if ($record === null || $record['id'] !== $previous_id || $record['expires_at'] <= time()) {
                return false;
            }
            $next_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4();
            $next_record = $this->encode_attempt_record($next_id, $retention_seconds);
            return $this->compare_and_swap_option($option_name, $current, $next_record);
        }

        private function compare_and_swap_option($option_name, $expected_value, $next_value)
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
                return false;
            }
            $sql = $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
                $next_value,
                $option_name,
                $expected_value
            );
            $updated = $wpdb->query($sql);
            // The failed-CAS path is precisely where another PHP worker may have
            // written the winner. Invalidate before its reread as well.
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
            }

            return $updated === 1;
        }

        /**
         * WordPress 6.9 add_option() deliberately UPSERTs duplicate keys; it is
         * unsuitable for idempotency/locks. INSERT IGNORE returns 1 only to the
         * worker that inserted this exact option, on both MySQL and MariaDB.
         */
        private function insert_option_once($option_name, $value)
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
                return false;
            }
            $sql = $wpdb->prepare(
                "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'off')",
                $option_name,
                $value
            );
            $inserted = $wpdb->query($sql) === 1;
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
                wp_cache_delete('notoptions', 'options');
            }

            return $inserted;
        }

        private function attempt_option_name($attempt_token)
        {
            return self::ATTEMPT_PREFIX . substr(hash('sha256', $attempt_token), 0, 40);
        }

        /** @return array{id:string,created_at:int,expires_at:int}|null */
        private function attempt_record($value)
        {
            if (!is_string($value)) {
                return null;
            }
            $decoded = json_decode($value, true);
            if (!is_array($decoded) || !$this->is_uuid(isset($decoded['id']) ? $decoded['id'] : null)
                || !isset($decoded['created_at'], $decoded['expires_at']) || !is_int($decoded['created_at']) || !is_int($decoded['expires_at'])
                || $decoded['expires_at'] <= $decoded['created_at']) {
                return null;
            }

            return array('id' => $decoded['id'], 'created_at' => $decoded['created_at'], 'expires_at' => $decoded['expires_at']);
        }

        private function encode_attempt_record($submission_id, $retention_seconds)
        {
            $created_at = time();
            $record = array('id' => $submission_id, 'created_at' => $created_at, 'expires_at' => $created_at + (int) $retention_seconds);
            return function_exists('wp_json_encode') ? wp_json_encode($record) : json_encode($record);
        }

        /** Keeps the daily maintenance event strictly behind the relay/privacy gate. */
        public function manage_purge_schedule()
        {
            $config = $this->configuration();
            if (!$config['enabled'] || !$config['valid']) {
                if (function_exists('wp_unschedule_hook')) {
                    wp_unschedule_hook(self::PURGE_HOOK);
                    wp_unschedule_hook(self::PURGE_CONTINUATION_HOOK);
                }
                return;
            }
            if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled(self::PURGE_HOOK)) {
                wp_schedule_event(time() + 60, 'daily', self::PURGE_HOOK);
            }
        }

        /** Called by WP-Cron only; it never sends CRM data. */
        public function run_scheduled_purge()
        {
            $config = $this->configuration();
            if ($config['enabled'] && $config['valid']) {
                $this->purge_expired_attempts();
            }
        }

        /** A bounded PHP parser avoids database JSON-function dependencies. */
        private function purge_expired_attempts()
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'get_results')) {
                return;
            }
            $now = time();
            $lock_token = $now . ':' . (function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : $this->uuid4());
            $current_lock = get_option(self::PURGE_LOCK_OPTION, false);
            if ($current_lock === false) {
                $acquired = $this->insert_option_once(self::PURGE_LOCK_OPTION, $lock_token);
            } else {
                $lock_created_at = (int) strtok((string) $current_lock, ':');
                $acquired = ($current_lock === '0' || $lock_created_at <= 0 || $lock_created_at + self::PURGE_LOCK_TTL < $now)
                    && $this->compare_and_swap_option(self::PURGE_LOCK_OPTION, $current_lock, $lock_token);
            }
            if (!$acquired) {
                return;
            }

            try {
                $cursor_raw = get_option(self::PURGE_CURSOR_OPTION, false);
                if ($cursor_raw === false) {
                    $this->insert_option_once(self::PURGE_CURSOR_OPTION, '0');
                    $cursor_raw = get_option(self::PURGE_CURSOR_OPTION, false);
                }
                if (!is_scalar($cursor_raw)) {
                    return;
                }
                $cursor_raw = (string) $cursor_raw;
                if (!preg_match('/^\d+$/', $cursor_raw)) {
                    if (!$this->compare_and_swap_option(self::PURGE_CURSOR_OPTION, $cursor_raw, '0')) {
                        return;
                    }
                    $cursor_raw = '0';
                }
                $cursor = (int) $cursor_raw;
                $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like(self::ATTEMPT_PREFIX) . '%' : self::ATTEMPT_PREFIX . '%';
                $sql = $wpdb->prepare(
                    "SELECT option_id, option_name, option_value FROM `{$wpdb->options}` WHERE option_name LIKE %s AND option_name NOT IN (%s, %s) AND option_id > %d ORDER BY option_id ASC LIMIT %d",
                    $like,
                    self::PURGE_LOCK_OPTION,
                    self::PURGE_CURSOR_OPTION,
                    $cursor,
                    self::PURGE_BATCH
                );
                $rows = (array) $wpdb->get_results($sql);
                $last_option_id = $cursor;
                foreach ($rows as $row) {
                    if (!isset($row->option_id, $row->option_name, $row->option_value) || !is_numeric($row->option_id)) {
                        continue;
                    }
                    $last_option_id = max($last_option_id, (int) $row->option_id);
                    $record = $this->attempt_record($row->option_value);
                    if ($record === null || $record['expires_at'] <= $now) {
                        $this->delete_option_if_value($row->option_name, $row->option_value);
                    }
                }

                if (count($rows) === self::PURGE_BATCH && $last_option_id > $cursor) {
                    if ($this->compare_and_swap_option(self::PURGE_CURSOR_OPTION, $cursor_raw, (string) $last_option_id)) {
                        $this->schedule_purge_continuation();
                    }
                } else {
                    // A partial pass has reached the current tail. Restarting at
                    // zero on the next daily pass makes old retained mappings
                    // eligible without starving newer expired or invalid rows.
                    $this->compare_and_swap_option(self::PURGE_CURSOR_OPTION, $cursor_raw, '0');
                }
            } finally {
                $this->compare_and_swap_option(self::PURGE_LOCK_OPTION, $lock_token, '0');
            }
        }

        /** Continue a full bounded pass without turning the daily task into an unbounded run. */
        private function schedule_purge_continuation()
        {
            if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event') && !wp_next_scheduled(self::PURGE_CONTINUATION_HOOK)) {
                wp_schedule_single_event(time() + self::PURGE_CONTINUATION_DELAY, self::PURGE_CONTINUATION_HOOK);
            }
        }

        private function delete_option_if_value($option_name, $expected_value)
        {
            global $wpdb;
            if (!isset($wpdb) || !isset($wpdb->options) || !method_exists($wpdb, 'prepare') || !method_exists($wpdb, 'query')) {
                return false;
            }
            $sql = $wpdb->prepare("DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", $option_name, $expected_value);
            $deleted = $wpdb->query($sql) === 1;
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete($option_name, 'options');
            }

            return $deleted;
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
