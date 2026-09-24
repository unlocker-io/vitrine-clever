<?php

/**
 * Registers the three landing page templates and serves them.
 *
 * A page created with `"template":"unlocker-landing-split"` (REST API or
 * wp post create --page_template=...) renders through
 * templates/template-split.php, and so on for the other two.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

add_filter('theme_page_templates', function (array $templates): array {
    $templates[TEMPLATE_SPLIT] = 'Landing — Split de paiement (conciergeries de montagne)';
    $templates[TEMPLATE_DELEGATION] = 'Landing — Délégation Carte G (conciergeries de montagne)';
    $templates[TEMPLATE_DEMARRER] = 'Landing — Démarrer (conciergeries de montagne)';

    return $templates;
});

add_filter('template_include', function (string $template): string {
    $slug = get_landing_template();

    if ($slug === null) {
        return $template;
    }

    $map = [
        TEMPLATE_SPLIT => PLUGIN_DIR . '/templates/template-split.php',
        TEMPLATE_DELEGATION => PLUGIN_DIR . '/templates/template-delegation.php',
        TEMPLATE_DEMARRER => PLUGIN_DIR . '/templates/template-demarrer.php',
    ];

    return $map[$slug] ?? $template;
});
