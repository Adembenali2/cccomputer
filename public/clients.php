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

// La fonction normalizeMacForUrl() est définie dans includes/helpers.php

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
            // Rollback uniquement si une transaction est active
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            
            if (isNoDefaultIdError($e)) {
                // La table clients n'a pas AUTO_INCREMENT, il faut générer l'ID manuellement
                // Utiliser une transaction avec SELECT FOR UPDATE pour éviter les race conditions
                try {
                    $pdo->beginTransaction();
                    
                    // Générer l'ID en verrouillant la table avec SELECT FOR UPDATE
                    // Cela garantit qu'aucun autre processus ne peut lire/modifier pendant la génération
                    $stmt = $pdo->prepare("SELECT COALESCE(MAX(id),0)+1 FROM clients FOR UPDATE");
                    $stmt->execute();
                    $id = (int)$stmt->fetchColumn();
                    
                    // Insérer avec l'ID généré
                    $stmt = $pdo->prepare("
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
                    ");
                    $stmt->execute($params + [':id' => $id]);
                    
                    // Enregistrer l'action dans l'historique
                    $details = "Client créé: ID=" . $id . ", numero=" . $numero . ", raison_sociale=" . $raison_sociale;
                    enregistrerAction($pdo, currentUserId(), 'client_ajoute', $details);
                    
                    $pdo->commit();
                    header('Location: /public/clients.php?added=1');
                    exit;
                } catch (PDOException $eId) {
                    // Rollback uniquement si une transaction est active
                    if ($pdo->inTransaction()) {
                        try {
                            $pdo->rollBack();
                        } catch (PDOException $rollbackEx) {
                            // Ignorer l'erreur de rollback
                        }
                    }
                    
                    // Logger l'erreur avec plus de détails
                    $errorInfo = $eId->errorInfo ?? [];
                    $errorCode = $errorInfo[1] ?? 'N/A';
                    $errorMessage = $eId->getMessage();
                    
                    error_log('clients.php INSERT with id error: ' . $errorMessage);
                    error_log('clients.php Error code: ' . $errorCode . ' | SQL State: ' . ($errorInfo[0] ?? 'N/A'));
                    if (isset($id)) {
                        error_log('clients.php Generated ID: ' . $id);
                    }
                    
                    // Message d'erreur plus informatif
                    $userMessage = "Erreur SQL: impossible de créer le client.";
                    if ($errorCode === 1062) {
                        $userMessage = "Erreur: Le numéro client ou l'ID existe déjà.";
                    } elseif ($errorCode === 1048 || $errorCode === 1364) {
                        $userMessage = "Erreur: Un champ obligatoire est manquant.";
                    }
                    
                    $flash = ['type' => 'error', 'msg' => $userMessage];
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
                    // Rollback uniquement si une transaction est active
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
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

// Traitement POST : attribution d'un photocopieur à un client
$shouldOpenAttachModal = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'attach_photocopieur') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
        
        $idClient = (int)($_POST['id_client'] ?? 0);
        $macInput = trim($_POST['mac_address'] ?? '');
        $snInput = trim($_POST['serial_number'] ?? '');
        
        if ($idClient <= 0) {
            $flash = ['type' => 'error', 'msg' => "Veuillez sélectionner un client."];
            $shouldOpenAttachModal = true;
        } else {
            try {
                $pdo->beginTransaction();
                
                // Normaliser la MAC si fournie
                $macNorm = null;
                $macColon = null;
                if ($macInput !== '') {
                    $macNorm = normalizeMacForUrl($macInput);
                    if ($macNorm) {
                        $macColon = implode(':', str_split($macNorm, 2));
                    }
                }
                
                // Vérifier qu'on a au moins un identifiant valide
                if (!$macNorm && $snInput === '') {
                    $flash = ['type' => 'error', 'msg' => "Adresse MAC ou numéro de série valide requis."];
                    $shouldOpenAttachModal = true;
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } else {
                    $existing = null;
                    
                    // Vérifier si le photocopieur existe déjà (par MAC ou SN)
                    if ($macNorm) {
                        $sqlCheck = "SELECT id FROM photocopieurs_clients WHERE mac_norm = :mac_norm LIMIT 1";
                        $stmtCheck = $pdo->prepare($sqlCheck);
                        $stmtCheck->execute([':mac_norm' => $macNorm]);
                        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    // Si pas trouvé par MAC, chercher par SerialNumber
                    if (!$existing && $snInput !== '') {
                        $sqlCheck = "SELECT id FROM photocopieurs_clients WHERE SerialNumber = :sn LIMIT 1";
                        $stmtCheck = $pdo->prepare($sqlCheck);
                        $stmtCheck->execute([':sn' => $snInput]);
                        $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    if ($existing) {
                        // Mise à jour si existe déjà
                        $sqlUpdate = "UPDATE photocopieurs_clients SET id_client = :id_client";
                        $paramsUpdate = [':id_client' => $idClient];
                        if ($macColon) {
                            $sqlUpdate .= ", MacAddress = :mac_address";
                            $paramsUpdate[':mac_address'] = $macColon;
                        }
                        if ($snInput !== '') {
                            $sqlUpdate .= ", SerialNumber = :sn";
                            $paramsUpdate[':sn'] = $snInput;
                        }
                        $sqlUpdate .= " WHERE id = :id";
                        $paramsUpdate[':id'] = $existing['id'];
                        
                        $pdo->prepare($sqlUpdate)->execute($paramsUpdate);
                    } else {
                        // Insertion si nouveau
                        $sqlInsert = "INSERT INTO photocopieurs_clients (id_client, MacAddress, SerialNumber) VALUES (:id_client, :mac_address, :sn)";
                        $pdo->prepare($sqlInsert)->execute([
                            ':id_client' => $idClient,
                            ':mac_address' => $macColon ?: null,
                            ':sn' => $snInput ?: null,
                        ]);
                    }
                    
                    $userId = currentUserId();
                    $details = "Photocopieur attribué: MAC=" . ($macNorm ?? 'N/A') . ", SN=" . ($snInput ?: 'N/A') . " → Client #" . $idClient;
                    enregistrerAction($pdo, $userId, 'photocopieur_attribue', $details);
                    $pdo->commit();
                    
                    header('Location: /public/clients.php?attached=1');
                    exit;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('clients.php attach_photocopieur error: ' . $e->getMessage());
                $flash = ['type' => 'error', 'msg' => "Erreur: " . $e->getMessage()];
                $shouldOpenAttachModal = true;
            } catch (PDOException $e) {
                // Rollback uniquement si une transaction est active
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('clients.php attach_photocopieur error: ' . $e->getMessage());
                $flash = ['type' => 'error', 'msg' => "Erreur SQL: impossible d'attribuer le photocopieur."];
                $shouldOpenAttachModal = true;
            }
        }
    } catch (RuntimeException $csrfEx) {
        $flash = ['type' => 'error', 'msg' => $csrfEx->getMessage()];
        $shouldOpenAttachModal = true;
    }
}

if (($_GET['attached'] ?? '') === '1') {
    $flash = ['type' => 'success', 'msg' => "Photocopieur attribué avec succès."];
}

// Récupération de la liste des clients pour le select
$clientsList = [];
try {
    $stmtClients = $pdo->prepare("
        SELECT id, numero_client, raison_sociale, nom_dirigeant, prenom_dirigeant
        FROM clients
        ORDER BY raison_sociale ASC
        LIMIT 500
    ");
    $stmtClients->execute();
    $clientsList = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('clients.php clients list error: ' . $e->getMessage());
}

// Détermination de la vue (assigned ou unassigned)
$view = ($_GET['view'] ?? 'assigned');
$view = ($view === 'unassigned') ? 'unassigned' : 'assigned';

// Construction de la requête SQL selon la vue
if ($view === 'unassigned') {
    // Non attribués = relevé sans client + entrée pc sans client et sans relevé
    $sql = "
    WITH v_compteur_unified AS (
      -- Relevés nouveaux (priorité 1)
      SELECT r.*, 'nouveau' AS source
      FROM compteur_relevee r
      UNION ALL
      -- Relevés anciens (priorité 2)
      SELECT r.*, 'ancien' AS source
      FROM compteur_relevee_ancien r
    ),
    v_compteur_last AS (
      SELECT r.*,
             ROW_NUMBER() OVER (
               PARTITION BY r.mac_norm 
               ORDER BY 
                 CASE r.source WHEN 'nouveau' THEN 0 ELSE 1 END,
                 r.`Timestamp` DESC
             ) AS rn
      FROM v_compteur_unified r
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
        v.source AS data_source,
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
        NULL AS data_source,
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
    // Tous les clients (avec ou sans photocopieur, avec ou sans relevé)
    $sql = "
    WITH v_compteur_unified AS (
      -- Relevés nouveaux (priorité 1)
      SELECT r.*, 'nouveau' AS source
      FROM compteur_relevee r
      UNION ALL
      -- Relevés anciens (priorité 2)
      SELECT r.*, 'ancien' AS source
      FROM compteur_relevee_ancien r
    ),
    v_compteur_last AS (
      SELECT r.*,
             ROW_NUMBER() OVER (
               PARTITION BY r.mac_norm 
               ORDER BY 
                 CASE r.source WHEN 'nouveau' THEN 0 ELSE 1 END,
                 r.`Timestamp` DESC
             ) AS rn
      FROM v_compteur_unified r
    ),
    v_last AS (
      SELECT *, TIMESTAMPDIFF(HOUR, `Timestamp`, NOW()) AS age_hours
      FROM v_compteur_last WHERE rn = 1
    )
    SELECT
      c.id AS client_id, c.numero_client, c.raison_sociale,
      c.nom_dirigeant, c.prenom_dirigeant, c.telephone1,
      pc.mac_norm,
      COALESCE(pc.SerialNumber, v.SerialNumber) AS SerialNumber,
      COALESCE(pc.MacAddress, v.MacAddress) AS MacAddress,
      v.Model, v.Nom,
      v.`Timestamp` AS last_ts, v.age_hours AS last_age_hours,
      v.TonerBlack, v.TonerCyan, v.TonerMagenta, v.TonerYellow,
      v.TotalBW, v.TotalColor, v.TotalPages, v.Status,
      v.source AS data_source
    FROM clients c
    LEFT JOIN photocopieurs_clients pc ON pc.id_client = c.id
    LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
    ORDER BY
      COALESCE(c.raison_sociale, '—') ASC,
      COALESCE(pc.SerialNumber, pc.mac_norm) ASC
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
        <span class="meta-label">Clients</span>
        <strong class="meta-value"><?= h((string)$uniqueClientsCount) ?></strong>
        <span class="meta-sub">Total dans la base</span>
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
        $sn      = $r['SerialNumber'] ?? '';
        $mac     = $r['MacAddress'] ?? '';
        $macNorm = $r['mac_norm'] ?? '';
        $nom     = $r['Nom'] ?: '';
        $lastTsRaw = $r['last_ts'] ?? null;
        $lastTs = formatDateTime($lastTsRaw, 'Y-m-d H:i');
        $ageHours = isset($r['last_age_hours']) ? (int)$r['last_age_hours'] : null;
        $totBW   = is_null($r['TotalBW'])    ? '—' : number_format((int)$r['TotalBW'], 0, ',', ' ');
        $totCol  = is_null($r['TotalColor']) ? '—' : number_format((int)$r['TotalColor'], 0, ',', ' ');

        $dataSource = $r['data_source'] ?? null; // 'nouveau', 'ancien', ou null
        $isAncien = ($dataSource === 'ancien');

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
        
        // Nettoyer les valeurs
        $macNormClean = trim((string)($macNorm ?? ''));
        $macClean = trim((string)($mac ?? ''));
        $snClean = trim((string)($sn ?? ''));
        
        // Priorité 1 : Utiliser mac_norm si valide (12 hex sans séparateurs)
        if ($macNormClean !== '' && preg_match('/^[0-9A-F]{12}$/i', $macNormClean)) {
            $macNormUpper = strtoupper($macNormClean);
            $rowHref = '/public/photocopieurs_details.php?mac=' . urlencode($macNormUpper);
        }
        // Priorité 2 : Normaliser MacAddress si mac_norm n'est pas valide
        elseif ($macClean !== '') {
            $normalizedMac = normalizeMacForUrl($macClean);
            if ($normalizedMac !== null && $normalizedMac !== '') {
                $rowHref = '/public/photocopieurs_details.php?mac=' . urlencode($normalizedMac);
            }
        }
        
        // Priorité 3 : Fallback sur SerialNumber si aucune MAC valide
        if ($rowHref === '' && $snClean !== '') {
            $rowHref = '/public/photocopieurs_details.php?sn=' . urlencode($snClean);
        }

        $isAlert = rowHasAlert($r);
        $isUnassigned = empty($r['client_id']);

        $rowClasses = [];
        // Toutes les lignes sont cliquables (soit pour redirection, soit pour modale)
        $rowClasses[] = 'is-clickable';
        if ($isAlert) {
            $rowClasses[] = 'row-alert';
        }
        $rowClassAttr = $rowClasses ? ' class="'.h(implode(' ', $rowClasses)).'"' : '';
        $rowHrefAttr = ($rowHref && !$isUnassigned) ? ' data-href="'.h($rowHref).'"' : '';
      ?>
        <tr data-search="<?= h($searchText) ?>"
            <?= $rowHrefAttr ?>
            <?= $rowClassAttr ?>
            <?= $isUnassigned ? ' data-unassigned="1"' : '' ?>
            data-mac-norm="<?= h($macNormClean) ?>"
            data-mac-address="<?= h($macClean) ?>"
            data-serial-number="<?= h($snClean) ?>"
            data-model="<?= h($modele) ?>">
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
              <div class="machine-line">
                <strong><?= h($modele ?: '—') ?></strong>
                <?php if ($isAncien): ?>
                  <span class="ancien-badge" title="Données provenant de l'ancien système" aria-label="Ancien système">📜</span>
                <?php endif; ?>
              </div>
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
        <?= $view==='unassigned' ? "Aucun photocopieur non attribué." : "Aucun client trouvé." ?>
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

<!-- ===== Popup "Attribuer un photocopieur" ===== -->
<div id="attachModalOverlay" class="popup-overlay" aria-hidden="true"></div>
<div id="attachModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="attachModalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="attachModalTitle">Attribuer un photocopieur à un client</h3>
    <button type="button" id="btnCloseAttachModal" class="icon-btn icon-btn--close" aria-label="Fermer"><span aria-hidden="true">×</span></button>
  </div>

  <?php if ($flash['type'] && $shouldOpenAttachModal): ?>
    <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
      <?= h($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
      <input type="hidden" name="action" value="attach_photocopieur">
      <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
      <input type="hidden" name="mac_address" id="attachMacAddress" value="<?= h($_POST['mac_address'] ?? '') ?>">
      <input type="hidden" name="serial_number" id="attachSerialNumber" value="<?= h($_POST['serial_number'] ?? '') ?>">

      <div class="card-like">
        <div class="subsection-title">Informations photocopieur</div>
        <div id="attachDeviceInfo" style="padding: 0.75rem; background: var(--bg-elevated); border-radius: 4px; margin-bottom: 1rem;">
          <div><strong>Modèle:</strong> <span id="attachModel">—</span></div>
          <div><strong>N° de série:</strong> <span id="attachSN">—</span></div>
          <div><strong>Adresse MAC:</strong> <span id="attachMAC">—</span></div>
        </div>

        <div class="subsection-title">Sélectionner un client</div>
        <label for="attachClientSearch">Client*</label>
        <div class="client-search-wrapper" style="position: relative;">
          <input 
            type="text" 
            id="attachClientSearch" 
            class="client-search-input" 
            placeholder="Rechercher par référence, raison sociale ou nom..."
            autocomplete="off"
            style="width:100%; padding:0.5rem; box-sizing:border-box;">
          <input type="hidden" name="id_client" id="attachClientId" value="<?= h(isset($_POST['id_client']) ? (string)$_POST['id_client'] : '') ?>" required>
          <div id="attachClientResults" class="client-results" style="display:none;"></div>
        </div>
      </div>

    <div class="modal-actions">
      <div class="modal-hint">* obligatoire — Le photocopieur sera attribué au client sélectionné</div>
      <button type="submit" class="fiche-action-btn">Valider l'attribution</button>
    </div>
  </form>
</div>

<!-- JS -->
<script src="/assets/js/clients.js"></script>

<script>
  // Données clients pour la recherche
  window.__CLIENTS_DATA__ = <?= json_encode($clientsList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  
  // Ouverture auto si erreurs validation
  window.__CLIENT_MODAL_INIT_OPEN__ = <?= json_encode($shouldOpenModal) ?>;
  window.__ATTACH_MODAL_INIT_OPEN__ = <?= json_encode($shouldOpenAttachModal) ?>;

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
    if (window.__ATTACH_MODAL_INIT_OPEN__) {
      // Si on a des données POST, les utiliser directement
      const macAddress = document.getElementById('attachMacAddress').value;
      const serialNumber = document.getElementById('attachSerialNumber').value;
      
      if (macAddress || serialNumber) {
        // Remplir les informations depuis les champs cachés
        document.getElementById('attachSN').textContent = serialNumber || '—';
        document.getElementById('attachMAC').textContent = macAddress || '—';
        
        // Ouvrir la modale
        document.body.classList.add('modal-open');
        attachModalOverlay.setAttribute('aria-hidden', 'false');
        attachModalOverlay.style.display = 'block';
        attachModal.style.display = 'block';
        
        // Réinitialiser le champ de recherche
        const searchInput = document.getElementById('attachClientSearch');
        const clientIdInput = document.getElementById('attachClientId');
        const resultsDiv = document.getElementById('attachClientResults');
        if (searchInput) {
          setTimeout(() => {
            searchInput.value = '';
            if (clientIdInput) clientIdInput.value = '';
            if (resultsDiv) {
              resultsDiv.style.display = 'none';
              resultsDiv.classList.remove('show');
            }
            searchInput.focus();
          }, 100);
        }
      } else {
        // Sinon, trouver la première ligne non attribuée
        const firstUnassigned = document.querySelector('table#tbl tbody tr[data-unassigned="1"]');
        if (firstUnassigned) {
          openAttachModal(firstUnassigned);
        }
      }
    }

    // Gestion de la modale d'attribution
    const attachModalOverlay = document.getElementById('attachModalOverlay');
    const attachModal = document.getElementById('attachModal');
    const btnCloseAttachModal = document.getElementById('btnCloseAttachModal');

    function openAttachModal(tr) {
      const macNorm = tr.getAttribute('data-mac-norm') || '';
      const macAddress = tr.getAttribute('data-mac-address') || '';
      const serialNumber = tr.getAttribute('data-serial-number') || '';
      const model = tr.getAttribute('data-model') || '—';

      // Remplir les champs cachés
      document.getElementById('attachMacAddress').value = macAddress;
      document.getElementById('attachSerialNumber').value = serialNumber;

      // Afficher les informations
      document.getElementById('attachModel').textContent = model;
      document.getElementById('attachSN').textContent = serialNumber || '—';
      document.getElementById('attachMAC').textContent = macAddress || macNorm || '—';

      // Réinitialiser la recherche de client
      const searchInput = document.getElementById('attachClientSearch');
      const clientIdInput = document.getElementById('attachClientId');
      const resultsDiv = document.getElementById('attachClientResults');
      if (searchInput) searchInput.value = '';
      if (clientIdInput) clientIdInput.value = '';
      if (resultsDiv) {
        resultsDiv.style.display = 'none';
        resultsDiv.classList.remove('show');
      }

      // Ouvrir la modale
      document.body.classList.add('modal-open');
      attachModalOverlay.setAttribute('aria-hidden', 'false');
      attachModalOverlay.style.display = 'block';
      attachModal.style.display = 'block';
      
      // Focus sur le champ de recherche
      if (searchInput) {
        setTimeout(() => searchInput.focus(), 100);
      }
    }

    function closeAttachModal() {
      document.body.classList.remove('modal-open');
      attachModalOverlay.setAttribute('aria-hidden', 'true');
      attachModalOverlay.style.display = 'none';
      attachModal.style.display = 'none';
    }

    if (btnCloseAttachModal) {
      btnCloseAttachModal.addEventListener('click', closeAttachModal);
    }
    if (attachModalOverlay) {
      attachModalOverlay.addEventListener('click', closeAttachModal);
    }

    // Lignes cliquables
    const rows = document.querySelectorAll('table#tbl tbody tr.is-clickable');
    rows.forEach(tr => {
      tr.style.cursor = 'pointer';
      tr.addEventListener('click', (e) => {
        // Ne pas déclencher si l'utilisateur sélectionne du texte
        if (window.getSelection && String(window.getSelection()).trim()) {
          return;
        }

        // Vérifier si c'est un photocopieur non attribué
        const isUnassigned = tr.getAttribute('data-unassigned') === '1';
        
        if (isUnassigned) {
          // Ouvrir la modale d'attribution
          openAttachModal(tr);
        } else {
          // Rediriger vers la page de détails
          const href = tr.getAttribute('data-href');
          if (href && href.trim() !== '') {
            try {
              window.location.assign(href);
            } catch (err) {
              console.error('Erreur lors de la redirection:', err, 'URL:', href);
            }
          } else {
            console.warn('Ligne cliquable sans href valide:', tr);
          }
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

    // ===== Recherche de client avec autocomplétion =====
    (function() {
      const searchInput = document.getElementById('attachClientSearch');
      const clientIdInput = document.getElementById('attachClientId');
      const resultsDiv = document.getElementById('attachClientResults');
      const clientsData = window.__CLIENTS_DATA__ || [];

      if (!searchInput || !clientIdInput || !resultsDiv) return;

      let selectedIndex = -1;
      let filteredClients = [];

      // Fonction pour mettre en évidence le texte recherché
      function highlightText(text, query) {
        if (!query) return text;
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return text.replace(regex, '<span class="highlight">$1</span>');
      }

      // Fonction de recherche
      function searchClients(query) {
        if (!query || query.trim().length < 1) {
          return [];
        }

        const q = query.trim().toLowerCase();
        return clientsData.filter(client => {
          const numero = (client.numero_client || '').toLowerCase();
          const raison = (client.raison_sociale || '').toLowerCase();
          const nom = (client.nom_dirigeant || '').toLowerCase();
          const prenom = (client.prenom_dirigeant || '').toLowerCase();
          const fullName = (nom + ' ' + prenom).trim().toLowerCase();

          return numero.includes(q) || 
                 raison.includes(q) || 
                 nom.includes(q) || 
                 prenom.includes(q) ||
                 fullName.includes(q);
        }).slice(0, 20); // Limiter à 20 résultats
      }

      // Fonction pour afficher les résultats
      function displayResults(clients) {
        filteredClients = clients;
        selectedIndex = -1;

        if (clients.length === 0) {
          resultsDiv.innerHTML = '<div class="client-result-item empty">Aucun client trouvé</div>';
          resultsDiv.classList.add('show');
          resultsDiv.style.display = 'block';
          return;
        }

        const query = searchInput.value.trim().toLowerCase();
        resultsDiv.innerHTML = clients.map((client, index) => {
          const numero = client.numero_client || '';
          const raison = client.raison_sociale || '';
          const nom = client.nom_dirigeant || '';
          const prenom = client.prenom_dirigeant || '';
          const fullName = (nom + ' ' + prenom).trim();

          return `
            <div class="client-result-item" data-index="${index}" data-client-id="${client.id}">
              <strong>${highlightText(raison, query)}</strong>
              <span class="client-ref">${highlightText(numero, query)}</span>
              ${fullName ? `<span class="client-name">${highlightText(fullName, query)}</span>` : ''}
            </div>
          `;
        }).join('');

        resultsDiv.classList.add('show');
        resultsDiv.style.display = 'block';

        // Ajouter les event listeners sur les items
        resultsDiv.querySelectorAll('.client-result-item').forEach(item => {
          item.addEventListener('click', function() {
            const clientId = this.getAttribute('data-client-id');
            const index = parseInt(this.getAttribute('data-index'));
            selectClient(clientId, index);
          });

          item.addEventListener('mouseenter', function() {
            resultsDiv.querySelectorAll('.client-result-item').forEach(i => i.classList.remove('selected'));
            this.classList.add('selected');
            selectedIndex = parseInt(this.getAttribute('data-index'));
          });
        });
      }

      // Fonction pour sélectionner un client
      function selectClient(clientId, index) {
        if (index >= 0 && index < filteredClients.length) {
          const client = filteredClients[index];
          const numero = client.numero_client || '';
          const raison = client.raison_sociale || '';
          const nom = client.nom_dirigeant || '';
          const prenom = client.prenom_dirigeant || '';
          const fullName = (nom + ' ' + prenom).trim();
          
          searchInput.value = `${numero} — ${raison}${fullName ? ' (' + fullName + ')' : ''}`;
          clientIdInput.value = clientId;
          
          resultsDiv.style.display = 'none';
          resultsDiv.classList.remove('show');
          selectedIndex = -1;
        }
      }

      // Event listener sur le champ de recherche
      let searchTimeout;
      searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        const query = this.value.trim();

        if (query.length === 0) {
          resultsDiv.style.display = 'none';
          resultsDiv.classList.remove('show');
          clientIdInput.value = '';
          return;
        }

        // Debounce de 150ms
        searchTimeout = setTimeout(() => {
          const results = searchClients(query);
          displayResults(results);
        }, 150);
      });

      // Navigation au clavier
      searchInput.addEventListener('keydown', function(e) {
        if (!resultsDiv.classList.contains('show') || filteredClients.length === 0) {
          return;
        }

        if (e.key === 'ArrowDown') {
          e.preventDefault();
          selectedIndex = Math.min(selectedIndex + 1, filteredClients.length - 1);
          updateSelection();
        } else if (e.key === 'ArrowUp') {
          e.preventDefault();
          selectedIndex = Math.max(selectedIndex - 1, -1);
          updateSelection();
        } else if (e.key === 'Enter') {
          e.preventDefault();
          if (selectedIndex >= 0 && selectedIndex < filteredClients.length) {
            selectClient(filteredClients[selectedIndex].id, selectedIndex);
          }
        } else if (e.key === 'Escape') {
          resultsDiv.style.display = 'none';
          resultsDiv.classList.remove('show');
          selectedIndex = -1;
        }
      });

      function updateSelection() {
        const items = resultsDiv.querySelectorAll('.client-result-item');
        items.forEach((item, idx) => {
          if (idx === selectedIndex) {
            item.classList.add('selected');
            item.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          } else {
            item.classList.remove('selected');
          }
        });
      }

      // Fermer la liste si on clique en dehors
      document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !resultsDiv.contains(e.target)) {
          resultsDiv.style.display = 'none';
          resultsDiv.classList.remove('show');
        }
      });

      // Réinitialiser lors de l'ouverture de la modale
      // On intercepte l'ouverture de la modale pour réinitialiser le champ de recherche
      const attachModalEl = document.getElementById('attachModal');
      if (attachModalEl) {
        const observer = new MutationObserver(function(mutations) {
          mutations.forEach(function(mutation) {
            if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
              const isVisible = attachModalEl.style.display !== 'none';
              if (isVisible && searchInput) {
                // Réinitialiser le champ de recherche quand la modale s'ouvre
                setTimeout(() => {
                  searchInput.value = '';
                  clientIdInput.value = '';
                  resultsDiv.style.display = 'none';
                  resultsDiv.classList.remove('show');
                  searchInput.focus();
                }, 100);
              }
            }
          });
        });
        observer.observe(attachModalEl, { attributes: true, attributeFilter: ['style'] });
      }
    })();
  })();
</script>
</body>
</html>
