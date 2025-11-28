<?php
// /public/agenda.php
// Agenda affichant les SAV et livraisons prévus par jour pour chaque utilisateur

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('agenda', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';

// La fonction h() est définie dans includes/helpers.php

function currentUserId(): ?int {
    if (isset($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
    if (isset($_SESSION['user_id']))    return (int)$_SESSION['user_id'];
    return null;
}

function currentUserRole(): ?string {
    if (isset($_SESSION['emploi'])) return $_SESSION['emploi'];
    if (isset($_SESSION['user']['Emploi'])) return $_SESSION['user']['Emploi'];
    if (isset($_SESSION['user']['emploi'])) return $_SESSION['user']['emploi'];
    return null;
}

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
    // Calculer une date de début pour inclure les SAV en cours (7 jours avant la période ou maximum 30 jours en arrière)
    $dateObjMin = new DateTime($startDate);
    $dateObjMin->modify('-7 days');
    $minDateForOngoing = $dateObjMin->format('Y-m-d');
    $dateObjLimit = new DateTime();
    $dateObjLimit->modify('-30 days');
    $limitDate = $dateObjLimit->format('Y-m-d');
    $actualMinDate = max($minDateForOngoing, $limitDate);
    
    // Calculer une date limite pour inclure les SAV en cours (90 jours en arrière maximum)
    $dateObjLimit = new DateTime($startDate);
    $dateObjLimit->modify('-90 days');
    $maxOngoingDate = $dateObjLimit->format('Y-m-d');
    
    if ($hasDateIntervention) {
        // Inclure les SAV :
        // 1. Date d'ouverture dans la période
        // 2. Date d'intervention prévue dans la période
        // 3. SAV non résolus/annulés ouverts avant ou pendant la période (dans les 90 derniers jours)
        // Note: Le filtre sur statut NOT IN ('resolu', 'annule') est appliqué plus tard
        $whereSav = [
            "((s.date_ouverture BETWEEN :start_date AND :end_date)
              OR (s.date_intervention_prevue IS NOT NULL AND s.date_intervention_prevue BETWEEN :start_date2 AND :end_date2)
              OR (s.date_ouverture >= :max_ongoing_date AND s.date_ouverture <= :end_date)
              OR (s.date_intervention_prevue IS NOT NULL AND s.date_intervention_prevue >= :max_ongoing_date2 AND s.date_intervention_prevue <= :end_date3))"
        ];
        $paramsSav = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':start_date2' => $startDate,
            ':end_date2' => $endDate,
            ':max_ongoing_date' => $maxOngoingDate,
            ':max_ongoing_date2' => $maxOngoingDate,
            ':end_date3' => $endDate
        ];
        $dateOrderBy = "COALESCE(s.date_intervention_prevue, s.date_ouverture)";
        $selectDateIntervention = "s.date_intervention_prevue,";
    } else {
        // Inclure les SAV :
        // 1. Date d'ouverture dans la période
        // 2. SAV non résolus/annulés ouverts avant ou pendant la période (dans les 90 derniers jours)
        // Note: Le filtre sur statut NOT IN ('resolu', 'annule') est appliqué plus tard
        $whereSav = [
            "(s.date_ouverture BETWEEN :start_date AND :end_date
              OR (s.date_ouverture >= :max_ongoing_date AND s.date_ouverture <= :end_date))"
        ];
        $paramsSav = [
            ':start_date' => $startDate,
            ':end_date' => $endDate,
            ':max_ongoing_date' => $maxOngoingDate
        ];
        $dateOrderBy = "s.date_ouverture";
        $selectDateIntervention = "";
    }
    
    // Filtrer par technicien si spécifié
    if ($filterUser) {
        // Filtrer par technicien spécifique
        $whereSav[] = "s.id_technicien = :tech_id";
        $paramsSav[':tech_id'] = $filterUser;
    } elseif (!$isAdmin) {
        // Si technicien, afficher uniquement les SAV assignés à l'utilisateur
        if ($isTechnicien) {
            $whereSav[] = "s.id_technicien = :current_user_id";
            $paramsSav[':current_user_id'] = $currentUserId;
        } else {
            // Si pas technicien, ne pas afficher de SAV
            $whereSav[] = "1 = 0"; // Condition toujours fausse pour ne rien retourner
        }
    }
    // Pour les admins, pas de filtre sur id_technicien (ils voient tous les SAV)
    
    // Exclure les SAV résolus et annulés (déjà géré dans la condition de date pour les en cours)
    $whereSav[] = "s.statut NOT IN ('resolu', 'annule')";
    
    // Vérifier si type_panne existe
    $hasTypePanne = false;
    try {
        require_once __DIR__ . '/../includes/api_helpers.php';
        $hasTypePanne = columnExists($pdo, 'sav', 'type_panne');
    } catch (Throwable $e) {
        error_log('agenda.php - Erreur vérification colonne type_panne: ' . $e->getMessage());
    }
    
    $selectTypePanne = $hasTypePanne ? "s.type_panne," : "";
    
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
        WHERE " . implode(' AND ', $whereSav) . "
        ORDER BY {$dateOrderBy} ASC, s.priorite DESC
    ";
    
    // Debug : Afficher la requête SQL et les paramètres
    error_log('agenda.php - SQL SAV: ' . $sqlSav);
    error_log('agenda.php - Paramètres SAV: ' . json_encode($paramsSav));
    error_log('agenda.php - Période: ' . $startDate . ' à ' . $endDate);
    error_log('agenda.php - hasDateIntervention: ' . ($hasDateIntervention ? 'true' : 'false'));
    
    $stmtSav = $pdo->prepare($sqlSav);
    $stmtSav->execute($paramsSav);
    $savs = $stmtSav->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug temporaire
    if (empty($savs)) {
        error_log('agenda.php - Aucun SAV trouvé après exécution');
        error_log('agenda.php - isAdmin: ' . ($isAdmin ? 'true' : 'false') . ', isTechnicien: ' . ($isTechnicien ? 'true' : 'false') . ', currentUserId: ' . $currentUserId . ', currentUserRole: ' . ($currentUserRole ?? 'null'));
        
        // Test direct de la requête
        try {
            $testSql = "SELECT COUNT(*) as cnt FROM sav WHERE date_ouverture BETWEEN :start_date AND :end_date AND statut NOT IN ('resolu', 'annule')";
            $testStmt = $pdo->prepare($testSql);
            $testStmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
            $testResult = $testStmt->fetch(PDO::FETCH_ASSOC);
            error_log('agenda.php - Test direct (dans période): ' . ($testResult['cnt'] ?? 0) . ' SAV trouvés');
            
            // Test avec SAV en cours
            $testSql2 = "SELECT COUNT(*) as cnt FROM sav WHERE date_ouverture >= :max_ongoing_date AND date_ouverture <= :end_date AND statut NOT IN ('resolu', 'annule')";
            $testStmt2 = $pdo->prepare($testSql2);
            $testStmt2->execute([':max_ongoing_date' => $maxOngoingDate, ':end_date' => $endDate]);
            $testResult2 = $testStmt2->fetch(PDO::FETCH_ASSOC);
            error_log('agenda.php - Test direct (en cours): ' . ($testResult2['cnt'] ?? 0) . ' SAV trouvés (maxOngoingDate: ' . $maxOngoingDate . ')');
            
            // Test tous les SAV non résolus
            $testSql3 = "SELECT id, reference, date_ouverture, statut, id_technicien FROM sav WHERE statut NOT IN ('resolu', 'annule') ORDER BY date_ouverture DESC LIMIT 5";
            $testStmt3 = $pdo->query($testSql3);
            $testResult3 = $testStmt3->fetchAll(PDO::FETCH_ASSOC);
            error_log('agenda.php - Derniers SAV non résolus: ' . json_encode($testResult3));
        } catch (PDOException $e) {
            error_log('agenda.php - Erreur test direct: ' . $e->getMessage());
        }
    } else {
        error_log('agenda.php - ' . count($savs) . ' SAV trouvés');
    }
} catch (PDOException $e) {
    error_log('agenda.php - Erreur récupération SAV: ' . $e->getMessage());
}

// Récupérer les livraisons
$livraisons = [];
try {
    // Calculer une date de début pour inclure les livraisons en cours
    $dateObjMinLiv = new DateTime($startDate);
    $dateObjMinLiv->modify('-7 days');
    $minDateForOngoingLiv = $dateObjMinLiv->format('Y-m-d');
    $dateObjLimitLiv = new DateTime();
    $dateObjLimitLiv->modify('-30 days');
    $limitDateLiv = $dateObjLimitLiv->format('Y-m-d');
    $actualMinDateLiv = max($minDateForOngoingLiv, $limitDateLiv);
    
    // Inclure les livraisons dans la période OU les livraisons en cours depuis une date raisonnable jusqu'à la fin de la période
    $whereLiv = [
        "(l.date_prevue BETWEEN :start_date AND :end_date 
          OR (l.date_prevue >= :min_ongoing_date AND l.date_prevue <= :end_date AND l.statut IN ('planifiee', 'en_cours')))"
    ];
    $paramsLiv = [
        ':start_date' => $startDate,
        ':end_date' => $endDate,
        ':min_ongoing_date' => $actualMinDateLiv
    ];
    
    // Filtrer par livreur si spécifié
    if ($filterUser) {
        // Filtrer par livreur spécifique
        $whereLiv[] = "l.id_livreur = :livreur_id";
        $paramsLiv[':livreur_id'] = $filterUser;
    } elseif (!$isAdmin) {
        // Si livreur, afficher uniquement les livraisons assignées à l'utilisateur
        if ($isLivreur) {
            $whereLiv[] = "l.id_livreur = :current_user_id";
            $paramsLiv[':current_user_id'] = $currentUserId;
        } else {
            // Si pas livreur, ne pas afficher de livraisons
            $whereLiv[] = "1 = 0"; // Condition toujours fausse pour ne rien retourner
        }
    }
    // Pour les admins, pas de filtre sur id_livreur (ils voient toutes les livraisons)
    
    // Exclure les livraisons livrées et annulées (déjà géré dans la condition de date pour les en cours)
    $whereLiv[] = "l.statut NOT IN ('livree', 'annulee')";
    
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
        WHERE " . implode(' AND ', $whereLiv) . "
        ORDER BY l.date_prevue ASC, l.id ASC
    ";
    
    $stmtLiv = $pdo->prepare($sqlLiv);
    $stmtLiv->execute($paramsLiv);
    $livraisons = $stmtLiv->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug temporaire
    if (empty($livraisons)) {
        error_log('agenda.php - Aucune livraison trouvée. SQL: ' . $sqlLiv);
        error_log('agenda.php - Paramètres: ' . json_encode($paramsLiv));
        error_log('agenda.php - isAdmin: ' . ($isAdmin ? 'true' : 'false') . ', isLivreur: ' . ($isLivreur ? 'true' : 'false') . ', currentUserId: ' . $currentUserId . ', currentUserRole: ' . ($currentUserRole ?? 'null'));
        error_log('agenda.php - Période: ' . $startDate . ' à ' . $endDate);
    }
} catch (PDOException $e) {
    error_log('agenda.php - Erreur récupération livraisons: ' . $e->getMessage());
}

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
$debugInfo = [];
if (empty($savs) && empty($livraisons)) {
    try {
        // Compter tous les SAV non résolus/annulés
        $stmtDebug = $pdo->query("SELECT COUNT(*) as total FROM sav WHERE statut NOT IN ('resolu', 'annule')");
        $debugInfo['total_savs_db'] = $stmtDebug->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Compter toutes les livraisons non livrées/annulées
        $stmtDebug = $pdo->query("SELECT COUNT(*) as total FROM livraisons WHERE statut NOT IN ('livree', 'annulee')");
        $debugInfo['total_livraisons_db'] = $stmtDebug->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;
        
        // Vérifier les dates des SAV
        $stmtDebug = $pdo->query("SELECT MIN(date_ouverture) as min_date, MAX(date_ouverture) as max_date FROM sav WHERE statut NOT IN ('resolu', 'annule')");
        $datesSav = $stmtDebug->fetch(PDO::FETCH_ASSOC);
        $debugInfo['sav_dates'] = $datesSav;
        
        // Vérifier les dates des livraisons
        $stmtDebug = $pdo->query("SELECT MIN(date_prevue) as min_date, MAX(date_prevue) as max_date FROM livraisons WHERE statut NOT IN ('livree', 'annulee')");
        $datesLiv = $stmtDebug->fetch(PDO::FETCH_ASSOC);
        $debugInfo['livraison_dates'] = $datesLiv;
        
        // Vérifier les SAV dans la période avec détails
        $stmtDebug = $pdo->prepare("
            SELECT id, reference, date_ouverture, id_technicien, statut 
            FROM sav 
            WHERE statut NOT IN ('resolu', 'annule') 
            AND date_ouverture BETWEEN :start_date AND :end_date
        ");
        $stmtDebug->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        $debugInfo['savs_in_period'] = $stmtDebug->fetchAll(PDO::FETCH_ASSOC);
        
        // Informations sur l'utilisateur
        $debugInfo['user_info'] = [
            'isAdmin' => $isAdmin,
            'isTechnicien' => $isTechnicien,
            'isLivreur' => $isLivreur,
            'currentUserId' => $currentUserId,
            'currentUserRole' => $currentUserRole,
            'filterUser' => $filterUser
        ];
    } catch (PDOException $e) {
        error_log('agenda.php - Erreur debug: ' . $e->getMessage());
    }
}
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
            Visualisez tous les SAV et livraisons prévus par jour pour chaque utilisateur.<br>
            <strong><?= h((string)$totalSavs) ?> SAV</strong> et <strong><?= h((string)$totalLivraisons) ?> livraison(s)</strong> sur la période sélectionnée.
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

            <!-- Statistiques -->
            <div style="margin-top: 1rem;">
                <div class="section-title">Statistiques</div>
                <div class="agenda-stats">
                    <div class="agenda-stat-item">
                        <span class="agenda-stat-label">SAV</span>
                        <span class="agenda-stat-value"><?= h((string)$totalSavs) ?></span>
                    </div>
                    <div class="agenda-stat-item">
                        <span class="agenda-stat-label">Livraisons</span>
                        <span class="agenda-stat-value"><?= h((string)$totalLivraisons) ?></span>
                    </div>
                    <div class="agenda-stat-item">
                        <span class="agenda-stat-label">Total</span>
                        <span class="agenda-stat-value"><?= h((string)($totalSavs + $totalLivraisons)) ?></span>
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
                        <?= h(date('d/m/Y', strtotime($filterDate))) ?>
                    <?php elseif ($viewMode === 'week'): ?>
                        Semaine du <?= h(date('d/m/Y', strtotime($startDate))) ?> au <?= h(date('d/m/Y', strtotime($endDate))) ?>
                    <?php else: ?>
                        <?= h(date('F Y', strtotime($filterDate))) ?>
                    <?php endif; ?>
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

            <div class="agenda-container">
                <?php if (empty($agendaByDate)): ?>
                    <div class="agenda-empty">
                        <p><strong>Aucun SAV ou livraison prévu pour cette période.</strong></p>
                        <p style="margin-top: 1rem; color: #6b7280; font-size: 0.9rem;">
                            Période sélectionnée : 
                            <?php if ($viewMode === 'day'): ?>
                                <?= h(date('d/m/Y', strtotime($filterDate))) ?>
                            <?php elseif ($viewMode === 'week'): ?>
                                Semaine du <?= h(date('d/m/Y', strtotime($startDate))) ?> au <?= h(date('d/m/Y', strtotime($endDate))) ?>
                            <?php else: ?>
                                Mois de <?= h(date('F Y', strtotime($filterDate))) ?>
                            <?php endif; ?>
                        </p>
                        <p style="margin-top: 0.5rem; color: #6b7280; font-size: 0.9rem;">
                            Suggestions :
                            <ul style="margin-top: 0.5rem; padding-left: 1.5rem;">
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
                    // Calculer le total de tâches pour chaque date
                    function countTasksForDate($dateData) {
                        $total = 0;
                        foreach ($dateData['users'] as $userData) {
                            foreach ($userData['clients'] as $clientData) {
                                $total += count($clientData['savs'] ?? []);
                                $total += count($clientData['livraisons'] ?? []);
                            }
                        }
                        return $total;
                    }
                    
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
                    
                    // Noms de jours en français
                    $joursFr = [
                        'Monday' => 'Lundi',
                        'Tuesday' => 'Mardi',
                        'Wednesday' => 'Mercredi',
                        'Thursday' => 'Jeudi',
                        'Friday' => 'Vendredi',
                        'Saturday' => 'Samedi',
                        'Sunday' => 'Dimanche'
                    ];
                    ?>
                    <?php foreach ($agendaByDate as $date => $dateData): ?>
                        <?php 
                        $totalTasks = countTasksForDate($dateData);
                        $jourEn = date('l', strtotime($date));
                        $jourFr = $joursFr[$jourEn] ?? $jourEn;
                        ?>
                        <div class="agenda-day">
                            <div class="agenda-day-header">
                                <h3 class="agenda-day-title">
                                    <?= h($jourFr . ' ' . date('d/m/Y', strtotime($date))) ?>
                                    <?php if ($date === date('Y-m-d')): ?>
                                        <span class="badge" style="margin-left: 0.5rem; background: #3b82f6;">Aujourd'hui</span>
                                    <?php endif; ?>
                                </h3>
                                <div class="agenda-day-count">
                                    <?= $totalTasks ?> tâche(s)
                                </div>
                            </div>

                            <div class="agenda-day-content">
                                <?php if (empty($dateData['users'])): ?>
                                    <p style="color: var(--text-secondary); text-align: center; padding: 1rem;">
                                        Aucune tâche prévue pour ce jour.
                                    </p>
                                <?php else: ?>
                                    <?php foreach ($dateData['users'] as $userKey => $userData): ?>
                                        <div class="agenda-user-group">
                                            <div class="agenda-user-header">
                                                <div class="agenda-user-info">
                                                    <span class="agenda-user-icon"><?= $userData['role'] === 'Livreur' ? '🚚' : '🔧' ?></span>
                                                    <div>
                                                        <strong class="agenda-user-name"><?= h($userData['name']) ?></strong>
                                                        <span class="agenda-user-role"><?= h($userData['role']) ?></span>
                                                    </div>
                                                </div>
                                                <?php 
                                                $userTotalTasks = 0;
                                                foreach ($userData['clients'] as $clientData) {
                                                    $userTotalTasks += count($clientData['savs'] ?? []);
                                                    $userTotalTasks += count($clientData['livraisons'] ?? []);
                                                }
                                                ?>
                                                <span class="agenda-user-count"><?= $userTotalTasks ?> tâche(s)</span>
                                            </div>

                                            <div class="agenda-user-clients">
                                                <?php foreach ($userData['clients'] as $clientKey => $clientData): ?>
                                                    <?php 
                                                    $clientTotalTasks = count($clientData['savs'] ?? []) + count($clientData['livraisons'] ?? []);
                                                    if ($clientTotalTasks === 0) continue;
                                                    ?>
                                                    <div class="agenda-client-group">
                                                        <div class="agenda-client-header">
                                                            <span class="agenda-client-icon">👤</span>
                                                            <strong class="agenda-client-name"><?= h($clientData['name']) ?></strong>
                                                            <span class="agenda-client-count"><?= $clientTotalTasks ?> tâche(s)</span>
                                                        </div>

                                                        <div class="agenda-client-tasks">
                                                            <!-- SAV du client -->
                                                            <?php if (!empty($clientData['savs'])): ?>
                                                                <?php foreach ($clientData['savs'] as $sav): ?>
                                                                    <?php
                                                                    $priorite = $sav['priorite'] ?? 'normale';
                                                                    $clientAdresse = trim(($sav['client_adresse'] ?? '') . ' ' . ($sav['client_code_postal'] ?? '') . ' ' . ($sav['client_ville'] ?? ''));
                                                                    ?>
                                                                    <div class="agenda-item agenda-item-sav" 
                                                                         data-sav-id="<?= (int)$sav['id'] ?>"
                                                                         onclick="window.location.href='/public/sav.php?ref=<?= urlencode($sav['reference']) ?>'">
                                                                        <div class="agenda-item-header">
                                                                            <div class="agenda-item-title">
                                                                                <span class="agenda-item-type-icon">🔧</span>
                                                                                <strong><?= h($sav['reference']) ?></strong>
                                                                                <?php if (!empty($sav['type_panne'])): ?>
                                                                                    <span class="agenda-item-badge" style="background: #6366f1;">
                                                                                        <?= h($typePanneLabels[$sav['type_panne']] ?? $sav['type_panne']) ?>
                                                                                    </span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                            <span class="agenda-item-priority" style="background: <?= h($prioriteColors[$priorite] ?? '#6b7280') ?>;">
                                                                                <?= h($prioriteLabels[$priorite] ?? $priorite) ?>
                                                                            </span>
                                                                        </div>
                                                                        <div class="agenda-item-body">
                                                                            <div class="agenda-item-description">
                                                                                <?= h(mb_substr($sav['description'], 0, 120)) ?><?= mb_strlen($sav['description']) > 120 ? '...' : '' ?>
                                                                            </div>
                                                                            <div class="agenda-item-meta">
                                                                                <?php if ($clientAdresse): ?>
                                                                                    <span>📍 <?= h($clientAdresse) ?></span>
                                                                                <?php endif; ?>
                                                                                <span>📊 <?= h($statutLabels[$sav['statut']] ?? $sav['statut']) ?></span>
                                                                                <?php if ($hasDateIntervention && !empty($sav['date_intervention_prevue'])): ?>
                                                                                    <span>📅 <?= h(date('d/m/Y', strtotime($sav['date_intervention_prevue']))) ?></span>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>

                                                            <!-- Livraisons du client -->
                                                            <?php if (!empty($clientData['livraisons'])): ?>
                                                                <?php foreach ($clientData['livraisons'] as $liv): ?>
                                                                    <?php
                                                                    $clientAdresse = trim(($liv['client_ville'] ?? '') . ' ' . ($liv['client_code_postal'] ?? ''));
                                                                    ?>
                                                                    <div class="agenda-item agenda-item-livraison" 
                                                                         data-livraison-id="<?= (int)$liv['id'] ?>"
                                                                         onclick="window.location.href='/public/livraison.php?ref=<?= urlencode($liv['reference']) ?>'">
                                                                        <div class="agenda-item-header">
                                                                            <div class="agenda-item-title">
                                                                                <span class="agenda-item-type-icon">📦</span>
                                                                                <strong><?= h($liv['reference']) ?></strong>
                                                                            </div>
                                                                            <span class="agenda-item-status" style="background: <?= h($statutColors[$liv['statut']] ?? '#6b7280') ?>;">
                                                                                <?= h($statutLabels[$liv['statut']] ?? $liv['statut']) ?>
                                                                            </span>
                                                                        </div>
                                                                        <div class="agenda-item-body">
                                                                            <div class="agenda-item-description">
                                                                                <?= h($liv['objet'] ?? '') ?>
                                                                            </div>
                                                                            <div class="agenda-item-meta">
                                                                                <?php if ($liv['adresse_livraison']): ?>
                                                                                    <span>📍 <?= h($liv['adresse_livraison']) ?></span>
                                                                                <?php elseif ($clientAdresse): ?>
                                                                                    <span>📍 <?= h($clientAdresse) ?></span>
                                                                                <?php endif; ?>
                                                                                <span>📅 <?= h(date('d/m/Y', strtotime($liv['date_prevue']))) ?></span>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

