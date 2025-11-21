<?php
// /public/livraisons.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/** PDO en mode exceptions **/
if (method_exists($pdo, 'setAttribute')) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {}
}

/** Helpers **/
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function currentUserId(): ?int {
    if (isset($_SESSION['user']['id'])) return (int)$_SESSION['user']['id'];
    if (isset($_SESSION['user_id']))    return (int)$_SESSION['user_id'];
    return null;
}

function currentUserRole(): ?string {
    // Récupérer le rôle depuis la session (comme défini dans auth.php)
    if (isset($_SESSION['emploi'])) return $_SESSION['emploi'];
    // Fallback pour compatibilité
    if (isset($_SESSION['user']['Emploi'])) return $_SESSION['user']['Emploi'];
    if (isset($_SESSION['user']['emploi'])) return $_SESSION['user']['emploi'];
    return null;
}

/** CSRF minimal (même logique que dans clients.php) **/
function ensureCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function assertValidCsrf(string $token): void {
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        throw new RuntimeException("Session expirée. Veuillez recharger la page.");
    }
}

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
                    // - sinon on laisse la date_reelle telle quelle
                    $dateReelle = $liv['date_reelle'] ?? null;
                    $oldStatut = $liv['statut'] ?? '';
                    $isBecomingLivree = ($newStatut === 'livree' && $oldStatut !== 'livree');
                    
                    if ($newStatut === 'livree' && empty($dateReelle)) {
                        $dateReelle = $today;
                    }

                    $pdo->beginTransaction();
                    try {
                        $upd = $pdo->prepare("
                            UPDATE livraisons
                            SET statut = :statut,
                                date_reelle = :date_reelle,
                                updated_at = NOW()
                            WHERE id = :id
                        ");
                        $upd->execute([
                            ':statut'      => $newStatut,
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
                        $flash = ['type'=>'success','msg'=>"Livraison mise à jour avec succès." . ($isBecomingLivree && !empty($liv['product_type']) ? " Stock client mis à jour." : "")];
                    } catch (PDOException $e) {
                        $pdo->rollBack();
                        error_log('livraisons.php UPDATE/STOCK error: ' . $e->getMessage());
                        $flash = ['type'=>'error','msg'=>"Erreur SQL : impossible de mettre à jour la livraison."];
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
    $stmt = $pdo->query($sql);
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

foreach ($rows as $idx => $l) {
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
// Vue (toutes / retard / aujourd’hui)
// ============================================================================
$view = $_GET['view'] ?? 'toutes';
if (!in_array($view, ['toutes', 'retard', 'aujourdhui'], true)) {
    $view = 'toutes';
}

$filteredLivraisons = array_values(array_filter($rows, function($l) use ($view) {
    if ($view === 'retard') {
        return !empty($l['is_late']);
    }
    if ($view === 'aujourdhui') {
        return !empty($l['is_today']);
    }
    return true; // toutes
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
          echo $view === 'retard'
            ? 'En retard'
            : ($view === 'aujourdhui' ? 'Aujourd’hui' : 'Toutes');
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
      <a href="/public/livraisons.php?view=toutes"
         class="btn <?= $view === 'toutes' ? 'btn-primary' : 'btn-outline' ?>">Toutes</a>
      <a href="/public/livraisons.php?view=aujourdhui"
         class="btn <?= $view === 'aujourdhui' ? 'btn-primary' : 'btn-outline' ?>">Aujourd’hui</a>
      <a href="/public/livraisons.php?view=retard"
         class="btn <?= $view === 'retard' ? 'btn-primary' : 'btn-outline' ?>">En retard</a>
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
        <tr>
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
          data-search="<?= h($searchText) ?>"
          data-client="<?= h($clientNom) ?>"
          data-ref="<?= h($ref) ?>"
          data-adresse="<?= h($adresse) ?>"
          data-objet="<?= h($objet) ?>"
          data-prevue="<?= h($prevueLabel) ?>"
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
            <input type="text" id="modal_prevue" readonly>
          </div>
          <div>
            <label>Date réelle</label>
            <input type="text" id="modal_reelle" readonly>
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

        <label>Commentaire (lecture seule)</label>
        <textarea id="modal_commentaire" rows="3" readonly></textarea>

        <div id="modal_permission_msg" style="margin-top:0.5rem; font-size:0.85rem;"></div>
      </div>
    </div>

    <div class="modal-actions">
      <div class="modal-hint">
        <strong>Permissions :</strong> Seul le livreur assigné à cette livraison peut modifier son statut. Les administrateurs et dirigeants peuvent modifier toutes les livraisons.
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

      if (inputId)      inputId.value = id;
      if (inputClient)  inputClient.value = client;
      if (inputRef)     inputRef.value = ref;
      if (inputAdresse) inputAdresse.value = adresse;
      if (inputObjet)   inputObjet.value = objet;
      if (inputPrevue)  inputPrevue.value = prevue;
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

  // Filtre rapide
  const q = document.getElementById('q');
  const clear = document.getElementById('clearQ');
  if (q) {
    const lines = Array.from(document.querySelectorAll('table#tbl tbody tr'));
    function apply(){
      const v = (q.value || '').trim().toLowerCase();
      lines.forEach(tr => {
        const t = (tr.getAttribute('data-search') || '').toLowerCase();
        tr.style.display = !v || t.includes(v) ? '' : 'none';
      });
    }
    q.addEventListener('input', apply);
    if (clear) {
      clear.addEventListener('click', () => {
        q.value = '';
        apply();
        q.focus();
      });
    }
  }
})();
</script>
</body>
</html>
