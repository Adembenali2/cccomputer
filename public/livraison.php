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

                            // Si la livraison vient d'être marquée comme "livrée", ajouter au stock client
                            if ($isBecomingLivree) {
                            $productType = $liv['product_type'] ?? null;
                            $productId = isset($liv['product_id']) ? (int)$liv['product_id'] : 0;
                            $productQty = isset($liv['product_qty']) ? (int)$liv['product_qty'] : 0;
                            $clientId = (int)($liv['id_client'] ?? 0);

                            // Log pour débogage
                            error_log("Livraison #{$livraisonId} marquée livrée - Client: {$clientId}, Type: {$productType}, ID: {$productId}, Qty: {$productQty}");

                            if (!empty($productType) && $productId > 0 && $productQty > 0 && $clientId > 0) {
                                if (in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) {
                                    try {
                                        // Vérifier si le stock client existe déjà pour ce produit
                                        $checkStock = $pdo->prepare("
                                            SELECT id, qty_stock 
                                            FROM client_stock 
                                            WHERE id_client = :client_id 
                                              AND product_type = :product_type 
                                              AND product_id = :product_id 
                                            LIMIT 1
                                        ");
                                        $checkStock->execute([
                                            ':client_id' => $clientId,
                                            ':product_type' => $productType,
                                            ':product_id' => $productId
                                        ]);
                                        $existingStock = $checkStock->fetch(PDO::FETCH_ASSOC);

                                        if ($existingStock) {
                                            // Mettre à jour le stock existant
                                            $updateStock = $pdo->prepare("
                                                UPDATE client_stock 
                                                SET qty_stock = qty_stock + :qty,
                                                    updated_at = NOW()
                                                WHERE id = :id
                                            ");
                                            $updateStock->execute([
                                                ':qty' => $productQty,
                                                ':id' => $existingStock['id']
                                            ]);
                                            error_log("Stock client mis à jour - ID: {$existingStock['id']}, Nouvelle qty: " . ($existingStock['qty_stock'] + $productQty));
                                        } else {
                                            // Créer un nouveau stock client
                                            $insertStock = $pdo->prepare("
                                                INSERT INTO client_stock (id_client, product_type, product_id, qty_stock)
                                                VALUES (:client_id, :product_type, :product_id, :qty)
                                            ");
                                            $insertStock->execute([
                                                ':client_id' => $clientId,
                                                ':product_type' => $productType,
                                                ':product_id' => $productId,
                                                ':qty' => $productQty
                                            ]);
                                            error_log("Nouveau stock client créé - Client: {$clientId}, Type: {$productType}, ID: {$productId}, Qty: {$productQty}");
                                        }
                                    } catch (PDOException $e) {
                                        error_log("Erreur lors de l'ajout au stock client: " . $e->getMessage());
                                        // Ne pas faire échouer la transaction pour cette erreur
                                    }
                                } else {
                                    error_log("Type de produit invalide: {$productType}");
                                }
                            } else {
                                error_log("Données produit manquantes ou invalides - Type: " . ($productType ?? 'null') . ", ID: {$productId}, Qty: {$productQty}, Client: {$clientId}");
                                }
                            }

                            $pdo->commit();
                        
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
                            
                            enregistrerAction($pdo, currentUserId(), 'livraison_modifiee', $details);
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
    </div>
  </div>

  <!-- Tableau -->
  <div class="table-wrapper">
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
          <td class="td-date has-pullout" data-th="Statut">
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
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
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

<script>
// Gestion modale
(function(){
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
})();
</script>
</body>
</html>
