# Continuité d’acquisition consentie (C2d)

Le MU-plugin charge `unlkr-acquisition-continuity.js` uniquement lorsque la feature C2d est explicitement activée et que toute sa configuration est valide. C2d est indépendant du relay MetForm C2a : il ne modifie ni le formulaire, ni Site Kit/GTM, ni les intégrations historiques, et n’active aucun pixel ou flux publicitaire.

## Configuration

Toutes les valeurs sont des variables serveur ; aucune n’est un secret :

```text
CRM_ACQUISITION_CONTINUITY_ENABLED=false
CRM_ACQUISITION_WEB_PREFERENCES_URL=https://crm.unlkr.io/acquisition-web/preferences
CRM_ACQUISITION_WEB_ALLOWED_HOSTS=crm.unlkr.io
CRM_ACQUISITION_SITE_KEY=unlocker-web
CRM_ACQUISITION_CONTINUITY_NOTICE_VERSION=<version réellement affichée>
CRM_ACQUISITION_CONTINUITY_APP_ORIGINS=https://app.unlocker.io
CRM_ACQUISITION_CONTINUITY_TTL_SECONDS=300
CRM_ACQUISITION_CONTINUITY_TIMEOUT_MS=3000
CRM_ACQUISITION_CONTINUITY_RETRIES=1
```

La feature est OFF par défaut. L’URL CRM doit être HTTPS, viser exactement `/acquisition-web/preferences`, sans query string, fragment, identifiants ni port non standard, et son hôte doit figurer dans `CRM_ACQUISITION_WEB_ALLOWED_HOSTS`. Les origines app sont des origines HTTPS exactes séparées par des virgules, sans chemin. Le TTL local est compris entre 60 et 1 800 secondes (300 par défaut), le timeout entre 500 et 10 000 ms, et le nombre de retries entre 0 et 2. Toute valeur absente ou hors borne laisse C2d fermé.

## CookieYes et retrait

Le producteur écoute les événements officiels `cookieyes_banner_load` et `cookieyes_consent_update`, et relit `getCkyConsent()` si CookieYes était déjà prêt à son initialisation. La catégorie CookieYes `advertisement` doit être explicitement vraie/acceptée avant le premier appel CRM. `unknown`, `denied`, un événement mal formé, le retrait de cette catégorie, l’expiration et une origine de transport invalide suppriment ou rendent immédiatement inutilisable la continuité locale. Un retrait après création envoie en arrière-plan une préférence `revoked` au CRM avec le handle et le reçu existants, puis reste supprimé même si cet appel échoue.

Les événements documentés par CookieYes ne sont disponibles dans son plugin WordPress que lorsque celui-ci est connecté à la Web App CookieYes. Avant activation, vérifier en navigateur non-prod les deux formats : `detail.categories.advertisement` au chargement et les tableaux `detail.accepted` / `detail.rejected` lors d’une mise à jour. Sans preuve de ces événements, laisser `CRM_ACQUISITION_CONTINUITY_ENABLED=false`.

## Données, durée et fiabilité

Après consentement, le navigateur appelle C2b sans bearer et sans credentials navigateur. Le CRM crée lui-même `visitor_handle` (`av1_…`) et `consent_receipt` (`acr1_…`). Le producteur valide ces formats et `expires_at`, génère un UUID v4 `identify_attempt_id`, puis conserve en `sessionStorage` uniquement :

```json
{
  "schema_version": 1,
  "site_key": "unlocker-web",
  "visitor_handle": "av1_<opaque>",
  "consent_receipt": "acr1_<opaque>",
  "identify_attempt_id": "<uuid-v4>"
}
```

L’UUID reste stable pendant la durée de vie et les retries du payload. L’échéance effective est le minimum entre l’expiration CRM et le TTL local. Un timer supprime l’enregistrement ; chaque lecture revérifie aussi l’échéance, afin qu’un timer suspendu ne rende jamais un payload expiré utilisable. `sessionStorage` borne l’enregistrement au contexte de navigation courant (un nouvel onglet même origine ouvert avec un `opener` peut en recevoir une copie initiale selon le navigateur) ; la consommation unique, le retrait et les contrôles d’origine restent donc obligatoires. Ce stockage ne remplace pas le logout/retrait C2c, qui doit également vider toute copie en mémoire.

Les appels CRM ne bloquent aucun clic, formulaire ou navigation. Seuls les erreurs réseau, 429 et 5xx sont rejoués, dans la limite configurée. Aucun échec n’est affiché ni journalisé. Le payload, un token produit, une identité, une URL de page, un identifiant publicitaire ou une donnée personnelle ne sont jamais placés dans une URL, un cookie, `dataLayer` ou un log.

Le contrat OpenAPI C2b version 1 décrit les entrées mais pas encore le schéma JSON de la réponse de `/preferences`. C2d se limite au comportement livré par C2b : réponses 200/201 avec `visitor_handle`, `consent_receipt` et `expires_at`. Une réponse absente, mal formée ou expirée est rejetée localement ; aucun handle/reçu n’est inventé.

## Handshake exact-origin pour C2c

C2c doit conserver une relation de fenêtre avec la page publique productrice (typiquement `window.opener`) ; aucun contexte n’est encodé dans l’URL. Après avoir installé son listener, il envoie à l’origine publique exacte :

```json
{
  "type": "unlkr:continuity:request",
  "schema_version": 1,
  "request_id": "<uuid-v4 C2c>"
}
```

Le producteur vérifie l’origine appelante par égalité stricte avec l’allowlist, la forme exacte du message et la source de fenêtre. S’il existe un payload consenti non expiré, il le supprime **avant** de répondre une seule fois, avec `postMessage(..., event.origin)` :

```json
{
  "type": "unlkr:continuity:response",
  "schema_version": 1,
  "request_id": "<même uuid-v4>",
  "payload": { "schema_version": 1, "site_key": "…", "visitor_handle": "…", "consent_receipt": "…", "identify_attempt_id": "…" }
}
```

C2c doit vérifier symétriquement l’origine publique exacte, `event.source`, le `request_id`, la version et tous les formats. Il garde le payload seulement en mémoire, utilise le token produit exclusivement dans `X-Unlocker-Access-Token` vers `/acquisition-web/identify`, et réutilise le même `identify_attempt_id` pour les retries bornés d’une tentative. Il détruit sa copie au succès terminal, à l’expiration, au logout, au changement de compte et au retrait. Login, restauration de session et confirmation peuvent déclencher la même tentative idempotente ; aucun de ces parcours ne doit être bloqué par l’absence ou l’échec de continuité.

## CSP et recette

Autoriser au minimum l’asset same-origin dans `script-src 'self'` et l’origine CRM exacte dans `connect-src`. Le transport `postMessage` ne justifie ni `unsafe-inline`, ni wildcard d’origine. Si l’app choisit un iframe plutôt qu’un opener, la politique `frame-ancestors` du site public doit nommer explicitement les origines app ; l’opener reste le chemin recommandé.

Avant activation non-prod : contrôler consentement initial, refus, retrait, événement mal formé/désordonné, CRM indisponible, TTL, replay, second onglet/profil, origine refusée et consommation unique. Vérifier dans Network/Application/Console qu’aucun token, PII, URL, cookie publicitaire ou payload ne fuit. Les gates automatiques sont `composer test && npm test`.
