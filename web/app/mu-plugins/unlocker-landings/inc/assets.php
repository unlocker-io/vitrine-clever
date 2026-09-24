<?php

/**
 * Assets for the three landing pages: our own style.css/flow.js (enqueued
 * only on these templates, versioned by filemtime), font preloads, and the
 * dequeuing of every foreign style/script (theme, Elementor & friends) that
 * would otherwise diverge the rendered page from the mockup.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_landing_assets');

function enqueue_landing_assets(): void
{
    if (get_landing_template() === null) {
        return;
    }

    wp_enqueue_style('unlocker-landings', asset_url('style.css'), [], asset_version('style.css'));
    wp_enqueue_script('unlocker-landings-flow', asset_url('flow.js'), [], asset_version('flow.js'), false);
}

// The mockup loads flow.js in <head> with `defer`; wp_enqueue_script() alone
// would print it without that attribute.
add_filter('script_loader_tag', function (string $tag, string $handle): string {
    if ($handle === 'unlocker-landings-flow' && strpos($tag, ' defer') === false) {
        $tag = str_replace(' src=', ' defer src=', $tag);
    }

    return $tag;
}, 10, 2);

add_action('wp_head', __NAMESPACE__ . '\\print_font_preloads', 1);

function print_font_preloads(): void
{
    if (get_landing_template() === null) {
        return;
    }

    // Only the weights actually used above the fold: body copy (400) and the
    // eyebrow/h1/CTA (700). Medium (500) exists in style.css but isn't used
    // there, so it isn't worth a preload hint.
    foreach (['Gilroy-Regular.woff2', 'Gilroy-Bold.woff2'] as $font) {
        printf(
            '<link rel="preload" as="font" type="font/woff2" href="%s" crossorigin>' . "\n",
            esc_url(asset_url('fonts/' . $font))
        );
    }
}

add_action('wp', __NAMESPACE__ . '\\disable_emoji_on_landings');

function disable_emoji_on_landings(): void
{
    if (get_landing_template() === null) {
        return;
    }

    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
}

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\dequeue_foreign_assets', 100);

// Elementor's global kit (fonts, colors) is enqueued site-wide -- even on
// pages that don't use the builder -- via a `wp_head` callback registered
// at priority 7 during `template_redirect` (i.e. after our wp_enqueue_scripts
// sweep above already ran). Re-run the same sweep right after it: deferring
// the add_action() call to `template_redirect` (which fires after Elementor's
// own template_redirect-time registration) guarantees ours lands after
// Elementor's within the same wp_head:7 bucket, and before wp_print_styles
// prints anything (wp_head priority 8).
add_action('template_redirect', function (): void {
    if (get_landing_template() === null) {
        return;
    }

    add_action('wp_head', __NAMESPACE__ . '\\dequeue_foreign_assets', 7);
}, 20);

/**
 * Dequeues every style and script on our three templates except our own and
 * an explicit allow-list (CookieYes, PixelYourSite, Site Kit, Yoast),
 * matched by their registered src. Everything else -- the theme, Elementor,
 * Elementor Pro, JKit, The Plus, MetForm, Nexter, WP core's block-library /
 * global-styles / classic-theme-styles -- is dequeued, not deregistered, so
 * a genuine dependency (e.g. an allow-listed plugin needing jQuery) still
 * gets pulled in by WP_Dependencies' own dependency resolution.
 */
function dequeue_foreign_assets(): void
{
    if (get_landing_template() === null) {
        return;
    }

    foreach ((array) wp_styles()->queue as $handle) {
        if ($handle === 'unlocker-landings' || has_allowed_src(wp_styles(), $handle)) {
            continue;
        }

        wp_dequeue_style($handle);
    }

    foreach ((array) wp_scripts()->queue as $handle) {
        if ($handle === 'unlocker-landings-flow' || has_allowed_src(wp_scripts(), $handle)) {
            continue;
        }

        wp_dequeue_script($handle);
    }
}

function has_allowed_src(\WP_Dependencies $registry, string $handle): bool
{
    $needles = [
        '/plugins/cookie-law-info/',
        '/plugins/pixelyoursite/',
        '/plugins/google-site-kit/',
        '/plugins/wordpress-seo/',
    ];

    $item = $registry->registered[$handle] ?? null;
    $src = $item->src ?? '';

    if ($src === '' || $src === false) {
        return false;
    }

    foreach ($needles as $needle) {
        if (strpos($src, $needle) !== false) {
            return true;
        }
    }

    return false;
}
