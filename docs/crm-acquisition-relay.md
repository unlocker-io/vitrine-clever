# Relais MetForm vers le CRM Unlocker

Le MU-plugin `unlkr-acquisition-relay.php` est installé mais inactif par défaut. Il ne concerne qu'un formulaire MetForm dont l'ID est explicitement déclaré. Il s'exécute après la validation, les nonces/captcha et la persistance MetForm; il ne les remplace pas.

## Configuration serveur

Déclarer les variables suivantes dans le gestionnaire de secrets de l'hébergeur (jamais dans Git, le HTML, une URL ou un cookie) :

```text
CRM_ACQUISITION_RELAY_ENABLED
CRM_ACQUISITION_API_URL
CRM_ACQUISITION_ALLOWED_HOSTS
CRM_ACQUISITION_SERVICE_TOKEN
CRM_ACQUISITION_METFORM_FORM_ID
CRM_ACQUISITION_LANDING_KEY
CRM_ACQUISITION_PRIVACY_NOTICE_VERSION
CRM_ACQUISITION_CONSENT_GATE_CONFIRMED
CRM_ACQUISITION_FIELD_EMAIL
CRM_ACQUISITION_FIELD_NAME
CRM_ACQUISITION_FIELD_PHONE
CRM_ACQUISITION_FIELD_BUSINESS_NAME
CRM_ACQUISITION_FIELD_COUNTRY
CRM_ACQUISITION_FIELD_AREA
CRM_ACQUISITION_FIELD_PROPERTY_COUNT_BAND
CRM_ACQUISITION_FIELD_OFFER
CRM_ACQUISITION_FIELD_ADS_MEASUREMENT
CRM_ACQUISITION_FIELD_ADS_SHARING
CRM_ACQUISITION_FIELD_MARKETING_OPT_IN
CRM_ACQUISITION_FIELD_ATTEMPT_TOKEN
```

`CRM_ACQUISITION_API_URL` doit être l'URL HTTPS exacte du chemin `/api/v1/acquisition/requests`, sans query string, identifiants URL ni port autre que 443. Son hôte doit apparaître exactement dans `CRM_ACQUISITION_ALLOWED_HOSTS` (liste séparée par des virgules). Toute configuration manquante ou invalide laisse le relais fermé.

Les clés `CRM_ACQUISITION_FIELD_*` sont les `mf_input_name` vérifiés du seul formulaire retenu. Il doit aussi contenir un champ caché destiné au `CRM_ACQUISITION_FIELD_ATTEMPT_TOKEN`; le plugin y place un UUID v4 opaque, propre à la tentative. `CRM_ACQUISITION_LANDING_KEY` est une valeur serveur autorisée par le CRM, et non une valeur fournie par le navigateur. `CRM_ACQUISITION_PRIVACY_NOTICE_VERSION` est la version effectivement affichée par le formulaire.

`CRM_ACQUISITION_CONSENT_GATE_CONFIRMED` doit valoir exactement `1` après la recette de consentement. C'est un verrou de déploiement manuel, pas une intégration CookieYes.

## Inventaire et activation

Avant d'activer :

1. relever en production l'ID MetForm, les `mf_input_name`, les nonces/captcha et ajouter le champ caché opaque de tentative ;
2. inventorier séparément la bannière CookieYes, le texte/version de notice et les choix de consentement ; ce plugin ne lit pas CookieYes et ne doit pas être déclaré raccordé à CookieYes ;
3. vérifier en recette que les champs consentement MetForm transmettent strictement `granted`, `denied` ou `unknown`, l'opt-in marketing un booléen, et que cette correspondance a l'accord juridique ;
4. seulement alors positionner `CRM_ACQUISITION_CONSENT_GATE_CONFIRMED=1` ;
5. faire valider `CRM_ACQUISITION_LANDING_KEY` dans le registre CRM ;
6. créer un token M2M dédié avec seulement `crm.acquisition.ingest`, stocké côté serveur ;
7. activer le relais, puis tester les réponses CRM 201, 200, 409, 422 et une indisponibilité réseau depuis un environnement non productif.

Le relais n'envoie aucun `fbclid`, `gclid`, UTM, cookie publicitaire, URL de landing complète ni fingerprint. L'attribution consentie relève des endpoints visiteurs du lot C1c/C2b.

## Fiabilité et vie privée

Le navigateur place un UUID v4 opaque dans son champ caché de tentative. Le serveur le résout vers un UUID d'idempotence CRM différent, persisté dans une option WordPress non autoloadée sous un nom hashé : aucune donnée du prospect, cookie, réponse CRM ou identifiant de publicité n'est stocké. L'insertion de cette association repose sur l'unicité atomique de `option_name`, donc deux doubles-soumissions concurrentes portent la même clé C1b. Une réponse C1b 409 ou 422 fait tourner atomiquement l'UUID CRM associé, afin qu'une correction ultérieure ne soit jamais bloquée par un conflit permanent.

Une requête utilise le même UUID durant son unique tentative supplémentaire (uniquement timeout/réseau, 429 ou 5xx). Les redirections HTTP sont interdites, TLS est vérifié, la primitive HTTP sûre WordPress refuse les URL non sûres et le timeout est de trois secondes. Aucun corps, secret ou PII n'est journalisé. Une indisponibilité CRM produit une erreur neutre dans la réponse REST ciblée MetForm : le formulaire ne prétend jamais que le CRM a reçu le prospect.
