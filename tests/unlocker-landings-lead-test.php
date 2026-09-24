<?php

declare(strict_types=1);

/**
 * Pure-function tests for inc/lead.php's validate_lead() and
 * brevo_payload() -- no WordPress install required. Run with:
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.4-cli php tests/unlocker-landings-lead-test.php
 *
 * The handful of WordPress functions inc/lead.php calls at load time
 * (registering hooks) are stubbed as no-ops below, only if not already
 * defined -- running this under WP-CLI would use the real ones instead.
 */

if (!function_exists('add_action')) {
    function add_action(...$args)
    {
    }
}

if (!function_exists('add_filter')) {
    function add_filter(...$args)
    {
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email)
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : false;
    }
}

require __DIR__ . '/../web/app/mu-plugins/unlocker-landings/inc/lead.php';

use function Unlocker\Landings\brevo_payload;
use function Unlocker\Landings\validate_lead;

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

function validSubmission(array $overrides = []): array
{
    return array_merge([
        'email' => 'contact@conciergerie-montagne.fr',
        'company' => 'Conciergerie des Cimes',
        'country' => 'France',
        'area' => 'Chamonix, vallée de l’Arve',
        'size' => '10–24',
        'phone' => '+33612345678',
        'offer' => 'delegation',
        'parcours' => 'demo',
        'utm_source' => 'meta',
        'utm_campaign' => 'test',
        'page_url' => 'https://unlocker.io/demarrer/',
        'website' => '',
    ], $overrides);
}

// -- Cas nominal ----------------------------------------------------------

[$errors, $lead] = validate_lead(validSubmission());
check('nominal: no errors', $errors === []);
check('nominal: email kept', $lead['email'] === 'contact@conciergerie-montagne.fr');
check('nominal: company kept', $lead['company'] === 'Conciergerie des Cimes');
check('nominal: size kept', $lead['size'] === '10–24');
check('nominal: offer kept', $lead['offer'] === 'delegation');
check('nominal: parcours kept', $lead['parcours'] === 'demo');
check('nominal: phone kept', $lead['phone'] === '+33612345678');
check('nominal: utm_source kept', $lead['utm_source'] === 'meta');
check('nominal: page_url kept', $lead['page_url'] === 'https://unlocker.io/demarrer/');

// -- Champ requis manquant -------------------------------------------------

[$errors, $lead] = validate_lead(validSubmission(['email' => '']));
check('missing email: error code', ($errors['email'] ?? null) === 'required');
check('missing email: not in lead', !isset($lead['email']));

[$errors, ] = validate_lead(validSubmission(['company' => '   ']));
check('missing company (whitespace only): error code', ($errors['company'] ?? null) === 'required');

[$errors, ] = validate_lead(validSubmission(['area' => '']));
check('missing area: error code', ($errors['area'] ?? null) === 'required');

// -- size hors liste --------------------------------------------------------

[$errors, $lead] = validate_lead(validSubmission(['size' => '5-10']));
check('invalid size (wrong dash): error code', ($errors['size'] ?? null) === 'invalid_choice');
check('invalid size: not in lead', !isset($lead['size']));

[$errors, ] = validate_lead(validSubmission(['size' => '999+']));
check('invalid size (made up): error code', ($errors['size'] ?? null) === 'invalid_choice');

foreach (['1–9', '10–24', '25–49', '50–99', '100+'] as $size) {
    [$errors, $lead] = validate_lead(validSubmission(['size' => $size]));
    check("size option '{$size}' is accepted", !isset($errors['size']) && ($lead['size'] ?? null) === $size);
}

// -- Longueur dépassée --------------------------------------------------------

$tooLongCompany = str_repeat('a', 161);
[$errors, $lead] = validate_lead(validSubmission(['company' => $tooLongCompany]));
check('company too long: error code', ($errors['company'] ?? null) === 'too_long');
check('company too long: not silently truncated into lead', !isset($lead['company']));

$tooLongEmailLocal = str_repeat('a', 250) . '@a.fr';
[$errors, ] = validate_lead(validSubmission(['email' => $tooLongEmailLocal]));
check('email too long: error code', ($errors['email'] ?? null) === 'too_long');

$tooLongPageUrl = 'https://unlocker.io/' . str_repeat('a', 490);
[$errors, ] = validate_lead(validSubmission(['page_url' => $tooLongPageUrl]));
check('page_url too long: error code (never truncated)', ($errors['page_url'] ?? null) === 'too_long');

// -- UTM tronqué à 200 (jamais en erreur) ------------------------------------

$longUtm = str_repeat('u', 250);
[$errors, $lead] = validate_lead(validSubmission(['utm_source' => $longUtm]));
check('long utm_source: no error', !isset($errors['utm_source']));
check('long utm_source: truncated to 200', $lead['utm_source'] === str_repeat('u', 200));
check('long utm_source: truncated length is exactly 200', mb_strlen($lead['utm_source']) === 200);

$longFbclid = str_repeat('f', 600);
[, $lead] = validate_lead(validSubmission(['fbclid' => $longFbclid]));
check('long fbclid: truncated to 500', mb_strlen($lead['fbclid']) === 500);

// -- brevo_payload: attributs vides non envoyés, listIds ---------------------

[, $lead] = validate_lead(validSubmission(['phone' => '', 'utm_campaign' => '']));
$payload = brevo_payload($lead, 117);

check('brevo payload: email at top level', $payload['email'] === $lead['email']);
check('brevo payload: updateEnabled true', $payload['updateEnabled'] === true);
check('brevo payload: listIds', $payload['listIds'] === [117]);
check('brevo payload: LEAD_TYPE always set', $payload['attributes']['LEAD_TYPE'] === 'landing_montagne');
check('brevo payload: NOM_SOCIETE present', $payload['attributes']['NOM_SOCIETE'] === $lead['company']);
check('brevo payload: empty phone -> no TELEPHONE key', !array_key_exists('TELEPHONE', $payload['attributes']));
check('brevo payload: empty utm_campaign -> no UTM_CAMPAIGN key', !array_key_exists('UTM_CAMPAIGN', $payload['attributes']));
check('brevo payload: utm_source still present', $payload['attributes']['UTM_SOURCE'] === $lead['utm_source']);

[, $leadWithPhone] = validate_lead(validSubmission());
$payloadWithPhone = brevo_payload($leadWithPhone, 42);
check('brevo payload: listIds reflects argument', $payloadWithPhone['listIds'] === [42]);
check('brevo payload: TELEPHONE present when phone given', $payloadWithPhone['attributes']['TELEPHONE'] === $leadWithPhone['phone']);

// --------------------------------------------------------------------------

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
