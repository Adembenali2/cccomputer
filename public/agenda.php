<?php
// /public/agenda.php
// Agenda affichant les SAV et livraisons prévus par jour pour chaque utilisateur

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('agenda', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Les fonctions h(), formatDate(), currentUserId(), currentUserRole() sont définies dans includes/helpers.php

$currentUserId = currentUserId();
$currentUserRole = currentUserRole();
$isAdmin = ($currentUserRole === 'Admin' || $currentUserRole === 'Dirigeant');
$isTechnicien = ($currentUserRole === 'Technicien');
$isLivreur = ($currentUserRole === 'Livreur');

// Paramètres de filtrage
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
$filterDate = $_GET['date'] ?? date('Y-m-d');
$viewMode = $_GET['view'] ?? 'week'; // 'day', 'week', 'month'

// Validation de la date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $filterDate = date('Y-m-d');
}

// Calculer les dates selon le mode d'affichage
$startDate = $filterDate;
$endDate = $filterDate;

if ($viewMode === 'week') {
    $dateObj = new DateTime($filterDate);
    $dateObj->modify('monday this week');
    $startDate = $dateObj->format('Y-m-d');
    $dateObj->modify('sunday this week');
    $endDate = $dateObj->format('Y-m-d');
} elseif ($viewMode === 'month') {
    $dateObj = new DateTime($filterDate);
    $dateObj->modify('first day of this month');
    $startDate = $dateObj->format('Y-m-d');
    $dateObj->modify('last day of this month');
    $endDate = $dateObj->format('Y-m-d');
}

// Récupérer la liste des utilisateurs pour le filtre
$users = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, Emploi 
        FROM utilisateurs 
        WHERE statut = 'actif' 
        ORDER BY nom, prenom
    ");
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('agenda.php - Erreur récupération utilisateurs: ' . $e->getMessage());
}

// Vérifier si la colonne date_intervention_prevue existe
$hasDateIntervention = false;
try {
    require_once __DIR__ . '/../includes/api_helpers.php';
    $hasDateIntervention = columnExists($pdo, 'sav', 'date_intervention_prevue');
} catch (Throwable $e) {
    error_log('agenda.php - Erreur vérification colonne date_intervention_prevue: ' . $e->getMessage());
}

// Récupérer les SAV
$savs = [];
try {
    // Vérifier si type_panne existe
    $hasTypePanne = false;
    try {
        require_once __DIR__ . '/../includes/api_helpers.php';
        $hasTypePanne = columnExists($pdo, 'sav', 'type_panne');
    } catch (Throwable $e) {
        error_log('agenda.php - Erreur vérification colonne type_panne: ' . $e->getMessage());
    }
    
    $selectTypePanne = $hasTypePanne ? "s.type_panne," : "";
    $selectDateIntervention = $hasDateIntervention ? "s.date_intervention_prevue," : "";
    
    // Utiliser la requête simple qui fonctionne (basée sur celle du diagnostic)
    // mais avec tous les champs nécessaires pour l'affichage
    $sqlSav = "
        SELECT 
            s.id,
            s.id_client,
            s.id_technicien,
            s.reference,
            s.description,
            s.date_ouverture,
            {$selectDateIntervention}
            s.statut,
            s.priorite,
            {$selectTypePanne}
            c.raison_sociale AS client_nom,
            c.adresse AS client_adresse,
            c.ville AS client_ville,
            c.code_postal AS client_code_postal,
            u.nom AS technicien_nom,
            u.prenom AS technicien_prenom,
            u.Emploi AS technicien_role
        FROM sav s
        LEFT JOIN clients c ON c.id = s.id_client
        LEFT JOIN utilisateurs u ON u.id = s.id_technicien
        WHERE s.statut NOT IN ('resolu', 'annule')
        AND s.date_ouverture BETWEEN :start_date AND :end_date
    ";
    
    $paramsSav = [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ];
    
    // Filtrer par technicien si spécifié dans le filtre utilisateur
    if ($filterUser) {
        $sqlSav .= " AND s.id_technicien = :tech_id";
        $paramsSav[':tech_id'] = $filterUser;
    }
    
    $sqlSav .= " ORDER BY s.date_ouverture ASC, s.priorite DESC";
    
    $stmtSav = $pdo->prepare($sqlSav);
    $stmtSav->execute($paramsSav);
    $savs = $stmtSav->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('agenda.php - Erreur récupération SAV: ' . $e->getMessage());
}

// Récupérer les livraisons
$livraisons = [];
try {
    // Utiliser une requête simple qui fonctionne (similaire à celle du diagnostic)
    $sqlLiv = "
        SELECT 
            l.id,
            l.id_client,
            l.id_livreur,
            l.reference,
            l.objet,
            l.date_prevue,
            l.date_reelle,
            l.statut,
            l.adresse_livraison,
            c.raison_sociale AS client_nom,
            c.ville AS client_ville,
            c.code_postal AS client_code_postal,
            u.nom AS livreur_nom,
            u.prenom AS livreur_prenom,
            u.Emploi AS livreur_role
        FROM livraisons l
        LEFT JOIN clients c ON c.id = l.id_client
        LEFT JOIN utilisateurs u ON u.id = l.id_livreur
        WHERE l.statut NOT IN ('livree', 'annulee')
        AND l.date_prevue BETWEEN :start_date AND :end_date
    ";
    
    $paramsLiv = [
        ':start_date' => $startDate,
        ':end_date' => $endDate
    ];
    
    // Filtrer par livreur si spécifié dans le filtre utilisateur
    if ($filterUser) {
        $sqlLiv .= " AND l.id_livreur = :livreur_id";
        $paramsLiv[':livreur_id'] = $filterUser;
    }
    
    $sqlLiv .= " ORDER BY l.date_prevue ASC, l.id ASC";
    
    $stmtLiv = $pdo->prepare($sqlLiv);
    $stmtLiv->execute($paramsLiv);
    $livraisons = $stmtLiv->fetchAll(PDO::FETCH_ASSOC);
    
    // Sauvegarder les données
    $livraisonsAfterQuery = $livraisons;
} catch (PDOException $e) {
    error_log('agenda.php - Erreur récupération livraisons: ' . $e->getMessage());
}

// Sauvegarder les données APRÈS les requêtes (y compris les requêtes simplifiées)
// Utiliser les variables sauvegardées si elles existent, sinon utiliser les originaux
$savsBackup = isset($savsAfterQuery) ? $savsAfterQuery : $savs;
$livraisonsBackup = isset($livraisonsAfterQuery) ? $livraisonsAfterQuery : $livraisons;

// Grouper par date, puis par utilisateur, puis par client
$agendaByDate = [];

// Fonction pour obtenir l'ID utilisateur d'un SAV
function getSavUserId($sav) {
    return isset($sav['id_technicien']) ? (int)$sav['id_technicien'] : null;
}

// Fonction pour obtenir l'ID utilisateur d'une livraison
function getLivUserId($liv) {
    return isset($liv['id_livreur']) ? (int)$liv['id_livreur'] : null;
}

// Fonction pour obtenir l'ID client d'un SAV
function getSavClientId($sav) {
    return isset($sav['id_client']) ? (int)$sav['id_client'] : null;
}

// Fonction pour obtenir l'ID client d'une livraison
function getLivClientId($liv) {
    return isset($liv['id_client']) ? (int)$liv['id_client'] : null;
}

// Fonction pour obtenir le nom complet de l'utilisateur
function getUserFullName($user) {
    return trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''));
}

// Grouper les SAV par date, utilisateur et client
foreach ($savs as $sav) {
    $date = ($hasDateIntervention && !empty($sav['date_intervention_prevue'])) 
        ? $sav['date_intervention_prevue'] 
        : $sav['date_ouverture'];
    
    if (!isset($agendaByDate[$date])) {
        $agendaByDate[$date] = ['users' => []];
    }
    
    $userId = getSavUserId($sav);
    $userKey = $userId ? 'user_' . $userId : 'user_unassigned';
    $userName = $userId ? trim(($sav['technicien_prenom'] ?? '') . ' ' . ($sav['technicien_nom'] ?? '')) : 'Non assigné';
    
    if (!isset($agendaByDate[$date]['users'][$userKey])) {
        $agendaByDate[$date]['users'][$userKey] = [
            'id' => $userId,
            'name' => $userName,
            'role' => $sav['technicien_role'] ?? 'Technicien',
            'clients' => []
        ];
    }
    
    $clientId = getSavClientId($sav);
    $clientKey = $clientId ? 'client_' . $clientId : 'client_unknown';
    $clientName = $sav['client_nom'] ?? 'Client inconnu';
    
    if (!isset($agendaByDate[$date]['users'][$userKey]['clients'][$clientKey])) {
        $agendaByDate[$date]['users'][$userKey]['clients'][$clientKey] = [
            'id' => $clientId,
            'name' => $clientName,
            'savs' => [],
            'livraisons' => []
        ];
    }
    
    $agendaByDate[$date]['users'][$userKey]['clients'][$clientKey]['savs'][] = $sav;
}

// Grouper les livraisons par date, utilisateur et client
foreach ($livraisons as $liv) {
    $date = $liv['date_prevue'];
    
    if (!isset($agendaByDate[$date])) {
        $agendaByDate[$date] = ['users' => []];
    }
    
    $userId = getLivUserId($liv);
    $userKey = $userId ? 'user_' . $userId : 'user_unassigned';
    $userName = $userId ? trim(($liv['livreur_prenom'] ?? '') . ' ' . ($liv['livreur_nom'] ?? '')) : 'Non assigné';
    
    if (!isset($agendaByDate[$date]['users'][$userKey])) {
        $agendaByDate[$date]['users'][$userKey] = [
            'id' => $userId,
            'name' => $userName,
            'role' => $liv['livreur_role'] ?? 'Livreur',
            'clients' => []
        ];
    }
    
    $clientId = getLivClientId($liv);
    $clientKey = $clientId ? 'client_' . $clientId : 'client_unknown';
    $clientName = $liv['client_nom'] ?? 'Client inconnu';
    
    if (!isset($agendaByDate[$date]['users'][$userKey]['clients'][$clientKey])) {
        $agendaByDate[$date]['users'][$userKey]['clients'][$clientKey] = [
            'id' => $clientId,
            'name' => $clientName,
            'savs' => [],
            'livraisons' => []
        ];
    }
    
    $agendaByDate[$date]['users'][$userKey]['clients'][$clientKey]['livraisons'][] = $liv;
}

// Trier les dates
ksort($agendaByDate);

// Fusionner les utilisateurs qui ont le même ID mais des rôles différents
// et trier les utilisateurs et clients dans chaque date
foreach ($agendaByDate as $date => &$dateData) {
    // Fusionner les utilisateurs avec le même ID
    $mergedUsers = [];
    foreach ($dateData['users'] as $userKey => $userData) {
        $userId = $userData['id'];
        if ($userId === null) {
            // Utilisateurs non assignés : garder séparés par type
            $mergedUsers[$userKey] = $userData;
        } else {
            $key = 'user_' . $userId;
            if (!isset($mergedUsers[$key])) {
                $mergedUsers[$key] = $userData;
            } else {
                // Fusionner les clients
                foreach ($userData['clients'] as $clientKey => $clientData) {
                    if (!isset($mergedUsers[$key]['clients'][$clientKey])) {
                        $mergedUsers[$key]['clients'][$clientKey] = $clientData;
                    } else {
                        // Fusionner les SAV et livraisons
                        $mergedUsers[$key]['clients'][$clientKey]['savs'] = array_merge(
                            $mergedUsers[$key]['clients'][$clientKey]['savs'] ?? [],
                            $clientData['savs'] ?? []
                        );
                        $mergedUsers[$key]['clients'][$clientKey]['livraisons'] = array_merge(
                            $mergedUsers[$key]['clients'][$clientKey]['livraisons'] ?? [],
                            $clientData['livraisons'] ?? []
                        );
                    }
                }
                // Mettre à jour le rôle si nécessaire (afficher les deux rôles)
                if ($mergedUsers[$key]['role'] !== $userData['role']) {
                    $mergedUsers[$key]['role'] = $mergedUsers[$key]['role'] . ' / ' . $userData['role'];
                }
            }
        }
    }
    $dateData['users'] = $mergedUsers;
    
    // Trier les utilisateurs : assignés d'abord, puis non assignés
    uasort($dateData['users'], function($a, $b) {
        if ($a['id'] === null && $b['id'] !== null) return 1;
        if ($a['id'] !== null && $b['id'] === null) return -1;
        return strcmp($a['name'], $b['name']);
    });
    
    // Trier les clients dans chaque utilisateur
    foreach ($dateData['users'] as &$userData) {
        uasort($userData['clients'], function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    }
}
unset($dateData);

// Statistiques
$totalSavs = count($savs);
$totalLivraisons = count($livraisons);

// Debug : Vérifier s'il y a des SAV/livraisons dans la base (pour diagnostic)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Agenda - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/maps.css">
    <link rel="stylesheet" href="/assets/css/agenda.css">
</head>
<body class="page-maps page-agenda">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Agenda</h1>
        <p class="page-sub">
            Visualisez tous les SAV et livraisons prévus par jour pour chaque utilisateur avec leurs détails complets.
        </p>
    </header>

    <section class="maps-layout">
        <!-- PANNEAU GAUCHE : FILTRES -->
        <aside class="maps-panel" aria-label="Filtres de l'agenda">
            <h2>Filtres</h2>
            
            <!-- Mode d'affichage -->
            <div>
                <div class="section-title">Vue</div>
                <div class="btn-group" style="flex-direction: column; gap: 0.3rem;">
                    <a href="/public/agenda.php?view=day&date=<?= h($filterDate) ?><?= $filterUser ? '&user_id=' . $filterUser : '' ?>"
                       class="btn <?= $viewMode === 'day' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📅 Jour
                    </a>
                    <a href="/public/agenda.php?view=week&date=<?= h($filterDate) ?><?= $filterUser ? '&user_id=' . $filterUser : '' ?>"
                       class="btn <?= $viewMode === 'week' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📆 Semaine
                    </a>
                    <a href="/public/agenda.php?view=month&date=<?= h($filterDate) ?><?= $filterUser ? '&user_id=' . $filterUser : '' ?>"
                       class="btn <?= $viewMode === 'month' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📋 Mois
                    </a>
                </div>
            </div>

            <!-- Filtre par utilisateur -->
            <?php if ($isAdmin): ?>
            <div style="margin-top: 1rem;">
                <div class="section-title">Utilisateur</div>
                <select id="filterUser" class="messagerie-select" onchange="filterByUser(this.value)">
                    <option value="">Tous les utilisateurs</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int)$user['id'] ?>" <?= $filterUser === (int)$user['id'] ? 'selected' : '' ?>>
                            <?= h(trim(($user['prenom'] ?? '') . ' ' . ($user['nom'] ?? ''))) ?> (<?= h($user['Emploi'] ?? '') ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
                <div style="margin-top: 1rem;">
                    <div class="section-title">Utilisateur</div>
                    <p class="hint">
                        <?php
                        $currentUser = array_filter($users, function($u) use ($currentUserId) {
                            return (int)$u['id'] === $currentUserId;
                        });
                        $currentUser = reset($currentUser);
                        if ($currentUser):
                        ?>
                            <?= h(trim(($currentUser['prenom'] ?? '') . ' ' . ($currentUser['nom'] ?? ''))) ?> (<?= h($currentUser['Emploi'] ?? '') ?>)
                        <?php else: ?>
                            Vous
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Navigation de dates -->
            <div style="margin-top: 1rem;">
                <div class="section-title">Date</div>
                <div class="btn-group" style="flex-direction: column; gap: 0.3rem;">
                    <input type="date" 
                           id="datePicker" 
                           class="messagerie-input" 
                           value="<?= h($filterDate) ?>"
                           onchange="navigateToDate(this.value)">
                    <div style="display: flex; gap: 0.3rem;">
                        <button type="button" 
                                class="btn secondary" 
                                onclick="navigateDate(-1)"
                                style="flex: 1;">
                            ← Précédent
                        </button>
                        <button type="button" 
                                class="btn secondary" 
                                onclick="navigateDate(1)"
                                style="flex: 1;">
                            Suivant →
                        </button>
                    </div>
                    <button type="button" 
                            class="btn secondary" 
                            onclick="navigateToDate('<?= date('Y-m-d') ?>')"
                            style="width: 100%;">
                        📅 Aujourd'hui
                    </button>
                </div>
            </div>

            <!-- Statistiques - Style maps.php -->
            <div style="margin-top: 1rem;">
                <div class="section-title">Statistiques</div>
                <div class="maps-stats">
                    <div class="maps-stat">
                        <span class="maps-stat-label">SAV</span>
                        <span class="maps-stat-value"><?= h((string)$totalSavs) ?></span>
                    </div>
                    <div class="maps-stat">
                        <span class="maps-stat-label">Livraisons</span>
                        <span class="maps-stat-value"><?= h((string)$totalLivraisons) ?></span>
                    </div>
                    <div class="maps-stat">
                        <span class="maps-stat-label">Total</span>
                        <span class="maps-stat-value"><?= h((string)($totalSavs + $totalLivraisons)) ?></span>
                    </div>
                </div>
            </div>
        </aside>

        <!-- PANNEAU DROIT : AGENDA -->
        <section class="map-wrapper">
            <div class="map-toolbar">
                <div class="map-toolbar-left">
                    <strong>Agenda</strong> – 
                    <?php if ($viewMode === 'day'): ?>
                        <?= h(formatDate($filterDate)) ?>
                    <?php elseif ($viewMode === 'week'): ?>
                        Semaine du <?= h(formatDate($startDate)) ?> au <?= h(formatDate($endDate)) ?>
                    <?php else: ?>
                        <?= h(formatDate($filterDate, 'F Y')) ?>
                    <?php endif; ?>
                    <span style="margin-left: 0.5rem; color: var(--text-secondary);">
                        (<?= h((string)$totalSavs) ?> SAV, <?= h((string)$totalLivraisons) ?> livraison(s))
                    </span>
                </div>
                <div class="map-toolbar-right">
                    <span class="badge">Vue : <?= $viewMode === 'day' ? 'Jour' : ($viewMode === 'week' ? 'Semaine' : 'Mois') ?></span>
                    <?php if ($filterUser): ?>
                        <?php
                        $selectedUser = array_filter($users, function($u) use ($filterUser) {
                            return (int)$u['id'] === $filterUser;
                        });
                        $selectedUser = reset($selectedUser);
                        ?>
                        <span class="badge">Utilisateur : <?= $selectedUser ? h(trim(($selectedUser['prenom'] ?? '') . ' ' . ($selectedUser['nom'] ?? ''))) : 'N/A' ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="agenda-container" style="padding: 1rem; max-height: calc(100vh - 200px); overflow-y: auto;">
                <?php 
                // Debug : Vérifier l'état des variables avant l'affichage
                // Utiliser les sauvegardes si les originaux sont vides
                $savsToDisplay = !empty($savs) ? $savs : $savsBackup;
                $livraisonsToDisplay = !empty($livraisons) ? $livraisons : $livraisonsBackup;
                
                ?>
                
                <?php if (empty($savsToDisplay) && empty($livraisonsToDisplay)): ?>
                    <div class="agenda-empty" style="padding: 3rem 2rem; text-align: center; background: var(--bg-primary); border-radius: var(--radius-lg); border: 1px solid var(--border-color);">
                        <p><strong>Aucun SAV ou livraison prévu pour cette période.</strong></p>
                        <p style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.9rem;">
                            Période sélectionnée : 
                            <?php if ($viewMode === 'day'): ?>
                                <?= h(formatDate($filterDate)) ?>
                            <?php elseif ($viewMode === 'week'): ?>
                                Semaine du <?= h(formatDate($startDate)) ?> au <?= h(formatDate($endDate)) ?>
                            <?php else: ?>
                                Mois de <?= h(formatDate($filterDate, 'F Y')) ?>
                            <?php endif; ?>
                        </p>
                        <p style="margin-top: 0.5rem; color: var(--text-secondary); font-size: 0.9rem;">
                            Suggestions :
                            <ul style="margin-top: 0.5rem; padding-left: 1.5rem; text-align: left; display: inline-block;">
                                <li>Essayez de changer la date ou la période (jour/semaine/mois)</li>
                                <li>Vérifiez que les SAV/livraisons ne sont pas tous résolus/annulés</li>
                                <?php if (!$isAdmin): ?>
                                    <li>Vérifiez que vous avez des SAV/livraisons assignés à votre compte</li>
                                <?php endif; ?>
                            </ul>
                        </p>
                        <?php if (!empty($debugInfo)): ?>
                            <div style="margin-top: 1rem; padding: 1rem; background: #f3f4f6; border-radius: 4px; font-size: 0.85rem;">
                                <strong>Informations de diagnostic :</strong>
                                <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
                                    <li>SAV dans la base (non résolus/annulés) : <?= h((string)($debugInfo['total_savs_db'] ?? 0)) ?></li>
                                    <li>Livraisons dans la base (non livrées/annulées) : <?= h((string)($debugInfo['total_livraisons_db'] ?? 0)) ?></li>
                                    <?php if (!empty($debugInfo['sav_dates'])): ?>
                                        <li>Dates SAV : du <?= h($debugInfo['sav_dates']['min_date'] ?? 'N/A') ?> au <?= h($debugInfo['sav_dates']['max_date'] ?? 'N/A') ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($debugInfo['livraison_dates'])): ?>
                                        <li>Dates livraisons : du <?= h($debugInfo['livraison_dates']['min_date'] ?? 'N/A') ?> au <?= h($debugInfo['livraison_dates']['max_date'] ?? 'N/A') ?></li>
                                    <?php endif; ?>
                                    <?php if (!empty($debugInfo['savs_in_period'])): ?>
                                        <li>SAV dans la période (<?= count($debugInfo['savs_in_period']) ?>) :
                                            <ul style="margin-top: 0.3rem; padding-left: 1.5rem;">
                                                <?php foreach ($debugInfo['savs_in_period'] as $savDebug): ?>
                                                    <li>
                                                        <?= h($savDebug['reference']) ?> - 
                                                        Date: <?= h($savDebug['date_ouverture']) ?> - 
                                                        Technicien: <?= $savDebug['id_technicien'] ? h((string)$savDebug['id_technicien']) : 'Non assigné' ?> - 
                                                        Statut: <?= h($savDebug['statut']) ?>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (!empty($debugInfo['user_info'])): ?>
                                        <li>Votre rôle : <?= h($debugInfo['user_info']['currentUserRole'] ?? 'Non défini') ?> 
                                            (Admin: <?= $debugInfo['user_info']['isAdmin'] ? 'Oui' : 'Non' ?>, 
                                            Technicien: <?= $debugInfo['user_info']['isTechnicien'] ? 'Oui' : 'Non' ?>, 
                                            Livreur: <?= $debugInfo['user_info']['isLivreur'] ? 'Oui' : 'Non' ?>)
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php 
                    // Labels et couleurs
                    $prioriteColors = [
                        'urgente' => '#ef4444',
                        'haute' => '#f97316',
                        'normale' => '#16a34a',
                        'basse' => '#6b7280'
                    ];
                    $prioriteLabels = [
                        'urgente' => 'Urgente',
                        'haute' => 'Haute',
                        'normale' => 'Normale',
                        'basse' => 'Basse'
                    ];
                    $statutLabels = [
                        'ouvert' => 'Ouvert',
                        'en_cours' => 'En cours',
                        'resolu' => 'Résolu',
                        'annule' => 'Annulé',
                        'planifiee' => 'Planifiée',
                        'livree' => 'Livrée',
                        'annulee' => 'Annulée'
                    ];
                    $statutColors = [
                        'planifiee' => '#6b7280',
                        'en_cours' => '#3b82f6',
                        'livree' => '#16a34a',
                        'annulee' => '#ef4444',
                        'ouvert' => '#6b7280',
                        'resolu' => '#16a34a',
                        'annule' => '#ef4444'
                    ];
                    $typePanneLabels = [
                        'logiciel' => 'Logiciel',
                        'materiel' => 'Matériel',
                        'piece_rechangeable' => 'Pièce rechangeable'
                    ];
                    
                    // Fusionner tous les SAV et livraisons dans un seul tableau pour affichage simple
                    $allItems = [];
                    
                    // Ajouter tous les SAV
                    foreach ($savsToDisplay as $sav) {
                        $date = ($hasDateIntervention && !empty($sav['date_intervention_prevue'])) 
                            ? $sav['date_intervention_prevue'] 
                            : $sav['date_ouverture'];
                        $allItems[] = [
                            'type' => 'sav',
                            'date' => $date,
                            'data' => $sav
                        ];
                    }
                    
                    // Ajouter toutes les livraisons
                    foreach ($livraisonsToDisplay as $liv) {
                        $allItems[] = [
                            'type' => 'livraison',
                            'date' => $liv['date_prevue'],
                            'data' => $liv
                        ];
                    }
                    
                    // Trier par date
                    usort($allItems, function($a, $b) {
                        return strcmp($a['date'], $b['date']);
                    });
                    ?>
                    
                    <div class="selected-clients" style="max-height: none; padding: 0;">
                        <?php foreach ($allItems as $item): ?>
                            <?php if ($item['type'] === 'sav'): ?>
                                <?php
                                $sav = $item['data'];
                                $priorite = $sav['priorite'] ?? 'normale';
                                $clientAdresse = trim(($sav['client_adresse'] ?? '') . ' ' . ($sav['client_code_postal'] ?? '') . ' ' . ($sav['client_ville'] ?? ''));
                                $clientName = $sav['client_nom'] ?? 'Client inconnu';
                                $description = mb_substr($sav['description'] ?? '', 0, 150);
                                if (mb_strlen($sav['description'] ?? '') > 150) $description .= '...';
                                $dateDisplay = '—';
                                if ($hasDateIntervention && !empty($sav['date_intervention_prevue'])) {
                                    $dateDisplay = formatDate($sav['date_intervention_prevue']);
                                } elseif (!empty($sav['date_ouverture'])) {
                                    $dateDisplay = formatDate($sav['date_ouverture']);
                                }
                                ?>
                                <div class="selected-client-chip" 
                                     data-sav-id="<?= (int)$sav['id'] ?>"
                                     onclick="window.location.href='/public/sav.php?ref=<?= urlencode($sav['reference']) ?>'"
                                     style="border-left: 4px solid <?= h($prioriteColors[$priorite] ?? '#6b7280') ?>;">
                                    <div class="selected-client-main">
                                        <strong>🔧 <?= h($sav['reference']) ?> — <?= h($clientName) ?></strong>
                                        <span>
                                            <?= h($description) ?>
                                            <?php if ($clientAdresse): ?>
                                                • <?= h($clientAdresse) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($sav['type_panne'])): ?>
                                                • Type: <?= h($typePanneLabels[$sav['type_panne']] ?? $sav['type_panne']) ?>
                                            <?php endif; ?>
                                        </span>
                                        <small style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 0.2rem; display: block;">
                                            Statut: <?= h($statutLabels[$sav['statut']] ?? $sav['statut']) ?>
                                            • Date: <?= h($dateDisplay) ?>
                                            <?php if (!empty($sav['technicien_nom']) || !empty($sav['technicien_prenom'])): ?>
                                                • Technicien: <?= h(trim(($sav['technicien_prenom'] ?? '') . ' ' . ($sav['technicien_nom'] ?? ''))) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="selected-client-controls">
                                        <span class="badge" style="background: <?= h($prioriteColors[$priorite] ?? '#6b7280') ?>; color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                            <?= h($prioriteLabels[$priorite] ?? $priorite) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php
                                $liv = $item['data'];
                                $clientAdresse = trim(($liv['client_ville'] ?? '') . ' ' . ($liv['client_code_postal'] ?? ''));
                                $clientName = $liv['client_nom'] ?? 'Client inconnu';
                                $adresseLivraison = $liv['adresse_livraison'] ?? $clientAdresse;
                                $objet = mb_substr($liv['objet'] ?? '', 0, 150);
                                if (mb_strlen($liv['objet'] ?? '') > 150) $objet .= '...';
                                ?>
                                <div class="selected-client-chip" 
                                     data-livraison-id="<?= (int)$liv['id'] ?>"
                                     onclick="window.location.href='/public/livraison.php?ref=<?= urlencode($liv['reference']) ?>'"
                                     style="border-left: 4px solid <?= h($statutColors[$liv['statut']] ?? '#6b7280') ?>;">
                                    <div class="selected-client-main">
                                        <strong>📦 <?= h($liv['reference']) ?> — <?= h($clientName) ?></strong>
                                        <span>
                                            <?= h($objet) ?>
                                            <?php if ($adresseLivraison): ?>
                                                • <?= h($adresseLivraison) ?>
                                            <?php endif; ?>
                                        </span>
                                        <small style="color: var(--text-secondary); font-size: 0.75rem; margin-top: 0.2rem; display: block;">
                                            Statut: <?= h($statutLabels[$liv['statut']] ?? $liv['statut']) ?>
                                            • Date prévue: <?= h(formatDate($liv['date_prevue'] ?? null)) ?>
                                            <?php if (!empty($liv['date_reelle'])): ?>
                                                • Date réelle: <?= h(formatDate($liv['date_reelle'])) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($liv['livreur_nom']) || !empty($liv['livreur_prenom'])): ?>
                                                • Livreur: <?= h(trim(($liv['livreur_prenom'] ?? '') . ' ' . ($liv['livreur_nom'] ?? ''))) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>
                                    <div class="selected-client-controls">
                                        <span class="badge" style="background: <?= h($statutColors[$liv['statut']] ?? '#6b7280') ?>; color: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; font-weight: 600;">
                                            <?= h($statutLabels[$liv['statut']] ?? $liv['statut']) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </section>
</main>

<script>
// Navigation de dates
function navigateDate(days) {
    const currentDate = document.getElementById('datePicker').value;
    const date = new Date(currentDate);
    date.setDate(date.getDate() + days);
    const newDate = date.toISOString().split('T')[0];
    navigateToDate(newDate);
}

function navigateToDate(date) {
    const viewMode = '<?= h($viewMode) ?>';
    const userId = <?= $filterUser ? (int)$filterUser : 'null' ?>;
    let url = `/public/agenda.php?view=${viewMode}&date=${date}`;
    if (userId) {
        url += `&user_id=${userId}`;
    }
    window.location.href = url;
}

function filterByUser(userId) {
    const viewMode = '<?= h($viewMode) ?>';
    const date = '<?= h($filterDate) ?>';
    let url = `/public/agenda.php?view=${viewMode}&date=${date}`;
    if (userId) {
        url += `&user_id=${userId}`;
    }
    window.location.href = url;
}
</script>
</body>
</html>

