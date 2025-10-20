<?php
// /public/clients.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

/** Helpers **/
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function pctOrDash($v): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    $v = max(0, min(100, (int)$v));
    return (string)$v . '%';
}
function old(string $key, string $default=''): string {
    return htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

/** Génère un numéro client unique : C + 5 chiffres (ex: C12345) **/
function generateClientNumber(PDO $pdo): string {
    $sql = "
        SELECT LPAD(COALESCE(MAX(CAST(SUBSTRING(numero_client, 2) AS UNSIGNED)), 0) + 1, 5, '0') AS next_num
        FROM clients
        WHERE numero_client REGEXP '^C[0-9]{5}$'
    ";
    $next = $pdo->query($sql)->fetchColumn();
    if (!$next) { $next = '00001'; }
    return 'C' . $next;
}

/** Traitement POST (ajout client) **/
$flash = ['type' => null, 'msg' => null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'add_client') {
    $raison_sociale      = trim($_POST['raison_sociale'] ?? '');
    $adresse             = trim($_POST['adresse'] ?? '');
    $code_postal         = trim($_POST['code_postal'] ?? '');
    $ville               = trim($_POST['ville'] ?? '');
    $livraison_identique = isset($_POST['livraison_identique']) ? 1 : 0;
    $adresse_livraison   = trim($_POST['adresse_livraison'] ?? '');
    $nom_dirigeant       = trim($_POST['nom_dirigeant'] ?? '');
    $prenom_dirigeant    = trim($_POST['prenom_dirigeant'] ?? '');
    $telephone1          = trim($_POST['telephone1'] ?? '');
    $telephone2          = trim($_POST['telephone2'] ?? '');
    $email               = trim($_POST['email'] ?? '');
    $siret               = trim($_POST['siret'] ?? '');
    $numero_tva          = trim($_POST['numero_tva'] ?? '');
    $parrain             = trim($_POST['parrain'] ?? '');
    $offre               = in_array(($_POST['offre'] ?? 'packbronze'), ['packbronze','packargent'], true) ? $_POST['offre'] : 'packbronze';

    if ($livraison_identique) $adresse_livraison = $adresse;

    $errors = [];
    if ($raison_sociale === '')   $errors[] = "La raison sociale est obligatoire.";
    if ($adresse === '')          $errors[] = "L'adresse est obligatoire.";
    if ($code_postal === '')      $errors[] = "Le code postal est obligatoire.";
    if ($ville === '')            $errors[] = "La ville est obligatoire.";
    if ($nom_dirigeant === '')    $errors[] = "Le nom du dirigeant est obligatoire.";
    if ($prenom_dirigeant === '') $errors[] = "Le prénom du dirigeant est obligatoire.";
    if ($telephone1 === '')       $errors[] = "Le téléphone est obligatoire.";
    if ($email === '')            $errors[] = "L'email est obligatoire.";
    if ($siret === '')            $errors[] = "Le SIRET est obligatoire.";

    if (empty($errors)) {
        try {
            $numero = generateClientNumber($pdo);
            $stmt = $pdo->prepare("
                INSERT INTO clients
                    (numero_client, raison_sociale, adresse, code_postal, ville,
                     adresse_livraison, livraison_identique, siret, numero_tva,
                     nom_dirigeant, prenom_dirigeant, telephone1, telephone2,
                     email, parrain, offre)
                VALUES
                    (:numero_client, :raison_sociale, :adresse, :code_postal, :ville,
                     :adresse_livraison, :livraison_identique, :siret, :numero_tva,
                     :nom_dirigeant, :prenom_dirigeant, :telephone1, :telephone2,
                     :email, :parrain, :offre)
            ");
            $params = [
                ':numero_client'       => $numero,
                ':raison_sociale'      => $raison_sociale,
                ':adresse'             => $adresse,
                ':code_postal'         => $code_postal,
                ':ville'               => $ville,
                ':adresse_livraison'   => ($adresse_livraison !== '' ? $adresse_livraison : null),
                ':livraison_identique' => $livraison_identique,
                ':siret'               => $siret,
                ':numero_tva'          => ($numero_tva !== '' ? $numero_tva : null),
                ':nom_dirigeant'       => $nom_dirigeant,
                ':prenom_dirigeant'    => $prenom_dirigeant,
                ':telephone1'          => $telephone1,
                ':telephone2'          => ($telephone2 !== '' ? $telephone2 : null),
                ':email'               => $email,
                ':parrain'             => ($parrain !== '' ? $parrain : null),
                ':offre'               => $offre,
            ];
            $stmt->execute($params);
            header('Location: /public/clients.php?added=1');
            exit;
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                try {
                    $numero = generateClientNumber($pdo);
                    $params[':numero_client'] = $numero;
                    $stmt->execute($params);
                    header('Location: /public/clients.php?added=1');
                    exit;
                } catch (PDOException $e2) {
                    error_log('clients.php INSERT retry error: '.$e2->getMessage());
                    $flash = ['type' => 'error', 'msg' => "Erreur SQL (unicité): impossible de créer le client."];
                }
            } else {
                error_log('clients.php INSERT error: '.$e->getMessage());
                $flash = ['type' => 'error', 'msg' => "Erreur SQL: impossible de créer le client."];
            }
        }
    } else {
        $flash = ['type' => 'error', 'msg' => implode('<br>', array_map('htmlspecialchars', $errors))];
    }
}
if (($_GET['added'] ?? '') === '1') {
    $flash = ['type' => 'success', 'msg' => "Client ajouté avec succès."];
}

/** Requête principale (inchangée) **/
$sql = "
WITH v_compteur_last AS (
  SELECT r.*,
         ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
  FROM compteur_relevee r
),
v_last AS (
  SELECT *
  FROM v_compteur_last
  WHERE rn = 1
),
blk_releve AS (
  SELECT
    COALESCE(pc.mac_norm, v.mac_norm)                                 AS mac_norm,
    COALESCE(pc.SerialNumber, v.SerialNumber)                          AS SerialNumber,
    COALESCE(pc.MacAddress,  v.MacAddress)                             AS MacAddress,
    v.Model,
    v.Nom,
    v.`Timestamp`                                                      AS last_ts,
    v.TonerBlack, v.TonerCyan, v.TonerMagenta, v.TonerYellow,
    v.TotalBW, v.TotalColor, v.TotalPages, v.Status,
    c.id                                                               AS client_id,
    c.numero_client,
    c.raison_sociale,
    c.nom_dirigeant,
    c.prenom_dirigeant,
    c.telephone1
  FROM v_last v
  LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = v.mac_norm
  LEFT JOIN clients c               ON c.id = pc.id_client
),
blk_sans_releve AS (
  SELECT
    pc.mac_norm,
    pc.SerialNumber,
    pc.MacAddress,
    NULL AS Model,
    NULL AS Nom,
    NULL AS last_ts,
    NULL AS TonerBlack, NULL AS TonerCyan, NULL AS TonerMagenta, NULL AS TonerYellow,
    NULL AS TotalBW, NULL AS TotalColor, NULL AS TotalPages, NULL AS Status,
    c.id AS client_id,
    c.numero_client,
    c.raison_sociale,
    c.nom_dirigeant,
    c.prenom_dirigeant,
    c.telephone1
  FROM photocopieurs_clients pc
  LEFT JOIN clients c  ON c.id = pc.id_client
  LEFT JOIN v_last v   ON v.mac_norm = pc.mac_norm
  WHERE v.mac_norm IS NULL
)
SELECT *
FROM (
  SELECT * FROM blk_releve
  UNION ALL
  SELECT * FROM blk_sans_releve
) x
ORDER BY
  COALESCE(x.raison_sociale, '— sans client —') ASC,
  COALESCE(x.SerialNumber, x.mac_norm) ASC
";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('clients.php SQL error: ' . $e->getMessage());
    $rows = [];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Clients & Photocopieurs - CCComputer</title>

    <!-- Styles -->
    <link rel="stylesheet" href="/assets/css/main.css" />
    <link rel="stylesheet" href="/assets/css/clients.css" />
</head>
<body class="page-clients">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="page-container">
        <div class="page-header">
            <h2 class="page-title">Photocopieurs par client (dernier relevé)</h2>
        </div>

        <!-- Barre de filtres + bouton Ajouter -->
        <div class="filters-row" style="margin-bottom:1rem; display:flex; gap:0.75rem; align-items:center;">
            <input type="text" id="q" placeholder="Filtrer (client, modèle, SN, MAC)…"
                   style="flex:1; padding:0.55rem 0.75rem; border:1px solid var(--border-color); border-radius: var(--radius-md); background:var(--bg-primary); color:var(--text-primary);">
            <button id="clearQ" class="btn" style="padding:0.55rem 0.9rem; border:1px solid var(--border-color); background:var(--bg-primary); border-radius:var(--radius-md); cursor:pointer;">
                Effacer
            </button>
            <button id="btnAddClient" class="btn btn-primary" style="padding:0.55rem 0.9rem; border:1px solid var(--border-color); background:var(--accent-primary); color:#fff; border-radius:var(--radius-md); cursor:pointer;">
                + Ajouter un client
            </button>
        </div>

        <!-- Flash message -->
        <?php if ($flash['type']): ?>
          <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
            <?= $flash['msg'] ?>
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
                    $raison  = $r['raison_sociale'] ?: '— sans client —';
                    $numero  = $r['numero_client']   ?: '';
                    $dirNom  = trim(($r['nom_dirigeant'] ?? '').' '.($r['prenom_dirigeant'] ?? ''));
                    $tel     = $r['telephone1'] ?: '';
                    $modele  = $r['Model'] ?: '';
                    $sn      = $r['SerialNumber'] ?: '';
                    $mac     = $r['MacAddress'] ?: ($r['mac_norm'] ?? '');
                    $nom     = $r['Nom'] ?: '';
                    $lastTs  = $r['last_ts'] ? date('Y-m-d H:i', strtotime($r['last_ts'])) : '—';
                    $totBW   = is_null($r['TotalBW'])    ? '—' : number_format((int)$r['TotalBW'], 0, ',', ' ');
                    $totCol  = is_null($r['TotalColor']) ? '—' : number_format((int)$r['TotalColor'], 0, ',', ' ');

                    $tk = $r['TonerBlack'];   $tk = is_null($tk) ? null : max(0, min(100, (int)$tk));
                    $tc = $r['TonerCyan'];    $tc = is_null($tc) ? null : max(0, min(100, (int)$tc));
                    $tm = $r['TonerMagenta']; $tm = is_null($tm) ? null : max(0, min(100, (int)$tm));
                    $ty = $r['TonerYellow'];  $ty = is_null($ty) ? null : max(0, min(100, (int)$ty));

                    $searchText = strtolower(
                        ($r['raison_sociale'] ?? '') . ' ' .
                        ($r['numero_client'] ?? '') . ' ' .
                        $dirNom . ' ' . $tel . ' ' .
                        $modele . ' ' . $sn . ' ' . $mac
                    );
                ?>
                    <tr data-search="<?= h($searchText) ?>">
                        <td data-th="Client">
                            <div class="client-cell">
                                <div class="client-raison"><?= h($raison) ?></div>
                                <?php if ($numero): ?>
                                  <div class="client-num"><?= h($numero) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td data-th="Dirigeant"><?= h($dirNom ?: '—') ?></td>
                        <td data-th="Téléphone"><?= h($tel ?: '—') ?></td>

                        <td data-th="Photocopieur">
                            <div class="machine-cell">
                                <div class="machine-line"><strong><?= h($modele ?: '—') ?></strong></div>
                                <div class="machine-sub">
                                    SN: <?= h($sn ?: '—') ?> · MAC: <?= h($mac ?: '—') ?>
                                </div>
                                <?php if ($nom): ?>
                                  <div class="machine-sub">Nom: <?= h($nom) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td data-th="Toners">
                            <div class="toners">
                                <div class="toner t-k" title="Black: <?= pctOrDash($tk) ?>">
                                    <span style="width:<?= ($tk!==null?$tk:0) ?>%"></span>
                                    <em><?= pctOrDash($tk) ?></em>
                                </div>
                                <div class="toner t-c" title="Cyan: <?= pctOrDash($tc) ?>">
                                    <span style="width:<?= ($tc!==null?$tc:0) ?>%"></span>
                                    <em><?= pctOrDash($tc) ?></em>
                                </div>
                                <div class="toner t-m" title="Magenta: <?= pctOrDash($tm) ?>">
                                    <span style="width:<?= ($tm!==null?$tm:0) ?>%"></span>
                                    <em><?= pctOrDash($tm) ?></em>
                                </div>
                                <div class="toner t-y" title="Yellow: <?= pctOrDash($ty) ?>">
                                    <span style="width:<?= ($ty!==null?$ty:0) ?>%"></span>
                                    <em><?= pctOrDash($ty) ?></em>
                                </div>
                            </div>
                        </td>

                        <td class="td-metric" data-th="Total BW"><?= h($totBW) ?></td>
                        <td class="td-metric" data-th="Total Color"><?= h($totCol) ?></td>
                        <td class="td-date" data-th="Dernier relevé"><?= h($lastTs) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$rows): ?>
                <div style="padding:1rem; color:var(--text-secondary);">Aucune donnée à afficher.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ===== Popup "Ajouter un client" (overlay + fenêtre) ===== -->
    <div id="clientModalOverlay" class="popup-overlay" aria-hidden="true"></div>

    <div id="clientModal" class="support-popup" role="dialog" aria-modal="true" aria-labelledby="clientModalTitle" style="display:none;">
      <div class="modal-header">
        <h3 id="clientModalTitle">Ajouter un client</h3>
        <button type="button" id="btnCloseModal" class="icon-btn icon-btn--close" aria-label="Fermer">
          <span aria-hidden="true">×</span>
        </button>
      </div>

      <?php if ($flash['type'] && $flash['type']!=='success' && ($_POST['action'] ?? '')==='add_client'): ?>
        <div class="flash <?= $flash['type']==='success' ? 'flash-success' : 'flash-error' ?>" style="margin-bottom:0.75rem;">
          <?= $flash['msg'] ?>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>" class="standard-form modal-form" novalidate>
        <input type="hidden" name="action" value="add_client">

        <div class="form-grid-2">
          <div class="card-like">
            <div class="subsection-title">Informations société</div>

            <label>Raison sociale* </label>
            <input type="text" name="raison_sociale" value="<?= old('raison_sociale') ?>" required>

            <label>Adresse* </label>
            <input type="text" name="adresse" value="<?= old('adresse') ?>" required>

            <div class="grid-two">
              <div>
                <label>Code postal*</label>
                <input type="text" name="code_postal" value="<?= old('code_postal') ?>" required>
              </div>
              <div>
                <label>Ville*</label>
                <input type="text" name="ville" value="<?= old('ville') ?>" required>
              </div>
            </div>

            <div class="grid-two">
              <div>
                <label>SIRET*</label>
                <input type="text" name="siret" value="<?= old('siret') ?>" required>
              </div>
              <div>
                <label>Numéro TVA</label>
                <input type="text" name="numero_tva" value="<?= old('numero_tva') ?>">
              </div>
            </div>

            <!-- Livraison: case stylée sur une ligne, adresse juste en dessous -->
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
              <div>
                <label>Nom dirigeant*</label>
                <input type="text" name="nom_dirigeant" value="<?= old('nom_dirigeant') ?>" required>
              </div>
              <div>
                <label>Prénom dirigeant*</label>
                <input type="text" name="prenom_dirigeant" value="<?= old('prenom_dirigeant') ?>" required>
              </div>
            </div>

            <div class="grid-two">
              <div>
                <label>Téléphone*</label>
                <input type="text" name="telephone1" value="<?= old('telephone1') ?>" required>
              </div>
              <div>
                <label>Téléphone 2</label>
                <input type="text" name="telephone2" value="<?= old('telephone2') ?>">
              </div>
            </div>

            <label>Email*</label>
            <input type="email" name="email" value="<?= old('email') ?>" required>

            <div class="grid-two">
              <div>
                <label>Parrain</label>
                <input type="text" name="parrain" value="<?= old('parrain') ?>">
              </div>
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

    <!-- Ouverture auto si erreurs validation -->
    <script>
      window.__CLIENT_MODAL_INIT_OPEN__ = <?= json_encode(($flash['type']==='error' && ($_POST['action'] ?? '')==='add_client') ? true : false) ?>;
    </script>

    <!-- Gestion popup: centrer, bloquer scroll page, retirer barres -->
    <script>
      (function(){
        const overlay = document.getElementById('clientModalOverlay');
        const modal   = document.getElementById('clientModal');
        const openBtn = document.getElementById('btnAddClient');
        const closeBtn = document.getElementById('btnCloseModal');

        function openModal(){
          document.body.classList.add('modal-open');
          overlay.setAttribute('aria-hidden','false');
          overlay.style.display = 'block';
          modal.style.display = 'block';
        }
        function closeModal(){
          document.body.classList.remove('modal-open');
          overlay.setAttribute('aria-hidden','true');
          overlay.style.display = 'none';
          modal.style.display = 'none';
        }

        openBtn && openBtn.addEventListener('click', openModal);
        closeBtn && closeBtn.addEventListener('click', closeModal);
        overlay && overlay.addEventListener('click', closeModal);

        // Ouvre auto si erreurs validation
        if (window.__CLIENT_MODAL_INIT_OPEN__) openModal();
      })();
    </script>

    <!-- Sync adresse de livraison quand "identique" est coché (readOnly pour poster la valeur) -->
    <script>
      (function(){
        const cb      = document.getElementById('livraison_identique');
        const adr     = document.querySelector('input[name="adresse"]');
        const adrLiv  = document.getElementById('adresse_livraison');
        if (!cb || !adr || !adrLiv) return;

        function syncLivraison() {
          if (cb.checked) {
            adrLiv.value = adr.value;
            adrLiv.readOnly = true;
            adrLiv.classList.add('is-disabled');
          } else {
            adrLiv.readOnly = false;
            adrLiv.classList.remove('is-disabled');
          }
        }

        adr.addEventListener('input', () => { if (cb.checked) adrLiv.value = adr.value; });
        cb.addEventListener('change', syncLivraison);
        syncLivraison();
      })();
    </script>
</body>
</html>
