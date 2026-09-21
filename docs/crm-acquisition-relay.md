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
```

`CRM_ACQUISITION_API_URL` doit être l'URL HTTPS exacte du chemin `/api/v1/acquisition/requests`. Son hôte doit apparaître exactement dans `CRM_ACQUISITION_ALLOWED_HOSTS` (liste séparée par des virgules). Toute configuration manquante ou invalide laisse le relais fermé.

Les clés `CRM_ACQUISITION_FIELD_*` sont les `mf_input_name` vérifiés du seul formulaire retenu. `CRM_ACQUISITION_LANDING_KEY` est une valeur serveur autorisée par le CRM, et non une valeur fournie par le navigateur. `CRM_ACQUISITION_PRIVACY_NOTICE_VERSION` est la version effectivement affichée par le formulaire.

## Inventaire et activation

Avant d'activer :

1. relever en production l'ID MetForm, les `mf_input_name`, les nonces/captcha, le texte/version de notice et les choix CookieYes correspondants ;
2. vérifier que les champs consentement transmettent strictement `granted`, `denied` ou `unknown`, et l'opt-in marketing un booléen ;
3. faire valider `CRM_ACQUISITION_LANDING_KEY` dans le registre CRM ;
4. créer un token M2M dédié avec seulement `crm.acquisition.ingest`, stocké côté serveur ;
5. activer le relais, puis tester les réponses CRM 201, 200, 409 et une indisponibilité réseau depuis un environnement non productif.

Le relais n'envoie aucun `fbclid`, `gclid`, UTM, cookie publicitaire, URL de landing complète ni fingerprint. L'attribution consentie relève des endpoints visiteurs du lot C1c/C2b.

## Fiabilité et vie privée

Une requête utilise un UUID stable durant son unique tentative supplémentaire (uniquement timeout/réseau, 429 ou 5xx). Si MetForm fournit un identifiant d'entrée, un transient de 15 minutes associe seulement un hash de cet identifiant à cet UUID; aucune donnée du prospect ni réponse CRM n'est persistée. Un conflit 409 efface cette association afin qu'une correction soit soumise avec une nouvelle clé.

Les redirections HTTP sont interdites, le timeout est de trois secondes et aucun corps, secret ou PII n'est journalisé. Une indisponibilité CRM produit une erreur neutre dans la réponse REST ciblée MetForm : le formulaire ne prétend jamais que le CRM a reçu le prospect.
