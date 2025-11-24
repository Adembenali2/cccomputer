<?php
// /public/profil.php (VERSION CORRIGÉE + icône détails import)

// ÉTAPE 1 : SÉCURITÉ D'ABORD
require_once __DIR__ . '/../includes/auth_role.php';        // démarre la session via auth.php
authorize_roles(['Admin', 'Dirigeant', 'Technicien', 'Livreur']);           // Utilise les valeurs exactes de la base de données (ENUM)
require_once __DIR__ . '/../includes/db.php';               // $pdo (PDO connecté)
require_once __DIR__ . '/../includes/historique.php';

// ========================================================================
// CONSTANTES & HELPERS
// ========================================================================
const USERS_LIMIT          = 300;
const SEARCH_MAX_LENGTH    = 120;
const TELEPHONE_PATTERN    = '/^[0-9+\-.\s]{6,}$/';
const IMPORT_HISTORY_LIMIT = 20;

// Contexte utilisateur courant (pour protections)
$currentUser = [
    'id'    => (int)($_SESSION['user_id'] ?? 0),
    'Emploi'=> (string)($_SESSION['emploi'] ?? '')
];

// Vérifier si l'utilisateur est un livreur, technicien ou admin/dirigeant (pour restrictions d'affichage)
$isLivreur = ($currentUser['Emploi'] ?? '') === 'Livreur';
$isTechnicien = ($currentUser['Emploi'] ?? '') === 'Technicien';
$isAdminOrDirigeant = in_array($currentUser['Emploi'] ?? '', ['Admin', 'Dirigeant'], true);
// Technicien et Livreur ont des restrictions (ne peuvent modifier que leur propre profil)
$hasRestrictions = $isLivreur || $isTechnicien;

function sanitizeSearch(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = mb_substr(preg_replace('/\s+/', ' ', $value), 0, SEARCH_MAX_LENGTH);
    return $value;
}

function safeFetchAll(PDO $pdo, string $sql, array $params = [], string $context = 'query'): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (PDOException $e) {
        error_log("Erreur SQL ({$context}) : " . $e->getMessage());
        return [];
    }
}

function safeFetch(PDO $pdo, string $sql, array $params = [], string $context = 'query'): ?array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    } catch (PDOException $e) {
        error_log("Erreur SQL ({$context}) : " . $e->getMessage());
        return null;
    }
}

function validateTelephone(?string $tel): bool {
    if ($tel === null || $tel === '') {
        return true;
    }
    return (bool)preg_match(TELEPHONE_PATTERN, $tel);
}

function formatDateDisplay(?string $date, string $format = 'd/m/Y'): string {
    if (!$date) {
        return '—';
    }
    try {
        $dt = new DateTime($date);
        return $dt->format($format);
    } catch (Exception $e) {
        return $date;
    }
}

function logProfilAction(PDO $pdo, string $action, string $details): void {
    try {
        enregistrerAction($pdo, $_SESSION['user_id'] ?? null, $action, $details);
    } catch (Throwable $e) {
        error_log('profil.php log error: ' . $e->getMessage());
    }
}

// ========================================================================
// GESTION DES REQUÊTES POST (Formulaires) - Pattern PRG
// ========================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ------------------------------------------------------------------------
// Rôles autorisés dans le champ Emploi (ENUM de la base de données)
// On les récupère directement depuis la définition de la colonne ENUM
// pour être sûr d'avoir TOUS les rôles, même s'ils ne sont pas encore utilisés
// ------------------------------------------------------------------------
$DEFAULT_ROLES = ['Chargé relation clients', 'Livreur', 'Technicien', 'Secrétaire', 'Dirigeant', 'Admin'];

$ROLES = [];

// On récupère la définition de la colonne ENUM `Emploi`
$col = safeFetch($pdo, "SHOW COLUMNS FROM utilisateurs LIKE 'Emploi'", [], 'enum_emploi');

if ($col && isset($col['Type']) && preg_match("/^enum\((.*)\)$/i", $col['Type'], $m)) {
    // $m[1] contient la liste :  'Chargé relation clients','Livreur','Technicien','Secrétaire','Dirigeant','Admin'
    $enumValues = str_getcsv($m[1], ',', "'");
    foreach ($enumValues as $val) {
        $val = trim($val);
        if ($val !== '') {
            $ROLES[] = $val;
        }
    }
}

// fallback de sécurité si jamais on ne récupère rien
if (!$ROLES) {
    $ROLES = $DEFAULT_ROLES;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Erreur de sécurité. Veuillez réessayer."];
        header('Location: /public/profil.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        // Les livreurs et techniciens ne peuvent pas créer de nouveaux utilisateurs
        if ($action === 'create') {
            if ($hasRestrictions) {
                throw new RuntimeException('Vous n\'êtes pas autorisé à créer des utilisateurs.');
            }
            $email  = trim($_POST['Email'] ?? '');
            $pwd    = (string)($_POST['password'] ?? '');
            $nom    = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $emploi = trim($_POST['Emploi'] ?? '');
            $debut  = trim($_POST['date_debut'] ?? '');
            $tel    = trim($_POST['telephone'] ?? '');
            $statut = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Email invalide.");
            if (strlen($pwd) < 8) throw new RuntimeException("Mot de passe trop court (min. 8 caractères).");
            if ($nom === '' || $prenom === '') throw new RuntimeException("Nom et prénom sont requis.");
            if (!in_array($emploi, $ROLES, true)) throw new RuntimeException("Rôle (Emploi) invalide.");
            if ($debut === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) throw new RuntimeException("Date de début invalide.");
            if (!validateTelephone($tel)) throw new RuntimeException("Téléphone invalide.");

            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (Email, password, nom, prenom, telephone, Emploi, statut, date_debut)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$email, $hash, $nom, $prenom, $tel, $emploi, $statut, $debut]);
            $newId = (int)$pdo->lastInsertId();
            logProfilAction($pdo, 'utilisateur_cree', "Utilisateur #{$newId} créé ({$email}, rôle {$emploi})");

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur créé avec succès."];
        }
        elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');
            $before = safeFetch($pdo, "SELECT Email, nom, prenom, telephone, Emploi, statut, date_debut FROM utilisateurs WHERE id=?", [$id], 'profil_update_fetch');
            if (!$before) throw new RuntimeException('Utilisateur introuvable.');

            // Les livreurs et techniciens ne peuvent modifier que leur propre profil
            if ($hasRestrictions && $id !== $currentUser['id']) {
                throw new RuntimeException('Vous ne pouvez modifier que votre propre profil.');
            }
            
            // Empêche un utilisateur de changer son propre rôle
            if ($id === $currentUser['id'] && isset($_POST['Emploi']) && $_POST['Emploi'] !== $currentUser['Emploi']) {
                throw new RuntimeException('Vous ne pouvez pas modifier votre propre rôle.');
            }
            
            // Les livreurs et techniciens ne peuvent pas modifier certains champs sensibles (statut, Emploi) sauf pour leur propre profil avec restrictions
            if ($hasRestrictions && $id === $currentUser['id']) {
                // Un livreur/technicien peut modifier son profil mais pas son rôle ni son statut
                if (isset($_POST['Emploi']) && $_POST['Emploi'] !== $before['Emploi']) {
                    throw new RuntimeException('Vous ne pouvez pas modifier votre propre rôle.');
                }
                if (isset($_POST['statut']) && $_POST['statut'] !== $before['statut']) {
                    throw new RuntimeException('Vous ne pouvez pas modifier votre propre statut.');
                }
            }

            $email  = trim($_POST['Email'] ?? '');
            $nom    = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $tel    = trim($_POST['telephone'] ?? '');
            $emploi = trim($_POST['Emploi'] ?? '');
            $statut = ($_POST['statut'] ?? 'actif') === 'inactif' ? 'inactif' : 'actif';
            $debut  = trim($_POST['date_debut'] ?? '');

            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Email invalide.");
            if ($nom === '' || $prenom === '') throw new RuntimeException("Nom et prénom sont requis.");
            if (!in_array($emploi, $ROLES, true)) throw new RuntimeException("Rôle (Emploi) invalide.");
            if ($debut === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) throw new RuntimeException("Date de début invalide.");
            if (!validateTelephone($tel)) throw new RuntimeException("Téléphone invalide.");

            $stmt = $pdo->prepare("
                UPDATE utilisateurs
                   SET Email=?, nom=?, prenom=?, telephone=?, Emploi=?, statut=?, date_debut=?
                 WHERE id=?
            ");
            $stmt->execute([$email, $nom, $prenom, $tel, $emploi, $statut, $debut, $id]);
            $changes = [];
            $fieldMap = [
                'Email' => $email,
                'nom' => $nom,
                'prenom' => $prenom,
                'telephone' => $tel,
                'Emploi' => $emploi,
                'statut' => $statut,
                'date_debut' => $debut,
            ];
            foreach ($fieldMap as $field => $newValue) {
                $oldValue = $before[$field] ?? null;
                if ($oldValue !== $newValue) {
                    $changes[] = "{$field}: '{$oldValue}' → '{$newValue}'";
                }
            }
            $detail = $changes ? implode(', ', $changes) : 'Aucun changement détecté';
            logProfilAction($pdo, 'utilisateur_modifie', "Utilisateur #{$id} mis à jour. {$detail}");

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur mis à jour."];
        }
        elseif ($action === 'toggle') {
            // Les livreurs et techniciens ne peuvent pas activer/désactiver des comptes
            if ($hasRestrictions) {
                throw new RuntimeException('Vous n\'êtes pas autorisé à modifier le statut des utilisateurs.');
            }
            
            $id = (int)$_POST['id'];
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');
            // Empêche de se désactiver soi-même
            if ($id === $currentUser['id']) throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte.');

            $new = (($_POST['to'] ?? 'actif') === 'inactif') ? 'inactif' : 'actif';
            $stmt = $pdo->prepare("UPDATE utilisateurs SET statut=? WHERE id=?");
            $stmt->execute([$new, $id]);
            logProfilAction($pdo, 'utilisateur_statut', "Statut utilisateur #{$id} changé en {$new}");

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Statut modifié en {$new}."];
        }
        elseif ($action === 'resetpwd') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');

            // Les livreurs ne peuvent réinitialiser que leur propre mot de passe
            if ($isLivreur && $id !== $currentUser['id']) {
                throw new RuntimeException('Vous ne pouvez réinitialiser que votre propre mot de passe.');
            }

            $newpwd = (string)($_POST['new_password'] ?? '');
            if (strlen($newpwd) < 8) throw new RuntimeException('Mot de passe trop court (min. 8).');

            $hash = password_hash($newpwd, PASSWORD_BCRYPT, ['cost' => 10]);
            $stmt = $pdo->prepare("UPDATE utilisateurs SET password=? WHERE id=?");
            $stmt->execute([$hash, $id]);
            logProfilAction($pdo, 'utilisateur_pwd_reset', "Mot de passe utilisateur #{$id} réinitialisé");

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Mot de passe réinitialisé."];
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => $e->getMessage()];
    }

    header('Location: /public/profil.php');
    exit;
}

// ========================================================================
// PRÉPARATION DE L'AFFICHAGE (Requêtes GET)
// ========================================================================
// La fonction h() est définie dans includes/helpers.php
$CSRF = $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

$search = sanitizeSearch($_GET['q'] ?? '');
$status = $_GET['statut'] ?? '';
$role   = $_GET['role'] ?? '';

$params = [];
$where  = [];

if ($search !== '') {
    $tokens = preg_split('/\s+/', $search);
    $tokenIndex = 0;
    $searchConditions = [];
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        $key = ':q' . $tokenIndex++;
        $searchConditions[] = "(Email LIKE {$key} OR nom LIKE {$key} OR prenom LIKE {$key} OR telephone LIKE {$key})";
        $params[$key] = "%{$token}%";
    }
    if (!empty($searchConditions)) {
        $where[] = '(' . implode(' AND ', $searchConditions) . ')';
    }
}

if ($status !== '' && in_array($status, ['actif', 'inactif'], true)) {
    $where[] = "statut = :statut";
    $params[':statut'] = $status;
}
if ($role !== '' && in_array($role, $ROLES, true)) {
    $where[] = "Emploi = :role";
    $params[':role'] = $role;
}

$sql = "SELECT id, Email, nom, prenom, telephone, Emploi, statut, date_debut, date_creation, date_modification
        FROM utilisateurs";
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY nom ASC, prenom ASC LIMIT ' . USERS_LIMIT;

$users = safeFetchAll($pdo, $sql, $params, 'utilisateurs_list');

$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = $editId > 0 ? safeFetch($pdo, "SELECT id, Email, nom, prenom, telephone, Emploi, statut, date_debut, date_creation, date_modification FROM utilisateurs WHERE id=?", [$editId], 'utilisateur_edit') : null;

// ——— NOUVEAU : on récupère les 20 derniers imports pour l’icône ———
$imports = safeFetchAll(
    $pdo,
    "SELECT id, ran_at, imported, skipped, ok, msg FROM import_run ORDER BY id DESC LIMIT " . IMPORT_HISTORY_LIMIT,
    [],
    'imports_history'
);

$totalUsers     = count($users);
$activeUsers    = 0;
$roleBreakdown  = [];
$latestCreation = null;

foreach ($users as $user) {
    if (($user['statut'] ?? '') === 'actif') {
        $activeUsers++;
    }
    $roleName = $user['Emploi'] ?? '—';
    $roleBreakdown[$roleName] = ($roleBreakdown[$roleName] ?? 0) + 1;

    $createdAt = $user['date_creation'] ?? null;
    if ($createdAt) {
        if ($latestCreation === null || strtotime($createdAt) > strtotime($latestCreation)) {
            $latestCreation = $createdAt;
        }
    }
}
$inactiveUsers  = max($totalUsers - $activeUsers, 0);
arsort($roleBreakdown);
$topRoles = array_slice($roleBreakdown, 0, 3, true);
$filtersActive = ($search !== '' || ($status !== '' && in_array($status, ['actif','inactif'], true)) || ($role !== '' && in_array($role, $ROLES, true)));

// utilitaire pour décoder proprement msg JSON
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
    <!-- chemins absolus -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/profil.css">
    <style>
        /* ——— Icône import : discrète et stylée ——— */
        .page-header { position: relative; }
        .import-mini {
            position:absolute; right:0; top:0;
            display:flex; align-items:center; gap:8px;
        }
        .import-mini-btn{
            display:inline-flex; align-items:center; justify-content:center;
            width:32px; height:32px; border-radius:999px;
            background:#fff; border:1px solid #e5e7eb; cursor:pointer;
            box-shadow:0 4px 10px rgba(0,0,0,.06);
        }
        .import-mini-btn:hover{ transform:translateY(-1px); box-shadow:0 8px 18px rgba(0,0,0,.08); }
        .import-mini-btn.ok{ color:#16a34a; }
        .import-mini-btn.ko{ color:#dc2626; }
        .import-mini-btn.run{ color:#4338ca; }

        .import-drop{
            position:absolute; right:0; top:40px; z-index:30;
            width:min(520px, 92vw);
            background:#fff; border:1px solid #e5e7eb; border-radius:12px;
            box-shadow:0 16px 40px rgba(0,0,0,.12);
            padding:10px;
            display:none;
        }
        .import-drop.open{ display:block; }
        .import-drop h3{
            margin:4px 6px 10px; font-size:14px; font-weight:700; color:#111827;
        }
        .import-list{ max-height:320px; overflow:auto; padding-right:4px; }
        .imp-item{
            display:grid; grid-template-columns: 24px 1fr auto; gap:10px; align-items:center;
            padding:8px; border-radius:10px;
        }
        .imp-item + .imp-item{ border-top:1px solid #f3f4f6; }
        .imp-ico{
            width:22px; height:22px; border-radius:999px; display:flex; align-items:center; justify-content:center; font-weight:700;
        }
        .imp-ico.ok{ background:#dcfce7; color:#16a34a; }
        .imp-ico.ko{ background:#fee2e2; color:#dc2626; }
        .imp-ico.run{ background:#e0e7ff; color:#4338ca; }
        .imp-main{ min-width:0; }
        .imp-title{ font-size:13px; font-weight:600; color:#111827; }
        .imp-sub{ font-size:12px; color:#6b7280; }
        .imp-badges{ display:flex; gap:6px; }
        .badge-mini{
            font-size:11px; padding:2px 6px; border-radius:999px; background:#f3f4f6; color:#374151; border:1px solid #e5e7eb;
        }
        .files{
            margin-top:4px; font-size:12px; color:#374151; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
        }
        @media (max-width:640px){
            .import-drop{ right:6px; left:6px; width:auto; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-profil">
    <header class="page-header">
        <h1 class="page-title">Gestion des utilisateurs</h1>
        <p class="page-sub">Page réservée aux administrateurs (Admin), dirigeants, techniciens et livreurs pour créer, modifier et activer/désactiver des comptes.</p>

        <!-- ——— Icône Import (ouvre panneau des derniers imports) ——— -->
        <div class="import-mini" id="impMini">
            <?php
            // déterminer l'état de l'icône selon le dernier import
            $last = $imports[0] ?? null;
            $stateClass = 'run'; $glyph = '⏳'; $title = 'Imports';
            if ($last) {
                if ((int)$last['ok'] === 1) { $stateClass = 'ok'; $glyph = '✓'; $title = 'Dernier import OK'; }
                elseif ((int)$last['ok'] === 0) { $stateClass = 'ko'; $glyph = '!'; $title = 'Dernier import KO'; }
            }
            ?>
            <button class="import-mini-btn <?= h($stateClass) ?>" id="impBtn" type="button" aria-haspopup="true" aria-expanded="false" title="<?= h($title) ?>">
                <?= h($glyph) ?>
            </button>

            <div class="import-drop" id="impDrop" role="dialog" aria-label="Derniers imports">
                <h3>Derniers imports</h3>
                <div class="import-list">
                    <?php if (!$imports): ?>
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
                                if (is_array($files) && $files) {
                                    $filesStr = implode(', ', array_slice($files, 0, 5));
                                    if (count($files) > 5) $filesStr .= ' …';
                                }
                            ?>
                            <div class="imp-item">
                                <div class="imp-ico <?= h($icoCls) ?>"><?= h($icoTxt) ?></div>
                                <div class="imp-main">
                                    <div class="imp-title">
                                        <?= $ok===1 ? 'Import réussi' : ($ok===0 ? 'Import échoué' : 'Import en cours') ?>
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
        <!-- ——— fin icône import ——— -->
    </header>

    <section class="profil-meta">
        <div class="meta-card">
            <span class="meta-label">Utilisateurs</span>
            <strong class="meta-value"><?= h((string)$totalUsers) ?></strong>
            <?php if ($totalUsers >= USERS_LIMIT): ?>
                <span class="meta-chip" title="Limite d’affichage atteinte">+<?= USERS_LIMIT ?> affichés</span>
            <?php endif; ?>
        </div>
        <div class="meta-card">
            <span class="meta-label">Actifs</span>
            <strong class="meta-value success"><?= h((string)$activeUsers) ?></strong>
            <span class="meta-sub">Inactifs : <?= h((string)$inactiveUsers) ?></span>
        </div>
        <div class="meta-card">
            <span class="meta-label">Dernière création</span>
            <strong class="meta-value"><?= h(formatDateDisplay($latestCreation)) ?></strong>
        </div>
        <div class="meta-card meta-roles">
            <span class="meta-label">Top rôles</span>
            <div class="meta-pills">
                <?php if ($topRoles): ?>
                    <?php foreach ($topRoles as $roleName => $count): ?>
                        <span class="pill role-pill">
                            <?= h($roleName) ?>
                            <span class="pill-count"><?= h((string)$count) ?></span>
                        </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="meta-sub">Aucun rôle disponible</span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($filtersActive): ?>
        <div class="active-filters" role="status" aria-live="polite">
            <span class="badge">Filtres actifs</span>
            <?php if ($search !== ''): ?>
                <span class="pill">Recherche : <?= h($search) ?></span>
            <?php endif; ?>
            <?php if ($role !== ''): ?>
                <span class="pill">Rôle : <?= h($role) ?></span>
            <?php endif; ?>
            <?php if ($status !== ''): ?>
                <span class="pill">Statut : <?= h($status) ?></span>
            <?php endif; ?>
            <a class="pill pill-clear" href="/public/profil.php" aria-label="Réinitialiser les filtres">Réinitialiser</a>
        </div>
    <?php endif; ?>

    <?php if ($flash && isset($flash['type'])): ?>
        <div class="flash <?= h($flash['type']) ?>"><?= h($flash['msg'] ?? '') ?></div>
    <?php endif; ?>

    <form class="filtre-form" method="get" action="/public/profil.php" novalidate>
        <div class="filter-bar">
            <div class="filter-field grow">
                <input class="filter-input" type="search" id="q" name="q" value="<?= h($search) ?>" placeholder="Rechercher (email, nom, téléphone…)" />
            </div>
            <div class="filter-field">
                <select class="filter-select" id="role" name="role" aria-label="Rôle">
                    <option value="">Rôle : Tous</option>
                    <?php foreach ($ROLES as $r): ?>
                        <option value="<?= h($r) ?>" <?= $role===$r?'selected':'' ?>><?= h($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-field">
                <select class="filter-select" id="statut" name="statut" aria-label="Statut">
                    <option value="">Statut : Tous</option>
                    <option value="actif"   <?= $status==='actif'  ?'selected':'' ?>>Actif</option>
                    <option value="inactif" <?= $status==='inactif'?'selected':'' ?>>Inactif</option>
                </select>
            </div>
            <div class="filter-field">
                <button class="filter-submit" type="submit">Filtrer</button>
            </div>
        </div>
    </form>

    <section class="grid-2cols">
        <?php if ($isAdminOrDirigeant): ?>
        <div class="panel">
            <h2 class="panel-title">Créer un utilisateur</h2>
            <form class="standard-form" method="post" action="/public/profil.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="create">
                <label>Email<input type="email" name="Email" required></label>
                <label>Mot de passe (min. 8)<input type="password" name="password" minlength="8" required></label>
                <div class="grid-2">
                    <label>Nom<input type="text" name="nom" required></label>
                    <label>Prénom<input type="text" name="prenom" required></label>
                </div>
                <label>Téléphone<input type="tel" name="telephone" pattern="[0-9+\-.\s]{6,}" inputmode="tel"></label>
                <div class="grid-2">
                    <label>Rôle (Emploi)
                        <select name="Emploi" required>
                            <?php foreach ($ROLES as $r): ?><option value="<?= h($r) ?>"><?= h($r) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Statut
                        <select name="statut">
                            <option value="actif">Actif</option>
                            <option value="inactif">Inactif</option>
                        </select>
                    </label>
                </div>
                <label>Date de début<input type="date" name="date_debut" required></label>
                <button class="fiche-action-btn" type="submit">Créer</button>
            </form>
        </div>
        <?php endif; ?>

        <div class="panel <?= !$isAdminOrDirigeant ? 'grid-full' : '' ?>">
            <h2 class="panel-title">Utilisateurs (<?= count($users) ?>)</h2>
            <div class="table-responsive">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>#</th><th>Nom</th><th>Email</th><th>Téléphone</th>
                            <th>Rôle</th><th>Statut</th><th>Début</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td colspan="8" class="aucun">Aucun utilisateur.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $u): ?>
                            <tr class="<?= ($editId>0 && $editId===(int)$u['id']) ? 'is-editing' : '' ?>">
                                <td data-label="#"><?= (int)$u['id'] ?></td>
                                <td data-label="Nom"><?= h($u['nom'] . ' ' . $u['prenom']) ?></td>
                                <td data-label="Email"><?= h($u['Email']) ?></td>
                                <td data-label="Téléphone"><?= h($u['telephone'] ?? '') ?></td>
                                <td data-label="Rôle"><span class="badge role"><?= h($u['Emploi']) ?></span></td>
                                <td data-label="Statut"><span class="badge <?= $u['statut']==='actif'?'success':'muted' ?>"><?= h($u['statut']) ?></span></td>
                                <td data-label="Début"><?= h($u['date_debut']) ?></td>
                                <td data-label="Actions" class="actions">
                                    <?php if ($hasRestrictions && (int)$u['id'] !== $currentUser['id']): ?>
                                        <span class="text-muted">Non autorisé</span>
                                    <?php else: ?>
                                        <a class="btn btn-primary" href="/public/profil.php?edit=<?= (int)$u['id'] ?>">Modifier</a>
                                        <?php if ($isAdminOrDirigeant): ?>
                                        <form method="post" action="/public/profil.php" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <input type="hidden" name="to" value="<?= $u['statut']==='actif'?'inactif':'actif' ?>">
                                            <button type="submit" class="btn <?= $u['statut']==='actif'?'btn-danger':'btn-success' ?>">
                                                <?= $u['statut']==='actif'?'Désactiver':'Activer' ?>
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
            <form class="standard-form" method="post" action="/public/profil.php?edit=<?= (int)$editing['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">

                <label>Email<input type="email" name="Email" value="<?= h($editing['Email']) ?>" required></label>
                <div class="grid-2">
                    <label>Nom<input type="text" name="nom" value="<?= h($editing['nom']) ?>" required></label>
                    <label>Prénom<input type="text" name="prenom" value="<?= h($editing['prenom']) ?>" required></label>
                </div>
                <label>Téléphone<input type="tel" name="telephone" value="<?= h($editing['telephone'] ?? '') ?>" pattern="[0-9+\-.\s]{6,}" inputmode="tel"></label>
                <?php if ($isAdminOrDirigeant): ?>
                <div class="grid-2">
                    <label>Rôle (Emploi)
                        <select name="Emploi" required <?= ($hasRestrictions && (int)$editing['id'] === $currentUser['id']) ? 'disabled' : '' ?>>
                            <?php foreach ($ROLES as $r): ?>
                                <option value="<?= h($r) ?>" <?= ($editing['Emploi'] ?? '')===$r?'selected':'' ?>><?= h($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($hasRestrictions && (int)$editing['id'] === $currentUser['id']): ?>
                            <input type="hidden" name="Emploi" value="<?= h($editing['Emploi'] ?? '') ?>">
                        <?php endif; ?>
                    </label>
                    <label>Statut
                        <select name="statut" <?= ($hasRestrictions && (int)$editing['id'] === $currentUser['id']) ? 'disabled' : '' ?>>
                            <option value="actif"   <?= ($editing['statut'] ?? '')==='actif'?'selected':'' ?>>Actif</option>
                            <option value="inactif" <?= ($editing['statut'] ?? '')==='inactif'?'selected':'' ?>>Inactif</option>
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
                <label>Date de début<input type="date" name="date_debut" value="<?= h($editing['date_debut']) ?>" required></label>
                <button class="fiche-action-btn" type="submit">Enregistrer</button>
                <a class="link-reset" href="/public/profil.php">Fermer</a>
            </form>

            <hr class="sep">

            <form class="standard-form danger" method="post" action="/public/profil.php?edit=<?= (int)$editing['id'] ?>" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                <input type="hidden" name="action" value="resetpwd">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <h3>Réinitialiser le mot de passe</h3>
                <label>Nouveau mot de passe (min. 8)<input type="password" name="new_password" minlength="8" required></label>
                <button class="btn-danger" type="submit">Réinitialiser</button>
            </form>
        </section>
    <?php endif; ?>
</main>

<?php if ($editing): ?>
<script>
    // Fait défiler jusqu'au panneau d'édition si présent
    (function(){
        var p = document.getElementById('editPanel');
        if (p && 'scrollIntoView' in p) {
            p.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    })();
</script>
<?php endif; ?>

<script>
/* ——— JS léger pour l’icône import ——— */
(function(){
    const btn  = document.getElementById('impBtn');
    const drop = document.getElementById('impDrop');

    if (!btn || !drop) return;

    btn.addEventListener('click', function(e){
        e.preventDefault();
        const isOpen = drop.classList.contains('open');
        drop.classList.toggle('open', !isOpen);
        btn.setAttribute('aria-expanded', String(!isOpen));
    });

    // fermer au clic hors
    document.addEventListener('click', function(e){
        if (!drop.classList.contains('open')) return;
        if (e.target === btn || btn.contains(e.target) || drop.contains(e.target)) return;
        drop.classList.remove('open');
        btn.setAttribute('aria-expanded', 'false');
    });

    // fermer via Échap
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape' && drop.classList.contains('open')) {
            drop.classList.remove('open');
            btn.setAttribute('aria-expanded', 'false');
        }
    });
})();
</script>

</body>
</html>
