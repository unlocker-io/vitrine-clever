# Touches d’acquisition consenties (C2c)

Le MU-plugin charge `unlkr-acquisition-touches.js` uniquement lorsque la feature C2c est
explicitement activée et que toute sa configuration est valide. C2c est le premier — et à ce
jour le seul — producteur qui appelle `POST /acquisition-web/touches`. Il est indépendant du
relais MetForm C2a et de la continuité C2d : il ne modifie ni le formulaire, ni le handshake
`postMessage` vers l’app, et n’active aucun pixel ni flux publicitaire tiers. Sa seule
responsabilité est de transmettre, sous consentement, le signal UTM/click-id porté par l’URL
d’atterrissage.

## Différence avec C2a et C2d

- **C2a (relais MetForm)** transmet un formulaire rempli vers `/api/v1/acquisition/requests`,
  sans aucun UTM ni click-id.
- **C2d (continuité)** mint un `visitor_handle`/`consent_receipt` via `/acquisition-web/preferences`
  pour les transmettre à l’app par `postMessage`, sans jamais appeler `/touches`.
- **C2c (ce document)** mint également un `visitor_handle`/`consent_receipt` via
  `/acquisition-web/preferences`, mais les utilise pour envoyer un ou plusieurs « touches »
  d’attribution à `/acquisition-web/touches`, à chaque atterrissage porteur d’un paramètre de
  campagne reconnu.

## Configuration

Toutes les valeurs sont des variables serveur ; aucune n’est un secret.

### Nouvelles (propres à C2c)

```text
CRM_ACQUISITION_TOUCHES_ENABLED=false
CRM_ACQUISITION_WEB_TOUCHES_URL=https://crm.unlkr.io/acquisition-web/touches
CRM_ACQUISITION_TOUCHES_NOTICE_VERSION=<version réellement affichée>
CRM_ACQUISITION_TOUCHES_LANDING_KEY=<valeur enregistrée côté CRM>
CRM_ACQUISITION_TOUCHES_TTL_SECONDS=300
CRM_ACQUISITION_TOUCHES_TIMEOUT_MS=3000
CRM_ACQUISITION_TOUCHES_RETRIES=1
```

### Réutilisées telles quelles (déjà déclarées par C2d)

```text
CRM_ACQUISITION_WEB_PREFERENCES_URL=https://crm.unlkr.io/acquisition-web/preferences
CRM_ACQUISITION_WEB_ALLOWED_HOSTS=crm.unlkr.io
CRM_ACQUISITION_SITE_KEY=unlocker-web
```

`CRM_ACQUISITION_WEB_TOUCHES_URL` doit être HTTPS, viser exactement `/acquisition-web/touches`,
sans query string, fragment, identifiants ni port non standard, et son hôte doit figurer dans
`CRM_ACQUISITION_WEB_ALLOWED_HOSTS` — le même allow-list d’hôtes que celui déjà utilisé pour
l’URL de préférences. Le TTL local est compris entre 60 et 1 800 secondes (300 par défaut), le
timeout entre 500 et 10 000 ms, et le nombre de retries entre 0 et 2. `notice_version` et
`landing_key` sont non vides et ≤ 64 caractères. Toute valeur absente ou hors borne laisse C2c
fermé.

**Deux valeurs exigent une confirmation côté CRM avant activation, et ne sont pas créées par ce
dépôt :**

- `CRM_ACQUISITION_TOUCHES_LANDING_KEY` doit être enregistrée dans la config CRM
  `crm.acquisition.landing_keys` — une valeur non enregistrée fait rejeter toute requête par le
  serveur (412, `TouchController`), même si la configuration WordPress locale est par ailleurs
  valide.
- L’origine de ce site doit être couverte par la config CRM `crm.acquisition.sites` pour le
  `site_key` déclaré. `unlocker.io` et `www.unlocker.io` apparaissent aujourd’hui dans le
  `CRM_ACQUISITION_WEB_ORIGINS` par défaut du CRM pour `site_key=unlocker-web` — **à confirmer
  dans l’environnement de production réel avant activation**, ce document ne peut pas le
  vérifier depuis ce dépôt.

## Chargement de l’asset sur les landings, et `landing_key` par gabarit

**Correctif de la liste blanche.** `dequeue_foreign_assets()` (dans
`unlocker-landings/inc/assets.php`) désenfile, sur les trois gabarits de landing (split,
délégation, démarrer), tout script et style dont le `src` ne matche pas une liste blanche
fermée (`has_allowed_src()`). Cette liste ne couvrait jusqu’ici que quatre plugins tiers
(CookieYes, PixelYourSite, Site Kit, Yoast) : `unlkr-acquisition-touches.js` — et, avec lui,
les autres producteurs `unlkr-acquisition-*` (relais C2a, continuité C2d) — en étaient exclu et
donc systématiquement désenfilé sur les trois landings, alors même que sa méta-config
`<meta name="unlkr-acquisition-touches" …>` était bien imprimée par le relais (l’enfilement et
l’injection de la méta-config sont deux mécanismes indépendants). Le correctif ajoute la
needle `/mu-plugins/unlkr-acquisition-` à `$needles`, ce qui couvre par construction les trois
fichiers servis depuis `.../app/mu-plugins/` sur les trois gabarits. La dépendance
(`enqueue_touches_producer()` enfile `unlkr-acquisition-touches` sans aucune dépendance de
script) garantit par ailleurs qu’il ne peut jamais être bloqué en cascade par le désenfilement
d’un autre script.

**`landing_key` propre aux deux landings publicitaires.** `touches_configuration()` lit la
valeur globale `CRM_ACQUISITION_TOUCHES_LANDING_KEY`, puis la fait passer par un filtre
WordPress générique, `unlkr_acquisition_touches_landing_key` — le relais ne connaît rien de
`unlocker-landings`, il expose juste un point d’extension. `unlocker-landings` y accroche
`touches_landing_key_override()` (dans `inc/assets.php`) qui remplace cette valeur par
`mountain_split` sur `/split-de-paiement-conciergerie/` et par `mountain_delegation` sur
`/delegation-carte-g-location-saisonniere/`. Partout ailleurs — y compris `/demarrer/` et le
reste du site — la valeur globale `CRM_ACQUISITION_TOUCHES_LANDING_KEY` traverse le filtre
inchangée. Comme rappelé plus haut, `mountain_split` et `mountain_delegation` doivent être
enregistrées côté CRM dans `crm.acquisition.landing_keys` avant toute activation sur ces deux
landings, sous peine du même 412 ; la valeur globale existante n’est pas concernée par ce
risque si elle y figure déjà.

## Consentement (CookieYes)

Le producteur réutilise exactement le mécanisme de C2d : les événements officiels
`cookieyes_banner_load` et `cookieyes_consent_update`, et une relecture de `getCkyConsent()` si
CookieYes était déjà prêt à son initialisation. La catégorie `advertisement` doit être
explicitement vraie (`isUserActionCompleted: true` et `categories.advertisement === true`) avant
tout appel CRM — un touch d’attribution publicitaire est par nature une mesure publicitaire, la
même catégorie de consentement que celle qui gouverne déjà C2d s’applique donc sans en inventer
une nouvelle. `unknown`, `denied`, le retrait (`revoked`) et un événement mal formé empêchent ou
suppriment tout envoi. Contrairement à C2d, un retrait n’envoie **aucune** notification au CRM :
il n’y a rien à révoquer côté serveur pour un touch déjà transmis (ce n’est pas une session
continue comme un handle applicatif), le retrait se limite donc à effacer les deux enregistrements
locaux (handle et arrivée) et à fermer le producteur.

## Handle/reçu : un magasin distinct de celui de C2d

C2c mint son `visitor_handle`/`consent_receipt` via le même contrat public `/acquisition-web/preferences`
que C2d — même URL, même schéma de requête, mêmes formats `av1_…`/`acr1_…`, même logique de
retry/timeout bornés. C’est une réutilisation du **mécanisme**, pas un second mécanisme.
En revanche l’enregistrement local est conservé sous sa propre clé `sessionStorage`
(`unlkr_acquisition_touches_handle_v1_<site_key>`), distincte de celle de C2d. Raison : le
magasin de C2d est **à usage unique** — il est supprimé dès sa consommation par le handshake
`postMessage` d’identification vers l’app (C2c au sens CRM, `identify`) — alors que le magasin
de touches doit rester utilisable **pendant toute la durée de vie de la page**, pour couvrir
plusieurs atterrissages successifs sans re-solliciter `/preferences` à chaque fois tant que le
handle local n’a pas expiré.

## Paramètres lus et correspondance UNL-4587

Le producteur lit exclusivement `window.location.search` de la page courante et n’en retient que
les clés suivantes :

- `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term` → mappées 1:1 dans
  `campaign_parameters`, après `trim()`, rejetées si vides, tronquées à 256 caractères.
- `utm_id` → `campaign_parameters.campaign_external_id`, même traitement, tronqué à 120
  caractères — cf. UNL-4587 §4 : « le producteur de touches transmet `utm_id` dans
  `campaign_external_id` et les `utm_*` existants ».
- `gclid`, `fbclid`, `gbraid`, `wbraid` → `click_ids`, conservés uniquement s’ils correspondent à
  `^[A-Za-z0-9._~-]+$` et ne dépassent pas 256 caractères ; sinon la clé est **abandonnée**, jamais
  tronquée (un identifiant de clic tronqué n’est plus le vrai identifiant).

Aucun autre paramètre de la query string n’est jamais lu ni transmis — pas d’e-mail, pas de nom,
rien d’autre que ces clés de campagne publicitaire déjà publiques dans l’URL. Si la page
n’expose aucun de ces paramètres reconnus, le producteur ne s’enregistre même pas sur les
événements de consentement : il n’y a rien à mesurer.

**Limite connue :** `ad_external_id` n’est jamais renseigné. Il n’existe aujourd’hui aucune source
définie pour cette valeur dans la query string de ce site WordPress ; ce champ du contrat CRM
reste donc systématiquement absent côté C2c, sans qu’il faille en inventer une.

## Stabilité et idempotence de `external_touch_id`

Chaque atterrissage produit une clé déterministe (`arrivalKey`) à partir de l’union triée des
`campaign_parameters` et `click_ids` reconnus sur cette page. Un enregistrement
`sessionStorage` distinct (`unlkr_acquisition_touches_arrival_v1_<site_key>`) associe cette clé à
un `external_touch_id` (`awt1_<uuid v4>`) et à un indicateur `sent`. Un rechargement de la même
page avec les mêmes paramètres reconnus retrouve la même clé : si le touch a déjà été envoyé
(`sent: true`), le producteur ne contacte le CRM pour rien — zéro requête. Un nouvel atterrissage
avec des paramètres reconnus différents change la clé et mint un nouvel identifiant. C’est ce
design, et non un identifiant aléatoire à chaque rechargement, qui évite le 409
`touch_conflict` que provoquerait le renvoi littéral d’un même `external_touch_id` avec un
`occurred_at` différent (toujours « maintenant »).

## Fiabilité et vie privée

Les appels CRM ne bloquent aucune navigation. `/preferences` suit la même logique de retry bornée
que C2d (réseau, 429, 5xx). `/touches` est un envoi « fire-and-forget » : toute réponse HTTP
obtenue — y compris un 409/410/412/413 — est considérée terminale et ne déclenche jamais de
nouvelle tentative sur cette même arrivée ; seul un échec réseau/abort total, sans aucune réponse,
laisse la porte ouverte à un futur rechargement pour réessayer. Aucun échec n’est jamais journalisé
ni affiché. Aucun bearer, cookie publicitaire, `dataLayer`, e-mail, nom ou donnée personnelle n’est
jamais placé dans une requête ; chaque appel utilise `credentials: 'omit'`.

## Déploiement : activation toujours explicite

La feature est **OFF par défaut** (`CRM_ACQUISITION_TOUCHES_ENABLED=false`). L’activation
WordPress de C2c — poser `CRM_ACQUISITION_TOUCHES_ENABLED=1` en production ou en staging — doit
**toujours** être un geste d’opérateur explicite et délibéré, jamais un effet de bord implicite
d’un merge ou d’un déploiement de ce code. Merger cette PR ne doit activer aucun comportement tant
que cet interrupteur n’a pas été positionné à la main, après vérification du registre CRM
(`landing_key` et origine du site).

## Amendement 4 (UNL-4643) : décorateur de lien de continuité par fragment

Spec complète : `sacred-book/developers/platform/unlocker-internal/specs/2026-09-25--UNL-4643--crm-acquisition-amendement-4-continuite-fragment.md`.

Le handshake `postMessage` de C2d (ci-dessus, `docs/crm-acquisition-continuity.md`) suppose
un `window.opener` : mesuré en prod le 25/09, tous les liens du site vers l'app s'ouvrent dans
le **même onglet**, avec `rel="noreferrer"` — l'app n'a donc jamais de `window.opener`, et le
handshake n'aboutit jamais (0 lien d'identité). L'amendement 4 ajoute un second mécanisme, qui
prévaut désormais sur le `postMessage` : au clic sur un lien vers l'app, le site ajoute
directement le couple dans le fragment de l'URL de navigation.

**Pourquoi dans ce fichier plutôt que dans celui de C2d.** Ce décorateur vit dans
`unlkr-acquisition-touches.js` — actif en prod, sans dépendre de l'activation de C2d (qui reste
`false` en prod aujourd'hui) — et non dans un nouveau fichier : c'est le seul producteur déjà
chargé partout où un lien vers l'app peut apparaître, et il expose déjà `readHandle()`, le
mécanisme de consentement CookieYes et les formats `av1_…`/`acr1_…` que le décorateur réutilise
tels quels.

**Comportement.** Une délégation d'événement `click` **et** `auxclick` (clic milieu, ouverture
en nouvel onglet) en phase de capture sur `document` — jamais une réécriture du DOM au
chargement, ce qui couvre aussi un lien injecté tardivement dans la page. Au clic, si le lien
résolu (`event.target.closest('a[href]')`) a pour origine **exactement** une entrée de la liste
configurée (égalité stricte, aucun joker, aucune correspondance par préfixe/suffixe — c'est le
point que la suite de tests mute pour le prouver), le producteur :

1. relit le consentement courant via `getCkyConsent()` (même mécanisme que le reste de ce
   fichier) — sans couple validé, rien ne change ;
2. cherche un couple `visitor_handle`/`consent_receipt` valide et non expiré, d'abord dans son
   propre magasin C2c (`readHandle()`, `unlkr_acquisition_touches_handle_v1_<site_key>`), puis,
   à défaut, dans le magasin C2d (`unlkr_acquisition_continuity_v1_<site_key>`) — lu, jamais
   consommé ni supprimé ici : la consommation unique de C2d reste le rôle exclusif de son propre
   handshake `postMessage` ;
3. ajoute `ul_ch=<visitor_handle>.<consent_receipt>` au fragment de l'URL, en remplaçant une
   entrée `ul_ch` déjà présente et en conservant toutes les autres. `rel="noreferrer"` n'empêche
   pas cette opération : un fragment reste dans l'URL de navigation, il n'est jamais envoyé au
   serveur ni au `Referer`. Les autres attributs de l'ancre (`rel`, `target`, …) ne sont jamais
   modifiés.

Sans consentement, sans couple valide, ou pour toute autre origine, le lien reste
**byte-identique**.

**Configuration** (sous-clé de la configuration touches ci-dessus, silencieuse si la valeur est
absente ou hors borne) :

```text
CRM_ACQUISITION_TOUCHES_LINK_DECORATOR_ENABLED=true
CRM_ACQUISITION_TOUCHES_LINK_DECORATOR_APP_ORIGINS=https://app.unlocker.io
```

`LINK_DECORATOR_ENABLED` est **actif par défaut dès que le producteur touches lui-même est
actif et valide** (absence de variable ⇒ `true`) ; le mettre à `0`/`false` désactive uniquement
le décorateur, sans toucher au reste du producteur. `LINK_DECORATOR_APP_ORIGINS` est une liste
explicite d'origines HTTPS exactes séparées par des virgules (défaut `https://app.unlocker.io`
si absente) — jamais de joker, comparaison par égalité stricte uniquement. Une entrée non
canonique (chemin, query, fragment, identifiants, scéma non HTTPS) invalide la liste entière et
désactive uniquement le décorateur ; la configuration touches globale (mint de handle, envoi des
touches) n'est jamais affectée par une configuration de décorateur absente ou invalide.

**Liens concernés sur ce site** (mesurés le 25/09 sur `unlocker.io/`, `/carte-t/` et
`/demarrer/?offre=carte-t&parcours=demo`) : le header Elementor (« Connexion » /
« S'inscrire », `href="https://app.unlocker.io/login?"` / `.../register?"`, contenu en base
Elementor, hors de ce dépôt), les CTA des pages Ads (`inc/ads-landings.php`, réécrits vers
`/demarrer/` par un Product decision antérieur — ils ne pointent donc plus vers l'app), et le
bouton de succès `/demarrer/` (`templates/template-demarrer.php`,
`href="https://app.unlocker.io/register" rel="noreferrer"`). Le décorateur ne fait aucune
hypothèse sur la structure de ces pages : il ne dépend que de l'origine du lien au moment du
clic.

## Gates avant PR

```bash
rtk npm test
rtk docker run --rm -v "$(pwd)":/app -w /app php:8.3-cli php tests/unlkr-acquisition-relay-test.php
```

Aucun local PHP n’est installé sur ce VPS : le harnais PHP se vérifie uniquement via Docker,
comme documenté ci-dessus. Le harnais DOM et le test de contrat CRM tournent avec le runtime
Node déclaré dans `package.json` (`Node >=20 <25`) : `rtk npm test` exécute `test:relay-dom`,
`test:continuity-dom`, `test:touches-dom`, `test:touches-contract` et `test:link-decorator-dom`
(amendement 4, UNL-4643), entre autres suites DOM du dépôt. Le dépôt ne contient
actuellement aucun workflow CI : tant qu’un runner n’automatise pas ces deux commandes, leurs
résultats doivent être consignés manuellement dans la PR.
