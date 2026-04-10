<?php
// /public/livraison.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('livraison', []); // Accessible à tous les utilisateurs connectés
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

/** Permissions : qui peut éditer une livraison ? **/
function canEditDelivery(array $liv): bool {
    $uid  = currentUserId();
    $role = currentUserRole();
    if (!$uid || !$role) return false;

    // Les Admin et Dirigeant peuvent modifier toutes les livraisons
    if (in_array($role, ['Admin', 'Dirigeant'], true)) {
        return true;
    }

    // Les livreurs ne peuvent modifier QUE leurs propres livraisons assignées
    if ($role === 'Livreur') {
        $livreurId = isset($liv['id_livreur']) ? (int)$liv['id_livreur'] : 0;
        return $livreurId > 0 && $livreurId === (int)$uid;
    }

    // Tous les autres rôles (Technicien, Secrétaire, Chargé relation clients) ne peuvent pas modifier
    return false;
}

/** Flash simple **/
$flash = ['type' => null, 'msg' => null];

$CSRF  = ensureCsrfToken();
$today = date('Y-m-d');

$articlesStock = $pdo->query(
  "SELECT id, reference, designation, categorie,
          quantite, quantite_min, unite, contenance,
          marque, modele, couleur_toner
   FROM stock
   WHERE actif = 1 AND quantite > 0
   ORDER BY categorie, designation"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ============================================================================
// POST : mise à jour de livraison (statut, éventuellement date_reelle)
// ============================================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_delivery') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
    } catch (RuntimeException $csrfEx) {
        $flash = ['type' => 'error', 'msg' => $csrfEx->getMessage()];
    }

    if (!$flash['type']) {
        $livraisonId = (int)($_POST['livraison_id'] ?? 0);
        $newStatut   = $_POST['statut'] ?? '';
        $newDatePrevue = trim($_POST['date_prevue'] ?? '');

        $allowedStatuts = ['planifiee','en_cours','livree','annulee'];
        if (!$livraisonId || !in_array($newStatut, $allowedStatuts, true)) {
            $flash = ['type'=>'error','msg'=>"Données invalides pour la mise à jour de la livraison."];
        } else {
            try {
                // Récupération de la livraison pour vérifier permissions + date_reelle actuelle
                // Inclure les colonnes product_type, product_id, product_qty
                $stmt = $pdo->prepare("
                    SELECT l.id, l.id_client, l.id_livreur, l.reference, l.adresse_livraison, 
                           l.objet, l.date_prevue, l.date_reelle, l.statut, l.commentaire,
                           l.product_type, l.product_id, l.product_qty,
                           l.created_at, l.updated_at
                    FROM livraisons l
                    WHERE l.id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $livraisonId]);
                $liv = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$liv) {
                    $flash = ['type'=>'error','msg'=>"Livraison introuvable."];
                } elseif (!canEditDelivery($liv)) {
                    $flash = ['type'=>'error','msg'=>"Vous n'êtes pas autorisé à modifier cette livraison."];
                } else {
                    // Gestion automatique de la date_reelle :
                    // - si on passe en "livree" et qu'il n'y a pas encore de date_reelle -> on met aujourd'hui
                    // - si on passe d'un statut "livree" à un autre -> on supprime la date_reelle (replanification)
                    $dateReelle = $liv['date_reelle'] ?? null;
                    $oldStatut = $liv['statut'] ?? '';
                    $isBecomingLivree = ($newStatut === 'livree' && $oldStatut !== 'livree');
                    $isLeavingLivree = ($oldStatut === 'livree' && $newStatut !== 'livree');
                    
                    if ($newStatut === 'livree' && empty($dateReelle)) {
                        // Passer à "livrée" : mettre la date réelle à aujourd'hui si elle n'existe pas
                        $dateReelle = $today;
                    } elseif ($newStatut !== 'livree') {
                        // Passer à un autre statut (planifiee, en_cours, annulee) : supprimer la date réelle
                        // Cela permet de replanifier la livraison et de la sortir des archives
                        $dateReelle = null;
                    }
                    
                    // Gestion de la date prévue : utiliser la nouvelle date si fournie, sinon garder l'ancienne
                    $datePrevue = !empty($newDatePrevue) ? $newDatePrevue : ($liv['date_prevue'] ?? null);
                    
                    // Validation de la date prévue si fournie
                    if (!empty($newDatePrevue)) {
                        $dateParts = explode('-', $newDatePrevue);
                        if (count($dateParts) !== 3 || !checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])) {
                            $flash = ['type'=>'error','msg'=>"Date prévue invalide."];
                            $datePrevue = $liv['date_prevue'] ?? null;
                        }
                    }

                    if (!$flash['type']) {
                        $pdo->beginTransaction();
                        try {
                            $upd = $pdo->prepare("
                                UPDATE livraisons
                                SET statut = :statut,
                                    date_prevue = :date_prevue,
                                    date_reelle = :date_reelle,
                                    updated_at = NOW()
                                WHERE id = :id
                            ");
                            $upd->execute([
                                ':statut'      => $newStatut,
                                ':date_prevue'  => $datePrevue,
                                ':date_reelle' => $dateReelle,
                                ':id'          => $livraisonId,
                            ]);

                            // Si la livraison vient d'être marquée comme "livrée", décrémenter le stock global
                            if ($isBecomingLivree) {
                            $productType = $liv['product_type'] ?? null;
                            $productId = isset($liv['product_id']) ? (int)$liv['product_id'] : 0;
                            $productQty = isset($liv['product_qty']) ? (int)$liv['product_qty'] : 0;
                            $clientId = (int)($liv['id_client'] ?? 0);

                            // Log pour débogage
                            error_log("Livraison #{$livraisonId} marquée livrée - Client: {$clientId}, Type: {$productType}, ID: {$productId}, Qty: {$productQty}");

                            if (!empty($productType) && $productId > 0 && $productQty > 0 && $clientId > 0) {
                                $stmtQte = $pdo->prepare("SELECT quantite FROM stock WHERE id = ? FOR UPDATE");
                                $stmtQte->execute([$productId]);
                                $qteAvant = (int)($stmtQte->fetchColumn() ?: 0);
                                $qteApres = $qteAvant - $productQty;
                                if ($qteApres < 0) {
                                    throw new RuntimeException("Stock insuffisant pour valider la livraison.");
                                }

                                $stmtMv = $pdo->prepare("
                                  INSERT INTO stock_mouvements
                                    (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by)
                                  VALUES (?, 'livraison', ?, ?, ?, ?, ?, ?)
                                ");
                                $stmtMv->execute([
                                  $productId,
                                  $productQty,
                                  $qteAvant,
                                  $qteApres,
                                  'Livraison client',
                                  (string)($liv['reference'] ?? ''),
                                  (int)($_SESSION['user_id'] ?? 0)
                                ]);

                                $stmtUpd = $pdo->prepare("UPDATE stock SET quantite = quantite - ? WHERE id = ? AND quantite >= ?");
                                $stmtUpd->execute([$productQty, $productId, $productQty]);
                                if ($stmtUpd->rowCount() === 0) {
                                    throw new RuntimeException("Impossible de mettre à jour le stock (quantité insuffisante).");
                                }
                            } else {
                                error_log("Données produit manquantes ou invalides - Type: " . ($productType ?? 'null') . ", ID: {$productId}, Qty: {$productQty}, Client: {$clientId}");
                                }
                            }

                            $pdo->commit();

                            if ($newStatut === 'planifiee' && $oldStatut !== 'planifiee') {
                                $notifyLivreurId = (int) ($liv['id_livreur'] ?? 0);
                                if ($notifyLivreurId > 0) {
                                    require_once __DIR__ . '/../includes/NotificationService.php';
                                    $datePrevLabel = !empty($datePrevue) ? (string) $datePrevue : '';
                                    NotificationService::create(
                                        $notifyLivreurId,
                                        'livraison_planifiee',
                                        'Livraison planifiée',
                                        $datePrevLabel !== ''
                                            ? sprintf(
                                                'La livraison #%d (%s) est planifiée (date prévue : %s).',
                                                $livraisonId,
                                                $liv['reference'] ?? 'N/A',
                                                $datePrevLabel
                                            )
                                            : sprintf(
                                                'La livraison #%d (%s) est planifiée.',
                                                $livraisonId,
                                                $liv['reference'] ?? 'N/A'
                                            ),
                                        $livraisonId,
                                        'livraison'
                                    );
                                }
                            }
                        
                        // Enregistrer dans l'historique
                        try {
                            $statutLabels = [
                                'planifiee' => 'Planifiée',
                                'en_cours' => 'En cours',
                                'livree' => 'Livrée',
                                'annulee' => 'Annulée'
                            ];
                            $oldStatutLabel = $statutLabels[$oldStatut] ?? $oldStatut;
                            $newStatutLabel = $statutLabels[$newStatut] ?? $newStatut;
                            
                            $details = sprintf(
                                'Livraison #%d (%s) : statut changé de "%s" à "%s"',
                                $livraisonId,
                                $liv['reference'] ?? 'N/A',
                                $oldStatutLabel,
                                $newStatutLabel
                            );
                            
                            if ($isBecomingLivree && !empty($liv['product_type'])) {
                                $details .= ' - Stock client mis à jour';
                            }
                            
                            if (!empty($dateReelle) && $newStatut === 'livree') {
                                $details .= sprintf(' - Date réelle: %s', $dateReelle);
                            }
                            
                            // Ajouter la date prévue si modifiée
                            if (!empty($newDatePrevue) && $newDatePrevue !== ($liv['date_prevue'] ?? '')) {
                                $details .= sprintf(' - Date prévue: %s', $datePrevue);
                            }
                            
                            // Ajouter une note si replanification
                            if ($isLeavingLivree) {
                                $details .= ' - Livraison replanifiée';
                            }
                            
                            $actionType = $isBecomingLivree ? 'livraison_effectuee' : 'modification';
                            $actionLabel = $isBecomingLivree
                                ? 'Livraison effectuee — ' . (string)($liv['reference'] ?? ('#' . $livraisonId))
                                : 'Livraison mise a jour — ' . (string)($liv['reference'] ?? ('#' . $livraisonId));
                            logAction(
                                $pdo,
                                'livraison',
                                $actionType,
                                $actionLabel,
                                'Nouveau statut: ' . $newStatutLabel,
                                $livraisonId,
                                'livraison.php'
                            );
                        } catch (Throwable $e) {
                            error_log('livraison.php historique error: ' . $e->getMessage());
                            // Ne pas faire échouer la transaction pour une erreur d'historique
                        }
                        
                        $flashMsg = "Livraison mise à jour avec succès.";
                        if ($isBecomingLivree && !empty($liv['product_type'])) {
                            $flashMsg .= " Stock client mis à jour.";
                        }
                        if ($isLeavingLivree) {
                            $flashMsg .= " La livraison a été replanifiée et est sortie de l'archive.";
                        }
                        $flash = ['type'=>'success','msg'=>$flashMsg];
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        error_log('livraisons.php UPDATE/STOCK error: ' . $e->getMessage());
                        $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour la livraison."];
                    }
                    }
                }
            } catch (PDOException $e) {
                error_log('livraisons.php UPDATE error: ' . $e->getMessage());
                $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour la livraison."];
            }
        }
    }
}

// ============================================================================
// Récupération des livraisons depuis la base (pour l’affichage)
// ============================================================================
try {
    $sql = "
        SELECT
            l.*,
            c.raison_sociale AS client_nom,
            u.nom    AS livreur_nom,
            u.prenom AS livreur_prenom
        FROM livraisons l
        LEFT JOIN clients c      ON c.id = l.id_client
        LEFT JOIN utilisateurs u ON u.id = l.id_livreur
        ORDER BY l.date_prevue DESC, l.id DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('livraisons.php SQL error: ' . $e->getMessage());
    $rows = [];
}

// ============================================================================
// Calcul des flags (retard / aujourd’hui) et stats globales
// ============================================================================
$totalLivraisons = count($rows);
$retardCount     = 0;
$todayCount      = 0;
$archiveCount    = 0;

foreach ($rows as $idx => $l) {
    $statut = $l['statut'] ?? '';
    $isLivree = ($statut === 'livree');
    
    // Compter les livraisons archivées
    if ($isLivree) {
        $archiveCount++;
        // Ne pas calculer les flags pour les livraisons archivées
        $rows[$idx]['is_today'] = false;
        $rows[$idx]['is_late']  = false;
        continue;
    }
    
    $prevue = $l['date_prevue'] ?? null;
    $reelle = $l['date_reelle'] ?? null;

    $isToday = false;
    if ($prevue && $prevue === $today) {
        $isToday = true;
    }
    if ($reelle && $reelle === $today) {
        $isToday = true;
    }

    $isLate = false;
    if ($prevue) {
        if ($reelle) {
            if ($reelle > $prevue) $isLate = true;
        } else {
            if ($prevue < $today) $isLate = true;
        }
    }

    $rows[$idx]['is_today'] = $isToday;
    $rows[$idx]['is_late']  = $isLate;

    if ($isLate)  $retardCount++;
    if ($isToday) $todayCount++;
}

// ============================================================================
// Vue (toutes / retard / aujourd’hui / archive)
// ============================================================================
$view = $_GET['view'] ?? 'toutes';
$currentRole = currentUserRole();
$isAdminOrDirigeant = in_array($currentRole, ['Admin', 'Dirigeant'], true);

// Vérifier les permissions pour l'archive
if ($view === 'archive' && !$isAdminOrDirigeant) {
    // Rediriger vers la vue "toutes" si l'utilisateur n'est pas autorisé
    $flash = ['type' => 'error', 'msg' => "Vous n'êtes pas autorisé à accéder à l'archive."];
    $view = 'toutes';
}

if (!in_array($view, ['toutes', 'retard', 'aujourdhui', 'archive'], true)) {
    $view = 'toutes';
}

$filteredLivraisons = array_values(array_filter($rows, function($l) use ($view, $today) {
    $statut = $l['statut'] ?? '';
    
    // Vue "archive" : afficher uniquement les livraisons livrées
    if ($view === 'archive') {
        return $statut === 'livree';
    }
    
    // Pour toutes les autres vues, exclure les livraisons livrées
    if ($statut === 'livree') {
        return false;
    }
    
    // Vue "retard" : afficher uniquement les livraisons en retard
    if ($view === 'retard') {
        $isLate = !empty($l['is_late']);
        // Vérification supplémentaire si is_late n'est pas défini
        if (!$isLate) {
            $prevue = $l['date_prevue'] ?? null;
            $reelle = $l['date_reelle'] ?? null;
            if ($prevue) {
                if ($reelle) {
                    $isLate = ($reelle > $prevue);
                } else {
                    $isLate = ($prevue < $today);
                }
            }
        }
        return $isLate;
    }
    
    // Vue "aujourdhui" : afficher les livraisons prévues ou livrées aujourd'hui
    if ($view === 'aujourdhui') {
        $isToday = !empty($l['is_today']);
        // Vérification supplémentaire si is_today n'est pas défini
        if (!$isToday) {
            $prevue = $l['date_prevue'] ?? null;
            $reelle = $l['date_reelle'] ?? null;
            if ($prevue && $prevue === $today) {
                $isToday = true;
            }
            if ($reelle && $reelle === $today) {
                $isToday = true;
            }
        }
        return $isToday;
    }
    
    // Vue "toutes" : afficher toutes les livraisons sauf les livrées
    return true;
}));

$listedCount      = count($filteredLivraisons);
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
  <title>Planning des livraisons - CCComputer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/livraison.css" />
</head>
<body class="page-livraisons">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
  <div class="page-header">
    <h2 class="page-title">Planning des livraisons</h2>
    <p class="page-sub">
      Vue des livraisons prévues et réalisées — dernière mise à jour <?= h($lastRefreshLabel) ?>.
    </p>
  </div>

  <!-- Flash -->
  <?php if ($flash['type']): ?>
    <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
      <?= $flash['msg'] ?>
    </div>
  <?php endif; ?>

  <!-- Meta cards -->
  <section class="clients-meta">
    <div class="meta-card">
      <span class="meta-label">Livraisons listées</span>
      <strong class="meta-value"><?= h((string)$listedCount) ?></strong>
      <?php if ($listedCount === 0): ?>
        <span class="meta-chip">Aucune donnée</span>
      <?php endif; ?>
      <?php if ($view !== 'archive' && $isAdminOrDirigeant): ?>
        <span class="meta-sub">Archive : <?= h((string)$archiveCount) ?> livraison(s)</span>
      <?php endif; ?>
    </div>

    <div class="meta-card">
      <span class="meta-label">Livraisons en retard</span>
      <strong class="meta-value <?= $retardCount > 0 ? 'danger' : 'success' ?>">
        <?= h((string)$retardCount) ?>
      </strong>
      <span class="meta-sub">
        <?= $retardCount > 0 ? 'À traiter en priorité' : 'Aucun retard détecté' ?>
      </span>
    </div>

    <div class="meta-card">
      <span class="meta-label">Aujourd’hui</span>
      <strong class="meta-value"><?= h((string)$todayCount) ?></strong>
      <span class="meta-sub">Livraisons prévues ou livrées aujourd’hui</span>
    </div>

    <div class="meta-card">
      <span class="meta-label">Vue active</span>
      <strong class="meta-value">
        <?php
          if ($view === 'archive') {
              echo 'Archive';
          } elseif ($view === 'retard') {
              echo 'En retard';
          } elseif ($view === 'aujourdhui') {
              echo 'Aujourd’hui';
          } else {
              echo 'Toutes';
          }
        ?>
      </strong>
      <span class="meta-sub">Filtrer en un clic</span>
    </div>
  </section>

  <!-- Barre de filtres (sans bouton d’ajout) -->
  <div class="filters-row">
    <div class="filters-left">
      <input type="text" id="q" class="filter-input" placeholder="Filtrer (client, référence, adresse, objet, livreur)…">
      <button id="clearQ" class="btn btn-secondary" type="button">Effacer</button>
    </div>
    <div class="filters-actions">
      <a href="/public/livraison.php?view=toutes"
         class="btn <?= $view === 'toutes' ? 'btn-primary' : 'btn-outline' ?>">Toutes</a>
      <a href="/public/livraison.php?view=aujourdhui"
         class="btn <?= $view === 'aujourdhui' ? 'btn-primary' : 'btn-outline' ?>">Aujourd'hui</a>
      <a href="/public/livraison.php?view=retard"
         class="btn <?= $view === 'retard' ? 'btn-primary' : 'btn-outline' ?>">En retard</a>
      <?php if ($isAdminOrDirigeant): ?>
      <a href="/public/livraison.php?view=archive"
         class="btn <?= $view === 'archive' ? 'btn-primary' : 'btn-outline' ?>">📦 Archive</a>
      <?php endif; ?>
      <button type="button" class="btn btn-outline" id="btnOpenPdfExportLiv">Exporter PDF</button>
    </div>
    <div class="view-toggle" id="livViewToggle">
      <button type="button" class="view-btn active" id="btnLivTableau" title="Vue tableau">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/>
        </svg> Tableau
      </button>
      <button type="button" class="view-btn" id="btnLivPlanning" title="Vue planning">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/>
          <line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/>
        </svg> Planning
      </button>
    </div>
  </div>

  <!-- Tableau -->
  <div class="table-wrapper" id="livTableWrap">
    <table class="tbl-livraisons" id="tbl">
      <thead>
        <tr>
          <th>Client</th>
          <th>Référence</th>
          <th>Adresse de livraison</th>
          <th>Objet</th>
          <th>Date prévue</th>
          <th>Date réelle</th>
          <th>Livré par</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$filteredLivraisons): ?>
        <tr data-empty-row="1">
          <td colspan="8" style="padding:1rem; color:var(--text-secondary);">
            Aucune livraison à afficher pour cette vue.
          </td>
        </tr>
      <?php else: ?>
        <?php foreach ($filteredLivraisons as $liv):

          $clientNom = $liv['client_nom'] ?: '—';
          $ref       = $liv['reference'] ?? '—';
          $adresse   = $liv['adresse_livraison'] ?? '—';
          $objet     = $liv['objet'] ?? '—';

          $prevue    = $liv['date_prevue'] ?? null;
          $reelle    = $liv['date_reelle'] ?? null;

          $prevueLabel = $prevue ? date('d/m/Y', strtotime($prevue)) : '—';
          $reelleLabel = $reelle ? date('d/m/Y', strtotime($reelle)) : '—';

          $livreurNomComplet = trim(
              ($liv['livreur_prenom'] ?? '') . ' ' . ($liv['livreur_nom'] ?? '')
          );
          if ($livreurNomComplet === '') {
              $livreurNomComplet = '—';
          }

          $isLate  = !empty($liv['is_late']);
          $isToday = !empty($liv['is_today']);

          if ($reelle) {
              if ($isLate) {
                  $statutLabel = 'Livrée (en retard)';
              } else {
                  $statutLabel = 'Livrée';
              }
          } else {
              $statutLabel = $isLate ? 'En retard' : 'Planifiée';
          }

          $commentaire = $liv['commentaire'] ?? '';

          $searchText = strtolower(
              $clientNom . ' ' . $ref . ' ' . $adresse . ' ' . $objet . ' ' . $livreurNomComplet
          );

          $canEditThis = canEditDelivery($liv);
          $rowClasses = [];
          if ($isLate)  $rowClasses[] = 'row-alert';
          if ($isToday) $rowClasses[] = 'row-today';
          $rowClassAttr = $rowClasses ? ' class="'.h(implode(' ', $rowClasses)).'"' : '';
        ?>
        <tr
          data-id="<?= (int)$liv['id'] ?>"
          data-client-id="<?= (int)($liv['id_client'] ?? 0) ?>"
          data-search="<?= h($searchText) ?>"
          data-client="<?= h($clientNom) ?>"
          data-ref="<?= h($ref) ?>"
          data-adresse="<?= h($adresse) ?>"
          data-objet="<?= h($objet) ?>"
          data-prevue="<?= h($prevueLabel) ?>"
          data-prevue-iso="<?= $prevue ? h($prevue) : '' ?>"
          data-reelle="<?= h($reelleLabel) ?>"
          data-statut="<?= h($liv['statut']) ?>"
          data-livreur="<?= h($livreurNomComplet) ?>"
          data-commentaire="<?= h($commentaire) ?>"
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
          <td data-th="Adresse de livraison">
            <div class="machine-cell">
              <div class="machine-line"><?= h($adresse) ?></div>
              <?php if ($commentaire): ?>
                <div class="machine-sub">Note: <?= h($commentaire) ?></div>
              <?php endif; ?>
            </div>
          </td>
          <td data-th="Objet"><?= h($objet) ?></td>
          <td class="td-date" data-th="Date prévue"><?= h($prevueLabel) ?></td>
          <td class="td-date" data-th="Date réelle"><?= h($reelleLabel) ?></td>
          <td data-th="Livré par"><?= h($livreurNomComplet) ?></td>
          <td class="td-date has-pullout liv-statut-cell" data-th="Statut">
            <div class="liv-statut-badge-wrap">
              <?= h($statutLabel) ?>
              <?php if ($isLate): ?>
                <span class="alert-pullout" title="Livraison en retard">
                  ⚠️ En retard
                </span>
              <?php elseif ($isToday): ?>
                <span class="badge-today" title="Prévue ou livrée aujourd’hui">
                  📅 Aujourd’hui
                </span>
              <?php endif; ?>
            </div>
            <?php
            $rawStatut = $liv['statut'] ?? 'planifiee';
            $pipStatutLabels = [
                'planifiee' => 'Planifiée',
                'en_cours'  => 'En cours',
                'livree'    => 'Livrée',
                'annulee'   => 'Annulée',
            ];
            $statutOrder = ['planifiee', 'en_cours', 'livree'];
            $stepIndex = array_search($rawStatut, $statutOrder, true);
            ?>
            <?php if ($rawStatut !== 'annulee'): ?>
            <div class="liv-pipeline">
              <?php foreach ($statutOrder as $i => $s): ?>
                <div class="pip-step <?= ($stepIndex !== false && $i <= $stepIndex) ? 'pip-done' : '' ?> <?= $s === $rawStatut ? 'pip-current' : '' ?>">
                  <div class="pip-dot"></div>
                  <span class="pip-label"><?= h($pipStatutLabels[$s]) ?></span>
                </div>
                <?php if ($i < count($statutOrder) - 1): ?>
                  <div class="pip-line <?= ($stepIndex !== false && $i < $stepIndex) ? 'pip-line-done' : '' ?>"></div>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <span class="pip-annulee">Annulée</span>
            <?php endif; ?>
            <?php if ($canEditThis && $rawStatut === 'planifiee'): ?>
              <button type="button" class="btn-statut-rapide" data-id="<?= (int)$liv['id'] ?>" data-to="en_cours">▶ Démarrer</button>
            <?php elseif ($canEditThis && $rawStatut === 'en_cours'): ?>
              <button type="button" class="btn-statut-rapide btn-livrer" data-id="<?= (int)$liv['id'] ?>" data-to="livree">✔ Marquer livrée</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div id="livPlanningView" hidden>
    <div class="planning-nav">
      <button type="button" class="planning-nav-btn" id="planPrev">&#8592; Semaine préc.</button>
      <span class="planning-week-label" id="planWeekLabel"></span>
      <button type="button" class="planning-nav-btn" id="planNext">Semaine suiv. &#8594;</button>
    </div>
    <div class="planning-grid" id="planGrid"></div>
  </div>
</div>

<!-- Popup édition livraison -->
<div id="deliveryModalOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="editDeliveryModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="editDeliveryModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="editDeliveryModalTitle">Modifier la livraison</h3>
    <button type="button" id="btnCloseDeliveryModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>

  <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
    <input type="hidden" name="action" value="update_delivery">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
    <input type="hidden" name="livraison_id" id="livraison_id">

    <div class="form-grid-2">
      <div class="card-like">
        <div class="subsection-title">Informations</div>
        <label>Client</label>
        <input type="text" id="modal_client" readonly>

        <label>Référence</label>
        <input type="text" id="modal_ref" readonly>

        <label>Adresse de livraison</label>
        <textarea id="modal_adresse" rows="2" readonly></textarea>

        <label>Objet</label>
        <input type="text" id="modal_objet" readonly>
      </div>

      <div class="card-like">
        <div class="subsection-title">Statut & dates</div>
        <div class="grid-two">
          <div>
            <label>Date prévue</label>
            <input type="date" name="date_prevue" id="modal_prevue">
            <small style="color: #6b7280; font-size: 0.85rem;">Vous pouvez modifier la date prévue pour replanifier la livraison</small>
          </div>
          <div>
            <label>Date réelle</label>
            <input type="text" id="modal_reelle" readonly>
            <small style="color: #6b7280; font-size: 0.85rem;">Remplie automatiquement lors de la livraison</small>
          </div>
        </div>

        <label>Livré par</label>
        <input type="text" id="modal_livreur" readonly>

        <label>Statut</label>
        <select name="statut" id="modal_statut">
          <option value="planifiee">Planifiée</option>
          <option value="en_cours">En cours</option>
          <option value="livree">Livrée</option>
          <option value="annulee">Annulée</option>
        </select>
        <small style="color: #6b7280; font-size: 0.85rem;">Changer le statut de "Livrée" vers un autre statut permet de replanifier la livraison</small>

        <label>Commentaire (lecture seule)</label>
        <textarea id="modal_commentaire" rows="3" readonly></textarea>

        <div id="modal_permission_msg" style="margin-top:0.5rem; font-size:0.85rem;"></div>
      </div>
    </div>

    <div class="modal-actions">
      <div class="modal-hint">
        <strong>Permissions :</strong> Seul le livreur assigné à cette livraison peut modifier son statut. Les administrateurs et dirigeants peuvent modifier toutes les livraisons.<br>
        <strong>Replanification :</strong> Vous pouvez replanifier une livraison déjà livrée en changeant son statut vers "Planifiée" ou "En cours" et en modifiant la date prévue. La date réelle sera automatiquement supprimée.
      </div>
      <button type="submit" id="modal_submit_btn" class="fiche-action-btn">Enregistrer</button>
    </div>
  </form>
</div>

<div id="pdfExportLivOverlay" class="popup-overlay" style="display:none;" aria-hidden="true"></div>
<div id="pdfExportLivModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="pdfExportLivTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="pdfExportLivTitle">Exporter le rapport livraisons (PDF)</h3>
    <button type="button" id="btnClosePdfExportLiv" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>
  <div class="standard-form modal-form" style="padding:1rem 1.25rem 1.25rem;">
    <label for="pdfLivDateDebut">Date début</label>
    <input type="date" id="pdfLivDateDebut" class="filter-input" value="<?= h($pdfExportDateDebutDefault) ?>" required>
    <label for="pdfLivDateFin" style="margin-top:0.75rem;">Date fin</label>
    <input type="date" id="pdfLivDateFin" class="filter-input" value="<?= h($pdfExportDateFinDefault) ?>" required>
    <div class="modal-actions" style="margin-top:1rem;">
      <button type="button" class="btn btn-primary" id="btnPdfLivGo">Télécharger le PDF</button>
    </div>
  </div>
</div>

<script <?= csp_nonce() ?>>
// Gestion modale
(function(){
  const csrfToken = <?= json_encode($CSRF) ?>;
  window.__CSRF_TOKEN__ = csrfToken;

  const overlay   = document.getElementById('deliveryModalOverlay');
  const modal     = document.getElementById('editDeliveryModal');
  const closeBtn  = document.getElementById('btnCloseDeliveryModal');

  const inputId        = document.getElementById('livraison_id');
  const inputClient    = document.getElementById('modal_client');
  const inputRef       = document.getElementById('modal_ref');
  const inputAdresse   = document.getElementById('modal_adresse');
  const inputObjet     = document.getElementById('modal_objet');
  const inputPrevue    = document.getElementById('modal_prevue');
  const inputReelle    = document.getElementById('modal_reelle');
  const inputLivreur   = document.getElementById('modal_livreur');
  const selectStatut   = document.getElementById('modal_statut');
  const textareaCom    = document.getElementById('modal_commentaire');
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
      if (e.target.closest('.btn-statut-rapide')) return;
      // ne pas ouvrir si l'utilisateur est en train de sélectionner du texte
      if (window.getSelection && String(window.getSelection())) return;

      const id        = tr.getAttribute('data-id');
      const client    = tr.getAttribute('data-client') || '';
      const ref       = tr.getAttribute('data-ref') || '';
      const adresse   = tr.getAttribute('data-adresse') || '';
      const objet     = tr.getAttribute('data-objet') || '';
      const prevue    = tr.getAttribute('data-prevue') || '';
      const reelle    = tr.getAttribute('data-reelle') || '';
      const livreur   = tr.getAttribute('data-livreur') || '';
      const statut    = tr.getAttribute('data-statut') || 'planifiee';
      const com       = tr.getAttribute('data-commentaire') || '';
      const canEdit   = tr.getAttribute('data-can-edit') === '1';
      
      // Récupérer la date prévue au format ISO depuis l'attribut data-prevue-iso si disponible
      // Sinon, convertir depuis le format dd/mm/yyyy
      let prevueISO = tr.getAttribute('data-prevue-iso') || '';
      if (!prevueISO && prevue && prevue !== '—') {
        // Convertir dd/mm/yyyy en yyyy-mm-dd
        const parts = prevue.split('/');
        if (parts.length === 3) {
          prevueISO = parts[2] + '-' + parts[1].padStart(2, '0') + '-' + parts[0].padStart(2, '0');
        }
      }

      if (inputId)      inputId.value = id;
      if (inputClient)  inputClient.value = client;
      if (inputRef)     inputRef.value = ref;
      if (inputAdresse) inputAdresse.value = adresse;
      if (inputObjet)   inputObjet.value = objet;
      if (inputPrevue)  {
        inputPrevue.value = prevueISO;
        inputPrevue.disabled = !canEdit;
      }
      if (inputReelle)  inputReelle.value = reelle;
      if (inputLivreur) inputLivreur.value = livreur;
      if (textareaCom)  textareaCom.value = com;

      if (selectStatut) {
        selectStatut.value = statut;
        selectStatut.disabled = !canEdit;
      }

      if (submitBtn) {
        submitBtn.disabled = !canEdit;
      }

      if (permMsg) {
        if (canEdit) {
          permMsg.textContent = '';
          permMsg.style.color = '';
        } else {
          const currentLivreur = tr.getAttribute('data-livreur') || '—';
          permMsg.textContent = "Vous ne pouvez pas modifier cette livraison. Seul le livreur assigné (" + currentLivreur + ") ou un administrateur/dirigeant peut modifier le statut.";
          permMsg.style.color = '#dc2626';
        }
      }

      openModal();
    });
  });

  // Filtre rapide (+ option client_id depuis la timeline / fiche)
  const q = document.getElementById('q');
  const clear = document.getElementById('clearQ');
  const urlParamsLiv = new URLSearchParams(window.location.search);
  const initialClientIdLiv = urlParamsLiv.get('client_id');
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
        const okClient = !initialClientIdLiv || (tr.getAttribute('data-client-id') || '') === initialClientIdLiv;
        tr.style.display = okSearch && okClient ? '' : 'none';
      });
    }
    if (initialClientIdLiv && !q.value) q.placeholder = 'Filtré client #' + initialClientIdLiv;
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
    const refParam = urlParamsLiv.get('ref');
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
    const lid = urlParamsLiv.get('livraison_id');
    if (!lid || !/^\d+$/.test(lid)) return;
    const trLiv = document.querySelector('table#tbl tbody tr[data-id="' + lid + '"]');
    if (trLiv) {
      setTimeout(() => {
        trLiv.click();
        trLiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
        trLiv.style.backgroundColor = '#fef3c7';
        setTimeout(() => { trLiv.style.backgroundColor = ''; }, 2000);
      }, 120);
    }
  })();

  const pdfLivOv = document.getElementById('pdfExportLivOverlay');
  const pdfLivMd = document.getElementById('pdfExportLivModal');
  const btnOpenPdfLiv = document.getElementById('btnOpenPdfExportLiv');
  const btnClosePdfLiv = document.getElementById('btnClosePdfExportLiv');
  const btnPdfLivGo = document.getElementById('btnPdfLivGo');
  const pdfLivD1 = document.getElementById('pdfLivDateDebut');
  const pdfLivD2 = document.getElementById('pdfLivDateFin');
  function openPdfLivModal() {
    if (!pdfLivOv || !pdfLivMd) return;
    document.body.classList.add('modal-open');
    pdfLivOv.style.display = 'block';
    pdfLivOv.setAttribute('aria-hidden', 'false');
    pdfLivMd.style.display = 'block';
  }
  function closePdfLivModal() {
    if (!pdfLivOv || !pdfLivMd) return;
    document.body.classList.remove('modal-open');
    pdfLivOv.style.display = 'none';
    pdfLivOv.setAttribute('aria-hidden', 'true');
    pdfLivMd.style.display = 'none';
  }
  if (btnOpenPdfLiv) btnOpenPdfLiv.addEventListener('click', openPdfLivModal);
  if (btnClosePdfLiv) btnClosePdfLiv.addEventListener('click', closePdfLivModal);
  if (pdfLivOv) pdfLivOv.addEventListener('click', closePdfLivModal);
  if (btnPdfLivGo && pdfLivD1 && pdfLivD2) {
    btnPdfLivGo.addEventListener('click', function () {
      const d1 = pdfLivD1.value;
      const d2 = pdfLivD2.value;
      if (!d1 || !d2) {
        alert('Veuillez renseigner les deux dates.');
        return;
      }
      if (d1 > d2) {
        alert('La date de début doit être antérieure ou égale à la date de fin.');
        return;
      }
      const url = '/API/export_pdf_livraisons.php?date_debut=' + encodeURIComponent(d1) + '&date_fin=' + encodeURIComponent(d2);
      window.open(url, '_blank', 'noopener,noreferrer');
      closePdfLivModal();
    });
  }

  // ===== PLANNING HEBDOMADAIRE + actions statut rapide =====
  (function () {
    const btnTableau = document.getElementById('btnLivTableau');
    const btnPlanning = document.getElementById('btnLivPlanning');
    const planView = document.getElementById('livPlanningView');
    const planGrid = document.getElementById('planGrid');
    const planLabel = document.getElementById('planWeekLabel');
    const tableWrap = document.getElementById('livTableWrap');
    const planPrev = document.getElementById('planPrev');
    const planNext = document.getElementById('planNext');

    if (!btnTableau || !btnPlanning || !planView || !planGrid || !planLabel) return;

    const WEEK_KEY = 'livPlanWeekStart';

    function getMonday(d) {
      const dt = new Date(d.getFullYear(), d.getMonth(), d.getDate());
      const day = dt.getDay();
      const diff = dt.getDate() - day + (day === 0 ? -6 : 1);
      dt.setDate(diff);
      dt.setHours(0, 0, 0, 0);
      return dt;
    }

    function formatDateLocal(d) {
      const y = d.getFullYear();
      const m = String(d.getMonth() + 1).padStart(2, '0');
      const day = String(d.getDate()).padStart(2, '0');
      return y + '-' + m + '-' + day;
    }

    function formatDisplayDate(d) {
      return d.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
    }

    function loadWeekStart() {
      const stored = sessionStorage.getItem(WEEK_KEY);
      if (stored && /^\d{4}-\d{2}-\d{2}$/.test(stored)) {
        const p = stored.split('-').map(Number);
        const dt = new Date(p[0], p[1] - 1, p[2]);
        if (!isNaN(dt.getTime())) return getMonday(dt);
      }
      return getMonday(new Date());
    }

    let currentWeekStart = loadWeekStart();

    function saveWeekStart() {
      sessionStorage.setItem(WEEK_KEY, formatDateLocal(currentWeekStart));
    }

    const JOURS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    const STATUT_COLORS = {
      planifiee: '#3b82f6',
      en_cours: '#f59e0b',
      livree: '#10b981',
      annulee: '#9ca3af'
    };
    const STATUT_LABELS = {
      planifiee: 'Planifiée', en_cours: 'En cours', livree: 'Livrée', annulee: 'Annulée'
    };

    function escP(s) {
      const d = document.createElement('div');
      d.appendChild(document.createTextNode(String(s ?? '')));
      return d.innerHTML;
    }

    function frTodayLabel() {
      const n = new Date();
      const pad = function (x) { return String(x).padStart(2, '0'); };
      return pad(n.getDate()) + '/' + pad(n.getMonth() + 1) + '/' + n.getFullYear();
    }

    function visibleLivRows() {
      return Array.prototype.slice.call(document.querySelectorAll('#tbl tbody tr[data-id]')).filter(function (tr) {
        if (tr.getAttribute('data-empty-row') === '1') return false;
        return tr.style.display !== 'none';
      });
    }

    function getLivraisonsData() {
      const result = [];
      visibleLivRows().forEach(function (tr) {
        const prevIso = tr.getAttribute('data-prevue-iso') || '';
        result.push({
          id: tr.getAttribute('data-id'),
          client: tr.getAttribute('data-client') || '—',
          ref: tr.getAttribute('data-ref') || '—',
          adresse: tr.getAttribute('data-adresse') || '—',
          objet: tr.getAttribute('data-objet') || '—',
          prevue: prevIso,
          statut: tr.getAttribute('data-statut') || 'planifiee',
          livreur: tr.getAttribute('data-livreur') || '—',
          canEdit: tr.getAttribute('data-can-edit') !== '0'
        });
      });
      return result;
    }

    async function postStatut(id, statut) {
      const res = await fetch('/API/livraisons/update_statut.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({ id: parseInt(id, 10), statut: statut, csrf_token: csrfToken })
      });
      return res.json();
    }

    function updateRowAfterStatut(tr, to, data) {
      if (!tr) return;
      tr.setAttribute('data-statut', to);
      if (to === 'livree') {
        const dr = data && data.date_reelle ? String(data.date_reelle) : '';
        if (dr && /^\d{4}-\d{2}-\d{2}$/.test(dr)) {
          const p = dr.split('-');
          tr.setAttribute('data-reelle', p[2] + '/' + p[1] + '/' + p[0]);
        } else {
          tr.setAttribute('data-reelle', frTodayLabel());
        }
      } else {
        tr.setAttribute('data-reelle', '—');
      }
    }

    function buildPlanning() {
      const livraisons = getLivraisonsData();
      planGrid.innerHTML = '';

      const weekEnd = new Date(currentWeekStart.getFullYear(), currentWeekStart.getMonth(), currentWeekStart.getDate() + 6);
      planLabel.textContent = 'Semaine du ' + formatDisplayDate(currentWeekStart) + ' au ' + formatDisplayDate(weekEnd);

      const todayStr = formatDateLocal(new Date());

      for (let i = 0; i < 7; i++) {
        const day = new Date(currentWeekStart.getFullYear(), currentWeekStart.getMonth(), currentWeekStart.getDate() + i);
        const dayStr = formatDateLocal(day);
        const isToday = dayStr === todayStr;

        const dayItems = livraisons.filter(function (l) { return l.prevue && l.prevue.indexOf(dayStr) === 0; });

        const col = document.createElement('div');
        col.className = 'planning-day-col' + (isToday ? ' planning-today' : '');
        col.innerHTML =
          '<div class="planning-day-header">' +
          '<span class="planning-day-name">' + JOURS[i] + '</span>' +
          '<span class="planning-day-date">' + formatDisplayDate(day) + '</span>' +
          (dayItems.length > 0 ? '<span class="planning-day-count">' + dayItems.length + '</span>' : '') +
          '</div>' +
          '<div class="planning-day-cards" id="planDay-' + dayStr + '"></div>';
        planGrid.appendChild(col);

        const cardsContainer = col.querySelector('.planning-day-cards');
        if (dayItems.length === 0) {
          cardsContainer.innerHTML = '<div class="planning-empty">—</div>';
        } else {
          dayItems.forEach(function (liv) {
            const card = document.createElement('div');
            card.className = 'planning-card';
            card.dataset.id = liv.id;
            const addr = liv.adresse || '';
            const addrShort = addr.length > 60 ? addr.substring(0, 60) + '…' : addr;
            const c = STATUT_COLORS[liv.statut] || '#9ca3af';
            const lb = STATUT_LABELS[liv.statut] || liv.statut;
            const movePlan = liv.canEdit && liv.statut === 'planifiee'
              ? '<button type="button" class="plan-btn-statut" data-id="' + escP(liv.id) + '" data-to="en_cours">▶</button>'
              : '';
            const moveLiv = liv.canEdit && liv.statut === 'en_cours'
              ? '<button type="button" class="plan-btn-statut plan-btn-livrer" data-id="' + escP(liv.id) + '" data-to="livree">✔</button>'
              : '';
            card.innerHTML =
              '<div class="planning-card-head">' +
              '<span class="planning-card-ref">' + escP(liv.ref) + '</span>' +
              '<span class="planning-card-badge" style="background:' + c + '">' + escP(lb) + '</span>' +
              '</div>' +
              '<div class="planning-card-client">' + escP(liv.client) + '</div>' +
              '<div class="planning-card-adresse">' + escP(addrShort) + '</div>' +
              '<div class="planning-card-footer"><span>👤 ' + escP(liv.livreur) + '</span></div>' +
              '<div class="planning-card-actions">' +
              '<button type="button" class="plan-btn-detail" data-id="' + escP(liv.id) + '">✏️ Détail</button>' +
              movePlan + moveLiv +
              '</div>';
            cardsContainer.appendChild(card);
          });
        }
      }

      planGrid.querySelectorAll('.plan-btn-detail').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
          e.stopPropagation();
          const tr = document.querySelector('#tbl tbody tr[data-id="' + btn.getAttribute('data-id') + '"]');
          if (tr) tr.click();
        });
      });

      planGrid.querySelectorAll('.plan-btn-statut').forEach(function (btn) {
        btn.addEventListener('click', async function (e) {
          e.stopPropagation();
          btn.disabled = true;
          const id = btn.getAttribute('data-id');
          const to = btn.getAttribute('data-to');
          try {
            const data = await postStatut(id, to);
            if (data.success) {
              const tr = document.querySelector('#tbl tbody tr[data-id="' + id + '"]');
              updateRowAfterStatut(tr, to, data);
              buildPlanning();
            } else {
              alert(data.error || 'Erreur');
              btn.disabled = false;
            }
          } catch (err) {
            alert('Erreur réseau');
            btn.disabled = false;
          }
        });
      });
    }

    function showTableau() {
      btnTableau.classList.add('active');
      btnPlanning.classList.remove('active');
      planView.hidden = true;
      if (tableWrap) tableWrap.hidden = false;
      localStorage.setItem('livView', 'tableau');
    }

    function showPlanning() {
      btnPlanning.classList.add('active');
      btnTableau.classList.remove('active');
      if (tableWrap) tableWrap.hidden = true;
      planView.hidden = false;
      saveWeekStart();
      buildPlanning();
      localStorage.setItem('livView', 'planning');
    }

    btnTableau.addEventListener('click', showTableau);
    btnPlanning.addEventListener('click', showPlanning);

    if (planPrev) {
      planPrev.addEventListener('click', function () {
        currentWeekStart.setDate(currentWeekStart.getDate() - 7);
        saveWeekStart();
        buildPlanning();
      });
    }
    if (planNext) {
      planNext.addEventListener('click', function () {
        currentWeekStart.setDate(currentWeekStart.getDate() + 7);
        saveWeekStart();
        buildPlanning();
      });
    }

    const tableEl = document.getElementById('livTableWrap');
    if (tableEl) {
      tableEl.addEventListener('click', async function (e) {
        const btn = e.target.closest('.btn-statut-rapide');
        if (!btn) return;
        e.stopPropagation();
        e.preventDefault();
        btn.disabled = true;
        try {
          const data = await postStatut(btn.getAttribute('data-id'), btn.getAttribute('data-to'));
          if (data.success) {
            window.location.reload();
          } else {
            alert(data.error || 'Erreur');
            btn.disabled = false;
          }
        } catch (err) {
          alert('Erreur réseau');
          btn.disabled = false;
        }
      });
    }

    const qPlan = document.getElementById('q');
    if (qPlan) {
      qPlan.addEventListener('input', function () {
        if (!planView.hidden) buildPlanning();
      });
    }

    if (localStorage.getItem('livView') === 'planning') showPlanning();
  })();
})();
</script>
</body>
</html>
