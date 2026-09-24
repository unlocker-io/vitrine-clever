<?php

declare(strict_types=1);

/**
 * Integration tests for the two touches producer fixes wired in
 * web/app/mu-plugins/unlocker-landings/inc/assets.php:
 *
 *  1. has_allowed_src() must let the unlkr-acquisition-* consent-relay
 *     assets survive dequeue_foreign_assets()'s foreign-script sweep on all
 *     three landing templates -- previously it silently dequeued
 *     unlkr-acquisition-touches.js (and the relay/continuity producers)
 *     because none of them matched the old closed allow-list.
 *  2. The unlkr_acquisition_touches_landing_key filter (registered here,
 *     consumed by Unlkr_Acquisition_Relay::touches_configuration()) must
 *     replace the site-wide landing key with 'mountain_split' /
 *     'mountain_delegation' on the two ad-facing mountain landings only,
 *     leaving every other page (including /demarrer/) on the site-wide
 *     CRM_ACQUISITION_TOUCHES_LANDING_KEY value.
 *
 * This file exercises unlocker-landings and unlkr-acquisition-relay
 * together: unlike tests/unlkr-acquisition-relay-test.php (which stubs
 * add_filter() as a no-op and never registers a real filter), the
 * add_filter()/apply_filters() stubs below form a real registry, so the
 * filter unlocker-landings registers is the one actually invoked by the
 * relay.
 *
 * Run with:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/unlocker-landings-touches-test.php
 */

$GLOBALS['test_filters'] = array();
function add_action() {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['test_filters'][$hook][] = $callback; }
function apply_filters($hook, $value) { foreach (($GLOBALS['test_filters'][$hook] ?? array()) as $callback) { $value = $callback($value); } return $value; }

$GLOBALS['test_page_template_slug'] = null;
function is_page() { return $GLOBALS['test_page_template_slug'] !== null; }
function get_queried_object_id() { return 1; }
function get_page_template_slug($id) { return $GLOBALS['test_page_template_slug']; }

class WP_Dependencies { public $queue = array(); public $registered = array(); }
$GLOBALS['test_wp_styles'] = new WP_Dependencies();
$GLOBALS['test_wp_scripts'] = new WP_Dependencies();
function wp_styles() { return $GLOBALS['test_wp_styles']; }
function wp_scripts() { return $GLOBALS['test_wp_scripts']; }
function wp_dequeue_style($handle) { $GLOBALS['test_wp_styles']->queue = array_values(array_diff($GLOBALS['test_wp_styles']->queue, array($handle))); }
function wp_dequeue_script($handle) { $GLOBALS['test_wp_scripts']->queue = array_values(array_diff($GLOBALS['test_wp_scripts']->queue, array($handle))); }

// Nothing else is referenced at the top level of constants.php / assets.php
// / unlkr-acquisition-relay.php (only inside function/method bodies this
// file never calls), so no further stubs are required for the three
// require()s below not to fatal.

require dirname(__DIR__) . '/web/app/mu-plugins/unlocker-landings/inc/constants.php';
require dirname(__DIR__) . '/web/app/mu-plugins/unlocker-landings/inc/assets.php';
require dirname(__DIR__) . '/web/app/mu-plugins/unlkr-acquisition-relay.php';

use function Unlocker\Landings\has_allowed_src;
use function Unlocker\Landings\dequeue_foreign_assets;
use const Unlocker\Landings\TEMPLATE_SPLIT;
use const Unlocker\Landings\TEMPLATE_DELEGATION;
use const Unlocker\Landings\TEMPLATE_DEMARRER;

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

function registry(array $items): WP_Dependencies
{
    $registry = new WP_Dependencies();

    foreach ($items as $handle => $src) {
        $registry->registered[$handle] = (object) array('src' => $src);
        $registry->queue[] = $handle;
    }

    return $registry;
}

// -- has_allowed_src(): allow-list needle coverage ---------------------------

$scripts = registry(array(
    'unlkr-acquisition-touches' => 'https://public.example.test/app/mu-plugins/unlkr-acquisition-touches.js',
    'unlkr-acquisition-relay' => 'https://public.example.test/app/mu-plugins/unlkr-acquisition-relay.js',
    'some-theme-script' => 'https://public.example.test/app/themes/foo/script.js',
));
$styles = registry(array(
    'cookie-law-info' => 'https://public.example.test/app/plugins/cookie-law-info/style.css',
));

check(
    'unlkr-acquisition-touches.js is allow-listed',
    has_allowed_src($scripts, 'unlkr-acquisition-touches') === true
);
check(
    'unlkr-acquisition-relay.js is allow-listed',
    has_allowed_src($scripts, 'unlkr-acquisition-relay') === true
);
check(
    'a generic theme script is still NOT allow-listed (no regression on the sweep)',
    has_allowed_src($scripts, 'some-theme-script') === false
);
check(
    'the pre-existing cookie-law-info allow-list entry is unaffected',
    has_allowed_src($styles, 'cookie-law-info') === true
);

// -- dequeue_foreign_assets(): the four acquisition/own assets survive the --
// -- sweep on all three landing templates; the generic sweep still works ----

function run_dequeue_scenario(?string $templateSlug, string $label): void
{
    $GLOBALS['test_page_template_slug'] = $templateSlug;
    $GLOBALS['test_wp_styles'] = new WP_Dependencies();
    $GLOBALS['test_wp_scripts'] = registry(array(
        'unlocker-landings-flow' => 'https://public.example.test/app/mu-plugins/unlocker-landings/assets/flow.js',
        'unlkr-acquisition-touches' => 'https://public.example.test/app/mu-plugins/unlkr-acquisition-touches.js',
        'unlkr-acquisition-relay' => 'https://public.example.test/app/mu-plugins/unlkr-acquisition-relay.js',
        'unlkr-acquisition-continuity' => 'https://public.example.test/app/mu-plugins/unlkr-acquisition-continuity.js',
        'some-theme-script' => 'https://public.example.test/app/themes/foo/script.js',
    ));

    dequeue_foreign_assets();

    $queue = $GLOBALS['test_wp_scripts']->queue;

    foreach (array('unlocker-landings-flow', 'unlkr-acquisition-touches', 'unlkr-acquisition-relay', 'unlkr-acquisition-continuity') as $handle) {
        check("{$label}: {$handle} stays enqueued", in_array($handle, $queue, true));
    }
    check("{$label}: some-theme-script is still dequeued (generic sweep unaffected)", !in_array('some-theme-script', $queue, true));
}

run_dequeue_scenario(TEMPLATE_SPLIT, 'TEMPLATE_SPLIT');
run_dequeue_scenario(TEMPLATE_DELEGATION, 'TEMPLATE_DELEGATION');
run_dequeue_scenario(TEMPLATE_DEMARRER, 'TEMPLATE_DEMARRER');

// -- unlkr_acquisition_touches_landing_key: per-landing override -----------

function env_reset(): void
{
    foreach (array(
        'CRM_ACQUISITION_TOUCHES_ENABLED',
        'CRM_ACQUISITION_WEB_PREFERENCES_URL',
        'CRM_ACQUISITION_WEB_ALLOWED_HOSTS',
        'CRM_ACQUISITION_SITE_KEY',
        'CRM_ACQUISITION_WEB_TOUCHES_URL',
        'CRM_ACQUISITION_TOUCHES_NOTICE_VERSION',
        'CRM_ACQUISITION_TOUCHES_LANDING_KEY',
        'CRM_ACQUISITION_TOUCHES_TTL_SECONDS',
        'CRM_ACQUISITION_TOUCHES_TIMEOUT_MS',
        'CRM_ACQUISITION_TOUCHES_RETRIES',
    ) as $key) {
        putenv($key);
    }
}

function configure_touches(): void
{
    env_reset();
    foreach (array(
        'CRM_ACQUISITION_WEB_PREFERENCES_URL=https://crm.example.test/acquisition-web/preferences',
        'CRM_ACQUISITION_WEB_ALLOWED_HOSTS=crm.example.test',
        'CRM_ACQUISITION_SITE_KEY=unlocker-web',
        'CRM_ACQUISITION_TOUCHES_ENABLED=1',
        'CRM_ACQUISITION_WEB_TOUCHES_URL=https://crm.example.test/acquisition-web/touches',
        'CRM_ACQUISITION_TOUCHES_NOTICE_VERSION=2026-09',
        'CRM_ACQUISITION_TOUCHES_LANDING_KEY=unlocker_web',
    ) as $entry) {
        putenv($entry);
    }
}

configure_touches();
$relay = new Unlkr_Acquisition_Relay();

$scenarios = array(
    array(TEMPLATE_SPLIT, 'mountain_split', 'TEMPLATE_SPLIT'),
    array(TEMPLATE_DELEGATION, 'mountain_delegation', 'TEMPLATE_DELEGATION'),
    array(TEMPLATE_DEMARRER, 'unlocker_web', 'TEMPLATE_DEMARRER'),
    array(null, 'unlocker_web', 'no landing template (rest of the site)'),
);

foreach ($scenarios as [$templateSlug, $expectedLandingKey, $label]) {
    $GLOBALS['test_page_template_slug'] = $templateSlug;
    $config = $relay->touches_configuration();

    check("landing_key on {$label} is '{$expectedLandingKey}'", $config['landing_key'] === $expectedLandingKey);
    check("touches configuration stays valid on {$label}", $config['valid'] === true);
}

// --------------------------------------------------------------------------

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
