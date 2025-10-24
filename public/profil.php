<?php
// /public/profil.php (VERSION CORRIGÉE + icône détails import)

// ÉTAPE 1 : SÉCURITÉ D'ABORD
require_once __DIR__ . '/../includes/auth_role.php';        // démarre la session via auth.php
authorize_roles(['Administrateur', 'Dirigeant']);           // rôles cohérents avec le reste
require_once __DIR__ . '/../includes/db.php';               // $pdo (PDO connecté)

// Contexte utilisateur courant (pour protections)
$currentUser = [
    'id'    => (int)($_SESSION['user_id'] ?? 0),
    'Emploi'=> (string)($_SESSION['emploi'] ?? '')
];

// ========================================================================
// GESTION DES REQUÊTES POST (Formulaires) - Pattern PRG
// ========================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Rôles autorisés dans le champ Emploi
$ROLES = ['Chargé relation clients', 'Livreur', 'Technicien', 'Secrétaire', 'Dirigeant', 'Administrateur'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => "Erreur de sécurité. Veuillez réessayer."];
        header('Location: /public/profil.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create') {
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

            $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 10]);
            $stmt = $pdo->prepare("
                INSERT INTO utilisateurs (Email, password, nom, prenom, telephone, Emploi, statut, date_debut)
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$email, $hash, $nom, $prenom, $tel, $emploi, $statut, $debut]);

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur créé avec succès."];
        }
        elseif ($action === 'update') {
            $id = (int)$_POST['id'];
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');

            // Empêche un admin/dirigeant de changer son propre rôle
            if ($id === $currentUser['id'] && isset($_POST['Emploi']) && $_POST['Emploi'] !== $currentUser['Emploi']) {
                throw new RuntimeException('Vous ne pouvez pas modifier votre propre rôle.');
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

            $stmt = $pdo->prepare("
                UPDATE utilisateurs
                   SET Email=?, nom=?, prenom=?, telephone=?, Emploi=?, statut=?, date_debut=?
                 WHERE id=?
            ");
            $stmt->execute([$email, $nom, $prenom, $tel, $emploi, $statut, $debut, $id]);

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Utilisateur mis à jour."];
        }
        elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');
            // Empêche de se désactiver soi-même
            if ($id === $currentUser['id']) throw new RuntimeException('Vous ne pouvez pas désactiver votre propre compte.');

            $new = (($_POST['to'] ?? 'actif') === 'inactif') ? 'inactif' : 'actif';
            $stmt = $pdo->prepare("UPDATE utilisateurs SET statut=? WHERE id=?");
            $stmt->execute([$new, $id]);

            $_SESSION['flash'] = ['type' => 'success', 'msg' => "Statut modifié en {$new}."];
        }
        elseif ($action === 'resetpwd') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identifiant manquant.');

            $newpwd = (string)($_POST['new_password'] ?? '');
            if (strlen($newpwd) < 8) throw new RuntimeException('Mot de passe trop court (min. 8).');

            $hash = password_hash($newpwd, PASSWORD_BCRYPT, ['cost' => 10]);
            $stmt = $pdo->prepare("UPDATE utilisateurs SET password=? WHERE id=?");
            $stmt->execute([$hash, $id]);

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
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
$CSRF = $_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

$search = trim($_GET['q'] ?? '');
$status = $_GET['statut'] ?? '';
$role   = $_GET['role'] ?? '';

$params = [];
$where  = [];

if ($search !== '') {
    $where[] = "(Email LIKE :q OR nom LIKE :q OR prenom LIKE :q OR telephone LIKE :q)";
    $params[':q'] = "%{$search}%";
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
$sql .= ' ORDER BY nom ASC, prenom ASC LIMIT 300';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$editId  = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editing = null;
if ($editId > 0) {
    $s = $pdo->prepare("SELECT * FROM utilisateurs WHERE id=?");
    $s->execute([$editId]);
    $editing = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ——— NOUVEAU : on récupère les 20 derniers imports pour l’icône ———
$imports = [];
try {
    $q = $pdo->query("SELECT id, ran_at, imported, skipped, ok, msg FROM import_run ORDER BY id DESC LIMIT 20");
    $imports = $q->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $imports = [];
}

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
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-profil">
    <header class="page-header">
        <h1 class="page-title">Gestion des utilisateurs</h1>
        <p class="page-sub">Page réservée aux administrateurs/dirigeants pour créer, modifier et activer/désactiver des comptes.</p>

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
            <button
                class="import-mini-btn <?= h($stateClass) ?>"
                id="impBtn"
                type="button"
                aria-haspopup="true"
                aria-expanded="false"
                aria-controls="impDrop"
                title="<?= h($title) ?>"
            >
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
                <label>Téléphone<input type="tel" name="telephone" pattern="[0-9+\-.\s]{6,}"></label>
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

        <div class="panel">
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
                                    <a class="btn btn-primary" href="/public/profil.php?edit=<?= (int)$u['id'] ?>">Modifier</a>
                                    <form method="post" action="/public/profil.php" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                                        <input type="hidden" name="action" value="toggle">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <input type="hidden" name="to" value="<?= $u['statut']==='actif'?'inactif':'actif' ?>">
                                        <button type="submit" class="btn <?= $u['statut']==='actif'?'btn-danger':'btn-success' ?>">
                                            <?= $u['statut']==='actif'?'Désactiver':'Activer' ?>
                                        </button>
                                    </form>
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
                <label>Téléphone<input type="tel" name="telephone" value="<?= h($editing['telephone'] ?? '') ?>" pattern="[0-9+\-.\s]{6,}"></label>
                <div class="grid-2">
                    <label>Rôle (Emploi)
                        <select name="Emploi" required>
                            <?php foreach ($ROLES as $r): ?>
                                <option value="<?= h($r) ?>" <?= ($editing['Emploi'] ?? '')===$r?'selected':'' ?>><?= h($r) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Statut
                        <select name="statut">
                            <option value="actif"   <?= ($editing['statut'] ?? '')==='actif'?'selected':'' ?>>Actif</option>
                            <option value="inactif" <?= ($editing['statut'] ?? '')==='inactif'?'selected':'' ?>>Inactif</option>
                        </select>
                    </label>
                </div>
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
