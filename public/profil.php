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
// Recherche SAV par nom, prénom ou raison sociale du client
$savSearch = trim((string)($_GET['q_sav'] ?? ''));
$savSearch = mb_substr(preg_replace('/\s+/', ' ', $savSearch), 0, 120);

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
// RÉCUPÉRATION DES DONNÉES SAV, PAIEMENTS ET FACTURES
// ========================================================================
// Récupérer les SAV (filtrés par nom, prénom ou raison sociale du client si recherche)
$savSql = "
    SELECT s.id, s.reference, s.description, s.date_ouverture, s.date_fermeture, 
           s.statut, s.priorite, s.type_panne,
           c.raison_sociale as client_nom,
           c.nom_dirigeant as client_nom_dirigeant,
           c.prenom_dirigeant as client_prenom_dirigeant,
           u.nom as technicien_nom, u.prenom as technicien_prenom
    FROM sav s
    LEFT JOIN clients c ON s.id_client = c.id
    LEFT JOIN utilisateurs u ON s.id_technicien = u.id
";
$savParams = [];
if ($savSearch !== '') {
    $savLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $savSearch) . '%';
    $savSql .= " WHERE (c.raison_sociale LIKE ? OR c.nom_dirigeant LIKE ? OR c.prenom_dirigeant LIKE ?)";
    $savParams = [$savLike, $savLike, $savLike];
}
$savSql .= " ORDER BY s.date_ouverture DESC LIMIT 100";
$savList = safeFetchAll($pdo, $savSql, $savParams, 'sav_list');

// Récupérer les paiements
$paiementsList = safeFetchAll($pdo, "
    SELECT p.id, p.date_paiement, p.montant, p.mode_paiement, p.statut, 
           p.reference, p.recu_path,
           c.raison_sociale as client_nom,
           f.numero as facture_numero
    FROM paiements p
    LEFT JOIN clients c ON p.id_client = c.id
    LEFT JOIN factures f ON p.id_facture = f.id
    ORDER BY p.date_paiement DESC
    LIMIT 100
", [], 'paiements_list');

// Récupérer les factures
$facturesList = safeFetchAll($pdo, "
    SELECT f.id, f.numero, f.date_facture, f.montant_ht, f.tva, f.montant_ttc,
           f.statut, f.type, f.pdf_path,
           c.raison_sociale as client_nom
    FROM factures f
    LEFT JOIN clients c ON f.id_client = c.id
    ORDER BY f.date_facture DESC
    LIMIT 100
", [], 'factures_list');

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
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
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

        /* Quick actions bar */
        .quick-actions-bar {
            display: flex;
            gap: 1rem;
            margin: 1.5rem 0 2rem 0;
            flex-wrap: wrap;
        }

        .quick-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.875rem 1.5rem;
            background: var(--bg-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
            white-space: nowrap;
        }

        .quick-action-btn:hover {
            background: var(--bg-secondary);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .quick-action-btn:active {
            transform: translateY(0);
            box-shadow: var(--shadow-sm);
        }

        .quick-action-btn svg {
            flex-shrink: 0;
            stroke-width: 2.5;
        }

        .quick-action-btn span {
            line-height: 1;
        }

        @media (max-width: 768px) {
            .quick-actions-bar {
                flex-direction: column;
                gap: 0.75rem;
            }

            .quick-action-btn {
                width: 100%;
                justify-content: center;
                padding: 1rem 1.5rem;
            }
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
            padding: 1.5rem;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            scroll-margin-top: 100px; /* Offset pour le scroll */
            display: none; /* Caché par défaut */
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .payments-panel.active {
            display: block; /* Affiché quand actif */
            opacity: 1;
        }

        .payments-panel .panel-title {
            margin-bottom: 0.75rem;
            font-size: 1.25rem;
            font-weight: 700;
        }

        .payments-panel .panel-subtitle {
            margin-bottom: 1.5rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        /* Styles améliorés pour le tableau des factures */
        .factures-panel {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            scroll-margin-top: 100px; /* Offset pour le scroll */
            display: none; /* Caché par défaut */
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .factures-panel.active {
            display: block; /* Affiché quand actif */
            opacity: 1;
        }

        /* Styles améliorés pour le tableau des SAV */
        .sav-panel {
            margin-top: 2rem;
            padding: 1.5rem;
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            scroll-margin-top: 100px; /* Offset pour le scroll */
            display: none; /* Caché par défaut */
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .sav-panel.active {
            display: block; /* Affiché quand actif */
            opacity: 1;
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
            table-layout: auto;
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

        .sav-table thead th:nth-child(1) { width: 10%; min-width: 110px; } /* Date ouverture */
        .sav-table thead th:nth-child(2) { width: 11%; min-width: 120px; } /* Référence */
        .sav-table thead th:nth-child(3) { width: 13%; min-width: 130px; } /* Client */
        .sav-table thead th:nth-child(4) { width: 18%; min-width: 180px; } /* Description */
        .sav-table thead th:nth-child(5) { width: 10%; min-width: 100px; } /* Type panne */
        .sav-table thead th:nth-child(6) { width: 9%; min-width: 90px; } /* Priorité */
        .sav-table thead th:nth-child(7) { width: 10%; min-width: 110px; } /* Date fermeture */
        .sav-table thead th:nth-child(8) { width: 11%; min-width: 120px; } /* Technicien */
        .sav-table thead th:nth-child(9) { width: 8%; min-width: 80px; } /* Statut */
        .sav-table thead th:nth-child(10) { width: 18%; min-width: 150px; } /* Actions */

        .sav-table tbody tr {
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s ease;
        }

        .sav-table tbody tr:hover {
            background-color: var(--bg-secondary);
        }

        .sav-table tbody td {
            padding: 0.75rem 0.75rem;
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

        .factures-table thead th:nth-child(1) { width: 8%; } /* Date */
        .factures-table thead th:nth-child(2) { width: 9%; } /* Numéro */
        .factures-table thead th:nth-child(3) { width: 15%; } /* Client */
        .factures-table thead th:nth-child(4) { width: 8%; } /* Type */
        .factures-table thead th:nth-child(5) { width: 8%; } /* Montant HT */
        .factures-table thead th:nth-child(6) { width: 6%; } /* TVA */
        .factures-table thead th:nth-child(7) { width: 8%; } /* Total TTC */
        .factures-table thead th:nth-child(8) { width: 10%; } /* Statut */
        .factures-table thead th:nth-child(9) { width: 10%; } /* PDF */
        .factures-table thead th:nth-child(10) { width: 18%; } /* Actions */

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

        .payments-table thead th:nth-child(1) { width: 8%; } /* Date */
        .payments-table thead th:nth-child(2) { width: 15%; } /* Client */
        .payments-table thead th:nth-child(3) { width: 8%; } /* Facture */
        .payments-table thead th:nth-child(4) { width: 8%; } /* Montant */
        .payments-table thead th:nth-child(5) { width: 10%; } /* Mode */
        .payments-table thead th:nth-child(6) { width: 10%; } /* Statut */
        .payments-table thead th:nth-child(7) { width: 12%; } /* Référence */
        .payments-table thead th:nth-child(8) { width: 10%; } /* Reçu */
        .payments-table thead th:nth-child(9) { width: 17%; } /* Actions */

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
        
        
    </header>

    <div class="quick-actions-bar">
        <a href="#sav" class="quick-action-btn" onclick="scrollToSection(event, 'sav')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span>SAV</span>
        </a>
        <a href="#paiements" class="quick-action-btn" onclick="scrollToSection(event, 'paiements')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
            </svg>
            <span>Paiements</span>
        </a>
        <a href="#factures" class="quick-action-btn" onclick="scrollToSection(event, 'factures')">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span>Factures</span>
        </a>
    </div>

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

    <?php if ($isAdminOrDirigeant): ?>
    <div class="panel-toggle-buttons">
        <button type="button" class="panel-toggle-btn" data-target="createUserPanel">Créer un utilisateur</button>
        <?php if ($editing): ?>
        <a href="/public/profil.php" class="panel-toggle-btn is-active">Utilisateurs</a>
        <?php else: ?>
        <button type="button" class="panel-toggle-btn is-active" data-target="usersPanel">Utilisateurs</button>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="panel-toggle-buttons">
        <?php if ($editing): ?>
        <a href="/public/profil.php" class="panel-toggle-btn is-active">Utilisateurs</a>
        <?php else: ?>
        <button type="button" class="panel-toggle-btn is-active" data-target="usersPanel">Utilisateurs</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($isAdminOrDirigeant): ?>
    <div class="panel panel-toggle-target" id="createUserPanel">
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

    <div class="panel panel-toggle-target" id="usersPanel">
        <!-- Liste des utilisateurs : visible quand on n'est pas en édition -->
        <div class="users-panel-list <?= $editing ? 'is-hidden' : '' ?>">
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
                                        <a class="btn btn-primary" href="/public/profil.php?edit=<?= (int)$u['id'] ?>&perm_user=<?= (int)$u['id'] ?>#permissionsPanel" 
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
        </div><!-- .users-panel-list -->

        <!-- Modifier + Gestion des permissions : visible à la place de la liste quand on a cliqué sur Modifier -->
        <div class="users-panel-edit <?= !$editing ? 'is-hidden' : '' ?>">
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
        <!-- Section Gestion des Permissions (ACL) - à côté du bloc Modifier -->
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

        </div><!-- .users-panel-edit -->
    </div><!-- #usersPanel -->

    <!-- Section SAV -->
    <section id="sav" class="sav-panel">
        <h2 class="panel-title">SAV</h2>
        <p class="panel-subtitle">Recherchez et consultez les tickets SAV par client (nom, prénom, raison sociale).</p>

        <form method="get" action="/public/profil.php" class="sav-search-form" id="savSearchForm">
            <?php if (!empty($_GET['edit'])): ?><input type="hidden" name="edit" value="<?= h($_GET['edit']) ?>"><?php endif; ?>
            <?php if (!empty($_GET['perm_user'])): ?><input type="hidden" name="perm_user" value="<?= h($_GET['perm_user']) ?>"><?php endif; ?>
            <div class="sav-search-bar">
                <label for="q_sav" class="sr-only">Rechercher un SAV</label>
                <svg class="sav-search-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="20" height="20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                <input type="search" id="q_sav" name="q_sav" value="<?= h($savSearch) ?>"
                       placeholder="Nom, prénom ou raison sociale du client…"
                       class="sav-search-input"
                       aria-label="Rechercher un SAV par client">
                <button type="submit" class="sav-search-btn">Rechercher</button>
            </div>
        </form>

        <div class="sav-table-wrap">
            <div class="table-responsive sav-table-responsive">
                <table class="sav-table" role="table" aria-label="Tickets SAV">
                    <thead>
                        <tr>
                            <th scope="col">Date ouverture</th>
                            <th scope="col">Référence</th>
                            <th scope="col">Client</th>
                            <th scope="col">Description</th>
                            <th scope="col">Type panne</th>
                            <th scope="col">Date fermeture</th>
                            <th scope="col">Statut</th>
                            <th scope="col">Technicien</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($savList)): ?>
                            <tr>
                                <td colspan="9" class="sav-aucun"><?= $savSearch !== '' ? 'Aucun ticket SAV ne correspond à votre recherche.' : 'Aucun ticket SAV trouvé.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($savList as $sav): 
                                $clientLabel = $sav['client_nom'] ?? 'N/A';
                                $dirigeant = trim(($sav['client_prenom_dirigeant'] ?? '') . ' ' . ($sav['client_nom_dirigeant'] ?? ''));
                                if ($dirigeant !== '') {
                                    $clientLabel .= ' (' . $dirigeant . ')';
                                }
                            ?>
                                <tr>
                                    <td data-label="Date ouverture"><span class="sav-cell-date"><?= h($sav['date_ouverture'] ?? '—') ?></span></td>
                                    <td data-label="Référence"><span class="sav-cell-ref"><?= h($sav['reference'] ?? '—') ?></span></td>
                                    <td data-label="Client"><span class="sav-cell-client"><?= h($clientLabel) ?></span></td>
                                    <td data-label="Description"><span class="sav-cell-desc"><?= h(mb_substr($sav['description'] ?? '', 0, 60)) ?><?= mb_strlen($sav['description'] ?? '') > 60 ? '…' : '' ?></span></td>
                                    <td data-label="Type panne"><span class="sav-cell-type"><?= h($sav['type_panne'] ?? '—') ?></span></td>
                                    <td data-label="Date fermeture"><span class="sav-cell-date"><?= h($sav['date_fermeture'] ?? '—') ?></span></td>
                                    <td data-label="Statut">
                                        <span class="badge sav-badge-statut statut-<?= str_replace('_', '-', $sav['statut'] ?? '') ?>">
                                            <?= h($sav['statut'] ?? '') ?>
                                        </span>
                                    </td>
                                    <td data-label="Technicien"><span class="sav-cell-technicien"><?= $sav['technicien_nom'] ? h(($sav['technicien_prenom'] ?? '') . ' ' . $sav['technicien_nom']) : '—' ?></span></td>
                                    <td data-label="Actions" class="sav-actions">
                                        <a href="/public/sav.php?ref=<?= urlencode($sav['reference'] ?? '') ?>" class="btn btn-primary btn-sm">Voir</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Section Paiements -->
    <section id="paiements" class="payments-panel">
        <h2 class="panel-title">Paiements</h2>
        <p class="panel-subtitle">Liste des paiements</p>
        <div class="table-responsive">
            <table class="payments-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Facture</th>
                        <th>Montant</th>
                        <th>Mode</th>
                        <th>Statut</th>
                        <th>Référence</th>
                        <th>Justificatif</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($paiementsList)): ?>
                        <tr>
                            <td colspan="9" class="aucun">Aucun paiement trouvé.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($paiementsList as $paiement): ?>
                            <tr>
                                <td><?= h($paiement['date_paiement'] ?? '') ?></td>
                                <td><?= h($paiement['client_nom'] ?? 'N/A') ?></td>
                                <td><?= h($paiement['facture_numero'] ?? '') ?></td>
                                <td><?= number_format((float)($paiement['montant'] ?? 0), 2, ',', ' ') ?> €</td>
                                <td><?= h($paiement['mode_paiement'] ?? '') ?></td>
                                <td>
                                    <span class="badge <?= $paiement['statut'] === 'valide' ? 'success' : ($paiement['statut'] === 'en_attente' ? 'warning' : '') ?>">
                                        <?= h($paiement['statut'] ?? '') ?>
                                    </span>
                                </td>
                                <td><?= h($paiement['reference'] ?? '') ?></td>
                                <td>
                                    <?php if (!empty($paiement['recu_path'])): ?>
                                        <a href="<?= h($paiement['recu_path']) ?>" target="_blank" class="btn-justificatif">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                            Voir
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="actions">
                                    <a href="/public/paiements.php?id=<?= (int)($paiement['id'] ?? 0) ?>" class="btn btn-primary btn-sm">Voir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Section Factures -->
    <section id="factures" class="factures-panel">
        <h2 class="panel-title">Factures</h2>
        <p class="panel-subtitle">Liste des factures</p>
        <div class="table-responsive">
            <table class="factures-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Numéro</th>
                        <th>Client</th>
                        <th>Type</th>
                        <th>Montant HT</th>
                        <th>TVA</th>
                        <th>Total TTC</th>
                        <th>Statut</th>
                        <th>PDF</th>
                        <th>Méthode paiement</th>
                        <th>Justificatif</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($facturesList)): ?>
                        <tr>
                            <td colspan="10" class="aucun">Aucune facture trouvée.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($facturesList as $facture): ?>
                            <tr>
                                <td><?= h($facture['date_facture'] ?? '') ?></td>
                                <td><?= h($facture['numero'] ?? '') ?></td>
                                <td><?= h($facture['client_nom'] ?? 'N/A') ?></td>
                                <td><?= h($facture['type'] ?? '') ?></td>
                                <td><?= number_format((float)($facture['montant_ht'] ?? 0), 2, ',', ' ') ?> €</td>
                                <td><?= number_format((float)($facture['tva'] ?? 0), 2, ',', ' ') ?> €</td>
                                <td><?= number_format((float)($facture['montant_ttc'] ?? 0), 2, ',', ' ') ?> €</td>
                                <td>
                                    <span class="badge statut-<?= str_replace('_', '-', $facture['statut'] ?? '') ?>">
                                        <?= h($facture['statut'] ?? '') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($facture['pdf_path'])): ?>
                                        <a href="<?= h($facture['pdf_path']) ?>" target="_blank" class="btn-pdf-facture">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            PDF
                                        </a>
                                    <?php else: ?>
                                        <a href="/API/generate_facture_pdf.php?id=<?= (int)($facture['id'] ?? 0) ?>" target="_blank" class="btn-pdf-facture">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                            </svg>
                                            Générer
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td><span class="text-muted">—</span></td>
                                <td><span class="text-muted">—</span></td>
                                <td class="actions">
                                    <a href="/public/paiements.php?facture=<?= (int)($facture['id'] ?? 0) ?>" class="btn btn-primary btn-sm">Voir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
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
                html += '<a class="btn btn-primary" href="/public/profil.php?edit=' + u.id + '&perm_user=' + u.id + '#permissionsPanel" aria-label="Modifier l\'utilisateur ' + escapeHtml(fullName.trim()) + '">Modifier</a>';
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

/* Basculer entre les panneaux "Créer un utilisateur" et "Utilisateurs" */
(function() {
    const toggleButtons = document.querySelectorAll('.panel-toggle-btn');
    const createPanel = document.getElementById('createUserPanel');
    const usersPanel = document.getElementById('usersPanel');

    if (!toggleButtons.length || !usersPanel) return;

    function showPanel(targetId) {
        const panels = [createPanel, usersPanel].filter(Boolean);
        panels.forEach(function(panel) {
            if (panel.id === targetId) {
                panel.classList.remove('is-hidden');
            } else {
                panel.classList.add('is-hidden');
            }
        });

        toggleButtons.forEach(function(btn) {
            if (btn.dataset.target === targetId) {
                btn.classList.add('is-active');
            } else {
                btn.classList.remove('is-active');
            }
        });
    }

    toggleButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const target = this.dataset.target;
            if (target) {
                showPanel(target);
            }
        });
    });

    // État initial : si on a le panneau de création, on affiche "Utilisateurs" par défaut
    if (createPanel) {
        showPanel('usersPanel');
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

/* Fonction pour le scroll smooth vers les sections */
function scrollToSection(event, sectionId) {
    event.preventDefault();
    
    // Cacher toutes les sections d'abord
    const allSections = document.querySelectorAll('.sav-panel, .payments-panel, .factures-panel');
    allSections.forEach(function(section) {
        section.classList.remove('active');
    });
    
    // Afficher la section demandée
    const section = document.getElementById(sectionId);
    if (section) {
        // Ajouter la classe active pour afficher la section
        section.classList.add('active');
        
        // Attendre un peu pour que l'affichage se fasse avant de scroller
        setTimeout(function() {
            const headerOffset = 100; // Offset pour le header fixe si présent
            const elementPosition = section.getBoundingClientRect().top;
            const offsetPosition = elementPosition + window.pageYOffset - headerOffset;

            window.scrollTo({
                top: offsetPosition,
                behavior: 'smooth'
            });
            
            // Ajouter un highlight temporaire
            section.style.transition = 'box-shadow 0.3s ease';
            section.style.boxShadow = '0 0 20px rgba(59, 130, 246, 0.5)';
            setTimeout(function() {
                section.style.boxShadow = '';
            }, 2000);
        }, 50);
    }
}


</script>

</body>
</html>
