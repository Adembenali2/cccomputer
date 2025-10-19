<?php
// /public/profil.php (VERSION FINALE COMPLÈTE ET CORRIGÉE)

// ÉTAPE 1 : SÉCURITÉ D'ABORD
require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/db.php'; // Doit fournir $pdo (PDO connecté)


// ====== Lecture des filtres GET ======
$searchUser = trim($_GET['user_search'] ?? '');
$searchDate = trim($_GET['date_search'] ?? '');

// Validation simple de la date (YYYY-MM-DD)
if ($searchDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $searchDate)) {
    $searchDate = '';
}

// ====== Construction de la requête ======
$params = [];
$where  = [];

if ($searchUser !== '') {
    // LIKE sur "Nom Prénom" (prévoir collation pour la casse/accents côté BDD)
    $where[] = "CONCAT(u.nom, ' ', u.prenom) LIKE :search_user";
    $params[':search_user'] = '%' . $searchUser . '%';
}

if ($searchDate !== '') {
    // Utiliser une plage pour préserver les index de h.date_action
    $where[] = "h.date_action >= :dstart AND h.date_action < :dend";
    $params[':dstart'] = $searchDate . ' 00:00:00';
    $params[':dend']   = date('Y-m-d', strtotime($searchDate . ' +1 day')) . ' 00:00:00';
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
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY h.date_action DESC LIMIT 200"; // ajuste la limite si besoin

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$historique = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ====== Helper d’échappement ======
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
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
    </header>

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
                <?php if (empty($historique)): ?>
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
        <?php if (empty($historique)): ?>
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
