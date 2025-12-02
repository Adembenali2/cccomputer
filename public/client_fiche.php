<?php
// /public/client_fiche.php
require_once __DIR__ . '/../includes/auth_role.php';        // démarre la session via auth.php
authorize_page('client_fiche', ['Admin', 'Dirigeant']); // Utilise les valeurs exactes de la base de données (ENUM)   
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

const ALLOWED_DEPOT_CF  = ['espece','cheque','virement','paiement_carte'];
const ALLOWED_OFFRES_CF = ['packbronze','packargent'];
const ALLOWED_UPLOAD_EXT = ['pdf','jpg','jpeg','png'];

/**
 * Valide la signature d'un fichier (magic bytes) pour détecter les fichiers malveillants renommés
 * 
 * @param string $filepath Chemin du fichier temporaire
 * @param string $expectedType Extension attendue (pdf, jpg, png)
 * @return bool true si la signature correspond, false sinon
 */
function validateFileSignature(string $filepath, string $expectedType): bool {
    if (!file_exists($filepath) || !is_readable($filepath)) {
        return false;
    }
    
    $handle = @fopen($filepath, 'rb');
    if (!$handle) {
        return false;
    }
    
    $bytes = @fread($handle, 4);
    @fclose($handle);
    
    if ($bytes === false || strlen($bytes) < 4) {
        return false;
    }
    
    $signatures = [
        'pdf' => ["%PDF"],
        'jpg' => ["\xFF\xD8\xFF"],
        'jpeg' => ["\xFF\xD8\xFF"],
        'png' => ["\x89\x50\x4E\x47"]
    ];
    
    if (!isset($signatures[$expectedType])) {
        return false;
    }
    
    return strpos($bytes, $signatures[$expectedType][0]) === 0;
}

// Les fonctions h(), ensureCsrfToken(), assertValidCsrf(), validatePhone(), validatePostalCode(), validateSiret() sont définies dans includes/helpers.php
function v(?string $k, $default='') { return $_POST[$k] ?? $default; }

// --------- Récup param ----------
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><p>ID client manquant.</p>";
  exit;
}

// --------- Helpers upload ----------
function uploads_base_path(): string {
  $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
  if ($docRoot !== '' && is_dir($docRoot)) {
    return $docRoot . '/uploads/clients';
  }
  return dirname(__DIR__) . '/uploads/clients';
}

function ensure_upload_dir(int $id): string {
  $base = uploads_base_path() . '/' . (int)$id; // Sécuriser l'ID
  if (!is_dir($base)) {
    @mkdir($base, 0755, true); // Permissions plus restrictives (0755 au lieu de 0775)
  }
  return $base;
}
function safe_filename(string $name): string {
  $name = preg_replace('/[^\w.\-]+/u', '_', $name);
  $name = trim($name, '._ ');
  if ($name === '') $name = 'file';
  return $name;
}
function allowed_ext(string $filename): bool {
  $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
  return in_array($ext, ALLOWED_UPLOAD_EXT, true);
}

function logClientAction(PDO $pdo, int $clientId, string $details): void {
  try {
    enregistrerAction($pdo, $_SESSION['user_id'] ?? null, 'client_mis_a_jour', "Client #{$clientId} : {$details}");
  } catch (Throwable $e) {
    error_log('client_fiche log error: '.$e->getMessage());
  }
}
function store_upload(array $file, int $id): ?string {
  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
  if (!is_uploaded_file($file['tmp_name'])) return null;
  if (!allowed_ext($file['name'])) return null;
  
  // Vérification de la taille (max 10MB)
  $maxSize = 10 * 1024 * 1024; // 10MB
  if (($file['size'] ?? 0) > $maxSize) return null;
  
  // Vérification du type MIME basique (sécurité supplémentaire)
  $finfo = finfo_open(FILEINFO_MIME_TYPE);
  $mimeType = finfo_file($finfo, $file['tmp_name']);
  finfo_close($finfo);
  $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
  if (!in_array($mimeType, $allowedMimes, true)) return null;
  
  // Vérification de la signature du fichier (magic bytes) pour plus de sécurité
  $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
  if (!validateFileSignature($file['tmp_name'], $ext)) return null;
  
  $dir = ensure_upload_dir($id);
  $base = date('Ymd_His').'_'.safe_filename($file['name']);
  $destAbs = $dir.'/'.$base;
  if (!move_uploaded_file($file['tmp_name'], $destAbs)) return null;
  
  // Sécuriser les permissions du fichier
  @chmod($destAbs, 0644);
  
  // chemin relatif web
  $rel = '/uploads/clients/'.$id.'/'.$base;
  return $rel;
}

// --------- Charger client ----------
try {
  // Sélection explicite des colonnes nécessaires au lieu de SELECT * pour améliorer les performances
  $stmt = $pdo->prepare("SELECT id, numero_client, raison_sociale, adresse, code_postal, ville, 
                         nom_dirigeant, prenom_dirigeant, telephone1, telephone2, email, siret, 
                         numero_tva, depot_mode, parrain, offre, date_creation, date_dajout,
                         adresse_livraison, livraison_identique, pdf1, pdf2, pdf3, pdf4, pdf5, pdfcontrat, iban
                         FROM clients WHERE id = :id LIMIT 1");
  $stmt->execute([':id'=>$id]);
  $client = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('client_fiche SELECT error: '.$e->getMessage());
  $client = null;
}
if (!$client) {
  http_response_code(404);
  echo "<!doctype html><meta charset='utf-8'><p>Client introuvable.</p>";
  exit;
}

// --------- Enregistrer ----------
$flash = ['type'=>null,'msg'=>null];
$csrfToken = ensureCsrfToken();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'save_client') {
  try {
    assertValidCsrf($_POST['csrf_token'] ?? '');
  } catch (RuntimeException $csrfEx) {
    $flash = ['type'=>'error','msg'=>$csrfEx->getMessage()];
  }
  // Champs
  $data = [
    'numero_client'     => trim(v('numero_client', $client['numero_client'])),
    'raison_sociale'    => trim(v('raison_sociale', $client['raison_sociale'])),
    'adresse'           => trim(v('adresse', $client['adresse'])),
    'code_postal'       => trim(v('code_postal', $client['code_postal'])),
    'ville'             => trim(v('ville', $client['ville'])),
    'adresse_livraison' => trim(v('adresse_livraison', $client['adresse_livraison'])),
    'livraison_identique'=> isset($_POST['livraison_identique']) ? 1 : 0,
    'siret'             => trim(v('siret', $client['siret'])),
    'numero_tva'        => trim(v('numero_tva', $client['numero_tva'])),
    'depot_mode'        => in_array(v('depot_mode', $client['depot_mode']), ['espece','cheque','virement','paiement_carte'], true) ? v('depot_mode', $client['depot_mode']) : $client['depot_mode'],
    'nom_dirigeant'     => trim(v('nom_dirigeant', $client['nom_dirigeant'])),
    'prenom_dirigeant'  => trim(v('prenom_dirigeant', $client['prenom_dirigeant'])),
    'telephone1'        => trim(v('telephone1', $client['telephone1'])),
    'telephone2'        => trim(v('telephone2', $client['telephone2'])),
    'email'             => trim(v('email', $client['email'])),
    'parrain'           => trim(v('parrain', $client['parrain'])),
    'offre'             => in_array(v('offre', $client['offre']), ['packbronze','packargent'], true) ? v('offre', $client['offre']) : $client['offre'],
    'iban'              => trim(v('iban', $client['iban'])),
  ];
  if ($data['livraison_identique']) {
    $data['adresse_livraison'] = $data['adresse']; // synchro
  }

  // Validation minimale
  $errors = [];
  foreach (['numero_client','raison_sociale','adresse','code_postal','ville','siret','telephone1','email'] as $req) {
    if ($data[$req] === '') $errors[] = "Le champ « $req » est obligatoire.";
  }
  if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = "Email invalide.";
  }
  if (!validatePhone($data['telephone1'])) {
    $errors[] = "Téléphone principal invalide.";
  }
  if ($data['telephone2'] && !validatePhone($data['telephone2'])) {
    $errors[] = "Téléphone secondaire invalide.";
  }
  if (!validatePostalCode($data['code_postal'])) {
    $errors[] = "Code postal invalide.";
  }
  if (!validateSiret($data['siret'])) {
    $errors[] = "Le SIRET doit contenir 14 chiffres.";
  }

  // Gestion fichiers: pdf1..pdf5,pdfcontrat (replace ou suppression)
  $fileCols = ['pdf1','pdf2','pdf3','pdf4','pdf5','pdfcontrat'];
  $fileUpdates = [];
  foreach ($fileCols as $col) {
    // suppression ?
    if (isset($_POST['delete_'.$col]) && $_POST['delete_'.$col] === '1') {
      $fileUpdates[$col] = null;
    }
    // upload ?
    if (!empty($_FILES[$col]['name'])) {
      $stored = store_upload($_FILES[$col], $id);
      if ($stored) {
        $fileUpdates[$col] = $stored;
      } else {
        $errors[] = "Fichier $col invalide (seuls PDF/JPG/PNG).";
      }
    }
  }

  if (empty($errors)) {
    // Whitelist des colonnes autorisées pour sécuriser l'UPDATE dynamique
    $allowedColumns = [
      'numero_client', 'raison_sociale', 'adresse', 'code_postal', 'ville',
      'adresse_livraison', 'livraison_identique', 'siret', 'numero_tva',
      'depot_mode', 'nom_dirigeant', 'prenom_dirigeant', 'telephone1',
      'telephone2', 'email', 'parrain', 'offre', 'iban'
    ];
    $allowedFileColumns = ['pdf1', 'pdf2', 'pdf3', 'pdf4', 'pdf5', 'pdfcontrat'];
    
    // construire SQL dynamique avec validation des colonnes
    $set = [];
    $params = [':id'=>$id];
    foreach ($data as $k=>$v) {
      // Vérifier que la colonne est autorisée avant de l'ajouter
      if (!in_array($k, $allowedColumns, true)) {
        continue; // Ignorer les colonnes non autorisées
      }
      $set[] = "`$k` = :$k";
      $params[":$k"] = ($v === '' ? null : $v);
    }
    foreach ($fileUpdates as $k=>$v) {
      // Vérifier que la colonne de fichier est autorisée
      if (!in_array($k, $allowedFileColumns, true)) {
        continue; // Ignorer les colonnes non autorisées
      }
      $set[] = "`$k` = :$k";
      $params[":$k"] = $v; // peut être null
    }
    $sql = "UPDATE clients SET ".implode(', ', $set)." WHERE id = :id";
    try {
      $pdo->prepare($sql)->execute($params);
      logClientAction($pdo, $id, "Fiche mise à jour");
      // PRG
      header("Location: /public/client_fiche.php?id=".$id."&saved=1");
      exit;
    } catch (PDOException $e) {
      error_log('client_fiche UPDATE error: '.$e->getMessage());
      $flash = ['type'=>'error','msg'=>"Erreur SQL: impossible d'enregistrer."];
    }
  } else {
    $flash = ['type'=>'error','msg'=>implode('<br>', array_map('h', $errors))];
    // réinjecter $client avec les valeurs postées pour garder le formulaire rempli
    $client = array_merge($client, $data, $fileUpdates);
  }
}
if (($_GET['saved'] ?? '') === '1') {
  $flash = ['type'=>'success','msg'=>"Client enregistré avec succès."];
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Fiche client — <?= h($client['raison_sociale']) ?></title>

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/client_fiche.css" />
</head>
<body class="page-client-fiche">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

  <div class="page-container">

    <div class="toolbar">
      <a href="/public/clients.php" class="back-link">← Retour</a>
      <div class="toolbar-right">
        <span class="badge">N° client : <?= h($client['numero_client']) ?></span>
        <span class="badge">Offre : <?= h($client['offre']) ?></span>
        <span class="badge">Créé le : <?= h(date('Y-m-d', strtotime($client['date_creation'] ?? $client['date_dajout'] ?? 'now'))) ?></span>
      </div>
    </div>

    <div class="details-header">
      <div>
        <div class="h1">Fiche client</div>
        <p class="sub">Mettez à jour les informations administratives, contacts et justificatifs.</p>
      </div>
      <div class="meta-grid">
        <div class="meta-block">
          <span class="label">Raison sociale</span>
          <strong><?= h($client['raison_sociale']) ?></strong>
          <span class="sub">Ville : <?= h($client['ville']) ?></span>
        </div>
        <div class="meta-block">
          <span class="label">Téléphone</span>
          <strong><?= h($client['telephone1']) ?></strong>
          <span class="sub"><?= h($client['telephone2'] ?: '—') ?></span>
        </div>
        <div class="meta-block">
          <span class="label">Email</span>
          <strong><?= h($client['email']) ?></strong>
          <span class="sub">Parrain : <?= h($client['parrain'] ?: '—') ?></span>
        </div>
        <div class="meta-block">
          <span class="label">Mode de dépôt</span>
          <strong><?= h($client['depot_mode'] ?: '—') ?></strong>
          <span class="sub">Offre : <?= h($client['offre'] ?: '—') ?></span>
        </div>
      </div>
    </div>

    <?php if ($flash['type']): ?>
      <div class="flash <?= $flash['type']==='error'?'flash-error':'flash-success' ?>" style="margin-bottom:.75rem;">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <form class="standard-form client-form" method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" enctype="multipart/form-data" novalidate>
      <input type="hidden" name="action" value="save_client" />
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>" />

      <!-- Informations société -->
      <div class="section-card">
        <div class="section-title">Informations société</div>
        <div class="grid two">
          <div>
            <label>N° client*</label>
            <input type="text" name="numero_client" value="<?= h($client['numero_client']) ?>" required />
          </div>
          <div>
            <label>Raison sociale*</label>
            <input type="text" name="raison_sociale" value="<?= h($client['raison_sociale']) ?>" required />
          </div>
        </div>

        <label>Adresse*</label>
        <input type="text" name="adresse" value="<?= h($client['adresse']) ?>" required />

        <div class="grid two">
          <div>
            <label>Code postal*</label>
            <input type="text" name="code_postal" value="<?= h($client['code_postal']) ?>" required />
          </div>
          <div>
            <label>Ville*</label>
            <input type="text" name="ville" value="<?= h($client['ville']) ?>" required />
          </div>
        </div>

        <div class="livraison-row">
          <label class="checkbox-inline">
            <input type="checkbox" name="livraison_identique" id="livraison_identique" <?= ($client['livraison_identique'] ?? 0) ? 'checked' : '' ?> />
            Adresse de livraison identique
          </label>
        </div>

        <label for="adresse_livraison" class="adresse-livraison-label">Adresse de livraison</label>
        <input type="text" name="adresse_livraison" id="adresse_livraison" value="<?= h($client['adresse_livraison']) ?>" placeholder="Laisser vide si identique" />
      </div>

      <!-- Administration -->
      <div class="section-card">
        <div class="section-title">Administration</div>
        <div class="grid two">
          <div>
            <label>SIRET*</label>
            <input type="text" name="siret" value="<?= h($client['siret']) ?>" required />
          </div>
          <div>
            <label>Numéro TVA</label>
            <input type="text" name="numero_tva" value="<?= h($client['numero_tva']) ?>" />
          </div>
        </div>

        <div class="grid two">
          <div>
            <label>Mode de dépôt</label>
            <select name="depot_mode">
              <?php
                $modes = ['espece'=>'Espèces','cheque'=>'Chèque','virement'=>'Virement','paiement_carte'=>'Carte'];
                foreach ($modes as $val=>$lbl) {
                  $sel = ($client['depot_mode']===$val)?'selected':'';
                  echo "<option value='".h($val)."' $sel>".h($lbl)."</option>";
                }
              ?>
            </select>
          </div>
          <div>
            <label>Offre</label>
            <select name="offre">
              <option value="packbronze" <?= ($client['offre']==='packbronze'?'selected':'') ?>>Pack Bronze</option>
              <option value="packargent" <?= ($client['offre']==='packargent'?'selected':'') ?>>Pack Argent</option>
            </select>
          </div>
        </div>

        <div>
          <label>IBAN</label>
          <input type="text" name="iban" value="<?= h($client['iban']) ?>" />
        </div>
      </div>

      <!-- Contacts -->
      <div class="section-card">
        <div class="section-title">Contacts</div>
        <div class="grid two">
          <div>
            <label>Nom dirigeant</label>
            <input type="text" name="nom_dirigeant" value="<?= h($client['nom_dirigeant']) ?>" />
          </div>
          <div>
            <label>Prénom dirigeant</label>
            <input type="text" name="prenom_dirigeant" value="<?= h($client['prenom_dirigeant']) ?>" />
          </div>
        </div>

        <div class="grid two">
          <div>
            <label>Téléphone*</label>
            <input type="text" name="telephone1" value="<?= h($client['telephone1']) ?>" required />
          </div>
          <div>
            <label>Téléphone 2</label>
            <input type="text" name="telephone2" value="<?= h($client['telephone2']) ?>" />
          </div>
        </div>

        <div class="grid two">
          <div>
            <label>Email*</label>
            <input type="email" name="email" value="<?= h($client['email']) ?>" required />
          </div>
          <div>
            <label>Parrain</label>
            <input type="text" name="parrain" value="<?= h($client['parrain']) ?>" />
          </div>
        </div>
      </div>

      <!-- Justificatifs -->
      <div class="section-card">
        <div class="section-title">Justificatifs (PDF/JPG/PNG)</div>

        <div class="files-grid">
          <?php
            $files = ['pdf1'=>'Justificatif 1','pdf2'=>'Justificatif 2','pdf3'=>'Justificatif 3','pdf4'=>'Justificatif 4','pdf5'=>'Justificatif 5','pdfcontrat'=>'Contrat'];
            foreach ($files as $col=>$label):
              $cur = $client[$col] ?? null;
          ?>
          <div class="file-field">
            <div class="file-label"><?= h($label) ?></div>

            <?php if ($cur): ?>
              <div class="file-pill">
                <a href="<?= h($cur) ?>" target="_blank" rel="noopener">Voir le fichier</a>
                <label class="pill-right">
                  <input type="checkbox" name="delete_<?= h($col) ?>" value="1"> Supprimer
                </label>
              </div>
            <?php else: ?>
              <div class="file-pill is-empty">Aucun fichier</div>
            <?php endif; ?>

            <input type="file" name="<?= h($col) ?>" accept=".pdf,.jpg,.jpeg,.png" />
            <small class="muted">Taille conseillée &lt; 10 Mo</small>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="form-actions">
        <button type="submit" class="fiche-action-btn">Enregistrer</button>
      </div>
    </form>
  </div>

  <script>
    // Sync adresse livraison si "identique"
    (function(){
      const cb = document.getElementById('livraison_identique');
      const adr = document.querySelector('input[name="adresse"]');
      const adrLiv = document.getElementById('adresse_livraison');
      if (!cb || !adr || !adrLiv) return;
      function sync() {
        if (cb.checked) {
          adrLiv.value = adr.value;
          adrLiv.readOnly = true;
          adrLiv.classList.add('is-disabled');
        } else {
          adrLiv.readOnly = false;
          adrLiv.classList.remove('is-disabled');
        }
      }
      adr.addEventListener('input', ()=>{ if (cb.checked) adrLiv.value = adr.value; });
      cb.addEventListener('change', sync);
      sync();
    })();
  </script>
</body>
</html>
