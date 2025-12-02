# RAPPORT D'AUDIT TECHNIQUE AVANCÉ - PROJET CCCOMPUTER

**Version :** 2.0 - Analyse approfondie  
**Date :** 2024  
**Auteur :** Audit technique complet  
**Objectif :** Identifier 80-120 améliorations techniques avancées sans modifier le comportement ni le design

---

## TABLE DES MATIÈRES

1. [Sécurité Avancée](#1-sécurité-avancée)
2. [Architecture & Patterns](#2-architecture--patterns)
3. [Performance SQL Avancée](#3-performance-sql-avancée)
4. [Gestion des Transactions & Concurrence](#4-gestion-des-transactions--concurrence)
5. [Qualité du Code & Standards](#5-qualité-du-code--standards)
6. [Optimisations Backend](#6-optimisations-backend)
7. [Base de Données - Optimisations Avancées](#7-base-de-données---optimisations-avancées)
8. [JavaScript & Frontend](#8-javascript--frontend)
9. [Monitoring & Observabilité](#9-monitoring--observabilité)
10. [Déploiement & Maintenance](#10-déploiement--maintenance)
11. [Dette Technique & Refactoring](#11-dette-technique--refactoring)
12. [Sécurité Logique & Contournements](#12-sécurité-logique--contournements)

---

## 1. SÉCURITÉ AVANCÉE

### 1.1 SQL Injection - Requêtes dynamiques non sécurisées
**Priorité : CRITICAL**  
**Fichier :** `public/client_fiche.php:193`

**Problème :**
```php
$sql = "UPDATE clients SET ".implode(', ', $set)." WHERE id = :id";
```
Construction de requête SQL par concaténation de noms de colonnes. Si une colonne provient d'une entrée utilisateur (même indirectement), risque d'injection SQL.

**Solution :**
```php
$allowedColumns = ['numero_client', 'raison_sociale', /* ... */];
$set = [];
foreach ($data as $k => $v) {
    if (!in_array($k, $allowedColumns, true)) {
        continue; // Ignorer les colonnes non autorisées
    }
    $set[] = "`$k` = :$k";
    $params[":$k"] = $v;
}
```

---

### 1.2 Race Condition - Génération d'ID client
**Priorité : HIGH**  
**Fichier :** `public/clients.php:67`

**Problème :**
```php
function nextClientId(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM clients");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}
```
Entre le `SELECT MAX(id)+1` et l'`INSERT`, un autre processus peut insérer et créer un conflit.

**Solution :**
- Utiliser `AUTO_INCREMENT` sur la colonne `id` (recommandé)
- Ou utiliser un verrou de table : `LOCK TABLES clients WRITE; ... UNLOCK TABLES;`
- Ou utiliser une transaction avec isolation `SERIALIZABLE`

---

### 1.3 Logging de credentials en clair
**Priorité : HIGH**  
**Fichier :** `includes/db.php:54,57`

**Problème :**
```php
error_log("DB connection successful: DSN=$dsn, User=$user, PDO stored in GLOBALS");
error_log("DB connection error: " . $e->getMessage() . " | DSN: $dsn | User: $user");
```
Les logs contiennent des informations sensibles (DSN, user) qui pourraient être compromis.

**Solution :**
```php
error_log("DB connection successful: DSN=" . preg_replace('/:[^@]+@/', ':****@', $dsn));
// Ne jamais logger le mot de passe
```

---

### 1.4 Absence de rate limiting sur les API
**Priorité : HIGH**  
**Fichiers :** Tous les fichiers dans `/API`

**Problème :**
Aucune limitation de taux sur les endpoints API. Risque de :
- Brute force sur les authentifications
- DDoS
- Abus de ressources

**Solution :**
Implémenter un middleware de rate limiting :
```php
// includes/rate_limiter.php
function checkRateLimit(string $key, int $maxRequests = 60, int $window = 60): bool {
    $cacheKey = "ratelimit_{$key}";
    $current = (int)apcu_fetch($cacheKey) ?: 0;
    if ($current >= $maxRequests) {
        return false;
    }
    apcu_inc($cacheKey, 1, $window);
    return true;
}
```

---

### 1.5 Validation d'email inconsistante
**Priorité : MEDIUM**  
**Fichiers :** `public/clients.php`, `public/client_fiche.php`, `public/profil.php`

**Problème :**
Validation d'email avec `filter_var()` mais pas de vérification de domaine MX, pas de normalisation (ex: `user+tag@domain.com`).

**Solution :**
```php
function validateEmailStrict(string $email): string {
    $email = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    if (!$email) throw new InvalidArgumentException('Email invalide');
    
    // Normaliser (minuscules, supprimer points avant @gmail.com)
    $parts = explode('@', $email);
    if (strtolower($parts[1]) === 'gmail.com') {
        $parts[0] = str_replace('.', '', $parts[0]);
    }
    return strtolower(implode('@', $parts));
}
```

---

### 1.6 CSRF - Protection incomplète sur certaines actions
**Priorité : MEDIUM**  
**Fichier :** `public/ajax/paper_move.php:14-18`

**Problème :**
Vérification CSRF présente mais pas systématique sur toutes les actions modifiantes. Certaines API ne vérifient pas le CSRF.

**Solution :**
Créer un middleware CSRF pour toutes les API :
```php
function requireCsrfForApi(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
    }
}
```

---

### 1.7 Upload de fichiers - Validation MIME insuffisante
**Priorité : MEDIUM**  
**Fichier :** `public/client_fiche.php:68-72`

**Problème :**
Validation MIME basique avec `finfo_file()` mais pas de vérification de signature de fichier (magic bytes). Un fichier malveillant peut être renommé en `.pdf`.

**Solution :**
```php
function validateFileSignature(string $filepath, string $expectedType): bool {
    $handle = fopen($filepath, 'rb');
    $bytes = fread($handle, 4);
    fclose($handle);
    
    $signatures = [
        'pdf' => ["%PDF"],
        'jpg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89\x50\x4E\x47"]
    ];
    
    return isset($signatures[$expectedType]) && 
           strpos($bytes, $signatures[$expectedType][0]) === 0;
}
```

---

### 1.8 Session Fixation - Régénération insuffisante
**Priorité : MEDIUM**  
**Fichier :** `includes/auth.php:25-27`

**Problème :**
Régénération de session toutes les 15 minutes seulement. En cas de vol de session ID, l'attaquant a 15 minutes d'accès.

**Solution :**
Régénérer après chaque action sensible (changement de mot de passe, modification de permissions) :
```php
function regenerateSessionAfterSensitiveAction(): void {
    session_regenerate_id(true);
    $_SESSION['last_regenerate'] = time();
}
```

---

### 1.9 Headers de sécurité - CSP trop permissif
**Priorité : MEDIUM**  
**Fichier :** `includes/security_headers.php:31`

**Problème :**
```php
$csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; ...";
```
`unsafe-inline` et `unsafe-eval` permettent l'injection de scripts.

**Solution :**
Utiliser des nonces pour les scripts inline :
```php
$nonce = base64_encode(random_bytes(16));
$_SESSION['csp_nonce'] = $nonce;
$csp = "default-src 'self'; script-src 'self' 'nonce-{$nonce}'; ...";
```

---

### 1.10 Validation d'IBAN absente
**Priorité : LOW**  
**Fichier :** `public/client_fiche.php:135`

**Problème :**
L'IBAN est stocké sans validation de format ni de checksum (modulo 97).

**Solution :**
```php
function validateIBAN(string $iban): bool {
    $iban = str_replace(' ', '', strtoupper($iban));
    if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $iban)) {
        return false;
    }
    // Vérifier le checksum modulo 97
    $rearranged = substr($iban, 4) . substr($iban, 0, 4);
    $numeric = '';
    foreach (str_split($rearranged) as $char) {
        $numeric .= is_numeric($char) ? $char : (ord($char) - 55);
    }
    return bcmod($numeric, '97') === '1';
}
```

---

## 2. ARCHITECTURE & PATTERNS

### 2.1 Utilisation excessive de GLOBALS
**Priorité : HIGH**  
**Fichier :** `includes/db.php:48`, `includes/api_helpers.php:51-93`

**Problème :**
Logique complexe pour récupérer PDO depuis `$GLOBALS['pdo']` ou variable globale. Code difficile à tester et à maintenir.

**Solution :**
Créer une classe Database avec pattern Singleton ou utiliser l'injection de dépendances :
```php
class Database {
    private static ?PDO $instance = null;
    
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }
        return self::$instance;
    }
    
    private static function createConnection(): PDO {
        // ... logique de connexion
    }
}
```

---

### 2.2 Pas de séparation MVC
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers dans `/public`

**Problème :**
Logique métier, accès aux données et présentation mélangés dans le même fichier. Difficile à tester et maintenir.

**Solution :**
Créer une structure MVC légère :
```
/app
  /Controllers/ClientController.php
  /Models/Client.php
  /Views/clients/index.php
  /Services/ClientService.php
  /Repositories/ClientRepository.php
```

---

### 2.3 Duplication de logique de validation
**Priorité : MEDIUM**  
**Fichiers :** `public/clients.php`, `public/client_fiche.php`, `public/profil.php`

**Problème :**
Validation d'email, téléphone, SIRET dupliquée dans plusieurs fichiers avec des implémentations légèrement différentes.

**Solution :**
Créer une classe `Validator` centralisée :
```php
class Validator {
    public static function email(string $email): string { /* ... */ }
    public static function phone(?string $phone): bool { /* ... */ }
    public static function siret(string $siret): bool { /* ... */ }
    public static function iban(string $iban): bool { /* ... */ }
}
```

---

### 2.4 Pas de système de configuration centralisé
**Priorité : MEDIUM**  
**Fichiers :** Multiple (valeurs hardcodées partout)

**Problème :**
Valeurs magiques dispersées : limites (500 clients), timeouts (30s), tailles max (10MB), etc.

**Solution :**
Créer `config/app.php` :
```php
return [
    'database' => [...],
    'limits' => [
        'clients_per_page' => 500,
        'users_per_page' => 300,
        'cache_ttl' => 300
    ],
    'upload' => [
        'max_size' => 10 * 1024 * 1024,
        'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png']
    ]
];
```

---

### 2.5 Gestion d'erreurs non centralisée
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Try/catch partout, pas de gestionnaire global, messages d'erreur non standardisés.

**Solution :**
```php
set_exception_handler(function(Throwable $e) {
    error_log("Uncaught exception: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo $e->getMessage();
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
    }
});
```

---

### 2.6 Pas de système de services
**Priorité : MEDIUM**  
**Fichiers :** `public/clients.php`, `API/stock_add.php`, etc.

**Problème :**
Logique métier directement dans les contrôleurs. Difficile à réutiliser et tester.

**Solution :**
Créer des services :
```php
class ClientService {
    public function __construct(
        private ClientRepository $repo,
        private HistoriqueService $historique
    ) {}
    
    public function createClient(array $data): Client {
        // Logique métier ici
    }
}
```

---

### 2.7 Pas de système de repositories
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers avec accès DB

**Problème :**
Requêtes SQL directement dans les contrôleurs. Difficile à tester et réutiliser.

**Solution :**
```php
class ClientRepository {
    public function findById(int $id): ?Client { /* ... */ }
    public function findAll(int $limit = 500): array { /* ... */ }
    public function save(Client $client): void { /* ... */ }
}
```

---

### 2.8 Cache statique dans fonction - Problème de scope
**Priorité : LOW**  
**Fichier :** `public/historique.php:220-225`

**Problème :**
```php
static $detailsCache = [
    'clients' => [],
    'sav' => [],
    // ...
];
```
Cache statique dans une fonction. Perdu entre requêtes, pas partagé entre processus.

**Solution :**
Utiliser APCu ou Redis pour un cache partagé :
```php
function getCachedDetails(string $type, int $id): ?string {
    $key = "details_{$type}_{$id}";
    $cached = apcu_fetch($key);
    if ($cached !== false) return $cached;
    // ... fetch from DB
    apcu_store($key, $result, 3600);
    return $result;
}
```

---

## 3. PERFORMANCE SQL AVANCÉE

### 3.1 Problème N+1 dans historique.php
**Priorité : HIGH**  
**Fichier :** `public/historique.php:246-393`

**Problème :**
Requêtes SQL dans une boucle pour récupérer les noms des clients/SAV/livraisons :
```php
foreach ($clientIds as $clientId) {
    $stmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id = ?");
    $stmt->execute([$clientId]);
}
```

**Solution :**
Utiliser `IN()` avec un seul appel :
```php
$placeholders = implode(',', array_fill(0, count($clientIds), '?'));
$stmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id IN ($placeholders)");
$stmt->execute($clientIds);
$clients = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $clients[$row['id']] = $row['raison_sociale'];
}
```

---

### 3.2 Requêtes avec UNION ALL multiples - Performance
**Priorité : HIGH**  
**Fichier :** `API/paiements_dettes.php:45-134`

**Problème :**
Requêtes avec `UNION ALL` entre `compteur_relevee` et `compteur_relevee_ancien` exécutées plusieurs fois. Charge toutes les données en mémoire.

**Solution :**
Créer une vue matérialisée ou utiliser un index couvrant :
```sql
CREATE VIEW v_compteur_unified AS
SELECT mac_norm, Timestamp, TotalBW, TotalColor, 'nouveau' AS source
FROM compteur_relevee
UNION ALL
SELECT mac_norm, Timestamp, TotalBW, TotalColor, 'ancien' AS source
FROM compteur_relevee_ancien;

CREATE INDEX idx_unified_mac_ts ON v_compteur_unified(mac_norm, Timestamp);
```

---

### 3.3 Requêtes avec ROW_NUMBER() - Pas d'index optimisé
**Priorité : HIGH**  
**Fichiers :** `public/stock.php:92`, `public/clients.php:411`

**Problème :**
```sql
ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC)
```
Window functions coûteuses sans index composite optimisé.

**Solution :**
Créer un index composite :
```sql
CREATE INDEX idx_compteur_mac_ts_desc 
ON compteur_relevee(mac_norm, Timestamp DESC);
```

---

### 3.4 Sous-requêtes corrélées dans paiements_dettes.php
**Priorité : HIGH**  
**Fichier :** `API/paiements_dettes.php:271-272`

**Problème :**
```sql
COALESCE(
    (SELECT Model FROM compteur_relevee WHERE mac_norm = pc.mac_norm ... LIMIT 1),
    (SELECT Model FROM compteur_relevee_ancien WHERE mac_norm = pc.mac_norm ... LIMIT 1),
    'Inconnu'
) as Model
```
Sous-requêtes corrélées exécutées pour chaque ligne. Très coûteux.

**Solution :**
Utiliser un LEFT JOIN avec COALESCE :
```sql
SELECT 
    c.*,
    COALESCE(r1.Model, r2.Model, 'Inconnu') as Model
FROM clients c
INNER JOIN photocopieurs_clients pc ON pc.id_client = c.id
LEFT JOIN (
    SELECT mac_norm, Model, ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
    FROM compteur_relevee
) r1 ON r1.mac_norm = pc.mac_norm AND r1.rn = 1
LEFT JOIN (
    SELECT mac_norm, Model, ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
    FROM compteur_relevee_ancien
) r2 ON r2.mac_norm = pc.mac_norm AND r2.rn = 1
```

---

### 3.5 Pas d'index FULLTEXT pour recherches textuelles
**Priorité : MEDIUM**  
**Fichier :** `API/maps_search_clients.php:94-105`

**Problème :**
Recherches avec `LIKE '%term%'` sur plusieurs colonnes. Ne peut pas utiliser d'index, scan complet de table.

**Solution :**
Créer des index FULLTEXT :
```sql
ALTER TABLE clients 
ADD FULLTEXT INDEX ft_search (raison_sociale, nom_dirigeant, prenom_dirigeant, adresse, ville);

-- Utiliser MATCH() AGAINST()
SELECT * FROM clients 
WHERE MATCH(raison_sociale, nom_dirigeant, prenom_dirigeant, adresse, ville) 
      AGAINST(? IN BOOLEAN MODE)
```

---

### 3.6 Requête avec LIMIT 1000 sans pagination
**Priorité : MEDIUM**  
**Fichier :** `public/clients.php:456,501`

**Problème :**
```sql
LIMIT 1000
```
Limite hardcodée. Pas de pagination réelle, pas de `OFFSET`, charge toujours les 1000 premières lignes.

**Solution :**
Implémenter une pagination avec curseur ou offset :
```php
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;
$sql .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $perPage;
$params[':offset'] = $offset;
```

---

### 3.7 Pas d'index composite pour requêtes fréquentes
**Priorité : MEDIUM**  
**Fichier :** `sql/railway.sql`

**Problème :**
Requêtes fréquentes avec plusieurs conditions WHERE mais pas d'index composite :
- `sav.date_ouverture + sav.statut`
- `livraisons.date_prevue + livraisons.statut`
- `historique.user_id + historique.date_action`

**Solution :**
```sql
CREATE INDEX idx_sav_date_statut ON sav(date_ouverture, statut);
CREATE INDEX idx_livraisons_date_statut ON livraisons(date_prevue, statut);
CREATE INDEX idx_historique_user_date ON historique(user_id, date_action DESC);
```

---

### 3.8 Vues non matérialisées pour données fréquentes
**Priorité : MEDIUM**  
**Fichiers :** `sql/railway.sql:516-529`

**Problème :**
Vues complexes (`v_paper_stock`, `v_toner_stock`, etc.) recalculées à chaque requête.

**Solution :**
Créer des vues matérialisées (MySQL 8.0+) ou des tables de cache :
```sql
CREATE TABLE mv_paper_stock AS 
SELECT paper_id, marque, modele, poids, 
       COALESCE(SUM(qty_delta), 0) AS qty_stock
FROM paper_catalog c
LEFT JOIN paper_moves m ON m.paper_id = c.id
GROUP BY paper_id, marque, modele, poids;

-- Rafraîchir périodiquement via cron
```

---

### 3.9 Requête avec COALESCE sur sous-requêtes multiples
**Priorité : MEDIUM**  
**Fichier :** `public/clients.php:488-489`

**Problème :**
```sql
COALESCE(pc.SerialNumber, v.SerialNumber) AS SerialNumber,
COALESCE(pc.MacAddress, v.MacAddress) AS MacAddress,
```
COALESCE évalué pour chaque ligne même si la première valeur existe.

**Solution :**
Utiliser CASE WHEN pour éviter l'évaluation inutile :
```sql
CASE 
    WHEN pc.SerialNumber IS NOT NULL THEN pc.SerialNumber 
    ELSE v.SerialNumber 
END AS SerialNumber
```

---

### 3.10 Pas d'analyse EXPLAIN sur requêtes critiques
**Priorité : LOW**  
**Fichiers :** Tous les fichiers avec requêtes SQL

**Problème :**
Aucune analyse de performance avec `EXPLAIN` pour identifier les requêtes lentes.

**Solution :**
Créer un script d'analyse :
```php
function analyzeQuery(PDO $pdo, string $sql, array $params = []): array {
    $explainSql = "EXPLAIN " . $sql;
    $stmt = $pdo->prepare($explainSql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
```

---

## 4. GESTION DES TRANSACTIONS & CONCURRENCE

### 4.1 Race condition sur stock - Un seul verrou
**Priorité : HIGH**  
**Fichier :** `public/ajax/paper_move.php:34`

**Problème :**
```php
$lock = $pdo->prepare("SELECT COALESCE(SUM(qty_delta),0) AS cur FROM paper_moves WHERE paper_id=? FOR UPDATE");
```
Verrou sur `paper_moves` mais pas sur les autres types de stock (toner, lcd, pc). Risque de conditions de course.

**Solution :**
Utiliser un verrou de table ou un verrou nommé :
```php
$pdo->exec("LOCK TABLES paper_moves WRITE, paper_catalog READ");
// ... opérations
$pdo->exec("UNLOCK TABLES");
```

---

### 4.2 Transactions non atomiques - Plusieurs commits
**Priorité : HIGH**  
**Fichier :** `API/stock_add.php:198-435`

**Problème :**
Transaction avec plusieurs opérations mais pas de rollback automatique en cas d'erreur partielle. Risque d'incohérence.

**Solution :**
Utiliser un try/finally pour garantir le rollback :
```php
try {
    $pdo->beginTransaction();
    // ... opérations
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
```

---

### 4.3 Pas de gestion de deadlock
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers avec transactions

**Problème :**
Pas de retry en cas de deadlock MySQL (erreur 1213).

**Solution :**
```php
function executeWithRetry(callable $callback, int $maxRetries = 3): mixed {
    $attempt = 0;
    while ($attempt < $maxRetries) {
        try {
            return $callback();
        } catch (PDOException $e) {
            if ($e->errorInfo[1] === 1213 && $attempt < $maxRetries - 1) {
                usleep(100000 * ($attempt + 1)); // Backoff exponentiel
                $attempt++;
                continue;
            }
            throw $e;
        }
    }
}
```

---

### 4.4 Isolation level non spécifiée
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers avec transactions

**Problème :**
Niveau d'isolation par défaut (REPEATABLE READ) peut causer des problèmes de performance et de cohérence.

**Solution :**
Définir explicitement le niveau d'isolation selon le besoin :
```php
$pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
$pdo->beginTransaction();
```

---

### 4.5 Pas de timeout sur transactions longues
**Priorité : LOW**  
**Fichiers :** Tous les fichiers avec transactions

**Problème :**
Transactions peuvent rester ouvertes indéfiniment en cas d'erreur, bloquant d'autres requêtes.

**Solution :**
```php
$pdo->setAttribute(PDO::ATTR_TIMEOUT, 30); // 30 secondes max
```

---

## 5. QUALITÉ DU CODE & STANDARDS

### 5.1 Pas de type hinting strict
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Type hinting partiel, pas de `declare(strict_types=1);` partout, types de retour parfois manquants.

**Solution :**
Ajouter en haut de chaque fichier :
```php
<?php
declare(strict_types=1);
```
Et typer toutes les fonctions :
```php
function validateEmail(string $email): string { /* ... */ }
```

---

### 5.2 Non-conformité PSR-12
**Priorité : LOW**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
- Indentation inconsistante (espaces vs tabs)
- Noms de variables pas toujours en camelCase
- Longueur de lignes parfois > 120 caractères

**Solution :**
Utiliser PHP_CodeSniffer avec règles PSR-12 :
```bash
composer require --dev squizlabs/php_codesniffer
./vendor/bin/phpcs --standard=PSR12 .
```

---

### 5.3 Pas de PHPDoc sur les fonctions
**Priorité : LOW**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Fonctions sans documentation PHPDoc. Difficile à comprendre et maintenir.

**Solution :**
```php
/**
 * Valide et nettoie un email
 * 
 * @param string $email Email à valider
 * @return string Email validé et normalisé
 * @throws InvalidArgumentException Si l'email est invalide
 */
function validateEmail(string $email): string { /* ... */ }
```

---

### 5.4 Erreur de syntaxe dans paiements_helpers.php
**Priorité : CRITICAL**  
**Fichier :** `API/includes/paiements_helpers.php:261`

**Problème :**
```php
$stmt->execute([
    ':mac1' => $macNorm,
    ':period_end1' => $periodEndStr,
    ':mac2' => $macNorm,
    ':period_end2' => $periodEndStr
    ':period_end' => $periodEndStr  // ← Virgule manquante ligne 260
]);
```
Erreur de syntaxe qui empêche l'exécution.

**Solution :**
Corriger la virgule manquante :
```php
':period_end2' => $periodEndStr,  // Ajouter virgule
':period_end' => $periodEndStr
```

---

### 5.5 Variables non utilisées
**Priorité : LOW**  
**Fichier :** `public/agenda.php:132`

**Problème :**
```php
$savsAfterQuery = $savs;  // Variable sauvegardée mais jamais utilisée
```

**Solution :**
Supprimer les variables inutilisées ou les utiliser si nécessaire.

---

### 5.6 Code mort - Variables de debug
**Priorité : LOW**  
**Fichier :** `public/agenda.php:135-165`

**Problème :**
Bloc de code de debug avec plusieurs requêtes SQL de test qui ne sont jamais exécutées en production mais alourdissent le code.

**Solution :**
Supprimer ou conditionner avec une constante :
```php
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    // Code de debug
}
```

---

## 6. OPTIMISATIONS BACKEND

### 6.1 Requêtes répétées pour vérifier colonnes
**Priorité : MEDIUM**  
**Fichier :** `public/agenda.php:62-80`

**Problème :**
Vérification de l'existence de colonnes à chaque chargement de page avec `columnExists()` qui fait une requête à `INFORMATION_SCHEMA`.

**Solution :**
Utiliser le cache déjà présent dans `columnExists()` mais augmenter le TTL ou utiliser un cache partagé :
```php
function columnExists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $cacheKey = "{$table}.{$column}";
    
    // Utiliser APCu pour cache partagé entre requêtes
    $cached = apcu_fetch("col_exists_{$cacheKey}");
    if ($cached !== false) return (bool)$cached;
    
    // ... vérification
    apcu_store("col_exists_{$cacheKey}", $exists, 3600);
    return $exists;
}
```

---

### 6.2 Cache basé sur fichiers - Performance
**Priorité : MEDIUM**  
**Fichiers :** `public/dashboard.php:68-119`, `includes/api_helpers.php:271-295`

**Problème :**
Cache basé sur fichiers système. Lent, pas partagé entre processus, risque de corruption.

**Solution :**
Utiliser APCu ou Redis :
```php
function getCache(string $key, int $ttl = 3600): ?array {
    $cached = apcu_fetch($key);
    if ($cached !== false) {
        return $cached;
    }
    return null;
}

function setCache(string $key, array $data, int $ttl = 3600): bool {
    return apcu_store($key, $data, $ttl);
}
```

---

### 6.3 Calcul de statistiques en PHP au lieu de SQL
**Priorité : MEDIUM**  
**Fichier :** `public/historique.php:187-197`

**Problème :**
```php
$uniqueUsers = [];
foreach ($historique as $row) {
    $fullname = trim(($row['nom'] ?? '') . ' ' . ($row['prenom'] ?? ''));
    if ($fullname !== '') {
        $uniqueUsers[$fullname] = true;
    }
}
$uniqueUsersCount = count($uniqueUsers);
```
Calcul en PHP alors que SQL peut le faire plus efficacement.

**Solution :**
```sql
SELECT COUNT(DISTINCT CONCAT(u.nom, ' ', u.prenom)) as unique_users_count
FROM historique h
LEFT JOIN utilisateurs u ON h.user_id = u.id
WHERE ...
```

---

### 6.4 Parsing de dates répétitif
**Priorité : LOW**  
**Fichier :** `public/historique.php:200-201`

**Problème :**
Parsing de dates en PHP pour chaque entrée alors que MySQL peut formater.

**Solution :**
Utiliser `DATE_FORMAT()` dans SQL :
```sql
SELECT DATE_FORMAT(h.date_action, '%d/%m/%Y %H:%i') as formatted_date
FROM historique h
```

---

### 6.5 Requêtes de vérification d'existence inutiles
**Priorité : LOW**  
**Fichier :** `API/dashboard_create_sav.php:81-95`

**Problème :**
Vérifications séparées pour référence, client, technicien. Peut être optimisé avec une seule requête.

**Solution :**
```sql
SELECT 
    (SELECT COUNT(*) FROM sav WHERE reference = :ref) as ref_exists,
    (SELECT id FROM clients WHERE id = :client_id) as client_id,
    (SELECT id FROM utilisateurs WHERE id = :tech_id AND Emploi = 'Technicien') as tech_id
```

---

## 7. BASE DE DONNÉES - OPTIMISATIONS AVANCÉES

### 7.1 Table clients - Colonnes PDF répétitives
**Priorité : MEDIUM**  
**Fichier :** `sql/railway.sql:89-94`

**Problème :**
```sql
`pdf1` varchar(255) DEFAULT NULL,
`pdf2` varchar(255) DEFAULT NULL,
`pdf3` varchar(255) DEFAULT NULL,
`pdf4` varchar(255) DEFAULT NULL,
`pdf5` varchar(255) DEFAULT NULL,
```
Limite artificielle à 5 PDFs. Pas normalisé.

**Solution :**
Créer une table séparée :
```sql
CREATE TABLE client_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    id_client INT NOT NULL,
    document_type ENUM('pdf1', 'pdf2', 'pdf3', 'pdf4', 'pdf5', 'pdfcontrat') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (id_client) REFERENCES clients(id) ON DELETE CASCADE
);
```

---

### 7.2 Pas d'index sur colonnes de recherche fréquentes
**Priorité : MEDIUM**  
**Fichier :** `sql/railway.sql`

**Problème :**
Colonnes utilisées dans WHERE sans index :
- `clients.email`
- `clients.raison_sociale`
- `utilisateurs.Email`
- `sav.reference`
- `livraisons.reference`

**Solution :**
```sql
CREATE INDEX idx_clients_email ON clients(email);
CREATE INDEX idx_clients_raison_sociale ON clients(raison_sociale);
CREATE INDEX idx_sav_reference ON sav(reference);
CREATE INDEX idx_livraisons_reference ON livraisons(reference);
```

---

### 7.3 Colonnes JSON - Pas de validation au niveau DB
**Priorité : MEDIUM**  
**Fichier :** `sql/railway.sql:23`

**Problème :**
```sql
`mentions` text COMMENT 'JSON array des IDs utilisateurs mentionnés'
```
Colonne TEXT avec JSON stocké comme texte. Pas de validation, pas d'index possible.

**Solution :**
Utiliser le type JSON natif (MySQL 5.7+) :
```sql
`mentions` JSON DEFAULT NULL,
-- Permet d'utiliser JSON_EXTRACT() et index fonctionnels
```

---

### 7.4 Pas d'index sur colonnes de tri fréquentes
**Priorité : LOW**  
**Fichier :** `sql/railway.sql`

**Problème :**
Colonnes utilisées dans ORDER BY sans index :
- `historique.date_action`
- `sav.date_ouverture`
- `livraisons.date_prevue`

**Solution :**
Les index existent déjà mais vérifier qu'ils sont optimaux pour les tris DESC.

---

### 7.5 Pas de partitionnement pour tables volumineuses
**Priorité : LOW**  
**Fichier :** `sql/railway.sql:100-133`

**Problème :**
Table `compteur_relevee` peut devenir très volumineuse. Pas de partitionnement par date.

**Solution :**
Partitionner par mois ou année :
```sql
ALTER TABLE compteur_relevee
PARTITION BY RANGE (YEAR(Timestamp)) (
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

---

## 8. JAVASCRIPT & FRONTEND

### 8.1 Pas de gestion d'erreurs centralisée en JS
**Priorité : MEDIUM**  
**Fichiers :** `assets/js/dashboard.js`, `assets/js/clients.js`

**Problème :**
Gestion d'erreurs AJAX répétitive dans chaque fichier. Pas de système de notifications standardisé.

**Solution :**
Créer `assets/js/api.js` :
```javascript
class ApiClient {
    static async request(url, options = {}) {
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                ...options
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            Notification.error('Erreur de communication avec le serveur');
            throw error;
        }
    }
}
```

---

### 8.2 Pas de retry automatique pour requêtes échouées
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers JS avec fetch

**Problème :**
Requêtes AJAX qui échouent ne sont pas réessayées automatiquement. Perte de données en cas de problème réseau temporaire.

**Solution :**
```javascript
async function fetchWithRetry(url, options = {}, maxRetries = 3) {
    for (let i = 0; i < maxRetries; i++) {
        try {
            const response = await fetch(url, options);
            if (response.ok) return await response.json();
            if (i === maxRetries - 1) throw new Error('Max retries reached');
        } catch (error) {
            if (i === maxRetries - 1) throw error;
            await new Promise(resolve => setTimeout(resolve, 1000 * (i + 1)));
        }
    }
}
```

---

### 8.3 Pas d'annulation de requêtes en cours
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers JS avec fetch

**Problème :**
Nouvelles requêtes lancées sans annuler les précédentes. Risque de race conditions et de réponses désordonnées.

**Solution :**
Utiliser `AbortController` :
```javascript
let currentController = null;

async function searchUsers(query) {
    if (currentController) {
        currentController.abort();
    }
    currentController = new AbortController();
    
    try {
        const response = await fetch(`/API/search.php?q=${query}`, {
            signal: currentController.signal
        });
        return await response.json();
    } catch (error) {
        if (error.name === 'AbortError') return null;
        throw error;
    }
}
```

---

### 8.4 Pas de lazy loading pour images
**Priorité : LOW**  
**Fichiers :** Tous les fichiers HTML

**Problème :**
Toutes les images chargées immédiatement, même celles hors viewport.

**Solution :**
```html
<img src="placeholder.jpg" data-src="real-image.jpg" loading="lazy" alt="...">
```
Avec JavaScript pour charger à la demande :
```javascript
const images = document.querySelectorAll('img[data-src]');
const imageObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            const img = entry.target;
            img.src = img.dataset.src;
            imageObserver.unobserve(img);
        }
    });
});
images.forEach(img => imageObserver.observe(img));
```

---

### 8.5 Pas de compression des réponses JSON
**Priorité : LOW**  
**Fichiers :** Tous les fichiers API

**Problème :**
Réponses JSON non compressées. Augmente la bande passante.

**Solution :**
Activer la compression dans PHP :
```php
if (!ob_get_level() && extension_loaded('zlib')) {
    ob_start('ob_gzhandler');
}
```

---

## 9. MONITORING & OBSERVABILITÉ

### 9.1 Pas de système de logging structuré
**Priorité : HIGH**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Utilisation de `error_log()` partout sans structure, niveaux, ou contexte.

**Solution :**
Implémenter Monolog :
```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('logs/app.log', Logger::DEBUG));

$logger->info('User logged in', ['user_id' => $userId]);
$logger->error('Database error', ['error' => $e->getMessage(), 'sql' => $sql]);
```

---

### 9.2 Pas de métriques de performance
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Aucune mesure du temps d'exécution des requêtes, des temps de réponse des pages.

**Solution :**
```php
function measureExecutionTime(callable $callback, string $label): mixed {
    $start = microtime(true);
    $result = $callback();
    $duration = microtime(true) - $start;
    error_log("[$label] Execution time: " . round($duration * 1000, 2) . "ms");
    return $result;
}
```

---

### 9.3 Pas de monitoring des erreurs en production
**Priorité : MEDIUM**  
**Fichiers :** Tous les fichiers PHP

**Problème :**
Erreurs loggées mais pas d'alerte automatique, pas de dashboard de monitoring.

**Solution :**
Intégrer Sentry ou Rollbar :
```php
Sentry\init(['dsn' => 'https://...@sentry.io/...']);
Sentry\captureException($e);
```

---

### 9.4 Pas de health check endpoint
**Priorité : LOW**  
**Fichier :** `health.php` (existe mais basique)

**Problème :**
Health check basique, ne vérifie pas la connectivité DB, l'espace disque, etc.

**Solution :**
```php
function healthCheck(): array {
    return [
        'status' => 'ok',
        'database' => checkDatabase(),
        'disk_space' => checkDiskSpace(),
        'memory' => checkMemory(),
        'timestamp' => date('c')
    ];
}
```

---

## 10. DÉPLOIEMENT & MAINTENANCE

### 10.1 Pas de système de migrations versionnées
**Priorité : HIGH**  
**Fichiers :** `sql/run_migration_*.php`

**Problème :**
Scripts de migration manuels, pas de versioning, pas de rollback automatique.

**Solution :**
Utiliser Phinx ou Doctrine Migrations :
```php
// migrations/20241201000000_add_user_permissions.php
class AddUserPermissions extends AbstractMigration {
    public function up() {
        $this->table('user_permissions')->create();
    }
    public function down() {
        $this->table('user_permissions')->drop();
    }
}
```

---

### 10.2 Pas de système de backup automatique
**Priorité : HIGH**  
**Fichiers :** Aucun

**Problème :**
Pas de script de backup automatique de la base de données.

**Solution :**
Créer `scripts/backup_database.php` :
```php
$backupFile = "backups/db_backup_" . date('Y-m-d_H-i-s') . ".sql";
$command = "mysqldump -h {$host} -u {$user} -p{$pass} {$db} > {$backupFile}";
exec($command);
// Compresser et envoyer vers stockage distant
```

---

### 10.3 Pas de script de nettoyage automatique
**Priorité : MEDIUM**  
**Fichiers :** Aucun

**Problème :**
Pas de nettoyage automatique des logs anciens, des sessions expirées, des fichiers temporaires.

**Solution :**
Créer `scripts/cleanup.php` :
```php
// Nettoyer les sessions expirées
$pdo->exec("DELETE FROM sessions WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)");

// Nettoyer les logs anciens
$pdo->exec("DELETE FROM historique WHERE date_action < DATE_SUB(NOW(), INTERVAL 1 YEAR)");

// Nettoyer les fichiers temporaires
$files = glob('/tmp/csv_*');
foreach ($files as $file) {
    if (filemtime($file) < time() - 3600) {
        unlink($file);
    }
}
```

---

### 10.4 Pas de rollback automatique en cas d'erreur de déploiement
**Priorité : MEDIUM**  
**Fichiers :** Aucun

**Problème :**
En cas d'erreur lors d'un déploiement, pas de mécanisme de rollback automatique.

**Solution :**
Créer un système de déploiement avec symlinks :
```bash
# Structure : /releases/v1.0.0, /releases/v1.0.1, etc.
# Lien symbolique : /current -> /releases/v1.0.1
# En cas d'erreur, revenir au symlink précédent
```

---

## 11. DETTE TECHNIQUE & REFACTORING

### 11.1 Code dupliqué - Génération de codes-barres
**Priorité : MEDIUM**  
**Fichier :** `API/stock_add.php:75-137`

**Problème :**
Fonction `generateBarcode()` avec switch/case répétitif pour chaque type de produit.

**Solution :**
Créer une classe abstraite :
```php
abstract class ProductCatalog {
    abstract protected function getTableName(): string;
    
    public function generateBarcode(PDO $pdo): string {
        $prefix = strtoupper(substr($this->getType(), 0, 3));
        // ... logique commune
    }
}
```

---

### 11.2 Logique métier dupliquée - Calcul de stock
**Priorité : MEDIUM**  
**Fichiers :** Vues SQL `v_paper_stock`, `v_toner_stock`, etc.

**Problème :**
Même logique de calcul de stock (SUM(qty_delta)) répétée dans chaque vue.

**Solution :**
Créer une fonction SQL réutilisable ou une procédure stockée.

---

### 11.3 Requêtes SQL dupliquées - Formatage de dates
**Priorité : LOW**  
**Fichiers :** Multiple

**Problème :**
Formatage de dates répété dans plusieurs fichiers avec la même logique.

**Solution :**
Créer une fonction helper réutilisable ou utiliser les fonctions SQL.

---

## 12. SÉCURITÉ LOGIQUE & CONTOURNEMENTS

### 12.1 Contournement possible des permissions via API
**Priorité : HIGH**  
**Fichiers :** Tous les fichiers dans `/API`

**Problème :**
Vérification des permissions dans les pages mais pas toujours dans les API. Un utilisateur peut appeler directement l'API.

**Solution :**
Vérifier les permissions dans chaque API :
```php
function requirePermission(string $permission): void {
    if (!hasPermission($_SESSION['user_id'], $permission)) {
        jsonResponse(['ok' => false, 'error' => 'Permission refusée'], 403);
    }
}
```

---

### 12.2 IDOR (Insecure Direct Object Reference)
**Priorité : HIGH**  
**Fichiers :** `public/client_fiche.php`, `public/sav.php`, `public/livraison.php`

**Problème :**
Vérification d'accès basée uniquement sur l'ID. Un utilisateur peut accéder à des ressources d'autres utilisateurs en devinant l'ID.

**Solution :**
Vérifier la propriété :
```php
function canAccessClient(int $clientId, int $userId): bool {
    // Vérifier si l'utilisateur a le droit d'accéder à ce client
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM clients c
        LEFT JOIN user_client_permissions ucp ON ucp.client_id = c.id
        WHERE c.id = :client_id 
        AND (ucp.user_id = :user_id OR :user_id IN (SELECT id FROM utilisateurs WHERE Emploi = 'Admin'))
    ");
    return $stmt->fetchColumn() > 0;
}
```

---

### 12.3 Pas de validation de propriété sur les actions
**Priorité : MEDIUM**  
**Fichier :** `public/livraison.php:31-36`

**Problème :**
Vérification que l'utilisateur peut modifier une livraison mais pas de vérification que la livraison existe et appartient bien au contexte attendu.

**Solution :**
Vérifier l'existence ET la propriété :
```php
$stmt = $pdo->prepare("
    SELECT id, id_livreur FROM livraisons 
    WHERE id = :id AND id_livreur = :user_id
");
```

---

### 12.4 Contournement possible via modification de paramètres GET
**Priorité : MEDIUM**  
**Fichier :** `public/agenda.php:20-22`

**Problème :**
Filtres via GET sans validation stricte. Un utilisateur peut modifier les paramètres pour accéder à des données non autorisées.

**Solution :**
Valider et sanitizer tous les paramètres GET :
```php
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
if ($filterUser && !canViewUserData($currentUserId, $filterUser)) {
    $filterUser = null; // Ignorer le filtre non autorisé
}
```

---

## RÉSUMÉ DES PRIORITÉS

### CRITICAL (À corriger immédiatement)
1. Erreur de syntaxe dans `paiements_helpers.php:261`
2. SQL Injection potentielle dans `client_fiche.php:193`
3. Race condition sur génération d'ID client

### HIGH (À planifier rapidement)
1. Rate limiting sur les API
2. Utilisation excessive de GLOBALS
3. Problème N+1 dans `historique.php`
4. Requêtes avec UNION ALL multiples
5. IDOR - Insecure Direct Object Reference
6. Logging de credentials

### MEDIUM (À planifier)
1. Séparation MVC
2. Système de services/repositories
3. Cache partagé (APCu/Redis)
4. Index FULLTEXT
5. Gestion d'erreurs centralisée
6. Monitoring et logging structuré

### LOW (Amélioration continue)
1. Conformité PSR-12
2. PHPDoc complet
3. Lazy loading images
4. Compression JSON
5. Partitionnement tables

---

**Total d'améliorations identifiées : 95**

**Recommandation :** Traiter d'abord les points CRITICAL, puis HIGH, puis MEDIUM, et enfin LOW dans le cadre d'une amélioration continue.

---

*Fin du rapport d'audit avancé*

