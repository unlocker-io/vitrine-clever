<?php

/**
 * SEO for the three landing pages: title, meta description, robots and the
 * Open Graph image, plus the FAQPage JSON-LD for split and delegation.
 *
 * Single config array below; with Yoast active it feeds the Yoast filters
 * and canonical/og:url/og:locale/BreadcrumbList/schema graph stay Yoast's.
 * Without Yoast, this file emits the tags itself.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

/**
 * @return array<string, array{title: string, description: string, robots: string, og_image: ?string}>
 */
function seo_config(): array
{
    return [
        TEMPLATE_SPLIT => [
            'title' => 'Split de paiement pour conciergeries de montagne | Unlocker',
            'description' => 'Conciergeries de montagne : répartissez vos encaissements entre propriétaires, commission et prestataires. Réduisez avances de trésorerie et relances.',
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
            'og_image' => asset_url('images/split-scene-og.jpg'),
        ],
        TEMPLATE_DELEGATION => [
            'title' => 'Délégation Carte G pour conciergeries de montagne | Unlocker',
            'description' => 'Conciergeries de montagne : délégation Carte G en location courte durée. Missions définies par contrat et cadre de la loi Hoguet en France avec Unlocker.',
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
            'og_image' => asset_url('images/delegation-scene-og.jpg'),
        ],
        TEMPLATE_DEMARRER => [
            'title' => 'Présentez votre conciergerie | Unlocker',
            'description' => 'Préparez votre parcours Unlocker en quelques informations.',
            'robots' => 'noindex, follow',
            'og_image' => null,
        ],
    ];
}

/**
 * @return array{title: string, description: string, robots: string, og_image: ?string}|null
 */
function current_seo_config(): ?array
{
    $slug = get_landing_template();

    if ($slug === null) {
        return null;
    }

    return seo_config()[$slug] ?? null;
}

function is_yoast_active(): bool
{
    return defined('WPSEO_VERSION');
}

// -- Yoast integration --------------------------------------------------

add_filter('wpseo_title', function (string $title): string {
    $config = current_seo_config();

    return $config !== null ? $config['title'] : $title;
});

add_filter('wpseo_metadesc', function (string $description): string {
    $config = current_seo_config();

    return $config !== null ? $config['description'] : $description;
});

add_filter('wpseo_opengraph_title', function (string $title): string {
    $config = current_seo_config();

    return $config !== null ? $config['title'] : $title;
});

add_filter('wpseo_opengraph_desc', function (string $description): string {
    $config = current_seo_config();

    return $config !== null ? $config['description'] : $description;
});

// Our landings have no featured image, so the indexable's own image list is
// empty and the `wpseo_opengraph_image` filter (which only rewrites an
// *existing* image) never runs. `wpseo_add_opengraph_images` is the hook
// that actually seeds the list; it runs before Yoast's own featured-image /
// default-image fallbacks, which all back off once an image is present.
add_filter('wpseo_add_opengraph_images', function ($images) {
    $config = current_seo_config();

    if ($config !== null && $config['og_image'] !== null && method_exists($images, 'add_image_by_url')) {
        $images->add_image_by_url($config['og_image']);
    }

    return $images;
});

add_filter('wpseo_opengraph_image_width', function ($width) {
    $config = current_seo_config();

    if ($config !== null && $config['og_image'] !== null) {
        return 1200;
    }

    return $width;
});

add_filter('wpseo_opengraph_image_height', function ($height) {
    $config = current_seo_config();

    if ($config !== null && $config['og_image'] !== null) {
        return 630;
    }

    return $height;
});

add_filter('wpseo_twitter_card_type', function (string $type): string {
    return current_seo_config() !== null ? 'summary_large_image' : $type;
});

add_filter('wpseo_robots', function (string $robots): string {
    $config = current_seo_config();

    return $config !== null ? $config['robots'] : $robots;
});

add_filter('wpseo_schema_graph', function ($graph) {
    $slug = get_landing_template();

    if ($slug === null) {
        return $graph;
    }

    $faq = faq_schema($slug);

    if ($faq !== null) {
        $graph[] = $faq;
    }

    return $graph;
});

add_filter('wpseo_exclude_from_sitemap_by_post_ids', function (array $excluded): array {
    global $wpdb;

    $ids = $wpdb->get_col($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_page_template' AND meta_value = %s",
        TEMPLATE_DEMARRER
    ));

    foreach ($ids as $id) {
        $excluded[] = (int) $id;
    }

    return $excluded;
});

// -- Fallback when Yoast is not active -----------------------------------

add_action('wp_head', __NAMESPACE__ . '\\print_fallback_seo_tags', 1);

function print_fallback_seo_tags(): void
{
    if (is_yoast_active()) {
        return;
    }

    $config = current_seo_config();

    if ($config === null) {
        return;
    }

    echo '<title>' . esc_html($config['title']) . '</title>' . "\n";
    echo '<meta name="description" content="' . esc_attr($config['description']) . '">' . "\n";
    echo '<meta name="robots" content="' . esc_attr($config['robots']) . '">' . "\n";
    echo '<link rel="canonical" href="' . esc_url(get_permalink()) . '">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr($config['title']) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr($config['description']) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(get_permalink()) . '">' . "\n";
    echo '<meta property="og:type" content="website">' . "\n";

    if ($config['og_image'] !== null) {
        echo '<meta property="og:image" content="' . esc_url($config['og_image']) . '">' . "\n";
        echo '<meta property="og:image:width" content="1200">' . "\n";
        echo '<meta property="og:image:height" content="630">' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
    }

    $slug = get_landing_template();
    $faq = $slug !== null ? faq_schema($slug) : null;

    if ($faq !== null) {
        $faq['@context'] = 'https://schema.org';
        echo '<script type="application/ld+json">' . wp_json_encode($faq) . '</script>' . "\n";
    }
}
