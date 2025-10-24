<?php
// 1. Init
require_once __DIR__ . '/../includes/init.php';

// 2. Auth (gardez si nécessaire)
require_once __DIR__ . '/../includes/auth_guard.php';

// 3. Connexion BDD (peut être désactivée par le flag)
require_once __DIR__ . '/../includes/db_connection.php';

// 4. Récup data
$search = $_GET['search'] ?? '';
$items  = [];

if ($pdo instanceof PDO) {
    try {
        $sql = "SELECT 
                    i.id, i.name, i.status, 
                    c.name AS category_name,
                    (SELECT photo_path FROM item_photos ip WHERE ip.item_id = i.id ORDER BY ip.id LIMIT 1) as photo_path
                FROM items i 
                LEFT JOIN categories c ON i.category_id = c.id";
        $params = [];
        if ($search !== '') {
            $sql .= " WHERE i.name LIKE ? OR c.name LIKE ? OR i.status LIKE ?";
            $like = "%{$search}%";
            $params = [$like, $like, $like];
        }
        $sql .= " ORDER BY i.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
    } catch (PDOException $e) {
        // Vous pouvez logger sans casser l’affichage
        error_log('DB OFF/ERR accueil.php: ' . $e->getMessage());
        $items = [];
    }
} else {
    // --- BDD désactivée : données factices (facultatif) ---
    $items = []; // ou laissez vide pour afficher le message "aucun article"
}

// — Header identique au dashboard —
require_once __DIR__ . '/../source/templates/header.php';
?>
<div class="container mt-4">
  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
    <h1 class="mb-2 mb-md-0 h2">Tableau de Bord</h1>
    <div class="btn-group" role="group">
      <a href="../catégories/manage_categories.php" class="btn btn-outline-secondary">Gérer les Catégories</a>
      <a href="../article/add_item.php" class="btn btn-primary">Ajouter un article</a>
    </div>
  </div>

  <div class="card mb-4">
    <div class="card-body">
      <form action="dashboard.php" method="GET" class="d-flex gap-2">
        <input type="text" name="search" class="form-control" placeholder="Rechercher un article par nom, catégorie, statut..." value="<?= htmlspecialchars($search) ?>">
        <button class="btn btn-primary" type="submit">Rechercher</button>
      </form>
      <?php if (!($pdo instanceof PDO)): ?>
        <div class="alert alert-warning mt-3 mb-0">
          Base de données désactivée (mode démo). Les résultats ne sont pas affichés.
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Liste des articles</span>
      <?php if ($search !== ''): ?>
        <span class="badge bg-secondary fw-normal">Filtre : "<?= htmlspecialchars($search) ?>"</span>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover mb-0 dashboard-table">
          <thead>
            <tr>
              <th scope="col">Image</th>
              <th scope="col" class="ps-3">Nom de l'article</th>
              <th scope="col">Catégorie</th>
              <th scope="col">Statut</th>
              <th scope="col" class="text-end pe-3">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="5" class="text-center p-4">
                  <?php if ($search !== ''): ?>
                    Aucun article trouvé pour "<?= htmlspecialchars($search) ?>".
                  <?php elseif (!($pdo instanceof PDO)): ?>
                    Mode démo : la base est désactivée.
                  <?php else: ?>
                    Aucun article dans l'inventaire. <a href="../article/add_item.php">Commencez par en ajouter un !</a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $item): 
                $statusClass = match ($item['status']) {
                  'En Stock' => 'text-bg-primary',
                  'Attribué' => 'text-bg-success',
                  'En Maintenance' => 'text-bg-warning',
                  'Hors Service' => 'text-bg-danger',
                  default => 'text-bg-secondary',
                }; ?>
                <tr data-bs-toggle="modal" data-bs-target="#itemDetailModal" data-item-id="<?= $item['id'] ?>" style="cursor:pointer;">
                  <td>
                    <?php if (!empty($item['photo_path'])): ?>
                      <img src="../uploads/images/<?= htmlspecialchars($item['photo_path']) ?>" alt="Photo de <?= htmlspecialchars($item['name']) ?>" style="width:50px;height:50px;object-fit:cover;">
                    <?php else: ?>
                      <div style="width:50px;height:50px;background:#f0f0f0;text-align:center;line-height:50px;color:#aaa;">
                        <i class="bi bi-card-image"></i>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td class="ps-3 fw-bold align-middle"><?= htmlspecialchars($item['name']) ?></td>
                  <td class="align-middle"><?= htmlspecialchars($item['category_name'] ?? 'N/A') ?></td>
                  <td class="align-middle">
                    <span class="badge rounded-pill <?= $statusClass ?>"><?= htmlspecialchars($item['status']) ?></span>
                  </td>
                  <td class="text-end pe-3 align-middle">
                    <a href="../article/edit_item.php?id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();">Modifier</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const itemDetailModal = document.getElementById('itemDetailModal');
  if (!itemDetailModal) return;

  const modalBody = document.getElementById('itemDetailsBody');
  const modalTitle = document.getElementById('itemDetailModalLabel');

  itemDetailModal.addEventListener('show.bs.modal', function (event) {
    // Si la BDD est off, on bloque l'ouverture “détails”
    <?php if (!($pdo instanceof PDO)) : ?>
      event.preventDefault();
      alert("Base de données désactivée (mode démo).");
      return;
    <?php endif; ?>

    const triggerElement = event.relatedTarget;
    const itemId = triggerElement?.getAttribute('data-item-id');

    modalTitle.textContent = "Chargement des détails...";
    modalBody.innerHTML = `<div class="text-center p-5"><div class="spinner-border" role="status"><span class="visually-hidden">Chargement...</span></div></div>`;

    fetch(`../scan/get_item_details.php?id=${itemId}`)
      .then(r => { if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(data => {
        if (!data.success || !data.data) {
          modalTitle.textContent = "Erreur";
          modalBody.innerHTML = `<div class="alert alert-danger">${data.message || "Impossible de récupérer les données de l'article."}</div>`;
          return;
        }
        const itemDetails = data.data;
        modalTitle.textContent = `Détails de : ${itemDetails.name}`;

        let photosHtml = '<p>Aucune photo disponible.</p>';
        if (itemDetails.photos?.length) {
          photosHtml = `
          <div id="photoCarousel" class="carousel slide mb-3" data-bs-ride="carousel">
            <div class="carousel-inner">
              ${itemDetails.photos.map((p,i)=>`
                <div class="carousel-item ${i===0?'active':''}">
                  <img src="${p.file_path}" class="d-block w-100" style="max-height:400px;object-fit:contain;" alt="Photo ${i+1}">
                </div>`).join('')}
            </div>
            ${itemDetails.photos.length>1?`
              <button class="carousel-control-prev" type="button" data-bs-target="#photoCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon" aria-hidden="true"></span><span class="visually-hidden">Précédent</span>
              </button>
              <button class="carousel-control-next" type="button" data-bs-target="#photoCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon" aria-hidden="true"></span><span class="visually-hidden">Suivant</span>
              </button>`:''}
          </div>`;
        }

        let customFieldsHtml = '';
        if (itemDetails.customValues?.length) {
          customFieldsHtml = itemDetails.customValues.map(field => `
            <li class="list-group-item d-flex justify-content-between align-items-center">
              ${field.field_name}
              <span class="badge bg-primary rounded-pill">${field.value || 'N/A'}</span>
            </li>`).join('');
        } else {
          customFieldsHtml = '<li class="list-group-item">Aucun champ personnalisé.</li>';
        }

        modalBody.innerHTML = `
          ${photosHtml}
          <h5>Informations Générales</h5>
          <ul class="list-group mb-3">
            <li class="list-group-item"><strong>Marque :</strong> ${itemDetails.brand || 'N/A'}</li>
            <li class="list-group-item"><strong>Modèle :</strong> ${itemDetails.model || 'N/A'}</li>
            <li class="list-group-item"><strong>N° de série :</strong> ${itemDetails.serial_number || 'N/A'}</li>
            <li class="list-group-item"><strong>Description :</strong> ${itemDetails.description || 'N/A'}</li>
            <li class="list-group-item"><strong>Statut :</strong> <span class="badge rounded-pill ${getBootstrapStatusClass(itemDetails.status)}">${itemDetails.status}</span></li>
          </ul>

          <h5>Champs Personnalisés</h5>
          <ul class="list-group">${customFieldsHtml}</ul>
        `;
      })
      .catch(err => {
        console.error(err);
        modalTitle.textContent = "Erreur de chargement";
        modalBody.innerHTML = `<div class="alert alert-danger">Impossible de contacter le serveur. Veuillez réessayer.</div>`;
      });
  });

  function getBootstrapStatusClass(status){
    switch(status){
      case 'En Stock': return 'text-bg-primary';
      case 'Attribué': return 'text-bg-success';
      case 'En Maintenance': return 'text-bg-warning';
      case 'Hors Service': return 'text-bg-danger';
      default: return 'text-bg-secondary';
    }
  }
});
</script>
