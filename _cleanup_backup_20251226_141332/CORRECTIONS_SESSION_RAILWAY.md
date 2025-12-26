# Corrections Session Railway - Résumé des changements

## Cause probable du problème

Les erreurs 401/302 sur les endpoints `/API/*` étaient causées par :

1. **Cookies de session non envoyés** : Les appels `fetch()` n'utilisaient pas `credentials: 'include'`, donc les cookies de session n'étaient pas envoyés au serveur Railway
2. **SameSite=Lax trop restrictif** : Sur Railway avec HTTPS derrière proxy, `SameSite=Lax` peut bloquer les cookies. Il faut utiliser `SameSite=None` avec `Secure=true`
3. **Redirections HTML au lieu de JSON** : Certains endpoints utilisaient `auth.php` qui fait des redirections HTML (302) au lieu de renvoyer du JSON (401)
4. **Incohérence dans l'initialisation des sessions** : Certains endpoints n'utilisaient pas `initApi()` de manière cohérente

## Fichiers modifiés

### 1. `includes/session_config.php`
- **Changement** : Détection automatique de Railway (proxy) et utilisation de `SameSite=None` si HTTPS derrière proxy
- **Impact** : Les cookies de session fonctionnent correctement sur Railway avec HTTPS

### 2. `includes/api_helpers.php`
- **Changement** : Ajout de logging minimal dans `requireApiAuth()` et création de l'alias `require_login()`
- **Impact** : Logging pour debug prod sans spam, helper standardisé pour tous les endpoints

### 3. Endpoints API corrigés
- `API/chatroom_get_notifications.php` : Remplacement de `auth.php` par `initApi()` + `requireApiAuth()`
- `API/messagerie_get_unread_count.php` : Utilisation de `initApi()` (comportement existant conservé : retourne 0 si non auth)
- `API/maps_geocode_client.php` : Utilisation de `initApi()` + `requireApiAuth()`
- `API/import/sftp_status.php` : Utilisation de `initApi()` + `requireApiAuth()`
- `API/import/ionos_status.php` : Utilisation de `initApi()` + `requireApiAuth()`
- `API/factures_envoyer_email.php` : Remplacement de `auth.php` par `initApi()` + `requireApiAuth()`
- `API/paiements_get_stats.php` : Remplacement de `auth.php` par `initApi()` + `requireApiAuth()`
- `API/paiements_historique.php` : Remplacement de `auth.php` par `initApi()` + `requireApiAuth()`

### 4. Nouveau endpoint
- `API/auth_status.php` : Endpoint léger pour vérifier le statut d'authentification (évite polling inutile)

### 5. Corrections JavaScript (fetch avec credentials)
- `source/templates/header.php` : `credentials: 'same-origin'` → `credentials: 'include'`
- `public/dashboard.php` : Ajout de `credentials: 'include'` à tous les fetch()
- `public/messagerie.php` : `credentials: 'same-origin'` → `credentials: 'include'`
- `public/profil.php` : `credentials: 'same-origin'` → `credentials: 'include'`
- `assets/js/api.js` : `credentials: 'same-origin'` → `credentials: 'include'`

## Liste des endpoints corrigés

✅ `/API/messagerie_get_unread_count.php`
✅ `/API/import/sftp_status.php`
✅ `/API/import/ionos_status.php`
✅ `/API/chatroom_get_notifications.php`
✅ `/API/maps_geocode_client.php`
✅ `/API/factures_envoyer_email.php`
✅ `/API/paiements_get_stats.php`
✅ `/API/paiements_historique.php`

## Validation

Les corrections garantissent que :
- ✅ Les appels API authentifiés fonctionnent (cookies envoyés avec `credentials: 'include'`)
- ✅ Les appels non authentifiés renvoient 401 JSON (pas de 302 HTML)
- ✅ Les cookies de session fonctionnent sur Railway/HTTPS (SameSite=None si proxy)
- ✅ Logging minimal pour debug prod (une fois par endpoint)
- ✅ Comportement existant conservé (pas de changement de logique métier)

## Notes importantes

- **Pas de changement de style UI** : Aucun changement visuel
- **Pas de changement de logique fonctionnelle** : Comportement métier identique
- **Compatibilité Railway** : Détection automatique de l'environnement (local vs Railway)
- **Minimal et sûr** : Changements ciblés, pas de refactoring massif

## Endpoints restants utilisant auth.php

Les endpoints suivants utilisent encore `auth.php` mais ne sont pas mentionnés dans les logs d'erreur :
- `API/factures_generer.php`
- `API/factures_update_statut.php`
- `API/factures_generer_clients.php`
- `API/paiements_enregistrer.php`
- `API/factures_liste.php`
- `API/factures_envoyer.php`
- `API/paiements_export_excel.php`
- `API/clients/attribuer_photocopieur.php`
- `API/clients/get_client_photocopieur.php`

Ces endpoints peuvent être corrigés de la même manière si nécessaire (remplacer `auth.php` par `initApi()` + `requireApiAuth()`).

