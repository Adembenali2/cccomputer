<?php
/**
 * /public/profil.php - Gestion des utilisateurs
 * Version refactorisée : Sécurité, Performance, UI/UX améliorées
 */

// ========================================================================
// SÉCURITÉ D'ABORD
// ========================================================================
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
const IMPORT_HISTORY_LIMIT = 20;
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
            
            // Liste des pages disponibles
            $availablePages = [
                'dashboard' => 'Dashboard',
                'agenda' => 'Agenda',
                'clients' => 'Clients',
                'client_fiche' => 'Fiche Client',
                'historique' => 'Historique',
                'profil' => 'Gestion Utilisateurs',
                'maps' => 'Cartes & Planification',
                'messagerie' => 'Messagerie',
                'sav' => 'SAV',
                'livraison' => 'Livraisons',
                'stock' => 'Stock',
                'photocopieurs_details' => 'Détails Photocopieurs'
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

// Récupérer les imports (seulement si nécessaire pour l'affichage)
$imports = [];
if (true) { // Toujours charger pour l'icône
$imports = safeFetchAll(
    $pdo,
    "SELECT id, ran_at, imported, skipped, ok, msg FROM import_run ORDER BY id DESC LIMIT " . IMPORT_HISTORY_LIMIT,
    [],
    'imports_history'
);
}

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
// Liste des pages disponibles pour les permissions
$availablePages = [
    'dashboard' => 'Dashboard',
    'agenda' => 'Agenda',
    'clients' => 'Clients',
    'client_fiche' => 'Fiche Client',
    'historique' => 'Historique',
    'profil' => 'Gestion Utilisateurs',
    'maps' => 'Cartes & Planification',
    'messagerie' => 'Messagerie',
    'sav' => 'SAV',
    'livraison' => 'Livraisons',
    'stock' => 'Stock',
    'photocopieurs_details' => 'Détails Photocopieurs'
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
        /* Import icon styles */
        .page-header { position: relative; }
        .import-mini {
            position: absolute; right: 0; top: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .import-mini-btn {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 999px;
            background: var(--bg-primary); border: 1px solid var(--border-color);
            cursor: pointer; transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            font-size: 16px;
        }
        .import-mini-btn:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-md);
        }
        .import-mini-btn.ok { color: #16a34a; border-color: rgba(22, 163, 74, 0.3); }
        .import-mini-btn.ko { color: #dc2626; border-color: rgba(220, 38, 38, 0.3); }
        .import-mini-btn.run { color: #4338ca; border-color: rgba(67, 56, 202, 0.3); }

        .import-drop {
            position: absolute; right: 0; top: 44px; z-index: 30;
            width: min(520px, 92vw);
            background: var(--bg-primary); border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 12px;
            display: none;
            animation: slideDown 0.2s ease;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .import-drop.open { display: block; }
        .import-drop h3 {
            margin: 4px 6px 12px; font-size: 14px; font-weight: 700;
            color: var(--text-primary);
        }
        .import-list {
            max-height: 320px; overflow-y: auto; padding-right: 4px;
        }
        .import-list::-webkit-scrollbar {
            width: 6px;
        }
        .import-list::-webkit-scrollbar-track {
            background: var(--bg-secondary);
            border-radius: 3px;
        }
        .import-list::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        .import-list::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
        .imp-item {
            display: grid; grid-template-columns: 28px 1fr auto; gap: 10px;
            align-items: center; padding: 10px; border-radius: var(--radius-md);
            transition: background-color 0.2s ease;
        }
        .imp-item:hover { background: var(--bg-secondary); }
        .imp-item + .imp-item { border-top: 1px solid var(--border-color); }
        .imp-ico {
            width: 24px; height: 24px; border-radius: 999px;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 12px;
        }
        .imp-ico.ok { background: #dcfce7; color: #16a34a; }
        .imp-ico.ko { background: #fee2e2; color: #dc2626; }
        .imp-ico.run { background: #e0e7ff; color: #4338ca; }
        .imp-main { min-width: 0; }
        .imp-title {
            font-size: 13px; font-weight: 600; color: var(--text-primary);
        }
        .imp-sub {
            font-size: 12px; color: var(--text-secondary); margin-top: 2px;
        }
        .imp-badges {
            display: flex; gap: 6px; flex-wrap: wrap;
        }
        .badge-mini {
            font-size: 11px; padding: 3px 8px; border-radius: 999px;
            background: var(--bg-secondary); color: var(--text-secondary);
            border: 1px solid var(--border-color);
        }
        .files {
            margin-top: 4px; font-size: 11px; color: var(--text-secondary);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        @media (max-width: 640px) {
            .import-drop { right: 6px; left: 6px; width: auto; }
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
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-profil">
    <header class="page-header">
        <h1 class="page-title">Gestion des utilisateurs</h1>
        <p class="page-sub">Page réservée aux administrateurs (Admin), dirigeants, techniciens et livreurs pour créer, modifier et activer/désactiver des comptes.</p>

        <!-- Icône Import -->
        <div class="import-mini" id="impMini">
            <?php
            $last = $imports[0] ?? null;
            $stateClass = 'run';
            $glyph = '⏳';
            $title = 'Imports';
            if ($last) {
                if ((int)$last['ok'] === 1) {
                    $stateClass = 'ok';
                    $glyph = '✓';
                    $title = 'Dernier import OK';
                } elseif ((int)$last['ok'] === 0) {
                    $stateClass = 'ko';
                    $glyph = '!';
                    $title = 'Dernier import KO';
                }
            }
            ?>
            <button class="import-mini-btn <?= h($stateClass) ?>" id="impBtn" type="button" 
                    aria-haspopup="true" aria-expanded="false" title="<?= h($title) ?>" aria-label="<?= h($title) ?>">
                <?= h($glyph) ?>
            </button>

            <div class="import-drop" id="impDrop" role="dialog" aria-label="Derniers imports" aria-modal="true">
                <h3>Derniers imports</h3>
                <div class="import-list">
                    <?php if (empty($imports)): ?>
                        <div class="imp-item">
                            <div class="imp-ico run">⏳</div>
                            <div class="imp-main">
                                <div class="imp-title">Aucun import trouvé</div>
                                <div class="imp-sub">Démarrez un import depuis le dashboard.</div>
                            </div>
                            <div class="imp-badges"></div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($imports as $row): ?>
                            <?php
                                $ok = (int)($row['ok'] ?? 0);
                                $icoCls = $ok === 1 ? 'ok' : ($ok === 0 ? 'ko' : 'run');
                                $icoTxt = $ok === 1 ? '✓' : ($ok === 0 ? '!' : '⏳');
                                $msg = decode_msg($row);
                                $files = $msg['files'] ?? null;
                                $filesStr = '';
                            if (is_array($files) && !empty($files)) {
                                    $filesStr = implode(', ', array_slice($files, 0, 5));
                                    if (count($files) > 5) $filesStr .= ' …';
                                }
                            ?>
                            <div class="imp-item">
                                <div class="imp-ico <?= h($icoCls) ?>" aria-label="<?= $ok === 1 ? 'Succès' : ($ok === 0 ? 'Erreur' : 'En cours') ?>">
                                    <?= h($icoTxt) ?>
                                </div>
                                <div class="imp-main">
                                    <div class="imp-title">
                                        <?= $ok === 1 ? 'Import réussi' : ($ok === 0 ? 'Import échoué' : 'Import en cours') ?>
                                    </div>
                                    <div class="imp-sub">
                                        Le <?= h($row['ran_at'] ?? '') ?> — id #<?= (int)$row['id'] ?>
                                    </div>
                                    <?php if ($filesStr !== ''): ?>
                                        <div class="files" title="<?= h($filesStr) ?>"><?= h($filesStr) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="imp-badges">
                                    <span class="badge-mini">ok: <?= h((string)$row['ok']) ?></span>
                                    <span class="badge-mini">importés: <?= (int)$row['imported'] ?></span>
                                    <span class="badge-mini">erreurs: <?= (int)$row['skipped'] ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <section class="profil-meta">
        <div class="meta-card">
            <span class="meta-label">Total utilisateurs</span>
            <strong class="meta-value"><?= h((string)$totalUsers) ?></strong>
            <?php if ($totalUsers >= USERS_LIMIT): ?>
                <span class="meta-chip" title="Limite d'affichage atteinte">+<?= USERS_LIMIT ?> affichés</span>
            <?php endif; ?>
        </div>
        <div class="meta-card">
            <span class="meta-label">Actifs</span>
            <strong class="meta-value success"><?= h((string)$activeUsers) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Inactifs</span>
            <strong class="meta-value" style="color: #dc2626;"><?= h((string)$inactiveUsers) ?></strong>
        </div>
        <div class="meta-card">
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
                <input class="filter-input" type="search" id="q" name="q" value="<?= h($search) ?>" 
                       placeholder="Rechercher par nom, prénom ou email (commence par)…" 
                       aria-label="Rechercher un utilisateur" 
                       autocomplete="off" />
                <span class="search-loading" id="searchLoading" style="display: none;" aria-hidden="true">⏳</span>
                <button type="button" class="input-clear" id="clearSearch" aria-label="Effacer la recherche" style="<?= $search === '' ? 'display: none;' : '' ?>">
                    <span aria-hidden="true">✕</span>
                </button>
            </div>
            </div>
        <div class="filter-hint">
            <small>Recherche en temps réel - tapez un nom, prénom ou email (recherche par début de mot).</small>
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
                                                <?= $u['statut'] === 'actif' ? 'Désactiver' : 'Activer' ?>
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
                            <?php foreach ($availablePages as $pageKey => $pageName): ?>
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
                                            <strong><?= h($pageName) ?></strong>
                                            <small><?= h($pageKey) ?>.php</small>
                                        </span>
                                    </label>
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
/* Gestion de l'icône import */
(function() {
    const btn = document.getElementById('impBtn');
    const drop = document.getElementById('impDrop');

    if (!btn || !drop) return;

    function toggleDropdown() {
        const isOpen = drop.classList.contains('open');
        drop.classList.toggle('open', !isOpen);
        btn.setAttribute('aria-expanded', String(!isOpen));
    }
    
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        toggleDropdown();
    });
    
    // Fermer au clic extérieur
    document.addEventListener('click', function(e) {
        if (!drop.classList.contains('open')) return;
        if (btn.contains(e.target) || drop.contains(e.target)) return;
        drop.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    });

    // Fermer avec Échap
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drop.classList.contains('open')) {
            drop.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
            btn.focus();
        }
    });
})();

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
            credentials: 'same-origin'
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
