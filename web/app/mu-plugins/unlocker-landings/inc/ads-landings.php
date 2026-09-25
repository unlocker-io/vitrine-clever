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
 * Measured in prod (25/09): that per-widget filter is NOT a reliable enough
 * hook on its own -- a widget can reach the browser without ever having gone
 * through a fresh `render_content()` call this request (Elementor's element
 * cache, an intermediary cache, or anything else able to serve a widget's
 * markup older than the PHP that's supposed to produce it), and when that
 * happens the filter above is simply never called for it, silently. So this
 * file ALSO wraps the full, already-rendered page in an output buffer (see
 * start_ad_landing_output_buffer(), on `template_redirect`) and re-applies
 * the exact same rewriting rules to whatever HTML actually reached the
 * response, by locating each target widget's `data-id` in that string. Both
 * mechanisms are kept: the per-widget filter still runs first on a normal
 * render, and the full-page pass is a no-op on top of it (see the
 * idempotence note on ads_landing_rewrite_button_href() and
 * ads_landing_rewrite_full_content()) -- it only does real work for whatever
 * the per-widget filter missed.
 *
 * Also, page 12723 only: a hero image widget geometrically overlaps its CTA
 * button at desktop widths and swallows clicks meant for it. Fixed the same
 * way -- an inline `<style>` printed in <head>, never touching the Elementor
 * DB content -- see print_ad_landing_cta_overlap_fix() near the bottom of
 * this file.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

const AD_LANDING_PAGES = [
    11814 => [
        'offer' => 'delegation',
        'ctas' => ['13932f9c', '5901f037', '4ac9dc0b'],
        'calendar_widget' => '10ffe685',
        'cta_overlap_widget' => null,
    ],
    12231 => [
        'offer' => 'carte-g-t',
        'ctas' => ['02ab38a', 'fadda98', '2c7f661'],
        'calendar_widget' => '5f0ad3d',
        'cta_overlap_widget' => null,
    ],
    12723 => [
        'offer' => 'carte-t',
        'ctas' => ['7038b1ec'],
        'calendar_widget' => null,
        // Measured in prod (25/09): the hero mockup image widget's own
        // `.elementor-widget-container` carries a `margin-top:-60px` (see
        // uploads/elementor/css/post-12723.css) that pulls its rendered box
        // -- not just the <img> inside it -- over the CTA button
        // (elementor-element-7038b1ec) sitting right above it in the DOM, at
        // desktop widths (measured 1280x900 and 1920x1080; the mobile layout
        // stacks them without overlap). See print_ad_landing_cta_overlap_fix()
        // below.
        'cta_overlap_widget' => '745598c0',
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
    // Idempotent: a block that already carries the marker was already
    // rewritten -- either by this same filter on a fresh render, or by a
    // previous pass of the full-page cache-bypass rewrite further down this
    // file. Re-matching the <a> tag and replacing its (already correct) href
    // again would be harmless, but re-appending the marker attribute a
    // second time would NOT be: it would leave two `data-ul-ads-cta="1"`
    // attributes on the same tag. Bailing out here keeps repeated
    // applications byte-for-byte identical.
    if (strpos($content, AD_LANDING_CTA_MARKER . '="1"') !== false) {
        return $content;
    }

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

/**
 * Pure (no WordPress call): given the FULL rendered page HTML and a widget
 * id, returns the byte offset and length of that widget's inner content --
 * the same slice `elementor/widget/render_content` hands to the filter above
 * -- i.e. everything inside its `.elementor-widget-container` wrapper div,
 * balanced against nested `<div>`/`</div>` tags so a widget with its own
 * nested markup (the button's `.elementor-button-wrapper`, the calendar's
 * `.elementor-shortcode`) is never truncated mid-element.
 *
 * Returns null -- callers must leave $html untouched in that case -- when
 * the widget id, or a `.elementor-widget-container` after it, isn't found
 * (widget absent from this render) or when the `<div>`/`</div>` tags never
 * balance (unexpected markup shape): never risk corrupting unrelated markup
 * on a shape this plugin wasn't written against.
 */
function ads_landing_locate_widget_inner_html(string $html, string $widgetId): ?array
{
    $idPos = strpos($html, 'data-id="' . $widgetId . '"');

    if ($idPos === false) {
        return null;
    }

    $containerTag = '<div class="elementor-widget-container">';
    $containerPos = strpos($html, $containerTag, $idPos);

    if ($containerPos === false) {
        return null;
    }

    $innerStart = $containerPos + strlen($containerTag);
    $cursor = $innerStart;
    $depth = 1;

    while ($depth > 0) {
        $nextOpen = strpos($html, '<div', $cursor);
        $nextClose = strpos($html, '</div>', $cursor);

        if ($nextClose === false) {
            // Unbalanced markup: bail out rather than guess.
            return null;
        }

        if ($nextOpen !== false && $nextOpen < $nextClose) {
            $depth++;
            $cursor = $nextOpen + 4;
        } else {
            $depth--;
            $cursor = $nextClose + 6;
        }
    }

    $innerEnd = $cursor - 6; // Back up to just before the matching </div>.

    return [$innerStart, $innerEnd - $innerStart];
}

/**
 * Pure (no WordPress call): applies the exact same rewriting rules as
 * filter_ad_landing_widget_content() above, but to the page's FULL
 * already-rendered HTML rather than to a single widget's content as
 * Elementor hands it to the `elementor/widget/render_content` filter.
 *
 * This is the cache-proof path: whatever mechanism served a widget's markup
 * unrendered by that filter on this request, the bytes that actually reached
 * the browser still carry each target widget's `data-id`, so locating and
 * rewriting THAT string closes the gap regardless of the reason it opened.
 *
 * Idempotent: re-running it on HTML the per-widget filter (or a previous
 * pass of this same function) already fixed is a no-op byte-for-byte --
 * ads_landing_rewrite_button_href() bails out on a block that already
 * carries the marker attribute, and ads_landing_calendar_button_html()
 * produces byte-identical output for the same $config every time, so
 * replacing an already-correct block with it changes nothing.
 */
function ads_landing_rewrite_full_content(string $html, array $config): string
{
    $targetUrl = demarrer_url('offre=' . rawurlencode($config['offer']) . '&parcours=demo');

    foreach ($config['ctas'] as $widgetId) {
        $location = ads_landing_locate_widget_inner_html($html, $widgetId);

        if ($location === null) {
            continue;
        }

        [$offset, $length] = $location;
        $inner = substr($html, $offset, $length);
        $rewritten = ads_landing_rewrite_button_href($inner, $targetUrl, $widgetId);

        if ($rewritten !== $inner) {
            $html = substr_replace($html, $rewritten, $offset, $length);
        }
    }

    if ($config['calendar_widget'] !== null) {
        $location = ads_landing_locate_widget_inner_html($html, $config['calendar_widget']);

        if ($location !== null) {
            [$offset, $length] = $location;
            $inner = substr($html, $offset, $length);
            $newInner = ads_landing_calendar_button_html($targetUrl);

            if ($inner !== $newInner) {
                $html = substr_replace($html, $newInner, $offset, $length);
            }
        }
    }

    return $html;
}

/**
 * Same page-id guard as the widget filter above. Returns the callback to
 * hand to ob_start() for the current request, or null when the current page
 * isn't one of the three ad landings -- kept separate from ob_start() itself
 * (see start_ad_landing_output_buffer() below) so the decision of WHICH
 * pages get buffered, and WHAT the buffer callback does, can be tested
 * without touching real output buffering.
 *
 * The widget-id scoping that protects the shared header's
 * Connexion/S'inscrire buttons happens inside ads_landing_rewrite_full_content()
 * itself, which only ever searches for the current page's own configured
 * widget ids -- never the header's.
 */
function ads_landing_output_buffer_callback_for_current_request(): ?callable
{
    $pageId = get_queried_object_id();

    if (!isset(AD_LANDING_PAGES[$pageId]) || !is_page($pageId)) {
        return null;
    }

    $config = AD_LANDING_PAGES[$pageId];

    return function (string $html) use ($config): string {
        return ads_landing_rewrite_full_content($html, $config);
    };
}

add_action('template_redirect', __NAMESPACE__ . '\\start_ad_landing_output_buffer', 0);

/**
 * Cache-proof counterpart to filter_ad_landing_widget_content(): starts an
 * output buffer over the ENTIRE response for the three ad landing pages,
 * rewritten on flush by ads_landing_rewrite_full_content(). Registered on
 * `template_redirect` (before the theme's template file runs) so it wraps
 * everything the page prints, whatever produced it.
 */
function start_ad_landing_output_buffer(): void
{
    $callback = ads_landing_output_buffer_callback_for_current_request();

    if ($callback !== null) {
        ob_start($callback);
    }
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

/**
 * Pure (no WordPress call): the inline `<style>` block that neutralizes
 * pointer events on a decorative widget overlapping a CTA, scoped to a single
 * page via the `page-id-{$pageId}` class WordPress always prints on <body>.
 *
 * Scoping by page id (rather than emitting a bare `.elementor-element-xxx`
 * rule) means this can never affect another page even in the (very unlikely)
 * event Elementor ever reused the same short widget id elsewhere.
 *
 * `pointer-events: none` -- rather than a `position`/`z-index` fix on the
 * button -- was chosen because the overlapping widget is a purely decorative
 * image (`alt=""`, not a link or anything else interactive): it has nothing
 * to lose by stopping receiving pointer events, whereas repositioning the
 * button risks the button and image nudging each other or reflowing
 * differently than the design at some viewport this fix isn't scoped to
 * check.
 */
function ad_landing_cta_overlap_fix_css(int $pageId, string $widgetId): string
{
    return '<style>.page-id-' . $pageId . ' .elementor-element-' . $widgetId . '{pointer-events:none}</style>' . "\n";
}

add_action('wp_head', __NAMESPACE__ . '\\print_ad_landing_cta_overlap_fix');

/**
 * Measured in prod (25/09) on page 12723 (/carte-t/) only: the hero mockup
 * image widget's own `.elementor-widget-container` (not just the <img>
 * inside it, see the `cta_overlap_widget` comment on AD_LANDING_PAGES above)
 * geometrically overlaps the "M'inscrire sur Unlocker" CTA button at desktop
 * widths, and -- despite the button being visually on top in the design --
 * is what `document.elementFromPoint()` and a real mouse click at the
 * button's location actually hit, silently swallowing the click. Mobile is
 * unaffected (the two widgets stack without overlapping) and isn't touched.
 *
 * Emits the CSS directly in `<head>` (like print_fallback_seo_tags() in
 * seo.php and print_font_preloads() just above in assets.php) rather than via
 * wp_add_inline_style() on an enqueued handle: these three Elementor pages
 * are plain DB content, not one of this plugin's own page templates, so they
 * never go through enqueue_landing_assets() / dequeue_foreign_assets() (both
 * gated on get_landing_template(), which is null here) -- there is no
 * `unlocker-landings` stylesheet enqueued on this request to attach to.
 *
 * The Elementor DB content itself is never touched, same principle as the
 * href rewriting above: this only ever changes what's printed in <head>.
 */
function print_ad_landing_cta_overlap_fix(): void
{
    $pageId = get_queried_object_id();

    if (!isset(AD_LANDING_PAGES[$pageId]) || !is_page($pageId)) {
        return;
    }

    $widgetId = AD_LANDING_PAGES[$pageId]['cta_overlap_widget'] ?? null;

    if ($widgetId === null) {
        return;
    }

    echo ad_landing_cta_overlap_fix_css($pageId, $widgetId);
}
