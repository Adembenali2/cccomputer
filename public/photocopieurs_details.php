<?php
// /public/photocopieurs_details.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('photocopieurs_details', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

const CLIENT_OPTIONS_LIMIT = 500;

// Les fonctions h(), normalizeMac(), ensureCsrfToken(), assertValidCsrf() sont définies dans includes/helpers.php

function logDeviceAction(PDO $pdo, string $action, string $details): void {
  try {
    enregistrerAction($pdo, $_SESSION['user_id'] ?? null, $action, $details);
  } catch (Throwable $e) {
    error_log('photocopieurs_details log error: ' . $e->getMessage());
  }
}

/* ---------- Entrée ---------- */
/**
 * CORRECTION BUG : Normalisation de la MAC avant validation
 * 
 * PROBLÈME IDENTIFIÉ :
 * - La MAC peut arriver dans l'URL avec des séparateurs (ex: "00:26:73:3F:C6:94")
 * - L'ancienne validation exigeait 12 hex sans séparateurs, ce qui faisait échouer la validation
 * - Résultat : aucun relevé n'était chargé même s'ils existaient en base
 * 
 * SOLUTION :
 * - Normaliser la MAC AVANT la validation en utilisant normalizeMac()
 * - Cette fonction enlève tous les séparateurs et vérifie que la MAC fait 12 hex
 * - La version normalisée ($macParam) est utilisée dans toutes les requêtes SQL
 * - Cela garantit que les relevés sont trouvés dans compteur_relevee ET compteur_relevee_ancien
 * 
 * FORMAT ATTENDU EN BASE :
 * - La colonne mac_norm est générée comme : replace(upper(MacAddress),':','')
 * - Format stocké : 12 hex sans séparateurs (ex: "0026733FC694")
 * - $macParam utilise exactement ce format pour la correspondance WHERE
 */
$macInput = trim($_GET['mac'] ?? ''); // MAC brute depuis l'URL (peut contenir des ':')
$snParam  = trim($_GET['sn'] ?? '');

// Normaliser la MAC si fournie (enlever les séparateurs et vérifier qu'elle fait 12 hex)
$macParam = null;
if ($macInput !== '') {
  $normalized = normalizeMac($macInput);
  if ($normalized['norm'] !== null) {
    $macParam = $normalized['norm']; // Format normalisé : 12 hex sans séparateurs (ex: "0026733FC694")
  }
}

// Déterminer quel critère utiliser pour la recherche
$useMac = false; 
$useSn = false;
if ($macParam !== null && $macParam !== '') {
  $useMac = true;
} elseif ($snParam !== '') {
  $useSn = true;
} else {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><p>Paramètre manquant ou invalide. Utilisez ?mac=001122AABBCC (12 hex) ou ?sn=SERIAL.</p>";
  exit;
}

/* ---------- Action: associer un client ---------- */
$flash = ['type'=>null,'msg'=>null];
$shouldOpenAttachModal = false;
$csrfToken = ensureCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'attach_client') {
    try {
        assertValidCsrf($_POST['csrf_token'] ?? '');
    } catch (RuntimeException $csrfEx) {
        $flash = ['type'=>'error','msg'=>$csrfEx->getMessage()];
        $shouldOpenAttachModal = true;
    }

    $clientId = (int)($_POST['id_client'] ?? 0);
    $snPost   = trim($_POST['sn'] ?? '');
    $macPost  = trim($_POST['mac'] ?? '');

    $mac = normalizeMac($macPost);
    $macNorm  = $mac['norm'];   // AABBCCDDEEFF
    $macColon = $mac['colon'];  // AA:BB:CC:DD:EE:FF

    // ✅ On accepte SN seul OU MAC seule (au moins un des deux)
    if ($clientId <= 0 || ($snPost === '' && !$macNorm)) {
        $flash = ['type'=>'error','msg'=>"Veuillez choisir un client et fournir au moins le N° de série ou la MAC."];
        $shouldOpenAttachModal = true;
    } else {
        try {
            // Construire l'INSERT dynamiquement (on n'écrit JAMAIS mac_norm car colonne générée)
            $sql = "
              INSERT INTO photocopieurs_clients (MacAddress, SerialNumber, id_client)
              VALUES (:mac_addr, :sn, :id_client)
              ON DUPLICATE KEY UPDATE
                id_client    = VALUES(id_client),
                SerialNumber = VALUES(SerialNumber),
                MacAddress   = VALUES(MacAddress)
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':mac_addr'  => $macColon ?: null,                // peut être NULL si MAC inconnue
                ':sn'        => ($snPost !== '' ? $snPost : null),// peut être NULL si SN inconnu
                ':id_client' => $clientId
            ]);
            logDeviceAction($pdo, 'photocopieur_attribue', "Photocopieur SN='{$snPost}' MAC='{$macNorm}' attribué au client #{$clientId}");

            header('Location: '.$_SERVER['REQUEST_URI']);
            exit;

        } catch (PDOException $e) {
            error_log('attach_client error: '.$e->getMessage());
            $flash = ['type'=>'error','msg'=>"Erreur: impossible d'associer le client."];
            $shouldOpenAttachModal = true;
        }
    }
}

/* ---------- Lecture relevés ---------- */
/**
 * CORRECTION BUG DÉFINITIVE : Recherche robuste des relevés
 * 
 * PROBLÈME IDENTIFIÉ :
 * - Si MacAddress est NULL dans les relevés, mac_norm sera aussi NULL (colonne générée)
 * - La requête WHERE mac_norm = :mac ne trouve rien si mac_norm est NULL
 * - Certains relevés anciens peuvent avoir MacAddress NULL mais SerialNumber valide
 * - Le fallback par SerialNumber ne fonctionnait que si on trouvait le SN dans photocopieurs_clients
 * 
 * SOLUTION AMÉLIORÉE :
 * - Recherche d'abord par mac_norm (si MAC fournie)
 * - Si aucun résultat, récupérer le SerialNumber depuis photocopieurs_clients (si disponible)
 * - Si SerialNumber trouvé, rechercher TOUS les relevés par SerialNumber (même ceux avec MacAddress NULL)
 * - UNION ALL pour combiner compteur_relevee et compteur_relevee_ancien
 * - Recherche aussi par SerialNumber directement dans les relevés si photocopieurs_clients n'a pas de SN
 */
try {
  // Sélection explicite des colonnes nécessaires au lieu de SELECT * pour améliorer les performances
  $columns = "id, `Timestamp`, Model, Nom, Status, IpAddress, MacAddress, SerialNumber, 
              TonerBlack, TonerCyan, TonerMagenta, TonerYellow, TotalBW, TotalColor, TotalPages";
  
  $rows = [];
  
  
  if ($useMac) {
    // Recherche principale par mac_norm (format normalisé : 12 hex sans séparateurs)
    // IMPORTANT : $macParam est déjà normalisé (12 hex sans séparateurs) grâce à normalizeMac()
    // La colonne mac_norm est générée comme : replace(upper(MacAddress),':','')
    // Format attendu : 12 hex en majuscules sans séparateurs (ex: "0026733FC694")
    // Si MacAddress est NULL, mac_norm sera NULL aussi, donc la condition mac_norm = :mac ne trouvera rien
    // CORRECTION BUG HY093 : PDO ne permet pas d'utiliser le même paramètre deux fois dans UNION ALL
    // On utilise :mac1 et :mac2 avec la même valeur
    $sql = "
      SELECT {$columns}, 'nouveau' AS source
      FROM compteur_relevee 
      WHERE mac_norm = :mac1
      UNION ALL
      SELECT {$columns}, 'ancien' AS source
      FROM compteur_relevee_ancien 
      WHERE mac_norm = :mac2
      ORDER BY `Timestamp` DESC, id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':mac1' => $macParam, ':mac2' => $macParam]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Si aucun résultat par MAC, essayer de trouver le SerialNumber associé à cette MAC
    // Cela gère le cas où les relevés ont MacAddress NULL mais SerialNumber valide
    if (empty($rows)) {
      try {
        $snFromMac = null;
        
        // ÉTAPE 1 : Essayer d'abord dans photocopieurs_clients (photocopieur attribué)
        // C'est la source la plus fiable car c'est la liaison client-photocopieur
        // IMPORTANT : On récupère le SerialNumber même si la recherche par MAC n'a rien donné
        // car les relevés peuvent avoir MacAddress NULL mais SerialNumber valide
        $sqlSn1 = "SELECT SerialNumber FROM photocopieurs_clients WHERE mac_norm = :mac AND SerialNumber IS NOT NULL AND SerialNumber != '' LIMIT 1";
        
        $stmtSn = $pdo->prepare($sqlSn1);
        $stmtSn->execute([':mac' => $macParam]);
        $snFromMac = $stmtSn->fetchColumn();
        
        // ÉTAPE 2 : Si pas trouvé dans photocopieurs_clients, chercher dans les relevés eux-mêmes
        // On cherche un SerialNumber dans les relevés qui ont cette MAC (même si mac_norm est NULL)
        // On normalise MacAddress manuellement pour trouver même si mac_norm est NULL dans certains cas
        // CORRECTION BUG HY093 : PDO ne permet pas d'utiliser le même paramètre deux fois dans UNION
        // On utilise :mac1 et :mac2 avec la même valeur
        if (($snFromMac === false || $snFromMac === null || $snFromMac === '') && !empty($macParam)) {
          $sqlSn2 = "
            SELECT DISTINCT SerialNumber 
            FROM (
              SELECT SerialNumber FROM compteur_relevee 
              WHERE REPLACE(UPPER(COALESCE(MacAddress, '')), ':', '') = :mac1 
                AND SerialNumber IS NOT NULL AND SerialNumber != ''
              UNION
              SELECT SerialNumber FROM compteur_relevee_ancien 
              WHERE REPLACE(UPPER(COALESCE(MacAddress, '')), ':', '') = :mac2 
                AND SerialNumber IS NOT NULL AND SerialNumber != ''
            ) AS combined
            LIMIT 1
          ";
          
          $stmtSn2 = $pdo->prepare($sqlSn2);
          $stmtSn2->execute([':mac1' => $macParam, ':mac2' => $macParam]);
          $snFromMac = $stmtSn2->fetchColumn();
        }
        
        // ÉTAPE 3 : Si on a trouvé un SerialNumber, rechercher TOUS les relevés par SerialNumber
        // Cela permet de trouver même les relevés avec MacAddress NULL
        // C'est la clé : même si MacAddress est NULL dans les relevés, on peut les trouver par SerialNumber
        // CORRECTION BUG HY093 : PDO ne permet pas d'utiliser le même paramètre deux fois dans UNION ALL
        // On utilise :sn1 et :sn2 avec la même valeur
        if ($snFromMac !== false && $snFromMac !== null && $snFromMac !== '') {
          $sqlSn = "
            SELECT {$columns}, 'nouveau' AS source
            FROM compteur_relevee 
            WHERE SerialNumber = :sn1
            UNION ALL
            SELECT {$columns}, 'ancien' AS source
            FROM compteur_relevee_ancien 
            WHERE SerialNumber = :sn2
            ORDER BY `Timestamp` DESC, id DESC
          ";
          
          $stmtSn3 = $pdo->prepare($sqlSn);
          $stmtSn3->execute([':sn1' => $snFromMac, ':sn2' => $snFromMac]);
          $rows = $stmtSn3->fetchAll(PDO::FETCH_ASSOC);
        }
      } catch (PDOException $eSn) {
        error_log('photocopieurs_details fallback SN search error: '.$eSn->getMessage());
      }
    }
  } else {
    // Recherche par numéro de série
    // CORRECTION BUG HY093 : PDO ne permet pas d'utiliser le même paramètre deux fois dans UNION ALL
    // On utilise :sn1 et :sn2 avec la même valeur
    $sql = "
      SELECT {$columns}, 'nouveau' AS source
      FROM compteur_relevee 
      WHERE SerialNumber = :sn1
      UNION ALL
      SELECT {$columns}, 'ancien' AS source
      FROM compteur_relevee_ancien 
      WHERE SerialNumber = :sn2
      ORDER BY `Timestamp` DESC, id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':sn1' => $snParam, ':sn2' => $snParam]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
  
  // Logger si aucun résultat trouvé (uniquement en cas de problème)
  if (empty($rows)) {
    error_log('photocopieurs_details: Aucun relevé trouvé pour ' . ($useMac ? 'MAC=' . $macParam : 'SN=' . $snParam));
  }
} catch (PDOException $e) {
  error_log('photocopieurs_details SQL error: '.$e->getMessage());
  error_log('photocopieurs_details SQL query: '.($sql ?? 'N/A'));
  error_log('photocopieurs_details SQL params: '.json_encode($useMac ? [':mac' => $macParam] : [':sn' => $snParam]));
  $rows = [];
}

// [Fonctionnalité consommation]
$premierReleve = null;
$dernierReleve = null;
$premierReleveMois = null;
$historiqueReleves = [];
$consoTotalBW = 0;
$consoTotalColor = 0;
$consoMoisBW = 0;
$consoMoisColor = 0;
if (!empty($macParam)) {
  try {
    $stmtFirst = $pdo->prepare("
      SELECT TotalBW, TotalColor, Timestamp FROM (
        SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee WHERE mac_norm = :mac1
        UNION ALL
        SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee_ancien WHERE mac_norm = :mac2
      ) r WHERE TotalBW IS NOT NULL ORDER BY Timestamp ASC LIMIT 1
    ");
    $stmtFirst->execute([':mac1' => $macParam, ':mac2' => $macParam]);
    $premierReleve = $stmtFirst->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtLast = $pdo->prepare("
      SELECT TotalBW, TotalColor, TotalPages, Timestamp, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
      FROM (
        SELECT TotalBW, TotalColor, TotalPages, Timestamp, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
        FROM compteur_relevee WHERE mac_norm = :mac1
        UNION ALL
        SELECT TotalBW, TotalColor, TotalPages, Timestamp, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
        FROM compteur_relevee_ancien WHERE mac_norm = :mac2
      ) r WHERE TotalBW IS NOT NULL ORDER BY Timestamp DESC LIMIT 1
    ");
    $stmtLast->execute([':mac1' => $macParam, ':mac2' => $macParam]);
    $dernierReleve = $stmtLast->fetch(PDO::FETCH_ASSOC) ?: null;

    $debutMois = date('Y-m-01 00:00:00');
    $stmtMoisFirst = $pdo->prepare("
      SELECT TotalBW, TotalColor, Timestamp FROM (
        SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee WHERE mac_norm = :mac1 AND Timestamp >= :debut1
        UNION ALL
        SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee_ancien WHERE mac_norm = :mac2 AND Timestamp >= :debut2
      ) r WHERE TotalBW IS NOT NULL ORDER BY Timestamp ASC LIMIT 1
    ");
    $stmtMoisFirst->execute([':mac1' => $macParam, ':debut1' => $debutMois, ':mac2' => $macParam, ':debut2' => $debutMois]);
    $premierReleveMois = $stmtMoisFirst->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($premierReleve && $dernierReleve) {
      $consoTotalBW = max(0, (int)$dernierReleve['TotalBW'] - (int)$premierReleve['TotalBW']);
      $consoTotalColor = max(0, (int)$dernierReleve['TotalColor'] - (int)$premierReleve['TotalColor']);
    }
    if ($premierReleveMois && $dernierReleve) {
      $consoMoisBW = max(0, (int)$dernierReleve['TotalBW'] - (int)$premierReleveMois['TotalBW']);
      $consoMoisColor = max(0, (int)$dernierReleve['TotalColor'] - (int)$premierReleveMois['TotalColor']);
    }

    $stmtHisto = $pdo->prepare("
      SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
      FROM (
        SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
        FROM compteur_relevee WHERE mac_norm = :mac1
        UNION ALL
        SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
        FROM compteur_relevee_ancien WHERE mac_norm = :mac2
      ) r WHERE TotalBW IS NOT NULL ORDER BY Timestamp DESC LIMIT 30
    ");
    $stmtHisto->execute([':mac1' => $macParam, ':mac2' => $macParam]);
    $historiqueReleves = $stmtHisto->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($historiqueReleves as $i => &$row) {
      $next = $historiqueReleves[$i + 1] ?? null;
      if ($next) {
        $row['delta_bw'] = max(0, (int)$row['TotalBW'] - (int)$next['TotalBW']);
        $row['delta_color'] = max(0, (int)$row['TotalColor'] - (int)$next['TotalColor']);
      } else {
        $row['delta_bw'] = max(0, (int)$row['TotalBW'] - (int)($premierReleve['TotalBW'] ?? 0));
        $row['delta_color'] = max(0, (int)$row['TotalColor'] - (int)($premierReleve['TotalColor'] ?? 0));
      }
      $row['conso_depuis_debut_bw'] = max(0, (int)$row['TotalBW'] - (int)($premierReleve['TotalBW'] ?? 0));
      $row['conso_depuis_debut_color'] = max(0, (int)$row['TotalColor'] - (int)($premierReleve['TotalColor'] ?? 0));
    }
    unset($row);
  } catch (PDOException $e) {
    error_log('[photocopieurs_details] Erreur consommation: ' . $e->getMessage());
  }
}

/* ---------- En-tête (infos machine) ---------- */
$latest     = !empty($rows) ? $rows[0] : null;
$macPrettyFromParam = $useMac ? implode(':', str_split($macParam,2)) : null;

// Vérifier que $latest existe avant d'accéder à ses propriétés
$macDisplay = ($latest && isset($latest['MacAddress']))   ? $latest['MacAddress']   : ($macPrettyFromParam ?: '—');
$snDisplay  = ($latest && isset($latest['SerialNumber'])) ? $latest['SerialNumber'] : ($useSn ? $snParam : '—');
$model      = ($latest && isset($latest['Model']))        ? $latest['Model']        : '—';
$name       = ($latest && isset($latest['Nom']))          ? $latest['Nom']          : '—';
$status     = ($latest && isset($latest['Status']))       ? $latest['Status']       : '—';
$ipDisplay  = ($latest && isset($latest['IpAddress']))    ? $latest['IpAddress']    : '—';

/* ---------- Client attribué (si existe) ---------- */
$client = null;
try {
  if ($useMac) {
    $q = $pdo->prepare("
      SELECT c.id, c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
      FROM photocopieurs_clients pc
      LEFT JOIN clients c ON c.id = pc.id_client
      WHERE pc.mac_norm = :mac
      LIMIT 1
    ");
    $q->execute([':mac' => $macParam]);
  } else {
    $q = $pdo->prepare("
      SELECT c.id, c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
      FROM photocopieurs_clients pc
      LEFT JOIN clients c ON c.id = pc.id_client
      WHERE pc.SerialNumber = :sn
      LIMIT 1
    ");
    $q->execute([':sn' => $snParam]);
  }
  $client = $q->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
  error_log('photocopieurs_details client lookup error: '.$e->getMessage());
  $client = null;
}

/* ---------- Liste des clients (pour la popup) ---------- */
$clientsList = [];
try {
  $stmt = $pdo->prepare("
    SELECT id, numero_client, raison_sociale,
           COALESCE(nom_dirigeant,'') AS nom_dirigeant,
           COALESCE(prenom_dirigeant,'') AS prenom_dirigeant,
           telephone1
    FROM clients
    ORDER BY raison_sociale ASC
    LIMIT 500
  ");
  $stmt->execute();
  $clientsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('clients list error: '.$e->getMessage());
}

/* ---------- Helpers ---------- */
function pctOrIntOrNull($v): ?int {
  if ($v === null || $v === '' || !is_numeric($v)) return null;
  return max(0, min(100, (int)$v));
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Détails photocopieur — Historique</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/photocopieurs_details.css" />
</head>
<body class="page-details">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

  <div class="page-container">
    <div class="toolbar">
      <a href="/public/clients.php" class="back-link">← Retour</a>

      <?php if ($client && ($client['id'] ?? null)): ?>
        <a href="/public/client_fiche.php?id=<?= (int)$client['id'] ?>" class="btn btn-primary" id="btn-espace-client">Espace client</a>
      <?php else: ?>
        <!-- Pas encore attribué → ouvre le formulaire d’attribution -->
        <button type="button" class="btn btn-primary" id="btn-espace-client">Attribuer</button>
      <?php endif; ?>
    </div>

    <?php if ($flash['type']): ?>
      <div class="flash <?= $flash['type']==='error'?'flash-error':'flash-success' ?>" style="margin-bottom:.75rem;">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="details-header">
      <div class="h1">Historique du photocopieur</div>

      <div class="meta-grid">
        <div class="meta-block">
          <span class="label">Modèle</span>
          <strong><?= h($model) ?></strong>
          <span class="sub">Nom: <?= h($name) ?></span>
        </div>
        <div class="meta-block">
          <span class="label">Série</span>
          <strong><?= h($snDisplay) ?></strong>
          <span class="sub">MAC: <?= h($macDisplay) ?></span>
        </div>
        <div class="meta-block">
          <span class="label">Réseau</span>
          <strong><?= h($ipDisplay) ?></strong>
          <span class="sub">Statut: <?= h($status) ?></span>
        </div>
        <?php if ($client): ?>
        <div class="meta-block">
          <span class="label">Client</span>
          <strong><?= h($client['raison_sociale'] ?? '—') ?></strong>
          <span class="sub"><?= h(trim(($client['nom_dirigeant'] ?? '').' '.($client['prenom_dirigeant'] ?? '')) ?: '—') ?> · <?= h($client['telephone1'] ?? '—') ?></span>
        </div>
        <?php endif; ?>
      </div>

      <?php if (!$rows): ?>
        <div class="muted">Aucun relevé trouvé pour ce photocopieur.</div>
      <?php endif; ?>
    </div>

    <?php if ($rows): ?>
      <!-- [Fonctionnalité consommation] -->
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;text-align:center;">
          <div style="font-size:0.8rem;color:#6b7280;margin-bottom:6px;">Consommation totale N&B</div>
          <div style="font-size:2rem;font-weight:700;color:#111827;"><?= number_format($consoTotalBW, 0, ',', ' ') ?></div>
          <div style="font-size:0.75rem;color:#9ca3af;">pages depuis le <?= $premierReleve ? date('d/m/Y', strtotime($premierReleve['Timestamp'])) : '—' ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;text-align:center;">
          <div style="font-size:0.8rem;color:#6b7280;margin-bottom:6px;">Consommation totale Couleur</div>
          <div style="font-size:2rem;font-weight:700;color:#2563eb;"><?= number_format($consoTotalColor, 0, ',', ' ') ?></div>
          <div style="font-size:0.75rem;color:#9ca3af;">pages depuis le <?= $premierReleve ? date('d/m/Y', strtotime($premierReleve['Timestamp'])) : '—' ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;text-align:center;">
          <div style="font-size:0.8rem;color:#6b7280;margin-bottom:6px;">Ce mois-ci N&B</div>
          <div style="font-size:2rem;font-weight:700;color:#059669;"><?= number_format($consoMoisBW, 0, ',', ' ') ?></div>
          <div style="font-size:0.75rem;color:#9ca3af;"><?= date('F Y') ?></div>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;text-align:center;">
          <div style="font-size:0.8rem;color:#6b7280;margin-bottom:6px;">Ce mois-ci Couleur</div>
          <div style="font-size:2rem;font-weight:700;color:#7c3aed;"><?= number_format($consoMoisColor, 0, ',', ' ') ?></div>
          <div style="font-size:0.75rem;color:#9ca3af;"><?= date('F Y') ?></div>
        </div>
      </div>

      <div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-bottom:24px;">
        <h3 style="margin:0 0 16px;font-size:1rem;color:#111827;">Historique des relevés (30 derniers)</h3>
        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
          <button onclick="filtrerPeriode('7j')" class="btn-periode" data-periode="7j" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;">7 jours</button>
          <button onclick="filtrerPeriode('30j')" class="btn-periode" data-periode="30j" style="padding:6px 14px;border:1px solid #2563eb;border-radius:6px;background:#2563eb;color:#fff;cursor:pointer;font-size:0.85rem;">30 jours</button>
          <button onclick="filtrerPeriode('mois')" class="btn-periode" data-periode="mois" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;">Ce mois</button>
          <button onclick="filtrerPeriode('tout')" class="btn-periode" data-periode="tout" style="padding:6px 14px;border:1px solid #d1d5db;border-radius:6px;background:#fff;cursor:pointer;font-size:0.85rem;">Tout</button>
        </div>
        <div style="overflow-x:auto;">
          <table id="historique-table" style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
              <tr style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                <th style="padding:10px 12px;text-align:left;color:#6b7280;font-weight:600;">Date / Heure</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Delta N&B</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Delta Couleur</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Conso totale N&B</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Conso totale Couleur</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Compteur brut N&B</th>
                <th style="padding:10px 12px;text-align:right;color:#6b7280;font-weight:600;">Compteur brut Couleur</th>
                <th style="padding:10px 12px;text-align:center;color:#6b7280;font-weight:600;">Toners</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($historiqueReleves)): ?>
                <tr><td colspan="8" style="padding:20px;text-align:center;color:#9ca3af;">Aucun relevé disponible</td></tr>
              <?php else: ?>
                <?php foreach ($historiqueReleves as $i => $releve): ?>
                  <tr style="border-bottom:1px solid #f3f4f6;<?= $i === 0 ? 'background:#f0fdf4;' : '' ?>">
                    <td style="padding:10px 12px;color:#374151;font-weight:<?= $i===0?'600':'400' ?>;">
                      <?= date('d/m/Y H:i', strtotime((string)$releve['Timestamp'])) ?>
                      <?php if ($i === 0): ?>
                        <span style="background:#059669;color:#fff;font-size:0.7rem;padding:2px 6px;border-radius:4px;margin-left:6px;">Dernier</span>
                      <?php endif; ?>
                    </td>
                    <td style="padding:10px 12px;text-align:right;color:<?= ((int)$releve['delta_bw'] > 0) ? '#111827' : '#9ca3af' ?>;">
                      <?= ((int)$releve['delta_bw'] > 0) ? '+' . number_format((int)$releve['delta_bw'], 0, ',', ' ') : '—' ?>
                    </td>
                    <td style="padding:10px 12px;text-align:right;color:<?= ((int)$releve['delta_color'] > 0) ? '#2563eb' : '#9ca3af' ?>;">
                      <?= ((int)$releve['delta_color'] > 0) ? '+' . number_format((int)$releve['delta_color'], 0, ',', ' ') : '—' ?>
                    </td>
                    <td style="padding:10px 12px;text-align:right;color:#111827;"><?= number_format((int)$releve['conso_depuis_debut_bw'], 0, ',', ' ') ?></td>
                    <td style="padding:10px 12px;text-align:right;color:#2563eb;"><?= number_format((int)$releve['conso_depuis_debut_color'], 0, ',', ' ') ?></td>
                    <td style="padding:10px 12px;text-align:right;color:#9ca3af;font-size:0.78rem;"><?= number_format((int)($releve['TotalBW'] ?? 0), 0, ',', ' ') ?></td>
                    <td style="padding:10px 12px;text-align:right;color:#9ca3af;font-size:0.78rem;"><?= number_format((int)($releve['TotalColor'] ?? 0), 0, ',', ' ') ?></td>
                    <td style="padding:10px 12px;text-align:center;">
                      <?php
                        $tb = (int)($releve['TonerBlack'] ?? 0);
                        $tc = (int)($releve['TonerCyan'] ?? 0);
                        $tm = (int)($releve['TonerMagenta'] ?? 0);
                        $ty = (int)($releve['TonerYellow'] ?? 0);

                        $tonerColor = function($v) {
                            if ($v <= 10) return '#ef4444';
                            if ($v <= 25) return '#f59e0b';
                            return '#22c55e';
                        };

                        $toners = [
                            ['label' => 'N', 'val' => $tb, 'bg' => $tonerColor($tb)],
                            ['label' => 'C', 'val' => $tc, 'bg' => '#0ea5e9'],
                            ['label' => 'M', 'val' => $tm, 'bg' => '#ec4899'],
                            ['label' => 'J', 'val' => $ty, 'bg' => '#eab308'],
                        ];
                      ?>
                      <?php foreach ($toners as $t): ?>
                        <?php if ($t['val'] <= 0) continue; ?>
                        <?php $bg = ($t['label'] === 'N') ? $tonerColor($tb) : $t['bg']; ?>
                        <?php $textColor = ($t['label'] === 'J') ? '#111827' : '#fff'; ?>
                        <span style="display:inline-flex;align-items:center;gap:2px;background:<?= $bg ?>;color:<?= $textColor ?>;font-size:0.7rem;font-weight:600;padding:3px 6px;border-radius:4px;margin:1px;white-space:nowrap;" title="Toner <?= $t['label'] ?> : <?= $t['val'] ?>%">
                          <?= $t['label'] ?> <?= $t['val'] ?>%
                        </span>
                      <?php endforeach; ?>
                      <?php if ($tb <= 0 && $tc <= 0 && $tm <= 0 && $ty <= 0): ?>
                        <span style="color:#9ca3af;font-size:0.78rem;">—</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== Popup d’attribution client ===== -->
  <div id="attachOverlay" class="popup-overlay"></div>
  <div id="attachModal" class="support-popup" style="max-width:640px;">
    <h3 style="margin-top:0;">Attribuer ce photocopieur à un client</h3>

    <form method="post" class="standard-form" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="action" value="attach_client">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="sn"  value="<?= h($snDisplay) ?>">

      <?php
        // 🟢 IMPORTANT : on envoie la MAC issue du paramètre ?mac si présent (pas l'affichage "—")
        $macForForm = $macPrettyFromParam ?: $macDisplay;
      ?>
      <input type="hidden" name="mac" value="<?= h($macForForm) ?>">

      <!-- Recherche instantanée -->
      <div>
        <label for="clientSearch">Rechercher un client</label>
        <input type="text" id="clientSearch" placeholder="Tapez raison sociale, N° client, dirigeant, téléphone…">
        <small class="muted">Filtre instantané — Entrée pour sélectionner le premier résultat.</small>
      </div>

      <div>
        <label for="clientSelect">Résultats</label>
        <select name="id_client" id="clientSelect" size="8" required
                style="width:100%; max-height:280px; overflow:auto;">
          <?php foreach ($clientsList as $c):
            $label = sprintf('%s (%s) — %s %s — %s',
              $c['raison_sociale'],
              $c['numero_client'],
              trim($c['nom_dirigeant'].' '.$c['prenom_dirigeant']),
              '',
              $c['telephone1']
            );
          ?>
            <option value="<?= (int)$c['id'] ?>"
                    data-search="<?= h(strtolower(
                      $c['raison_sociale'].' '.$c['numero_client'].' '.
                      $c['nom_dirigeant'].' '.$c['prenom_dirigeant'].' '.$c['telephone1']
                    )) ?>">
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="noMatch" class="muted" style="display:none; margin-top:.25rem;">Aucun résultat…</div>
      </div>

      <div style="display:flex; gap:.5rem; justify-content:flex-end;">
        <button type="button" id="btnAttachCancel" class="btn">Annuler</button>
        <button type="submit" class="btn btn-primary">Associer</button>
      </div>
    </form>
  </div>

  <script <?= csp_nonce() ?>>
    // [Fonctionnalité consommation]
    function filtrerPeriode(periode) {
      const maintenant = new Date();
      let limiteDate = null;
      if (periode === '7j') limiteDate = new Date(maintenant - 7 * 86400000);
      else if (periode === '30j') limiteDate = new Date(maintenant - 30 * 86400000);
      else if (periode === 'mois') limiteDate = new Date(maintenant.getFullYear(), maintenant.getMonth(), 1);

      document.querySelectorAll('#historique-table tbody tr').forEach(tr => {
        if (!limiteDate || periode === 'tout') { tr.style.display = ''; return; }
        const dateCell = tr.querySelector('td:first-child');
        if (!dateCell) { tr.style.display = ''; return; }
        const parts = dateCell.textContent.trim().match(/(\d+)\/(\d+)\/(\d+)/);
        if (!parts) { tr.style.display = ''; return; }
        const dateReleve = new Date(parts[3], parts[2] - 1, parts[1]);
        tr.style.display = dateReleve >= limiteDate ? '' : 'none';
      });

      document.querySelectorAll('.btn-periode').forEach(b => {
        b.style.background = b.dataset.periode === periode ? '#2563eb' : '#fff';
        b.style.color = b.dataset.periode === periode ? '#fff' : '';
        b.style.borderColor = b.dataset.periode === periode ? '#2563eb' : '#d1d5db';
      });
    }

    (function(){
      const btn   = document.getElementById('btn-espace-client');
      const modal = document.getElementById('attachModal');
      const ovl   = document.getElementById('attachOverlay');
      const cancel= document.getElementById('btnAttachCancel');

      const shouldOpen = <?= json_encode($shouldOpenAttachModal) ?>;

      // Ouvrir le formulaire seulement si pas encore attribué (bouton, pas lien)
      if (btn && btn.tagName.toLowerCase() === 'button') {
        btn.addEventListener('click', openModal);
      }

      function openModal(){ ovl.classList.add('active'); modal.classList.add('active'); document.body.classList.add('modal-open'); }
      function closeModal(){ ovl.classList.remove('active'); modal.classList.remove('active'); document.body.classList.remove('modal-open'); }

      ovl && ovl.addEventListener('click', closeModal);
      cancel && cancel.addEventListener('click', closeModal);

      if (shouldOpen) {
        openModal();
      }

      // Recherche instantanée
      const q = document.getElementById('clientSearch');
      const sel = document.getElementById('clientSelect');
      const noMatch = document.getElementById('noMatch');

      if (q && sel) {
        const opts = Array.from(sel.options);

        function filter() {
          const v = q.value.trim().toLowerCase();
          let shown = 0;
          opts.forEach(o => {
            const ok = !v || (o.dataset.search || o.textContent.toLowerCase()).includes(v);
            o.hidden = !ok;
            if (ok) shown++;
          });

          // Auto-sélection du premier visible
          const firstVisible = opts.find(o => !o.hidden);
          sel.value = firstVisible ? firstVisible.value : '';
          noMatch.style.display = shown ? 'none' : 'block';
        }

        q.addEventListener('input', filter);
        q.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            const first = opts.find(o => !o.hidden);
            if (first) sel.value = first.value;
          }
        });
        q.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); sel.focus(); } });
        sel.addEventListener('keydown', (e) => { if (e.key === 'ArrowUp' && sel.selectedIndex === 0) { e.preventDefault(); q.focus(); } });

        filter();
      }
      filtrerPeriode('30j');
    })();
  </script>

</body>
</html>
