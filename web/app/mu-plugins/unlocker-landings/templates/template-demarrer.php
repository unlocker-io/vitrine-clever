<?php

/**
 * "Démarrer" form landing -- markup transcribed verbatim from the design
 * mockup body (see .exploration-landings.md), served through
 * theme_page_templates + template_include (inc/templates.php). Brevo wiring
 * is a separate lot: this template keeps flow.js's preview-only behaviour.
 *
 * @package UnlockerLandings
 */

namespace Unlocker\Landings;

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('ul-form-page'); ?>>
<?php wp_body_open(); ?><header class="ul-header ul-container "><a href="<?php echo esc_url(home_url('/')); ?>"><img class="ul-logo" src="<?php echo esc_url(asset_url('logo.svg')); ?>" alt="Unlocker" width="168" height="25"></a><span class="ul-fine">Campagne montagne</span></header><main class="ul-container ul-form-layout"><div class="ul-form-side"><a id="ul-back" class="ul-link" href="<?php echo esc_url(split_url()); ?>">← Retour à l’offre</a><p class="ul-kicker" data-ul-offer>Le split de paiement</p><h1 id="ul-intent">Votre prochaine saison commence ici.</h1><p>Quelques informations pour orienter votre parcours. Vous pourrez ensuite démarrer en ligne ou choisir un échange avec notre équipe.</p><p class="ul-callout">Votre pays et votre activité seront étudiés pendant l’onboarding, avant toute confirmation d’éligibilité.</p></div><div class="ul-form-card"><form id="ul-lead-form"><div class="ul-form-marker"><span>1</span> Votre conciergerie</div><h2 style="margin-top:22px">Faisons connaissance.</h2><p class="ul-fine">Tous les champs sont requis, sauf le téléphone.</p><div class="ul-fields"><div class="ul-field ul-field--wide"><label for="ul-email">Email professionnel</label><input id="ul-email" type="email" autocomplete="email" required placeholder="vous@conciergerie.fr" maxlength="254"></div><div class="ul-field ul-field--wide"><label for="ul-company">Nom de la conciergerie</label><input id="ul-company" autocomplete="organization" required maxlength="160" placeholder="Votre conciergerie"></div><div class="ul-field"><label for="ul-country">Pays d’activité</label><input id="ul-country" autocomplete="country-name" required maxlength="100" placeholder="Ex. France"></div><div class="ul-field"><label for="ul-area">Secteur ou station</label><input id="ul-area" required maxlength="160" placeholder="Ex. Chamonix, vallée de l’Arve"></div><div class="ul-field"><label for="ul-size">Nombre de logements</label><select id="ul-size" required><option value="">Sélectionnez</option><option>1–9</option><option>10–24</option><option>25–49</option><option>50–99</option><option>100+</option></select></div><div class="ul-field"><label for="ul-phone">Téléphone <small>(facultatif)</small></label><input id="ul-phone" type="tel" autocomplete="tel" maxlength="40" placeholder="Votre numéro"></div></div><button class="ul-btn" type="submit" disabled>Continuer</button><noscript><p>Activez JavaScript pour envoyer ce formulaire.</p></noscript><p class="ul-fine">Vos informations servent uniquement à vous recontacter au sujet de votre demande. Consultez la <a href="https://unlocker.io/politique-de-confidentialite/">politique de confidentialité Unlocker</a>.</p></form><section id="ul-next" hidden aria-labelledby="ul-next-title"><div class="ul-check" aria-hidden="true">↗</div><p class="ul-kicker">La suite du parcours</p><h2 id="ul-next-title" tabindex="-1">C’est noté. Voici la suite.</h2><p id="ul-next-intro">Créez votre compte Unlocker, choisissez votre offre et complétez votre onboarding en ligne. Votre éligibilité sera examinée pendant ce parcours.</p><a class="ul-btn" href="https://app.unlocker.io/register" rel="noreferrer">Créer mon compte et démarrer</a><a class="ul-btn ul-btn--outline" href="https://meet.brevo.com/enzo-bortone/presentation-unlocker" rel="noreferrer">Réserver une démo</a><button id="ul-edit" class="ul-edit" type="button">← Modifier mes informations</button></section></div></main><footer class="ul-footer"><div class="ul-container"><img class="ul-logo" src="<?php echo esc_url(asset_url('logo.svg')); ?>" alt="Unlocker" width="124" height="20"><div class="ul-footer-links"><span>© Unlocker</span><a href="https://unlocker.io/politique-de-confidentialite/">Confidentialité</a><a href="https://unlocker.io/mentions-legales/">Mentions légales</a><a href="mailto:contact@unlocker.io">Contacter Unlocker</a></div></div></footer>
<?php wp_footer(); ?>
</body>
</html>
