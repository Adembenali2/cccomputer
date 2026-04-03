<?php
// /public/sav.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('sav', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

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

        $allowedStatuts = ['ouvert','en_cours','resolu','annule'];
        $allowedPriorites = ['basse','normale','haute','urgente'];
        $allowedTypePanne = ['logiciel','materiel','piece_rechangeable'];
        
        if (!$savId || !in_array($newStatut, $allowedStatuts, true) || !in_array($newPriorite, $allowedPriorites, true)) {
            $flash = ['type'=>'error','msg'=>"Données invalides pour la mise à jour du SAV."];
        } elseif (!empty($newTypePanne) && !in_array($newTypePanne, $allowedTypePanne, true)) {
            $flash = ['type'=>'error','msg'=>"Type de panne invalide."];
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
                        
                        if ($hasNotesTechniques) {
                            $upd = $pdo->prepare("
                                UPDATE sav
                                SET statut = :statut,
                                    priorite = :priorite,
                                    type_panne = :type_panne,
                                    date_fermeture = :date_fermeture,
                                    notes_techniques = :commentaire_technicien,
                                    updated_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->execute([
                                ':statut'      => $newStatut,
                                ':priorite'    => $newPriorite,
                                ':type_panne'  => !empty($newTypePanne) ? $newTypePanne : null,
                                ':date_fermeture' => $dateFermeture,
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
                                    updated_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->execute([
                                ':statut'      => $newStatut,
                                ':priorite'    => $newPriorite,
                                ':type_panne'  => !empty($newTypePanne) ? $newTypePanne : null,
                                ':date_fermeture' => $dateFermeture,
                                ':id'          => $savId,
                            ]);
                        }

                        $pdo->commit();
                        
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
                            
                            enregistrerAction($pdo, currentUserId(), 'sav_modifie', $details);
                            if ($isBecomingResolu) {
                                enregistrerAction($pdo, currentUserId(), 'sav_cloture', sprintf('SAV #%d (%s) clôturé', $savId, $sav['reference'] ?? 'N/A'));
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
            } catch (PDOException $e) {
                error_log('sav.php UPDATE error: ' . $e->getMessage());
                $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour le SAV."];
            }
        }
    }
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

// Vérifier les permissions pour l'archive
if ($view === 'archive' && !$isAdminOrDirigeant) {
    $flash = ['type' => 'error', 'msg' => "Vous n'êtes pas autorisé à accéder à l'archive."];
    $view = 'toutes';
}

if (!in_array($view, ['toutes', 'urgent', 'aujourdhui', 'archive'], true)) {
    $view = 'toutes';
}

$filteredSav = array_values(array_filter($rows, function($s) use ($view, $today) {
    $statut = $s['statut'] ?? '';
    
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
      <a href="/public/sav.php?view=toutes"
         class="btn <?= $view === 'toutes' ? 'btn-primary' : 'btn-outline' ?>">Toutes</a>
      <a href="/public/sav.php?view=urgent"
         class="btn <?= $view === 'urgent' ? 'btn-primary' : 'btn-outline' ?>">Urgents</a>
      <a href="/public/sav.php?view=aujourdhui"
         class="btn <?= $view === 'aujourdhui' ? 'btn-primary' : 'btn-outline' ?>">Aujourd'hui</a>
      <?php if ($isAdminOrDirigeant): ?>
      <a href="/public/sav.php?view=archive"
         class="btn <?= $view === 'archive' ? 'btn-primary' : 'btn-outline' ?>">📦 Archive</a>
      <?php endif; ?>
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
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filteredSav): ?>
        <tr>
          <td colspan="8" style="padding:1rem; color:var(--text-secondary);">
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
          <td class="td-date" data-th="Date ouverture"><?= h($ouvertureLabel) ?></td>
          <td class="td-date" data-th="Date fermeture"><?= h($fermetureLabel) ?></td>
          <td data-th="Technicien"><?= h($technicienNomComplet) ?></td>
          <td data-th="Priorité">
            <span style="padding: 0.25rem 0.5rem; border-radius: 4px; background: <?= h($prioriteColor) ?>; color: white; font-size: 0.75rem;">
              <?= h($prioriteLabel) ?>
            </span>
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

        <label>Technicien</label>
        <input type="text" id="modal_technicien" readonly>

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

        <div id="modal_permission_msg" style="margin-top:0.5rem; font-size:0.85rem;"></div>
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

<script>
// Gestion modale
(function(){
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
  const selectPriorite   = document.getElementById('modal_priorite');
  const selectStatut   = document.getElementById('modal_statut');
  const selectTypePanne = document.getElementById('modal_type_panne');
  const textareaCom    = document.getElementById('modal_commentaire');
  const textareaComTech = document.getElementById('modal_commentaire_technicien');
  const permMsg        = document.getElementById('modal_permission_msg');
  const submitBtn      = document.getElementById('modal_submit_btn');

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
      const priorite    = tr.getAttribute('data-priorite') || 'normale';
      const statut    = tr.getAttribute('data-statut') || 'ouvert';
      const typePanne = tr.getAttribute('data-type-panne') || '';
      const com       = tr.getAttribute('data-commentaire') || '';
      const comTech   = tr.getAttribute('data-commentaire-technicien') || '';
      const canEdit   = tr.getAttribute('data-can-edit') === '1';

      if (inputId)      inputId.value = id;
      if (inputClient)  inputClient.value = client;
      if (inputRef)     inputRef.value = ref;
      if (inputDescription) inputDescription.value = description;
      if (inputOuverture)  inputOuverture.value = ouverture;
      if (inputFermeture)  inputFermeture.value = fermeture;
      if (inputTechnicien) inputTechnicien.value = technicien;
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

      openModal();
    });
  });

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
})();
</script>
</body>
</html>

