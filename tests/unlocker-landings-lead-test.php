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
use function Unlocker\Landings\crm_payload;
use function Unlocker\Landings\validate_lead;
use function Unlocker\Landings\lead_client_ip;

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
        'country' => 'FR',
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

// -- country allow-list -------------------------------------------------

[$errors, ] = validate_lead(validSubmission(['country' => '']));
check('missing country: error code', ($errors['country'] ?? null) === 'required');

[$errors, $lead] = validate_lead(validSubmission(['country' => 'France']));
check('invalid country (free text): error code', ($errors['country'] ?? null) === 'invalid_choice');
check('invalid country: not in lead', !isset($lead['country']));

[$errors, ] = validate_lead(validSubmission(['country' => 'fr']));
check('invalid country (lowercase code): error code', ($errors['country'] ?? null) === 'invalid_choice');

foreach (['FR', 'CH', 'BE', 'IT', 'ES', 'AD', 'AT', 'ZZ'] as $country) {
    [$errors, $lead] = validate_lead(validSubmission(['country' => $country]));
    check("country option '{$country}' is accepted", !isset($errors['country']) && ($lead['country'] ?? null) === $country);
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

// -- brevo_payload: PAYS receives the human label, never the ISO code -------

[, $leadFr] = validate_lead(validSubmission(['country' => 'FR']));
check('brevo payload: PAYS is the French label, not the code', brevo_payload($leadFr, 117)['attributes']['PAYS'] === 'France');

[, $leadZz] = validate_lead(validSubmission(['country' => 'ZZ']));
check('brevo payload: PAYS for ZZ is "Autre pays"', brevo_payload($leadZz, 117)['attributes']['PAYS'] === 'Autre pays');

// -- lead_client_ip: proxy X-Forwarded-For handling --------------------------

check('client_ip: uses last X-Forwarded-For when multiple', lead_client_ip(['HTTP_X_FORWARDED_FOR' => '1.1.1.1, 203.0.113.9', 'REMOTE_ADDR' => '10.0.0.1']) === '203.0.113.9');
check('client_ip: uses X-Forwarded-For when single', lead_client_ip(['HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => '10.0.0.1']) === '203.0.113.9');
check('client_ip: falls back to REMOTE_ADDR on invalid X-Forwarded-For', lead_client_ip(['HTTP_X_FORWARDED_FOR' => 'garbage', 'REMOTE_ADDR' => '10.0.0.1']) === '10.0.0.1');
check('client_ip: uses REMOTE_ADDR when no X-Forwarded-For', lead_client_ip(['REMOTE_ADDR' => '10.0.0.1']) === '10.0.0.1');

// -- consent_ads: never rejected, defaults to 'unknown' ----------------------

[$errors, $lead] = validate_lead(validSubmission([]));
check('consent_ads absent: no error', !isset($errors['consent_ads']));
check('consent_ads absent: defaults to unknown', $lead['consent_ads'] === 'unknown');

[$errors, $lead] = validate_lead(validSubmission(['consent_ads' => 'garbage']));
check('consent_ads invalid value: no error', !isset($errors['consent_ads']));
check('consent_ads invalid value: defaults to unknown', $lead['consent_ads'] === 'unknown');

foreach (['granted', 'denied', 'unknown'] as $consent) {
    [, $lead] = validate_lead(validSubmission(['consent_ads' => $consent]));
    check("consent_ads '{$consent}' is kept as-is", $lead['consent_ads'] === $consent);
}

// -- landed_at: optional, silently dropped when implausible ------------------

[$errors, $lead] = validate_lead(validSubmission([]));
check('landed_at absent: no error', !isset($errors['landed_at']));
check('landed_at absent: not in lead', !isset($lead['landed_at']));

$nowIso = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
[$errors, $lead] = validate_lead(validSubmission(['landed_at' => $nowIso]));
check('landed_at just now: no error', !isset($errors['landed_at']));
check('landed_at just now: kept', isset($lead['landed_at']));

[$errors, $lead] = validate_lead(validSubmission(['landed_at' => 'not-a-date']));
check('landed_at unparsable: no error', !isset($errors['landed_at']));
check('landed_at unparsable: dropped', !isset($lead['landed_at']));

$tooFuture = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+10 minutes')->format(DateTimeInterface::ATOM);
[$errors, $lead] = validate_lead(validSubmission(['landed_at' => $tooFuture]));
check('landed_at >5min in the future: no error', !isset($errors['landed_at']));
check('landed_at >5min in the future: dropped', !isset($lead['landed_at']));

$tooOld = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-31 days')->format(DateTimeInterface::ATOM);
[$errors, $lead] = validate_lead(validSubmission(['landed_at' => $tooOld]));
check('landed_at >30 days old: no error', !isset($errors['landed_at']));
check('landed_at >30 days old: dropped', !isset($lead['landed_at']));

// -- crm_payload: mapping, allow-listed keys only, submission_id -------------

function crmLead(array $overrides = []): array
{
    [, $lead] = validate_lead(validSubmission($overrides));

    return $lead;
}

$fixedNow = '2026-09-24T10:00:00+00:00';

// property_count_band: all 5 size options.
$bandExpectations = [
    '1–9' => '1_9',
    '10–24' => '10_49',
    '25–49' => '10_49',
    '50–99' => '50_99',
    '100+' => '100_plus',
];
foreach ($bandExpectations as $size => $expectedBand) {
    $payload = crm_payload(crmLead(['size' => $size]), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
    check("crm_payload: size '{$size}' maps to property_count_band '{$expectedBand}'", $payload['business']['property_count_band'] === $expectedBand);
}

// offer mapping.
check('crm_payload: offer split -> split', crm_payload(crmLead(['offer' => 'split']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow)['offer'] === 'split');
check('crm_payload: offer delegation -> delegation_g', crm_payload(crmLead(['offer' => 'delegation']), 'sub-1', 'mountain_delegation', 'notice-1', $fixedNow)['offer'] === 'delegation_g');

// submission_id passthrough.
$payload = crm_payload(crmLead(), 'a1b2c3d4-e5f6-4789-a123-456789abcdef', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: submission_id equals the argument', $payload['submission_id'] === 'a1b2c3d4-e5f6-4789-a123-456789abcdef');

// phone normalization.
$phoneFr = crm_payload(crmLead(['country' => 'FR', 'phone' => '06 12 34 56 78']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow)['contact']['phone'];
check('crm_payload: FR phone "06 12 34 56 78" -> +33612345678', $phoneFr === '+33612345678');

$phoneCh = crm_payload(crmLead(['country' => 'CH', 'phone' => '0041 79 123 45 67']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow)['contact']['phone'];
check('crm_payload: CH phone "0041 79 123 45 67" -> +41791234567', $phoneCh === '+41791234567');

$phoneInvalid = crm_payload(crmLead(['country' => 'FR', 'phone' => 'abc']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow)['contact']['phone'];
check('crm_payload: unparsable phone "abc" -> null', $phoneInvalid === null);

$phoneAbsent = crm_payload(crmLead(['phone' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow)['contact']['phone'];
check('crm_payload: no phone -> null', $phoneAbsent === null);

// attribution: absent on denied/unknown, present on granted with UTM.
foreach (['denied', 'unknown'] as $consent) {
    $payload = crm_payload(crmLead(['consent_ads' => $consent, 'utm_source' => 'meta']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
    check("crm_payload: no attribution when consent_ads is '{$consent}'", !array_key_exists('attribution', $payload));
}

$grantedWithUtm = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: attribution present when granted with UTM', array_key_exists('attribution', $grantedWithUtm));
check('crm_payload: attribution touch carries utm_source', $grantedWithUtm['attribution']['touches'][0]['utm_source'] === 'meta');
check('crm_payload: attribution touch carries utm_medium', $grantedWithUtm['attribution']['touches'][0]['utm_medium'] === 'cpc');
check('crm_payload: attribution touch has no utm_campaign when absent', !array_key_exists('utm_campaign', $grantedWithUtm['attribution']['touches'][0]));
check('crm_payload: attribution touch occurred_at falls back to $now', $grantedWithUtm['attribution']['touches'][0]['occurred_at'] === $fixedNow);

// attribution: granted but with neither UTM nor click id -> still absent.
$grantedNoTouchData = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => '', 'utm_campaign' => '', 'fbclid' => '', 'gclid' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: no attribution when granted but nothing to report', !array_key_exists('attribution', $grantedNoTouchData));

// click id invalid (contains characters outside the contract's pattern) is discarded.
$grantedInvalidClickId = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => '', 'utm_campaign' => '', 'fbclid' => 'abc def!', 'gclid' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: invalid fbclid alone -> still no attribution', !array_key_exists('attribution', $grantedInvalidClickId));

$grantedValidClickId = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => '', 'utm_campaign' => '', 'fbclid' => 'abc123', 'gclid' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: valid fbclid -> attribution present', array_key_exists('attribution', $grantedValidClickId));
check('crm_payload: valid fbclid kept in click_ids', $grantedValidClickId['attribution']['touches'][0]['click_ids']['fbclid'] === 'abc123');
check('crm_payload: no gclid key when gclid absent', !array_key_exists('gclid', $grantedValidClickId['attribution']['touches'][0]['click_ids']));

// -- crm_payload: campaign_external_id (from utm_id) -------------------------

$grantedWithUtmId = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => '', 'utm_campaign' => '', 'utm_id' => str_repeat('c', 150), 'fbclid' => '', 'gclid' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: utm_id alone is enough to trigger attribution', array_key_exists('attribution', $grantedWithUtmId));
check('crm_payload: campaign_external_id is truncated to 120', $grantedWithUtmId['attribution']['touches'][0]['campaign_external_id'] === str_repeat('c', 120));

$grantedNoUtmId = crm_payload(crmLead(['consent_ads' => 'granted', 'utm_source' => 'meta', 'utm_campaign' => '', 'utm_id' => '', 'fbclid' => '', 'gclid' => '']), 'sub-1', 'mountain_split', 'notice-1', $fixedNow);
check('crm_payload: no campaign_external_id key when utm_id absent', !array_key_exists('campaign_external_id', $grantedNoUtmId['attribution']['touches'][0]));

// -- crm_payload: no key outside the C1b contract ----------------------------

$fullPayload = crm_payload(
    crmLead(['consent_ads' => 'granted', 'utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'winter', 'utm_id' => 'campaign-42', 'fbclid' => 'abc123', 'gclid' => 'xyz789']),
    'sub-1',
    'mountain_split',
    'notice-1',
    $fixedNow
);

$expectedTopKeys = ['schema_version', 'submission_id', 'visitor_handle', 'contact', 'business', 'offer', 'landing_key', 'privacy', 'attribution'];
check('crm_payload: top-level keys match the contract exactly', array_keys($fullPayload) === $expectedTopKeys);

$expectedContactKeys = ['email', 'name', 'phone'];
check('crm_payload: contact keys match the contract exactly', array_keys($fullPayload['contact']) === $expectedContactKeys);

$expectedBusinessKeys = ['name', 'country', 'area', 'property_count_band'];
check('crm_payload: business keys match the contract exactly', array_keys($fullPayload['business']) === $expectedBusinessKeys);

$expectedPrivacyKeys = ['notice_version', 'ads_measurement', 'ads_sharing', 'marketing_opt_in'];
check('crm_payload: privacy keys match the contract exactly', array_keys($fullPayload['privacy']) === $expectedPrivacyKeys);

$expectedTouchKeys = ['occurred_at', 'utm_source', 'utm_medium', 'utm_campaign', 'campaign_external_id', 'click_ids'];
check('crm_payload: touch keys match the contract exactly (no utm_content/utm_term)', array_keys($fullPayload['attribution']['touches'][0]) === $expectedTouchKeys);
check('crm_payload: campaign_external_id carries utm_id', $fullPayload['attribution']['touches'][0]['campaign_external_id'] === 'campaign-42');

$expectedClickIdKeys = ['fbclid', 'gclid'];
check('crm_payload: click_ids keys match the contract exactly', array_keys($fullPayload['attribution']['touches'][0]['click_ids']) === $expectedClickIdKeys);

// --------------------------------------------------------------------------

fwrite(STDOUT, "{$assertions} assertions, {$failures} failures\n");

exit($failures > 0 ? 1 : 0);
