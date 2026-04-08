# Audit complet – CCComputer (PHP/HTML/CSS/JS)

**Date :** 5 mars 2025  
**Auditeur :** Senior Full-Stack Auditor  
**Version PHP cible :** 8.0+

---

## 1) Résumé

L’application CCComputer est une gestion clients/facturation/stock avec messagerie, chatroom et planification. La base est solide (PDO préparé, CSRF sur la plupart des formulaires, sessions sécurisées), mais plusieurs points critiques et majeurs ont été identifiés :

- **CRITIQUE :** Absence de CSRF sur `paiements_enregistrer.php` (enregistrement de paiements sans token).
- **CRITIQUE :** Risque de path traversal dans `view_facture.php` si `pdf_path` en base est manipulé.
- **MAJEUR :** Variable `$pdo` non définie dans plusieurs API (`messagerie_search_sav.php`, `messagerie_search_livraisons.php`, etc.) → erreurs 500.
- **MAJEUR :** `profil_search_users.php` utilise `db.php` qui ne fournit plus `$pdo` → erreur de connexion.
- **MAJEUR :** `API/osrm_route.php` sans authentification → abus possible (proxy, rate-limit OSRM).
- **MAJEUR :** `chatroom_upload_image.php` sans vérification CSRF.
- **MOYEN :** Absence de `.env.example` pour documenter les variables d’environnement.
- **MOYEN :** `paiements_historique.php` utilise `$pdo->query($sql)` avec une requête statique (pas de SQLi, mais incohérent avec le reste du code).
- **MOYEN :** Logs `error_log` avec données sensibles (SQL, params) en mode DEBUG.

---

## 2) Tableau des problèmes (triés par sévérité)

| Sévérité | Type | Fichier | Lignes | Symptôme | Cause probable | Fix recommandé |
|----------|------|---------|--------|----------|----------------|----------------|
| **CRITIQUE** | Sécurité | `API/paiements_enregistrer.php` | 1–50 | Enregistrement de paiements sans CSRF → attaque CSRF possible | Pas de vérification du token CSRF | Ajouter `requireCsrfToken()` et inclure le token dans le formulaire/fetch |
| **CRITIQUE** | Sécurité | `public/view_facture.php` | 42–67 | Path traversal si `pdf_path` en DB contient `../` | `$relativePath` non validé avec `realpath()` | Valider le chemin résolu avec `realpath()` et vérifier qu’il est sous `uploads/factures/` |
| **MAJEUR** | PHP | `API/messagerie_search_sav.php` | 18–53 | Erreur 500 / variable non définie | `$pdo` jamais défini (initApi ne l’expose pas) | Ajouter `$pdo = getPdoOrFail();` |
| **MAJEUR** | PHP | `API/messagerie_search_livraisons.php` | idem | Idem | Idem | Idem |
| **MAJEUR** | PHP | `API/profil_search_users.php` | 51–86 | Erreur 500 / connexion DB | Utilise `db.php` qui ne fournit plus `$pdo` | Remplacer par `$pdo = getPdo();` (via `db_connection.php`) |
| **MAJEUR** | Sécurité | `API/osrm_route.php` | 1–87 | Proxy OSRM sans auth → abus | Aucune vérification de session | Protéger par auth ou rate-limit strict |
| **MAJEUR** | Sécurité | `API/chatroom_upload_image.php` | 1–110 | Upload sans CSRF | Pas de vérification du token | Ajouter CSRF (token dans FormData ou header) |
| **MOYEN** | Config | Racine | - | Pas de `.env.example` | Fichier non créé | Créer `.env.example` avec toutes les variables nécessaires |
| **MOYEN** | PHP | `API/paiements_historique.php` | 44 | Incohérence avec le reste du code | Utilise `$pdo->query()` au lieu de `prepare()` | Remplacer par `prepare()` pour cohérence (requête statique, pas de SQLi) |
| **MOYEN** | Sécurité | `public/historique.php` | 148–150 | Logs SQL/params en prod si DEBUG_MODE | `error_log` avec SQL et params | Ne jamais logger SQL/params en production |
| **MINEUR** | Maintenabilité | `includes/db.php` | 1–22 | Fichier déprécié | Migration incomplète | Migrer tous les usages vers `getPdo()` et supprimer |
| **MINEUR** | Maintenabilité | `maps-enhancements.js` (racine) | - | Doublon avec `assets/js/maps-enhancements.js` | Copie à la racine | Supprimer le doublon à la racine |
| **MINEUR** | Config | `API/osrm_route.php` | 12 | CORS figé sur un seul domaine | `Access-Control-Allow-Origin` en dur | Utiliser une config (env) pour le domaine autorisé |

---

## 3) Détails + Correctifs

### 3.1 CRITIQUE – CSRF manquant sur paiements_enregistrer.php

**Analyse :** L’endpoint accepte des POST pour enregistrer des paiements sans vérifier le token CSRF. Un site tiers peut forger une requête POST (avec cookies de session si SameSite=None) et enregistrer un paiement au nom de l’utilisateur connecté.

**Patch proposé :**

```diff
--- a/API/paiements_enregistrer.php
+++ b/API/paiements_enregistrer.php
@@ -6,6 +6,7 @@
 require_once __DIR__ . '/../includes/auth.php';
 require_once __DIR__ . '/../includes/api_helpers.php';
 
+initApi(); // Démarre session et génère CSRF si besoin
 // Vérifier que c'est une requête POST
 if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
     jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
@@ -14,6 +15,11 @@
 try {
     $pdo = getPdo();
     $userId = currentUserId();
+    
+    // Vérification CSRF
+    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
+    if (empty($csrfToken) || empty($_SESSION['csrf_token'] ?? '') || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
+        jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
+    }
     
     // Récupération des données
```

**Côté frontend (paiements.php)** – ajouter le token CSRF dans le FormData :

```javascript
// Dans le gestionnaire du formulaire d'enregistrement
const csrfInput = document.querySelector('input[name="csrf_token"]') || document.querySelector('meta[name="csrf-token"]');
if (csrfInput) {
    formData.append('csrf_token', csrfInput.value || csrfInput.getAttribute('content'));
}
```

**Pourquoi ça marche :** Le token CSRF lie la requête à la session. Un site tiers ne peut pas le connaître, donc la requête forgée sera rejetée.

---

### 3.2 CRITIQUE – Path traversal dans view_facture.php

**Analyse :** Si `pdf_path` en base contient par exemple `/uploads/factures/../../../etc/passwd`, après `preg_replace` on obtient `../../../etc/passwd`, et `$baseDir . '/uploads/factures/' . $relativePath` peut résoudre vers un fichier hors du répertoire prévu.

**Patch proposé :**

```diff
--- a/public/view_facture.php
+++ b/public/view_facture.php
@@ -40,7 +40,7 @@
     if (!empty($pdfWebPath)) {
         $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
+        $relativePath = str_replace(['../', '..\\'], '', $relativePath); // Bloquer path traversal
         
         // Tester plusieurs chemins possibles (compatible Railway)
         $possibleBaseDirs = [];
@@ -60,8 +60,14 @@
         
         foreach ($possibleBaseDirs as $baseDir) {
             $testPath = $baseDir . '/uploads/factures/' . $relativePath;
-            if (file_exists($testPath) && is_file($testPath)) {
+            $realPath = realpath($testPath);
+            $realBase = realpath($baseDir . '/uploads/factures');
+            if ($realPath !== false && $realBase !== false 
+                && strpos($realPath, $realBase) === 0 
+                && is_file($realPath)) {
                 $pdfPath = $realPath;
                 break;
             }
         }
```

**Pourquoi ça marche :** `realpath()` résout les `..` et `realpath` du base. On vérifie que le fichier résolu est bien sous `uploads/factures/`.

---

### 3.3 MAJEUR – Variable $pdo non définie dans messagerie_search_sav.php et messagerie_search_livraisons.php

**Analyse :** Ces scripts utilisent `$pdo` sans l’avoir défini. `initApi()` crée une variable locale `$pdo` qui n’est pas visible dans le scope de l’appelant.

**Patch proposé :**

```diff
--- a/API/messagerie_search_sav.php
+++ b/API/messagerie_search_sav.php
@@ -4,6 +4,7 @@
 require_once __DIR__ . '/../includes/api_helpers.php';
 initApi();
 requireApiAuth();
+$pdo = getPdoOrFail();
 
 $query = trim($_GET['q'] ?? '');
```

Idem pour `API/messagerie_search_livraisons.php`.

---

### 3.4 MAJEUR – profil_search_users.php et $pdo

**Analyse :** Le script inclut `db.php` qui ne définit plus `$pdo`. La condition `if (!isset($pdo) || !($pdo instanceof PDO))` renvoie toujours une erreur 500.

**Patch proposé :**

```diff
--- a/API/profil_search_users.php
+++ b/API/profil_search_users.php
@@ -28,8 +28,8 @@
 try {
     require_once __DIR__ . '/../includes/session_config.php';
     require_once __DIR__ . '/../includes/auth_role.php';
-    require_once __DIR__ . '/../includes/db.php';
     require_once __DIR__ . '/../includes/helpers.php';
+    $pdo = getPdo();
 } catch (Throwable $e) {
```

---

### 3.5 MAJEUR – osrm_route.php sans authentification

**Analyse :** L’endpoint est un proxy vers OSRM. Sans auth, n’importe qui peut l’utiliser et surcharger le service OSRM ou votre serveur.

**Patch proposé (option 1 – auth session) :**

```diff
--- a/API/osrm_route.php
+++ b/API/osrm_route.php
@@ -1,5 +1,10 @@
 <?php
 // Proxy pour les requêtes OSRM (évite les problèmes CORS)
 ob_start();
+
+require_once __DIR__ . '/../includes/api_helpers.php';
+initApi();
+requireApiAuth();
```

**Option 2 (rate-limit strict sans auth) :** Si l’accès doit rester public, ajouter un rate-limit très strict (ex. 10 req/min par IP).

---

### 3.6 MAJEUR – chatroom_upload_image.php sans CSRF

**Patch proposé :** Accepter le token en header ou dans FormData :

```php
// Après requireApiAuth();
$csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfToken) || empty($_SESSION['csrf_token'] ?? '') || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}
```

Côté messagerie.php, ajouter le token au FormData avant l’upload :

```javascript
formData.append('csrf_token', CONFIG.csrfToken);
```

---

## 4) Checklist de validation

### Commandes et étapes

1. **Vérifier la syntaxe PHP :**
   ```bash
   php -l index.php
   php -l public/dashboard.php
   php -l API/paiements_enregistrer.php
   # Répéter pour les fichiers modifiés
   ```

2. **Vérifier les includes :**
   ```bash
   php -r "require 'includes/helpers.php'; require 'includes/db_connection.php'; echo getPdo() ? 'OK' : 'FAIL';"
   ```

3. **Tester les endpoints API (après corrections) :**
   - `GET /API/profil_search_users.php?q=test` → doit retourner du JSON (pas 500)
   - `GET /API/messagerie_search_sav.php?q=test` → idem
   - `POST /API/paiements_enregistrer.php` sans CSRF → doit retourner 403
   - `POST /API/paiements_enregistrer.php` avec CSRF valide → doit fonctionner

4. **Logs à surveiller :**
   - `error_log` : ne doit pas contenir de SQL/params en production
   - Vérifier que `DEBUG_MODE` est à `false` dans `historique.php`

### Pages / flows à retester

| Page / flow | Vérifications |
|-------------|---------------|
| Login / Logout | Connexion, déconnexion, redirection |
| Dashboard | Chargement, cartes, stats |
| Clients | Liste, ajout, fiche, upload documents |
| Paiements | Enregistrement, génération reçu, envoi email |
| Stock | Ajout produit, mouvement papier, scan code-barres |
| Messagerie / Chatroom | Envoi message, upload image, recherche SAV/livraisons |
| Historique | Filtres utilisateur/date, pas d’erreur 500 |
| Profil | Recherche utilisateurs, édition, permissions |
| Factures | Vue PDF, régénération |
| Maps | Géocodage, itinéraire OSRM |

---

## 5) Top 10 priorités à corriger

1. **CSRF sur paiements_enregistrer.php** (CRITIQUE)
2. **Path traversal view_facture.php** (CRITIQUE)
3. **$pdo manquant dans messagerie_search_sav.php et messagerie_search_livraisons.php** (MAJEUR)
4. **$pdo manquant dans profil_search_users.php** (MAJEUR)
5. **Auth ou rate-limit sur osrm_route.php** (MAJEUR)
6. **CSRF sur chatroom_upload_image.php** (MAJEUR)
7. **Créer .env.example** (MOYEN)
8. **Désactiver DEBUG_MODE en production (historique.php)** (MOYEN)
9. **Migrer db.php vers getPdo() partout** (MINEUR)
10. **Supprimer le doublon maps-enhancements.js à la racine** (MINEUR)

---

## 6) Fichiers de configuration proposés

### .env.example

```env
# Base de données (Railway / MySQL)
MYSQLHOST=localhost
MYSQLPORT=3306
MYSQLDATABASE=cccomputer
MYSQLUSER=root
MYSQLPASSWORD=

# SMTP
SMTP_ENABLED=false
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_FROM_EMAIL=facture@example.com
SMTP_FROM_NAME=CCComputer Facturation

# Test SMTP (token requis pour /test_smtp.php)
SMTP_TEST_TOKEN=

# App
APP_ENV=production

# Sentry (optionnel)
SENTRY_DSN=

# SFTP Import (cron)
SFTP_HOST=
SFTP_USER=
SFTP_PASS=
SFTP_PORT=22
SFTP_DIR=.
SFTP_IMPORT_ALL=0
SFTP_IMPORT_DRY_RUN=0
SFTP_MOVE_TO_PROCESSED=0

# IONOS Import
IONOS_URL=
IONOS_IMPORT_DRY_RUN=0
```

---

*Fin du rapport d’audit.*
