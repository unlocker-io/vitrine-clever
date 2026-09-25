<?php

declare(strict_types=1);

/**
 * Tests for web/app/mu-plugins/unlocker-landings/inc/ads-landings.php: the
 * three Google Ads landing pages (11814, 12231, 12723) whose CTA buttons and
 * (on two of them) Brevo calendar iframe get rerouted to /demarrer/ by
 * filtering the RENDERED Elementor HTML, never touching the DB content.
 *
 * Follows the style of tests/unlocker-landings-touches-test.php: plain PHP
 * script, no phpunit, a real add_filter()/apply_filters() registry (not a
 * no-op stub) so filter_ad_landing_widget_content() is exercised through the
 * actual `elementor/widget/render_content` filter for the integration cases.
 *
 * Run with:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/unlocker-landings-ads-landings-test.php
 */

$GLOBALS['test_filters'] = array();
function add_action() {}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['test_filters'][$hook][] = $callback; }
function apply_filters($hook, $value, ...$args) { foreach (($GLOBALS['test_filters'][$hook] ?? array()) as $callback) { $value = $callback($value, ...$args); } return $value; }

// $GLOBALS['test_current_page_id'] === null means "the current request is
// not a page at all" (is_page() === false regardless of argument); any other
// value is the id WordPress would report for get_queried_object_id(), and
// is_page($id) mirrors WP's own behaviour of matching that id.
$GLOBALS['test_current_page_id'] = null;
function get_queried_object_id() { return $GLOBALS['test_current_page_id'] ?? 0; }
function is_page($id = '') {
    if ($GLOBALS['test_current_page_id'] === null) {
        return false;
    }
    if ($id === '') {
        return true;
    }
    return $GLOBALS['test_current_page_id'] === $id;
}

function home_url(string $path = ''): string { return 'https://unlocker.io' . $path; }
function content_url(string $path = ''): string { return 'https://unlocker.io/app/' . $path; }

$GLOBALS['test_enqueued_scripts'] = array();
function wp_enqueue_script($handle, $src, $deps = array(), $ver = false, $in_footer = false) {
    $GLOBALS['test_enqueued_scripts'][$handle] = array('src' => $src, 'deps' => $deps, 'ver' => $ver, 'in_footer' => $in_footer);
}

require dirname(__DIR__) . '/web/app/mu-plugins/unlocker-landings/inc/constants.php';
require dirname(__DIR__) . '/web/app/mu-plugins/unlocker-landings/inc/ads-landings.php';

use function Unlocker\Landings\ads_landing_rewrite_button_href;
use function Unlocker\Landings\ads_landing_calendar_button_html;
use function Unlocker\Landings\filter_ad_landing_widget_content;
use function Unlocker\Landings\enqueue_ad_landing_query_script;
use const Unlocker\Landings\AD_LANDING_CTA_MARKER;

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

class TestAdLandingWidget
{
    private string $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function get_id(): string
    {
        return $this->id;
    }
}

// -- ads_landing_rewrite_button_href(): real fixtures ------------------------
// Verbatim fragments from the cartography (curl'd real production HTML).

$page11814Cta13932f9c = <<<'HTML'
<div class="elementor-button-wrapper">
    <a class="elementor-button elementor-button-link elementor-size-sm" href="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit" target="_blank">
        <span class="elementor-button-content-wrapper">
            <span class="elementor-button-text">Découvrir la délégation de carte G</span>
        </span>
    </a>
</div>
HTML;

$page12723Cta7038b1ec = <<<'HTML'
<div class="elementor-button-wrapper">
    <a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/register" target="_blank">
        <span class="elementor-button-content-wrapper">
            <span class="elementor-button-text">M'inscrire sur Unlocker</span>
        </span>
    </a>
</div>
HTML;

$newHrefDelegation = 'https://unlocker.io/demarrer/?offre=delegation&parcours=demo';
$newHrefCarteT = 'https://unlocker.io/demarrer/?offre=carte-t&parcours=demo';

$rewritten13932f9c = ads_landing_rewrite_button_href($page11814Cta13932f9c, $newHrefDelegation, '13932f9c');
check(
    '13932f9c: href changed to the expected /demarrer/ value',
    strpos($rewritten13932f9c, 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') !== false
);
check(
    '13932f9c: old Brevo href is gone',
    strpos($rewritten13932f9c, 'meet.brevo.com') === false
);
check(
    '13932f9c: marker attribute added to the <a> tag',
    (bool) preg_match('/<a\b[^>]*\bdata-ul-ads-cta="1"[^>]*>/', $rewritten13932f9c)
);
check(
    '13932f9c: label text is byte-identical',
    strpos($rewritten13932f9c, 'Découvrir la délégation de carte G') !== false
);
check(
    '13932f9c: target="_blank" preserved',
    strpos($rewritten13932f9c, 'target="_blank"') !== false
);
check(
    '13932f9c: elementor-button classes preserved verbatim',
    strpos($rewritten13932f9c, 'class="elementor-button elementor-button-link elementor-size-sm"') !== false
);
// Diff: only the href value and the marker attribute should differ from the input.
$expectedAfterDiff = str_replace(
    'href="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit" target="_blank"',
    'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '" target="_blank" ' . AD_LANDING_CTA_MARKER . '="1"',
    $page11814Cta13932f9c
);
check(
    '13932f9c: nothing else in the string changed (href + marker only)',
    $rewritten13932f9c === $expectedAfterDiff
);

$rewritten7038b1ec = ads_landing_rewrite_button_href($page12723Cta7038b1ec, $newHrefCarteT, '7038b1ec');
check(
    '7038b1ec: href changed to the expected /demarrer/ value',
    strpos($rewritten7038b1ec, 'href="' . htmlspecialchars($newHrefCarteT, ENT_QUOTES) . '"') !== false
);
check(
    '7038b1ec: old register href is gone',
    strpos($rewritten7038b1ec, 'app.unlocker.io/register') === false
);
check(
    '7038b1ec: marker attribute added to the <a> tag',
    (bool) preg_match('/<a\b[^>]*\bdata-ul-ads-cta="1"[^>]*>/', $rewritten7038b1ec)
);
check(
    "7038b1ec: label text is byte-identical (straight apostrophe as in the DB)",
    strpos($rewritten7038b1ec, "M'inscrire sur Unlocker") !== false
);
$expectedAfterDiff7038b1ec = str_replace(
    'href="https://app.unlocker.io/register" target="_blank"',
    'href="' . htmlspecialchars($newHrefCarteT, ENT_QUOTES) . '" target="_blank" ' . AD_LANDING_CTA_MARKER . '="1"',
    $page12723Cta7038b1ec
);
check(
    '7038b1ec: nothing else in the string changed (href + marker only)',
    $rewritten7038b1ec === $expectedAfterDiff7038b1ec
);

// -- Defensive case: no elementor-button class anywhere ----------------------

$unrelatedWidgetContent = '<div class="elementor-shortcode">[formidable id=3]</div>';
$unchanged = ads_landing_rewrite_button_href($unrelatedWidgetContent, $newHrefDelegation, '2bb6abe');
check(
    'content without an elementor-button class is returned UNCHANGED, byte-for-byte',
    $unchanged === $unrelatedWidgetContent
);

// -- ads_landing_calendar_button_html() --------------------------------------

$calendarHtml = ads_landing_calendar_button_html($newHrefDelegation);
check('calendar button: has elementor-button-wrapper', strpos($calendarHtml, 'elementor-button-wrapper') !== false);
check(
    'calendar button: has the three elementor button classes',
    strpos($calendarHtml, 'class="elementor-button elementor-button-link elementor-size-sm"') !== false
);
check('calendar button: opens in a new tab', strpos($calendarHtml, 'target="_blank"') !== false);
check(
    'calendar button: carries the marker attribute',
    strpos($calendarHtml, AD_LANDING_CTA_MARKER . '="1"') !== false
);
check('calendar button: label is "Réserver ma démo"', strpos($calendarHtml, 'Réserver ma démo') !== false);
check(
    'calendar button: href equals the given URL',
    strpos($calendarHtml, 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') !== false
);

// -- Integration: filter_ad_landing_widget_content() via the real filter ----

$GLOBALS['test_current_page_id'] = 11814;

$ctaResult = apply_filters('elementor/widget/render_content', $page11814Cta13932f9c, new TestAdLandingWidget('13932f9c'));
check(
    'page 11814, widget 13932f9c (a CTA): rewritten to .../demarrer/?offre=delegation&parcours=demo',
    strpos($ctaResult, 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') !== false
);

$calendarWidgetInput = '<div class="elementor-shortcode"><iframe frameborder="0" width="100%" height="720" src="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit"></iframe></div>';
$calendarResult = apply_filters('elementor/widget/render_content', $calendarWidgetInput, new TestAdLandingWidget('10ffe685'));
check(
    'page 11814, widget 10ffe685 (the calendar): content becomes the button HTML pointing at the same url',
    strpos($calendarResult, 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') !== false
    && strpos($calendarResult, '<iframe') === false
);

$headerConnexionHtml = '<a href="https://app.unlocker.io/login">Connexion</a>';
$headerResult = apply_filters('elementor/widget/render_content', $headerConnexionHtml, new TestAdLandingWidget('99a471c'));
check(
    'header Connexion button on an ad landing page is never touched',
    $headerResult === $headerConnexionHtml
);

$GLOBALS['test_current_page_id'] = 12723;

$carteTResult = apply_filters('elementor/widget/render_content', $page12723Cta7038b1ec, new TestAdLandingWidget('7038b1ec'));
check(
    'page 12723 (single-CTA page), widget 7038b1ec: rewritten to .../demarrer/?offre=carte-t&parcours=demo',
    strpos($carteTResult, 'href="' . htmlspecialchars($newHrefCarteT, ENT_QUOTES) . '"') !== false
);

$arbitraryWidgetHtml = '<div class="elementor-widget-container">some other widget</div>';
$arbitraryResult = apply_filters('elementor/widget/render_content', $arbitraryWidgetHtml, new TestAdLandingWidget('nonexistent-id'));
check(
    'page 12723: some other arbitrary widget id is unchanged (no calendar widget on this page)',
    $arbitraryResult === $arbitraryWidgetHtml
);

// -- Mutation-proof case: page-id restriction --------------------------------
// A widget id that matches a real CTA id, but on a page that is NOT one of
// the 3 ad landings. This must stay unchanged: it's exactly what would go
// red if the `isset(AD_LANDING_PAGES[$pageId])` guard were deleted, leaving
// only the id-scoped check.

$GLOBALS['test_current_page_id'] = 999;

$unrelatedPageResult = apply_filters('elementor/widget/render_content', $page11814Cta13932f9c, new TestAdLandingWidget('13932f9c'));
check(
    'a widget id that matches a CTA on an unrelated page is never rewritten',
    $unrelatedPageResult === $page11814Cta13932f9c
);

// -- Integration: enqueue_ad_landing_query_script() guard --------------------

$GLOBALS['test_current_page_id'] = 11814;
$GLOBALS['test_enqueued_scripts'] = array();
enqueue_ad_landing_query_script();
check(
    'on page 11814 (an ad landing), the query-propagation script IS enqueued',
    isset($GLOBALS['test_enqueued_scripts']['unlocker-ads-landing-query'])
);

$GLOBALS['test_current_page_id'] = 999;
$GLOBALS['test_enqueued_scripts'] = array();
enqueue_ad_landing_query_script();
check(
    'on an unrelated page, the query-propagation script is NOT enqueued',
    !isset($GLOBALS['test_enqueued_scripts']['unlocker-ads-landing-query'])
);

// --------------------------------------------------------------------------

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
