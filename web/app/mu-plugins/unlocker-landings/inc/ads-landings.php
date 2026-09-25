<?php

/**
 * Google Ads landing pages (ids 11814, 12231, 12723): plain Elementor pages
 * stored in the DB whose CTA buttons currently point at an external Brevo
 * meeting scheduler or app.unlocker.io/register. A Product decision reroutes
 * them to this site's own /demarrer/ lead form instead, and replaces the
 * Brevo calendar <iframe> embed at the bottom of two of the pages with a
 * button to the same destination.
 *
 * Elementor data in the DB is never touched: everything happens by filtering
 * the RENDERED HTML at request time, via Elementor core's
 * `elementor/widget/render_content` filter (fires once per rendered widget,
 * $content = the widget's inner HTML, $widget->get_id() = the same short hex
 * string as the front-end data-id attribute). This filter fires for every
 * widget on every page, including the shared header's Connexion/S'inscrire
 * buttons, which reuse the SAME widget ids on all pages including these 3 --
 * the guard below is therefore both page-scoped (current queried page id is
 * a key of AD_LANDING_PAGES) AND id-scoped (the widget id is in that page's
 * own explicit allow-list). Never match by button label text: labels use
 * curly/straight apostrophes inconsistently and are not a stable target.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

const AD_LANDING_PAGES = [
    11814 => [
        'offer' => 'delegation',
        'ctas' => ['13932f9c', '5901f037', '4ac9dc0b'],
        'calendar_widget' => '10ffe685',
    ],
    12231 => [
        'offer' => 'carte-g-t',
        'ctas' => ['02ab38a', 'fadda98', '2c7f661'],
        'calendar_widget' => '5f0ad3d',
    ],
    12723 => [
        'offer' => 'carte-t',
        'ctas' => ['7038b1ec'],
        'calendar_widget' => null,
    ],
];

const AD_LANDING_CTA_MARKER = 'data-ul-ads-cta';

/**
 * Pure (no WordPress call): given the widget's real rendered content --
 * always exactly one `<a class="elementor-button ..." href="...">...</a>`
 * inside a `.elementor-button-wrapper` div, per the real HTML measured
 * against the three pages -- rewrites ONLY its href and adds the CTA marker
 * attribute so the query-propagation script (assets/ads-landing-query.js)
 * can find it.
 *
 * Doesn't assume attribute order (class before href): it matches the
 * opening `<a ...>` tag first, confirms it carries the `elementor-button`
 * class token, THEN extracts/replaces its `href="..."` value.
 *
 * Defensive: if the expected shape isn't found, $content is returned
 * UNCHANGED (never risk corrupting unrelated markup) and the widget id that
 * didn't match is error_log()'d.
 */
function ads_landing_rewrite_button_href(string $content, string $newHref, string $widgetIdForLogging): string
{
    if (!preg_match('/<a\b[^>]*>/i', $content, $tagMatch, PREG_OFFSET_CAPTURE)) {
        error_log("ads-landings: no <a> tag found in widget {$widgetIdForLogging}, content left unchanged");

        return $content;
    }

    $tag = $tagMatch[0][0];
    $tagOffset = $tagMatch[0][1];

    if (
        !preg_match('/\bclass\s*=\s*"([^"]*)"/i', $tag, $classMatch)
        || !in_array('elementor-button', preg_split('/\s+/', trim($classMatch[1])), true)
    ) {
        error_log("ads-landings: <a> tag missing the elementor-button class in widget {$widgetIdForLogging}, content left unchanged");

        return $content;
    }

    if (!preg_match('/\bhref\s*=\s*"[^"]*"/i', $tag, $hrefMatch)) {
        error_log("ads-landings: elementor-button <a> tag missing href in widget {$widgetIdForLogging}, content left unchanged");

        return $content;
    }

    $newTag = str_replace($hrefMatch[0], 'href="' . htmlspecialchars($newHref, ENT_QUOTES) . '"', $tag);
    // Insert the marker attribute right before the tag's closing '>'.
    $newTag = rtrim(substr($newTag, 0, -1)) . ' ' . AD_LANDING_CTA_MARKER . '="1">';

    return substr_replace($content, $newTag, $tagOffset, strlen($tag));
}

/**
 * Pure (no WordPress call): builds a replacement for the Brevo <iframe>
 * shortcode widget, reusing the same classes as the real CTA buttons
 * (elementor-button-wrapper > a.elementor-button.elementor-button-link
 * .elementor-size-sm > two nested spans), labelled "Réserver ma démo",
 * opening in a new tab, and carrying the CTA marker too so it also gets
 * query-string propagation.
 */
function ads_landing_calendar_button_html(string $href): string
{
    $escapedHref = htmlspecialchars($href, ENT_QUOTES);

    return '<div class="elementor-button-wrapper">'
        . '<a class="elementor-button elementor-button-link elementor-size-sm" href="' . $escapedHref . '" target="_blank" ' . AD_LANDING_CTA_MARKER . '="1">'
        . '<span class="elementor-button-content-wrapper">'
        . '<span class="elementor-button-text">Réserver ma démo</span>'
        . '</span>'
        . '</a>'
        . '</div>';
}

add_filter('elementor/widget/render_content', __NAMESPACE__ . '\\filter_ad_landing_widget_content', 10, 2);

function filter_ad_landing_widget_content(string $content, $widget): string
{
    $pageId = get_queried_object_id();

    if (!isset(AD_LANDING_PAGES[$pageId]) || !is_page($pageId)) {
        return $content;
    }

    $config = AD_LANDING_PAGES[$pageId];
    $widgetId = $widget->get_id();
    $targetUrl = demarrer_url('offre=' . rawurlencode($config['offer']) . '&parcours=demo');

    if (in_array($widgetId, $config['ctas'], true)) {
        return ads_landing_rewrite_button_href($content, $targetUrl, $widgetId);
    }

    if ($config['calendar_widget'] !== null && $widgetId === $config['calendar_widget']) {
        return ads_landing_calendar_button_html($targetUrl);
    }

    return $content;
}

add_action('wp_enqueue_scripts', __NAMESPACE__ . '\\enqueue_ad_landing_query_script');

/**
 * Enqueues the query-propagation script (assets/ads-landing-query.js) only
 * on the three ad landing pages, using the same page-id guard as the widget
 * content filter above.
 */
function enqueue_ad_landing_query_script(): void
{
    $pageId = get_queried_object_id();

    if (!isset(AD_LANDING_PAGES[$pageId]) || !is_page($pageId)) {
        return;
    }

    wp_enqueue_script(
        'unlocker-ads-landing-query',
        asset_url('ads-landing-query.js'),
        [],
        asset_version('ads-landing-query.js'),
        false
    );
}

// wp_enqueue_script() alone would print the script without `defer`.
add_filter('script_loader_tag', function (string $tag, string $handle): string {
    if ($handle === 'unlocker-ads-landing-query' && strpos($tag, ' defer') === false) {
        $tag = str_replace(' src=', ' defer src=', $tag);
    }

    return $tag;
}, 10, 2);
