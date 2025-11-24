<?php
/**
 * /public/historique.php
 * Page d'historique des actions - Version refactorisée
 * 
 * Affiche l'historique des actions utilisateurs avec filtres par utilisateur et date.
 * Accès réservé aux rôles Admin et Dirigeant.
 */

// ====== Configuration & Sécurité ======
require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Constantes
const HISTORIQUE_PAGE_LIMIT = 200;
const USER_SEARCH_MAX_CHARS = 80;
const DEBOUNCE_DELAY_MS = 400;

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
    // \p{L} inclut toutes les lettres Unicode (y compris les accents)
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

// ====== INSTRUMENTATION DE DÉBOGAGE ======
error_log('=== DÉBUT DÉBOGAGE HISTORIQUE ===');
error_log('$_GET complet: ' . json_encode($_GET, JSON_UNESCAPED_UNICODE));

// Récupération et validation des paramètres
$rawUser = $_GET['user_search'] ?? '';
$rawDate = $_GET['date_search'] ?? '';

error_log('Point 1 - rawUser (après $_GET): ' . var_export($rawUser, true));
error_log('Point 1 - rawDate (après $_GET): ' . var_export($rawDate, true));
error_log('Point 1 - Type rawUser: ' . gettype($rawUser));
error_log('Point 1 - Type rawDate: ' . gettype($rawDate));

$searchUser = sanitizeUserSearch(is_string($rawUser) ? $rawUser : '');
$dateFilter = parseDateFilter(is_string($rawDate) ? $rawDate : '');
$searchDate = $dateFilter ? $dateFilter['display'] : '';

error_log('Point 2 - searchUser (après sanitizeUserSearch): ' . var_export($searchUser, true));
error_log('Point 2 - searchUser length: ' . strlen($searchUser));
error_log('Point 2 - searchUser empty?: ' . ($searchUser === '' ? 'OUI' : 'NON'));

// Debug temporaire : vérifier que la recherche n'est pas vide après sanitization
if ($rawUser !== '' && $searchUser === '') {
    error_log('⚠️ ALERTE: recherche utilisateur vidée par sanitization. Input: ' . $rawUser);
}

// ====== Construction de la requête SQL sécurisée ======
$params = [];
$whereConditions = [];

// Filtre par utilisateur (recherche multi-mots)
error_log('Point 3 - Vérification searchUser !== "": ' . ($searchUser !== '' ? 'OUI' : 'NON'));

if ($searchUser !== '') {
    error_log('Point 4 - Entrée dans le bloc if searchUser');
    $tokens = preg_split('/\s+/', $searchUser);
    error_log('Point 4 - Tokens après preg_split: ' . json_encode($tokens, JSON_UNESCAPED_UNICODE));
    error_log('Point 4 - Nombre de tokens: ' . count($tokens));
    
    $userConditions = [];
    $tokenIndex = 0;
    
    foreach ($tokens as $token) {
        $token = trim($token);
        error_log('Point 5 - Token brut: ' . var_export($token, true));
        error_log('Point 5 - Token après trim: ' . var_export($token, true));
        error_log('Point 5 - Token vide?: ' . ($token === '' ? 'OUI' : 'NON'));
        
        if ($token === '') {
            error_log('Point 5 - Token vide, on continue');
            continue;
        }
        // Créer DEUX placeholders uniques pour chaque token (un pour nom, un pour prénom)
        // PDO ne permet pas de réutiliser le même placeholder plusieurs fois
        $paramKeyNom = ':search_user_nom_' . $tokenIndex;
        $paramKeyPrenom = ':search_user_prenom_' . $tokenIndex;
        $tokenIndex++;
        $paramValue = '%' . $token . '%';
        
        error_log('Point 6 - paramKeyNom: ' . $paramKeyNom);
        error_log('Point 6 - paramKeyPrenom: ' . $paramKeyPrenom);
        error_log('Point 6 - paramValue: ' . $paramValue);
        
        // Construire la condition avec deux placeholders distincts
        $condition = "(u.nom LIKE " . $paramKeyNom . " OR u.prenom LIKE " . $paramKeyPrenom . ")";
        $userConditions[] = $condition;
        $params[$paramKeyNom] = $paramValue;
        $params[$paramKeyPrenom] = $paramValue;
        
        error_log('Point 6 - Condition créée: ' . $condition);
        error_log('Point 6 - Params ajoutés: ' . $paramKeyNom . ' => ' . $paramValue . ', ' . $paramKeyPrenom . ' => ' . $paramValue);
    }
    
    error_log('Point 7 - Nombre de userConditions: ' . count($userConditions));
    error_log('Point 7 - userConditions: ' . json_encode($userConditions, JSON_UNESCAPED_UNICODE));
    
    if (!empty($userConditions)) {
        $combinedCondition = '(' . implode(' AND ', $userConditions) . ')';
        $whereConditions[] = $combinedCondition;
        error_log('Point 7 - Condition combinée ajoutée: ' . $combinedCondition);
    } else {
        error_log('⚠️ Point 7 - userConditions est vide !');
    }
} else {
    error_log('Point 4 - searchUser est vide, on ne rentre pas dans le bloc if');
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

error_log('Point 8 - SQL de base: ' . $sql);
error_log('Point 8 - whereConditions: ' . json_encode($whereConditions, JSON_UNESCAPED_UNICODE));
error_log('Point 8 - Nombre de whereConditions: ' . count($whereConditions));

if (!empty($whereConditions)) {
    $whereClause = ' WHERE ' . implode(' AND ', $whereConditions);
    $sql .= $whereClause;
    error_log('Point 8 - WHERE clause ajoutée: ' . $whereClause);
} else {
    error_log('Point 8 - Aucune condition WHERE');
}

// LIMIT doit être un entier, pas un paramètre nommé (compatibilité PDO)
$limit = (int)HISTORIQUE_PAGE_LIMIT;
$sql .= ' ORDER BY h.date_action DESC LIMIT ' . $limit;

error_log('Point 9 - SQL FINAL: ' . $sql);
error_log('Point 9 - Params FINAUX: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
error_log('Point 9 - Nombre de params: ' . count($params));

// ====== Exécution de la requête ======
$historique = [];
$dbError = null;

try {
    error_log('Point 10 - Début try/catch');
    error_log('Point 10 - Params empty?: ' . (empty($params) ? 'OUI' : 'NON'));
    
    // Vérification de la requête SQL avant exécution (debug)
    if (empty($params)) {
        error_log('Point 11 - Exécution sans paramètres');
        // Pas de paramètres, exécution directe
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        error_log('Point 11 - Requête exécutée sans paramètres');
    } else {
        error_log('Point 12 - Exécution avec paramètres');
        $stmt = $pdo->prepare($sql);
        error_log('Point 12 - Requête préparée');
        
        // Bind des paramètres un par un pour plus de contrôle
        foreach ($params as $key => $value) {
            error_log('Point 13 - Binding: ' . $key . ' => ' . var_export($value, true));
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
            error_log('Point 13 - Bind réussi pour: ' . $key);
        }
        
        error_log('Point 14 - Tous les paramètres bindés, exécution...');
        $stmt->execute();
        error_log('Point 14 - Requête exécutée avec succès');
    }
    
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    error_log('Point 15 - Résultats récupérés: ' . count($historique) . ' lignes');
    
    if (count($historique) > 0) {
        error_log('Point 15 - Premier résultat: ' . json_encode($historique[0], JSON_UNESCAPED_UNICODE));
    }
    
} catch (PDOException $e) {
    $dbError = 'Impossible de charger l\'historique pour le moment.';
    // Log détaillé pour le débogage
    $errorDetails = [
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'sql' => $sql,
        'params' => $params,
        'searchUser' => $searchUser,
        'rawUser' => $rawUser ?? '',
    ];
    
    error_log('❌ ERREUR SQL (historique): ' . $e->getMessage());
    error_log('❌ Code erreur: ' . $e->getCode());
    error_log('❌ SQL: ' . $sql);
    error_log('❌ Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE));
    error_log('❌ SearchUser: ' . $searchUser);
    error_log('❌ Stack trace: ' . $e->getTraceAsString());
    
    // AFFICHAGE TEMPORAIRE POUR DÉBOGAGE (à retirer en production)
    $dbError .= ' [DEBUG: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . ']';
    
    $historique = [];
}

error_log('=== FIN DÉBOGAGE HISTORIQUE ===');

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
                            <?php if (isset($errorDetails)): ?>
                                <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; font-size: 0.85rem; text-align: left; max-width: 800px; margin-left: auto; margin-right: auto;">
                                    <strong>Détails de l'erreur (DEBUG):</strong><br>
                                    <strong>Message:</strong> <?= h($errorDetails['message']) ?><br>
                                    <strong>Code:</strong> <?= h((string)$errorDetails['code']) ?><br>
                                    <strong>SearchUser:</strong> <?= h($errorDetails['searchUser']) ?><br>
                                    <strong>RawUser:</strong> <?= h($errorDetails['rawUser']) ?><br>
                                    <strong>SQL:</strong> <code style="word-break: break-all;"><?= h($errorDetails['sql']) ?></code><br>
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
                                    <span class="details-text"><?= h($entree['details']) ?></span>
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
                                <span class="value"><?= h($entree['details']) ?></span>
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
