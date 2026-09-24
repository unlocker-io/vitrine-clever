<?php

/**
 * Shared constants and small helpers for the mountain landing pages: page
 * template keys, slugs and asset URLs. Single source of truth so the rest of
 * the plugin never hardcodes a slug or a path.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

const TEMPLATE_SPLIT = 'unlocker-landing-split';
const TEMPLATE_DELEGATION = 'unlocker-landing-delegation';
const TEMPLATE_DEMARRER = 'unlocker-landing-demarrer';

const SLUG_SPLIT = 'split-de-paiement-conciergerie';
const SLUG_DELEGATION = 'delegation-carte-g-location-saisonniere';
const SLUG_DEMARRER = 'demarrer';

const PLUGIN_DIR = __DIR__ . '/..';

/**
 * Absolute URL to a file under this plugin's assets/ directory.
 */
function asset_url(string $path): string
{
    return content_url('mu-plugins/unlocker-landings/assets/' . $path);
}

/**
 * Filesystem path to a file under assets/, used for filemtime() versioning.
 */
function asset_path(string $path): string
{
    return PLUGIN_DIR . '/assets/' . $path;
}

/**
 * A cache-busting version string for an asset (its mtime, or a static
 * fallback when the file can't be stat'd).
 */
function asset_version(string $path): string
{
    $file = asset_path($path);
    $mtime = file_exists($file) ? filemtime($file) : false;

    return $mtime !== false ? (string) $mtime : '1.0.0';
}

function split_url(): string
{
    return home_url('/' . SLUG_SPLIT . '/');
}

function delegation_url(): string
{
    return home_url('/' . SLUG_DELEGATION . '/');
}

function demarrer_url(string $query = ''): string
{
    $url = home_url('/' . SLUG_DEMARRER . '/');

    return $query === '' ? $url : $url . '?' . $query;
}

/**
 * The landing template key of the currently queried page, or null when the
 * current request isn't one of our three landings.
 */
function get_landing_template(): ?string
{
    if (!is_page()) {
        return null;
    }

    $slug = get_page_template_slug(get_queried_object_id());

    $known = [TEMPLATE_SPLIT, TEMPLATE_DELEGATION, TEMPLATE_DEMARRER];

    return in_array($slug, $known, true) ? $slug : null;
}
