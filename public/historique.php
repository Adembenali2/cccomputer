<?php
/**
 * /public/historique.php
 * Page d'historique des actions - Version améliorée
 *
 * Affiche l'historique des actions utilisateurs avec filtres, pagination et modal détail.
 * Accès réservé aux rôles Admin et Dirigeant.
 */

// ====== Configuration & Sécurité ======
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('historique', ['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();

// Charger la config
$config = require __DIR__ . '/../config/app.php';
$perPage = (int)($config['limits']['historique_per_page'] ?? 1000);
$perPage = max(10, min(500, $perPage)); // Bornes 10-500

const USER_SEARCH_MAX_CHARS = 80;
const DEBOUNCE_DELAY_MS = 400;
const DEBUG_MODE = false;

// Mapping catégorie → patterns d'action (pour filtre SQL)
const CATEGORY_PATTERNS = [
    'Clients' => ['client%', 'photocopieur%'],
    'SAV' => ['sav%'],
    'Livraisons' => ['livraison%'],
    'Stock' => ['mouvement_stock%', 'stock%'],
    'Messagerie' => ['message%'],
    'Factures' => ['facture%'],
    'Paiements' => ['paiement%'],
    'Authentification' => ['connexion%', 'deconnexion%', 'login%'],
];

// Couleurs des badges par catégorie
const CATEGORY_COLORS = [
    'Clients' => 'badge-clients',
    'SAV' => 'badge-sav',
    'Livraisons' => 'badge-livraisons',
    'Stock' => 'badge-stock',
    'Messagerie' => 'badge-messagerie',
    'Factures' => 'badge-factures',
    'Paiements' => 'badge-paiements',
    'Authentification' => 'badge-auth',
    'Autre' => 'badge-other',
];

// ====== Helpers ======
function sanitizeUserSearch(string $input): string {
    $cleaned = trim($input);
    if ($cleaned === '') return '';
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    $cleaned = mb_substr($cleaned, 0, USER_SEARCH_MAX_CHARS);
    $cleaned = preg_replace('/[^\p{L}\p{N}\s\-\'\.]/u', '', $cleaned);
    return $cleaned;
}

function parseDateFilter(string $dateInput): ?array {
    $cleaned = trim($dateInput);
    if ($cleaned === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleaned)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $cleaned);
    if (!$dt) return null;
    $dateStart = (clone $dt)->setTime(0, 0, 0);
    $dateEnd = (clone $dt)->modify('+1 day')->setTime(0, 0, 0);
    return ['start' => $dateStart, 'end' => $dateEnd, 'display' => $cleaned];
}

function getCategoryFromAction(?string $action): string {
    if (!$action) return 'Autre';
    foreach (CATEGORY_PATTERNS as $cat => $patterns) {
        foreach ($patterns as $p) {
            $prefix = rtrim($p, '%');
            if ($prefix !== '' && stripos($action, $prefix) === 0) return $cat;
        }
    }
    return 'Autre';
}

function formatFullName(?string $nom, ?string $prenom): string {
    $fullname = trim(($nom ?? '') . ' ' . ($prenom ?? ''));
    return $fullname !== '' ? $fullname : '—';
}

function formatAction(?string $action): string {
    return $action ? str_replace('_', ' ', $action) : '—';
}

// ====== Validation des paramètres GET ======
$rawUser = $_GET['user_search'] ?? '';
$rawDateDebut = $_GET['date_debut'] ?? '';
$rawDateFin = $_GET['date_fin'] ?? '';
$rawCategory = $_GET['categorie'] ?? '';
$rawAction = $_GET['action_filter'] ?? '';
$rawPage = $_GET['page'] ?? '1';

$searchUser = sanitizeUserSearch(is_string($rawUser) ? $rawUser : '');
$dateDebutFilter = parseDateFilter(is_string($rawDateDebut) ? $rawDateDebut : '');
$dateFinFilter = parseDateFilter(is_string($rawDateFin) ? $rawDateFin : '');
$searchDateDebut = $dateDebutFilter ? $dateDebutFilter['display'] : '';
$searchDateFin = $dateFinFilter ? $dateFinFilter['display'] : '';

// Catégorie : whitelist
$allowedCategories = array_keys(CATEGORY_PATTERNS);
$searchCategory = in_array($rawCategory, $allowedCategories, true) ? $rawCategory : '';

// Action : whitelist (fetch distinct from DB)
$distinctActions = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT action FROM historique ORDER BY action");
    $distinctActions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('historique.php distinct actions: ' . $e->getMessage());
}
$searchAction = (is_string($rawAction) && in_array($rawAction, $distinctActions, true)) ? $rawAction : '';

// Page : validation
$page = max(1, (int)$rawPage);

// ====== Construction SQL ======
$params = [];
$whereConditions = [];

if ($searchUser !== '') {
    $tokens = preg_split('/\s+/', $searchUser);
    $userConditions = [];
    $tokenIndex = 0;
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') continue;
        $paramKeyNom = ':search_user_nom_' . $tokenIndex;
        $paramKeyPrenom = ':search_user_prenom_' . $tokenIndex;
        $tokenIndex++;
        $paramValue = '%' . $token . '%';
        $userConditions[] = "(u.nom LIKE " . $paramKeyNom . " OR u.prenom LIKE " . $paramKeyPrenom . ")";
        $params[$paramKeyNom] = $paramValue;
        $params[$paramKeyPrenom] = $paramValue;
    }
    if (!empty($userConditions)) {
        $whereConditions[] = '(' . implode(' AND ', $userConditions) . ')';
    }
}

// Plage de dates
if ($dateDebutFilter) {
    $whereConditions[] = "h.date_action >= :dstart";
    $params[':dstart'] = $dateDebutFilter['start']->format('Y-m-d H:i:s');
}
if ($dateFinFilter) {
    $whereConditions[] = "h.date_action < :dend";
    $params[':dend'] = $dateFinFilter['end']->format('Y-m-d H:i:s');
}

// Catégorie
if ($searchCategory !== '' && isset(CATEGORY_PATTERNS[$searchCategory])) {
    $catConditions = [];
    foreach (CATEGORY_PATTERNS[$searchCategory] as $i => $p) {
        $key = ':cat_' . $searchCategory . '_' . $i;
        $catConditions[] = "h.action LIKE " . $key;
        $params[$key] = $p;
    }
    $whereConditions[] = '(' . implode(' OR ', $catConditions) . ')';
}

// Action exacte
if ($searchAction !== '') {
    $whereConditions[] = "h.action = :action_filter";
    $params[':action_filter'] = $searchAction;
}

$sqlBase = "
    SELECT h.id, h.date_action, h.action, h.details, h.ip_address, u.nom, u.prenom
    FROM historique h
    LEFT JOIN utilisateurs u ON h.user_id = u.id
";
$sqlWhere = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
$sqlOrder = ' ORDER BY h.date_action DESC';

// Compte total pour pagination
$countSql = "SELECT COUNT(*) FROM historique h LEFT JOIN utilisateurs u ON h.user_id = u.id" . $sqlWhere;
$totalCount = 0;
try {
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $k => $v) {
        $countStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $totalCount = (int)$countStmt->fetchColumn();
} catch (PDOException $e) {
    error_log('historique.php count: ' . $e->getMessage());
}

$totalPages = max(1, (int)ceil($totalCount / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = $sqlBase . $sqlWhere . $sqlOrder . " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$historique = [];
$dbError = null;
$errorDetails = null;

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $dbError = 'Impossible de charger l\'historique pour le moment.';
    $errorDetails = ['message' => $e->getMessage()];
    error_log('Erreur SQL (historique): ' . $e->getMessage());
    $historique = [];
}

// Stats (sur la page courante pour "Entrées listées", total pour les autres)
$historiqueCount = count($historique);
$statsSql = "
    SELECT COUNT(DISTINCT h.user_id) as unique_users,
           MIN(h.date_action) as first_activity,
           MAX(h.date_action) as last_activity
    FROM historique h
    LEFT JOIN utilisateurs u ON h.user_id = u.id
" . $sqlWhere;

$uniqueUsersCount = 0;
$lastActivity = null;
$firstActivity = null;

try {
    $statsStmt = $pdo->prepare($statsSql);
    foreach ($params as $k => $v) {
        $statsStmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    $uniqueUsersCount = (int)($stats['unique_users'] ?? 0);
    $lastActivity = $stats['last_activity'] ?? null;
    $firstActivity = $stats['first_activity'] ?? null;
} catch (PDOException $e) {
    error_log('historique.php stats: ' . $e->getMessage());
    $uniqueUsers = [];
    foreach ($historique as $row) {
        $fn = trim(($row['nom'] ?? '') . ' ' . ($row['prenom'] ?? ''));
        if ($fn !== '') $uniqueUsers[$fn] = true;
    }
    $uniqueUsersCount = count($uniqueUsers);
    $lastActivity = $historique[0]['date_action'] ?? null;
    $firstActivity = $historiqueCount > 0 ? ($historique[$historiqueCount - 1]['date_action'] ?? null) : null;
}

$filtersActive = ($searchUser !== '' || $searchDateDebut !== '' || $searchDateFin !== '' || $searchCategory !== '' || $searchAction !== '');

// ====== Cache formatDetails ======
static $detailsCache = ['clients' => [], 'sav' => [], 'livraisons' => [], 'utilisateurs' => []];

function formatDetails(PDO $pdo, ?string $details): string {
    global $detailsCache;
    if (!$details || $details === '') return '—';
    $formatted = $details;

    if (preg_match_all('/client\s+[^(]*\(ID\s+(\d+)\)/i', $details, $matches)) {
        $clientIds = array_unique(array_map('intval', $matches[1]));
        $clientIds = array_filter($clientIds, fn($id) => $id > 0);
        if (!empty($clientIds)) {
            $missingIds = array_diff($clientIds, array_keys($detailsCache['clients']));
            if (!empty($missingIds)) {
                $ph = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id IN ($ph)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['clients'][(int)$row['id']] = $row['raison_sociale'];
                }
            }
            foreach ($clientIds as $cid) {
                if (isset($detailsCache['clients'][$cid])) {
                    $formatted = preg_replace('/client\s+[^(]*\(ID\s+' . $cid . '\)/i', 'client ' . $detailsCache['clients'][$cid], $formatted);
                }
            }
        }
    }

    if (preg_match_all('/SAV\s+#(\d+)/i', $details, $matches)) {
        $savIds = array_unique(array_map('intval', $matches[1]));
        $savIds = array_filter($savIds, fn($id) => $id > 0);
        if (!empty($savIds)) {
            $missingIds = array_diff($savIds, array_keys($detailsCache['sav']));
            if (!empty($missingIds)) {
                $ph = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, reference FROM sav WHERE id IN ($ph)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['sav'][(int)$row['id']] = $row['reference'];
                }
            }
            foreach ($savIds as $sid) {
                if (isset($detailsCache['sav'][$sid])) {
                    $formatted = preg_replace('/SAV\s+#' . $sid . '/i', 'SAV ' . $detailsCache['sav'][$sid], $formatted);
                }
            }
        }
    }

    if (preg_match_all('/Livraison\s+#(\d+)/i', $details, $matches)) {
        $livIds = array_unique(array_map('intval', $matches[1]));
        $livIds = array_filter($livIds, fn($id) => $id > 0);
        if (!empty($livIds)) {
            $missingIds = array_diff($livIds, array_keys($detailsCache['livraisons']));
            if (!empty($missingIds)) {
                $ph = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, reference FROM livraisons WHERE id IN ($ph)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['livraisons'][(int)$row['id']] = $row['reference'];
                }
            }
            foreach ($livIds as $lid) {
                if (isset($detailsCache['livraisons'][$lid])) {
                    $formatted = preg_replace('/Livraison\s+#' . $lid . '/i', 'Livraison ' . $detailsCache['livraisons'][$lid], $formatted);
                }
            }
        }
    }

    if (preg_match_all('/(?:Statut\s+)?[Uu]tilisateur\s+#(\d+)/i', $formatted, $matches)) {
        $userIds = array_unique(array_map('intval', $matches[1]));
        $userIds = array_filter($userIds, fn($id) => $id > 0);
        if (!empty($userIds)) {
            $missingIds = array_diff($userIds, array_keys($detailsCache['utilisateurs']));
            if (!empty($missingIds)) {
                $ph = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, nom, prenom FROM utilisateurs WHERE id IN ($ph)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fn = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
                    $detailsCache['utilisateurs'][(int)$row['id']] = $fn !== '' ? $fn : 'Utilisateur #' . $row['id'];
                }
            }
            foreach ($userIds as $uid) {
                if (isset($detailsCache['utilisateurs'][$uid])) {
                    $formatted = preg_replace('/Statut\s+[Uu]tilisateur\s+#' . $uid . '/i', 'Statut utilisateur ' . $detailsCache['utilisateurs'][$uid], $formatted);
                    $formatted = preg_replace('/[Uu]tilisateur\s+#' . $uid . '/i', 'Utilisateur ' . $detailsCache['utilisateurs'][$uid], $formatted);
                }
            }
        }
    }

    $formatted = preg_replace('/\s+/', ' ', $formatted);
    $formatted = trim($formatted);
    return $formatted;
}

// URL de base pour les liens de pagination
$baseUrl = 'historique.php?';
$queryParts = [];
if ($searchUser !== '') $queryParts['user_search'] = $searchUser;
if ($searchDateDebut !== '') $queryParts['date_debut'] = $searchDateDebut;
if ($searchDateFin !== '') $queryParts['date_fin'] = $searchDateFin;
if ($searchCategory !== '') $queryParts['categorie'] = $searchCategory;
if ($searchAction !== '') $queryParts['action_filter'] = $searchAction;
$queryString = http_build_query($queryParts);
$paginationBase = $queryString !== '' ? $baseUrl . $queryString . '&' : $baseUrl;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Actions - CCComputer</title>
    <meta name="description" content="Consultez l'historique des actions effectuées sur le système">
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/historique.css">
</head>
<body>

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-historique">
    <header class="page-header">
        <div class="page-header-content">
            <div class="page-header-icon" aria-hidden="true">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            <div>
                <h1 class="page-title">Historique des actions</h1>
                <p class="page-subtitle">Surveillez les opérations clés en temps réel</p>
            </div>
        </div>
        <a href="historique.php<?= $queryString ? '?' . $queryString : '' ?>" class="btn-actualiser" title="Actualiser les données">
            <span class="btn-actualiser-icon" aria-hidden="true">↻</span>
            Actualiser
        </a>
    </header>

    <section class="history-meta <?= $filtersActive ? 'has-filter' : '' ?>" role="region" aria-label="Statistiques">
        <div class="meta-card">
            <span class="meta-label">Entrées listées</span>
            <strong class="meta-value"><?= h((string)$historiqueCount) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Utilisateurs impliqués</span>
            <strong class="meta-value"><?= h((string)$uniqueUsersCount) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Dernière activité</span>
            <strong class="meta-value"><?= h(formatDate($lastActivity, 'd/m/Y H:i')) ?></strong>
        </div>
        <div class="meta-card">
            <span class="meta-label">Première activité</span>
            <strong class="meta-value"><?= h(formatDate($firstActivity, 'd/m/Y H:i')) ?></strong>
        </div>
    </section>

    <?php if ($filtersActive): ?>
    <div class="active-filters" role="status" aria-live="polite">
        <span class="badge">Filtres actifs</span>
        <?php if ($searchUser !== ''): ?>
            <span class="pill">👤 <?= h($searchUser) ?></span>
        <?php endif; ?>
        <?php if ($searchDateDebut !== ''): ?>
            <span class="pill">📅 Début : <?= h($searchDateDebut) ?></span>
        <?php endif; ?>
        <?php if ($searchDateFin !== ''): ?>
            <span class="pill">📅 Fin : <?= h($searchDateFin) ?></span>
        <?php endif; ?>
        <?php if ($searchCategory !== ''): ?>
            <span class="pill"><?= h($searchCategory) ?></span>
        <?php endif; ?>
        <?php if ($searchAction !== ''): ?>
            <span class="pill"><?= h(formatAction($searchAction)) ?></span>
        <?php endif; ?>
        <a class="pill pill-clear" href="historique.php" aria-label="Réinitialiser les filtres">✕ Réinitialiser</a>
    </div>
    <?php endif; ?>

    <form class="filtre-form" id="filterForm" method="get" action="historique.php" novalidate aria-label="Filtres" data-debounce-delay="<?= (int)DEBOUNCE_DELAY_MS ?>">
        <input type="hidden" name="page" value="1">
        <div class="filter-group">
            <label for="user_search">Recherche utilisateur</label>
            <div class="input-wrapper">
                <input type="text" id="user_search" name="user_search" value="<?= h($searchUser) ?>" placeholder="Nom Prénom…" autocomplete="off" maxlength="<?= USER_SEARCH_MAX_CHARS ?>">
                <?php if ($searchUser !== ''): ?>
                    <button type="button" class="input-clear" aria-label="Effacer" data-clear="user_search">✕</button>
                <?php endif; ?>
            </div>
        </div>
        <div class="filter-group">
            <label for="categorie">Catégorie</label>
            <select id="categorie" name="categorie">
                <option value="">Toutes</option>
                <?php foreach ($allowedCategories as $cat): ?>
                    <option value="<?= h($cat) ?>" <?= $searchCategory === $cat ? 'selected' : '' ?>><?= h($cat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="action_filter">Action</label>
            <select id="action_filter" name="action_filter">
                <option value="">Toutes</option>
                <?php foreach ($distinctActions as $act): ?>
                    <option value="<?= h($act) ?>" <?= $searchAction === $act ? 'selected' : '' ?>><?= h(formatAction($act)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="date_debut">Date début</label>
            <input type="date" id="date_debut" name="date_debut" value="<?= h($searchDateDebut) ?>">
        </div>
        <div class="filter-group">
            <label for="date_fin">Date fin</label>
            <input type="date" id="date_fin" name="date_fin" value="<?= h($searchDateFin) ?>">
        </div>
        <div class="filter-group filter-actions">
            <button type="submit" class="btn-filter">Filtrer</button>
            <a href="historique.php" class="btn-reset">Réinitialiser</a>
        </div>
    </form>

    <div class="table-responsive" role="region" aria-label="Tableau de l'historique">
        <table class="history-table" role="table">
            <thead>
                <tr>
                    <th scope="col">Date &amp; Heure</th>
                    <th scope="col">Utilisateur</th>
                    <th scope="col">Catégorie</th>
                    <th scope="col">Action</th>
                    <th scope="col">Détails</th>
                    <th scope="col">IP</th>
                    <th scope="col"></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dbError !== null): ?>
                    <tr>
                        <td colspan="7" class="aucun" role="alert">
                            <span class="error-icon" aria-hidden="true">⚠</span>
                            <?= h($dbError) ?>
                        </td>
                    </tr>
                <?php elseif (empty($historique)): ?>
                    <tr>
                        <td colspan="7" class="aucun">
                            <span class="empty-icon" aria-hidden="true">📭</span>
                            Aucun résultat trouvé.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historique as $entree):
                        $cat = getCategoryFromAction($entree['action']);
                        $badgeClass = CATEGORY_COLORS[$cat] ?? 'badge-other';
                        $detailsFormatted = formatDetails($pdo, $entree['details']);
                    ?>
                        <tr class="history-row" data-date="<?= h($entree['date_action']) ?>" data-user="<?= h(formatFullName($entree['nom'], $entree['prenom'])) ?>" data-action="<?= h($entree['action']) ?>" data-details="<?= h($detailsFormatted) ?>" data-ip="<?= h($entree['ip_address'] ?? '') ?>">
                            <td data-label="Date & Heure">
                                <time datetime="<?= h($entree['date_action']) ?>"><?= h(formatDate($entree['date_action'], 'd/m/Y H:i')) ?></time>
                            </td>
                            <td data-label="Utilisateur"><?= h(formatFullName($entree['nom'], $entree['prenom'])) ?></td>
                            <td data-label="Catégorie">
                                <span class="badge-categorie <?= h($badgeClass) ?>"><?= h($cat) ?></span>
                            </td>
                            <td data-label="Action">
                                <span class="action-badge <?= h($badgeClass) ?>"><?= h(formatAction($entree['action'])) ?></span>
                            </td>
                            <td data-label="Détails">
                                <?php if (!empty($entree['details'])): ?>
                                    <span class="details-text"><?= h($detailsFormatted) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="IP">
                                <?php if (!empty($entree['ip_address'])): ?>
                                    <code class="ip-address"><?= h($entree['ip_address']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="">
                                <button type="button" class="btn-voir" aria-label="Voir le détail">Voir</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <ul class="history-list" role="list">
        <?php if ($dbError !== null): ?>
            <li class="history-item"><div class="aucun-resultat" role="alert">⚠ <?= h($dbError) ?></div></li>
        <?php elseif (empty($historique)): ?>
            <li class="history-item"><div class="aucun-resultat">📭 Aucun résultat trouvé.</div></li>
        <?php else: ?>
            <?php foreach ($historique as $entree):
                $cat = getCategoryFromAction($entree['action']);
                $badgeClass = CATEGORY_COLORS[$cat] ?? 'badge-other';
                $detailsFormatted = formatDetails($pdo, $entree['details']);
            ?>
                <li class="history-item" data-date="<?= h($entree['date_action']) ?>" data-user="<?= h(formatFullName($entree['nom'], $entree['prenom'])) ?>" data-action="<?= h($entree['action']) ?>" data-details="<?= h($detailsFormatted) ?>" data-ip="<?= h($entree['ip_address'] ?? '') ?>">
                    <div class="item-header">
                        <span class="item-title"><?= h(formatFullName($entree['nom'], $entree['prenom'])) ?></span>
                        <time class="item-date" datetime="<?= h($entree['date_action']) ?>"><?= h(formatDate($entree['date_action'], 'd/m/Y H:i')) ?></time>
                    </div>
                    <div class="item-body">
                        <div class="item-detail">
                            <span class="label">Catégorie :</span>
                            <span class="badge-categorie <?= h($badgeClass) ?>"><?= h($cat) ?></span>
                        </div>
                        <div class="item-detail">
                            <span class="label">Action :</span>
                            <span class="action-badge <?= h($badgeClass) ?>"><?= h(formatAction($entree['action'])) ?></span>
                        </div>
                        <?php if (!empty($entree['details'])): ?>
                            <div class="item-detail">
                                <span class="label">Détails :</span>
                                <span class="value"><?= h($detailsFormatted) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($entree['ip_address'])): ?>
                            <div class="item-detail">
                                <span class="label">IP :</span>
                                <code class="ip-address"><?= h($entree['ip_address']) ?></code>
                            </div>
                        <?php endif; ?>
                        <button type="button" class="btn-voir btn-voir-card">Voir</button>
                    </div>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>

    <?php if ($totalCount > 0): ?>
    <p class="pagination-info"><?= h((string)$totalCount) ?> résultat<?= $totalCount > 1 ? 's' : '' ?> au total</p>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="pagination" aria-label="Pagination">
        <div class="pagination-inner">
            <?php if ($page > 1): ?>
                <a href="<?= h($paginationBase . 'page=' . ($page - 1)) ?>" class="pagination-btn pagination-prev">Précédent</a>
            <?php else: ?>
                <span class="pagination-btn pagination-prev disabled">Précédent</span>
            <?php endif; ?>

            <div class="pagination-numbers">
                <?php
                $start = max(1, $page - 2);
                $end = min($totalPages, $page + 2);
                if ($start > 1): ?>
                    <a href="<?= h($paginationBase . 'page=1') ?>" class="pagination-num">1</a>
                    <?php if ($start > 2): ?><span class="pagination-ellipsis">…</span><?php endif;
                endif;
                for ($i = $start; $i <= $end; $i++): ?>
                    <?php if ($i === $page): ?>
                        <span class="pagination-num current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="<?= h($paginationBase . 'page=' . $i) ?>" class="pagination-num"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor;
                if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="pagination-ellipsis">…</span><?php endif; ?>
                    <a href="<?= h($paginationBase . 'page=' . $totalPages) ?>" class="pagination-num"><?= $totalPages ?></a>
                <?php endif; ?>
            </div>

            <?php if ($page < $totalPages): ?>
                <a href="<?= h($paginationBase . 'page=' . ($page + 1)) ?>" class="pagination-btn pagination-next">Suivant</a>
            <?php else: ?>
                <span class="pagination-btn pagination-next disabled">Suivant</span>
            <?php endif; ?>
        </div>
    </nav>
    <?php endif; ?>
</main>

<!-- Modal détail événement -->
<div id="eventModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
    <div class="modal-content" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h2 id="modalTitle">Détail de l'événement</h2>
            <button type="button" class="modal-close" aria-label="Fermer" onclick="closeEventModal()">×</button>
        </div>
        <div class="modal-body">
            <dl class="modal-details">
                <dt>Date</dt>
                <dd id="modalDate">—</dd>
                <dt>Utilisateur</dt>
                <dd id="modalUser">—</dd>
                <dt>Action</dt>
                <dd id="modalAction">—</dd>
                <dt>Détails</dt>
                <dd id="modalDetails">—</dd>
                <dt>Adresse IP</dt>
                <dd id="modalIp">—</dd>
            </dl>
        </div>
    </div>
</div>

<script>
(function() {
    'use strict';
    const form = document.getElementById('filterForm');
    const userInput = document.getElementById('user_search');
    const debounceDelay = parseInt(form?.getAttribute('data-debounce-delay'), 10) || 400;
    let debounceTimer;

    if (userInput) {
        userInput.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function() { form.submit(); }, debounceDelay);
        });
        userInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); clearTimeout(debounceTimer); form.submit(); }
        });
    }

    document.querySelectorAll('#categorie, #action_filter, #date_debut, #date_fin').forEach(function(el) {
        if (el) el.addEventListener('change', function() { form.submit(); });
    });

    document.querySelectorAll('.input-clear').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-clear');
            const inp = document.getElementById(id);
            if (inp) { inp.value = ''; form.submit(); }
        });
    });

    function openEventModal(date, user, action, details, ip) {
        document.getElementById('modalDate').textContent = date || '—';
        document.getElementById('modalUser').textContent = user || '—';
        document.getElementById('modalAction').textContent = action || '—';
        document.getElementById('modalDetails').textContent = details || '—';
        document.getElementById('modalIp').textContent = ip || '—';
        var m = document.getElementById('eventModal');
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeEventModal() {
        var m = document.getElementById('eventModal');
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    window.closeEventModal = closeEventModal;

    document.getElementById('eventModal').addEventListener('click', function(e) {
        if (e.target === this) closeEventModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeEventModal();
    });

    function showDetail(btn) {
        var row = btn.closest('.history-row') || btn.closest('.history-item');
        if (!row) return;
        openEventModal(
            row.getAttribute('data-date') || '',
            row.getAttribute('data-user') || '',
            row.getAttribute('data-action') ? row.getAttribute('data-action').replace(/_/g, ' ') : '',
            row.getAttribute('data-details') || '',
            row.getAttribute('data-ip') || ''
        );
    }

    document.querySelectorAll('.btn-voir').forEach(function(btn) {
        btn.addEventListener('click', function(e) { e.stopPropagation(); showDetail(this); });
    });

    document.querySelectorAll('.history-row').forEach(function(row) {
        row.addEventListener('click', function(e) {
            if (!e.target.closest('.btn-voir')) showDetail(this.querySelector('.btn-voir'));
        });
    });

    document.querySelectorAll('.history-item').forEach(function(item) {
        item.addEventListener('click', function(e) {
            if (!e.target.closest('.btn-voir')) {
                var btn = this.querySelector('.btn-voir');
                if (btn) showDetail(btn);
            }
        });
    });
})();
</script>
</body>
</html>
