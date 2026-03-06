<?php
/**
 * API/historique_export.php
 * Export CSV de l'historique avec les mêmes filtres que la page
 * Accès : Admin, Dirigeant
 */

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

if (!checkPagePermission('historique', ['Admin', 'Dirigeant'])) {
    ob_end_clean();
    header('HTTP/1.1 403 Forbidden');
    exit('Accès non autorisé');
}

$pdo = getPdo();

function sanitizeUserSearchExport(string $input): string {
    $cleaned = trim($input);
    if ($cleaned === '') return '';
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    $cleaned = mb_substr($cleaned, 0, 80);
    $cleaned = preg_replace('/[^\p{L}\p{N}\s\-\'\.]/u', '', $cleaned);
    return $cleaned;
}

function parseDateFilterExport(string $dateInput): ?array {
    $cleaned = trim($dateInput);
    if ($cleaned === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleaned)) return null;
    $dt = DateTime::createFromFormat('Y-m-d', $cleaned);
    if (!$dt) return null;
    $dateStart = (clone $dt)->setTime(0, 0, 0);
    $dateEnd = (clone $dt)->modify('+1 day')->setTime(0, 0, 0);
    return ['start' => $dateStart, 'end' => $dateEnd];
}

$rawUser = isset($_GET['user_search']) ? (string)$_GET['user_search'] : '';
$rawDateDebut = isset($_GET['date_debut']) ? (string)$_GET['date_debut'] : '';
$rawDateFin = isset($_GET['date_fin']) ? (string)$_GET['date_fin'] : '';
$rawCategorie = isset($_GET['categorie']) ? (string)$_GET['categorie'] : '';
$rawAction = isset($_GET['action_filter']) ? (string)$_GET['action_filter'] : '';

$searchUser = sanitizeUserSearchExport($rawUser);
$dateDebutFilter = parseDateFilterExport($rawDateDebut);
$dateFinFilter = parseDateFilterExport($rawDateFin);

$allowedCategories = array_keys(AUDIT_CATEGORY_PATTERNS);
$searchCategory = in_array($rawCategorie, $allowedCategories, true) ? $rawCategorie : '';

$distinctActions = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT action FROM historique ORDER BY action");
    $distinctActions = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $distinctActions = [];
}
$searchAction = ($rawAction !== '' && in_array($rawAction, $distinctActions, true)) ? $rawAction : '';

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

if ($dateDebutFilter) {
    $whereConditions[] = "h.date_action >= :dstart";
    $params[':dstart'] = $dateDebutFilter['start']->format('Y-m-d H:i:s');
}
if ($dateFinFilter) {
    $whereConditions[] = "h.date_action < :dend";
    $params[':dend'] = $dateFinFilter['end']->format('Y-m-d H:i:s');
}

if ($searchCategory !== '' && isset(AUDIT_CATEGORY_PATTERNS[$searchCategory])) {
    $catConditions = [];
    foreach (AUDIT_CATEGORY_PATTERNS[$searchCategory] as $i => $p) {
        $key = ':cat_' . $searchCategory . '_' . $i;
        $catConditions[] = "h.action LIKE " . $key;
        $params[$key] = $p;
    }
    $whereConditions[] = '(' . implode(' OR ', $catConditions) . ')';
}

if ($searchAction !== '') {
    $whereConditions[] = "h.action = :action_filter";
    $params[':action_filter'] = $searchAction;
}

$sqlWhere = !empty($whereConditions) ? ' WHERE ' . implode(' AND ', $whereConditions) : '';
$sql = "SELECT h.id, h.date_action, h.action, h.details, h.ip_address, u.nom, u.prenom
        FROM historique h
        LEFT JOIN utilisateurs u ON h.user_id = u.id
        " . $sqlWhere . "
        ORDER BY h.date_action DESC
        LIMIT 10000";

$rows = [];
try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('historique_export.php: ' . $e->getMessage());
}

ob_end_clean();

$filename = 'historique_cccomputer_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['ID', 'Date', 'Heure', 'Utilisateur', 'Catégorie', 'Action', 'Détails', 'IP'], ';');

foreach ($rows as $r) {
    $dt = $r['date_action'] ?? '';
    $datePart = $dt ? substr($dt, 0, 10) : '';
    $timePart = $dt ? substr($dt, 11, 5) : '';
    $user = trim(($r['nom'] ?? '') . ' ' . ($r['prenom'] ?? ''));
    if ($user === '') $user = '—';
    $cat = getActionCategory($r['action'] ?? '');
    $actionLabel = formatActionLabel($r['action'] ?? '');
    $details = $r['details'] ?? '';
    $ip = $r['ip_address'] ?? '';
    fputcsv($out, [$r['id'] ?? '', $datePart, $timePart, $user, $cat, $actionLabel, $details, $ip], ';');
}

fclose($out);
exit;
