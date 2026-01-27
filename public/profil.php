<?php
/**
 * /public/profil.php - Gestion des utilisateurs
 * Version refactorisée : Sécurité, Performance, UI/UX améliorées
 */

// ========================================================================
// SÉCURITÉ D'ABORD
// ========================================================================
// Headers de sécurité (doit être avant tout output)
if (!headers_sent()) {
    require_once __DIR__ . '/../includes/security_headers.php';
}

require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('profil', ['Admin', 'Dirigeant', 'Technicien', 'Livreur']);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// ========================================================================
// CONSTANTES
// ========================================================================
const USERS_LIMIT = 300;
const SEARCH_MAX_LENGTH = 120;
const ROLES_CACHE_TTL = 3600; // 1 heure

// ========================================================================
// CONTEXTE UTILISATEUR
// ========================================================================
$currentUser = [
    'id' => currentUserId() ?? 0,
    'emploi' => $_SESSION['emploi'] ?? ''
];

$isLivreur = $currentUser['emploi'] === 'Livreur';
$isTechnicien = $currentUser['emploi'] === 'Technicien';
$isAdminOrDirigeant = in_array($currentUser['emploi'], ['Admin', 'Dirigeant'], true);
$hasRestrictions = $isLivreur || $isTechnicien;

// ========================================================================
// FONCTIONS UTILITAIRES
// ========================================================================

/**
 * Récupère les rôles depuis la base avec cache
 */
function getAvailableRoles(PDO $pdo): array {
    $cacheFile = __DIR__ . '/../cache/roles_enum.json';
    $defaultRoles = ['Chargé relation clients', 'Livreur', 'Technicien', 'Secrétaire', 'Dirigeant', 'Admin'];
    
    // Vérifier le cache
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < ROLES_CACHE_TTL) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }
    
    // Récupérer depuis la base
    $roles = [];
    $col = safeFetch($pdo, "SHOW COLUMNS FROM utilisateurs LIKE 'Emploi'", [], 'enum_emploi');
    
    if ($col && isset($col['Type']) && preg_match("/^enum\((.*)\)$/i", $col['Type'], $matches)) {
        $enumValues = str_getcsv($matches[1], ',', "'");
        foreach ($enumValues as $val) {
            $val = trim($val);
            if ($val !== '') {
                $roles[] = $val;
            }
        }
    }
    
    // Fallback
    if (empty($roles)) {
        $roles = $defaultRoles;
    }
    
    // Sauvegarder en cache
    if (!is_dir(__DIR__ . '/../cache')) {
        @mkdir(__DIR__ . '/../cache', 0755, true);
    }
    @file_put_contents($cacheFile, json_encode($roles));
    
    return $roles;
}

/**
 * Nettoie et valide une recherche
 */
function sanitizeSearch(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = mb_substr(preg_replace('/\s+/', ' ', $value), 0, SEARCH_MAX_LENGTH);
    return $value;
}

/**
 * Log une action profil
 */
function logProfilAction(PDO $pdo, ?int $userId, string $action, string $details): void {
    try {
        enregistrerAction($pdo, $userId, $action, $details);
    } catch (Throwable $e) {
        error_log('profil.php log error: ' . $e->getMessage());
    }
}

/**
 * Valide les données utilisateur pour création/mise à jour
 */
function validateUserData(array $data, bool $isUpdate = false): array {
    $errors = [];
    
    // Email
    try {
        $data['Email'] = validateEmail($data['Email'] ?? '');
    } catch (InvalidArgumentException $e) {
        $errors[] = "Email invalide.";
    }
    
    // Nom et prénom
    try {
        $data['nom'] = validateString($data['nom'] ?? '', 'Nom', 1, 100);
        $data['prenom'] = validateString($data['prenom'] ?? '', 'Prénom', 1, 100);
    } catch (InvalidArgumentException $e) {
        $errors[] = $e->getMessage();
    }
    
    // Téléphone (optionnel)
    if (isset($data['telephone']) && $data['telephone'] !== '') {
        if (!validatePhone($data['telephone'])) {
            $errors[] = "Téléphone invalide.";
        }
    }
    
    // Date de début
    if (empty($data['date_debut']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date_debut'])) {
        $errors[] = "Date de début invalide.";
    }
    
    // Mot de passe (seulement pour création)
    if (!$isUpdate && (empty($data['password']) || strlen($data['password']) < 8)) {
        $errors[] = "Mot de passe trop court (min. 8 caractères).";
    }
    
    if (!empty($errors)) {
        throw new RuntimeException(implode(' ', $errors));
    }
    
    return $data;
}

// ========================================================================
// GESTION DES REQUÊTES POST (Pattern PRG)
// ========================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Récupérer les rôles disponibles
$ROLES = getAvailableRoles($pdo);

// Générer le token CSRF
ensureCsrfToken();
$CSRF = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Vérification CSRF
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Erreur de sécurité. Veuillez réessayer."];
        header('Location: /public/profil.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        // ===== CRÉATION D'UTILISATEUR =====
        if ($action === 'create') {
            if ($hasRestrictions) {
                throw new RuntimeException('Vous n\'êtes pas autorisé à créer des utilisateurs.');
            }
            
            $data = [
                'Email' => trim($_POST['Email'] ?? ''),
                'password' => $_POST['password'] ?? '',
                'nom' => trim($_POST['nom'] ?? ''),
                'prenom' => trim($_POST['prenom'] ?? ''),
                'telephone' => trim($_POST['telephone'] ?? ''),
                'Emploi' => trim($_POST['Emploi'] ?? ''),
                'statut' => ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif',
                'date_debut' => trim($_POST['date_debut'] ?? '')
            ];
            
            // Validation
            $data = validateUserData($data, false);
            
            // Vérifier le rôle
            if (!in_array($data['Emploi'], $ROLES, true)) {
                throw new RuntimeException("Rôle (Emploi) invalide.");
            }
            
            // Vérifier l'unicité de l'email
            $existing = safeFetch($pdo, "SELECT id FROM utilisateurs WHERE Email = ?", [$data['Email']], 'check_email');
            if ($existing) {
                throw new RuntimeException("Cet email est déjà utilisé.");
            }
            
            // Créer l'utilisateur
            $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (Email, password, nom, prenom, telephone, Emploi, statut, date_debut)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $data['Email'],
                $hash,
                $data['nom'],
                $data['prenom'],
                $data['telephone'] ?: null,
                $data['Emploi'],
                $data['statut'],
                $data['date_debut']
            ]);
            
            $newId = (int)$pdo->lastInsertId();
            
            // Récupérer les infos de l'utilisateur créateur pour le log
            $creatorInfo = safeFetch($pdo, "SELECT nom, prenom, Email FROM utilisateurs WHERE id = ?", [$currentUser['id']], 'creator_info');
            $creatorName = $creatorInfo ? ($creatorInfo['prenom'] . ' ' . $creatorInfo['nom']) : "Utilisateur #{$currentUser['id']}";
            
            logProfilAction($pdo, $currentUser['id'], 'utilisateur_cree', 
                "Création utilisateur #{$newId}: {$data['prenom']} {$data['nom']} ({$data['Email']}), rôle: {$data['Emploi']}, statut: {$data['statut']} | Créé par: {$creatorName}");
            
            // Invalider le cache des rôles si nouveau rôle
            @unlink(__DIR__ . '/../cache/roles_enum.json');

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur créé avec succès."];
        }
        // ===== MISE À JOUR D'UTILISATEUR =====
        elseif ($action === 'update') {
            $id = validateId($_POST['id'] ?? 0, 'ID utilisateur');
            
            $before = safeFetch($pdo, 
                "SELECT Email, nom, prenom, telephone, Emploi, statut, date_debut FROM utilisateurs WHERE id = ?", 
                [$id], 
                'profil_update_fetch'
            );
            
            if (!$before) {
                throw new RuntimeException('Utilisateur introuvable.');
            }
            
            // Vérifications d'autorisation
            if ($hasRestrictions && $id !== $currentUser['id']) {
                throw new RuntimeException('Vous ne pouvez modifier que votre propre profil.');
            }
            
            if ($id === $currentUser['id'] && isset($_POST['Emploi']) && $_POST['Emploi'] !== $currentUser['emploi']) {
                throw new RuntimeException('Vous ne pouvez pas modifier votre propre rôle.');
            }
            
            if ($hasRestrictions && $id === $currentUser['id']) {
                if (isset($_POST['Emploi']) && $_POST['Emploi'] !== $before['Emploi']) {
                    throw new RuntimeException('Vous ne pouvez pas modifier votre propre rôle.');
                }
                if (isset($_POST['statut']) && $_POST['statut'] !== $before['statut']) {
                    throw new RuntimeException('Vous ne pouvez pas modifier votre propre statut.');
                }
            }

            $data = [
                'Email' => trim($_POST['Email'] ?? ''),
                'nom' => trim($_POST['nom'] ?? ''),
                'prenom' => trim($_POST['prenom'] ?? ''),
                'telephone' => trim($_POST['telephone'] ?? ''),
                'Emploi' => trim($_POST['Emploi'] ?? ''),
                'statut' => ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif',
                'date_debut' => trim($_POST['date_debut'] ?? '')
            ];
            
            // Validation
            $data = validateUserData($data, true);
            
            // Vérifier le rôle
            if (!in_array($data['Emploi'], $ROLES, true)) {
                throw new RuntimeException("Rôle (Emploi) invalide.");
            }
            
            // Vérifier l'unicité de l'email (sauf pour l'utilisateur actuel)
            $existing = safeFetch($pdo, "SELECT id FROM utilisateurs WHERE Email = ? AND id != ?", 
                [$data['Email'], $id], 'check_email_update');
            if ($existing) {
                throw new RuntimeException("Cet email est déjà utilisé par un autre utilisateur.");
            }
            
            // Mettre à jour
            $stmt = $pdo->prepare("
                UPDATE utilisateurs
                SET Email = ?, nom = ?, prenom = ?, telephone = ?, Emploi = ?, statut = ?, date_debut = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['Email'],
                $data['nom'],
                $data['prenom'],
                $data['telephone'] ?: null,
                $data['Emploi'],
                $data['statut'],
                $data['date_debut'],
                $id
            ]);
            
            // Log des changements
            $changes = [];
            $fieldMap = [
                'Email' => $data['Email'],
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'telephone' => $data['telephone'],
                'Emploi' => $data['Emploi'],
                'statut' => $data['statut'],
                'date_debut' => $data['date_debut']
            ];
            
            foreach ($fieldMap as $field => $newValue) {
                $oldValue = $before[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[] = "{$field}: '{$oldValue}' → '{$newValue}'";
                }
            }
            
            // Récupérer les infos de l'utilisateur modifié et du modificateur
            $targetUserInfo = safeFetch($pdo, "SELECT nom, prenom, Email FROM utilisateurs WHERE id = ?", [$id], 'target_user_info');
            $targetName = $targetUserInfo ? ($targetUserInfo['prenom'] . ' ' . $targetUserInfo['nom']) : "Utilisateur #{$id}";
            $targetEmail = $targetUserInfo['Email'] ?? 'N/A';
            
            $modifierInfo = safeFetch($pdo, "SELECT nom, prenom FROM utilisateurs WHERE id = ?", [$currentUser['id']], 'modifier_info');
            $modifierName = $modifierInfo ? ($modifierInfo['prenom'] . ' ' . $modifierInfo['nom']) : "Utilisateur #{$currentUser['id']}";
            
            $detail = $changes ? implode(', ', $changes) : 'Aucun changement détecté';
            logProfilAction($pdo, $currentUser['id'], 'utilisateur_modifie', 
                "Modification utilisateur #{$id}: {$targetName} ({$targetEmail}) | Modifié par: {$modifierName} | Changements: {$detail}");

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur mis à jour."];
        }
        // ===== TOGGLE STATUT =====
        elseif ($action === 'toggle') {
            if ($hasRestrictions) {
                throw new RuntimeException('Vous n\'êtes pas autorisé à modifier le statut des utilisateurs.');
            }
            
            $id = validateId($_POST['id'] ?? 0, 'ID utilisateur');
            
            if ($id === $currentUser['id']) {
                throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte.');
            }
            
            $newStatus = (($_POST['to'] ?? 'actif') === 'inactif') ? 'inactif' : 'actif';
            
            // Récupérer l'ancien statut et les infos utilisateur
            $userInfo = safeFetch($pdo, "SELECT nom, prenom, Email, statut FROM utilisateurs WHERE id = ?", [$id], 'user_status_info');
            $oldStatus = $userInfo['statut'] ?? 'inconnu';
            $userName = $userInfo ? ($userInfo['prenom'] . ' ' . $userInfo['nom']) : "Utilisateur #{$id}";
            $userEmail = $userInfo['Email'] ?? 'N/A';
            
            $stmt = $pdo->prepare("UPDATE utilisateurs SET statut = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            
            $modifierInfo = safeFetch($pdo, "SELECT nom, prenom FROM utilisateurs WHERE id = ?", [$currentUser['id']], 'modifier_status_info');
            $modifierName = $modifierInfo ? ($modifierInfo['prenom'] . ' ' . $modifierInfo['nom']) : "Utilisateur #{$currentUser['id']}";
            
            logProfilAction($pdo, $currentUser['id'], 'utilisateur_statut', 
                "Changement statut utilisateur #{$id}: {$userName} ({$userEmail}) | {$oldStatus} → {$newStatus} | Modifié par: {$modifierName}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Statut modifié en {$newStatus}."];
        }
        // ===== RÉINITIALISATION MOT DE PASSE =====
        elseif ($action === 'resetpwd') {
            $id = validateId($_POST['id'] ?? 0, 'ID utilisateur');

            if ($isLivreur && $id !== $currentUser['id']) {
                throw new RuntimeException('Vous ne pouvez réinitialiser que votre propre mot de passe.');
            }

            $newPassword = (string)($_POST['new_password'] ?? '');
            if (strlen($newPassword) < 8) {
                throw new RuntimeException('Mot de passe trop court (min. 8 caractères).');
            }
            
            // Récupérer les infos de l'utilisateur cible
            $targetUserInfo = safeFetch($pdo, "SELECT nom, prenom, Email FROM utilisateurs WHERE id = ?", [$id], 'target_pwd_reset_info');
            $targetName = $targetUserInfo ? ($targetUserInfo['prenom'] . ' ' . $targetUserInfo['nom']) : "Utilisateur #{$id}";
            $targetEmail = $targetUserInfo['Email'] ?? 'N/A';
            
            $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
            $stmt = $pdo->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $id]);

            $resetterInfo = safeFetch($pdo, "SELECT nom, prenom FROM utilisateurs WHERE id = ?", [$currentUser['id']], 'resetter_info');
            $resetterName = $resetterInfo ? ($resetterInfo['prenom'] . ' ' . $resetterInfo['nom']) : "Utilisateur #{$currentUser['id']}";
            
            logProfilAction($pdo, $currentUser['id'], 'utilisateur_pwd_reset', 
                "Réinitialisation mot de passe utilisateur #{$id}: {$targetName} ({$targetEmail}) | Réinitialisé par: {$resetterName}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Mot de passe réinitialisé."];
        }
        // ===== SAUVEGARDE DES PERMISSIONS =====
        elseif ($action === 'save_permissions') {
            if (!$isAdminOrDirigeant) {
                throw new RuntimeException('Vous n\'êtes pas autorisé à gérer les permissions.');
            }
            
            $targetUserId = validateId($_POST['target_user_id'] ?? 0, 'ID utilisateur');
            $redirectAfterSave = $targetUserId; // Pour la redirection
            
            // Vérifier que l'utilisateur cible existe
            $targetUser = safeFetch($pdo, "SELECT id, nom, prenom FROM utilisateurs WHERE id = ?", [$targetUserId], 'check_target_user');
            if (!$targetUser) {
                throw new RuntimeException('Utilisateur introuvable.');
            }
            
            // Liste des pages disponibles (organisées par catégories)
            $availablePages = [
                // Pages principales
                'dashboard' => 'Dashboard',
                'agenda' => 'Agenda',
                'historique' => 'Historique',
                
                // Gestion clients
                'clients' => 'Clients',
                'client_fiche' => 'Fiche Client',
                
                // Gestion financière
                'paiements' => 'Paiements & Factures',
                
                // Communication
                'messagerie' => 'Messagerie',
                
                // Opérations
                'sav' => 'SAV',
                'livraison' => 'Livraisons',
                'stock' => 'Stock',
                'photocopieurs_details' => 'Détails Photocopieurs',
                
                // Planification
                'maps' => 'Cartes & Planification',
                
                // Administration
                'profil' => 'Gestion Utilisateurs'
            ];
            
            // Récupérer les permissions envoyées
            $permissions = $_POST['permissions'] ?? [];
            
            // Démarrer une transaction
            $pdo->beginTransaction();
            
            try {
                // Supprimer les permissions existantes pour cet utilisateur
                $stmt = $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?");
                $stmt->execute([$targetUserId]);
                
                // Insérer les nouvelles permissions
                $stmt = $pdo->prepare("INSERT INTO user_permissions (user_id, page, allowed) VALUES (?, ?, ?)");
                
                foreach ($availablePages as $pageKey => $pageName) {
                    $allowed = isset($permissions[$pageKey]) && $permissions[$pageKey] === '1' ? 1 : 0;
                    $stmt->execute([$targetUserId, $pageKey, $allowed]);
                }
                
                $pdo->commit();
                
                $changes = [];
                foreach ($availablePages as $pageKey => $pageName) {
                    $allowed = isset($permissions[$pageKey]) && $permissions[$pageKey] === '1' ? 1 : 0;
                    $changes[] = "{$pageName}: " . ($allowed ? 'autorisé' : 'interdit');
                }
                
                // Récupérer les infos du modificateur
                $modifierInfo = safeFetch($pdo, "SELECT nom, prenom FROM utilisateurs WHERE id = ?", [$currentUser['id']], 'modifier_perms_info');
                $modifierName = $modifierInfo ? ($modifierInfo['prenom'] . ' ' . $modifierInfo['nom']) : "Utilisateur #{$currentUser['id']}";
                
                logProfilAction($pdo, $currentUser['id'], 'permissions_modifiees', 
                    "Modification permissions utilisateur #{$targetUserId}: {$targetUser['prenom']} {$targetUser['nom']} | Modifié par: {$modifierName} | " . implode(', ', $changes));
                
                $_SESSION['flash'] = ['type' => 'success', 'msg' => "Permissions mises à jour avec succès."];
    } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    } catch (InvalidArgumentException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (RuntimeException $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    } catch (Throwable $e) {
        error_log('profil.php error: ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Une erreur est survenue. Veuillez réessayer."];
    }

    // Redirection après action
    $redirectUrl = '/public/profil.php';
    if ($action === 'update' && isset($id)) {
        $redirectUrl .= '?edit=' . $id;
    } elseif ($action === 'save_permissions' && isset($redirectAfterSave)) {
        $redirectUrl .= '?perm_user=' . $redirectAfterSave;
    }
    header('Location: ' . $redirectUrl);
    exit;
}

// ========================================================================
// PRÉPARATION DE L'AFFICHAGE (Requêtes GET)
// ========================================================================

// Paramètres de recherche - barre de recherche unique avec filtrage intelligent
$search = sanitizeSearch($_GET['q'] ?? '');

// Construction de la requête SQL optimisée avec recherche partielle intelligente
$params = [];
$where = [];

if ($search !== '') {
    $searchLower = mb_strtolower(trim($search));
    
    // Recherche intelligente : combine toutes les possibilités avec OR
    // 1. Recherche dans nom, prénom, email, téléphone
    // 2. Recherche dans les rôles (correspondance partielle, insensible à la casse)
    // 3. Recherche dans les statuts (correspondance partielle)
    
    $searchConditions = [];
    $paramIndex = 0;
    
    // Recherche intelligente : nom OU prénom OU email commence par la saisie (LIKE 'saisie%')
    $key = ':search_text';
    $searchPattern = $search . '%';
    $searchConditions[] = "(LOWER(nom) LIKE LOWER({$key}) OR LOWER(prenom) LIKE LOWER({$key}) OR LOWER(Email) LIKE LOWER({$key}))";
    $params[$key] = $searchPattern;
    
    // Recherche dans les rôles (correspondance partielle, insensible à la casse)
    // Recherche directement dans le champ Emploi avec LIKE
    $key = ':search_role';
    $searchConditions[] = "LOWER(Emploi) LIKE LOWER({$key})";
    $params[$key] = "%{$search}%";
    
    // Recherche dans les statuts (correspondance partielle)
    $statusConditions = [];
    if (stripos('actif', $searchLower) !== false || stripos($searchLower, 'actif') !== false) {
        $key = ':search_status_actif';
        $statusConditions[] = "statut = {$key}";
        $params[$key] = 'actif';
    }
    if (stripos('inactif', $searchLower) !== false || stripos($searchLower, 'inactif') !== false) {
        $key = ':search_status_inactif';
        $statusConditions[] = "statut = {$key}";
        $params[$key] = 'inactif';
    }
    if (!empty($statusConditions)) {
        $searchConditions[] = '(' . implode(' OR ', $statusConditions) . ')';
    }
    
    // Combiner toutes les conditions avec OR pour un filtrage intelligent
    // Si l'utilisateur tape "diri", ça cherche dans nom/prénom/email/téléphone ET dans les rôles
    if (!empty($searchConditions)) {
        $where[] = '(' . implode(' OR ', $searchConditions) . ')';
    }
}

$sql = "SELECT id, Email, nom, prenom, telephone, Emploi, statut, date_debut, date_creation, date_modification
        FROM utilisateurs";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY nom ASC, prenom ASC LIMIT ' . USERS_LIMIT;

$users = safeFetchAll($pdo, $sql, $params, 'utilisateurs_list');

// Utilisateur en édition
$editId = isset($_GET['edit']) ? validateId($_GET['edit'], 'ID') : 0;
$editing = $editId > 0 ? safeFetch($pdo, 
    "SELECT id, Email, nom, prenom, telephone, Emploi, statut, date_debut, date_creation, date_modification 
     FROM utilisateurs WHERE id = ?", 
    [$editId], 
    'utilisateur_edit'
) : null;


// Calcul des statistiques (compte TOUS les utilisateurs, pas seulement ceux affichés)
$stats = safeFetch($pdo, "
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'actif' THEN 1 ELSE 0 END) as actifs,
        SUM(CASE WHEN statut = 'inactif' THEN 1 ELSE 0 END) as inactifs
    FROM utilisateurs
", [], 'stats_users');

$totalUsers = (int)($stats['total'] ?? 0);
$activeUsers = (int)($stats['actifs'] ?? 0);
$inactiveUsers = (int)($stats['inactifs'] ?? 0);

// Récupérer les informations de l'utilisateur connecté
$currentUserInfo = safeFetch($pdo, 
    "SELECT nom, prenom, Email, Emploi FROM utilisateurs WHERE id = ?", 
    [$currentUser['id']], 
    'current_user_info'
);

// Utilisateurs en ligne (activité récente dans les 5 dernières minutes)
// Utilise le champ last_activity si disponible, sinon utilise date_modification comme fallback
$onlineUsersList = safeFetchAll($pdo, "
    SELECT id, nom, prenom, Email, Emploi, last_activity
    FROM utilisateurs
    WHERE statut = 'actif'
    AND (
        (last_activity IS NOT NULL AND last_activity >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
        OR 
        (last_activity IS NULL AND date_modification >= DATE_SUB(NOW(), INTERVAL 5 MINUTE))
    )
    ORDER BY nom ASC, prenom ASC
", [], 'online_users_list');

$onlineUsers = count($onlineUsersList);

$filtersActive = ($search !== '');

// ========================================================================
// GESTION DES PERMISSIONS (ACL)
// ========================================================================
// Liste des pages disponibles pour les permissions (organisées par catégories)
$availablePages = [
    // Pages principales
    'dashboard' => 'Dashboard',
    'agenda' => 'Agenda',
    'historique' => 'Historique',
    
    // Gestion clients
    'clients' => 'Clients',
    'client_fiche' => 'Fiche Client',
    
    // Gestion financière
    'paiements' => 'Paiements & Factures',
    
    // Communication
    'messagerie' => 'Messagerie',
    
    // Opérations
    'sav' => 'SAV',
    'livraison' => 'Livraisons',
    'stock' => 'Stock',
    'photocopieurs_details' => 'Détails Photocopieurs',
    
    // Planification
    'maps' => 'Cartes & Planification',
    
    // Administration
    'profil' => 'Gestion Utilisateurs'
];

// Utilisateur cible pour les permissions (utilise l'utilisateur en édition si présent, sinon sélectionné)
$permUserId = 0;
if (isset($_GET['perm_user']) && $_GET['perm_user'] !== '') {
    try {
        $permUserId = validateId($_GET['perm_user'], 'ID utilisateur');
    } catch (InvalidArgumentException $e) {
        $permUserId = 0;
    }
}
$permissionTargetUserId = $editId > 0 ? $editId : $permUserId;

// Récupérer les permissions de l'utilisateur cible
$userPermissions = [];
if ($permissionTargetUserId > 0 && $isAdminOrDirigeant) {
    $permissionsData = safeFetchAll($pdo, 
        "SELECT page, allowed FROM user_permissions WHERE user_id = ?", 
        [$permissionTargetUserId], 
        'user_permissions_fetch'
    );
    
    foreach ($permissionsData as $perm) {
        $userPermissions[$perm['page']] = (int)$perm['allowed'] === 1;
    }
    
    // Si aucune permission n'existe, toutes les pages sont autorisées par défaut (fallback sur les rôles)
    // On initialise avec true pour toutes les pages
    foreach ($availablePages as $pageKey => $pageName) {
        if (!isset($userPermissions[$pageKey])) {
            $userPermissions[$pageKey] = true; // Par défaut autorisé (fallback sur rôles)
        }
    }
}

// Fonction utilitaire pour décoder msg JSON
function decode_msg($row) {
    if (!isset($row['msg'])) return null;
    $d = json_decode((string)$row['msg'], true);
    return (json_last_error() === JSON_ERROR_NONE) ? $d : null;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestion des utilisateurs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/profil.css">
    <style>
        /* Import history toggle button */
        .page-header { 
            position: relative; 
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 1rem;
        }
        .page-header > div:first-child {
            flex: 1;
        }
        .import-mini {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .import-mini .btn {
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            height: auto;
            line-height: 1.4;
        }

        /* Flash messages améliorés */
        .flash {
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem 1.25rem;
            margin: 1rem 0;
            box-shadow: var(--shadow-sm);
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-10px); }
            to { opacity: 1; transform: translateX(0); }
        }
        .flash.success {
            border-color: #16a34a;
            background: #f0fdf4;
            color: #166534;
        }
        .flash.error {
            border-color: #dc2626;
            background: #fef2f2;
            color: #991b1b;
        }
        .flash::before {
            content: '';
            width: 20px;
            height: 20px;
            flex-shrink: 0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .flash.success::before {
            content: '✓';
            background: #16a34a;
            color: white;
            font-weight: bold;
        }
        .flash.error::before {
            content: '!';
            background: #dc2626;
            color: white;
            font-weight: bold;
        }

        /* Amélioration des formulaires */
        .standard-form.danger {
            border-top: 2px solid #dc2626;
            padding-top: 1.5rem;
            margin-top: 1.5rem;
        }
        .standard-form.danger h3 {
            color: #dc2626;
            margin-bottom: 1rem;
        }
        .link-reset {
            display: inline-block;
            margin-top: 1rem;
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }
        .link-reset:hover {
            color: var(--accent-primary);
        }

        /* Tooltip utilisateurs en ligne */
        .online-users-wrapper {
            position: relative;
        }
        .online-count {
            cursor: help;
            transition: opacity 0.2s ease;
        }
        .online-count:hover {
            opacity: 0.8;
        }
        .online-tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            margin-bottom: 8px;
            width: 280px;
            max-width: 90vw;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 12px;
            z-index: 50;
            display: none;
            animation: tooltipFadeIn 0.2s ease;
        }
        @keyframes tooltipFadeIn {
            from {
                opacity: 0;
                transform: translateX(-50%) translateY(4px);
            }
            to {
                opacity: 1;
                transform: translateX(-50%) translateY(0);
            }
        }
        .online-users-wrapper:hover .online-tooltip,
        .online-tooltip:hover {
            display: block;
        }
        .tooltip-header {
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-color);
        }
        .tooltip-header strong {
            font-size: 13px;
            color: var(--text-primary);
        }
        .tooltip-list {
            max-height: 200px;
            overflow-y: auto;
        }
        .tooltip-list::-webkit-scrollbar {
            width: 4px;
        }
        .tooltip-list::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }
        .tooltip-list::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 2px;
        }
        .tooltip-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 6px 0;
            font-size: 12px;
        }
        .tooltip-item + .tooltip-item {
            border-top: 1px solid var(--bg-secondary);
        }
        .tooltip-name {
            font-weight: 600;
            color: var(--text-primary);
        }
        .tooltip-role {
            font-size: 11px;
            color: var(--text-secondary);
            background: var(--bg-secondary);
            padding: 2px 6px;
            border-radius: 999px;
        }
        /* Flèche du tooltip */
        .online-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-top: 6px solid var(--bg-primary);
        }
        .online-tooltip::before {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-top: 7px solid var(--border-color);
            margin-top: 1px;
        }

        /* Styles améliorés pour le tableau des paiements */
        .payments-panel {
            margin-top: 2rem;
        }

        /* Styles améliorés pour le tableau des factures */
        .factures-panel {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        /* Styles améliorés pour le tableau des SAV */
        .sav-panel {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .sav-panel .panel-title {
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .sav-panel .panel-subtitle {
            margin-bottom: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .sav-panel .table-responsive {
            overflow-x: visible;
            width: 100%;
            max-width: 100%;
            margin-top: 1rem;
        }

        .sav-table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .sav-table thead {
            background: var(--bg-secondary);
            border-bottom: 2px solid var(--border-color);
        }

        .sav-table thead th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .sav-table thead th:nth-child(1) { width: 8%; } /* Date ouverture */
        .sav-table thead th:nth-child(2) { width: 9%; } /* Référence */
        .sav-table thead th:nth-child(3) { width: 12%; } /* Client */
        .sav-table thead th:nth-child(4) { width: 20%; } /* Description */
        .sav-table thead th:nth-child(5) { width: 8%; } /* Type panne */
        .sav-table thead th:nth-child(6) { width: 8%; } /* Priorité */
        .sav-table thead th:nth-child(7) { width: 8%; } /* Date fermeture */
        .sav-table thead th:nth-child(8) { width: 10%; } /* Technicien */
        .sav-table thead th:nth-child(9) { width: 8%; } /* Statut */
        .sav-table thead th:nth-child(10) { width: 19%; } /* Actions */

        .sav-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
        }

        .sav-table tbody tr:hover {
            background-color: var(--bg-secondary);
        }

        .sav-table tbody td {
            padding: 0.75rem 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .sav-table tbody td:nth-child(1),
        .sav-table tbody td:nth-child(2),
        .sav-table tbody td:nth-child(5),
        .sav-table tbody td:nth-child(6),
        .sav-table tbody td:nth-child(7),
        .sav-table tbody td:nth-child(9) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sav-table tbody td:nth-child(3),
        .sav-table tbody td:nth-child(4),
        .sav-table tbody td:nth-child(8) {
            white-space: normal;
            word-break: break-word;
        }

        .sav-table .actions {
            white-space: nowrap;
            padding: 0.75rem 0.5rem;
        }

        .sav-table .actions form.sav-status-form {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            flex-wrap: wrap;
            padding: 0.5rem;
            margin: 0;
            width: 100%;
            min-width: 280px;
        }

        .sav-table .actions select.sav-status-select {
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.875rem;
            min-width: 150px;
            max-width: 180px;
            flex: 1 1 auto;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .sav-table .actions select.sav-status-select:hover {
            border-color: var(--accent-primary);
            background: var(--bg-secondary);
        }

        .sav-table .actions select.sav-status-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: var(--bg-primary);
        }

        .sav-table .actions button.sav-update-btn {
            padding: 0.5rem 1.25rem;
            font-size: 0.875rem;
            white-space: nowrap;
            transition: all 0.2s ease;
            margin: 0;
            font-weight: 500;
            flex: 0 0 auto;
        }

        .sav-table .actions button.sav-update-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Couleurs spécifiques pour les statuts de SAV */
        .sav-table .badge.statut-ouvert {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .sav-table .badge.statut-en-cours {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .sav-table .badge.statut-resolu {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .sav-table .badge.statut-annule {
            background: rgba(0, 0, 0, 0.15);
            color: #000000;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        @media (max-width: 1024px) {
            .sav-table {
                font-size: 0.8rem;
            }

            .sav-table thead th,
            .sav-table tbody td {
                padding: 0.6rem 0.4rem;
            }

            .sav-table thead th {
                font-size: 0.75rem;
            }

            .sav-table .actions form.sav-status-form {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
                min-width: 100%;
            }

            .sav-table .actions select.sav-status-select {
                width: 100%;
                max-width: 100%;
                min-width: 100%;
            }

            .sav-table .actions button.sav-update-btn {
                width: 100%;
            }
        }

        .factures-panel .panel-title {
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .factures-panel .panel-subtitle {
            margin-bottom: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .factures-panel .table-responsive {
            overflow-x: visible;
            width: 100%;
            max-width: 100%;
            margin-top: 1rem;
        }

        .factures-table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .factures-table thead {
            background: var(--bg-secondary);
            border-bottom: 2px solid var(--border-color);
        }

        .factures-table thead th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .factures-table thead th:nth-child(1) { width: 6%; } /* Date */
        .factures-table thead th:nth-child(2) { width: 7%; } /* Numéro */
        .factures-table thead th:nth-child(3) { width: 11%; } /* Client */
        .factures-table thead th:nth-child(4) { width: 6%; } /* Type */
        .factures-table thead th:nth-child(5) { width: 6%; } /* Montant HT */
        .factures-table thead th:nth-child(6) { width: 5%; } /* TVA */
        .factures-table thead th:nth-child(7) { width: 6%; } /* Total TTC */
        .factures-table thead th:nth-child(8) { width: 8%; } /* Statut */
        .factures-table thead th:nth-child(9) { width: 7%; } /* PDF */
        .factures-table thead th:nth-child(10) { width: 8%; } /* Méthode paiement */
        .factures-table thead th:nth-child(11) { width: 8%; } /* Justificatif */
        .factures-table thead th:nth-child(12) { width: 22%; } /* Actions */

        .factures-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
        }

        .factures-table tbody tr:hover {
            background-color: var(--bg-secondary);
        }

        .factures-table tbody td {
            padding: 0.75rem 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            vertical-align: middle;
            word-wrap: break-word;
        }

        .factures-table tbody td:nth-child(1),
        .factures-table tbody td:nth-child(4),
        .factures-table tbody td:nth-child(5),
        .factures-table tbody td:nth-child(6),
        .factures-table tbody td:nth-child(7),
        .factures-table tbody td:nth-child(10) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .factures-table tbody td:nth-child(3) {
            white-space: normal;
            word-break: break-word;
        }

        .factures-table .actions {
            white-space: nowrap;
            padding: 0.75rem 0.5rem;
        }

        .factures-table .actions form.facture-status-form {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            padding: 0.25rem;
            margin: 0;
            width: 100%;
            min-width: 240px;
        }

        .factures-table .actions select.facture-status-select {
            padding: 0.4rem 0.6rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.8rem;
            min-width: 120px;
            max-width: 150px;
            flex: 1 1 auto;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        .factures-table .actions select.facture-status-select:hover {
            border-color: var(--accent-primary);
            background: var(--bg-secondary);
        }

        .factures-table .actions select.facture-status-select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background: var(--bg-primary);
        }

        .factures-table .actions button.facture-update-btn {
            padding: 0.4rem 0.9rem;
            font-size: 0.8rem;
            white-space: nowrap;
            transition: all 0.2s ease;
            margin: 0;
            font-weight: 500;
            flex: 0 0 auto;
        }

        .factures-table .actions button.facture-update-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        .factures-table .badge {
            display: inline-block;
            padding: 0.3rem 0.5rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        /* Couleurs spécifiques pour les statuts de factures */
        .factures-table .badge.statut-brouillon {
            background: rgba(107, 114, 128, 0.15);
            color: #6b7280;
            border: 1px solid rgba(107, 114, 128, 0.3);
        }

        .factures-table .badge.statut-envoyee {
            background: rgba(59, 130, 246, 0.15);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .factures-table .badge.statut-payee {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .factures-table .badge.statut-en-retard {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .factures-table .badge.statut-annulee {
            background: rgba(0, 0, 0, 0.15);
            color: #000000;
            border: 1px solid rgba(0, 0, 0, 0.3);
        }

        /* Bouton PDF pour les factures */
        .btn-pdf-facture {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.875rem;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            text-decoration: none;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            white-space: nowrap;
        }

        .btn-pdf-facture:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
        }

        .btn-pdf-facture svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        @media (max-width: 1400px) {
            .factures-table {
                font-size: 0.8rem;
            }

            .factures-table thead th,
            .factures-table tbody td {
                padding: 0.5rem 0.35rem;
            }

            .factures-table thead th {
                font-size: 0.75rem;
            }
        }

        @media (max-width: 1024px) {
            .factures-table {
                font-size: 0.75rem;
            }

            .factures-table thead th,
            .factures-table tbody td {
                padding: 0.5rem 0.3rem;
            }

            .factures-table thead th {
                font-size: 0.7rem;
            }

            .factures-table .actions form.facture-status-form {
                flex-direction: column;
                align-items: stretch;
                gap: 0.4rem;
                min-width: 100%;
            }

            .factures-table .actions select.facture-status-select {
                width: 100%;
                max-width: 100%;
                min-width: 100%;
            }

            .factures-table .actions button.facture-update-btn {
                width: 100%;
            }

            .btn-pdf-facture {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
            }
        }

        .payments-panel .table-responsive {
            overflow-x: visible;
            width: 100%;
            max-width: 100%;
        }

        .payments-table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .payments-table thead {
            background: var(--bg-secondary);
            border-bottom: 2px solid var(--border-color);
        }

        .payments-table thead th {
            padding: 0.75rem 0.5rem;
            text-align: left;
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        .payments-table thead th:nth-child(1) { width: 7%; } /* Date */
        .payments-table thead th:nth-child(2) { width: 14%; } /* Client */
        .payments-table thead th:nth-child(3) { width: 7%; } /* Facture */
        .payments-table thead th:nth-child(4) { width: 7%; } /* Montant */
        .payments-table thead th:nth-child(5) { width: 9%; } /* Mode */
        .payments-table thead th:nth-child(6) { width: 9%; } /* Statut */
        .payments-table thead th:nth-child(7) { width: 9%; } /* Référence */
        .payments-table thead th:nth-child(8) { width: 9%; } /* Justificatif */
        .payments-table thead th:nth-child(9) { width: 19%; } /* Actions */

        .payments-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
        }

        .payments-table tbody tr:hover {
            background-color: var(--bg-secondary);
        }

        .payments-table tbody td {
            padding: 0.75rem 0.5rem;
            color: var(--text-primary);
            font-size: 0.875rem;
            vertical-align: middle;
            word-wrap: break-word;
        }

        /* Cellules avec texte tronqué (sauf client et actions) */
        .payments-table tbody td:nth-child(1),
        .payments-table tbody td:nth-child(3),
        .payments-table tbody td:nth-child(4),
        .payments-table tbody td:nth-child(5),
        .payments-table tbody td:nth-child(6),
        .payments-table tbody td:nth-child(7) {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Cellule client peut avoir plusieurs lignes */
        .payments-table tbody td:nth-child(2) {
            white-space: normal;
            word-break: break-word;
        }

        /* Colonnes spécifiques avec gestion du texte */
        .payments-table tbody td:nth-child(2) { /* Client */
            max-width: 180px;
        }

        .payments-table tbody td:nth-child(3) { /* Facture */
            max-width: 100px;
        }

        .payments-table tbody td:nth-child(7) { /* Référence */
            max-width: 130px;
        }

        .payments-table .actions {
            white-space: nowrap;
        }

        .payments-table .actions form {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            flex-wrap: wrap;
        }

        .payments-table .actions select {
            padding: 0.4rem 0.5rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.85rem;
            min-width: 100px;
            max-width: 120px;
            transition: all 0.2s ease;
        }

        .payments-table .actions select:hover {
            border-color: var(--accent-primary);
        }

        .payments-table .actions select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .payments-table .actions button {
            padding: 0.4rem 0.75rem;
            font-size: 0.85rem;
            white-space: nowrap;
            transition: all 0.2s ease;
        }

        .payments-table .actions button:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }

        /* Bouton justificatif */
        .btn-justificatif {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.4rem 0.6rem;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: white;
            text-decoration: none;
            border-radius: var(--radius-md);
            font-size: 0.8rem;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            white-space: nowrap;
        }

        .btn-justificatif:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
        }

        .btn-justificatif svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        /* Amélioration des badges de statut dans le tableau */
        .payments-table .badge {
            display: inline-block;
            padding: 0.3rem 0.5rem;
            border-radius: var(--radius-md);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
        }

        /* Optimisation des éléments du tableau */
        .payments-table code {
            font-size: 0.75rem;
            padding: 0.2rem 0.4rem;
            max-width: 100%;
            display: inline-block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .payments-table .text-muted {
            font-size: 0.8rem;
        }

        /* Responsive pour le tableau des paiements */
        @media (max-width: 1024px) {
            .payments-table {
                font-size: 0.8rem;
            }

            .payments-table thead th,
            .payments-table tbody td {
                padding: 0.6rem 0.4rem;
            }

            .payments-table thead th {
                font-size: 0.75rem;
            }

            .payments-table .actions form {
                flex-direction: column;
                align-items: stretch;
                gap: 0.4rem;
            }

            .payments-table .actions select {
                width: 100%;
                max-width: 100%;
            }

            .payments-table .actions button {
                width: 100%;
            }
        }

        @media (max-width: 768px) {
            .payments-table {
                display: block;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
            }

            .payments-table thead th {
                font-size: 0.8rem;
                padding: 0.75rem 0.5rem;
            }

            .payments-table tbody td {
                font-size: 0.85rem;
                padding: 0.75rem 0.5rem;
            }

            .btn-justificatif {
                padding: 0.4rem 0.6rem;
                font-size: 0.8rem;
            }

            .btn-justificatif svg {
                width: 14px;
                height: 14px;
            }
        }

        /* Styles pour les catégories de permissions */
        .permission-category {
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 2px solid var(--border-color);
        }

        .permission-category:last-child {
            border-bottom: none;
            margin-bottom: 0;
            padding-bottom: 0;
        }

        .permission-category-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 1rem 0;
            padding: 0.75rem 1rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            border-left: 4px solid var(--accent-primary);
        }

        .permission-category .permission-item {
            margin-left: 1rem;
            margin-bottom: 0.75rem;
        }

        .permission-category .permission-item:last-child {
            margin-bottom: 0;
        }

        @media (max-width: 768px) {
            .permission-category-title {
                font-size: 0.95rem;
                padding: 0.6rem 0.8rem;
            }

            .permission-category .permission-item {
                margin-left: 0.5rem;
            }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-profil">
    <header class="page-header">
        <h1 class="page-title">Gestion des utilisateurs</h1>
        <p class="page-sub">Page réservée aux administrateurs (Admin), dirigeants, techniciens et livreurs pour créer, modifier et activer/désactiver des comptes.</p>
        
    </header>

    <section class="profil-meta">
        <div class="meta-card">
            <div class="meta-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
            </div>
            <span class="meta-label">Total utilisateurs</span>
            <strong class="meta-value"><?= h((string)$totalUsers) ?></strong>
            <?php if ($totalUsers >= USERS_LIMIT): ?>
                <span class="meta-chip" title="Limite d'affichage atteinte">+<?= USERS_LIMIT ?> affichés</span>
            <?php endif; ?>
        </div>
        <div class="meta-card">
            <div class="meta-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <span class="meta-label">Actifs</span>
            <strong class="meta-value success"><?= h((string)$activeUsers) ?></strong>
        </div>
        <div class="meta-card">
            <div class="meta-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <span class="meta-label">Inactifs</span>
            <strong class="meta-value" style="color: #dc2626;"><?= h((string)$inactiveUsers) ?></strong>
        </div>
        <div class="meta-card">
            <div class="meta-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                </svg>
            </div>
            <span class="meta-label">Personne connectée</span>
            <strong class="meta-value" style="font-size: 1.1rem;">
                <?php if ($currentUserInfo): ?>
                    <?= h($currentUserInfo['prenom'] . ' ' . $currentUserInfo['nom']) ?>
                <?php else: ?>
                    —
                <?php endif; ?>
            </strong>
            <?php if ($currentUserInfo): ?>
                <span class="meta-sub"><?= h($currentUserInfo['Email']) ?></span>
            <?php endif; ?>
        </div>
        <div class="meta-card">
            <div class="meta-card-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
            </div>
            <span class="meta-label">En ligne</span>
            <div class="online-users-wrapper" style="position: relative;">
                <strong class="meta-value online-count" style="color: #16a34a; cursor: help;" 
                        id="onlineCount" aria-describedby="onlineTooltip"><?= h((string)$onlineUsers) ?></strong>
                <span class="meta-sub">Dernières 5 min</span>
                <?php if ($onlineUsers > 0): ?>
                <div class="online-tooltip" id="onlineTooltip" role="tooltip" aria-hidden="true">
                    <div class="tooltip-header">
                        <strong>Utilisateurs connectés (<?= $onlineUsers ?>)</strong>
                    </div>
                    <div class="tooltip-list">
                        <?php foreach ($onlineUsersList as $onlineUser): ?>
                            <div class="tooltip-item">
                                <span class="tooltip-name"><?= h($onlineUser['prenom'] . ' ' . $onlineUser['nom']) ?></span>
                                <span class="tooltip-role"><?= h($onlineUser['Emploi']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($filtersActive): ?>
        <div class="active-filters" role="status" aria-live="polite">
            <span class="badge">Filtre actif</span>
                <span class="pill">Recherche : <?= h($search) ?></span>
            <a class="pill pill-clear" href="/public/profil.php" aria-label="Réinitialiser le filtre">Réinitialiser</a>
        </div>
    <?php endif; ?>

    <?php if ($flash && isset($flash['type'])): ?>
        <div class="flash <?= h($flash['type']) ?>" role="alert" aria-live="polite">
            <?= h($flash['msg'] ?? '') ?>
        </div>
    <?php endif; ?>

    <form class="filtre-form" method="get" action="/public/profil.php" novalidate id="searchForm">
        <div class="filter-bar">
            <div class="filter-field grow">
                <label for="q" class="sr-only">Rechercher</label>
                <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input class="filter-input" type="search" id="q" name="q" value="<?= h($search) ?>" 
                       placeholder="Rechercher par nom, prénom ou email…" 
                       aria-label="Rechercher un utilisateur" 
                       autocomplete="off" />
                <span class="search-loading" id="searchLoading" style="display: none;" aria-hidden="true">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18" class="spinner">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                </span>
                <button type="button" class="input-clear" id="clearSearch" aria-label="Effacer la recherche" style="<?= $search === '' ? 'display: none;' : '' ?>">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            </div>
        <div class="filter-hint">
            <small>💡 Recherche en temps réel - tapez un nom, prénom, email ou rôle</small>
        </div>
    </form>

    <section class="grid-2cols">
        <?php if ($isAdminOrDirigeant): ?>
        <div class="panel">
            <h2 class="panel-title">Créer un utilisateur</h2>
            <form class="standard-form" method="post" action="/public/profil.php" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="create">
                <label for="create-email">Email <span aria-label="requis">*</span>
                    <input type="email" id="create-email" name="Email" required aria-required="true">
                </label>
                <label for="create-password">Mot de passe (min. 8) <span aria-label="requis">*</span>
                    <input type="password" id="create-password" name="password" minlength="8" required aria-required="true">
                </label>
                <div class="grid-2">
                    <label for="create-nom">Nom <span aria-label="requis">*</span>
                        <input type="text" id="create-nom" name="nom" required aria-required="true">
                    </label>
                    <label for="create-prenom">Prénom <span aria-label="requis">*</span>
                        <input type="text" id="create-prenom" name="prenom" required aria-required="true">
                    </label>
                </div>
                <label for="create-telephone">Téléphone
                    <input type="tel" id="create-telephone" name="telephone" pattern="[0-9+\-.\s]{6,}" inputmode="tel">
                </label>
                <div class="grid-2">
                    <label for="create-emploi">Rôle (Emploi) <span aria-label="requis">*</span>
                        <select id="create-emploi" name="Emploi" required aria-required="true">
                            <?php foreach ($ROLES as $r): ?>
                                <option value="<?= h($r) ?>"><?= h($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label for="create-statut">Statut
                        <select id="create-statut" name="statut">
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </label>
                </div>
                <label for="create-date_debut">Date de début <span aria-label="requis">*</span>
                    <input type="date" id="create-date_debut" name="date_debut" required aria-required="true">
                </label>
                <button class="fiche-action-btn" type="submit">Créer</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="panel <?= !$isAdminOrDirigeant ? 'grid-full' : '' ?>">
            <h2 class="panel-title">Utilisateurs (<span id="usersCount"><?= count($users) ?></span>)</h2>
            <div class="table-responsive">
                <table class="users-table" role="table" aria-label="Liste des utilisateurs">
                    <thead>
                        <tr>
                            <th scope="col">Nom</th>
                            <th scope="col">Email</th>
                            <th scope="col">Téléphone</th>
                            <th scope="col">Rôle</th>
                            <th scope="col">Statut</th>
                            <th scope="col">Début</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="aucun" role="cell">Aucun utilisateur trouvé.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr class="<?= ($editId > 0 && $editId === (int)$u['id']) ? 'is-editing' : '' ?>" 
                                role="row">
                                <td data-label="Nom" role="cell"><?= h($u['nom'] . ' ' . $u['prenom']) ?></td>
                                <td data-label="Email" role="cell"><?= h($u['Email']) ?></td>
                                <td data-label="Téléphone" role="cell"><?= h($u['telephone'] ?? '') ?></td>
                                <td data-label="Rôle" role="cell">
                                    <span class="badge role"><?= h($u['Emploi']) ?></span>
                                </td>
                                <td data-label="Statut" role="cell">
                                    <span class="badge <?= $u['statut'] === 'actif' ? 'success' : 'muted' ?>">
                                        <?= h($u['statut']) ?>
                                    </span>
                                </td>
                                <td data-label="Début" role="cell"><?= h($u['date_debut']) ?></td>
                                <td data-label="Actions" class="actions" role="cell">
                                    <?php if ($hasRestrictions && (int)$u['id'] !== $currentUser['id']): ?>
                                        <span class="text-muted" aria-label="Action non autorisée">Non autorisé</span>
                                    <?php else: ?>
                                        <a class="btn btn-primary" href="/public/profil.php?edit=<?= (int)$u['id'] ?>" 
                                           aria-label="Modifier l'utilisateur <?= h($u['nom'] . ' ' . $u['prenom']) ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                            </svg>
                                            Modifier
                                        </a>
                                        <?php if ($isAdminOrDirigeant): ?>
                                        <form method="post" action="/public/profil.php" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="to" value="<?= $u['statut'] === 'actif' ? 'inactif' : 'actif' ?>">
                                            <button type="submit" 
                                                    class="btn <?= $u['statut'] === 'actif' ? 'btn-danger' : 'btn-success' ?>"
                                                    aria-label="<?= $u['statut'] === 'actif' ? 'Désactiver' : 'Activer' ?> l'utilisateur <?= h($u['nom'] . ' ' . $u['prenom']) ?>">
                                                <?php if ($u['statut'] === 'actif'): ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                                    </svg>
                                                    Désactiver
                                                <?php else: ?>
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    Activer
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($editing): ?>
        <section class="panel edit-panel" id="editPanel">
            <h2 class="panel-title">Modifier l'utilisateur #<?= (int)$editing['id'] ?></h2>
            <form class="standard-form" method="post" action="/public/profil.php?edit=<?= (int)$editing['id'] ?>" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">

                <label for="edit-email">Email <span aria-label="requis">*</span>
                    <input type="email" id="edit-email" name="Email" value="<?= h($editing['Email']) ?>" required aria-required="true">
                </label>
                <div class="grid-2">
                    <label for="edit-nom">Nom <span aria-label="requis">*</span>
                        <input type="text" id="edit-nom" name="nom" value="<?= h($editing['nom']) ?>" required aria-required="true">
                    </label>
                    <label for="edit-prenom">Prénom <span aria-label="requis">*</span>
                        <input type="text" id="edit-prenom" name="prenom" value="<?= h($editing['prenom']) ?>" required aria-required="true">
                    </label>
                </div>
                <label for="edit-telephone">Téléphone
                    <input type="tel" id="edit-telephone" name="telephone" value="<?= h($editing['telephone'] ?? '') ?>" 
                           pattern="[0-9+\-.\s]{6,}" inputmode="tel">
                </label>
                <?php if ($isAdminOrDirigeant): ?>
                <div class="grid-2">
                    <label for="edit-emploi">Rôle (Emploi) <span aria-label="requis">*</span>
                        <select id="edit-emploi" name="Emploi" required 
                                <?= ($hasRestrictions && (int)$editing['id'] === $currentUser['id']) ? 'disabled aria-disabled="true"' : '' ?>>
                            <?php foreach ($ROLES as $r): ?>
                                <option value="<?= h($r) ?>" <?= ($editing['Emploi'] ?? '') === $r ? 'selected' : '' ?>>
                                    <?= h($r) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($hasRestrictions && (int)$editing['id'] === $currentUser['id']): ?>
                            <input type="hidden" name="Emploi" value="<?= h($editing['Emploi'] ?? '') ?>">
                        <?php endif; ?>
                    </label>
                    <label for="edit-statut">Statut
                        <select id="edit-statut" name="statut" 
                                <?= ($hasRestrictions && (int)$editing['id'] === $currentUser['id']) ? 'disabled aria-disabled="true"' : '' ?>>
                            <option value="actif" <?= ($editing['statut'] ?? '') === 'actif' ? 'selected' : '' ?>>Actif</option>
                            <option value="inactif" <?= ($editing['statut'] ?? '') === 'inactif' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                        <?php if ($hasRestrictions && (int)$editing['id'] === $currentUser['id']): ?>
                            <input type="hidden" name="statut" value="<?= h($editing['statut'] ?? '') ?>">
                        <?php endif; ?>
                    </label>
                </div>
                <?php else: ?>
                <input type="hidden" name="Emploi" value="<?= h($editing['Emploi'] ?? '') ?>">
                <input type="hidden" name="statut" value="<?= h($editing['statut'] ?? '') ?>">
                <?php endif; ?>
                <label for="edit-date_debut">Date de début <span aria-label="requis">*</span>
                    <input type="date" id="edit-date_debut" name="date_debut" value="<?= h($editing['date_debut']) ?>" required aria-required="true">
                </label>
                <div class="form-actions">
                <button class="fiche-action-btn" type="submit">Enregistrer</button>
                    <a class="fiche-action-btn btn-close" href="/public/profil.php">Fermer</a>
                </div>
            </form>

            <hr class="sep">

            <form class="standard-form danger" method="post" action="/public/profil.php?edit=<?= (int)$editing['id'] ?>" autocomplete="off" novalidate>
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="resetpwd">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <h3>Réinitialiser le mot de passe</h3>
                <label for="reset-password">Nouveau mot de passe (min. 8) <span aria-label="requis">*</span>
                    <input type="password" id="reset-password" name="new_password" minlength="8" required aria-required="true">
                </label>
                <button class="btn-danger btn-compact" type="submit">Réinitialiser</button>
            </form>
        </section>
    <?php endif; ?>
    
    <?php if ($isAdminOrDirigeant): ?>
        <!-- Section Gestion des Permissions (ACL) -->
        <section class="panel permissions-panel" id="permissionsPanel">
            <h2 class="panel-title">Gestion des Permissions</h2>
            <p class="panel-subtitle">Contrôlez l'accès aux pages pour chaque utilisateur. Si aucune permission n'est définie, les rôles par défaut s'appliquent.</p>
            
            <form class="standard-form" method="post" action="/public/profil.php" id="permissionsForm">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="save_permissions">
                
                <label for="perm_user_select">Sélectionner un utilisateur <span aria-label="requis">*</span>
                    <select id="perm_user_select" name="target_user_id" required aria-required="true" 
                            onchange="window.location.href='/public/profil.php?perm_user=' + this.value;">
                        <option value="">-- Choisir un utilisateur --</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>" 
                                    <?= $permissionTargetUserId === (int)$u['id'] ? 'selected' : '' ?>>
                                <?= h($u['nom'] . ' ' . $u['prenom']) ?> (<?= h($u['Emploi']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                
                <?php if ($permissionTargetUserId > 0): ?>
                    <?php
                    $targetUserInfo = safeFetch($pdo, 
                        "SELECT nom, prenom, Emploi FROM utilisateurs WHERE id = ?", 
                        [$permissionTargetUserId], 
                        'target_user_info'
                    );
                    ?>
                    <div class="permissions-grid">
                        <div class="permissions-header">
                            <h3>Permissions pour : <strong><?= $targetUserInfo ? h($targetUserInfo['prenom'] . ' ' . $targetUserInfo['nom']) : 'Utilisateur #' . $permissionTargetUserId ?></strong></h3>
                            <div class="permissions-actions">
                                <button type="button" class="btn btn-secondary btn-sm" id="selectAllPerms">Tout autoriser</button>
                                <button type="button" class="btn btn-secondary btn-sm" id="deselectAllPerms">Tout interdire</button>
                            </div>
                        </div>
                        
                        <div class="permissions-list">
                            <?php
                            // Organiser les pages par catégories pour l'affichage
                            $pagesByCategory = [
                                'Pages principales' => ['dashboard', 'agenda', 'historique'],
                                'Gestion clients' => ['clients', 'client_fiche'],
                                'Gestion financière' => ['paiements'],
                                'Communication' => ['messagerie'],
                                'Opérations' => ['sav', 'livraison', 'stock', 'photocopieurs_details'],
                                'Planification' => ['maps'],
                                'Administration' => ['profil']
                            ];
                            
                            $categoryLabels = [
                                'Pages principales' => '📊 Pages principales',
                                'Gestion clients' => '👥 Gestion clients',
                                'Gestion financière' => '💰 Gestion financière',
                                'Communication' => '💬 Communication',
                                'Opérations' => '⚙️ Opérations',
                                'Planification' => '🗺️ Planification',
                                'Administration' => '🔐 Administration'
                            ];
                            
                            foreach ($pagesByCategory as $category => $pageKeys): ?>
                                <div class="permission-category">
                                    <h4 class="permission-category-title"><?= h($categoryLabels[$category] ?? $category) ?></h4>
                                    <?php foreach ($pageKeys as $pageKey): ?>
                                        <?php if (isset($availablePages[$pageKey])): ?>
                                            <?php $isAllowed = $userPermissions[$pageKey] ?? true; ?>
                                            <div class="permission-item">
                                                <label class="permission-toggle">
                                                    <input type="checkbox" 
                                                           name="permissions[<?= h($pageKey) ?>]" 
                                                           value="1" 
                                                           <?= $isAllowed ? 'checked' : '' ?>
                                                           class="permission-checkbox"
                                                           data-page="<?= h($pageKey) ?>">
                                                    <span class="toggle-slider"></span>
                                                    <span class="permission-label">
                                                        <strong><?= h($availablePages[$pageKey]) ?></strong>
                                                        <small><?= h($pageKey) ?>.php</small>
                                                    </span>
                                                </label>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="form-actions">
                            <button class="fiche-action-btn" type="submit">Enregistrer les permissions</button>
                            <a class="fiche-action-btn btn-close" href="/public/profil.php">Annuler</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="permissions-placeholder">
                        <p>Sélectionnez un utilisateur ci-dessus pour gérer ses permissions.</p>
                    </div>
                <?php endif; ?>
            </form>
        </section>
    <?php endif; ?>
</main>

<?php if ($editing): ?>
                <div class="filter-bar">
                    <div class="filter-field grow">
                        <label for="search_payments" class="sr-only">Rechercher dans les paiements</label>
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input class="filter-input" type="search" id="search_payments" name="search_payments" 
                               value="<?= h($searchPayments) ?>" 
                               placeholder="Rechercher par raison sociale, nom ou prénom du client…" 
                               aria-label="Rechercher dans les paiements" 
                               autocomplete="off" />
                        <?php if ($searchPayments !== ''): ?>
                            <button type="button" class="input-clear" id="clearPaymentsSearch" aria-label="Effacer la recherche">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($searchPayments !== ''): ?>
                    <div class="filter-hint">
                        <small>🔍 Filtre actif : "<?= h($searchPayments) ?>" - <?= count($recentPayments) ?> résultat(s)</small>
                    </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="users-table payments-table" role="table" aria-label="Derniers paiements">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Client</th>
                            <th scope="col">Facture</th>
                            <th scope="col">Montant</th>
                            <th scope="col">Mode</th>
                            <th scope="col">Statut</th>
                            <th scope="col">Référence</th>
                            <th scope="col">Justificatif</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                            <tr>
                                <td colspan="9" class="aucun" role="cell">Aucun paiement enregistré.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $modeLabels = [
                                'virement' => 'Virement',
                                'cb' => 'Carte bancaire',
                                'cheque' => 'Chèque',
                                'especes' => 'Espèces',
                                'autre' => 'Autre'
                            ];
                            $statusLabels = [
                                'en_cours' => 'En cours',
                                'recu' => 'Reçu',
                                'refuse' => 'Refusé',
                                'annule' => 'Annulé'
                            ];
                            ?>
                            <?php foreach ($recentPayments as $p): ?>
                                <?php
                                $status = $p['statut'] ?? 'en_cours';
                                $statusClass = 'muted';
                                if ($status === 'recu') {
                                    $statusClass = 'success';
                                } elseif ($status === 'refuse' || $status === 'annule') {
                                    $statusClass = 'role';
                                }
                                ?>
                                <tr role="row">
                                    <td data-label="Date" role="cell">
                                        <span style="font-weight: 500; color: var(--text-primary);">
                                            <?= isset($p['date_paiement']) ? h(formatDate($p['date_paiement'], 'd/m/Y')) : '—' ?>
                                        </span>
                                    </td>
                                    <td data-label="Client" role="cell">
                                        <?php if (!empty($p['client_nom'])): ?>
                                            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                                <span style="font-weight: 600; color: var(--text-primary);">
                                                    <?= h($p['client_nom']) ?>
                                                </span>
                                                <?php if (!empty($p['client_code'])): ?>
                                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                                        <?= h($p['client_code']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Facture" role="cell">
                                        <?php if (!empty($p['facture_numero'])): ?>
                                            <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; font-weight: 500; color: var(--text-primary);">
                                                <?= h($p['facture_numero']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Montant" role="cell">
                                        <strong style="color: var(--accent-primary); font-weight: 600;">
                                            <?= h(number_format((float)$p['montant'], 2, ',', ' ')) ?> €
                                        </strong>
                                    </td>
                                    <td data-label="Mode" role="cell">
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; color: var(--text-primary);">
                                            <?= h($modeLabels[$p['mode_paiement']] ?? $p['mode_paiement']) ?>
                                        </span>
                                    </td>
                                    <td data-label="Statut" role="cell">
                                        <span class="badge <?= $statusClass ?>">
                                            <?= h($statusLabels[$status] ?? $status) ?>
                                        </span>
                                    </td>
                                    <td data-label="Référence" role="cell">
                                        <?php if (!empty($p['reference'])): ?>
                                            <code style="background: var(--bg-secondary); padding: 0.25rem 0.5rem; border-radius: var(--radius-sm); font-size: 0.85rem; color: var(--text-primary); font-family: 'Courier New', monospace;">
                                                <?= h($p['reference']) ?>
                                            </code>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Justificatif" role="cell">
                                        <?php if (!empty($p['recu_path'])): ?>
                                            <a href="<?= h($p['recu_path']) ?>" 
                                               target="_blank" 
                                               class="btn-justificatif"
                                               title="Voir le justificatif"
                                               aria-label="Voir le justificatif du paiement">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                    <polyline points="14 2 14 8 20 8"></polyline>
                                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                                </svg>
                                                Voir
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions" class="actions" role="cell">
                                        <form method="post" action="/public/profil.php#paymentsPanel" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                            <input type="hidden" name="action" value="update_payment_status">
                                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                                            <label for="payment-status-<?= (int)$p['id'] ?>" class="sr-only">Statut</label>
                                            <select id="payment-status-<?= (int)$p['id'] ?>" name="statut">
                                                <option value="en_cours" <?= $status === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                                                <option value="recu" <?= $status === 'recu' ? 'selected' : '' ?>>Reçu</option>
                                                <option value="refuse" <?= $status === 'refuse' ? 'selected' : '' ?>>Refusé</option>
                                                <option value="annule" <?= $status === 'annule' ? 'selected' : '' ?>>Annulé</option>
                                            </select>
                                            <button type="submit" class="fiche-action-btn" style="margin-left: 0.5rem;">
                                                Mettre à jour
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    
    <?php if ($isAdminOrDirigeant): ?>
        <!-- Section Toutes les factures -->
        <section class="panel factures-panel" id="facturesPanel" style="display: none;">
            <h2 class="panel-title">Toutes les factures <span class="badge role" style="font-size: 0.75rem; margin-left: 0.5rem;"><?= count($recentFactures) ?></span></h2>
            <p class="panel-subtitle">
                Liste complète de toutes les factures. Vous pouvez <strong>vérifier le PDF</strong> et mettre à jour le <strong>statut</strong> directement depuis cette page.
            </p>

            <!-- Barre de recherche pour les factures -->
            <form method="get" action="/public/profil.php#facturesPanel" class="filtre-form" style="margin-bottom: 1.5rem;">
                <div class="filter-bar">
                    <div class="filter-field grow">
                        <label for="search_factures" class="sr-only">Rechercher dans les factures</label>
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input class="filter-input" type="search" id="search_factures" name="search_factures" 
                               value="<?= h($searchFactures) ?>" 
                               placeholder="Rechercher par raison sociale, nom ou prénom du client…" 
                               aria-label="Rechercher dans les factures" 
                               autocomplete="off" />
                        <?php if ($searchFactures !== ''): ?>
                            <button type="button" class="input-clear" id="clearFacturesSearch" aria-label="Effacer la recherche">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($searchFactures !== ''): ?>
                    <div class="filter-hint">
                        <small>🔍 Filtre actif : "<?= h($searchFactures) ?>" - <?= count($recentFactures) ?> résultat(s)</small>
                    </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="users-table factures-table" role="table" aria-label="Dernières factures">
                    <thead>
                        <tr>
                            <th scope="col">Date</th>
                            <th scope="col">Numéro</th>
                            <th scope="col">Client</th>
                            <th scope="col">Type</th>
                            <th scope="col">Montant HT</th>
                            <th scope="col">TVA</th>
                            <th scope="col">Total TTC</th>
                            <th scope="col">Statut</th>
                            <th scope="col">PDF</th>
                            <th scope="col">Méthode paiement</th>
                            <th scope="col">Justificatif</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentFactures)): ?>
                            <tr>
                                <td colspan="12" class="aucun" role="cell">Aucune facture enregistrée.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $statusLabels = [
                                'brouillon' => 'Brouillon',
                                'envoyee' => 'Envoyée',
                                'payee' => 'Payée',
                                'en_retard' => 'En retard',
                                'annulee' => 'Annulée'
                            ];
                            $statusClasses = [
                                'brouillon' => 'statut-brouillon',
                                'envoyee' => 'statut-envoyee',
                                'payee' => 'statut-payee',
                                'en_retard' => 'statut-en-retard',
                                'annulee' => 'statut-annulee'
                            ];
                            $modePaiementLabels = [
                                'virement' => 'Virement',
                                'cb' => 'Carte bancaire',
                                'cheque' => 'Chèque',
                                'especes' => 'Espèces',
                                'autre' => 'Autre'
                            ];
                            ?>
                            <?php foreach ($recentFactures as $f): ?>
                                <?php
                                $status = $f['statut'] ?? 'brouillon';
                                $statusClass = $statusClasses[$status] ?? 'statut-brouillon';
                                $modePaiement = $f['mode_paiement'] ?? null;
                                $justificatif = $f['paiement_justificatif'] ?? null;
                                ?>
                                <tr role="row">
                                    <td data-label="Date" role="cell">
                                        <span style="font-weight: 500; color: var(--text-primary);">
                                            <?= isset($f['date_facture']) ? h(formatDate($f['date_facture'], 'd/m/Y')) : '—' ?>
                                        </span>
                                    </td>
                                    <td data-label="Numéro" role="cell">
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">
                                            <?= h($f['numero'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td data-label="Client" role="cell">
                                        <?php if (!empty($f['client_nom'])): ?>
                                            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                                <span style="font-weight: 600; color: var(--text-primary);">
                                                    <?= h($f['client_nom']) ?>
                                                </span>
                                                <?php if (!empty($f['client_code'])): ?>
                                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                                        <?= h($f['client_code']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Type" role="cell">
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; color: var(--text-primary);">
                                            <?= h($f['type'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td data-label="Montant HT" role="cell">
                                        <span style="color: var(--text-primary);">
                                            <?= h(number_format((float)$f['montant_ht'], 2, ',', ' ')) ?> €
                                        </span>
                                    </td>
                                    <td data-label="TVA" role="cell">
                                        <span style="color: var(--text-primary);">
                                            <?= h(number_format((float)$f['tva'], 2, ',', ' ')) ?> €
                                        </span>
                                    </td>
                                    <td data-label="Total TTC" role="cell">
                                        <strong style="color: var(--accent-primary); font-weight: 600;">
                                            <?= h(number_format((float)$f['montant_ttc'], 2, ',', ' ')) ?> €
                                        </strong>
                                    </td>
                                    <td data-label="Statut" role="cell">
                                        <span class="badge <?= $statusClasses[$status] ?? 'statut-brouillon' ?>">
                                            <?= h($statusLabels[$status] ?? $status) ?>
                                        </span>
                                    </td>
                                    <td data-label="PDF" role="cell">
                                        <?php if (!empty($f['pdf_path'])): ?>
                                            <a href="/public/view_facture.php?id=<?= (int)$f['id'] ?>" 
                                               target="_blank" 
                                               class="btn-justificatif btn-pdf-facture"
                                               title="Voir le PDF de la facture"
                                               aria-label="Voir le PDF de la facture <?= h($f['numero']) ?>">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                    <polyline points="14 2 14 8 20 8"></polyline>
                                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                                </svg>
                                                Voir PDF
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem; padding: 0.5rem 0;">Non généré</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Méthode paiement" role="cell">
                                        <?php if (!empty($modePaiement)): ?>
                                            <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; color: var(--text-primary);">
                                                <?= h($modePaiementLabels[$modePaiement] ?? $modePaiement) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Justificatif" role="cell">
                                        <?php if (!empty($justificatif)): ?>
                                            <a href="<?= h($justificatif) ?>" 
                                               target="_blank" 
                                               class="btn-justificatif"
                                               title="Voir le justificatif de paiement"
                                               aria-label="Voir le justificatif de paiement">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                                    <polyline points="14 2 14 8 20 8"></polyline>
                                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                                </svg>
                                                Voir
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Actions" class="actions" role="cell">
                                        <form method="post" action="/public/profil.php#facturesPanel" class="inline facture-status-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                            <input type="hidden" name="action" value="update_invoice_status">
                                            <input type="hidden" name="facture_id" value="<?= (int)$f['id'] ?>">
                                            <label for="facture-status-<?= (int)$f['id'] ?>" class="sr-only">Statut</label>
                                            <select id="facture-status-<?= (int)$f['id'] ?>" name="statut" class="facture-status-select">
                                                <option value="brouillon" <?= $status === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                                                <option value="envoyee" <?= $status === 'envoyee' ? 'selected' : '' ?>>Envoyée</option>
                                                <option value="payee" <?= $status === 'payee' ? 'selected' : '' ?>>Payée</option>
                                                <option value="en_retard" <?= $status === 'en_retard' ? 'selected' : '' ?>>En retard</option>
                                                <option value="annulee" <?= $status === 'annulee' ? 'selected' : '' ?>>Annulée</option>
                                            </select>
                                            <button type="submit" class="fiche-action-btn facture-update-btn">
                                                Mettre à jour
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    
    <?php if ($isAdminOrDirigeant): ?>
        <!-- Section SAV Résolus -->
        <section class="panel sav-panel" id="savPanel" style="display: none;">
            <h2 class="panel-title">SAV Résolus <span class="badge role" style="font-size: 0.75rem; margin-left: 0.5rem;"><?= count($recentSav) ?></span></h2>
            <p class="panel-subtitle">
                Liste complète de tous les SAV résolus. Vous pouvez <strong>réouvrir un SAV</strong> en modifiant son statut de "résolu" à "ouvert".
            </p>

            <!-- Barre de recherche pour les SAV -->
            <form method="get" action="/public/profil.php#savPanel" class="filtre-form" style="margin-bottom: 1.5rem;">
                <div class="filter-bar">
                    <div class="filter-field grow">
                        <label for="search_sav" class="sr-only">Rechercher dans les SAV</label>
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input class="filter-input" type="search" id="search_sav" name="search_sav" 
                               value="<?= h($searchSav) ?>" 
                               placeholder="Rechercher par raison sociale, nom, prénom du client ou technicien…" 
                               aria-label="Rechercher dans les SAV" 
                               autocomplete="off" />
                        <?php if ($searchSav !== ''): ?>
                            <button type="button" class="input-clear" id="clearSavSearch" aria-label="Effacer la recherche">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($searchSav !== ''): ?>
                    <div class="filter-hint">
                        <small>🔍 Filtre actif : "<?= h($searchSav) ?>" - <?= count($recentSav) ?> résultat(s)</small>
                    </div>
                <?php endif; ?>
            </form>

            <div class="table-responsive">
                <table class="users-table sav-table" role="table" aria-label="SAV résolus">
                    <thead>
                        <tr>
                            <th scope="col">Date ouverture</th>
                            <th scope="col">Référence</th>
                            <th scope="col">Client</th>
                            <th scope="col">Description</th>
                            <th scope="col">Type panne</th>
                            <th scope="col">Priorité</th>
                            <th scope="col">Date fermeture</th>
                            <th scope="col">Technicien</th>
                            <th scope="col">Statut</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSav)): ?>
                            <tr>
                                <td colspan="10" class="aucun" role="cell">Aucun SAV résolu enregistré.</td>
                            </tr>
                        <?php else: ?>
                            <?php
                            $statusLabels = [
                                'ouvert' => 'Ouvert',
                                'en_cours' => 'En cours',
                                'resolu' => 'Résolu',
                                'annule' => 'Annulé'
                            ];
                            $statusClasses = [
                                'ouvert' => 'statut-ouvert',
                                'en_cours' => 'statut-en-cours',
                                'resolu' => 'statut-resolu',
                                'annule' => 'statut-annule'
                            ];
                            $prioriteLabels = [
                                'basse' => 'Basse',
                                'normale' => 'Normale',
                                'haute' => 'Haute',
                                'urgente' => 'Urgente'
                            ];
                            $prioriteColors = [
                                'basse' => '#6b7280',
                                'normale' => '#3b82f6',
                                'haute' => '#f59e0b',
                                'urgente' => '#ef4444'
                            ];
                            $typePanneLabels = [
                                'logiciel' => 'Logiciel',
                                'materiel' => 'Matériel',
                                'piece_rechangeable' => 'Pièce réchangeable'
                            ];
                            ?>
                            <?php foreach ($recentSav as $s): ?>
                                <?php
                                $status = $s['statut'] ?? 'resolu';
                                $statusClass = $statusClasses[$status] ?? 'statut-resolu';
                                $priorite = $s['priorite'] ?? 'normale';
                                $prioriteColor = $prioriteColors[$priorite] ?? '#6b7280';
                                $technicienNom = trim(($s['technicien_prenom'] ?? '') . ' ' . ($s['technicien_nom'] ?? ''));
                                if ($technicienNom === '') {
                                    $technicienNom = '—';
                                }
                                ?>
                                <tr role="row">
                                    <td data-label="Date ouverture" role="cell">
                                        <span style="font-weight: 500; color: var(--text-primary);">
                                            <?= isset($s['date_ouverture']) ? h(formatDate($s['date_ouverture'], 'd/m/Y')) : '—' ?>
                                        </span>
                                    </td>
                                    <td data-label="Référence" role="cell">
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; font-weight: 600; color: var(--text-primary);">
                                            <?= h($s['reference'] ?? '—') ?>
                                        </span>
                                    </td>
                                    <td data-label="Client" role="cell">
                                        <?php if (!empty($s['client_nom'])): ?>
                                            <div style="display: flex; flex-direction: column; gap: 0.25rem;">
                                                <span style="font-weight: 600; color: var(--text-primary);">
                                                    <?= h($s['client_nom']) ?>
                                                </span>
                                                <?php if (!empty($s['client_code'])): ?>
                                                    <span style="font-size: 0.85rem; color: var(--text-secondary);">
                                                        <?= h($s['client_code']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Description" role="cell">
                                        <span style="color: var(--text-primary); max-width: 300px; display: inline-block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="<?= h($s['description'] ?? '') ?>">
                                            <?= h(mb_substr($s['description'] ?? '', 0, 80)) ?><?= mb_strlen($s['description'] ?? '') > 80 ? '...' : '' ?>
                                        </span>
                                    </td>
                                    <td data-label="Type panne" role="cell">
                                        <?php if (!empty($s['type_panne'])): ?>
                                            <span style="display: inline-block; padding: 0.25rem 0.5rem; background: var(--bg-secondary); border-radius: var(--radius-sm); font-size: 0.875rem; color: var(--text-primary);">
                                                <?= h($typePanneLabels[$s['type_panne']] ?? $s['type_panne']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size: 0.875rem;">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Priorité" role="cell">
                                        <span style="display: inline-block; padding: 0.25rem 0.5rem; background: <?= $prioriteColor ?>20; border: 1px solid <?= $prioriteColor ?>40; border-radius: var(--radius-sm); font-size: 0.875rem; font-weight: 500; color: <?= $prioriteColor ?>;">
                                            <?= h($prioriteLabels[$priorite] ?? $priorite) ?>
                                        </span>
                                    </td>
                                    <td data-label="Date fermeture" role="cell">
                                        <span style="font-weight: 500; color: var(--text-primary);">
                                            <?= isset($s['date_fermeture']) ? h(formatDate($s['date_fermeture'], 'd/m/Y')) : '—' ?>
                                        </span>
                                    </td>
                                    <td data-label="Technicien" role="cell">
                                        <span style="color: var(--text-primary);">
                                            <?= h($technicienNom) ?>
                                        </span>
                                    </td>
                                    <td data-label="Statut" role="cell">
                                        <span class="badge <?= $statusClass ?>">
                                            <?= h($statusLabels[$status] ?? $status) ?>
                                        </span>
                                    </td>
                                    <td data-label="Actions" class="actions" role="cell">
                                        <form method="post" action="/public/profil.php#savPanel" class="inline sav-status-form">
                                            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                            <input type="hidden" name="action" value="update_sav_status">
                                            <input type="hidden" name="sav_id" value="<?= (int)$s['id'] ?>">
                                            <label for="sav-status-<?= (int)$s['id'] ?>" class="sr-only">Statut</label>
                                            <select id="sav-status-<?= (int)$s['id'] ?>" name="statut" class="sav-status-select">
                                                <option value="ouvert" <?= $status === 'ouvert' ? 'selected' : '' ?>>Ouvert</option>
                                                <option value="en_cours" <?= $status === 'en_cours' ? 'selected' : '' ?>>En cours</option>
                                                <option value="resolu" <?= $status === 'resolu' ? 'selected' : '' ?>>Résolu</option>
                                                <option value="annule" <?= $status === 'annule' ? 'selected' : '' ?>>Annulé</option>
                                            </select>
                                            <button type="submit" class="fiche-action-btn sav-update-btn">
                                                Mettre à jour
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
    
    <?php if ($isAdminOrDirigeant): ?>
        <!-- Section Historique des Imports SFTP et IONOS (masquée par défaut) -->
        <section class="panel import-history-panel" id="importHistoryPanel" style="display: none;">
            <h2 class="panel-title">Historique des Imports (SFTP & IONOS) <span class="badge role" style="font-size: 0.75rem; margin-left: 0.5rem;"><?= count($importHistory) ?></span></h2>
            <p class="panel-subtitle">Historique complet de toutes les exécutions d'import SFTP et IONOS avec détails (fichiers/lignes traités, lignes insérées, erreurs, etc.).</p>
            
            <!-- Barre de recherche pour les imports (optionnelle, pour cohérence) -->
            <form method="get" action="/public/profil.php#importHistoryPanel" class="filtre-form" style="margin-bottom: 1.5rem;">
                <div class="filter-bar">
                    <div class="filter-field grow">
                        <label for="search_imports" class="sr-only">Rechercher dans les imports</label>
                        <svg class="search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <input class="filter-input" type="search" id="search_imports" name="search_imports" 
                               value="<?= h($searchImports) ?>" 
                               placeholder="Rechercher par date ou type d'import…" 
                               aria-label="Rechercher dans les imports" 
                               autocomplete="off" />
                        <?php if ($searchImports !== ''): ?>
                            <button type="button" class="input-clear" id="clearImportsSearch" aria-label="Effacer la recherche">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($searchImports !== ''): ?>
                    <div class="filter-hint">
                        <small>🔍 Filtre actif : "<?= h($searchImports) ?>" - <?= count($importHistory) ?> résultat(s)</small>
                    </div>
                <?php endif; ?>
            </form>
            
            <?php
            // Récupérer l'historique des imports SFTP et IONOS (avec filtrage optionnel)
            $importsParams = [];
            $importsWhere = ["(msg LIKE '%\"type\":\"sftp\"%' OR msg LIKE '%\"type\":\"ionos\"%' OR msg LIKE '%\"type\":\"sftp_check\"%' OR msg LIKE '%\"type\":\"ionos_check\"%')"];
            
            // Pour les imports, on peut filtrer par date ou contenu du message
            // Le filtrage par nom/prénom/raison sociale n'est pas applicable ici
            // mais on peut garder le paramètre pour cohérence
            
            $importsSql = "
                SELECT 
                    id,
                    ran_at,
                    imported,
                    skipped,
                    ok,
                    msg
                FROM import_run
                WHERE " . implode(' AND ', $importsWhere) . "
                ORDER BY ran_at DESC";
            $importHistory = safeFetchAll($pdo, $importsSql, $importsParams, 'import_history');
            
            // Fonction pour parser le message JSON et extraire les informations
            function parseImportMessage($msg): array {
                if (empty($msg)) {
                    return [
                        'type' => 'sftp',
                        'files_seen' => 0,
                        'files_processed' => 0,
                        'files_deleted' => 0,
                        'rows_seen' => 0,
                        'rows_processed' => 0,
                        'rows_inserted' => 0,
                        'rows_skipped' => 0,
                        'inserted_rows' => 0,
                        'duration_ms' => 0,
                        'error' => null,
                        'message' => null
                    ];
                }
                
                $data = json_decode($msg, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'type' => 'sftp',
                        'files_seen' => 0,
                        'files_processed' => 0,
                        'files_deleted' => 0,
                        'rows_seen' => 0,
                        'rows_processed' => 0,
                        'rows_inserted' => 0,
                        'rows_skipped' => 0,
                        'inserted_rows' => 0,
                        'duration_ms' => 0,
                        'error' => 'Erreur de parsing JSON',
                        'message' => null
                    ];
                }
                
                $type = $data['type'] ?? 'sftp';
                
                // Gérer les types 'sftp_check' et 'ionos_check' (vérifications manuelles)
                $isCheck = strpos($type, '_check') !== false;
                if ($isCheck) {
                    $type = str_replace('_check', '', $type);
                }
                
                // Pour SFTP, utiliser files_* et inserted_rows
                // Pour IONOS, utiliser rows_* et rows_inserted
                if ($type === 'ionos') {
                    return [
                        'type' => 'ionos',
                        'files_seen' => 0,
                        'files_processed' => 0,
                        'files_deleted' => 0,
                        'rows_seen' => (int)($data['rows_seen'] ?? 0),
                        'rows_processed' => (int)($data['rows_processed'] ?? 0),
                        'rows_inserted' => (int)($data['rows_inserted'] ?? 0),
                        'rows_skipped' => (int)($data['rows_skipped'] ?? 0),
                        'inserted_rows' => (int)($data['rows_inserted'] ?? 0),
                        'duration_ms' => (int)($data['duration_ms'] ?? 0),
                        'error' => $data['error'] ?? null,
                        'message' => ($isCheck ? 'Vérification manuelle' : null)
                    ];
                } else {
                    return [
                        'type' => 'sftp',
                        'files_seen' => (int)($data['files_seen'] ?? 0),
                        'files_processed' => (int)($data['files_processed'] ?? 0),
                        'files_deleted' => (int)($data['files_deleted'] ?? 0),
                        'rows_seen' => 0,
                        'rows_processed' => 0,
                        'rows_inserted' => 0,
                        'rows_skipped' => 0,
                        'inserted_rows' => (int)($data['inserted_rows'] ?? 0),
                        'duration_ms' => (int)($data['duration_ms'] ?? 0),
                        'error' => $data['error'] ?? null,
                        'message' => ($isCheck ? 'Vérification manuelle' : null)
                    ];
                }
            }
            
            // Fonction pour déterminer le statut
            function getImportStatus($ok, $msgData): string {
                if (!$ok || !empty($msgData['error'])) {
                    return 'error';
                }
                $type = $msgData['type'] ?? 'sftp';
                
                if ($type === 'ionos') {
                    // Pour IONOS, vérifier rows_processed et rows_inserted
                    if ($msgData['rows_processed'] > 0 && $msgData['rows_inserted'] > 0) {
                        return 'success';
                    }
                    if ($msgData['rows_processed'] > 0) {
                        return 'partial';
                    }
                } else {
                    // Pour SFTP, vérifier files_processed et files_deleted
                    if ($msgData['files_processed'] > 0 && $msgData['files_processed'] === $msgData['files_deleted']) {
                        return 'success';
                    }
                    if ($msgData['files_processed'] > 0) {
                        return 'partial';
                    }
                }
                return 'empty';
            }
            ?>
            
            <div class="table-responsive">
                <table class="users-table" role="table" aria-label="Historique des imports SFTP">
                    <thead>
                        <tr>
                            <th scope="col">Date/Heure</th>
                            <th scope="col">Type</th>
                            <th scope="col">Statut</th>
                            <th scope="col">Fichiers/Lignes vus</th>
                            <th scope="col">Fichiers/Lignes traités</th>
                            <th scope="col">Fichiers supprimés</th>
                            <th scope="col">Lignes insérées</th>
                            <th scope="col">Durée</th>
                            <th scope="col">Détails</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($importHistory)): ?>
                            <tr>
                                <td colspan="9" class="aucun" role="cell">Aucun import enregistré.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($importHistory as $run): ?>
                                <?php 
                                $msgData = parseImportMessage($run['msg']);
                                // Décoder aussi pour vérifier si c'est une vérification manuelle
                                $rawData = json_decode($run['msg'], true);
                                $isManualCheck = isset($rawData['source']) && $rawData['source'] === 'manual_check';
                                $status = getImportStatus((int)$run['ok'] === 1, $msgData);
                                $statusLabels = [
                                    'success' => 'Succès',
                                    'error' => 'Erreur',
                                    'partial' => 'Partiel',
                                    'empty' => 'Aucun fichier'
                                ];
                                $statusClasses = [
                                    'success' => 'success',
                                    'error' => 'muted',
                                    'partial' => 'role',
                                    'empty' => 'muted'
                                ];
                                $statusLabel = $statusLabels[$status] ?? 'Inconnu';
                                $statusClass = $statusClasses[$status] ?? 'muted';
                                
                                // Formater la durée
                                $durationSeconds = $msgData['duration_ms'] > 0 ? ($msgData['duration_ms'] / 1000) : 0;
                                $durationFormatted = $durationSeconds > 0 ? number_format($durationSeconds, 1) . 's' : '—';
                                ?>
                                <tr role="row">
                                    <td data-label="Date/Heure" role="cell">
                                        <?= formatDateTime($run['ran_at'], 'd/m/Y H:i:s') ?>
                                    </td>
                                    <td data-label="Type" role="cell">
                                        <?php
                                        $typeDisplay = $msgData['type'] ?? 'sftp';
                                        $typeBadgeClass = $typeDisplay === 'ionos' ? 'role' : 'success';
                                        $typeLabel = strtoupper($typeDisplay) . ($isManualCheck ? ' (Vérif)' : '');
                                        ?>
                                        <span class="badge <?= $typeBadgeClass ?>">
                                            <?= h($typeLabel) ?>
                                        </span>
                                    </td>
                                    <td data-label="Statut" role="cell">
                                        <span class="badge <?= $statusClass ?>"><?= h($statusLabel) ?></span>
                                    </td>
                                    <td data-label="Fichiers/Lignes vus" role="cell">
                                        <?php
                                        $seen = $msgData['type'] === 'ionos' ? $msgData['rows_seen'] : $msgData['files_seen'];
                                        echo h((string)$seen);
                                        ?>
                                    </td>
                                    <td data-label="Fichiers/Lignes traités" role="cell">
                                        <?php
                                        $processed = $msgData['type'] === 'ionos' ? $msgData['rows_processed'] : $msgData['files_processed'];
                                        echo h((string)$processed);
                                        ?>
                                    </td>
                                    <td data-label="Fichiers supprimés" role="cell">
                                        <?= $msgData['type'] === 'ionos' ? '—' : h((string)$msgData['files_deleted']) ?>
                                    </td>
                                    <td data-label="Lignes insérées" role="cell">
                                        <?= h((string)$msgData['inserted_rows']) ?>
                                    </td>
                                    <td data-label="Durée" role="cell">
                                        <?= h($durationFormatted) ?>
                                    </td>
                                    <td data-label="Détails" role="cell">
                                        <?php if ($msgData['error']): ?>
                                            <span class="text-muted" title="<?= h($msgData['error']) ?>" style="cursor: help;">
                                                ⚠️ Erreur
                                            </span>
                                        <?php elseif ($msgData['message']): ?>
                                            <span class="text-muted" title="<?= h($msgData['message']) ?>" style="cursor: help;">
                                                ℹ️ Info
                                            </span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php if ($editing): ?>
<script>
    // Scroll automatique vers le panneau d'édition
    (function() {
        const panel = document.getElementById('editPanel');
        if (panel && 'scrollIntoView' in panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    })();
</script>
<?php endif; ?>

<script>

/* Recherche intelligente en temps réel avec AJAX */
(function() {
    const searchInput = document.getElementById('q');
    const searchForm = document.getElementById('searchForm');
    const usersTableBody = document.getElementById('usersTableBody');
    const usersCount = document.getElementById('usersCount');
    const searchLoading = document.getElementById('searchLoading');
    const clearSearchBtn = document.getElementById('clearSearch');
    
    if (!searchInput || !usersTableBody) return;
    
    let debounceTimer;
    let currentRequest = null;
    const DEBOUNCE_DELAY = 300; // 300ms pour une recherche réactive
    
    // Variables PHP nécessaires pour le rendu
    const currentUserId = <?= json_encode($currentUser['id']) ?>;
    const hasRestrictions = <?= json_encode($hasRestrictions) ?>;
    const isAdminOrDirigeant = <?= json_encode($isAdminOrDirigeant) ?>;
    const csrfToken = <?= json_encode($CSRF) ?>;
    
    // Fonction pour mettre à jour le tableau
    function updateTable(users) {
        if (!users || users.length === 0) {
            usersTableBody.innerHTML = '<tr><td colspan="7" class="aucun" role="cell">Aucun utilisateur trouvé.</td></tr>';
            usersCount.textContent = '0';
            return;
        }
        
        usersCount.textContent = users.length.toString();
        
        let html = '';
        users.forEach(function(u) {
            const fullName = (u.prenom || '') + ' ' + (u.nom || '');
            const canEdit = !hasRestrictions || u.id === currentUserId;
            const statusClass = u.statut === 'actif' ? 'success' : 'muted';
            const toggleClass = u.statut === 'actif' ? 'btn-danger' : 'btn-success';
            const toggleText = u.statut === 'actif' ? 'Désactiver' : 'Activer';
            const toggleLabel = u.statut === 'actif' ? 'Désactiver' : 'Activer';
            
            html += '<tr role="row">';
            html += '<td data-label="Nom" role="cell">' + escapeHtml(fullName.trim()) + '</td>';
            html += '<td data-label="Email" role="cell">' + escapeHtml(u.email || '') + '</td>';
            html += '<td data-label="Téléphone" role="cell">' + escapeHtml(u.telephone || '') + '</td>';
            html += '<td data-label="Rôle" role="cell"><span class="badge role">' + escapeHtml(u.emploi || '') + '</span></td>';
            html += '<td data-label="Statut" role="cell"><span class="badge ' + statusClass + '">' + escapeHtml(u.statut || '') + '</span></td>';
            html += '<td data-label="Début" role="cell">' + escapeHtml(u.date_debut || '') + '</td>';
            html += '<td data-label="Actions" class="actions" role="cell">';
            
            if (!canEdit) {
                html += '<span class="text-muted" aria-label="Action non autorisée">Non autorisé</span>';
            } else {
                html += '<a class="btn btn-primary" href="/public/profil.php?edit=' + u.id + '" aria-label="Modifier l\'utilisateur ' + escapeHtml(fullName.trim()) + '">Modifier</a>';
                if (isAdminOrDirigeant) {
                    html += '<form method="post" action="/public/profil.php" class="inline">';
                    html += '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">';
                    html += '<input type="hidden" name="action" value="toggle">';
                    html += '<input type="hidden" name="id" value="' + u.id + '">';
                    html += '<input type="hidden" name="to" value="' + (u.statut === 'actif' ? 'inactif' : 'actif') + '">';
                    html += '<button type="submit" class="btn ' + toggleClass + '" aria-label="' + toggleLabel + ' l\'utilisateur ' + escapeHtml(fullName.trim()) + '">' + toggleText + '</button>';
                    html += '</form>';
                }
            }
            
            html += '</td></tr>';
        });
        
        usersTableBody.innerHTML = html;
    }
    
    // Fonction d'échappement HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Fonction de recherche AJAX
    function performSearch(query) {
        // Annuler la requête précédente si elle existe
        if (currentRequest) {
            currentRequest.abort();
        }
        
        // Afficher le loader
        if (searchLoading) {
            searchLoading.style.display = 'inline-block';
        }
        
        // Effectuer la requête AJAX (si vide, charge tous les utilisateurs)
        const url = '/API/profil_search_users.php?q=' + encodeURIComponent(query || '');
        currentRequest = fetch(url, {
            method: 'GET',
            headers: {
                'Accept': 'application/json'
            },
            credentials: 'include'
        })
        .then(function(response) {
            if (!response.ok) {
                throw new Error('Erreur réseau');
            }
            return response.json();
        })
        .then(function(data) {
            if (data.ok && data.users) {
                updateTable(data.users);
            } else {
                console.error('Erreur de recherche:', data.error || 'Erreur inconnue');
                usersTableBody.innerHTML = '<tr><td colspan="7" class="aucun" role="cell">Erreur lors de la recherche.</td></tr>';
                usersCount.textContent = '0';
            }
        })
        .catch(function(error) {
            if (error.name !== 'AbortError') {
                console.error('Erreur AJAX:', error);
                usersTableBody.innerHTML = '<tr><td colspan="7" class="aucun" role="cell">Erreur de connexion.</td></tr>';
                usersCount.textContent = '0';
            }
        })
        .finally(function() {
            // Masquer le loader
            if (searchLoading) {
                searchLoading.style.display = 'none';
            }
            currentRequest = null;
        });
    }
    
    // Fonction pour afficher/masquer le bouton de nettoyage
    function toggleClearButton(show) {
        if (clearSearchBtn) {
            if (show) {
                clearSearchBtn.style.display = 'block';
            } else {
                clearSearchBtn.style.display = 'none';
            }
        }
    }
    
    // Écouteur sur l'input avec debounce
    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        toggleClearButton(query !== '');
        clearTimeout(debounceTimer);
        
        debounceTimer = setTimeout(function() {
            performSearch(query);
        }, DEBOUNCE_DELAY);
    });
    
    // Bouton de nettoyage
    if (clearSearchBtn) {
        clearSearchBtn.addEventListener('click', function() {
            searchInput.value = '';
            toggleClearButton(false);
            performSearch('');
            searchInput.focus();
        });
    }
    
    // Empêcher la soumission du formulaire (on utilise AJAX)
    if (searchForm) {
        searchForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const query = searchInput.value.trim();
            clearTimeout(debounceTimer);
            performSearch(query);
        });
    }
})();

/* Gestion des permissions */
(function() {
    const selectAllBtn = document.getElementById('selectAllPerms');
    const deselectAllBtn = document.getElementById('deselectAllPerms');
    const permissionCheckboxes = document.querySelectorAll('.permission-checkbox');
    
    if (selectAllBtn) {
        selectAllBtn.addEventListener('click', function() {
            permissionCheckboxes.forEach(function(cb) {
                cb.checked = true;
            });
        });
    }
    
    if (deselectAllBtn) {
        deselectAllBtn.addEventListener('click', function() {
            permissionCheckboxes.forEach(function(cb) {
                cb.checked = false;
            });
        });
    }
})();


</script>

</body>
</html>
