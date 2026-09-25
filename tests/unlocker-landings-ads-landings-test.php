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
$GLOBALS['test_actions'] = array();
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['test_actions'][$hook][] = $callback; }
function do_action($hook, ...$args) { foreach (($GLOBALS['test_actions'][$hook] ?? array()) as $callback) { $callback(...$args); } }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) { $GLOBALS['test_filters'][$hook][] = $callback; }
function apply_filters($hook, $value, ...$args) { foreach (($GLOBALS['test_filters'][$hook] ?? array()) as $callback) { $value = $callback($value, ...$args); } return $value; }

// ob_start() itself is PHP's real, built-in one (it can't be redeclared) --
// the tests below only ever assert on ob_get_level() around do_action() to
// prove template_redirect starts/doesn't start a buffer, and always clean up
// with ob_end_clean() so nothing leaks into this script's own STDOUT. The
// buffer CALLBACK's actual rewriting logic is tested directly through
// ads_landing_output_buffer_callback_for_current_request(), with no real
// output buffering involved.

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
use function Unlocker\Landings\ads_landing_locate_widget_inner_html;
use function Unlocker\Landings\ads_landing_rewrite_full_content;
use function Unlocker\Landings\ads_landing_output_buffer_callback_for_current_request;
use function Unlocker\Landings\filter_ad_landing_widget_content;
use function Unlocker\Landings\enqueue_ad_landing_query_script;
use function Unlocker\Landings\ad_landing_cta_overlap_fix_css;
use const Unlocker\Landings\AD_LANDING_CTA_MARKER;
use const Unlocker\Landings\AD_LANDING_PAGES;

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

// -- Cache-proof path: fixtures --------------------------------------------
// Reduced from the real HTML served by prod on 25/09 (curl'd, PII-free):
// header widgets 99a471c/41bc9be (shared, reused on every page) plus each
// landing's own CTA(s), and -- on 11814 -- the calendar widget already
// showing the CORRECT replacement, because it's the one part of the page
// the per-widget filter DOES reach on every request; only the CTA buttons
// are stale (still pointing at Brevo / app.unlocker.io/register), which is
// exactly the bug this cache-proof path exists to close.

$page11814CachedFixture = <<<'HTML'
<!doctype html>
<html lang="fr-FR">
<head><meta charset="UTF-8"><title>Délégation carte G conciergerie</title></head>
<body class="wp-singular page-template-default page page-id-11814 wp-custom-logo wp-embed-responsive wp-theme-hello-elementor jkit-color-scheme hello-elementor-default elementor-default elementor-kit-23 elementor-page elementor-page-11814">
<header>
<div class="elementor-element elementor-element-8e6ddc2 e-con-full elementor-hidden-tablet elementor-hidden-mobile e-flex e-con e-child" data-id="8e6ddc2" data-element_type="container" data-e-type="container">
<div class="elementor-element elementor-element-99a471c elementor-widget elementor-widget-button" data-id="99a471c" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/login">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">Connexion</span>
</span>
</a>
</div>
</div>
</div>
<div class="elementor-element elementor-element-41bc9be elementor-widget elementor-widget-button" data-id="41bc9be" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/register">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">S'inscrire</span>
</span>
</a>
</div>
</div>
</div>
</div>
</header>
<main>
<div class="elementor-element elementor-element-13932f9c elementor-mobile-align-left elementor-widget elementor-widget-button" data-id="13932f9c" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit" target="_blank">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">Découvrir la délégation de carte G</span>
</span>
</a>
</div>
</div>
</div>
<div class="elementor-element elementor-element-5901f037 elementor-mobile-align-center elementor-widget elementor-widget-button" data-id="5901f037" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit" target="_blank">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">Voir comment ça marche</span>
</span>
</a>
</div>
</div>
</div>
<div class="elementor-element elementor-element-4ac9dc0b elementor-mobile-align-center elementor-widget elementor-widget-button" data-id="4ac9dc0b" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://meet.brevo.com/enzo-bortone/presentation-unlocker-1er-mois-gratuit" target="_blank">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">Accéder à l'offre</span>
</span>
</a>
</div>
</div>
</div>
<div class="elementor-element elementor-element-10ffe685 elementor-widget__width-inherit elementor-widget elementor-widget-shortcode" data-id="10ffe685" data-element_type="widget" data-e-type="widget" data-widget_type="shortcode.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper"><a class="elementor-button elementor-button-link elementor-size-sm" href="https://unlocker.io/demarrer/?offre=delegation&amp;parcours=demo" target="_blank" data-ul-ads-cta="1"><span class="elementor-button-content-wrapper"><span class="elementor-button-text">Réserver ma démo</span></span></a></div>
</div>
</div>
</main>
<footer>site footer</footer>
</body>
</html>
HTML;

$page12723CachedFixture = <<<'HTML'
<!doctype html>
<html lang="fr-FR">
<head><meta charset="UTF-8"><title>Carte T conciergerie</title></head>
<body class="wp-singular page-template-default page page-id-12723 wp-custom-logo wp-embed-responsive wp-theme-hello-elementor jkit-color-scheme hello-elementor-default elementor-default elementor-kit-23 elementor-page elementor-page-12723">
<header>
<div class="elementor-element elementor-element-8e6ddc2 e-con-full elementor-hidden-tablet elementor-hidden-mobile e-flex e-con e-child" data-id="8e6ddc2" data-element_type="container" data-e-type="container">
<div class="elementor-element elementor-element-99a471c elementor-widget elementor-widget-button" data-id="99a471c" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/login">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">Connexion</span>
</span>
</a>
</div>
</div>
</div>
<div class="elementor-element elementor-element-41bc9be elementor-widget elementor-widget-button" data-id="41bc9be" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/register">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">S'inscrire</span>
</span>
</a>
</div>
</div>
</div>
</div>
</header>
<main>
<div class="elementor-element elementor-element-7038b1ec elementor-mobile-align-center elementor-align-center elementor-widget elementor-widget-button" data-id="7038b1ec" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
<div class="elementor-widget-container">
<div class="elementor-button-wrapper">
<a class="elementor-button elementor-button-link elementor-size-sm" href="https://app.unlocker.io/register" target="_blank">
<span class="elementor-button-content-wrapper">
<span class="elementor-button-text">M'inscrire sur Unlocker</span>
</span>
</a>
</div>
</div>
</div>
</main>
<footer>site footer</footer>
</body>
</html>
HTML;

// -- Cache-proof path: ads_landing_locate_widget_inner_html() ---------------

$location13932f9c = ads_landing_locate_widget_inner_html($page11814CachedFixture, '13932f9c');
check(
    'ads_landing_locate_widget_inner_html finds the CTA\'s inner content on page 11814',
    $location13932f9c !== null
    && strpos(substr($page11814CachedFixture, $location13932f9c[0], $location13932f9c[1]), 'elementor-button-wrapper') !== false
);

$locationMissing = ads_landing_locate_widget_inner_html($page11814CachedFixture, 'doesnotexist');
check(
    'ads_landing_locate_widget_inner_html returns null for a widget id absent from the page',
    $locationMissing === null
);

// -- Cache-proof path: ads_landing_rewrite_full_content() -------------------

$config11814 = AD_LANDING_PAGES[11814];
$rewrittenFullPage11814 = ads_landing_rewrite_full_content($page11814CachedFixture, $config11814);

check(
    'full-page rewrite: no more meet.brevo.com references on page 11814',
    strpos($rewrittenFullPage11814, 'meet.brevo.com') === false
);
check(
    'full-page rewrite: the /demarrer/ delegation href appears 4 times on page 11814 (3 CTAs + the calendar button)',
    substr_count($rewrittenFullPage11814, 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') === 4
);
check(
    'full-page rewrite: the marker attribute appears exactly 4 times on page 11814, never duplicated',
    substr_count($rewrittenFullPage11814, AD_LANDING_CTA_MARKER . '="1"') === 4
);
check(
    'full-page rewrite: header Connexion button untouched on page 11814',
    strpos($rewrittenFullPage11814, 'href="https://app.unlocker.io/login">') !== false
);
check(
    'full-page rewrite: header S\'inscrire button untouched on page 11814',
    substr_count($rewrittenFullPage11814, 'href="https://app.unlocker.io/register">') === 1
);

$rewrittenFullPage11814Twice = ads_landing_rewrite_full_content($rewrittenFullPage11814, $config11814);
check(
    'full-page rewrite is idempotent on page 11814: a second pass changes nothing',
    $rewrittenFullPage11814Twice === $rewrittenFullPage11814
);

$config12723 = AD_LANDING_PAGES[12723];
$rewrittenFullPage12723 = ads_landing_rewrite_full_content($page12723CachedFixture, $config12723);

check(
    'full-page rewrite: the single CTA on page 12723 (no calendar configured) points at the /demarrer/ carte-t url',
    strpos($rewrittenFullPage12723, 'href="' . htmlspecialchars($newHrefCarteT, ENT_QUOTES) . '"') !== false
);
check(
    'full-page rewrite: the CTA\'s old app.unlocker.io/register href is gone, only the header S\'inscrire keeps it',
    substr_count($rewrittenFullPage12723, 'href="https://app.unlocker.io/register"') === 1
);
check(
    'full-page rewrite: exactly one marker attribute added on page 12723 (the single CTA, header untouched)',
    substr_count($rewrittenFullPage12723, AD_LANDING_CTA_MARKER . '="1"') === 1
);

$rewrittenFullPage12723Twice = ads_landing_rewrite_full_content($rewrittenFullPage12723, $config12723);
check(
    'full-page rewrite is idempotent on page 12723: a second pass changes nothing',
    $rewrittenFullPage12723Twice === $rewrittenFullPage12723
);

// -- ads_landing_output_buffer_callback_for_current_request() ---------------
// The pure page-id guard + callback factory that start_ad_landing_output_buffer()
// hands straight to ob_start(). Testing it directly exercises the exact same
// rewriting behaviour a real request would get, with no real output
// buffering involved.

$GLOBALS['test_current_page_id'] = 11814;
$bufferCallback11814 = ads_landing_output_buffer_callback_for_current_request();
check(
    'on page 11814, the output buffer callback factory returns a callable',
    is_callable($bufferCallback11814)
);
check(
    'that callback rewrites the 3 cached CTA buttons on page 11814',
    is_callable($bufferCallback11814)
    && substr_count($bufferCallback11814($page11814CachedFixture), 'href="' . htmlspecialchars($newHrefDelegation, ENT_QUOTES) . '"') === 4
);

$GLOBALS['test_current_page_id'] = 999;
check(
    'on an unrelated page, the output buffer callback factory returns null',
    ads_landing_output_buffer_callback_for_current_request() === null
);

// -- Mutation-proof case: start_ad_landing_output_buffer() wiring -----------
// This is the test that must go RED if the
// `add_action('template_redirect', …start_ad_landing_output_buffer', 0)`
// line, or the ob_start() call inside start_ad_landing_output_buffer()
// itself, is removed: with nothing registered on the hook,
// do_action('template_redirect') calls nothing, and ob_get_level() never
// moves -- exactly the prod symptom this whole cache-proof path exists to
// close (a cached response nothing ever gets a chance to rewrite).

$GLOBALS['test_current_page_id'] = 11814;
$obLevelBeforeMatch = ob_get_level();
do_action('template_redirect');
$obLevelAfterMatch = ob_get_level();
if ($obLevelAfterMatch > $obLevelBeforeMatch) {
    ob_end_clean();
}
check(
    'on page 11814, template_redirect starts exactly one output buffer',
    $obLevelAfterMatch === $obLevelBeforeMatch + 1
);

$GLOBALS['test_current_page_id'] = 999;
$obLevelBeforeUnrelated = ob_get_level();
do_action('template_redirect');
$obLevelAfterUnrelated = ob_get_level();
if ($obLevelAfterUnrelated > $obLevelBeforeUnrelated) {
    ob_end_clean();
}
check(
    'on an unrelated page, template_redirect does NOT start an output buffer',
    $obLevelAfterUnrelated === $obLevelBeforeUnrelated
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

// -- CTA/hero-image overlap fix: ad_landing_cta_overlap_fix_css() -----------
// Pure function: the exact <style> block print_ad_landing_cta_overlap_fix()
// echoes in <head>. Page-scoped by the `page-id-{id}` class WordPress always
// prints on <body>, so it can never leak to another page, and targets the
// whole widget wrapper (`.elementor-element-{id}`), not just an <img> class,
// because the real overlap on page 12723 is caused by the image widget's OWN
// `.elementor-widget-container` (margin-top:-60px in the real Elementor
// per-post CSS), not merely by the <img> tag nested inside it -- pointer-events:none
// on only the <img> leaves that container div still catching the click
// (verified with a real browser against a copy of the actual prod markup,
// see the recette notes in the PR description).

check(
    'ad_landing_cta_overlap_fix_css(): exact <style> block for page 12723 / widget 745598c0',
    ad_landing_cta_overlap_fix_css(12723, '745598c0') === "<style>.page-id-12723 .elementor-element-745598c0{pointer-events:none}</style>\n"
);

// -- CTA/hero-image overlap fix: print_ad_landing_cta_overlap_fix() ---------
// Integration via the real `wp_head` action this file registers the callback
// on (do_action('wp_head') runs everything add_action('wp_head', ...) added,
// exactly like a real WordPress request would).

function captureWpHeadOutput(): string
{
    ob_start();
    do_action('wp_head');

    return ob_get_clean();
}

$GLOBALS['test_current_page_id'] = 12723;
$wpHeadOutput12723 = captureWpHeadOutput();
check(
    'wp_head on page 12723: prints the overlap-fix <style> block, scoped to this page and this widget',
    strpos($wpHeadOutput12723, '.page-id-12723 .elementor-element-745598c0{pointer-events:none}') !== false
);

// -- Mutation-proof case: page-scoping of the overlap fix --------------------
// This is the test that must go RED if the `cta_overlap_widget` lookup keyed
// on the CURRENT page id were replaced by a hardcoded '745598c0' (or the
// page-id guard were dropped): a page id that resolves to a DIFFERENT
// AD_LANDING_PAGES entry (11814 and 12231, whose `cta_overlap_widget` is
// null -- they never had this bug) must never print any overlap-fix
// <style> block, and an entirely unrelated page id (999, not in
// AD_LANDING_PAGES at all) must not either.

$GLOBALS['test_current_page_id'] = 11814;
$wpHeadOutput11814 = captureWpHeadOutput();
check(
    'wp_head on page 11814 (no overlap bug there): no overlap-fix <style> block printed',
    strpos($wpHeadOutput11814, 'pointer-events:none') === false
);

$GLOBALS['test_current_page_id'] = 12231;
$wpHeadOutput12231 = captureWpHeadOutput();
check(
    'wp_head on page 12231 (no overlap bug there): no overlap-fix <style> block printed',
    strpos($wpHeadOutput12231, 'pointer-events:none') === false
);

$GLOBALS['test_current_page_id'] = 999;
$wpHeadOutputUnrelated = captureWpHeadOutput();
check(
    'wp_head on an unrelated page: no overlap-fix <style> block printed',
    strpos($wpHeadOutputUnrelated, 'pointer-events:none') === false
);

// --------------------------------------------------------------------------

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
