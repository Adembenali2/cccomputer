# JOURNAL DE NETTOYAGE - CCCOMPUTER

**Date de début** : Généré automatiquement  
**Objectif** : Refactoring sûr sans régression, en suivant DIAGNOSTIC_SITE.md  
**Principe** : Aucune suppression définitive, seulement déplacements vers `_trash/` (puis suppression après vérification)

---

## CHECKLIST DE TESTS RAPIDES

Après chaque modification, tester rapidement :

### Tests Critiques
- [ ] **Login** : `/public/login.php` - Connexion fonctionne
- [ ] **Dashboard** : `/public/dashboard.php` - Page s'affiche, données chargées
- [ ] **API principales** : 
  - [ ] `/API/maps_get_all_clients.php` - Retourne des données JSON
  - [ ] `/API/dashboard_get_sav.php` - Retourne des données JSON
  - [ ] `/API/messagerie_get_unread_count.php` - Retourne des données JSON
- [ ] **Import** : Vérifier que les scripts d'import fonctionnent (si applicable)

### Tests Fonctionnels
- [ ] **Clients** : `/public/clients.php` - Liste des clients s'affiche
- [ ] **Stock** : `/public/stock.php` - Page s'affiche
- [ ] **CSRF** : Les formulaires fonctionnent avec tokens CSRF

---

## MODIFICATIONS APPLIQUÉES

### ÉTAPE 0 - Préparation
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `CLEANUP_LOG.md` créé
- `_trash/` créé

**Raison** : Préparation du nettoyage  
**Risque** : Aucun  
**Test** : Vérifier que le dossier `_trash/` existe

---

### ÉTAPE 1A - Correction accès propriété privée
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/api_helpers.php` (ligne 111)

**Modification** : Suppression de `DatabaseConnection::$instance = $pdo;` qui tentait d'accéder à une propriété privée depuis l'extérieur de la classe. Ajout d'un commentaire explicatif.

**Raison** : Correction d'une erreur fatale PHP qui empêcherait l'exécution  
**Risque** : Très faible - La classe DatabaseConnection gère déjà son instance via getInstance()  
**Test** : 
- Tester une requête API qui utilise requirePdoConnection()
- Vérifier que les API fonctionnent normalement

---

### ÉTAPE 1B - Centralisation configuration erreurs
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/helpers.php` (ajout fonction `configureErrorReporting()`)
- `public/run-import.php` (remplacement config inline)
- `API/scripts/upload_compteur.php` (remplacement config inline)
- `import/test_import_db.php` (remplacement config inline)
- `import/import_ancien_http.php` (remplacement config inline)
- `includes/api_helpers.php` (utilise la nouvelle fonction)

**Modification** : 
- Création de la fonction `configureErrorReporting()` dans `includes/helpers.php`
- Remplacement des `error_reporting()` et `ini_set()` répétés par des appels à cette fonction
- Les scripts d'import/debug utilisent `forceDev=true` pour afficher les erreurs (nécessaire pour le debug)
- Les API utilisent `forceDev=false` pour ne pas afficher les erreurs en production
- La fonction respecte `APP_ENV` si définie

**Raison** : 
- Évite la duplication de code
- Centralise la configuration d'erreurs
- S'assure que `display_errors` est désactivé en production (sauf pour scripts de debug)
- Facilite la maintenance

**Risque** : Très faible - Le comportement reste identique, mais centralisé  
**Test** : 
- Tester les API : vérifier qu'elles ne révèlent pas d'erreurs en production
- Tester les scripts d'import : vérifier qu'ils affichent toujours les erreurs pour le debug
- Vérifier les logs d'erreurs PHP sont toujours générés

---

### ÉTAPE 2 - Remplacement usages dangereux de '@'
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `import/import_ancien_http.php` (ligne 152)
- `import/debug_import.php` (ligne 824)
- `API/scripts/upload_compteur.php` (lignes 348-349)

**Modification** : 
- Remplacement de `@$dom->loadHTML()` par `$dom->loadHTML()` avec gestion explicite via `libxml_use_internal_errors()` et `libxml_get_errors()`
- Remplacement de `@$sftp->mkdir()` par des try/catch explicites qui gèrent l'erreur "dossier existe déjà" de manière appropriée

**Raison** : 
- L'opérateur `@` masque toutes les erreurs, y compris les erreurs importantes
- Une gestion explicite permet de distinguer les erreurs acceptables (dossier existe déjà) des erreurs critiques
- Améliore le débogage en permettant de voir les vraies erreurs

**Risque** : Faible - Le comportement reste similaire, mais avec une meilleure gestion d'erreurs  
**Test** : 
- Tester l'import depuis la page web (import_ancien_http.php)
- Tester l'import SFTP (upload_compteur.php) - vérifier que les dossiers sont créés correctement
- Vérifier que les erreurs de parsing HTML sont toujours gérées correctement

---

### ÉTAPE 3 - Nettoyage fichiers suspects
**Date** : Généré automatiquement  
**Fichiers déplacés vers `_trash/`** : 
- `e 98eea26^` → `_trash/root/e_98eea26^`
- `import/test_import_db.php` → `_trash/import/test_import_db.php`

**Modification** : 
- Déplacement du fichier suspect `e 98eea26^` vers `_trash/root/`
- Déplacement du script de test `import/test_import_db.php` vers `_trash/import/` car c'est un script de test/diagnostic non utilisé en production

**Fichiers conservés (référencés)** : 
- `import/import_ancien_http.php` - **CONSERVÉ** : Référencé dans `run_import_web_if_due.php` et `run_import_ancien_if_due.php`, donc utilisé
- `import/last_import_ancien.php` - **CONSERVÉ** : Potentiellement utilisé par des scripts de monitoring
- `import/run_import_ancien_if_due.php` - **CONSERVÉ** : Potentiellement utilisé par un cron/scheduler

**Raison** : 
- Nettoyage des fichiers suspects/non utilisés sans suppression définitive
- Conservation des fichiers potentiellement utilisés (même si "ancien" dans le nom)

**Risque** : Très faible - Fichiers de test/suspects déplacés, pas supprimés  
**Test** : 
- Vérifier que le fichier `e 98eea26^` n'existe plus à la racine
- Vérifier que les imports fonctionnent toujours (import_ancien_http.php toujours présent)

---

### ÉTAPE 4 - Déduplication debugLog()
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/debug_helpers.php` créé (nouvelle fonction centralisée)
- `import/run_import_if_due.php` (remplacement définition locale par wrapper)
- `API/scripts/upload_compteur.php` (remplacement définition locale par wrapper)

**Modification** : 
- Création de `includes/debug_helpers.php` avec une fonction `debugLog()` centralisée
- Remplacement des deux définitions locales par des wrappers qui appellent la fonction centralisée
- Les wrappers préservent la signature originale (message, context) pour compatibilité
- Le comportement reste identique : même format de log, même sortie

**Raison** : 
- Élimination de la duplication de code
- Facilité de maintenance future (une seule fonction à modifier)
- Préserve le comportement existant grâce aux wrappers

**Risque** : Faible - Le comportement reste identique grâce aux wrappers  
**Test** : 
- Exécuter un import SFTP et vérifier que les logs s'affichent correctement
- Exécuter `run_import_if_due.php` et vérifier que les logs sont générés
- Vérifier que le format des logs reste le même qu'avant

---

### ÉTAPE 5 - Organisation documentation
**Date** : Généré automatiquement  
**Fichiers déplacés vers `docs/`** : 
- Tous les fichiers `.md` sauf `README.md`, `DIAGNOSTIC_SITE.md` et `CLEANUP_LOG.md` (15 fichiers déplacés)

**Modification** : 
- Création du dossier `docs/` à la racine
- Déplacement de tous les fichiers de documentation `.md` (sauf ceux à la racine nécessaires au projet) vers `docs/`
- Conservation de `README.md`, `DIAGNOSTIC_SITE.md` et `CLEANUP_LOG.md` à la racine

**Raison** : 
- Meilleure organisation de la documentation
- Racine du projet plus propre
- Facilite la navigation

**Risque** : Très faible - Déplacement de fichiers de documentation uniquement  
**Test** : 
- Vérifier que les fichiers `.md` sont maintenant dans `docs/`
- Vérifier que `README.md`, `DIAGNOSTIC_SITE.md` et `CLEANUP_LOG.md` sont toujours à la racine

---

### ÉTAPE 6 - Suppression définitive fichiers dans _trash/
**Date** : Généré automatiquement  
**Fichiers supprimés définitivement** : 
- `_trash/root/e_98eea26^`
- `_trash/import/test_import_db.php`

**Vérification effectuée** : 
- Recherche globale de références dans tout le codebase (PHP, JS, CSS, config, cron)
- Aucune référence trouvée dans le code source
- Seules références trouvées : dans CLEANUP_LOG.md et DIAGNOSTIC_SITE.md (documentation normale)

**Raison** : 
- Fichiers confirmés comme non utilisés et non référencés
- Nettoyage définitif après période de validation dans _trash/
- `e_98eea26^` : fichier suspect avec nom étrange, probablement erreur
- `test_import_db.php` : script de test non utilisé en production

**Risque** : Aucun - Fichiers non référencés et non utilisés  
**Test** : 
- Vérifier que les fichiers n'existent plus dans `_trash/`
- Vérifier que les dossiers vides ont été supprimés

**État final** : 
- `_trash/` est maintenant vide (mais conservé pour d'éventuels futurs déplacements)
- Tous les fichiers non référencés ont été supprimés définitivement

---

### ÉTAPE 1 - Unification PDO : Création fonction getPdo()
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/helpers.php` - Ajout fonction `getPdo()` (source de vérité)
- `includes/api_helpers.php` - Simplification DatabaseConnection::getInstance(), ajout include helpers.php
- `includes/db.php` - Ajout commentaires "COMPATIBILITÉ TEMPORAIRE" et initialisation DatabaseConnection

**Modification** : 
- Création de la fonction `getPdo()` dans `includes/helpers.php` qui utilise `DatabaseConnection::getInstance()`
- Cette fonction est la source de vérité unique pour obtenir PDO
- Maintien temporaire de `$GLOBALS['pdo']` dans db.php pour compatibilité pendant la migration
- Initialisation de DatabaseConnection depuis db.php pour unifier la création

**Raison** : 
- Définir une source de vérité unique avant de migrer les fichiers
- Permettre une migration progressive sans casser le site
- Maintenir la compatibilité avec le code existant pendant la transition

**Risque** : Très faible - Aucun changement de comportement, seulement ajout d'une fonction  
**Test** : 
- Vérifier que les pages et API fonctionnent toujours normalement
- Vérifier que `getPdo()` peut être appelée depuis n'importe où

---

### ÉTAPE 3 - Lot #1 : Migration PDO (4 fichiers)
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/auth_role.php` - Remplacement `global $pdo` par `getPdo()`
- `import/last_import_ancien.php` - Remplacement `$GLOBALS['pdo']` par `getPdo()`
- `import/run_import_ancien_if_due.php` - Remplacement `$GLOBALS['pdo']` par `getPdo()`
- `import/debug_import.php` - Remplacement `$GLOBALS['pdo']` par `getPdo()` (avec fallback temporaire)

**Modification** : 
- Remplacement de `global $pdo` et `$GLOBALS['pdo']` par `$pdo = getPdo();`
- Ajout de gestion d'erreurs avec try/catch pour getPdo()
- Pour debug_import.php : ajout d'un fallback vers GLOBALS en cas d'échec (compatibilité temporaire)

**Raison** : 
- Migration progressive vers une gestion unifiée de PDO
- Réduction de la dépendance aux variables globales
- Meilleure maintenabilité

**Risque** : Faible - Le comportement reste identique, seulement la méthode de récupération change  
**Test** : 
- Tester l'authentification et les permissions (auth_role.php) : `/public/dashboard.php`, `/public/clients.php`
- Tester les imports anciens si utilisés : vérifier que `last_import_ancien.php` et `run_import_ancien_if_due.php` fonctionnent
- Tester le debug import : vérifier que `import/debug_import.php` fonctionne si utilisé

**État actuel de la migration PDO** :
- ✅ Fonction `getPdo()` créée et simplifiée (retourne directement DatabaseConnection::getInstance())
- ✅ Isolation DatabaseConnection : classe déplacée dans `includes/db_connection.php` (suppression dépendance helpers.php → api_helpers.php)
- ✅ Lot #1 migré (4 fichiers : auth_role.php, last_import_ancien.php, run_import_ancien_if_due.php, debug_import.php)
- ✅ Lot #2 migré (10 fichiers API : maps_get_all_clients, dashboard_get_sav, messagerie_get_unread_count, dashboard_get_deliveries, dashboard_get_techniciens, dashboard_get_livreurs, dashboard_get_stock_products, maps_search_clients, dashboard_create_sav, stock_add)
- ✅ Lot #2B : Centralisation erreurs DB API (getPdoOrFail, apiFail)
- ✅ Lot #3 migré (5 fichiers publics : clients.php, stock.php, messagerie.php, profil.php, historique.php)
- ✅ Stabilisation effectuée : fallbacks supprimés, `getPdo()` simplifié
- ✅ Tous les includes utilisent `require_once` (vérification effectuée)
- ⚠️ D'autres fichiers publics et certains fichiers API utilisent encore `$pdo` directement ou `requirePdoConnection()`
- ⚠️ Compatibilité temporaire maintenue : `$GLOBALS['pdo']` et `global $pdo` dans db.php (sera retiré après migration complète)

**Prochaines étapes** :
- Migrer les fichiers publics et API par lots (rechercher les utilisations directes de `$pdo`)
- Retirer la compatibilité temporaire une fois tous les fichiers migrés

---

### ÉTAPE X - Stabilisation PDO (includes + getPdo + suppression fallback)
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/helpers.php` - Simplification de `getPdo()` (suppression fallback vers GLOBALS)
- `import/debug_import.php` - Suppression fallback vers `$GLOBALS['pdo']`
- `includes/api_helpers.php` - Ajout commentaire pour require_once db.php

**Modification** : 
1. **Simplification de getPdo()** :
   - Suppression du fallback vers `$GLOBALS['pdo']`
   - `getPdo()` retourne directement `DatabaseConnection::getInstance()`
   - Chargement de DatabaseConnection depuis `api_helpers.php` si nécessaire avec `require_once`
   - Lance une exception si DatabaseConnection n'est pas disponible (pas de fallback silencieux)
   - Code simplifié et prévisible

2. **Suppression du fallback dans debug_import.php** :
   - Retrait de la logique "si getPdo() échoue, utiliser $GLOBALS['pdo']"
   - Suppression du try/catch silencieux
   - Nettoyage du code mort (anciennes vérifications de GLOBALS)
   - Utilisation uniquement de `getPdo()` de manière directe

3. **Vérification des includes** :
   - Vérification complète : tous les includes de helpers.php, db.php, api_helpers.php, debug_helpers.php utilisent déjà `require_once`
   - Ajout d'un commentaire explicatif dans `api_helpers.php` pour `require_once db.php`
   - Aucun changement nécessaire, tout était déjà correct

**Raison** : 
- Stabiliser la base avant de continuer la migration
- Simplifier `getPdo()` pour éviter les comportements imprévisibles avec les fallbacks
- Garantir que seule la méthode unifiée (DatabaseConnection) est utilisée
- Éviter les "Cannot redeclare function" avec require_once

**Risque** : Faible - Simplification du code, comportement plus prévisible  
**Test** : 
- Tester `/public/dashboard.php` - doit fonctionner normalement
- Tester une API (ex: `/API/maps_get_all_clients.php`) - doit retourner des données JSON
- Tester `import/debug_import.php` si utilisé - doit fonctionner sans erreur
- Vérifier qu'aucune erreur "Cannot redeclare function" n'apparaît dans les logs

---

### ÉTAPE Y - Isolation DatabaseConnection
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/db_connection.php` - **NOUVEAU** : Classe DatabaseConnection isolée
- `includes/helpers.php` - Remplacement de l'inclusion d'api_helpers.php par db_connection.php
- `includes/api_helpers.php` - Remplacement de la définition de DatabaseConnection par l'inclusion de db_connection.php

**Modification** : 
1. **Création de `includes/db_connection.php`** :
   - Nouveau fichier contenant uniquement la classe `DatabaseConnection`
   - Isolé pour éviter les dépendances circulaires entre helpers.php et api_helpers.php
   - Déplacé depuis `includes/api_helpers.php`

2. **Modification de `includes/helpers.php`** :
   - `getPdo()` charge maintenant `db_connection.php` au lieu d'`api_helpers.php`
   - Suppression de la dépendance `helpers.php` → `api_helpers.php`
   - `getPdo()` simplifié : vérifie si DatabaseConnection existe, sinon charge db_connection.php, puis retourne `DatabaseConnection::getInstance()`

3. **Modification de `includes/api_helpers.php`** :
   - Suppression de la définition de la classe `DatabaseConnection`
   - Ajout de `require_once __DIR__ . '/db_connection.php';` au début
   - Le reste du fichier reste inchangé

**Raison** : 
- Éliminer la dépendance circulaire potentielle entre `helpers.php` et `api_helpers.php`
- Isoler DatabaseConnection dans un fichier dédié pour une meilleure organisation
- Faciliter la maintenance et éviter les problèmes de dépendances

**Risque** : Très faible - Réorganisation du code sans changement fonctionnel  
**Test** : 
- Tester `/public/dashboard.php` - doit fonctionner normalement
- Tester 2 endpoints API :
  - `/API/maps_get_all_clients.php` - doit retourner des données JSON
  - `/API/dashboard_get_sav.php` - doit retourner des données JSON
- Vérifier qu'aucune erreur "Class DatabaseConnection already declared" n'apparaît
- Vérifier que `getPdo()` fonctionne correctement depuis les pages publiques et les API

---

### ÉTAPE Z - Migration PDO Lot #2 (API)
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `API/maps_get_all_clients.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/dashboard_get_sav.php` - Remplacement de `requirePdoConnection()` par `getPdo()`
- `API/messagerie_get_unread_count.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/dashboard_get_deliveries.php` - Remplacement de `requirePdoConnection()` par `getPdo()`
- `API/dashboard_get_techniciens.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/dashboard_get_livreurs.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/dashboard_get_stock_products.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/maps_search_clients.php` - Remplacement de `$pdo` global par `getPdo()`
- `API/dashboard_create_sav.php` - Remplacement de `requirePdoConnection()` par `getPdo()`
- `API/stock_add.php` - Remplacement de `requirePdoConnection()` par `getPdo()`

**Modification** : 
- Pour chaque fichier migré :
  - Remplacement de `requirePdoConnection()` par `getPdo()` avec gestion d'erreur via try/catch
  - Remplacement de l'inclusion `db.php` par `helpers.php` pour les fichiers qui utilisaient `$pdo` global
  - Ajout de `$pdo = getPdo();` après les includes nécessaires
  - Suppression des vérifications `if (!isset($pdo) || !($pdo instanceof PDO))` remplacées par try/catch
- Aucun usage de `$GLOBALS['pdo']`, `global $pdo` ou `requirePdoConnection()` dans les fichiers migrés

**Raison** : 
- Migration progressive des fichiers API vers l'utilisation unifiée de `getPdo()`
- Élimination progressive des dépendances vers `requirePdoConnection()` et les variables globales

**Risque** : Faible - Migration ciblée sur les endpoints API, compatibilité maintenue pour les fichiers non migrés  
**Test** : 
- Tester au moins 3 endpoints API migrés :
  - `/API/maps_get_all_clients.php` - doit retourner la liste des clients avec leurs coordonnées
  - `/API/dashboard_get_sav.php?client_id=1` - doit retourner les SAV d'un client
  - `/API/messagerie_get_unread_count.php` - doit retourner le nombre de messages non lus
- Tester également les autres endpoints migrés :
  - `/API/dashboard_get_deliveries.php?client_id=1`
  - `/API/dashboard_get_techniciens.php`
  - `/API/dashboard_get_livreurs.php`
  - `/API/dashboard_get_stock_products.php?type=papier`
  - `/API/maps_search_clients.php?q=test`
- Vérifier qu'aucun fichier migré n'utilise encore `$GLOBALS['pdo']`, `global $pdo` ou `requirePdoConnection()`

---

### ÉTAPE Z2 - Centralisation erreurs DB API (Lot #2B)
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `includes/api_helpers.php` - Ajout de `apiFail()` et `getPdoOrFail()` helper functions
- `API/maps_get_all_clients.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_get_sav.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_get_deliveries.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_get_techniciens.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_get_livreurs.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_get_stock_products.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/maps_search_clients.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/dashboard_create_sav.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/stock_add.php` - Remplacement try/catch par `getPdoOrFail()`
- `API/messagerie_get_unread_count.php` - Conservé try/catch spécifique (retourne count=0 au lieu d'erreur pour ne pas bloquer le header)

**Modification** : 
1. **Ajout de fonctions helpers dans `includes/api_helpers.php`** :
   - `apiFail(string $message, int $code = 500, array $extra = [])` : Helper pour renvoyer une réponse d'erreur JSON standardisée
   - `getPdoOrFail()` : Wrapper autour de `getPdo()` qui appelle `apiFail()` en cas d'erreur (terminant l'exécution)

2. **Simplification des endpoints migrés** :
   - Suppression des try/catch répétitifs autour de `getPdo()`
   - Remplacement par `$pdo = getPdoOrFail();` (sauf messagerie_get_unread_count qui a un comportement spécial)
   - Code plus propre et gestion d'erreurs standardisée
   - Les erreurs PDO sont maintenant gérées de manière cohérente avec une réponse JSON standardisée

**Raison** : 
- Centraliser la gestion des erreurs PDO côté API
- Éliminer la duplication de code (try/catch répétitifs)
- Standardiser les réponses d'erreur JSON
- Simplifier le code des endpoints

**Risque** : Très faible - Simplification du code, comportement identique (même gestion d'erreur, juste centralisée)  
**Test** : 
- Tester 3 endpoints et vérifier le format de réponse d'erreur (en cas de panne DB simulée) :
  - `/API/maps_get_all_clients.php` - doit retourner `{"ok":false,"error":"Erreur de connexion à la base de données"}` avec code 500
  - `/API/dashboard_get_sav.php?client_id=1` - doit retourner `{"ok":false,"error":"Erreur de connexion à la base de données"}` avec code 500
  - `/API/dashboard_get_techniciens.php` - doit retourner `{"ok":false,"error":"Erreur de connexion à la base de données"}` avec code 500
- Vérifier que le comportement fonctionnel normal reste identique (quand la DB fonctionne)
- Optionnel : Simuler une panne DB (désactiver MySQL) et vérifier que tous les endpoints retournent la même réponse d'erreur standardisée

---

### ÉTAPE Z3 - Migration PDO Lot #3 (PUBLIC)
**Date** : Généré automatiquement  
**Fichiers modifiés** : 
- `public/clients.php` - Remplacement de `require_once db.php` par `helpers.php` + `getPdo()`
- `public/stock.php` - Remplacement de `require_once db.php` par `helpers.php` + `getPdo()`
- `public/messagerie.php` - Remplacement de `require_once db.php` par `helpers.php` + `getPdo()`
- `public/profil.php` - Remplacement de `require_once db.php` par `helpers.php` + `getPdo()`
- `public/historique.php` - Remplacement de `require_once db.php` par `helpers.php` + `getPdo()`

**Modification** : 
- Pour chaque fichier migré :
  - Remplacement de l'inclusion `db.php` par `helpers.php` (ou s'assurer que `helpers.php` est inclus)
  - Ajout de `$pdo = getPdo();` après les includes nécessaires
  - Suppression de la dépendance vers `$pdo` global créé par `db.php`
  - Les fichiers utilisent maintenant une variable locale `$pdo` obtenue via `getPdo()`
- Pas d'utilisation de `getPdoOrFail()` (réservé aux API)
- Aucune modification du SQL ni du HTML

**Raison** : 
- Migration progressive des pages publiques vers l'utilisation unifiée de `getPdo()`
- Élimination progressive des dépendances vers les variables globales PDO
- Normalisation de l'accès à la base de données dans tout le projet

**Risque** : Faible - Migration ciblée sur 5 fichiers publics, compatibilité maintenue pour les fichiers non migrés  
**Test** : 
- Tester les pages migrées :
  - `/public/clients.php` - doit afficher la liste des clients normalement
  - `/public/stock.php` - doit afficher la gestion du stock normalement
  - `/public/messagerie.php` - doit afficher la messagerie normalement
  - `/public/profil.php` - doit afficher la gestion des profils normalement
  - `/public/historique.php` - doit afficher l'historique normalement
- Vérifier qu'aucun fichier migré n'utilise encore `$GLOBALS['pdo']`, `global $pdo` ou `require_once db.php`
- Vérifier que toutes les fonctionnalités (affichage, recherche, filtres) fonctionnent normalement

---

## RÉSUMÉ DES MODIFICATIONS

### Fichiers modifiés
- `includes/api_helpers.php` - Correction accès propriété privée
- `includes/helpers.php` - Ajout fonction `configureErrorReporting()`
- `includes/debug_helpers.php` - Nouveau fichier avec fonction `debugLog()` centralisée
- `public/run-import.php` - Utilisation fonction centralisée
- `API/scripts/upload_compteur.php` - Utilisation fonction centralisée + gestion d'erreurs SFTP
- `import/import_ancien_http.php` - Utilisation fonction centralisée + gestion d'erreurs libxml
- `import/debug_import.php` - Gestion d'erreurs libxml
- `import/run_import_if_due.php` - Utilisation fonction centralisée
- `includes/api_helpers.php` - Utilisation fonction centralisée pour erreurs

### Fichiers déplacés puis supprimés définitivement de `_trash/`
- `e 98eea26^` → `_trash/root/e_98eea26^` → **SUPPRIMÉ DÉFINITIVEMENT** ✓
- `import/test_import_db.php` → `_trash/import/test_import_db.php` → **SUPPRIMÉ DÉFINITIVEMENT** ✓

### Fichiers déplacés vers `docs/`
- Tous les fichiers `.md` sauf `README.md`, `DIAGNOSTIC_SITE.md`, `CLEANUP_LOG.md` (15 fichiers)

### Fichiers conservés (référencés/utilisés)
- `import/import_ancien_http.php` - Utilisé par `run_import_web_if_due.php` et `run_import_ancien_if_due.php`
- `import/last_import_ancien.php` - Potentiellement utilisé
- `import/run_import_ancien_if_due.php` - Potentiellement utilisé

---

**Fin du journal de nettoyage**

---

