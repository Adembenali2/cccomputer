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

/** Helper d’échappement **/
function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

$today = date('Y-m-d');

// -----------------------------------------------------------------------------
// Récupération des livraisons depuis la base
// -----------------------------------------------------------------------------
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

// -----------------------------------------------------------------------------
// Calcul des flags (retard / aujourd’hui) et stats globales
// -----------------------------------------------------------------------------
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
            // Livrée après la date prévue
            if ($reelle > $prevue) $isLate = true;
        } else {
            // Non livrée alors que la date prévue est passée
            if ($prevue < $today) $isLate = true;
        }
    }

    $rows[$idx]['is_today'] = $isToday;
    $rows[$idx]['is_late']  = $isLate;

    if ($isLate)  $retardCount++;
    if ($isToday) $todayCount++;
}

// -----------------------------------------------------------------------------
// Vue (toutes / retard / aujourd’hui)
// -----------------------------------------------------------------------------
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

  <!-- Barre de filtres + actions -->
  <div class="filters-row">
    <div class="filters-left">
      <input type="text" id="q" class="filter-input" placeholder="Filtrer (client, référence, adresse, objet, livreur)…">
      <button id="clearQ" class="btn btn-secondary" type="button">Effacer</button>
    </div>
    <div class="filters-actions">
      <button id="btnAddDelivery" class="btn btn-primary" type="button">
        + Planifier une livraison
      </button>
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

          $rowClasses = [];
          if ($isLate)  $rowClasses[] = 'row-alert';
          if ($isToday) $rowClasses[] = 'row-today';
          $rowClassAttr = $rowClasses ? ' class="'.h(implode(' ', $rowClasses)).'"' : '';
        ?>
        <tr data-search="<?= h($searchText) ?>"<?= $rowClassAttr ?>>
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

<!-- Popup "Planifier une livraison" (pour l’instant sans INSERT) -->
<div id="deliveryModalOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="deliveryModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="deliveryModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="deliveryModalTitle">Planifier une livraison</h3>
    <button type="button" id="btnCloseDeliveryModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>

  <div style="padding:0.75rem 0; color:var(--text-secondary); font-size:0.95rem;">
    Pour l’instant cette fenêtre est uniquement visuelle.
    Tu pourras ensuite connecter ce formulaire à la base de données (INSERT dans <code>livraisons</code>).
  </div>

  <form method="post" action="#" class="standard-form modal-form" novalidate>
    <div class="form-grid-2">
      <div class="card-like">
        <div class="subsection-title">Infos client & livraison</div>
        <label>Client (texte libre pour le moment)</label>
        <input type="text" name="client" placeholder="Nom du client">
        <label>Référence commande</label>
        <input type="text" name="ref" placeholder="CMD-2025-XXX">
        <label>Adresse de livraison</label>
        <input type="text" name="adresse" placeholder="Adresse complète">
        <label>Objet</label>
        <input type="text" name="objet" placeholder="Ex : Livraison photocopieur">
      </div>

      <div class="card-like">
        <div class="subsection-title">Dates & planning</div>
        <label>Date prévue</label>
        <input type="date" name="date_prevue">
        <label>Date réelle</label>
        <input type="date" name="date_reelle">
        <label>Livré par (nom du livreur)</label>
        <input type="text" name="livreur" placeholder="Nom du livreur">
        <label>Commentaire</label>
        <textarea name="commentaire" rows="3" placeholder="Notes internes, contraintes d’accès…"></textarea>
      </div>
    </div>

    <div class="modal-actions">
      <div class="modal-hint">Ce formulaire est une maquette : il n’enregistre rien pour le moment.</div>
      <button type="button" class="fiche-action-btn">Enregistrer (bientôt)</button>
    </div>
  </form>
</div>

<script>
// Gestion modale
(function(){
  const overlay   = document.getElementById('deliveryModalOverlay');
  const modal     = document.getElementById('deliveryModal');
  const openBtn   = document.getElementById('btnAddDelivery');
  const closeBtn  = document.getElementById('btnCloseDeliveryModal');

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

  if (openBtn)  openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (overlay)  overlay.addEventListener('click', closeModal);
})();

// Filtre rapide
(function(){
  const q = document.getElementById('q');
  const clear = document.getElementById('clearQ');
  if (!q) return;
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
})();
</script>
</body>
</html>
