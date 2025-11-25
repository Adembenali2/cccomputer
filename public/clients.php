<?php
// /public/clients.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('clients', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/historique.php';

// Configuration PDO en mode exceptions
if (method_exists($pdo, 'setAttribute')) {
    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (\Throwable $e) {
        error_log('clients.php: Failed to set PDO error mode: ' . $e->getMessage());
    }
}

/**
 * Normalise une adresse MAC au format 12 hex sans séparateurs (pour URL)
 * Retourne null si la MAC n'est pas valide
 */
function normalizeMacForUrl(?string $mac): ?string {
    if (empty($mac)) {
        return null;
    }
    $raw = strtoupper(trim($mac));
    $hex = preg_replace('~[^0-9A-F]~', '', $raw);
    if (strlen($hex) !== 12) {
        return null;
    }
    return $hex;
}

/**
 * Vérifie si une ligne de données a une alerte (relevé manquant ou trop ancien)
 */
function rowHasAlert(array $row): bool {
    $macNorm = $row['mac_norm'] ?? '';
    $sn = $row['SerialNumber'] ?? '';
    $modele = $row['Model'] ?? '';
    $hasMachine = ($macNorm || $sn || $modele);
    
    if (!$hasMachine) {
        return false;
    }
    
    $lastTsRaw = $row['last_ts'] ?? null;
    if (!$lastTsRaw) {
        return true;
    }
    
    $ageHours = isset($row['last_age_hours']) ? (int)$row['last_age_hours'] : null;
    if ($ageHours !== null && $ageHours >= 48) {
        return true;
    }
    
    return false;
}

/**
 * Génère un numéro client au format C12345
 */
function generateClientNumber(PDO $pdo): string {
    $sql = "SELECT LPAD(COALESCE(MAX(CAST(SUBSTRING(numero_client, 2) AS UNSIGNED)), 0) + 1, 5, '0') AS next_num
            FROM clients WHERE numero_client REGEXP '^C[0-9]{5}$'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $next = $stmt->fetchColumn();
    
    if (!$next) {
        $next = '00001';
    }
    
    return 'C' . $next;
}

/**
 * Récupère le prochain ID client disponible
 */
function nextClientId(PDO $pdo): int {
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM clients");
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

/**
 * Vérifie si l'erreur PDO est liée à un ID manquant (code 1364 ou 1048)
 */
function isNoDefaultIdError(PDOException $e): bool {
    $code = (int)($e->errorInfo[1] ?? 0);
    return in_array($code, [1364, 1048], true);
}

// Traitement du formulaire POST pour ajouter un client
$flash = ['type' => null, 'msg' => null];
$shouldOpenModal = false;
$CSRF = ensureCsrfToken();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_client') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
    } catch (RuntimeException $csrfEx) {
        $flash = ['type' => 'error', 'msg' => $csrfEx->getMessage()];
        $shouldOpenModal = true;
    }

    // Récupération et nettoyage des données du formulaire
    $raison_sociale = trim($_POST['raison_sociale'] ?? '');
    $adresse = trim($_POST['adresse'] ?? '');
    $code_postal = trim($_POST['code_postal'] ?? '');
    $ville = trim($_POST['ville'] ?? '');
    $livraison_identique = isset($_POST['livraison_identique']) ? 1 : 0;
    $adresse_livraison = trim($_POST['adresse_livraison'] ?? '');
    $nom_dirigeant = trim($_POST['nom_dirigeant'] ?? '');
    $prenom_dirigeant = trim($_POST['prenom_dirigeant'] ?? '');
    $telephone1 = trim($_POST['telephone1'] ?? '');
    $telephone2 = trim($_POST['telephone2'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $siret = trim($_POST['siret'] ?? '');
    $numero_tva = trim($_POST['numero_tva'] ?? '');
    $parrain = trim($_POST['parrain'] ?? '');
    $offre = in_array(($_POST['offre'] ?? 'packbronze'), ['packbronze', 'packargent'], true) 
        ? $_POST['offre'] 
        : 'packbronze';

    if ($livraison_identique) {
        $adresse_livraison = $adresse;
    }

    // Validation des champs obligatoires
    $errors = [];
    if ($raison_sociale === '') {
        $errors[] = "La raison sociale est obligatoire.";
    }
    if ($adresse === '') {
        $errors[] = "L'adresse est obligatoire.";
    }
    if ($code_postal === '') {
        $errors[] = "Le code postal est obligatoire.";
    }
    if ($ville === '') {
        $errors[] = "La ville est obligatoire.";
    }
    if ($nom_dirigeant === '') {
        $errors[] = "Le nom du dirigeant est obligatoire.";
    }
    if ($prenom_dirigeant === '') {
        $errors[] = "Le prénom du dirigeant est obligatoire.";
    }
    if ($telephone1 === '') {
        $errors[] = "Le téléphone est obligatoire.";
    }
    if ($email === '') {
        $errors[] = "L'email est obligatoire.";
    }
    if ($siret === '') {
        $errors[] = "Le SIRET est obligatoire.";
    }
    if ($email && !validateEmailBool($email)) {
        $errors[] = "L'email est invalide.";
    }
    if ($telephone1 && !validatePhone($telephone1)) {
        $errors[] = "Le téléphone doit contenir au moins 6 caractères valides.";
    }
    if ($telephone2 && !validatePhone($telephone2)) {
        $errors[] = "Le téléphone 2 doit contenir au moins 6 caractères valides.";
    }
    if ($code_postal && !validatePostalCode($code_postal)) {
        $errors[] = "Code postal invalide.";
    }
    if ($siret && !validateSiret($siret)) {
        $errors[] = "Le SIRET doit contenir 14 chiffres.";
    }

    // Insertion en base de données si validation OK
    if (empty($errors) && !$shouldOpenModal) {
        $numero = generateClientNumber($pdo);
        $sqlInsert = "INSERT INTO clients
            (numero_client, raison_sociale, adresse, code_postal, ville,
             adresse_livraison, livraison_identique, siret, numero_tva,
             nom_dirigeant, prenom_dirigeant, telephone1, telephone2,
             email, parrain, offre)
            VALUES
            (:numero_client, :raison_sociale, :adresse, :code_postal, :ville,
             :adresse_livraison, :livraison_identique, :siret, :numero_tva,
             :nom_dirigeant, :prenom_dirigeant, :telephone1, :telephone2,
             :email, :parrain, :offre)";
        
        $params = [
            ':numero_client' => $numero,
            ':raison_sociale' => $raison_sociale,
            ':adresse' => $adresse,
            ':code_postal' => $code_postal,
            ':ville' => $ville,
            ':adresse_livraison' => ($adresse_livraison !== '' ? $adresse_livraison : null),
            ':livraison_identique' => $livraison_identique,
            ':siret' => $siret,
            ':numero_tva' => ($numero_tva !== '' ? $numero_tva : null),
            ':nom_dirigeant' => $nom_dirigeant,
            ':prenom_dirigeant' => $prenom_dirigeant,
            ':telephone1' => $telephone1,
            ':telephone2' => ($telephone2 !== '' ? $telephone2 : null),
            ':email' => $email,
            ':parrain' => ($parrain !== '' ? $parrain : null),
            ':offre' => $offre,
        ];
        
        try {
            $pdo->beginTransaction();
            $pdo->prepare($sqlInsert)->execute($params);
            $insertedId = $pdo->lastInsertId();
            $insertedId = $insertedId ? (int)$insertedId : null;

            $userId = currentUserId();
            $details = "Client créé: ID=" . ($insertedId ?? 'NULL') . ", numero=" . $numero . ", raison_sociale=" . $raison_sociale;
            enregistrerAction($pdo, $userId, 'client_ajoute', $details);
            $pdo->commit();

            header('Location: /public/clients.php?added=1');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            
            if (isNoDefaultIdError($e)) {
                try {
                    $pdo->beginTransaction();
                    $id = nextClientId($pdo);
                    $pdo->prepare("
                        INSERT INTO clients
                        (id, numero_client, raison_sociale, adresse, code_postal, ville,
                         adresse_livraison, livraison_identique, siret, numero_tva,
                         nom_dirigeant, prenom_dirigeant, telephone1, telephone2,
                         email, parrain, offre)
                        VALUES
                        (:id, :numero_client, :raison_sociale, :adresse, :code_postal, :ville,
                         :adresse_livraison, :livraison_identique, :siret, :numero_tva,
                         :nom_dirigeant, :prenom_dirigeant, :telephone1, :telephone2,
                         :email, :parrain, :offre)
                    ")->execute($params + [':id' => $id]);

                    $details = "Client créé: ID=" . $id . ", numero=" . $numero . ", raison_sociale=" . $raison_sociale;
                    enregistrerAction($pdo, currentUserId(), 'client_ajoute', $details);
                    $pdo->commit();
                    header('Location: /public/clients.php?added=1');
                    exit;
                } catch (PDOException $eId) {
                    $pdo->rollBack();
                    error_log('clients.php INSERT with id error: ' . $eId->getMessage());
                    $flash = ['type' => 'error', 'msg' => "Erreur SQL: impossible de créer le client (id requis)."];
                }
            } elseif ((int)($e->errorInfo[1] ?? 0) === 1062) {
                try {
                    $pdo->beginTransaction();
                    $numero = generateClientNumber($pdo);
                    $params[':numero_client'] = $numero;
                    $pdo->prepare($sqlInsert)->execute($params);
                    $details = "Client créé (retry): numero=" . $numero . ", raison_sociale=" . $raison_sociale;
                    enregistrerAction($pdo, currentUserId(), 'client_ajoute', $details);
                    $pdo->commit();
                    header('Location: /public/clients.php?added=1');
                    exit;
                } catch (PDOException $e2) {
                    $pdo->rollBack();
                    error_log('clients.php INSERT retry duplicate error: ' . $e2->getMessage());
                    $flash = ['type' => 'error', 'msg' => "Erreur SQL (unicité): impossible de créer le client."];
                }
            } else {
                error_log('clients.php INSERT error: ' . $e->getMessage());
                $flash = ['type' => 'error', 'msg' => "Erreur SQL: impossible de créer le client."];
            }
        }
    } else {
        $flash = ['type' => 'error', 'msg' => implode('<br>', array_map('htmlspecialchars', $errors))];
        $shouldOpenModal = true;
    }
}

if (($_GET['added'] ?? '') === '1') {
    $flash = ['type' => 'success', 'msg' => "Client ajouté avec succès."];
}

// Détermination de la vue (assigned ou unassigned)
$view = ($_GET['view'] ?? 'assigned');
$view = ($view === 'unassigned') ? 'unassigned' : 'assigned';

// Construction de la requête SQL selon la vue
if ($view === 'unassigned') {
    // Non attribués = relevé sans client + entrée pc sans client et sans relevé
    $sql = "
    WITH v_compteur_last AS (
      SELECT r.*,
             ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
      FROM compteur_relevee r
    ),
    v_last AS (
      SELECT *, TIMESTAMPDIFF(HOUR, `Timestamp`, NOW()) AS age_hours
      FROM v_compteur_last WHERE rn = 1
    ),
    unassigned_with_read AS (
      SELECT
        v.mac_norm, v.SerialNumber, v.MacAddress, v.Model, v.Nom,
        v.`Timestamp` AS last_ts, v.age_hours AS last_age_hours,
        v.TonerBlack, v.TonerCyan, v.TonerMagenta, v.TonerYellow,
        v.TotalBW, v.TotalColor, v.TotalPages, v.Status,
        NULL AS client_id, NULL AS numero_client, NULL AS raison_sociale,
        NULL AS nom_dirigeant, NULL AS prenom_dirigeant, NULL AS telephone1
      FROM v_last v
      LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = v.mac_norm
      WHERE pc.id_client IS NULL
    ),
    unassigned_without_read AS (
      SELECT
        pc.mac_norm, pc.SerialNumber, pc.MacAddress,
        NULL AS Model, NULL AS Nom,
        NULL AS last_ts, NULL AS last_age_hours,
        NULL AS TonerBlack, NULL AS TonerCyan, NULL AS TonerMagenta, NULL AS TonerYellow,
        NULL AS TotalBW, NULL AS TotalColor, NULL AS TotalPages, NULL AS Status,
        NULL AS client_id, NULL AS numero_client, NULL AS raison_sociale,
        NULL AS nom_dirigeant, NULL AS prenom_dirigeant, NULL AS telephone1
      FROM photocopieurs_clients pc
      LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
      WHERE pc.id_client IS NULL AND v.mac_norm IS NULL
    )
    SELECT * FROM (
      SELECT * FROM unassigned_with_read
      UNION ALL
      SELECT * FROM unassigned_without_read
    ) x
    ORDER BY COALESCE(x.SerialNumber, x.mac_norm) ASC
    LIMIT 1000
    ";
} else {
    // Attribués uniquement (avec ou sans relevé)
    $sql = "
    WITH v_compteur_last AS (
      SELECT r.*,
             ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
      FROM compteur_relevee r
    ),
    v_last AS (
      SELECT *, TIMESTAMPDIFF(HOUR, `Timestamp`, NOW()) AS age_hours
      FROM v_compteur_last WHERE rn = 1
    ),
    assigned_with_read AS (
      SELECT
        pc.mac_norm,
        COALESCE(pc.SerialNumber, v.SerialNumber) AS SerialNumber,
        COALESCE(pc.MacAddress,  v.MacAddress)    AS MacAddress,
        v.Model, v.Nom,
        v.`Timestamp` AS last_ts, v.age_hours AS last_age_hours,
        v.TonerBlack, v.TonerCyan, v.TonerMagenta, v.TonerYellow,
        v.TotalBW, v.TotalColor, v.TotalPages, v.Status,
        c.id AS client_id, c.numero_client, c.raison_sociale,
        c.nom_dirigeant, c.prenom_dirigeant, c.telephone1
      FROM photocopieurs_clients pc
      JOIN clients c      ON c.id = pc.id_client
      LEFT JOIN v_last v  ON v.mac_norm = pc.mac_norm
      WHERE pc.id_client IS NOT NULL AND v.mac_norm IS NOT NULL
    ),
    assigned_without_read AS (
      SELECT
        pc.mac_norm, pc.SerialNumber, pc.MacAddress,
        NULL AS Model, NULL AS Nom,
        NULL AS last_ts, NULL AS last_age_hours,
        NULL AS TonerBlack, NULL AS TonerCyan, NULL AS TonerMagenta, NULL AS TonerYellow,
        NULL AS TotalBW, NULL AS TotalColor, NULL AS TotalPages, NULL AS Status,
        c.id AS client_id, c.numero_client, c.raison_sociale,
        c.nom_dirigeant, c.prenom_dirigeant, c.telephone1
      FROM photocopieurs_clients pc
      JOIN clients c     ON c.id = pc.id_client
      LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
      WHERE pc.id_client IS NOT NULL AND v.mac_norm IS NULL
    )
    SELECT * FROM (
      SELECT * FROM assigned_with_read
      UNION ALL
      SELECT * FROM assigned_without_read
    ) x
    ORDER BY
      COALESCE(x.raison_sociale, '—') ASC,
      COALESCE(x.SerialNumber, x.mac_norm) ASC
    LIMIT 1000
    ";
}

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('clients.php SQL error: ' . $e->getMessage());
    $rows = [];
}

// Calcul des statistiques
$machineTotal = count($rows);
$alertCount = 0;
$uniqueClients = [];
foreach ($rows as $row) {
    if ($view === 'assigned' && !empty($row['client_id'])) {
        $uniqueClients[(int)$row['client_id']] = true;
    }
    if (rowHasAlert($row)) {
        $alertCount++;
    }
}
$uniqueClientsCount = count($uniqueClients);
$lastRefreshLabel = date('d/m/Y à H:i');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Clients & Photocopieurs - CCComputer</title>

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/clients.css" />
  <style>
    tr.is-clickable:hover { background: var(--bg-elevated); }
  </style>
</head>
<body class="page-clients">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
  <div class="page-header">
    <h2 class="page-title">
      <?= $view==='unassigned' ? 'Photocopieurs non attribués' : 'Photocopieurs attribués par client' ?>
    </h2>
    <p class="page-sub">
      Vue <?= $view==='unassigned' ? 'des équipements disponibles à attribuer' : 'des photocopieurs suivis par client' ?> — dernière mise à jour <?= h($lastRefreshLabel) ?>.
    </p>
  </div>

  <section class="clients-meta">
    <div class="meta-card">
      <span class="meta-label">Machines listées</span>
      <strong class="meta-value"><?= h((string)$machineTotal) ?></strong>
      <?php if ($machineTotal === 0): ?>
        <span class="meta-chip">Aucune donnée</span>
      <?php endif; ?>
    </div>
    <div class="meta-card">
      <span class="meta-label">Alertes relevé</span>
      <strong class="meta-value <?= $alertCount > 0 ? 'danger' : 'success' ?>"><?= h((string)$alertCount) ?></strong>
      <span class="meta-sub"><?= $alertCount > 0 ? 'Machines à contrôler' : 'Tout est à jour' ?></span>
    </div>
    <?php if ($view !== 'unassigned'): ?>
      <div class="meta-card">
        <span class="meta-label">Clients couverts</span>
        <strong class="meta-value"><?= h((string)$uniqueClientsCount) ?></strong>
        <span class="meta-sub">Avec au moins un appareil</span>
      </div>
    <?php endif; ?>
    <div class="meta-card">
      <span class="meta-label">Vue active</span>
      <strong class="meta-value"><?= $view === 'unassigned' ? 'Non attribués' : 'Attribués' ?></strong>
      <span class="meta-sub">Basculer en un clic</span>
    </div>
  </section>

  <!-- Barre de filtres + actions -->
  <div class="filters-row">
    <div class="filters-left">
      <input type="text" id="q" class="filter-input" placeholder="Filtrer (client, modèle, SN, MAC)…">
      <button id="clearQ" class="btn btn-secondary" type="button">Effacer</button>
    </div>
    <div class="filters-actions">
      <button id="btnAddClient" class="btn btn-primary" type="button">
        + Ajouter un client
      </button>
      <?php if ($view !== 'unassigned'): ?>
        <a href="/public/clients.php?view=unassigned" class="btn btn-outline" role="button">
          Voir non attribués
        </a>
      <?php else: ?>
        <a href="/public/clients.php" class="btn btn-outline" role="button">
          ← Revenir aux attribués
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Flash -->
  <?php if ($flash['type']): ?>
    <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <!-- Tableau -->
  <div class="table-wrapper">
    <table class="tbl-photocopieurs" id="tbl">
      <thead>
        <tr>
          <th>Client</th>
          <th>Dirigeant</th>
          <th>Téléphone</th>
          <th>Photocopieur</th>
          <th>Toners</th>
          <th>Total BW</th>
          <th>Total Color</th>
          <th>Dernier relevé</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        $raison  = $r['raison_sociale'] ?: ($view==='unassigned' ? '— non attribué —' : '—');
        $numero  = $r['numero_client']   ?: '';
        $dirNom  = trim(($r['nom_dirigeant'] ?? '').' '.($r['prenom_dirigeant'] ?? ''));
        $tel     = $r['telephone1'] ?: '';
        $modele  = $r['Model'] ?: '';
        $sn      = $r['SerialNumber'] ?: '';
        $mac     = $r['MacAddress'] ?: ($r['mac_norm'] ?? '');
        $macNorm = $r['mac_norm'] ?? '';
        $nom     = $r['Nom'] ?: '';
        $lastTsRaw = $r['last_ts'] ?? null;
        $lastTs  = $lastTsRaw ? date('Y-m-d H:i', strtotime($lastTsRaw)) : '—';
        $ageHours = isset($r['last_age_hours']) ? (int)$r['last_age_hours'] : null;
        $totBW   = is_null($r['TotalBW'])    ? '—' : number_format((int)$r['TotalBW'], 0, ',', ' ');
        $totCol  = is_null($r['TotalColor']) ? '—' : number_format((int)$r['TotalColor'], 0, ',', ' ');

        $tk = is_null($r['TonerBlack'])   ? null : max(0, min(100, (int)$r['TonerBlack']));
        $tc = is_null($r['TonerCyan'])    ? null : max(0, min(100, (int)$r['TonerCyan']));
        $tm = is_null($r['TonerMagenta']) ? null : max(0, min(100, (int)$r['TonerMagenta']));
        $ty = is_null($r['TonerYellow'])  ? null : max(0, min(100, (int)$r['TonerYellow']));

        $searchText = strtolower(
            ($r['raison_sociale'] ?? '') . ' ' .
            ($r['numero_client'] ?? '') . ' ' .
            $dirNom . ' ' . $tel . ' ' .
            $modele . ' ' . $sn . ' ' . $mac
        );

        // Construction de l'URL : utiliser mac_norm si disponible, sinon normaliser MacAddress, sinon utiliser SerialNumber
        $rowHref = '';
        
        // Vérifier que mac_norm est valide (12 hex sans séparateurs) et le convertir en majuscules
        if (!empty($macNorm) && preg_match('/^[0-9A-F]{12}$/i', $macNorm)) {
            // mac_norm est déjà au format 12 hex sans séparateurs, convertir en majuscules pour cohérence
            $macNormUpper = strtoupper(trim($macNorm));
            if (strlen($macNormUpper) === 12) {
                $rowHref = '/public/photocopieurs_details.php?mac=' . urlencode($macNormUpper);
            }
        }
        
        // Si mac_norm n'est pas valide, essayer de normaliser MacAddress
        if (empty($rowHref) && !empty($mac)) {
            $normalizedMac = normalizeMacForUrl($mac);
            if ($normalizedMac) {
                $rowHref = '/public/photocopieurs_details.php?mac=' . urlencode($normalizedMac);
            }
        }
        
        // Fallback sur SerialNumber si MAC non disponible
        if (empty($rowHref) && !empty($sn)) {
            $rowHref = '/public/photocopieurs_details.php?sn=' . urlencode($sn);
        }

        $isAlert = rowHasAlert($r);

        $rowClasses = [];
        if ($rowHref) {
            $rowClasses[] = 'is-clickable';
        }
        if ($isAlert) {
            $rowClasses[] = 'row-alert';
        }
        $rowClassAttr = $rowClasses ? ' class="'.h(implode(' ', $rowClasses)).'"' : '';
        $rowHrefAttr = $rowHref ? ' data-href="'.h($rowHref).'"' : '';
      ?>
        <tr data-search="<?= h($searchText) ?>"<?= $rowHrefAttr ?><?= $rowClassAttr ?>>
          <td data-th="Client">
            <div class="client-cell">
              <div class="client-raison"><?= h($raison) ?></div>
              <?php if ($numero): ?><div class="client-num"><?= h($numero) ?></div><?php endif; ?>
            </div>
          </td>

          <td data-th="Dirigeant"><?= h($dirNom ?: '—') ?></td>
          <td data-th="Téléphone"><?= h($tel ?: '—') ?></td>

          <td data-th="Photocopieur">
            <div class="machine-cell">
              <div class="machine-line"><strong><?= h($modele ?: '—') ?></strong></div>
              <div class="machine-sub">SN: <?= h($sn ?: '—') ?> · MAC: <?= h($mac ?: '—') ?></div>
              <?php if ($nom): ?><div class="machine-sub">Nom: <?= h($nom) ?></div><?php endif; ?>
            </div>
          </td>

          <td data-th="Toners">
            <div class="toners">
              <div class="toner t-k<?= ($tk===0 ? ' is-empty' : '') ?>"><span style="width:<?= ($tk!==null?$tk:0) ?>%"></span><em><?= pctOrDash($tk) ?></em></div>
              <div class="toner t-c<?= ($tc===0 ? ' is-empty' : '') ?>"><span style="width:<?= ($tc!==null?$tc:0) ?>%"></span><em><?= pctOrDash($tc) ?></em></div>
              <div class="toner t-m<?= ($tm===0 ? ' is-empty' : '') ?>"><span style="width:<?= ($tm!==null?$tm:0) ?>%"></span><em><?= pctOrDash($tm) ?></em></div>
              <div class="toner t-y<?= ($ty===0 ? ' is-empty' : '') ?>"><span style="width:<?= ($ty!==null?$ty:0) ?>%"></span><em><?= pctOrDash($ty) ?></em></div>
            </div>
          </td>

          <td class="td-metric" data-th="Total BW"><?= h($totBW) ?></td>
          <td class="td-metric" data-th="Total Color"><?= h($totCol) ?></td>
          <td class="td-date has-pullout" data-th="Dernier relevé" title="<?= h($lastTsRaw ? ('Âge: ~'.(int)$ageHours.' h') : 'Aucun relevé') ?>">
            <?= h($lastTs) ?>
            <?php if ($isAlert): ?>
              <span class="alert-pullout" title="<?= h(!$lastTsRaw ? 'Aucun relevé reçu pour cette machine.' : 'Dernier relevé il y a ~'.(int)floor($ageHours/24).' jours') ?>">
                ⚠️ Relevé en retard<?= !$lastTsRaw ? ' (jamais reçu)' : (($ageHours>=48)?' (≥ 2 jours)':'') ?>
              </span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if (!$rows): ?>
      <div style="padding:1rem; color:var(--text-secondary);">
        <?= $view==='unassigned' ? "Aucun photocopieur non attribué." : "Aucun photocopieur attribué trouvé." ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ===== Popup "Ajouter un client" ===== -->
<div id="clientModalOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="clientModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="clientModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="clientModalTitle">Ajouter un client</h3>
    <button type="button" id="btnCloseModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>

  <?php if ($flash['type'] && $shouldOpenModal): ?>
    <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
    <input type="hidden" name="action" value="add_client">
    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">

    <div class="form-grid-2">
      <div class="card-like">
        <div class="subsection-title">Informations société</div>
        <label>Raison sociale* </label><input type="text" name="raison_sociale" value="<?= old('raison_sociale') ?>" required>
        <label>Adresse* </label><input type="text" name="adresse" value="<?= old('adresse') ?>" required>
        <div class="grid-two">
          <div><label>Code postal*</label><input type="text" name="code_postal" value="<?= old('code_postal') ?>" required></div>
          <div><label>Ville*</label><input type="text" name="ville" value="<?= old('ville') ?>" required></div>
        </div>
        <div class="grid-two">
          <div><label>SIRET*</label><input type="text" name="siret" value="<?= old('siret') ?>" required></div>
          <div><label>Numéro TVA</label><input type="text" name="numero_tva" value="<?= old('numero_tva') ?>"></div>
        </div>

        <div class="livraison-row">
          <label class="livraison-toggle">
            <input type="checkbox" name="livraison_identique" id="livraison_identique" <?= isset($_POST['livraison_identique']) ? 'checked' : '' ?>>
            <span class="toggle-box" aria-hidden="true">
              <svg viewBox="0 0 24 24" class="toggle-check" aria-hidden="true">
                <path d="M20.285 6.709a1 1 0 0 0-1.414-1.414l-9.9 9.9-3.242-3.243a1 1 0 1 0-1.414 1.415l3.95 3.95a1 1 0 0 0 1.414 0l10.006-10.008z"></path>
              </svg>
            </span>
            <span class="livraison-emoji" aria-hidden="true">📦</span>
            <span class="livraison-text">Adresse de livraison identique</span>
          </label>
        </div>

        <label for="adresse_livraison" class="adresse-livraison-label">Adresse de livraison</label>
        <input type="text" name="adresse_livraison" id="adresse_livraison" value="<?= old('adresse_livraison') ?>" placeholder="Laisser vide si identique">
      </div>

      <div class="card-like">
        <div class="subsection-title">Contacts & offre</div>
        <div class="grid-two">
          <div><label>Nom dirigeant*</label><input type="text" name="nom_dirigeant" value="<?= old('nom_dirigeant') ?>" required></div>
          <div><label>Prénom dirigeant*</label><input type="text" name="prenom_dirigeant" value="<?= old('prenom_dirigeant') ?>" required></div>
        </div>
        <div class="grid-two">
          <div><label>Téléphone*</label><input type="text" name="telephone1" value="<?= old('telephone1') ?>" required></div>
          <div><label>Téléphone 2</label><input type="text" name="telephone2" value="<?= old('telephone2') ?>"></div>
        </div>
        <label>Email*</label><input type="email" name="email" value="<?= old('email') ?>" required>
        <div class="grid-two">
          <div><label>Parrain</label><input type="text" name="parrain" value="<?= old('parrain') ?>"></div>
          <div>
            <label>Offre</label>
            <select name="offre">
              <option value="packbronze" <?= (($_POST['offre'] ?? 'packbronze')==='packbronze'?'selected':'') ?>>Pack Bronze</option>
              <option value="packargent" <?= (($_POST['offre'] ?? '')==='packargent'?'selected':'') ?>>Pack Argent</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="modal-actions">
      <div class="modal-hint">* obligatoires — numéro client généré automatiquement (ex : C12345)</div>
      <button type="submit" class="fiche-action-btn">Enregistrer</button>
    </div>
  </form>
</div>

<!-- JS -->
<script src="/assets/js/clients.js"></script>

<script>
  // Ouverture auto si erreurs validation
  window.__CLIENT_MODAL_INIT_OPEN__ = <?= json_encode($shouldOpenModal) ?>;

  (function(){
    const overlay = document.getElementById('clientModalOverlay');
    const modal = document.getElementById('clientModal');
    const openBtn = document.getElementById('btnAddClient');
    const closeBtn = document.getElementById('btnCloseModal');

    function openModal() {
      document.body.classList.add('modal-open');
      overlay.setAttribute('aria-hidden', 'false');
      overlay.style.display = 'block';
      modal.style.display = 'block';
    }

    function closeModal() {
      document.body.classList.remove('modal-open');
      overlay.setAttribute('aria-hidden', 'true');
      overlay.style.display = 'none';
      modal.style.display = 'none';
    }

    if (openBtn) {
      openBtn.addEventListener('click', openModal);
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }
    if (overlay) {
      overlay.addEventListener('click', closeModal);
    }
    if (window.__CLIENT_MODAL_INIT_OPEN__) {
      openModal();
    }

    // Lignes cliquables
    const rows = document.querySelectorAll('table#tbl tbody tr.is-clickable[data-href]');
    rows.forEach(tr => {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', (e) => {
        if (window.getSelection && String(window.getSelection())) {
          return;
        }
        const href = tr.getAttribute('data-href');
        if (href) {
          window.location.assign(href);
        }
      });
    });

    // Filtre rapide
    const q = document.getElementById('q');
    const clear = document.getElementById('clearQ');
    if (q) {
      const lines = Array.from(document.querySelectorAll('table#tbl tbody tr'));
      function apply() {
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
