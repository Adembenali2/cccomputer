<?php
// /public/sav.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('sav', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';
require_once __DIR__ . '/../includes/NotificationService.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

/** PDO en mode exceptions **/
if (method_exists($pdo, 'setAttribute')) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {}
}

/** Helpers **/
// Les fonctions h(), currentUserId(), currentUserRole(), ensureCsrfToken(), assertValidCsrf() sont définies dans includes/helpers.php

/** Permissions : qui peut éditer un SAV ? **/
function canEditSav(array $sav): bool {
    $uid  = currentUserId();
    $role = currentUserRole();
    if (!$uid || !$role) return false;

    // Les Admin et Dirigeant peuvent modifier tous les SAV
    if (in_array($role, ['Admin', 'Dirigeant'], true)) {
        return true;
    }

    // Les techniciens ne peuvent modifier QUE leurs propres SAV assignés
    if ($role === 'Technicien') {
        $technicienId = isset($sav['id_technicien']) ? (int)$sav['id_technicien'] : 0;
        return $technicienId > 0 && $technicienId === (int)$uid;
    }

    // Tous les autres rôles ne peuvent pas modifier
    return false;
}

/** Flash simple **/
$flash = ['type' => null, 'msg' => null];

$CSRF  = ensureCsrfToken();
$today = date('Y-m-d');

// [Fonctionnalité A]
function generateUniqueSavReference(PDO $pdo): string {
    do {
        $reference = 'SAV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
        $check = $pdo->prepare('SELECT id FROM sav WHERE reference = ? LIMIT 1');
        $check->execute([$reference]);
        $exists = (bool)$check->fetch(PDO::FETCH_ASSOC);
    } while ($exists);
    return $reference;
}

// [Fonctionnalité A]
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'create_sav') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
    } catch (RuntimeException $csrfEx) {
        $flash = ['type' => 'error', 'msg' => $csrfEx->getMessage()];
    }

    if (!$flash['type']) {
        $idClient = (int)($_POST['id_client'] ?? 0);
        $description = trim((string)($_POST['description'] ?? ''));
        $priorite = (string)($_POST['priorite'] ?? 'normale');
        $typePanne = (string)($_POST['type_panne'] ?? '');
        $datePrevue = trim((string)($_POST['date_intervention_prevue'] ?? ''));
        $idTechnicien = (int)($_POST['id_technicien'] ?? 0);

        $allowedPriorites = ['basse','normale','haute','urgente'];
        $allowedTypePanne = ['logiciel','materiel','piece_rechangeable'];
        if ($idClient <= 0 || $description === '') {
            $flash = ['type' => 'error', 'msg' => 'Client et description requis.'];
        } elseif (!in_array($priorite, $allowedPriorites, true)) {
            $flash = ['type' => 'error', 'msg' => 'Priorité invalide.'];
        } elseif ($typePanne !== '' && !in_array($typePanne, $allowedTypePanne, true)) {
            $flash = ['type' => 'error', 'msg' => 'Type de panne invalide.'];
        } else {
            try {
                $isAdminOrDirigeantPost = in_array(currentUserRole(), ['Admin', 'Dirigeant'], true);
                $idTechnicienInsert = null;
                if ($isAdminOrDirigeantPost && $idTechnicien > 0) {
                    $techChk = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = ? AND Emploi = 'Technicien' AND statut = 'actif' LIMIT 1");
                    $techChk->execute([$idTechnicien]);
                    if ($techChk->fetch(PDO::FETCH_ASSOC)) {
                        $idTechnicienInsert = $idTechnicien;
                    }
                }
                $reference = generateUniqueSavReference($pdo);
                $stmt = $pdo->prepare("
                    INSERT INTO sav (id_client, id_technicien, reference, description, priorite, type_panne, date_intervention_prevue, statut, date_ouverture)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'ouvert', CURDATE())
                ");
                $stmt->execute([
                    $idClient,
                    $idTechnicienInsert,
                    $reference,
                    $description,
                    $priorite,
                    $typePanne !== '' ? $typePanne : null,
                    $datePrevue !== '' ? $datePrevue : null,
                ]);
                $newSavId = (int)$pdo->lastInsertId();
                $clientNomLog = 'Client #' . $idClient;
                try {
                    $clientStmt = $pdo->prepare("SELECT raison_sociale FROM clients WHERE id = ? LIMIT 1");
                    $clientStmt->execute([$idClient]);
                    $clientNomLog = (string)($clientStmt->fetchColumn() ?: $clientNomLog);
                } catch (Throwable $e) {
                    // ignore
                }
                logAction(
                    $pdo,
                    'sav',
                    'creation',
                    'SAV ouvert — ' . $clientNomLog,
                    'Ref: ' . $reference . ' | ' . mb_substr($description, 0, 80),
                    $newSavId,
                    'sav.php?id=' . $newSavId
                );
                if ($idTechnicienInsert) {
                    NotificationService::create(
                        (int)$idTechnicienInsert,
                        'sav_assigne',
                        'SAV assigné',
                        "Le SAV {$reference} vous a été assigné.",
                        $newSavId,
                        'sav'
                    );
                }
                header('Location: /public/sav.php?created=1');
                exit;
            } catch (PDOException $e) {
                error_log('sav.php create_sav error: ' . $e->getMessage());
                $flash = ['type' => 'error', 'msg' => 'Erreur SQL : impossible de créer le SAV.'];
            }
        }
    }
}

// ============================================================================
// POST : mise à jour de SAV (statut, éventuellement date_fermeture)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_sav') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
    } catch (RuntimeException $csrfEx) {
        $flash = ['type' => 'error', 'msg' => $csrfEx->getMessage()];
    }

    if (!$flash['type']) {
        $savId = (int)($_POST['sav_id'] ?? 0);
        $newStatut   = $_POST['statut'] ?? '';
        $newPriorite = $_POST['priorite'] ?? '';
        $newTypePanne = trim($_POST['type_panne'] ?? '');
        $newCommentaireTechnicien = trim($_POST['commentaire_technicien'] ?? '');
        // [Fonctionnalité C/D/E]
        $newTempsEstime = ($_POST['temps_estime'] ?? '') !== '' ? (float)$_POST['temps_estime'] : null;
        $newTempsReel = ($_POST['temps_reel'] ?? '') !== '' ? (float)$_POST['temps_reel'] : null;
        $newCout = ($_POST['cout_intervention'] ?? '') !== '' ? (float)$_POST['cout_intervention'] : null;
        $newDatePrevue = trim((string)($_POST['date_intervention_prevue'] ?? ''));
        $newSatisfaction = ($_POST['satisfaction_client'] ?? '') !== '' ? (int)$_POST['satisfaction_client'] : null;
        $newCommentaireClient = trim((string)($_POST['commentaire_client'] ?? ''));

        $allowedStatuts = ['ouvert','en_cours','resolu','annule'];
        $allowedPriorites = ['basse','normale','haute','urgente'];
        $allowedTypePanne = ['logiciel','materiel','piece_rechangeable'];
        
        if (!$savId || !in_array($newStatut, $allowedStatuts, true) || !in_array($newPriorite, $allowedPriorites, true)) {
            $flash = ['type'=>'error','msg'=>"Données invalides pour la mise à jour du SAV."];
        } elseif (!empty($newTypePanne) && !in_array($newTypePanne, $allowedTypePanne, true)) {
            $flash = ['type'=>'error','msg'=>"Type de panne invalide."];
        } elseif (($newTempsEstime !== null && $newTempsEstime < 0) || ($newTempsReel !== null && $newTempsReel < 0) || ($newCout !== null && $newCout < 0)) {
            $flash = ['type'=>'error','msg'=>"Temps / coût invalides (>= 0)."];
        } elseif ($newSatisfaction !== null && ($newSatisfaction < 1 || $newSatisfaction > 5)) {
            $flash = ['type'=>'error','msg'=>"Satisfaction invalide (1 à 5)."];
        } else {
            try {
                // Récupération du SAV pour vérifier permissions (avec infos client et technicien pour l'historique)
                $stmt = $pdo->prepare("
                    SELECT s.id, s.id_client, s.id_technicien, s.reference, s.description, 
                           s.date_ouverture, s.date_fermeture, s.statut, s.priorite, s.type_panne, s.commentaire,
                           s.notes_techniques AS commentaire_technicien,
                           s.created_at, s.updated_at,
                           c.raison_sociale AS client_nom,
                           u.nom AS technicien_nom,
                           u.prenom AS technicien_prenom
                    FROM sav s
                    LEFT JOIN clients c ON c.id = s.id_client
                    LEFT JOIN utilisateurs u ON u.id = s.id_technicien
                    WHERE s.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $savId]);
                $sav = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$sav) {
                    $flash = ['type'=>'error','msg'=>"SAV introuvable."];
                } elseif (!canEditSav($sav)) {
                    $flash = ['type'=>'error','msg'=>"Vous n'êtes pas autorisé à modifier ce SAV."];
                } else {
                    $oldTechId = (int) ($sav['id_technicien'] ?? 0);
                    $newTechId = $oldTechId;
                    $isAdminOrDirigeantPost = in_array(currentUserRole(), ['Admin', 'Dirigeant'], true);
                    if ($isAdminOrDirigeantPost && array_key_exists('id_technicien', $_POST)) {
                        $rawTech = $_POST['id_technicien'];
                        $parsed = ($rawTech === '' || $rawTech === null) ? 0 : (int) $rawTech;
                        if ($parsed < 0) {
                            $parsed = 0;
                        }
                        if ($parsed > 0) {
                            $chkTech = $pdo->prepare(
                                "SELECT id FROM utilisateurs WHERE id = ? AND Emploi = 'Technicien' AND statut = 'actif' LIMIT 1"
                            );
                            $chkTech->execute([$parsed]);
                            if (!$chkTech->fetch()) {
                                $flash = ['type' => 'error', 'msg' => 'Technicien invalide ou inactif.'];
                            } else {
                                $newTechId = $parsed;
                            }
                        } else {
                            $newTechId = 0;
                        }
                    }

                    if ($flash['type']) {
                        // Erreur validation technicien
                    } else {
                    // Gestion automatique de la date_fermeture :
                    // - si on passe en "resolu" et qu'il n'y a pas encore de date_fermeture -> on met aujourd'hui
                    // - si on passe en autre statut (même depuis "resolu") et qu'il y a une date_fermeture -> on la met à NULL
                    $dateFermeture = $sav['date_fermeture'] ?? null;
                    $oldStatut = $sav['statut'] ?? '';
                    $isBecomingResolu = ($newStatut === 'resolu' && $oldStatut !== 'resolu');
                    $isLeavingResolu = ($oldStatut === 'resolu' && $newStatut !== 'resolu');
                    
                    if ($newStatut === 'resolu' && empty($dateFermeture)) {
                        // Passer à "résolu" : mettre la date de fermeture à aujourd'hui si elle n'existe pas
                        $dateFermeture = $today;
                    } elseif ($newStatut !== 'resolu') {
                        // Passer à un autre statut (ouvert, en_cours, annule) : supprimer la date de fermeture
                        // Cela permet au SAV de réapparaître dans la liste principale et de sortir des archives
                        $dateFermeture = null;
                    }

                    $pdo->beginTransaction();
                    try {
                        // Vérifier si la colonne notes_techniques existe, sinon utiliser commentaire
                        require_once __DIR__ . '/../includes/api_helpers.php';
                        $hasNotesTechniques = columnExists($pdo, 'sav', 'notes_techniques');
                        
                        $idTechnicienBind = $newTechId > 0 ? $newTechId : null;

                        if ($hasNotesTechniques) {
                            $upd = $pdo->prepare("
                                UPDATE sav
                                SET statut = :statut,
                                    priorite = :priorite,
                                    type_panne = :type_panne,
                                    date_fermeture = :date_fermeture,
                                    date_intervention_prevue = :date_prevue,
                                    temps_intervention_estime = :temps_estime,
                                    temps_intervention_reel = :temps_reel,
                                    cout_intervention = :cout,
                                    satisfaction_client = :satisfaction_client,
                                    commentaire_client = :commentaire_client,
                                    id_technicien = :id_technicien,
                                    notes_techniques = :commentaire_technicien,
                                    updated_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->execute([
                                ':statut'      => $newStatut,
                                ':priorite'    => $newPriorite,
                                ':type_panne'  => !empty($newTypePanne) ? $newTypePanne : null,
                                ':date_fermeture' => $dateFermeture,
                                ':date_prevue' => $newDatePrevue !== '' ? $newDatePrevue : null,
                                ':temps_estime' => $newTempsEstime,
                                ':temps_reel' => $newTempsReel,
                                ':cout' => $newCout,
                                ':satisfaction_client' => $newSatisfaction,
                                ':commentaire_client' => $newCommentaireClient !== '' ? $newCommentaireClient : null,
                                ':id_technicien' => $idTechnicienBind,
                                ':commentaire_technicien' => !empty($newCommentaireTechnicien) ? $newCommentaireTechnicien : null,
                                ':id'          => $savId,
                            ]);
                        } else {
                            // Fallback : utiliser commentaire si notes_techniques n'existe pas
                            $upd = $pdo->prepare("
                                UPDATE sav
                                SET statut = :statut,
                                    priorite = :priorite,
                                    type_panne = :type_panne,
                                    date_fermeture = :date_fermeture,
                                    date_intervention_prevue = :date_prevue,
                                    temps_intervention_estime = :temps_estime,
                                    temps_intervention_reel = :temps_reel,
                                    cout_intervention = :cout,
                                    satisfaction_client = :satisfaction_client,
                                    commentaire_client = :commentaire_client,
                                    id_technicien = :id_technicien,
                                    updated_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->execute([
                                ':statut'      => $newStatut,
                                ':priorite'    => $newPriorite,
                                ':type_panne'  => !empty($newTypePanne) ? $newTypePanne : null,
                                ':date_fermeture' => $dateFermeture,
                                ':date_prevue' => $newDatePrevue !== '' ? $newDatePrevue : null,
                                ':temps_estime' => $newTempsEstime,
                                ':temps_reel' => $newTempsReel,
                                ':cout' => $newCout,
                                ':satisfaction_client' => $newSatisfaction,
                                ':commentaire_client' => $newCommentaireClient !== '' ? $newCommentaireClient : null,
                                ':id_technicien' => $idTechnicienBind,
                                ':id'          => $savId,
                            ]);
                        }

                        $pdo->commit();

                        if ($newTechId > 0 && $newTechId !== $oldTechId) {
                            NotificationService::create(
                                $newTechId,
                                'sav_assigne',
                                'SAV assigné',
                                sprintf(
                                    'Le SAV n°%d (%s) vous a été assigné.',
                                    $savId,
                                    $sav['reference'] ?? 'N/A'
                                ),
                                $savId,
                                'sav'
                            );
                        }
                        
                        // Enregistrer dans l'historique
                        try {
                            $statutLabels = [
                                'ouvert' => 'Ouvert',
                                'en_cours' => 'En cours',
                                'resolu' => 'Résolu',
                                'annule' => 'Annulé'
                            ];
                            $prioriteLabels = [
                                'basse' => 'Basse',
                                'normale' => 'Normale',
                                'haute' => 'Haute',
                                'urgente' => 'Urgente'
                            ];
                            $typePanneLabels = [
                                'logiciel' => 'Logiciel',
                                'materiel' => 'Matériel',
                                'piece_rechangeable' => 'Pièce rechangeable'
                            ];
                            
                            $oldStatutLabel = $statutLabels[$oldStatut] ?? $oldStatut;
                            $newStatutLabel = $statutLabels[$newStatut] ?? $newStatut;
                            $prioriteLabel = $prioriteLabels[$newPriorite] ?? $newPriorite;
                            
                            // Construire les détails comme pour les livraisons
                            $details = sprintf(
                                'SAV #%d (%s) : statut changé de "%s" à "%s"',
                                $savId,
                                $sav['reference'] ?? 'N/A',
                                $oldStatutLabel,
                                $newStatutLabel
                            );
                            
                            // Ajouter la priorité si elle a changé
                            $oldPriorite = $sav['priorite'] ?? 'normale';
                            if ($oldPriorite !== $newPriorite) {
                                $oldPrioriteLabel = $prioriteLabels[$oldPriorite] ?? $oldPriorite;
                                $details .= sprintf(', priorité changée de "%s" à "%s"', $oldPrioriteLabel, $prioriteLabel);
                            } else {
                                $details .= sprintf(', priorité: %s', $prioriteLabel);
                            }
                            
                            // Ajouter le type de panne si modifié
                            if (!empty($newTypePanne)) {
                                $typePanneLabel = $typePanneLabels[$newTypePanne] ?? $newTypePanne;
                                $details .= sprintf(', type de panne: %s', $typePanneLabel);
                            }
                            
                            // Ajouter le commentaire technicien si modifié
                            if (!empty($newCommentaireTechnicien)) {
                                $commentaireShort = mb_substr($newCommentaireTechnicien, 0, 100);
                                if (mb_strlen($newCommentaireTechnicien) > 100) {
                                    $commentaireShort .= '...';
                                }
                                $details .= sprintf(' - Commentaire technicien: %s', $commentaireShort);
                            }
                            
                            // Ajouter la date de fermeture si résolu
                            if (!empty($dateFermeture) && $newStatut === 'resolu') {
                                $details .= sprintf(' - Date de fermeture: %s', $dateFermeture);
                            }
                            
                            // Ajouter les informations client et technicien pour plus de contexte
                            $clientNom = $sav['client_nom'] ?? null;
                            if ($clientNom) {
                                $details .= sprintf(' - Client: %s', $clientNom);
                            }
                            
                            $technicienNom = null;
                            if (!empty($sav['technicien_prenom']) || !empty($sav['technicien_nom'])) {
                                $technicienNom = trim(($sav['technicien_prenom'] ?? '') . ' ' . ($sav['technicien_nom'] ?? ''));
                            }
                            if ($technicienNom) {
                                $details .= sprintf(' - Technicien: %s', $technicienNom);
                            }
                            
                            logAction(
                                $pdo,
                                'sav',
                                'modification',
                                'SAV mis a jour — ' . (string)($sav['reference'] ?? ('#' . $savId)),
                                'Nouveau statut: ' . $newStatutLabel,
                                $savId,
                                'sav.php?id=' . $savId
                            );
                            if ($isBecomingResolu) {
                                logAction(
                                    $pdo,
                                    'sav',
                                    'resolution',
                                    'SAV resolu — ' . (string)($sav['reference'] ?? ('#' . $savId)) . ' · ' . (string)($sav['client_nom'] ?? 'Client'),
                                    '',
                                    $savId,
                                    'sav.php'
                                );
                            }
                        } catch (Throwable $e) {
                            error_log('sav.php historique error: ' . $e->getMessage());
                        }
                        
                        $flash = ['type'=>'success','msg'=>"SAV mis à jour avec succès."];
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        error_log('sav.php UPDATE error: ' . $e->getMessage());
                        $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour le SAV."];
                    }
                    }
                }
            } catch (PDOException $e) {
                error_log('sav.php UPDATE error: ' . $e->getMessage());
                $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour le SAV."];
            }
        }
    }
}

if (($_GET['created'] ?? '') === '1') {
    $flash = ['type' => 'success', 'msg' => 'SAV créé avec succès.'];
}

// ============================================================================
// Récupération des SAV depuis la base (pour l'affichage)
// ============================================================================
try {
    // Sélection explicite de toutes les colonnes selon le schéma railway.sql
    // Évite les problèmes si le schéma change et améliore la lisibilité
    $sql = "
        SELECT
            s.id,
            s.id_client,
            s.mac_norm,
            s.id_technicien,
            s.reference,
            s.description,
            s.date_ouverture,
            s.date_intervention_prevue,
            s.temps_intervention_estime,
            s.temps_intervention_reel,
            s.cout_intervention,
            s.date_fermeture,
            s.satisfaction_client,
            s.commentaire_client,
            s.statut,
            s.priorite,
            s.type_panne,
            s.commentaire,
            s.notes_techniques AS commentaire_technicien,
            s.created_at,
            s.updated_at,
            c.raison_sociale AS client_nom,
            u.nom    AS technicien_nom,
            u.prenom AS technicien_prenom
        FROM sav s
        LEFT JOIN clients c      ON c.id = s.id_client
        LEFT JOIN utilisateurs u ON u.id = s.id_technicien
        ORDER BY 
            CASE s.priorite
                WHEN 'urgente' THEN 1
                WHEN 'haute' THEN 2
                WHEN 'normale' THEN 3
                WHEN 'basse' THEN 4
            END,
            s.date_ouverture DESC, s.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('sav.php SQL error: ' . $e->getMessage());
    $rows = [];
}

// ============================================================================
// Calcul des flags et stats globales
// ============================================================================
$totalSav = count($rows);
$urgentCount = 0;
$todayCount = 0;
$archiveCount = 0;

foreach ($rows as $idx => $s) {
    $statut = $s['statut'] ?? '';
    $isResolu = ($statut === 'resolu');
    
    // Compter les SAV archivés
    if ($isResolu) {
        $archiveCount++;
        $rows[$idx]['is_today'] = false;
        $rows[$idx]['is_urgent'] = false;
        continue;
    }
    
    $ouverture = $s['date_ouverture'] ?? null;
    $fermeture = $s['date_fermeture'] ?? null;

    $isToday = false;
    if ($ouverture && $ouverture === $today) {
        $isToday = true;
    }
    if ($fermeture && $fermeture === $today) {
        $isToday = true;
    }

    $isUrgent = ($s['priorite'] ?? 'normale') === 'urgente';

    $rows[$idx]['is_today'] = $isToday;
    $rows[$idx]['is_urgent'] = $isUrgent;

    if ($isUrgent)  $urgentCount++;
    if ($isToday) $todayCount++;
}

// ============================================================================
// Vue (toutes / urgent / aujourd'hui / archive)
// ============================================================================
$view = $_GET['view'] ?? 'toutes';
$currentRole = currentUserRole();
$isAdminOrDirigeant = in_array($currentRole, ['Admin', 'Dirigeant'], true);

$savTechniciensOptions = [];
if ($isAdminOrDirigeant) {
    try {
        $tq = $pdo->query(
            "SELECT id, nom, prenom FROM utilisateurs WHERE Emploi = 'Technicien' AND statut = 'actif' ORDER BY nom, prenom"
        );
        $savTechniciensOptions = $tq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('sav.php techniciens list: ' . $e->getMessage());
    }
}

// Vérifier les permissions pour l'archive
if ($view === 'archive' && !$isAdminOrDirigeant) {
    $flash = ['type' => 'error', 'msg' => "Vous n'êtes pas autorisé à accéder à l'archive."];
    $view = 'toutes';
}

if (!in_array($view, ['toutes', 'urgent', 'aujourdhui', 'archive', 'mes_sav'], true)) {
    $view = 'toutes';
}

$filteredSav = array_values(array_filter($rows, function($s) use ($view, $today) {
    $statut = $s['statut'] ?? '';
    // [Fonctionnalité F]
    if ($view === 'mes_sav') {
        return (int)($s['id_technicien'] ?? 0) === (int)currentUserId()
               && $statut !== 'resolu';
    }
    
    // Vue "archive" : afficher uniquement les SAV résolus
    if ($view === 'archive') {
        return $statut === 'resolu';
    }
    
    // Pour toutes les autres vues, exclure les SAV résolus
    if ($statut === 'resolu') {
        return false;
    }
    
    // Vue "urgent" : afficher uniquement les SAV urgents
    if ($view === 'urgent') {
        $isUrgent = !empty($s['is_urgent']);
        if (!$isUrgent) {
            $isUrgent = ($s['priorite'] ?? 'normale') === 'urgente';
        }
        return $isUrgent;
    }
    
    // Vue "aujourdhui" : afficher les SAV ouverts ou fermés aujourd'hui
    if ($view === 'aujourdhui') {
        $isToday = !empty($s['is_today']);
        if (!$isToday) {
            $ouverture = $s['date_ouverture'] ?? null;
            $fermeture = $s['date_fermeture'] ?? null;
            if ($ouverture && $ouverture === $today) {
                $isToday = true;
            }
            if ($fermeture && $fermeture === $today) {
                $isToday = true;
            }
        }
        return $isToday;
    }
    
    // Vue "toutes" : afficher tous les SAV sauf les résolus
    return true;
}));

$listedCount      = count($filteredSav);
$lastRefreshLabel = date('d/m/Y à H:i');
$pdfExportDateDebutDefault = date('Y-m-01');
$pdfExportDateFinDefault = $today;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Gestion des SAV - CCComputer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/livraison.css" />
</head>
<body class="page-livraisons">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
  <div class="page-header">
    <h2 class="page-title">Gestion des SAV</h2>
    <p class="page-sub">
      Vue des interventions SAV — dernière mise à jour <?= h($lastRefreshLabel) ?>.
    </p>
  </div>
  <?php if ($currentRole === 'Technicien' && $view === 'toutes'): ?>
    <!-- [Fonctionnalité F] -->
    <div style="background:#dbeafe;border-left:4px solid #3b82f6;padding:10px 16px;margin-bottom:12px;border-radius:6px;font-size:0.88rem;">
      💡 Utilisez "Mes SAV" pour voir uniquement vos interventions assignées.
    </div>
  <?php endif; ?>

  <!-- Flash -->
  <?php if ($flash['type']): ?>
    <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Meta cards -->
  <section class="clients-meta">
    <div class="meta-card">
      <span class="meta-label">SAV listés</span>
      <strong class="meta-value"><?= h((string)$listedCount) ?></strong>
      <?php if ($listedCount === 0): ?>
        <span class="meta-chip">Aucune donnée</span>
      <?php endif; ?>
      <?php if ($view !== 'archive' && $isAdminOrDirigeant): ?>
        <span class="meta-sub">Archive : <?= h((string)$archiveCount) ?> SAV résolu(s)</span>
      <?php endif; ?>
    </div>

    <div class="meta-card">
      <span class="meta-label">SAV urgents</span>
      <strong class="meta-value <?= $urgentCount > 0 ? 'danger' : 'success' ?>">
        <?= h((string)$urgentCount) ?>
      </strong>
      <span class="meta-sub">
        <?= $urgentCount > 0 ? 'À traiter en priorité' : 'Aucun SAV urgent' ?>
      </span>
    </div>

    <div class="meta-card">
      <span class="meta-label">Aujourd'hui</span>
      <strong class="meta-value"><?= h((string)$todayCount) ?></strong>
      <span class="meta-sub">SAV ouverts ou fermés aujourd'hui</span>
    </div>

    <div class="meta-card">
      <span class="meta-label">Vue active</span>
      <strong class="meta-value">
        <?php
          if ($view === 'archive') {
              echo 'Archive';
          } elseif ($view === 'urgent') {
              echo 'Urgents';
          } elseif ($view === 'aujourdhui') {
              echo 'Aujourd\'hui';
          } else {
              echo 'Toutes';
          }
        ?>
      </strong>
      <span class="meta-sub">Filtrer en un clic</span>
    </div>
  </section>

  <!-- Barre de filtres -->
  <div class="filters-row">
    <div class="filters-left">
      <input type="text" id="q" class="filter-input" placeholder="Filtrer (client, référence, description, technicien)…">
      <button id="clearQ" class="btn btn-secondary" type="button">Effacer</button>
    </div>
    <div class="filters-actions">
      <!-- [Fonctionnalité A] -->
      <button type="button" class="btn btn-primary" id="btnOpenCreateSav">+ Créer un SAV</button>
      <a href="/public/sav.php?view=toutes"
         class="btn <?= $view === 'toutes' ? 'btn-primary' : 'btn-outline' ?>">Toutes</a>
      <a href="/public/sav.php?view=urgent"
         class="btn <?= $view === 'urgent' ? 'btn-primary' : 'btn-outline' ?>">Urgents</a>
      <a href="/public/sav.php?view=aujourdhui"
         class="btn <?= $view === 'aujourdhui' ? 'btn-primary' : 'btn-outline' ?>">Aujourd'hui</a>
      <?php if ($currentRole === 'Technicien'): ?>
      <a href="/public/sav.php?view=mes_sav"
         class="btn <?= $view === 'mes_sav' ? 'btn-primary' : 'btn-outline' ?>">Mes SAV</a>
      <?php endif; ?>
      <?php if ($isAdminOrDirigeant): ?>
      <a href="/public/sav.php?view=archive"
         class="btn <?= $view === 'archive' ? 'btn-primary' : 'btn-outline' ?>">📦 Archive</a>
      <?php endif; ?>
      <button type="button" class="btn btn-outline" id="btnOpenPdfExportSav">Exporter PDF</button>
    </div>
  </div>

  <!-- Tableau -->
  <div class="table-wrapper">
    <table class="tbl-livraisons" id="tbl">
      <thead>
        <tr>
          <th>Client</th>
          <th>Référence</th>
          <th>Description</th>
          <th>Type de panne</th>
          <th>Date ouverture</th>
          <th>Date fermeture</th>
          <th>Technicien</th>
          <th>Priorité</th>
          <th>Durée / Coût</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filteredSav): ?>
        <tr data-empty-row="1">
          <td colspan="10" style="padding:1rem; color:var(--text-secondary);">
            Aucun SAV à afficher pour cette vue.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($filteredSav as $s):

          $clientNom = $s['client_nom'] ?: '—';
          $ref       = $s['reference'] ?? '—';
          $description = $s['description'] ?? '—';

          $ouverture    = $s['date_ouverture'] ?? null;
          $fermeture    = $s['date_fermeture'] ?? null;

          $ouvertureLabel = formatDate($ouverture);
          $fermetureLabel = formatDate($fermeture);

          $technicienNomComplet = trim(
              ($s['technicien_prenom'] ?? '') . ' ' . ($s['technicien_nom'] ?? '')
          );
          if ($technicienNomComplet === '') {
              $technicienNomComplet = '—';
          }

          $isUrgent  = !empty($s['is_urgent']);
          $isToday = !empty($s['is_today']);

          $statut = $s['statut'] ?? 'ouvert';
          $statutLabels = [
              'ouvert' => 'Ouvert',
              'en_cours' => 'En cours',
              'resolu' => 'Résolu',
              'annule' => 'Annulé'
          ];
          $statutLabel = $statutLabels[$statut] ?? $statut;

          $priorite = $s['priorite'] ?? 'normale';
          $prioriteLabels = [
              'basse' => 'Basse',
              'normale' => 'Normale',
              'haute' => 'Haute',
              'urgente' => 'Urgente'
          ];
          $prioriteLabel = $prioriteLabels[$priorite] ?? $priorite;
          $prioriteColors = [
              'basse' => '#6b7280',
              'normale' => '#3b82f6',
              'haute' => '#f59e0b',
              'urgente' => '#dc2626'
          ];
          $prioriteColor = $prioriteColors[$priorite] ?? '#6b7280';

          $typePanne = $s['type_panne'] ?? null;
          $typePanneLabels = [
              'logiciel' => 'Logiciel',
              'materiel' => 'Matériel',
              'piece_rechangeable' => 'Pièce rechargeable'
          ];
          $typePanneLabel = $typePanne ? ($typePanneLabels[$typePanne] ?? $typePanne) : '—';
          $typePanneColors = [
              'logiciel' => '#8b5cf6',
              'materiel' => '#ec4899',
              'piece_rechangeable' => '#10b981'
          ];
          $typePanneColor = $typePanne ? ($typePanneColors[$typePanne] ?? '#6b7280') : '#6b7280';

          $commentaire = $s['commentaire'] ?? '';
          $commentaireTechnicien = $s['commentaire_technicien'] ?? '';
          // [Fonctionnalité C/D/E]
          $tempsEstime = isset($s['temps_intervention_estime']) && $s['temps_intervention_estime'] !== null ? (float)$s['temps_intervention_estime'] : null;
          $tempsReel = isset($s['temps_intervention_reel']) && $s['temps_intervention_reel'] !== null ? (float)$s['temps_intervention_reel'] : null;
          $coutIntervention = isset($s['cout_intervention']) && $s['cout_intervention'] !== null ? (float)$s['cout_intervention'] : null;
          $datePrevue = $s['date_intervention_prevue'] ?? '';
          $satisfaction = isset($s['satisfaction_client']) ? (int)$s['satisfaction_client'] : 0;
          $commentaireClient = $s['commentaire_client'] ?? '';

          $searchText = strtolower(
              $clientNom . ' ' . $ref . ' ' . $description . ' ' . $technicienNomComplet
          );

          $canEditThis = canEditSav($s);
          $rowClasses = [];
          if ($isUrgent)  $rowClasses[] = 'row-alert';
          if ($isToday) $rowClasses[] = 'row-today';
          $rowClassAttr = $rowClasses ? ' class="'.h(implode(' ', $rowClasses)).'"' : '';
        ?>
        <tr
          data-id="<?= (int)$s['id'] ?>"
          data-id-technicien="<?= (int)($s['id_technicien'] ?? 0) ?>"
          data-client-id="<?= (int)($s['id_client'] ?? 0) ?>"
          data-search="<?= h($searchText) ?>"
          data-client="<?= h($clientNom) ?>"
          data-ref="<?= h($ref) ?>"
          data-description="<?= h($description) ?>"
          data-ouverture="<?= h($ouvertureLabel) ?>"
          data-fermeture="<?= h($fermetureLabel) ?>"
          data-statut="<?= h($statut) ?>"
          data-priorite="<?= h($priorite) ?>"
          data-type-panne="<?= h($typePanne ?? '') ?>"
          data-technicien="<?= h($technicienNomComplet) ?>"
          data-commentaire="<?= h($commentaire) ?>"
          data-commentaire-technicien="<?= h($commentaireTechnicien) ?>"
          data-temps-estime="<?= h($tempsEstime !== null ? (string)$tempsEstime : '') ?>"
          data-temps-reel="<?= h($tempsReel !== null ? (string)$tempsReel : '') ?>"
          data-cout="<?= h($coutIntervention !== null ? (string)$coutIntervention : '') ?>"
          data-date-prevue="<?= h($datePrevue) ?>"
          data-satisfaction="<?= h((string)$satisfaction) ?>"
          data-commentaire-client="<?= h($commentaireClient) ?>"
          data-can-edit="<?= $canEditThis ? '1' : '0' ?>"
          <?= $rowClassAttr ?>
        >
          <td data-th="Client">
            <div class="client-cell">
              <div class="client-raison"><?= h($clientNom) ?></div>
              <div class="client-num"><?= h($ref) ?></div>
            </div>
          </td>
          <td data-th="Référence"><?= h($ref) ?></td>
          <td data-th="Description">
            <div class="machine-cell">
              <div class="machine-line"><?= h($description) ?></div>
              <?php if ($commentaire): ?>
                <div class="machine-sub">Note: <?= h($commentaire) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td data-th="Type de panne">
            <?php if ($typePanne): ?>
              <span style="padding: 0.25rem 0.5rem; border-radius: 4px; background: <?= h($typePanneColor) ?>; color: white; font-size: 0.75rem;">
                <?= h($typePanneLabel) ?>
              </span>
            <?php else: ?>
              <span style="color: #9ca3af;">—</span>
            <?php endif; ?>
          </td>
          <td class="td-date" data-th="Date ouverture">
            <?= h($ouvertureLabel) ?>
            <?php if (!empty($datePrevue)): ?>
              <?php
                $isFuturePrevue = ($datePrevue > $today);
                $isLatePrevue = ($datePrevue < $today && $statut !== 'resolu');
              ?>
              <?php if ($isFuturePrevue): ?>
                <div style="font-size:0.75rem;color:#2563eb;">📅 Prévue : <?= h(formatDate($datePrevue)) ?></div>
              <?php elseif ($isLatePrevue): ?>
                <div style="font-size:0.75rem;color:#ef4444;">⚠️ En retard : <?= h(formatDate($datePrevue)) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="td-date" data-th="Date fermeture"><?= h($fermetureLabel) ?></td>
          <td data-th="Technicien"><?= h($technicienNomComplet) ?></td>
          <td data-th="Priorité">
            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; background: <?= h($prioriteColor) ?>; color: white; font-size: 0.75rem;">
              <?= h($prioriteLabel) ?>
            </span>
          </td>
          <td data-th="Durée / Coût">
            <?php
              $dureeLabel = '—';
              if ($tempsReel !== null) {
                $hours = floor($tempsReel);
                $mins = (int)round(($tempsReel - $hours) * 60);
                $dureeLabel = sprintf('%dh%02d', $hours, $mins);
              } elseif ($tempsEstime !== null) {
                $hours = floor($tempsEstime);
                $mins = (int)round(($tempsEstime - $hours) * 60);
                $dureeLabel = sprintf('%dh%02d', $hours, $mins);
              }
            ?>
            <span style="<?= $tempsReel === null && $tempsEstime !== null ? 'color:#9ca3af;' : '' ?>"><?= h($dureeLabel) ?></span>
            <?php if ($coutIntervention !== null): ?>
              <span> — <?= h(number_format($coutIntervention, 0, ',', ' ')) ?>€</span>
            <?php endif; ?>
          </td>
          <td class="td-date has-pullout" data-th="Statut">
            <?= h($statutLabel) ?>
            <?php if ($isUrgent): ?>
              <span class="alert-pullout" title="SAV urgent">
                ⚠️ Urgent
              </span>
            <?php elseif ($isToday): ?>
              <span class="badge-today" title="Ouvert ou fermé aujourd'hui">
                📅 Aujourd'hui
              </span>
            <?php endif; ?>
            <?php if (($s['statut'] ?? '') === 'resolu' && !empty($s['satisfaction_client'])): ?>
              <div style="color:#f59e0b;"><?= str_repeat('★', (int)$s['satisfaction_client']) ?><?= str_repeat('☆', 5 - (int)$s['satisfaction_client']) ?></div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Popup édition SAV -->
<div id="savModalOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="editSavModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="editSavModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="editSavModalTitle">Modifier le SAV</h3>
    <button type="button" id="btnCloseSavModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>

  <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
    <input type="hidden" name="action" value="update_sav">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
    <input type="hidden" name="sav_id" id="sav_id">

    <div class="form-grid-2">
      <div class="card-like">
        <div class="subsection-title">Informations</div>
        <label>Client</label>
        <input type="text" id="modal_client" readonly>

        <label>Référence</label>
        <input type="text" id="modal_ref" readonly>

        <label>Description</label>
        <textarea id="modal_description" rows="3" readonly></textarea>
      </div>

      <div class="card-like">
        <div class="subsection-title">Statut & dates</div>
        <div class="grid-two">
          <div>
            <label>Date ouverture</label>
            <input type="text" id="modal_ouverture" readonly>
          </div>
          <div>
            <label>Date fermeture</label>
            <input type="text" id="modal_fermeture" readonly>
          </div>
        </div>
        <!-- [Fonctionnalité D] -->
        <label>Date intervention prévue</label>
        <input type="date" name="date_intervention_prevue" id="modal_date_prevue">

        <?php if ($isAdminOrDirigeant): ?>
        <label for="modal_id_technicien">Technicien assigné</label>
        <select name="id_technicien" id="modal_id_technicien">
          <option value="">— Non assigné —</option>
          <?php foreach ($savTechniciensOptions as $tech): ?>
            <?php
              $tid = (int) ($tech['id'] ?? 0);
              $tlabel = trim(($tech['prenom'] ?? '') . ' ' . ($tech['nom'] ?? ''));
            ?>
            <option value="<?= $tid ?>"><?= h($tlabel !== '' ? $tlabel : ('#' . $tid)) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <label>Technicien</label>
        <input type="text" id="modal_technicien" readonly>
        <?php endif; ?>

        <label>Priorité</label>
        <select name="priorite" id="modal_priorite">
          <option value="basse">Basse</option>
          <option value="normale">Normale</option>
          <option value="haute">Haute</option>
          <option value="urgente">Urgente</option>
        </select>

        <label>Type de panne</label>
        <select name="type_panne" id="modal_type_panne">
          <option value="">— Non spécifié —</option>
          <option value="logiciel">Logiciel</option>
          <option value="materiel">Matériel</option>
          <option value="piece_rechangeable">Pièce rechargeable</option>
        </select>

        <label>Statut</label>
        <select name="statut" id="modal_statut">
          <option value="ouvert">Ouvert</option>
          <option value="en_cours">En cours</option>
          <option value="resolu">Résolu</option>
          <option value="annule">Annulé</option>
        </select>

        <label>Commentaire (lecture seule)</label>
        <textarea id="modal_commentaire" rows="3" readonly></textarea>

        <label>Commentaire technicien</label>
        <textarea name="commentaire_technicien" id="modal_commentaire_technicien" rows="4" placeholder="Ajoutez vos notes techniques ici..."></textarea>
        <small style="color: #6b7280; font-size: 0.85rem;">Ce commentaire est visible uniquement par les techniciens et administrateurs.</small>
        <!-- [Fonctionnalité B] -->
        <div class="subsection-title">Pièces utilisées</div>
        <div id="pieces-list"><p style="color:#9ca3af;font-size:0.82rem;">Chargement...</p></div>
        <button type="button" id="btn-add-piece">+ Ajouter une pièce</button>
        <div id="piece-inline-form" style="display:none;margin-top:8px;">
          <select id="piece_product_type">
            <option value="papier">papier</option>
            <option value="toner">toner</option>
            <option value="lcd">lcd</option>
            <option value="pc">pc</option>
          </select>
          <input type="number" id="piece_product_id" placeholder="product_id">
          <input type="number" id="piece_quantite" value="1" min="1">
          <button type="button" id="btn-piece-save">Ajouter</button>
        </div>
        <!-- [Fonctionnalité C] -->
        <label>Temps estimé (heures)</label>
        <input type="number" name="temps_estime" id="modal_temps_estime" min="0" max="99" step="0.25" placeholder="ex: 1.5">
        <label>Temps réel (heures)</label>
        <input type="number" name="temps_reel" id="modal_temps_reel" min="0" max="99" step="0.25" placeholder="ex: 2.0">
        <label>Coût intervention (€ HT)</label>
        <input type="number" name="cout_intervention" id="modal_cout" min="0" step="0.01" placeholder="ex: 150.00">
        <!-- [Fonctionnalité E] -->
        <div id="section-satisfaction" style="display:none;">
          <div class="subsection-title">Retour client</div>
          <label>Note de satisfaction (1 à 5 étoiles)</label>
          <div id="star-rating" style="display:flex;gap:6px;cursor:pointer;">
            <?php for($i=1;$i<=5;$i++): ?>
            <span class="star" data-val="<?=$i?>" style="font-size:1.5rem;color:#d1d5db;">★</span>
            <?php endfor; ?>
          </div>
          <input type="hidden" name="satisfaction_client" id="modal_satisfaction" value="">
          <label style="margin-top:10px;">Commentaire client</label>
          <textarea name="commentaire_client" id="modal_commentaire_client" rows="3" placeholder="Commentaire laissé par le client..."></textarea>
        </div>

        <div id="modal_permission_msg" style="margin-top:0.5rem; font-size:0.85rem;"></div>
      </div>
    </div>
    <!-- [Fonctionnalité G] -->
    <div style="margin-top:16px;border-top:1px solid #e5e7eb;padding-top:12px;">
      <div style="font-size:0.85rem;font-weight:600;color:#374151;margin-bottom:8px;">Historique des modifications</div>
      <div id="sav-historique-list" style="max-height:180px;overflow-y:auto;">
        <p style="color:#9ca3af;font-size:0.82rem;">Chargement...</p>
      </div>
    </div>

    <div class="modal-actions">
      <div class="modal-hint">
        <strong>Permissions :</strong> Seul le technicien assigné à ce SAV peut modifier son statut. Les administrateurs et dirigeants peuvent modifier tous les SAV.
      </div>
      <button type="submit" id="modal_submit_btn" class="fiche-action-btn">Enregistrer</button>
    </div>
  </form>
</div>

<!-- [Fonctionnalité A] -->
<div id="createSavOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="createSavModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="createSavModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="createSavModalTitle">Nouveau SAV</h3>
    <button type="button" id="btnCloseCreateSavModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>
  <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
    <input type="hidden" name="action" value="create_sav">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
    <label>Client</label>
    <input type="text" id="create_sav_client_search" placeholder="Rechercher client...">
    <select name="id_client" id="create_sav_client_id" required></select>
    <label>Description</label>
    <textarea name="description" required rows="3"></textarea>
    <label>Priorité</label>
    <select name="priorite">
      <option value="basse">basse</option>
      <option value="normale" selected>normale</option>
      <option value="haute">haute</option>
      <option value="urgente">urgente</option>
    </select>
    <label>Type de panne</label>
    <select name="type_panne">
      <option value="">—</option>
      <option value="logiciel">logiciel</option>
      <option value="materiel">materiel</option>
      <option value="piece_rechangeable">piece_rechangeable</option>
    </select>
    <?php if ($isAdminOrDirigeant): ?>
      <label>Technicien assigné</label>
      <select name="id_technicien">
        <option value="">— Non assigné —</option>
        <?php foreach ($savTechniciensOptions as $tech): ?>
          <option value="<?= (int)$tech['id'] ?>"><?= h(trim(($tech['prenom'] ?? '') . ' ' . ($tech['nom'] ?? ''))) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <label>Date intervention prévue</label>
    <input type="date" name="date_intervention_prevue">
    <div class="modal-actions">
      <button type="submit" class="fiche-action-btn">Créer</button>
    </div>
  </form>
</div>

<div id="pdfExportSavOverlay" class="popup-overlay" style="display:none;" aria-hidden="true"></div>
<div id="pdfExportSavModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="pdfExportSavTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="pdfExportSavTitle">Exporter le rapport SAV (PDF)</h3>
    <button type="button" id="btnClosePdfExportSav" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>
  <div class="standard-form modal-form" style="padding:1rem 1.25rem 1.25rem;">
    <label for="pdfSavDateDebut">Date début</label>
    <input type="date" id="pdfSavDateDebut" class="filter-input" value="<?= h($pdfExportDateDebutDefault) ?>" required>
    <label for="pdfSavDateFin" style="margin-top:0.75rem;">Date fin</label>
    <input type="date" id="pdfSavDateFin" class="filter-input" value="<?= h($pdfExportDateFinDefault) ?>" required>
    <div class="modal-actions" style="margin-top:1rem;">
      <button type="button" class="btn btn-primary" id="btnPdfSavGo">Télécharger le PDF</button>
    </div>
  </div>
</div>

<script <?= csp_nonce() ?>>
// Gestion modale
(function(){
  // [Fonctionnalité A/B/G]
  const csrfToken = <?= json_encode($CSRF) ?>;
  const overlay   = document.getElementById('savModalOverlay');
  const modal     = document.getElementById('editSavModal');
  const closeBtn  = document.getElementById('btnCloseSavModal');

  const inputId        = document.getElementById('sav_id');
  const inputClient    = document.getElementById('modal_client');
  const inputRef       = document.getElementById('modal_ref');
  const inputDescription = document.getElementById('modal_description');
  const inputOuverture    = document.getElementById('modal_ouverture');
  const inputFermeture    = document.getElementById('modal_fermeture');
  const inputTechnicien   = document.getElementById('modal_technicien');
  const selectTechnicienId = document.getElementById('modal_id_technicien');
  const selectPriorite   = document.getElementById('modal_priorite');
  const selectStatut   = document.getElementById('modal_statut');
  const selectTypePanne = document.getElementById('modal_type_panne');
  const textareaCom    = document.getElementById('modal_commentaire');
  const textareaComTech = document.getElementById('modal_commentaire_technicien');
  const inputDatePrevue = document.getElementById('modal_date_prevue');
  const inputTempsEstime = document.getElementById('modal_temps_estime');
  const inputTempsReel = document.getElementById('modal_temps_reel');
  const inputCout = document.getElementById('modal_cout');
  const inputSatisfaction = document.getElementById('modal_satisfaction');
  const textareaCommentaireClient = document.getElementById('modal_commentaire_client');
  const sectionSatisfaction = document.getElementById('section-satisfaction');
  const stars = document.querySelectorAll('#star-rating .star');
  const piecesList = document.getElementById('pieces-list');
  const btnAddPiece = document.getElementById('btn-add-piece');
  const pieceInlineForm = document.getElementById('piece-inline-form');
  const btnPieceSave = document.getElementById('btn-piece-save');
  const savHistoriqueList = document.getElementById('sav-historique-list');
  const permMsg        = document.getElementById('modal_permission_msg');
  const submitBtn      = document.getElementById('modal_submit_btn');

  function updateSatisfactionVisibility() {
    if (!sectionSatisfaction || !selectStatut) return;
    sectionSatisfaction.style.display = selectStatut.value === 'resolu' ? 'block' : 'none';
  }
  if (selectStatut) {
    selectStatut.addEventListener('change', updateSatisfactionVisibility);
  }
  stars.forEach(s => {
    s.addEventListener('click', function() {
      const val = parseInt(this.dataset.val || '0', 10);
      if (inputSatisfaction) inputSatisfaction.value = String(val);
      stars.forEach(st => {
        st.style.color = parseInt(st.dataset.val || '0', 10) <= val ? '#f59e0b' : '#d1d5db';
      });
    });
  });

  function loadPieces(idSav) {
    if (!piecesList) return;
    piecesList.innerHTML = '<p style="color:#9ca3af;font-size:0.82rem;">Chargement...</p>';
    fetch('/API/sav/pieces_get.php?id_sav=' + encodeURIComponent(String(idSav)), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.success) throw new Error('load pieces failed');
        const pieces = Array.isArray(d.pieces) ? d.pieces : [];
        if (pieces.length === 0) {
          piecesList.innerHTML = '<p style="color:#9ca3af;font-size:0.82rem;">Aucune pièce ajoutée.</p>';
          return;
        }
        piecesList.innerHTML = '<table style="width:100%;font-size:0.82rem;"><thead><tr><th>Produit</th><th>Type</th><th>Quantité</th><th>Supprimer</th></tr></thead><tbody>' +
          pieces.map(p => '<tr><td>' + ((p.marque || '') + ' ' + (p.modele || '')).trim() + ' (#' + p.product_id + ')</td><td>' + (p.product_type || '') + '</td><td>' + (p.quantite || '') + '</td><td><button type="button" class="btn-del-piece" data-piece="' + p.id + '" data-sav="' + idSav + '">Suppr.</button></td></tr>').join('') +
          '</tbody></table>';
        piecesList.querySelectorAll('.btn-del-piece').forEach(btn => {
          btn.addEventListener('click', function() {
            fetch('/API/sav/pieces_delete.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-CSRF-Token': csrfToken },
              body: new URLSearchParams({ id_piece: btn.dataset.piece || '', id_sav: btn.dataset.sav || '', csrf_token: csrfToken }).toString()
            }).then(r => r.json()).then(resp => {
              if (resp && resp.success) loadPieces(idSav);
            });
          });
        });
      })
      .catch(() => { piecesList.innerHTML = '<p style="color:#ef4444;font-size:0.82rem;">Erreur de chargement des pièces.</p>'; });
  }

  function loadHistorique(idSav) {
    if (!savHistoriqueList) return;
    savHistoriqueList.innerHTML = '<p style="color:#9ca3af;font-size:0.82rem;">Chargement...</p>';
    fetch('/API/sav/historique_get.php?id_sav=' + encodeURIComponent(String(idSav)), { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d || !d.success) throw new Error('load hist failed');
        const list = Array.isArray(d.historique) ? d.historique : [];
        if (!list.length) {
          savHistoriqueList.innerHTML = '<p style="color:#9ca3af;font-size:0.82rem;">Aucun historique.</p>';
          return;
        }
        savHistoriqueList.innerHTML = list.map(item => {
          const who = ((item.prenom || '') + ' ' + (item.nom || '')).trim() || 'Système';
          const date = item.date_action || '';
          const txt = item.details || item.action || '';
          return '<div style="padding:6px 0;border-bottom:1px solid #f1f5f9;"><span style="color:#9ca3af;font-size:0.78rem;">[' + date + ']</span> - <span style="color:#111827;">' + who + ' : ' + txt + '</span></div>';
        }).join('');
      })
      .catch(() => { savHistoriqueList.innerHTML = '<p style="color:#ef4444;font-size:0.82rem;">Erreur de chargement.</p>'; });
  }

  function openModal(){
    document.body.classList.add('modal-open');
    overlay.setAttribute('aria-hidden','false');
    overlay.style.display='block';
    modal.style.display='block';
  }
  function closeModal(){
    document.body.classList.remove('modal-open');
    overlay.setAttribute('aria-hidden','true');
    overlay.style.display='none';
    modal.style.display='none';
  }

  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (overlay)  overlay.addEventListener('click', closeModal);

  // Lignes cliquables -> ouverture modale d'édition
  const rows = document.querySelectorAll('table#tbl tbody tr[data-id]');
  rows.forEach(tr => {
    tr.style.cursor = 'pointer';
    tr.addEventListener('click', (e) => {
      if (window.getSelection && String(window.getSelection())) return;

      const id        = tr.getAttribute('data-id');
      const client    = tr.getAttribute('data-client') || '';
      const ref       = tr.getAttribute('data-ref') || '';
      const description   = tr.getAttribute('data-description') || '';
      const ouverture    = tr.getAttribute('data-ouverture') || '';
      const fermeture    = tr.getAttribute('data-fermeture') || '';
      const technicien   = tr.getAttribute('data-technicien') || '';
      const idTechnicien = tr.getAttribute('data-id-technicien') || '';
      const priorite    = tr.getAttribute('data-priorite') || 'normale';
      const statut    = tr.getAttribute('data-statut') || 'ouvert';
      const typePanne = tr.getAttribute('data-type-panne') || '';
      const com       = tr.getAttribute('data-commentaire') || '';
      const comTech   = tr.getAttribute('data-commentaire-technicien') || '';
      const tempsEstime = tr.getAttribute('data-temps-estime') || '';
      const tempsReel = tr.getAttribute('data-temps-reel') || '';
      const cout = tr.getAttribute('data-cout') || '';
      const datePrevue = tr.getAttribute('data-date-prevue') || '';
      const satisfaction = tr.getAttribute('data-satisfaction') || '';
      const commentaireClient = tr.getAttribute('data-commentaire-client') || '';
      const canEdit   = tr.getAttribute('data-can-edit') === '1';

      if (inputId)      inputId.value = id;
      if (inputClient)  inputClient.value = client;
      if (inputRef)     inputRef.value = ref;
      if (inputDescription) inputDescription.value = description;
      if (inputOuverture)  inputOuverture.value = ouverture;
      if (inputFermeture)  inputFermeture.value = fermeture;
      if (selectTechnicienId) {
        selectTechnicienId.value = idTechnicien && String(idTechnicien) !== '0' ? String(idTechnicien) : '';
        selectTechnicienId.disabled = !canEdit;
      } else if (inputTechnicien) {
        inputTechnicien.value = technicien;
      }
      if (textareaCom)  textareaCom.value = com;
      if (textareaComTech) {
        textareaComTech.value = comTech;
        textareaComTech.disabled = !canEdit;
      }

      if (selectPriorite) {
        selectPriorite.value = priorite;
        selectPriorite.disabled = !canEdit;
      }

      if (selectStatut) {
        selectStatut.value = statut;
        selectStatut.disabled = !canEdit;
      }
      if (selectTypePanne) {
        selectTypePanne.value = typePanne;
        selectTypePanne.disabled = !canEdit;
      }
      if (inputDatePrevue) {
        inputDatePrevue.value = datePrevue;
        inputDatePrevue.disabled = !canEdit;
      }
      if (inputTempsEstime) {
        inputTempsEstime.value = tempsEstime;
        inputTempsEstime.disabled = !canEdit;
      }
      if (inputTempsReel) {
        inputTempsReel.value = tempsReel;
        inputTempsReel.disabled = !canEdit;
      }
      if (inputCout) {
        inputCout.value = cout;
        inputCout.disabled = !canEdit;
      }
      if (inputSatisfaction) {
        inputSatisfaction.value = satisfaction;
      }
      if (textareaCommentaireClient) {
        textareaCommentaireClient.value = commentaireClient;
        textareaCommentaireClient.disabled = !canEdit;
      }
      stars.forEach(st => {
        st.style.color = parseInt(st.dataset.val || '0', 10) <= parseInt(satisfaction || '0', 10) ? '#f59e0b' : '#d1d5db';
      });
      updateSatisfactionVisibility();

      if (submitBtn) {
        submitBtn.disabled = !canEdit;
      }

      if (permMsg) {
        if (canEdit) {
          permMsg.textContent = '';
          permMsg.style.color = '';
        } else {
          const currentTechnicien = tr.getAttribute('data-technicien') || '—';
          permMsg.textContent = "Vous ne pouvez pas modifier ce SAV. Seul le technicien assigné (" + currentTechnicien + ") ou un administrateur/dirigeant peut modifier le statut.";
          permMsg.style.color = '#dc2626';
        }
      }

      loadPieces(id);
      loadHistorique(id);
      openModal();
    });
  });

  if (btnAddPiece && pieceInlineForm) {
    btnAddPiece.addEventListener('click', function() {
      pieceInlineForm.style.display = pieceInlineForm.style.display === 'none' ? 'block' : 'none';
    });
  }
  if (btnPieceSave) {
    btnPieceSave.addEventListener('click', function() {
      const idSav = inputId ? inputId.value : '';
      const productType = (document.getElementById('piece_product_type') || {}).value || '';
      const productId = (document.getElementById('piece_product_id') || {}).value || '';
      const quantite = (document.getElementById('piece_quantite') || {}).value || '1';
      fetch('/API/sav/pieces_save.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', 'X-CSRF-Token': csrfToken },
        body: new URLSearchParams({
          id_sav: String(idSav),
          product_type: String(productType),
          product_id: String(productId),
          quantite: String(quantite),
          csrf_token: csrfToken
        }).toString()
      }).then(r => r.json()).then(resp => {
        if (resp && resp.success) {
          loadPieces(idSav);
        } else {
          alert((resp && resp.error) ? resp.error : 'Erreur ajout pièce');
        }
      });
    });
  }

  const urlParamsSav = new URLSearchParams(window.location.search);
  const initialClientIdSav = urlParamsSav.get('client_id');

  // Filtre rapide (+ client_id depuis la timeline / fiche)
  const q = document.getElementById('q');
  const clear = document.getElementById('clearQ');
  if (q) {
    const lines = Array.from(document.querySelectorAll('table#tbl tbody tr'));
    function apply(){
      const v = (q.value || '').trim().toLowerCase();
      lines.forEach(tr => {
        if (tr.getAttribute('data-empty-row') === '1') {
          tr.style.display = '';
          return;
        }
        const t = (tr.getAttribute('data-search') || '').toLowerCase();
        const okSearch = !v || t.includes(v);
        const okClient = !initialClientIdSav || (tr.getAttribute('data-client-id') || '') === initialClientIdSav;
        tr.style.display = okSearch && okClient ? '' : 'none';
      });
    }
    if (initialClientIdSav && !q.value) q.placeholder = 'Filtré client #' + initialClientIdSav;
    q.addEventListener('input', apply);
    apply();
    if (clear) {
      clear.addEventListener('click', () => {
        q.value = '';
        apply();
        q.focus();
      });
    }
  }

  (function() {
    const refParam = urlParamsSav.get('ref');
    if (refParam) {
      const rows = document.querySelectorAll('table#tbl tbody tr[data-ref]');
      for (const tr of rows) {
        if (tr.style.display === 'none') continue;
        const rowRef = tr.getAttribute('data-ref');
        if (rowRef && rowRef.trim() === refParam.trim()) {
          setTimeout(() => {
            tr.click();
            tr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            tr.style.backgroundColor = '#fef3c7';
            setTimeout(() => { tr.style.backgroundColor = ''; }, 2000);
          }, 100);
          break;
        }
      }
    }
  })();

  (function() {
    const savIdParam = urlParamsSav.get('sav_id');
    if (!savIdParam || !/^\d+$/.test(savIdParam)) return;
    const trOpen = document.querySelector('table#tbl tbody tr[data-id="' + savIdParam + '"]');
    if (trOpen) {
      setTimeout(() => {
        trOpen.click();
        trOpen.scrollIntoView({ behavior: 'smooth', block: 'center' });
        trOpen.style.backgroundColor = '#fef3c7';
        setTimeout(() => { trOpen.style.backgroundColor = ''; }, 2000);
      }, 120);
    }
  })();

  const pdfOv = document.getElementById('pdfExportSavOverlay');
  const pdfMd = document.getElementById('pdfExportSavModal');
  const btnOpenPdf = document.getElementById('btnOpenPdfExportSav');
  const btnClosePdf = document.getElementById('btnClosePdfExportSav');
  const btnPdfGo = document.getElementById('btnPdfSavGo');
  const pdfD1 = document.getElementById('pdfSavDateDebut');
  const pdfD2 = document.getElementById('pdfSavDateFin');
  function openPdfSavModal() {
    if (!pdfOv || !pdfMd) return;
    document.body.classList.add('modal-open');
    pdfOv.style.display = 'block';
    pdfOv.setAttribute('aria-hidden', 'false');
    pdfMd.style.display = 'block';
  }
  function closePdfSavModal() {
    if (!pdfOv || !pdfMd) return;
    document.body.classList.remove('modal-open');
    pdfOv.style.display = 'none';
    pdfOv.setAttribute('aria-hidden', 'true');
    pdfMd.style.display = 'none';
  }
  if (btnOpenPdf) btnOpenPdf.addEventListener('click', openPdfSavModal);
  if (btnClosePdf) btnClosePdf.addEventListener('click', closePdfSavModal);
  if (pdfOv) pdfOv.addEventListener('click', closePdfSavModal);
  if (btnPdfGo && pdfD1 && pdfD2) {
    btnPdfGo.addEventListener('click', function () {
      const d1 = pdfD1.value;
      const d2 = pdfD2.value;
      if (!d1 || !d2) {
        alert('Veuillez renseigner les deux dates.');
        return;
      }
      if (d1 > d2) {
        alert('La date de début doit être antérieure ou égale à la date de fin.');
        return;
      }
      const url = '/API/export_pdf_sav.php?date_debut=' + encodeURIComponent(d1) + '&date_fin=' + encodeURIComponent(d2);
      window.open(url, '_blank', 'noopener,noreferrer');
      closePdfSavModal();
    });
  }

  // [Fonctionnalité A] create modal + clients autocomplete
  const createOv = document.getElementById('createSavOverlay');
  const createMd = document.getElementById('createSavModal');
  const btnOpenCreate = document.getElementById('btnOpenCreateSav');
  const btnCloseCreate = document.getElementById('btnCloseCreateSavModal');
  const createSearch = document.getElementById('create_sav_client_search');
  const createSelect = document.getElementById('create_sav_client_id');
  function openCreateModal() {
    if (!createOv || !createMd) return;
    document.body.classList.add('modal-open');
    createOv.style.display = 'block';
    createOv.setAttribute('aria-hidden', 'false');
    createMd.style.display = 'block';
  }
  function closeCreateModal() {
    if (!createOv || !createMd) return;
    document.body.classList.remove('modal-open');
    createOv.style.display = 'none';
    createOv.setAttribute('aria-hidden', 'true');
    createMd.style.display = 'none';
  }
  if (btnOpenCreate) btnOpenCreate.addEventListener('click', openCreateModal);
  if (btnCloseCreate) btnCloseCreate.addEventListener('click', closeCreateModal);
  if (createOv) createOv.addEventListener('click', closeCreateModal);
  if (createSearch && createSelect) {
    createSearch.addEventListener('input', function() {
      const qv = (createSearch.value || '').trim();
      if (qv.length < 1) {
        createSelect.innerHTML = '';
        return;
      }
      fetch('/API/clients_search.php?q=' + encodeURIComponent(qv), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
          const results = (d && d.ok && Array.isArray(d.results)) ? d.results : [];
          createSelect.innerHTML = results.map(item => '<option value="' + item.id + '">' + item.nom + '</option>').join('');
        });
    });
  }
})();
</script>
</body>
</html>

