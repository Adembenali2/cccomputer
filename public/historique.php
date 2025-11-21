<?php
// /public/historique.php

require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']); // Utilise les valeurs exactes de la base de données
require_once __DIR__ . '/../includes/db.php'; // fournit $pdo (PDO connecté)

const HISTORIQUE_PAGE_LIMIT = 200;
const USER_SEARCH_MAX_CHARS = 80;

// ====== Lecture et validation des filtres GET ======
$rawUser = $_GET['user_search'] ?? '';
$searchUser = trim(is_string($rawUser) ? $rawUser : '');
if ($searchUser !== '') {
    $searchUser = preg_replace('/\s+/', ' ', $searchUser);
    $searchUser = mb_substr($searchUser, 0, USER_SEARCH_MAX_CHARS);
}

$rawDate = $_GET['date_search'] ?? '';
$searchDate = trim(is_string($rawDate) ? $rawDate : '');
$dateStart = $dateEnd = null;
if ($searchDate !== '') {
    $dt = DateTime::createFromFormat('Y-m-d', $searchDate);
    $errors = DateTime::getLastErrors();
    if ($dt && empty($errors['warning_count']) && empty($errors['error_count'])) {
        $dateStart = (clone $dt)->setTime(0, 0, 0);
        $dateEnd   = (clone $dt)->modify('+1 day')->setTime(0, 0, 0);
    } else {
        $searchDate = '';
    }
}

// ====== Construction de la requête ======
$params = [];
$where  = [];

if ($searchUser !== '') {
    $tokens = preg_split('/\s+/', $searchUser);
    $tokenIndex = 0;
    foreach ($tokens as $token) {
        if ($token === '') {
            continue;
        }
        $paramKey = ':search_user_' . $tokenIndex++;
        $where[] = "(u.nom LIKE {$paramKey} OR u.prenom LIKE {$paramKey})";
        $params[$paramKey] = '%' . $token . '%';
    }
}

if ($dateStart && $dateEnd) {
    $where[] = "h.date_action >= :dstart AND h.date_action < :dend";
    $params[':dstart'] = $dateStart->format('Y-m-d H:i:s');
    $params[':dend']   = $dateEnd->format('Y-m-d H:i:s');
}

$sql = "
    SELECT
        h.id,
        h.date_action,
        h.action,
        h.details,
        h.ip_address,
        u.nom,
        u.prenom
    FROM historique h
    LEFT JOIN utilisateurs u ON h.user_id = u.id
";

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY h.date_action DESC LIMIT ' . HISTORIQUE_PAGE_LIMIT;

$historique = [];
$dbError = null;

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $dbError = 'Impossible de charger l’historique pour le moment.';
    error_log('Erreur SQL (historique): ' . $e->getMessage());
}

// ====== Helper d’échappement ======
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(?string $dateTime): string
{
    if (!$dateTime) {
        return '—';
    }
    try {
        $dt = new DateTime($dateTime);
        return $dt->format('d/m/Y H:i');
    } catch (Exception $e) {
        return $dateTime;
    }
}

$historiqueCount = count($historique);
$isLimited = ($historiqueCount === HISTORIQUE_PAGE_LIMIT);

$uniqueUsers = [];
foreach ($historique as $row) {
    $fullname = trim(($row['nom'] ?? '') . ' ' . ($row['prenom'] ?? ''));
    if ($fullname !== '') {
        $uniqueUsers[$fullname] = true;
    }
}
$uniqueUsersCount = count($uniqueUsers);
$lastActivity = $historique[0]['date_action'] ?? null;
$firstActivity = $historiqueCount > 0 ? ($historique[$historiqueCount - 1]['date_action'] ?? null) : null;

$filtersActive = ($searchUser !== '' || $searchDate !== '');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Historique des Actions</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Tes feuilles de style existantes -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/historique.css">
</head>
<body>

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-historique">
    <header class="page-header">
        <h1 class="page-title">Historique des actions</h1>
        <p class="page-subtitle">
            Surveillez les opérations clés en temps réel. Les 200 dernières entrées sont affichées.
        </p>
    </header>

    <section class="history-meta <?= $filtersActive ? 'has-filter' : '' ?>">
        <div class="meta-card">
            <span class="meta-label">Entrées listées</span>
            <strong class="meta-value"><?= h((string)$historiqueCount) ?></strong>
            <?php if ($isLimited): ?>
                <span class="meta-chip" title="Seulement les dernières entrées sont affichées">Limite atteinte</span>
            <?php endif; ?>
        </div>
        <div class="meta-card">
            <span class="meta-label">Utilisateurs impliqués</span>
            <strong class="meta-value"><?= h((string)$uniqueUsersCount) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Dernière activité</span>
            <strong class="meta-value"><?= h(formatDateTime($lastActivity)) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Première activité</span>
            <strong class="meta-value"><?= h(formatDateTime($firstActivity)) ?></strong>
        </div>
    </section>

    <?php if ($filtersActive): ?>
        <div class="active-filters" role="status" aria-live="polite">
            <span class="badge">Filtres actifs</span>
            <?php if ($searchUser !== ''): ?>
                <span class="pill">Utilisateur : <?= h($searchUser) ?></span>
            <?php endif; ?>
            <?php if ($searchDate !== ''): ?>
                <span class="pill">Date : <?= h($searchDate) ?></span>
            <?php endif; ?>
            <a class="pill pill-clear" href="historique.php" aria-label="Réinitialiser les filtres">Réinitialiser</a>
        </div>
    <?php endif; ?>

    <!-- Formulaire de filtres (auto-submit, sans boutons) -->
    <form class="filtre-form" id="filterForm" method="get" action="historique.php" novalidate>
        <div>
            <label for="user_search">Filtrer par utilisateur</label>
            <input
                type="text"
                id="user_search"
                name="user_search"
                value="<?= h($searchUser) ?>"
                placeholder="Nom Prénom…"
                autocomplete="off"
                inputmode="search"
            >
        </div>
        <div>
            <label for="date_search">Filtrer par date</label>
            <input
                type="date"
                id="date_search"
                name="date_search"
                value="<?= h($searchDate) ?>"
            >
        </div>
        <!-- Pas de boutons : filtrage automatique via JS -->
    </form>

    <!-- Tableau desktop -->
    <div class="table-responsive">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date &amp; Heure</th>
                    <th>Utilisateur</th>
                    <th>Action</th>
                    <th>Détails</th>
                    <th>Adresse IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dbError !== null): ?>
                    <tr>
                        <td colspan="5" class="aucun"><?= h($dbError) ?></td>
                    </tr>
                <?php elseif (empty($historique)): ?>
                    <tr>
                        <td colspan="5" class="aucun">Aucun résultat trouvé pour les filtres.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historique as $entree): ?>
                        <?php
                            $fullname = trim(($entree['nom'] ?? '') . ' ' . ($entree['prenom'] ?? ''));
                            if ($fullname === '') { $fullname = '—'; }
                        ?>
                        <tr>
                            <td><?= h($entree['date_action']) ?></td>
                            <td><?= h($fullname) ?></td>
                            <td><?= h(str_replace('_', ' ', (string)$entree['action'])) ?></td>
                            <td><?= h($entree['details'] ?? '') ?></td>
                            <td><?= h($entree['ip_address'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Version “cartes” mobile -->
    <ul class="history-list">
        <?php if ($dbError !== null): ?>
            <li class="history-item">
                <div class="item-body">
                    <div class="aucun-resultat"><?= h($dbError) ?></div>
                </div>
            </li>
        <?php elseif (empty($historique)): ?>
            <li class="history-item">
                <div class="item-body">
                    <div class="aucun-resultat">Aucun résultat trouvé pour les filtres.</div>
                </div>
            </li>
        <?php else: ?>
            <?php foreach ($historique as $entree): ?>
                <?php
                    $fullname = trim(($entree['nom'] ?? '') . ' ' . ($entree['prenom'] ?? ''));
                    if ($fullname === '') { $fullname = '—'; }
                ?>
                <li class="history-item">
                    <div class="item-header">
                        <span class="item-title"><?= h($fullname) ?></span>
                        <span class="item-date"><?= h($entree['date_action']) ?></span>
                    </div>
                    <div class="item-body">
                        <div class="item-detail">
                            <span class="label">Action :</span>
                            <span class="value"><?= h(str_replace('_', ' ', (string)$entree['action'])) ?></span>
                        </div>
                        <?php if (!empty($entree['details'])): ?>
                        <div class="item-detail">
                            <span class="label">Détails :</span>
                            <span class="value"><?= h($entree['details']) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($entree['ip_address'])): ?>
                        <div class="item-detail">
                            <span class="label">IP :</span>
                            <span class="value"><?= h($entree['ip_address']) ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</main>

<!-- Auto-filtrage : date (immédiat) + utilisateur (debounce 400ms) -->
<script>
(function(){
  const form  = document.getElementById('filterForm');
  const user  = document.getElementById('user_search');
  const dateI = document.getElementById('date_search');

  // Soumission immédiate quand la date change
  dateI.addEventListener('change', () => form.submit());

  // Debounce pour l'input utilisateur
  let t;
  user.addEventListener('input', () => {
    clearTimeout(t);
    t = setTimeout(() => form.submit(), 400);
  });

  // Enter dans le champ user => submit direct
  user.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); form.submit(); }
  });
})();
</script>

</body>
</html>
