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
CRM_ACQUISITION_ATTEMPT_RETENTION_DAYS
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

`CRM_ACQUISITION_CONSENT_GATE_CONFIRMED` doit valoir exactement `1` après la recette de consentement. C'est un verrou de déploiement manuel, pas une intégration CookieYes. `CRM_ACQUISITION_ATTEMPT_RETENTION_DAYS` est optionnelle, vaut 30 jours par défaut et est bornée entre 1 et 90 jours : elle concerne la table technique d’idempotence opaque ainsi que son identifiant first-party navigateur/formulaire.

## Inventaire et activation

Avant d'activer :

1. relever en production l'ID MetForm, les `mf_input_name`, les nonces/captcha et ajouter le champ caché opaque de tentative ;
2. inventorier séparément la bannière CookieYes, le texte/version de notice et les choix de consentement ; qualifier avec le juridique le stockage first-party technique d’idempotence (UUID opaque, sans finalité pub ni analytique) et ce plugin ne doit pas être déclaré raccordé à CookieYes ;
3. vérifier en recette que les champs consentement MetForm transmettent strictement `granted`, `denied` ou `unknown`, l'opt-in marketing un booléen, et que cette correspondance a l'accord juridique ;
4. seulement alors positionner `CRM_ACQUISITION_CONSENT_GATE_CONFIRMED=1` ;
5. faire valider `CRM_ACQUISITION_LANDING_KEY` dans le registre CRM ;
6. créer un token M2M dédié avec seulement `crm.acquisition.ingest`, stocké côté serveur ;
7. vérifier que WP-Cron est réellement exécuté par l’hébergeur (ce projet désactive le cron déclenché par requête) et constater l’événement quotidien `unlkr_acquisition_relay_purge` ;
8. autoriser l’asset same-origin `/app/mu-plugins/unlkr-acquisition-relay.js` dans la CSP `script-src`, puis vérifier le remplissage et le reset du champ opaque ; si cette règle CSP ou l’exécution WP-Cron ne sont pas démontrées, laisser `CRM_ACQUISITION_RELAY_ENABLED` désactivé ;
9. activer le relais, puis tester les réponses CRM 201, 200, 409, 422 et une indisponibilité réseau depuis un environnement non productif.

Le relais n'envoie aucun `fbclid`, `gclid`, UTM, cookie publicitaire, URL de landing complète ni fingerprint. L'attribution consentie relève des endpoints visiteurs du lot C1c/C2b.

## Fiabilité et vie privée

Le navigateur place un UUID v4 opaque dans son champ caché de tentative. Il est aussi conservé en stockage first-party, sous une clé versionnée limitée au formulaire configuré, avec le même TTL que le mapping serveur : rechargement, nouvelle visite et `reset` d’un corps identique portent donc la même clé C1b et ne recréent ni demande, ni lead, ni tâche. Le stockage contient uniquement `{v,id,expires_at}` ; il n’inclut aucune donnée de prospect, URL, cookie publicitaire ou consentement. Le bootstrap est un asset same-origin enregistré par WordPress, sans JavaScript inline : MetForm pouvant remplir son wrapper après le chargement de page, il observe ce rendu asynchrone pendant dix secondes au plus, se débranche dès que le champ est trouvé et reste fermé si Web Crypto ou le stockage first-party ne sont pas disponibles. Un listener `reset` ciblé sur ce seul formulaire réinsère la même tentative stable. Le serveur la résout vers un UUID d'idempotence CRM différent, persisté avec ses dates de création et d'expiration dans une option WordPress non autoloadée sous un nom hashé. L’insertion est un `INSERT IGNORE` MySQL/MariaDB non-écrasant, et non `add_option()` (qui UPSERT dans WordPress 6.9) : deux doubles-soumissions concurrentes portent donc la même clé C1b. Une réponse C1b 409 ou 422 fait tourner atomiquement l'UUID CRM associé sans tourner l’UUID navigateur : un corps modifié peut ainsi être renvoyé avec la nouvelle clé serveur après correction, sans conflit permanent.

La conservation de ce mapping est technique et bornée par `CRM_ACQUISITION_ATTEMPT_RETENTION_DAYS` (30 jours par défaut), indépendamment de la conservation CRM du prospect. Sous relais et gate de consentement valides, WordPress planifie une tâche WP-Cron quotidienne, idempotente, qui lit et parse au plus 500 mappings en PHP : elle ne dépend d’aucune fonction JSON SQL et purge aussi les valeurs invalides. Un curseur technique d’`option_id`, sans donnée de prospect, mémorise la progression ; une page complète programme une unique continuation une minute plus tard, jusqu’à atteindre une page partielle. Ainsi un volume supérieur à 500 mappings ne bloque pas indéfiniment les expirés ou valeurs invalides plus récents, sans transformer une exécution en traitement non borné. Le verrou porte un timestamp et un jeton opaque ; un bail orphelin n’est repris qu’après cinq minutes. Quand le relais ou le gate de consentement est désactivé, les tâches quotidienne et de continuation sont désinscrites et n’exécutent aucune purge. Un mapping expiré est remplacé par une nouvelle tentative par comparaison atomique : une course ne peut jamais supprimer une clé fraîche d’un autre worker.

Une requête utilise le même UUID durant son unique tentative supplémentaire (uniquement timeout/réseau, 429 ou 5xx). Les redirections HTTP sont interdites, TLS est vérifié, la primitive HTTP sûre WordPress refuse les URL non sûres et le timeout est de trois secondes. Aucun corps, secret ou PII n'est journalisé. Une indisponibilité CRM produit une erreur neutre dans la réponse REST ciblée MetForm : le formulaire ne prétend jamais que le CRM a reçu le prospect.

Les harnais PHP et DOM du relais font partie de `composer test` via `@relay-tests`; ils restent autonomes et ne contactent ni WordPress réel, ni CRM, ni compte tiers.
