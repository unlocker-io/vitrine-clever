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

// The 3 Google Ads landing pages (see inc/ads-landings.php's AD_LANDING_PAGES)
// that also route their CTAs through /demarrer/. Their real permalink slugs
// -- distinct from the 2 mountain slugs above.
const SLUG_AD_DELEGATION = 'delegation-carte-g-conciergerie';
const SLUG_AD_CARTE_G_T = 'offre-carte-g-t';
const SLUG_AD_CARTE_T = 'carte-t';

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

function ad_delegation_url(): string
{
    return home_url('/' . SLUG_AD_DELEGATION . '/');
}

function ad_carte_g_t_url(): string
{
    return home_url('/' . SLUG_AD_CARTE_G_T . '/');
}

function ad_carte_t_url(): string
{
    return home_url('/' . SLUG_AD_CARTE_T . '/');
}

function demarrer_url(string $query = ''): string
{
    $url = home_url('/' . SLUG_DEMARRER . '/');

    return $query === '' ? $url : $url . '?' . $query;
}

/**
 * The whitelist of `from` slugs /demarrer/ accepts as an explicit, trusted
 * signal of a visitor's page of origin: the 2 mountain landings plus the 3
 * Google Ads landings. Never a free-form URL -- that would be an open
 * redirect -- exposed to flow.js as a slug => absolute URL map, so the SAME
 * map also validates document.referrer's path against a whitelisted slug.
 *
 * @return array<string,string>
 */
function from_slug_whitelist(): array
{
    return [
        SLUG_SPLIT => split_url(),
        SLUG_DELEGATION => delegation_url(),
        SLUG_AD_DELEGATION => ad_delegation_url(),
        SLUG_AD_CARTE_G_T => ad_carte_g_t_url(),
        SLUG_AD_CARTE_T => ad_carte_t_url(),
    ];
}

/**
 * offer => absolute URL /demarrer/'s "← Retour à l'offre" link falls back to
 * when neither a whitelisted `from` param nor a same-origin whitelisted
 * referrer resolved one. delegation/split keep pointing at the 2 mountain
 * landings (unchanged behaviour); carte-g-t/carte-t point at their OWN Ads
 * landing -- this corrects carte-t's previous fallback, which silently
 * landed on /split-de-paiement-conciergerie/ because flow.js's old backHref
 * logic only ever distinguished "delegation" from "everything else".
 *
 * @return array<string,string>
 */
function offer_fallback_urls(): array
{
    return [
        'delegation' => delegation_url(),
        'split' => split_url(),
        'carte-g-t' => ad_carte_g_t_url(),
        'carte-t' => ad_carte_t_url(),
    ];
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
