<?php
/**
 * /public/historique.php
 * Page d'historique des actions - Version refactorisée et optimisée
 * 
 * Affiche l'historique des actions utilisateurs avec filtres par utilisateur et date.
 * Accès réservé aux rôles Admin et Dirigeant.
 */

// ====== Configuration & Sécurité ======
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('historique', ['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Constantes
const HISTORIQUE_PAGE_LIMIT = 200;
const USER_SEARCH_MAX_CHARS = 80;
const DEBOUNCE_DELAY_MS = 400;
const DEBUG_MODE = false; // Mettre à true pour activer les logs de débogage

// ====== Validation et nettoyage des paramètres GET ======
/**
 * Nettoie et valide la recherche utilisateur
 */
function sanitizeUserSearch(string $input): string {
    $cleaned = trim($input);
    if ($cleaned === '') {
        return '';
    }
    // Normaliser les espaces multiples
    $cleaned = preg_replace('/\s+/', ' ', $cleaned);
    // Limiter la longueur
    $cleaned = mb_substr($cleaned, 0, USER_SEARCH_MAX_CHARS);
    // Nettoyer : supprimer uniquement les caractères vraiment dangereux
    // Conserver les lettres (y compris accents), chiffres, espaces, tirets, apostrophes, points
    $cleaned = preg_replace('/[^\p{L}\p{N}\s\-\'\.]/u', '', $cleaned);
    return $cleaned;
}

/**
 * Valide et parse une date au format Y-m-d
 */
function parseDateFilter(string $dateInput): ?array {
    $cleaned = trim($dateInput);
    if ($cleaned === '') {
        return null;
    }
    
    // Validation stricte du format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $cleaned)) {
        return null;
    }
    
    $dt = DateTime::createFromFormat('Y-m-d', $cleaned);
    $errors = DateTime::getLastErrors();
    
    if (!$dt || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
        return null;
    }
    
    $dateStart = (clone $dt)->setTime(0, 0, 0);
    $dateEnd = (clone $dt)->modify('+1 day')->setTime(0, 0, 0);
    
    return [
        'start' => $dateStart,
        'end' => $dateEnd,
        'display' => $cleaned
    ];
}

// ====== Récupération et validation des paramètres ======
$rawUser = $_GET['user_search'] ?? '';
$rawDate = $_GET['date_search'] ?? '';

if (DEBUG_MODE) {
    error_log('=== DÉBOGAGE HISTORIQUE ===');
    error_log('$_GET: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));
}

$searchUser = sanitizeUserSearch(is_string($rawUser) ? $rawUser : '');
$dateFilter = parseDateFilter(is_string($rawDate) ? $rawDate : '');
$searchDate = $dateFilter ? $dateFilter['display'] : '';

// ====== Construction de la requête SQL sécurisée ======
$params = [];
$whereConditions = [];

// Filtre par utilisateur (recherche multi-mots)
if ($searchUser !== '') {
    $tokens = preg_split('/\s+/', $searchUser);
    $userConditions = [];
    $tokenIndex = 0;
    
    foreach ($tokens as $token) {
        $token = trim($token);
        if ($token === '') {
            continue;
        }
        // Créer DEUX placeholders uniques pour chaque token (un pour nom, un pour prénom)
        // PDO ne permet pas de réutiliser le même placeholder plusieurs fois
        $paramKeyNom = ':search_user_nom_' . $tokenIndex;
        $paramKeyPrenom = ':search_user_prenom_' . $tokenIndex;
        $tokenIndex++;
        $paramValue = '%' . $token . '%';
        
        // Construire la condition avec deux placeholders distincts
        $condition = "(u.nom LIKE " . $paramKeyNom . " OR u.prenom LIKE " . $paramKeyPrenom . ")";
        $userConditions[] = $condition;
        $params[$paramKeyNom] = $paramValue;
        $params[$paramKeyPrenom] = $paramValue;
    }
    
    if (!empty($userConditions)) {
        $whereConditions[] = '(' . implode(' AND ', $userConditions) . ')';
    }
}

// Filtre par date
if ($dateFilter) {
    $whereConditions[] = "h.date_action >= :dstart AND h.date_action < :dend";
    $params[':dstart'] = $dateFilter['start']->format('Y-m-d H:i:s');
    $params[':dend'] = $dateFilter['end']->format('Y-m-d H:i:s');
}

// Construction de la requête SQL
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

if (!empty($whereConditions)) {
    $sql .= ' WHERE ' . implode(' AND ', $whereConditions);
}

// LIMIT doit être un entier, pas un paramètre nommé (compatibilité PDO)
$limit = (int)HISTORIQUE_PAGE_LIMIT;
$sql .= ' ORDER BY h.date_action DESC LIMIT ' . $limit;

if (DEBUG_MODE) {
    error_log('SQL: ' . $sql);
    error_log('Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
}

// ====== Exécution de la requête ======
$historique = [];
$dbError = null;
$errorDetails = null;

try {
    $stmt = $pdo->prepare($sql);
    
    // Bind des paramètres un par un pour plus de contrôle
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    
    $stmt->execute();
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $dbError = 'Impossible de charger l\'historique pour le moment.';
    $errorDetails = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'sql' => $sql,
        'params' => $params,
        'searchUser' => $searchUser,
        'rawUser' => $rawUser ?? '',
    ];
    
    error_log('Erreur SQL (historique): ' . $e->getMessage());
    if (DEBUG_MODE) {
        error_log('SQL: ' . $sql);
        error_log('Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        error_log('Stack trace: ' . $e->getTraceAsString());
    }
    
    $historique = [];
}

// ====== Calcul des statistiques ======
$historiqueCount = count($historique);
$isLimited = ($historiqueCount === HISTORIQUE_PAGE_LIMIT);

// Comptage des utilisateurs uniques
$uniqueUsers = [];
foreach ($historique as $row) {
    $fullname = trim(($row['nom'] ?? '') . ' ' . ($row['prenom'] ?? ''));
    if ($fullname !== '') {
        $uniqueUsers[$fullname] = true;
    }
}
$uniqueUsersCount = count($uniqueUsers);

// Dates de première et dernière activité
$lastActivity = $historique[0]['date_action'] ?? null;
$firstActivity = $historiqueCount > 0 ? ($historique[$historiqueCount - 1]['date_action'] ?? null) : null;

$filtersActive = ($searchUser !== '' || $searchDate !== '');

// ====== Helper pour formater le nom complet ======
function formatFullName(?string $nom, ?string $prenom): string {
    $fullname = trim(($nom ?? '') . ' ' . ($prenom ?? ''));
    return $fullname !== '' ? $fullname : '—';
}

// ====== Helper pour formater l'action ======
function formatAction(?string $action): string {
    if (!$action) {
        return '—';
    }
    return str_replace('_', ' ', $action);
}

// ====== Cache pour éviter les requêtes répétées ======
static $detailsCache = [
    'clients' => [],
    'sav' => [],
    'livraisons' => [],
    'utilisateurs' => []
];

// ====== Helper pour formater les détails en remplaçant les IDs par les noms/références ======
function formatDetails(PDO $pdo, ?string $details): string {
    global $detailsCache;
    
    if (!$details || $details === '') {
        return '—';
    }
    
    $formatted = $details;
    
    // Remplacer les références de clients (ID X) par le nom du client
    if (preg_match_all('/client\s+[^(]*\(ID\s+(\d+)\)/i', $details, $matches)) {
        $clientIds = array_unique(array_map('intval', $matches[1]));
        $clientIds = array_filter($clientIds, function($id) { return $id > 0; });
        
        if (!empty($clientIds)) {
            $missingIds = array_diff($clientIds, array_keys($detailsCache['clients']));
            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id IN ($placeholders)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['clients'][(int)$row['id']] = $row['raison_sociale'];
                }
            }
            
            foreach ($clientIds as $clientId) {
                if (isset($detailsCache['clients'][$clientId])) {
                    $formatted = preg_replace(
                        '/client\s+[^(]*\(ID\s+' . $clientId . '\)/i',
                        'client ' . $detailsCache['clients'][$clientId],
                        $formatted
                    );
                }
            }
        }
    }
    
    // Remplacer les références SAV (#X) par la référence SAV
    if (preg_match_all('/SAV\s+#(\d+)/i', $details, $matches)) {
        $savIds = array_unique(array_map('intval', $matches[1]));
        $savIds = array_filter($savIds, function($id) { return $id > 0; });
        
        if (!empty($savIds)) {
            $missingIds = array_diff($savIds, array_keys($detailsCache['sav']));
            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, reference FROM sav WHERE id IN ($placeholders)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['sav'][(int)$row['id']] = $row['reference'];
                }
            }
            
            foreach ($savIds as $savId) {
                if (isset($detailsCache['sav'][$savId])) {
                    $formatted = preg_replace(
                        '/SAV\s+#' . $savId . '/i',
                        'SAV ' . $detailsCache['sav'][$savId],
                        $formatted
                    );
                }
            }
        }
    }
    
    // Remplacer les références de livraisons (#X) par la référence de livraison
    if (preg_match_all('/Livraison\s+#(\d+)/i', $details, $matches)) {
        $livIds = array_unique(array_map('intval', $matches[1]));
        $livIds = array_filter($livIds, function($id) { return $id > 0; });
        
        if (!empty($livIds)) {
            $missingIds = array_diff($livIds, array_keys($detailsCache['livraisons']));
            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, reference FROM livraisons WHERE id IN ($placeholders)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $detailsCache['livraisons'][(int)$row['id']] = $row['reference'];
                }
            }
            
            foreach ($livIds as $livId) {
                if (isset($detailsCache['livraisons'][$livId])) {
                    $formatted = preg_replace(
                        '/Livraison\s+#' . $livId . '/i',
                        'Livraison ' . $detailsCache['livraisons'][$livId],
                        $formatted
                    );
                }
            }
        }
    }
    
    // Remplacer les références d'utilisateurs (utilisateur #X, Utilisateur #X, Statut utilisateur #X, etc.)
    if (preg_match_all('/(?:Statut\s+)?[Uu]tilisateur\s+#(\d+)/i', $formatted, $matches)) {
        $userIds = array_unique(array_map('intval', $matches[1]));
        $userIds = array_filter($userIds, function($id) { return $id > 0; });
        
        if (!empty($userIds)) {
            $missingIds = array_diff($userIds, array_keys($detailsCache['utilisateurs']));
            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, nom, prenom FROM utilisateurs WHERE id IN ($placeholders)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fullname = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
                    $detailsCache['utilisateurs'][(int)$row['id']] = $fullname !== '' ? $fullname : 'Utilisateur #' . $row['id'];
                }
            }
            
            foreach ($userIds as $userId) {
                if (isset($detailsCache['utilisateurs'][$userId])) {
                    // Remplacer "Statut utilisateur #X" par "Statut utilisateur Nom Prénom"
                    $formatted = preg_replace(
                        '/Statut\s+[Uu]tilisateur\s+#' . $userId . '/i',
                        'Statut utilisateur ' . $detailsCache['utilisateurs'][$userId],
                        $formatted
                    );
                    // Remplacer "utilisateur #X" ou "Utilisateur #X" (insensible à la casse)
                    $formatted = preg_replace(
                        '/[Uu]tilisateur\s+#' . $userId . '/i',
                        'Utilisateur ' . $detailsCache['utilisateurs'][$userId],
                        $formatted
                    );
                }
            }
        }
    }
    
    // Remplacer aussi les patterns "#X" isolés qui apparaissent dans un contexte d'utilisateur
    if (preg_match_all('/(?<!SAV\s)(?<!Livraison\s)(?<!Livraison\s)(?<!SAV\s)#(\d+)/i', $formatted, $matches)) {
        $userIds = array_unique(array_map('intval', $matches[1]));
        $userIds = array_filter($userIds, function($id) { 
            return $id > 0 && 
                   !isset($detailsCache['sav'][$id]) && 
                   !isset($detailsCache['livraisons'][$id]);
        });
        
        if (!empty($userIds)) {
            $missingIds = array_diff($userIds, array_keys($detailsCache['utilisateurs']));
            if (!empty($missingIds)) {
                $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
                $stmt = $pdo->prepare("SELECT id, nom, prenom FROM utilisateurs WHERE id IN ($placeholders)");
                $stmt->execute($missingIds);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $fullname = trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? ''));
                    $detailsCache['utilisateurs'][(int)$row['id']] = $fullname !== '' ? $fullname : 'Utilisateur #' . $row['id'];
                }
            }
            
            foreach ($userIds as $userId) {
                if (isset($detailsCache['utilisateurs'][$userId])) {
                    // Remplacer seulement si c'est dans un contexte qui suggère un utilisateur
                    if (stripos($formatted, 'utilisateur') !== false || 
                        preg_match('/Statut\s+#?' . $userId . '/i', $formatted) ||
                        preg_match('/[Uu]tilisateur.*#' . $userId . '/i', $formatted)) {
                        $formatted = preg_replace(
                            '/(?<!SAV\s)(?<!Livraison\s)#' . $userId . '(?!\w)/i',
                            $detailsCache['utilisateurs'][$userId],
                            $formatted
                        );
                    }
                }
            }
        }
    }
    
    // Supprimer les références de messages (#X après "message")
    $formatted = preg_replace('/\s*(?:au\s+)?[Mm]essage\s+#\d+/i', '', $formatted);
    
    // Supprimer les patterns (ID X) restants qui ont été remplacés
    $formatted = preg_replace('/\s*\(ID\s+\d+\)\s*/i', ' ', $formatted);
    
    // Supprimer les références #X isolées restantes dans un contexte de message/réponse
    if (stripos($formatted, 'message') !== false || stripos($formatted, 'réponse') !== false || stripos($formatted, 'Réponse') !== false) {
        $formatted = preg_replace('/\s*#\d+\s*/i', ' ', $formatted);
    }
    
    // Normaliser les espaces multiples et nettoyer
    $formatted = preg_replace('/\s+/', ' ', $formatted);
    $formatted = preg_replace('/\s*:\s*/', ': ', $formatted);
    $formatted = preg_replace('/\s*-\s*/', ' - ', $formatted);
    $formatted = trim($formatted);
    
    return $formatted;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique des Actions - CCComputer</title>
    <meta name="description" content="Consultez l'historique des actions effectuées sur le système">
    
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/historique.css">
</head>
<body>

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container page-historique">
    <header class="page-header">
        <h1 class="page-title">Historique des actions</h1>
        <p class="page-subtitle">
            Surveillez les opérations clés en temps réel. Les <?= HISTORIQUE_PAGE_LIMIT ?> dernières entrées sont affichées.
        </p>
    </header>

    <!-- Statistiques -->
    <section class="history-meta <?= $filtersActive ? 'has-filter' : '' ?>" role="region" aria-label="Statistiques de l'historique">
        <div class="meta-card">
            <span class="meta-label">Entrées listées</span>
            <strong class="meta-value"><?= h((string)$historiqueCount) ?></strong>
            <?php if ($isLimited): ?>
                <span class="meta-chip" title="Seulement les dernières entrées sont affichées" aria-label="Limite atteinte">
                    <span aria-hidden="true">⚠</span> Limite atteinte
                </span>
            <?php endif; ?>
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

    <!-- Filtres actifs -->
    <?php if ($filtersActive): ?>
        <div class="active-filters" role="status" aria-live="polite">
            <span class="badge">Filtres actifs</span>
            <?php if ($searchUser !== ''): ?>
                <span class="pill">
                    <span aria-hidden="true">👤</span>
                    Utilisateur : <?= h($searchUser) ?>
                </span>
            <?php endif; ?>
            <?php if ($searchDate !== ''): ?>
                <span class="pill">
                    <span aria-hidden="true">📅</span>
                    Date : <?= h($searchDate) ?>
                </span>
            <?php endif; ?>
            <a class="pill pill-clear" href="historique.php" aria-label="Réinitialiser les filtres">
                <span aria-hidden="true">✕</span> Réinitialiser
            </a>
        </div>
    <?php endif; ?>

    <!-- Formulaire de filtres -->
    <form class="filtre-form" id="filterForm" method="get" action="historique.php" novalidate aria-label="Filtres de recherche" data-debounce-delay="<?= (int)DEBOUNCE_DELAY_MS ?>">
        <div class="filter-group">
            <label for="user_search">Filtrer par utilisateur</label>
            <div class="input-wrapper">
                <input
                    type="text"
                    id="user_search"
                    name="user_search"
                    value="<?= h($searchUser) ?>"
                    placeholder="Nom Prénom…"
                    autocomplete="off"
                    inputmode="search"
                    aria-label="Recherche par nom ou prénom"
                    maxlength="<?= USER_SEARCH_MAX_CHARS ?>"
                >
                <?php if ($searchUser !== ''): ?>
                    <button type="button" class="input-clear" aria-label="Effacer la recherche" data-clear="user_search">
                        <span aria-hidden="true">✕</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="filter-group">
            <label for="date_search">Filtrer par date</label>
            <div class="input-wrapper">
                <input
                    type="date"
                    id="date_search"
                    name="date_search"
                    value="<?= h($searchDate) ?>"
                    aria-label="Recherche par date"
                >
                <?php if ($searchDate !== ''): ?>
                    <button type="button" class="input-clear" aria-label="Effacer la date" data-clear="date_search">
                        <span aria-hidden="true">✕</span>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <!-- Tableau desktop -->
    <div class="table-responsive" role="region" aria-label="Tableau de l'historique">
        <table class="history-table" role="table">
            <thead>
                <tr>
                    <th scope="col">Date &amp; Heure</th>
                    <th scope="col">Utilisateur</th>
                    <th scope="col">Action</th>
                    <th scope="col">Détails</th>
                    <th scope="col">Adresse IP</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dbError !== null): ?>
                    <tr>
                        <td colspan="5" class="aucun" role="alert">
                            <span class="error-icon" aria-hidden="true">⚠</span>
                            <?= h($dbError) ?>
                            <?php if (DEBUG_MODE && isset($errorDetails)): ?>
                                <div class="debug-error-details">
                                    <strong>Détails de l'erreur (DEBUG):</strong><br>
                                    <strong>Message:</strong> <?= h($errorDetails['message']) ?><br>
                                    <strong>Code:</strong> <?= h((string)$errorDetails['code']) ?><br>
                                    <strong>SearchUser:</strong> <?= h($errorDetails['searchUser']) ?><br>
                                    <strong>RawUser:</strong> <?= h($errorDetails['rawUser']) ?><br>
                                    <strong>SQL:</strong> <code><?= h($errorDetails['sql']) ?></code><br>
                                    <strong>Params:</strong> <code><?= h(json_encode($errorDetails['params'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></code>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php elseif (empty($historique)): ?>
                    <tr>
                        <td colspan="5" class="aucun">
                            <span class="empty-icon" aria-hidden="true">📭</span>
                            Aucun résultat trouvé pour les filtres sélectionnés.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($historique as $entree): ?>
                        <tr>
                            <td data-label="Date &amp; Heure">
                                <time datetime="<?= h($entree['date_action']) ?>">
                                    <?= h(formatDate($entree['date_action'], 'd/m/Y H:i')) ?>
                                </time>
                            </td>
                            <td data-label="Utilisateur"><?= h(formatFullName($entree['nom'], $entree['prenom'])) ?></td>
                            <td data-label="Action">
                                <span class="action-badge"><?= h(formatAction($entree['action'])) ?></span>
                            </td>
                            <td data-label="Détails">
                                <?php if (!empty($entree['details'])): ?>
                                    <span class="details-text"><?= h(formatDetails($pdo, $entree['details'])) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Adresse IP">
                                <?php if (!empty($entree['ip_address'])): ?>
                                    <code class="ip-address"><?= h($entree['ip_address']) ?></code>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Version cartes mobile -->
    <ul class="history-list" role="list">
        <?php if ($dbError !== null): ?>
            <li class="history-item" role="listitem">
                <div class="item-body">
                    <div class="aucun-resultat" role="alert">
                        <span class="error-icon" aria-hidden="true">⚠</span>
                        <?= h($dbError) ?>
                    </div>
                </div>
            </li>
        <?php elseif (empty($historique)): ?>
            <li class="history-item" role="listitem">
                <div class="item-body">
                    <div class="aucun-resultat">
                        <span class="empty-icon" aria-hidden="true">📭</span>
                        Aucun résultat trouvé pour les filtres sélectionnés.
                    </div>
                </div>
            </li>
        <?php else: ?>
            <?php foreach ($historique as $entree): ?>
                <li class="history-item" role="listitem">
                    <div class="item-header">
                        <span class="item-title"><?= h(formatFullName($entree['nom'], $entree['prenom'])) ?></span>
                        <time class="item-date" datetime="<?= h($entree['date_action']) ?>">
                            <?= h(formatDate($entree['date_action'], 'd/m/Y H:i')) ?>
                        </time>
                    </div>
                    <div class="item-body">
                        <div class="item-detail">
                            <span class="label">Action :</span>
                            <span class="value">
                                <span class="action-badge"><?= h(formatAction($entree['action'])) ?></span>
                            </span>
                        </div>
                        <?php if (!empty($entree['details'])): ?>
                            <div class="item-detail">
                                <span class="label">Détails :</span>
                                <span class="value"><?= h(formatDetails($pdo, $entree['details'])) ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($entree['ip_address'])): ?>
                            <div class="item-detail">
                                <span class="label">IP :</span>
                                <span class="value"><code class="ip-address"><?= h($entree['ip_address']) ?></code></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</main>

<script>
(function() {
    'use strict';
    
    const form = document.getElementById('filterForm');
    const userInput = document.getElementById('user_search');
    const dateInput = document.getElementById('date_search');
    
    if (!form || !userInput || !dateInput) {
        return;
    }
    
    // Récupérer le délai de debounce depuis l'attribut data (conforme CSP)
    const debounceDelay = parseInt(form.getAttribute('data-debounce-delay'), 10) || 400;
    
    // Soumission immédiate quand la date change
    dateInput.addEventListener('change', function() {
        form.submit();
    });
    
    // Debounce pour l'input utilisateur
    let debounceTimer;
    userInput.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function() {
            form.submit();
        }, debounceDelay);
    });
    
    // Enter dans le champ user => submit direct
    userInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(debounceTimer);
            form.submit();
        }
    });
    
    // Boutons de nettoyage des champs
    const clearButtons = document.querySelectorAll('.input-clear');
    clearButtons.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetName = this.getAttribute('data-clear');
            const targetInput = document.getElementById(targetName);
            if (targetInput) {
                targetInput.value = '';
                form.submit();
            }
        });
    });
})();
</script>

</body>
</html>
