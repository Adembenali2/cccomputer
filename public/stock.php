<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrfToken = $_SESSION['csrf_token'] ?? '';
$pdo = getPdo();
$articles = $pdo->query("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$totalArticles = count($articles);
$valeurStock = array_sum(array_map(static fn($a) => ((float)$a['quantite'] * (float)$a['prix_unitaire_ht']), $articles));
$enAlerte = count(array_filter($articles, static fn($a) => ((int)$a['quantite'] > 0 && (int)$a['quantite'] <= (int)$a['quantite_min'])));
$enRupture = count(array_filter($articles, static fn($a) => (int)$a['quantite'] === 0));

function badgeCategorie(string $cat): string {
  $cfg = [
    'papier' => ['Papier', '#dbeafe', '#1d4ed8'],
    'toner_noir' => ['Toner Noir', '#1f2937', '#fff'],
    'toner_cyan' => ['Toner Cyan', '#cffafe', '#0e7490'],
    'toner_magenta' => ['Toner Magenta', '#fce7f3', '#9d174d'],
    'toner_jaune' => ['Toner Jaune', '#fef9c3', '#854d0e'],
    'pc' => ['PC', '#ede9fe', '#5b21b6'],
    'ecran_lcd' => ['Écran LCD', '#f3e8ff', '#7e22ce'],
    'imprimante' => ['Imprimante', '#dcfce7', '#166534'],
  ];
  [$label,$bg,$color] = $cfg[$cat] ?? [ucfirst($cat), '#f3f4f6', '#374151'];
  return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;\">{$label}</span>";
}
function badgeEtat(string $etat): string {
  $cfg = ['neuf'=>['Neuf','#dcfce7','#166534'],'bon'=>['Bon','#dbeafe','#1d4ed8'],'use'=>['Usé','#ffedd5','#9a3412'],'hs'=>['HS','#fee2e2','#991b1b']];
  [$label,$bg,$color] = $cfg[$etat] ?? [ucfirst($etat), '#f3f4f6', '#374151'];
  return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;\">{$label}</span>";
}
function detailsTechniques(array $a): string {
  $parts = [];
  switch ((string)$a['categorie']) {
    case 'pc':
      if (!empty($a['cpu'])) $parts[] = htmlspecialchars((string)$a['cpu']);
      if (!empty($a['ram'])) $parts[] = htmlspecialchars((string)$a['ram']) . ' RAM';
      if (!empty($a['stockage'])) $parts[] = htmlspecialchars((string)$a['stockage']);
      break;
    case 'ecran_lcd':
      if (!empty($a['taille_ecran'])) $parts[] = htmlspecialchars((string)$a['taille_ecran']);
      if (!empty($a['resolution'])) $parts[] = htmlspecialchars((string)$a['resolution']);
      break;
    case 'imprimante':
      if (!empty($a['numero_serie'])) $parts[] = 'SN: ' . htmlspecialchars(substr((string)$a['numero_serie'], 0, 12));
      if (!empty($a['modele'])) $parts[] = htmlspecialchars((string)$a['modele']);
      break;
    case 'toner_noir': case 'toner_cyan': case 'toner_magenta': case 'toner_jaune':
      if (!empty($a['modele'])) $parts[] = htmlspecialchars((string)$a['modele']);
      if (!empty($a['rendement_pages'])) $parts[] = number_format((float)$a['rendement_pages'], 0, ',', ' ') . ' pages';
      break;
    case 'papier':
      if (!empty($a['format_papier'])) $parts[] = htmlspecialchars((string)$a['format_papier']);
      if (!empty($a['grammage'])) $parts[] = htmlspecialchars((string)$a['grammage']);
      break;
  }
  return $parts ? implode(' · ', $parts) : '<span style="color:#d1d5db;">—</span>';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestion du Stock</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body{background:#f8f9fb}.wrap{padding:20px}.f-input,.f-select{border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px}
    .action-menu a:hover{background:#f9fafb}
    .toast-kf{animation:slideIn .3s ease}@keyframes slideIn{from{transform:translateX(20px);opacity:0}to{transform:translateX(0);opacity:1}}
    .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:1000;display:none;align-items:center;justify-content:center}
    .modal-box{background:#fff;border-radius:16px;width:600px;max-height:90vh;overflow-y:auto;padding:32px}
    .scanline{position:absolute;left:0;right:0;height:2px;background:#22c55e;animation:scan 2s linear infinite}@keyframes scan{0%{top:0}100%{top:298px}}
  </style>
</head>
<body data-csrf-token="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <?php if ($flash): ?><div style="margin-bottom:12px;background:#ecfeff;color:#155e75;padding:10px 12px;border-radius:10px;"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div style="margin-bottom:28px;">
    <h1 style="font-size:26px;font-weight:700;color:#111827;margin:0 0 4px;">Gestion du Stock</h1>
    <p style="color:#6b7280;font-size:14px;margin:0;"><?= $totalArticles ?> article<?= $totalArticles > 1 ? 's' : '' ?> en inventaire</p>
  </div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #6366f1;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Total articles</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $totalArticles ?></div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #10b981;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Valeur stock HT</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= number_format($valeurStock, 2, ',', ' ') ?> €</div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #f59e0b;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">En alerte</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $enAlerte ?></div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #ef4444;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">En rupture</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $enRupture ?></div></div>
  </div>
  <div style="background:#fff;border-radius:12px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap">
    <button type="button" id="btnAddArticle" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer">+ Ajouter article</button>
    <input id="recherche" class="f-input" placeholder="Rechercher (réf, désignation, SN...)" style="width:240px">
    <select id="filtreCategorie" class="f-select"><option value="">Toutes catégories</option><option value="papier">Papier</option><option value="toner_noir">Toner Noir</option><option value="toner_cyan">Toner Cyan</option><option value="toner_magenta">Toner Magenta</option><option value="toner_jaune">Toner Jaune</option><option value="pc">PC</option><option value="ecran_lcd">Écran LCD</option><option value="imprimante">Imprimante</option></select>
    <select id="filtreEtat" class="f-select"><option value="">Tous états</option><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select>
    <select id="filtreStatut" class="f-select"><option value="">Tout</option><option value="alerte">En alerte</option><option value="rupture">En rupture</option><option value="normal">Normal</option></select>
    <button type="button" id="btnEtiquettes" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">🏷️ Étiquettes</button>
    <button type="button" id="btnScanner" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">📷 Scanner QR</button>
  </div>
  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:visible">
    <table id="tableauStock" style="width:100%;border-collapse:collapse">
      <thead><tr style="background:#f9fafb"><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Référence</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Désignation</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Catégorie</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Détails</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Quantité</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">État</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a): ?>
        <?php $q=(int)$a['quantite']; $m=(int)$a['quantite_min']; $pct=$m>0?min(100,round(($q/max($m,1))*50)):100; $cq=$q==0?'#ef4444':($q<=$m?'#f59e0b':'#10b981'); $aff=($a['unite']==='carton' && (int)($a['contenance']??0)>0)?($q.' carton'.($q>1?'s':'').' ('.($q*(int)$a['contenance']).' f.)'):($q.' unité'.($q>1?'s':'')); ?>
        <tr data-id="<?= (int)$a['id'] ?>" data-ref="<?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>" data-categorie="<?= htmlspecialchars((string)$a['categorie'], ENT_QUOTES, 'UTF-8') ?>" data-etat="<?= htmlspecialchars((string)$a['etat'], ENT_QUOTES, 'UTF-8') ?>" data-qte="<?= $q ?>" data-qte-min="<?= $m ?>" style="border-bottom:1px solid #f3f4f6;transition:background .15s">
          <td style="padding:12px 16px;font-size:13px;font-family:monospace;color:#374151"><?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="padding:12px 16px;font-size:14px;font-weight:500;color:#111827"><?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="padding:12px 16px"><?= badgeCategorie((string)$a['categorie']) ?></td>
          <td style="padding:12px 16px;font-size:12px;color:#6b7280"><?= detailsTechniques($a) ?></td>
          <td style="padding:12px 16px"><div style="display:flex;align-items:center;gap:8px"><span style="font-weight:700;font-size:14px;color:<?= $cq ?>"><?= htmlspecialchars($aff, ENT_QUOTES, 'UTF-8') ?></span></div><div style="width:80px;height:5px;background:#e5e7eb;border-radius:3px;margin-top:4px"><div style="width:<?= (int)$pct ?>%;height:100%;background:<?= $cq ?>;border-radius:3px"></div></div><div style="font-size:10px;color:#9ca3af;margin-top:2px">min: <?= $m ?></div></td>
          <td style="padding:12px 16px"><?= badgeEtat((string)$a['etat']) ?></td>
          <td style="padding:12px 16px;text-align:right;">
            <div style="display:flex;justify-content:flex-end;gap:6px;align-items:center;">
              <button type="button" class="act-edit-btn" data-id="<?= (int)$a['id'] ?>" style="background:#eef2ff;border:1px solid #c7d2fe;color:#4338ca;border-radius:6px;padding:5px 8px;font-size:12px;cursor:pointer;">✏️ Modifier</button>
              <div class="menu-wrapper" style="position:relative;display:inline-block;">
              <button type="button" class="menu-toggle" style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:6px 10px;cursor:pointer;font-size:12px;color:#4b5563;line-height:1;font-weight:600;">Actions ▾</button>
              <div class="action-menu" style="display:none;position:absolute;right:0;top:calc(100% + 4px);background:#fff;border:1px solid #e5e7eb;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);min-width:180px;z-index:9999;">
                <a href="#" class="act-edit" data-id="<?= (int)$a['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;border-radius:10px 10px 0 0;">✏️ Modifier</a>
                <a href="#" class="act-entree" data-id="<?= (int)$a['id'] ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">➕ Entrée stock</a>
                <a href="#" class="act-sortie" data-id="<?= (int)$a['id'] ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>" data-qte="<?= $q ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">➖ Sortie stock</a>
                <a href="stock_etiquettes.php?stock_id=<?= (int)$a['id'] ?>" target="_blank" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">🏷️ Étiquette QR</a>
                <a href="#" class="act-historique" data-id="<?= (int)$a['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">📋 Historique</a>
                <hr style="margin:4px 8px;border:none;border-top:1px solid #f3f4f6;">
                <a href="#" class="act-delete" data-id="<?= (int)$a['id'] ?>" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#ef4444;text-decoration:none;border-radius:0 0 10px 10px;">🗑️ Supprimer</a>
              </div>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($articles)): ?><tr><td colspan="7" style="text-align:center;padding:40px;color:#9ca3af;">Aucun article en stock — <button type="button" id="btnFirstAdd">Ajouter le premier article</button></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<div id="modalArticle" class="modal-overlay"><div class="modal-box">
  <h3 style="margin-top:0">Ajouter / Modifier article</h3>
  <form id="formArticle">
    <input type="hidden" id="id" name="id"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
      <div><label>Catégorie</label><select id="categorie" name="categorie" class="f-select" required style="width:100%"><option value="papier">papier</option><option value="toner_noir">toner_noir</option><option value="toner_cyan">toner_cyan</option><option value="toner_magenta">toner_magenta</option><option value="toner_jaune">toner_jaune</option><option value="pc">pc</option><option value="ecran_lcd">ecran_lcd</option><option value="imprimante">imprimante</option></select></div>
      <div><label>Désignation</label><input id="designation" name="designation" class="f-input" required style="width:100%"></div>
      <div><label>Référence</label><input id="reference" name="reference" class="f-input" placeholder="Générée automatiquement si vide" style="width:100%"></div>
      <div><label>Marque</label><input id="marque" name="marque" class="f-input" style="width:100%"></div>
      <div><label>Modèle</label><input id="modele" name="modele" class="f-input" style="width:100%"></div>
      <div><label>Fournisseur</label><input id="fournisseur" name="fournisseur" class="f-input" style="width:100%"></div>
      <div><label>Quantité initiale</label><input id="quantite" name="quantite" type="number" min="0" value="0" class="f-input" style="width:100%"></div>
      <div><label>Seuil minimum</label><input id="quantite_min" name="quantite_min" type="number" min="0" value="5" class="f-input" style="width:100%"></div>
      <div><label>Prix unitaire HT</label><input id="prix_unitaire_ht" name="prix_unitaire_ht" type="number" step="0.01" value="0" class="f-input" style="width:100%"></div>
      <div><label>État</label><select id="etat" name="etat" class="f-select" style="width:100%"><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select></div>
      <div><label>Emplacement</label><input id="emplacement" name="emplacement" class="f-input" style="width:100%"></div>
      <div><label>Unité</label><select id="unite" name="unite" class="f-select" style="width:100%"><option value="unite">unite</option><option value="carton">carton</option></select></div>
    </div>
    <div id="sectionDetails" style="display:none;margin-top:12px">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        <div id="row_numero_serie" class="champ-conditionnel" data-cats="pc,imprimante"><label>Numéro de série</label><input id="numero_serie" name="numero_serie" class="f-input" style="width:100%"></div>
        <div id="row_adresse_mac" class="champ-conditionnel" data-cats="pc"><label>Adresse MAC</label><input id="adresse_mac" name="adresse_mac" class="f-input" placeholder="XX:XX:XX:XX:XX:XX" style="width:100%"></div>
        <div id="row_cpu" class="champ-conditionnel" data-cats="pc"><label>CPU</label><input id="cpu" name="cpu" class="f-input" placeholder="Ex: Intel Core i5-12400" style="width:100%"></div>
        <div id="row_ram" class="champ-conditionnel" data-cats="pc"><label>RAM</label><input id="ram" name="ram" class="f-input" placeholder="Ex: 8 Go DDR4" style="width:100%"></div>
        <div id="row_stockage" class="champ-conditionnel" data-cats="pc"><label>Stockage</label><input id="stockage" name="stockage" class="f-input" placeholder="Ex: 256 Go SSD" style="width:100%"></div>
        <div id="row_couleur_toner" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune"><label>Couleur</label><input id="couleur_toner" name="couleur_toner" class="f-input" readonly style="width:100%"></div>
        <div id="row_modele_compatible" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune,imprimante,piece_detachee"><label>Modèle compatible</label><input id="modele_compatible" name="modele_compatible" class="f-input" style="width:100%"></div>
        <div id="row_rendement_pages" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune"><label>Rendement (pages)</label><input id="rendement_pages" name="rendement_pages" type="number" class="f-input" style="width:100%"></div>
        <div id="row_taille_ecran" class="champ-conditionnel" data-cats="ecran_lcd"><label>Taille écran</label><input id="taille_ecran" name="taille_ecran" class="f-input" placeholder="Ex: 24 pouces" style="width:100%"></div>
        <div id="row_resolution" class="champ-conditionnel" data-cats="ecran_lcd"><label>Résolution</label><input id="resolution" name="resolution" class="f-input" placeholder="Ex: 1920x1080" style="width:100%"></div>
        <div id="row_grammage" class="champ-conditionnel" data-cats="papier"><label>Grammage</label><input id="grammage" name="grammage" class="f-input" placeholder="Ex: 80g/m²" style="width:100%"></div>
        <div id="row_format_papier" class="champ-conditionnel" data-cats="papier"><label>Format</label><input id="format_papier" name="format_papier" class="f-input" placeholder="Ex: A4" style="width:100%"></div>
        <div id="row_contenance" class="champ-conditionnel" data-cats="papier"><label>Contenance par carton</label><input id="contenance" name="contenance" type="number" value="2500" class="f-input" style="width:100%"></div>
      </div>
    </div>
    <div style="margin-top:10px"><label>Notes</label><textarea id="notes" name="notes" class="f-input" rows="3" style="width:100%"></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><button type="button" id="btnCloseArticle" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Annuler</button><button id="btnSauvegarder" type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer">Enregistrer</button></div>
  </form>
</div></div>
<div id="modalMouvement" class="modal-overlay"><div class="modal-box"><h3 style="margin-top:0">Mouvement stock</h3><form id="formMouvement"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" id="mv_stock_id" name="stock_id"><input type="hidden" id="mv_type" name="type"><div><label>Article</label><input id="mv_article" class="f-input" readonly style="width:100%"></div><div style="margin-top:8px"><label>Quantité</label><input id="mv_quantite" name="quantite" type="number" min="1" required class="f-input" style="width:100%"></div><div style="margin-top:8px"><label>Motif</label><input id="mv_motif" name="motif" class="f-input" style="width:100%"></div><div style="margin-top:8px"><label>Référence doc</label><input id="mv_reference_doc" name="reference_doc" class="f-input" style="width:100%"></div><div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px"><button type="button" id="btnCloseMove" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Annuler</button><button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer">Valider</button></div></form></div></div>
<div id="modalHistorique" class="modal-overlay"><div class="modal-box"><h3 style="margin-top:0">Historique</h3><table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left">Date</th><th style="text-align:left">Type</th><th style="text-align:left">Quantité</th><th style="text-align:left">Avant</th><th style="text-align:left">Après</th><th style="text-align:left">Motif</th></tr></thead><tbody id="historiqueBody"></tbody></table><div style="text-align:right;margin-top:10px"><button type="button" id="btnCloseHist" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Fermer</button></div></div></div>
<div id="modalScanner" class="modal-overlay"><div class="modal-box" style="width:720px"><h3 style="margin-top:0">Scanner QR</h3><div style="margin-bottom:8px"><select id="cameraSelect" class="f-select"></select></div><div style="position:relative;width:400px;height:300px;margin:auto;background:#000;border-radius:8px;overflow:hidden"><video id="qrVideo" width="400" height="300" style="width:100%;height:100%;object-fit:cover"></video><canvas id="qrCanvas" style="display:none"></canvas><div style="position:absolute;left:12px;top:12px;width:40px;height:40px;border-left:3px solid #22c55e;border-top:3px solid #22c55e"></div><div style="position:absolute;right:12px;top:12px;width:40px;height:40px;border-right:3px solid #22c55e;border-top:3px solid #22c55e"></div><div style="position:absolute;left:12px;bottom:12px;width:40px;height:40px;border-left:3px solid #22c55e;border-bottom:3px solid #22c55e"></div><div style="position:absolute;right:12px;bottom:12px;width:40px;height:40px;border-right:3px solid #22c55e;border-bottom:3px solid #22c55e"></div><div class="scanline"></div></div><div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px"><button type="button" id="manualSearch" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Saisir manuellement</button><button type="button" id="btnCloseScan" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Fermer</button></div></div></div>
<div id="modalEtiquettes" class="modal-overlay"><div class="modal-box" style="width:760px">
  <h3 style="margin-top:0">Choisir les produits a imprimer</h3>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
    <div style="font-size:13px;color:#6b7280">Coche les articles puis clique sur "Imprimer".</div>
    <div style="display:flex;gap:8px">
      <button type="button" id="btnSelectAllLabels" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:7px 10px;cursor:pointer">Tout cocher</button>
      <button type="button" id="btnClearLabels" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:7px 10px;cursor:pointer">Tout decocher</button>
    </div>
  </div>
  <div id="labelsList" style="max-height:360px;overflow:auto;border:1px solid #e5e7eb;border-radius:10px;padding:10px;background:#fafafa">
    <?php foreach ($articles as $a): ?>
      <label style="display:flex;align-items:center;gap:10px;padding:8px 6px;border-bottom:1px solid #eef2f7;">
        <input type="checkbox" class="label-item" value="<?= (int)$a['id'] ?>">
        <span style="font-family:monospace;font-size:12px;color:#374151;min-width:150px;"><?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?></span>
        <span style="font-size:13px;color:#111827;"><?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px">
    <button type="button" id="btnCloseLabels" style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:8px;padding:9px 14px;cursor:pointer">Annuler</button>
    <button type="button" id="btnPrintLabelsSelected" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 16px;cursor:pointer;font-weight:600">Imprimer</button>
  </div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" <?= csp_nonce() ?>></script>
<script <?= csp_nonce() ?>>
function afficherToast(message, type = 'success') {
  const colors = {success:{bg:'#10b981',icon:'✅'},error:{bg:'#ef4444',icon:'❌'},warning:{bg:'#f59e0b',icon:'⚠️'}};
  const c = colors[type] || colors.success;
  const toast = document.createElement('div');
  toast.className = 'toast-kf';
  toast.style.cssText = `position:fixed; bottom:24px; right:24px; z-index:99999; background:${c.bg}; color:#fff; padding:14px 20px; border-radius:10px; font-size:14px; font-weight:500; box-shadow:0 4px 16px rgba(0,0,0,.15); display:flex; align-items:center; gap:10px; max-width:360px;`;
  toast.innerHTML = `<span>${c.icon}</span><span>${message}</span>`;
  document.body.appendChild(toast);
  setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; setTimeout(() => toast.remove(), 300); }, 3000);
}
function fermerTousMenus() { document.querySelectorAll('.action-menu').forEach(m => { m.style.display = 'none'; }); }
function toggleMenu(btn) { const menu = btn.parentElement.querySelector('.action-menu'); const estOuvert = menu.style.display === 'block'; fermerTousMenus(); if (!estOuvert) { menu.style.display = 'block'; } }
document.addEventListener('click', function(e) { if (!e.target.closest('.menu-wrapper')) { fermerTousMenus(); } });

function filtrerTableau() {
  const search = document.getElementById('recherche').value.toLowerCase();
  const cat = document.getElementById('filtreCategorie').value;
  const etat = document.getElementById('filtreEtat').value;
  const statut = document.getElementById('filtreStatut').value;
  document.querySelectorAll('#tableauStock tbody tr[data-ref]').forEach(tr => {
    const ref = tr.dataset.ref.toLowerCase();
    const des = tr.dataset.designation.toLowerCase();
    const qte = parseInt(tr.dataset.qte || '0', 10);
    const qteMin = parseInt(tr.dataset.qteMin || '0', 10);
    let show = true;
    if (search && !ref.includes(search) && !des.includes(search)) show = false;
    if (cat && tr.dataset.categorie !== cat) show = false;
    if (etat && tr.dataset.etat !== etat) show = false;
    if (statut === 'rupture' && qte !== 0) show = false;
    if (statut === 'alerte' && (qte === 0 || qte > qteMin)) show = false;
    if (statut === 'normal' && qte <= qteMin) show = false;
    tr.style.display = show ? '' : 'none';
  });
}

function ouvrirModalAjout(){ document.getElementById('formArticle').reset(); document.getElementById('id').value=''; document.getElementById('sectionDetails').style.display='none'; document.querySelectorAll('.champ-conditionnel').forEach(el=>el.style.display='none'); document.getElementById('modalArticle').style.display='flex'; }
function ouvrirModalModif(id){ const tr = document.querySelector(`#tableauStock tbody tr[data-id="${id}"]`); if (!tr) return; ouvrirModalAjout(); document.getElementById('id').value=id; document.getElementById('designation').value=tr.dataset.designation||''; document.getElementById('reference').value=tr.dataset.ref||''; document.getElementById('categorie').value=tr.dataset.categorie||'papier'; document.getElementById('etat').value=tr.dataset.etat||'neuf'; document.getElementById('quantite').value=tr.dataset.qte||'0'; document.getElementById('quantite_min').value=tr.dataset.qteMin||'5'; document.getElementById('categorie').dispatchEvent(new Event('change')); }
function fermerModal(id){ document.getElementById(id).style.display='none'; }
const couleursAuto = {'toner_noir':'Noir','toner_cyan':'Cyan','toner_magenta':'Magenta','toner_jaune':'Jaune'};
document.getElementById('categorie').addEventListener('change', function() {
  const cat = this.value;
  document.querySelectorAll('.champ-conditionnel').forEach(el => { el.style.display='none'; const input=el.querySelector('input,select,textarea'); if (input && !input.readOnly) input.value=''; });
  const avecDetails = ['pc','ecran_lcd','imprimante','toner_noir','toner_cyan','toner_magenta','toner_jaune','papier'];
  document.getElementById('sectionDetails').style.display = avecDetails.includes(cat) ? 'block' : 'none';
  document.querySelectorAll('.champ-conditionnel').forEach(el => { const cats = (el.dataset.cats||'').split(','); if (cats.includes(cat)) el.style.display='block'; });
  if (couleursAuto[cat]) document.getElementById('couleur_toner').value = couleursAuto[cat];
  if (cat === 'papier') { document.getElementById('unite').value = 'carton'; if (!document.getElementById('contenance').value) document.getElementById('contenance').value = 2500; }
});
async function soumettreFormulaire(e) {
  e.preventDefault();
  const btn = document.getElementById('btnSauvegarder');
  btn.disabled = true;
  btn.textContent = 'Enregistrement...';
  const data = new FormData(document.getElementById('formArticle'));
  try {
    const res = await fetch('../API/stock_save.php', { method: 'POST', body: data, credentials:'include' });
    const json = await res.json();
    if (json.success) {
      fermerModal('modalArticle');
      afficherToast('Article ajouté avec succès — Référence: ' + (json.reference || ''), 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      afficherToast(json.message || 'Erreur lors de la sauvegarde', 'error');
    }
  } catch(err) {
    afficherToast('Erreur réseau', 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Enregistrer';
  }
}
document.getElementById('formArticle').addEventListener('submit', soumettreFormulaire);
function ouvrirModalEntree(id, designation){ document.getElementById('mv_stock_id').value=id; document.getElementById('mv_type').value='entree'; document.getElementById('mv_article').value=designation; document.getElementById('mv_quantite').value=''; document.getElementById('mv_motif').value=''; document.getElementById('mv_reference_doc').value=''; document.getElementById('modalMouvement').style.display='flex';}
function ouvrirModalSortie(id, designation, qte){ document.getElementById('mv_stock_id').value=id; document.getElementById('mv_type').value='sortie'; document.getElementById('mv_article').value=designation + ' (Stock: ' + qte + ')'; document.getElementById('mv_quantite').value=''; document.getElementById('mv_motif').value=''; document.getElementById('mv_reference_doc').value=''; document.getElementById('modalMouvement').style.display='flex';}
document.getElementById('formMouvement').addEventListener('submit', async (e)=>{ e.preventDefault(); const fd = new FormData(e.target); try{ const res = await fetch('../API/stock_mouvement.php', { method:'POST', body:fd, credentials:'include' }); const json = await res.json(); if(json.success){ afficherToast(json.message || 'Mouvement enregistré','success'); setTimeout(()=>location.reload(), 900);} else { afficherToast(json.message || 'Erreur','error'); }}catch(_){ afficherToast('Erreur réseau', 'error'); }});
async function ouvrirHistorique(id){ const res = await fetch('../API/stock_historique.php?stock_id=' + encodeURIComponent(id), { credentials:'include' }); const rows = await res.json(); const body=document.getElementById('historiqueBody'); body.innerHTML = Array.isArray(rows)&&rows.length ? rows.map(r=>`<tr><td>${r.created_at||''}</td><td>${r.type_mouvement||''}</td><td>${r.quantite||''}</td><td>${r.quantite_avant||''}</td><td>${r.quantite_apres||''}</td><td>${r.motif||''}</td></tr>`).join('') : '<tr><td colspan="6">Aucun mouvement</td></tr>'; document.getElementById('modalHistorique').style.display='flex'; }
async function supprimerArticle(id){ if(!confirm('Supprimer cet article ?')) return; const fd = new FormData(); fd.append('stock_id', id); fd.append('csrf_token', document.body.dataset.csrfToken || ''); try{ const res = await fetch('../API/stock_delete.php', { method:'POST', body:fd, credentials:'include' }); const json = await res.json(); if(json.success){ afficherToast('Article supprimé','success'); setTimeout(()=>location.reload(),700);} else { afficherToast(json.message || 'Erreur', 'error'); }}catch(_){ afficherToast('Erreur réseau','error'); }}

let scanStream = null, scanning = false;
async function ouvrirScanner(){ document.getElementById('modalScanner').style.display='flex'; const cams = await navigator.mediaDevices.enumerateDevices(); const sel=document.getElementById('cameraSelect'); sel.innerHTML = cams.filter(d=>d.kind==='videoinput').map((c,i)=>`<option value="${c.deviceId}">${c.label||('Caméra '+(i+1))}</option>`).join(''); await demarrerScan(sel.value||undefined); sel.onchange = async()=>{ await demarrerScan(sel.value||undefined); }; }
async function demarrerScan(deviceId){ stopScan(); const constraints = deviceId ? { video:{ deviceId:{ exact:deviceId } } } : { video:{ facingMode:'environment', width:400, height:300 } }; scanStream = await navigator.mediaDevices.getUserMedia(constraints); const video=document.getElementById('qrVideo'); video.srcObject=scanStream; await video.play(); scanning=true; requestAnimationFrame(scanFrame); }
function stopScan(){ scanning=false; if(scanStream){ scanStream.getTracks().forEach(t=>t.stop()); scanStream=null; } }
function scanFrame(){ if(!scanning || typeof jsQR==='undefined') return; const video=document.getElementById('qrVideo'); const canvas=document.getElementById('qrCanvas'); const ctx=canvas.getContext('2d'); if(video.readyState===video.HAVE_ENOUGH_DATA){ canvas.width=video.videoWidth; canvas.height=video.videoHeight; ctx.drawImage(video,0,0,canvas.width,canvas.height); const imageData=ctx.getImageData(0,0,canvas.width,canvas.height); const code=jsQR(imageData.data,imageData.width,imageData.height,{inversionAttempts:'dontInvert'}); if(code){ const ref=code.data; document.getElementById('recherche').value=ref; filtrerTableau(); const row=[...document.querySelectorAll('#tableauStock tbody tr[data-ref]')].find(tr=>tr.dataset.ref===ref); if(row){ row.style.background='#dcfce7'; setTimeout(()=>{row.style.background='';},2000);} stopScan(); fermerModal('modalScanner'); return; } } requestAnimationFrame(scanFrame); }
document.getElementById('manualSearch').addEventListener('click', ()=>{ stopScan(); fermerModal('modalScanner'); document.getElementById('recherche').focus(); });
document.getElementById('btnAddArticle')?.addEventListener('click', ouvrirModalAjout);
document.getElementById('btnFirstAdd')?.addEventListener('click', ouvrirModalAjout);
document.getElementById('btnEtiquettes')?.addEventListener('click', ()=>document.getElementById('modalEtiquettes').style.display='flex');
document.getElementById('btnScanner')?.addEventListener('click', ouvrirScanner);
document.getElementById('btnCloseArticle')?.addEventListener('click', ()=>fermerModal('modalArticle'));
document.getElementById('btnCloseMove')?.addEventListener('click', ()=>fermerModal('modalMouvement'));
document.getElementById('btnCloseHist')?.addEventListener('click', ()=>fermerModal('modalHistorique'));
document.getElementById('btnCloseScan')?.addEventListener('click', ()=>{ stopScan(); fermerModal('modalScanner'); });
document.getElementById('btnCloseLabels')?.addEventListener('click', ()=>fermerModal('modalEtiquettes'));
document.getElementById('btnSelectAllLabels')?.addEventListener('click', ()=>document.querySelectorAll('.label-item').forEach(c=>{ c.checked = true; }));
document.getElementById('btnClearLabels')?.addEventListener('click', ()=>document.querySelectorAll('.label-item').forEach(c=>{ c.checked = false; }));
document.getElementById('btnPrintLabelsSelected')?.addEventListener('click', ()=>{
  const ids = [...document.querySelectorAll('.label-item:checked')].map(c=>c.value).filter(Boolean);
  if (!ids.length) {
    afficherToast('Selectionne au moins un article', 'warning');
    return;
  }
  window.open('stock_etiquettes.php?ids=' + encodeURIComponent(ids.join(',')), '_blank');
});
document.getElementById('recherche')?.addEventListener('input', filtrerTableau);
document.getElementById('filtreCategorie')?.addEventListener('change', filtrerTableau);
document.getElementById('filtreEtat')?.addEventListener('change', filtrerTableau);
document.getElementById('filtreStatut')?.addEventListener('change', filtrerTableau);
document.querySelectorAll('.menu-toggle').forEach(btn => {
  btn.addEventListener('click', (event) => { toggleMenu(btn); event.stopPropagation(); });
});
document.querySelectorAll('.act-edit').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); ouvrirModalModif(parseInt(a.dataset.id||'0',10)); }));
document.querySelectorAll('.act-edit-btn').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); ouvrirModalModif(parseInt(a.dataset.id||'0',10)); }));
document.querySelectorAll('.act-entree').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); ouvrirModalEntree(parseInt(a.dataset.id||'0',10), a.dataset.designation || ''); }));
document.querySelectorAll('.act-sortie').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); ouvrirModalSortie(parseInt(a.dataset.id||'0',10), a.dataset.designation || '', parseInt(a.dataset.qte||'0',10)); }));
document.querySelectorAll('.act-historique').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); ouvrirHistorique(parseInt(a.dataset.id||'0',10)); }));
document.querySelectorAll('.act-delete').forEach(a => a.addEventListener('click', (e)=>{ e.preventDefault(); supprimerArticle(parseInt(a.dataset.id||'0',10)); }));
</script>
</body>
</html>
<?php __halt_compiler(); ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrfToken = $_SESSION['csrf_token'] ?? '';

$pdo = getPdo();
$articles = $pdo->query(
  "SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalArticles = count($articles);
$valeurStock = array_sum(array_map(static fn($a) => ((float)$a['quantite'] * (float)$a['prix_unitaire_ht']), $articles));
$enAlerte = count(array_filter($articles, static fn($a) => ((int)$a['quantite'] > 0 && (int)$a['quantite'] <= (int)$a['quantite_min'])));
$enRupture = count(array_filter($articles, static fn($a) => (int)$a['quantite'] === 0));

function badgeCategorie(string $cat): string {
  $cfg = [
    'papier'         => ['Papier', '#dbeafe', '#1d4ed8'],
    'toner_noir'     => ['Toner Noir', '#1f2937', '#fff'],
    'toner_cyan'     => ['Toner Cyan', '#cffafe', '#0e7490'],
    'toner_magenta'  => ['Toner Magenta', '#fce7f3', '#9d174d'],
    'toner_jaune'    => ['Toner Jaune', '#fef9c3', '#854d0e'],
    'pc'             => ['PC', '#ede9fe', '#5b21b6'],
    'ecran_lcd'      => ['Écran LCD', '#f3e8ff', '#7e22ce'],
    'imprimante'     => ['Imprimante', '#dcfce7', '#166534'],
  ];
  [$label, $bg, $color] = $cfg[$cat] ?? [ucfirst($cat), '#f3f4f6', '#374151'];
  return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;white-space:nowrap;\">{$label}</span>";
}

function badgeEtat(string $etat): string {
  $cfg = [
    'neuf' => ['Neuf', '#dcfce7', '#166534'],
    'bon'  => ['Bon', '#dbeafe', '#1d4ed8'],
    'use'  => ['Usé', '#ffedd5', '#9a3412'],
    'hs'   => ['HS', '#fee2e2', '#991b1b'],
  ];
  [$label, $bg, $color] = $cfg[$etat] ?? [ucfirst($etat), '#f3f4f6', '#374151'];
  return "<span style=\"background:{$bg};color:{$color};padding:3px 10px;border-radius:999px;font-size:11px;font-weight:600;\">{$label}</span>";
}

function detailsTechniques(array $a): string {
  $parts = [];
  switch ((string)$a['categorie']) {
    case 'pc':
      if (!empty($a['cpu'])) $parts[] = htmlspecialchars((string)$a['cpu']);
      if (!empty($a['ram'])) $parts[] = htmlspecialchars((string)$a['ram']) . ' RAM';
      if (!empty($a['stockage'])) $parts[] = htmlspecialchars((string)$a['stockage']);
      if (!empty($a['adresse_mac'])) $parts[] = '<span style="font-family:monospace;">' . htmlspecialchars((string)$a['adresse_mac']) . '</span>';
      break;
    case 'ecran_lcd':
      if (!empty($a['taille_ecran'])) $parts[] = htmlspecialchars((string)$a['taille_ecran']);
      if (!empty($a['resolution'])) $parts[] = htmlspecialchars((string)$a['resolution']);
      break;
    case 'imprimante':
      if (!empty($a['modele'])) $parts[] = htmlspecialchars((string)$a['modele']);
      if (!empty($a['numero_serie'])) $parts[] = 'SN: ' . htmlspecialchars(substr((string)$a['numero_serie'], 0, 12)) . '...';
      break;
    case 'toner_noir':
    case 'toner_cyan':
    case 'toner_magenta':
    case 'toner_jaune':
      if (!empty($a['modele'])) $parts[] = htmlspecialchars((string)$a['modele']);
      if (!empty($a['rendement_pages'])) $parts[] = number_format((float)$a['rendement_pages'], 0, ',', ' ') . ' pages';
      break;
    case 'papier':
      if (!empty($a['format_papier'])) $parts[] = htmlspecialchars((string)$a['format_papier']);
      if (!empty($a['grammage'])) $parts[] = htmlspecialchars((string)$a['grammage']);
      break;
  }
  return $parts ? implode(' · ', $parts) : '<span style="color:#d1d5db;">—</span>';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestion du Stock</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body { background:#f8f9fb; }
    .wrap { padding:20px; }
    .toolbar-btn { border:1px solid #e5e7eb; border-radius:8px; padding:9px 14px; cursor:pointer; background:#f3f4f6; color:#374151; }
    .f-input, .f-select { border:1px solid #e5e7eb; border-radius:8px; padding:8px 12px; }
    .action-menu a, .action-menu button { display:flex; align-items:center; gap:8px; padding:9px 16px; font-size:13px; color:#374151; text-decoration:none; width:100%; border:none; background:none; text-align:left; cursor:pointer; }
    .modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:1000; display:none; align-items:center; justify-content:center; }
    .modal-box { background:#fff; border-radius:16px; width:600px; max-height:90vh; overflow-y:auto; padding:32px; }
    .scan-line { position:absolute; left:0; right:0; height:2px; background:#22c55e; animation:scan 2s linear infinite; }
    @keyframes scan { 0%{top:0} 100%{top:298px} }
  </style>
</head>
<body data-csrf-token="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <?php if ($flash): ?><div style="margin-bottom:12px;background:#ecfeff;color:#155e75;padding:10px 12px;border-radius:10px;"><?= htmlspecialchars((string)$flash, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <div style="margin-bottom:28px;">
    <h1 style="font-size:26px;font-weight:700;color:#111827;margin:0 0 4px;">Gestion du Stock</h1>
    <p style="color:#6b7280;font-size:14px;margin:0;"><?= $totalArticles ?> article<?= $totalArticles > 1 ? 's' : '' ?> en inventaire</p>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #6366f1;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Total articles</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $totalArticles ?></div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #10b981;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Valeur stock HT</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= number_format($valeurStock, 2, ',', ' ') ?> €</div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #f59e0b;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">En alerte</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $enAlerte ?></div></div>
    <div style="background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07);border-left:4px solid #ef4444;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">En rupture</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $enRupture ?></div></div>
  </div>

  <div style="background:#fff;border-radius:12px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.07);margin-bottom:20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <button type="button" id="btnAjout" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;">+ Ajouter article</button>
    <input id="recherche" class="f-input" placeholder="Rechercher (réf, désignation, SN...)" style="width:240px;">
    <select id="filtreCategorie" class="f-select">
      <option value="">Toutes catégories</option><option value="papier">Papier</option><option value="toner_noir">Toner Noir</option><option value="toner_cyan">Toner Cyan</option><option value="toner_magenta">Toner Magenta</option><option value="toner_jaune">Toner Jaune</option><option value="pc">PC</option><option value="ecran_lcd">Écran LCD</option><option value="imprimante">Imprimante</option>
    </select>
    <select id="filtreEtat" class="f-select"><option value="">Tous états</option><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select>
    <select id="filtreStatut" class="f-select"><option value="">Tout</option><option value="alerte">En alerte</option><option value="rupture">En rupture</option><option value="normal">Normal</option></select>
    <button type="button" id="btnEtiquettes" class="toolbar-btn">🏷️ Étiquettes</button>
    <button type="button" id="btnScanner" class="toolbar-btn">📷 Scanner QR</button>
  </div>

  <div style="background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden;">
    <table id="tableauStock" style="width:100%;border-collapse:collapse;">
      <thead><tr style="background:#f9fafb;"><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Référence</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Désignation</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Catégorie</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Détails</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Quantité</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">État</th><th style="padding:12px 16px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;text-align:left;border-bottom:1px solid #e5e7eb;">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($articles as $a): ?>
        <?php
          $qte = (int)$a['quantite']; $qteMin = (int)$a['quantite_min'];
          $pct = $qteMin > 0 ? min(100, (int)round($qte / max($qteMin, 1) * 50)) : 100;
          $couleurQte = $qte === 0 ? '#ef4444' : ($qte <= $qteMin ? '#f59e0b' : '#10b981');
          $affQte = ($a['unite'] === 'carton' && (int)($a['contenance'] ?? 0) > 0) ? ($qte . ' carton' . ($qte > 1 ? 's' : '') . ' (' . ($qte * (int)$a['contenance']) . ' f.)') : ($qte . ' unité' . ($qte > 1 ? 's' : ''));
        ?>
        <tr data-ref="<?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>" data-categorie="<?= htmlspecialchars((string)$a['categorie'], ENT_QUOTES, 'UTF-8') ?>" data-etat="<?= htmlspecialchars((string)$a['etat'], ENT_QUOTES, 'UTF-8') ?>" data-qte="<?= $qte ?>" data-qte-min="<?= $qteMin ?>" style="border-bottom:1px solid #f3f4f6;transition:background .15s;">
          <td style="padding:12px 16px;font-size:13px;font-family:monospace;color:#374151;"><?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="padding:12px 16px;font-size:14px;font-weight:500;color:#111827;"><?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($a['marque'])): ?><span style="font-size:11px;color:#9ca3af;font-weight:400;display:block;"><?= htmlspecialchars((string)$a['marque'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></td>
          <td style="padding:12px 16px;"><?= badgeCategorie((string)$a['categorie']) ?></td>
          <td style="padding:12px 16px;font-size:12px;color:#6b7280;"><?= detailsTechniques($a) ?></td>
          <td style="padding:12px 16px;"><div style="display:flex;align-items:center;gap:8px;"><span style="font-weight:700;font-size:14px;color:<?= $couleurQte ?>;"><?= htmlspecialchars($affQte, ENT_QUOTES, 'UTF-8') ?></span></div><div style="width:80px;height:5px;background:#e5e7eb;border-radius:3px;margin-top:4px;"><div style="width:<?= $pct ?>%;height:100%;background:<?= $couleurQte ?>;border-radius:3px;"></div></div><div style="font-size:10px;color:#9ca3af;margin-top:2px;">min: <?= $qteMin ?></div></td>
          <td style="padding:12px 16px;"><?= badgeEtat((string)$a['etat']) ?></td>
          <td style="padding:12px 16px;text-align:right;">
            <div style="position:relative;display:inline-block;">
              <button type="button" data-menu-btn style="background:none;border:1px solid #e5e7eb;border-radius:6px;padding:5px 12px;cursor:pointer;color:#6b7280;font-size:16px;">⋮</button>
              <div class="action-menu" style="display:none;position:absolute;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:170px;z-index:100;margin-top:4px;">
                <button type="button" data-edit="<?= (int)$a['id'] ?>">✏️ Modifier</button>
                <button type="button" data-entree="<?= (int)$a['id'] ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>">➕ Entrée stock</button>
                <button type="button" data-sortie="<?= (int)$a['id'] ?>" data-designation="<?= htmlspecialchars((string)$a['designation'], ENT_QUOTES, 'UTF-8') ?>" data-qte="<?= $qte ?>">➖ Sortie stock</button>
                <a href="stock_etiquettes.php?stock_id=<?= (int)$a['id'] ?>" target="_blank">🏷️ Étiquette QR</a>
                <button type="button" data-historique="<?= (int)$a['id'] ?>">📋 Historique</button>
                <hr style="margin:4px 0;border:none;border-top:1px solid #f3f4f6;">
                <button type="button" data-delete="<?= (int)$a['id'] ?>" style="color:#ef4444;">🗑️ Supprimer</button>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($articles)): ?>
        <tr><td colspan="7" style="text-align:center;padding:60px;color:#9ca3af;"><div style="font-size:40px;margin-bottom:12px;">📦</div><div style="font-size:16px;font-weight:500;margin-bottom:8px;">Aucun article en stock</div><button type="button" id="btnPremier" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:10px 20px;cursor:pointer;font-weight:600;">+ Ajouter le premier article</button></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<div id="modalArticle" class="modal-overlay"><div class="modal-box">
  <h3 style="margin-top:0">Ajouter / Modifier article</h3>
  <form id="formArticle">
    <input type="hidden" name="id" id="id">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <div style="font-weight:600;margin-bottom:8px;">Général</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div><label>Catégorie</label><select name="categorie" id="categorie" class="f-select" required style="width:100%"><option value="papier">papier</option><option value="toner_noir">toner_noir</option><option value="toner_cyan">toner_cyan</option><option value="toner_magenta">toner_magenta</option><option value="toner_jaune">toner_jaune</option><option value="pc">pc</option><option value="ecran_lcd">ecran_lcd</option><option value="imprimante">imprimante</option></select></div>
      <div><label>Désignation</label><input name="designation" id="designation" class="f-input" required style="width:100%"></div>
      <div><label>Référence</label><input name="reference" id="reference" class="f-input" placeholder="Générée automatiquement si vide" style="width:100%"></div>
      <div><label>Marque</label><input name="marque" id="marque" class="f-input" style="width:100%"></div>
      <div><label>Modèle</label><input name="modele_compatible" id="modele_compatible" class="f-input" style="width:100%"></div>
      <div><label>Fournisseur</label><input name="fournisseur" id="fournisseur" class="f-input" style="width:100%"></div>
    </div>
    <div style="font-weight:600;margin:16px 0 8px;">Stock</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
      <div><label>Quantité initiale</label><input type="number" min="0" name="quantite" id="quantite" class="f-input" value="0" style="width:100%"></div>
      <div><label>Seuil minimum d'alerte</label><input type="number" min="0" name="quantite_min" id="quantite_min" class="f-input" value="5" style="width:100%"></div>
      <div><label>Prix unitaire HT</label><input type="number" step="0.01" name="prix_unitaire_ht" id="prix_unitaire_ht" class="f-input" value="0" style="width:100%"></div>
      <div><label>État</label><select name="etat" id="etat" class="f-select" style="width:100%"><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select></div>
      <div><label>Emplacement</label><input name="emplacement" id="emplacement" class="f-input" style="width:100%"></div>
      <div><label>Unité</label><select name="unite" id="unite" class="f-select" style="width:100%"><option value="unite">unite</option><option value="carton">carton</option></select></div>
    </div>
    <div id="sectionDetails" style="display:none">
      <div style="font-weight:600;margin:16px 0 8px;">Détails techniques</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div id="row_numero_serie" class="champ-conditionnel" data-cats="pc,imprimante"><label>Numéro de série</label><input name="numero_serie" id="numero_serie" class="f-input" style="width:100%"></div>
        <div id="row_adresse_mac" class="champ-conditionnel" data-cats="pc"><label>Adresse MAC</label><input name="adresse_mac" id="adresse_mac" class="f-input" placeholder="XX:XX:XX:XX:XX:XX" style="width:100%"></div>
        <div id="row_cpu" class="champ-conditionnel" data-cats="pc"><label>CPU</label><input name="cpu" id="cpu" class="f-input" placeholder="Ex: Intel Core i5-12400" style="width:100%"></div>
        <div id="row_ram" class="champ-conditionnel" data-cats="pc"><label>RAM</label><input name="ram" id="ram" class="f-input" placeholder="Ex: 8 Go DDR4" style="width:100%"></div>
        <div id="row_stockage" class="champ-conditionnel" data-cats="pc"><label>Stockage</label><input name="stockage" id="stockage" class="f-input" placeholder="Ex: 256 Go SSD" style="width:100%"></div>
        <div id="row_couleur_toner" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune"><label>Couleur</label><input name="couleur_toner" id="couleur_toner" class="f-input" readonly style="width:100%"></div>
        <div id="row_modele_compatible" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune,imprimante,piece_detachee"><label>Modèle compatible</label><input name="modele_compatible" id="modele_compatible_2" class="f-input" style="width:100%"></div>
        <div id="row_rendement_pages" class="champ-conditionnel" data-cats="toner_noir,toner_cyan,toner_magenta,toner_jaune"><label>Rendement (pages)</label><input type="number" name="rendement_pages" id="rendement_pages" class="f-input" style="width:100%"></div>
        <div id="row_taille_ecran" class="champ-conditionnel" data-cats="ecran_lcd"><label>Taille écran</label><input name="taille_ecran" id="taille_ecran" class="f-input" placeholder="Ex: 24 pouces" style="width:100%"></div>
        <div id="row_resolution" class="champ-conditionnel" data-cats="ecran_lcd"><label>Résolution</label><input name="resolution" id="resolution" class="f-input" placeholder="Ex: 1920x1080" style="width:100%"></div>
        <div id="row_grammage" class="champ-conditionnel" data-cats="papier"><label>Grammage</label><input name="grammage" id="grammage" class="f-input" placeholder="Ex: 80g/m²" style="width:100%"></div>
        <div id="row_format_papier" class="champ-conditionnel" data-cats="papier"><label>Format</label><input name="format_papier" id="format_papier" class="f-input" placeholder="Ex: A4" style="width:100%"></div>
        <div id="row_contenance" class="champ-conditionnel" data-cats="papier"><label>Contenance par carton</label><input type="number" name="contenance" id="contenance" class="f-input" value="2500" style="width:100%"></div>
      </div>
    </div>
    <div style="margin-top:12px;"><label>Notes</label><textarea name="notes" id="notes" class="f-input" rows="3" style="width:100%"></textarea></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;"><button type="button" id="closeArticle" class="toolbar-btn">Fermer</button><button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;">Enregistrer</button></div>
  </form>
</div></div>

<div id="modalMouvement" class="modal-overlay"><div class="modal-box">
  <h3 style="margin-top:0">Mouvement de stock</h3>
  <form id="formMouvement">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="stock_id" id="mv_stock_id">
    <input type="hidden" name="type" id="mv_type">
    <div><label>Article</label><input id="mv_article" class="f-input" readonly style="width:100%"></div>
    <div style="margin-top:8px;"><label>Quantité</label><input id="mv_quantite" name="quantite" type="number" min="1" required class="f-input" style="width:100%"></div>
    <div style="margin-top:8px;"><label>Motif</label><input id="mv_motif" name="motif" class="f-input" style="width:100%"></div>
    <div style="margin-top:8px;"><label>Référence doc</label><input id="mv_reference_doc" name="reference_doc" class="f-input" style="width:100%"></div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:12px;"><button type="button" id="closeMv" class="toolbar-btn">Fermer</button><button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;">Valider</button></div>
  </form>
</div></div>

<div id="modalHistorique" class="modal-overlay"><div class="modal-box">
  <h3 style="margin-top:0">Historique</h3>
  <table style="width:100%;border-collapse:collapse"><thead><tr><th style="text-align:left">Date</th><th style="text-align:left">Type</th><th style="text-align:left">Quantité</th><th style="text-align:left">Avant</th><th style="text-align:left">Après</th><th style="text-align:left">Motif</th></tr></thead><tbody id="historiqueBody"></tbody></table>
  <div style="text-align:right;margin-top:10px;"><button type="button" id="closeHist" class="toolbar-btn">Fermer</button></div>
</div></div>

<div id="modalScanner" class="modal-overlay"><div class="modal-box" style="width:700px;">
  <h3 style="margin-top:0">Scanner QR</h3>
  <div style="margin-bottom:8px;"><select id="cameraSelect" class="f-select"></select></div>
  <div style="position:relative;width:400px;height:300px;margin:auto;background:#000;border-radius:8px;overflow:hidden">
    <video id="qrVideo" width="400" height="300" style="width:100%;height:100%;object-fit:cover"></video>
    <canvas id="qrCanvas" style="display:none"></canvas>
    <div style="position:absolute;left:12px;top:12px;width:40px;height:40px;border-left:3px solid #22c55e;border-top:3px solid #22c55e;"></div>
    <div style="position:absolute;right:12px;top:12px;width:40px;height:40px;border-right:3px solid #22c55e;border-top:3px solid #22c55e;"></div>
    <div style="position:absolute;left:12px;bottom:12px;width:40px;height:40px;border-left:3px solid #22c55e;border-bottom:3px solid #22c55e;"></div>
    <div style="position:absolute;right:12px;bottom:12px;width:40px;height:40px;border-right:3px solid #22c55e;border-bottom:3px solid #22c55e;"></div>
    <div class="scan-line"></div>
  </div>
  <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:10px;"><button type="button" id="manualSearch" class="toolbar-btn">Saisir manuellement</button><button type="button" id="closeScan" class="toolbar-btn">Fermer</button></div>
</div></div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" <?= csp_nonce() ?>></script>
<script <?= csp_nonce() ?>>
const canWrite = <?= in_array((string)($_SESSION['emploi'] ?? ''), ['Admin','Dirigeant','Secrétaire'], true) ? 'true' : 'false' ?>;
let scanStream = null;
let scanning = false;

function fermerModal(id){document.getElementById(id).style.display='none';}
function ouvrirModal(id){document.getElementById(id).style.display='flex';}

document.getElementById('btnAjout').addEventListener('click', ()=>canWrite && ouvrirModal('modalArticle'));
const premier = document.getElementById('btnPremier'); if (premier) premier.addEventListener('click', ()=>canWrite && ouvrirModal('modalArticle'));
document.getElementById('closeArticle').addEventListener('click', ()=>fermerModal('modalArticle'));
document.getElementById('closeMv').addEventListener('click', ()=>fermerModal('modalMouvement'));
document.getElementById('closeHist').addEventListener('click', ()=>fermerModal('modalHistorique'));
document.getElementById('closeScan').addEventListener('click', ()=>{stopScan();fermerModal('modalScanner');});
document.getElementById('btnEtiquettes').addEventListener('click', ()=>window.open('stock_etiquettes.php?all=1','_blank'));

function filtrerTableau() {
  const search = document.getElementById('recherche').value.toLowerCase();
  const cat = document.getElementById('filtreCategorie').value;
  const etat = document.getElementById('filtreEtat').value;
  const statut = document.getElementById('filtreStatut').value;
  document.querySelectorAll('#tableauStock tbody tr[data-ref]').forEach(tr => {
    const ref = tr.dataset.ref.toLowerCase();
    const des = tr.dataset.designation.toLowerCase();
    const qte = parseInt(tr.dataset.qte || '0', 10);
    const qteMin = parseInt(tr.dataset.qteMin || '0', 10);
    let show = true;
    if (search && !ref.includes(search) && !des.includes(search)) show = false;
    if (cat && tr.dataset.categorie !== cat) show = false;
    if (etat && tr.dataset.etat !== etat) show = false;
    if (statut === 'rupture' && qte !== 0) show = false;
    if (statut === 'alerte' && (qte === 0 || qte > qteMin)) show = false;
    if (statut === 'normal' && qte <= qteMin) show = false;
    tr.style.display = show ? '' : 'none';
  });
}
['recherche','filtreCategorie','filtreEtat','filtreStatut'].forEach(id=>document.getElementById(id).addEventListener('input',filtrerTableau));
['filtreCategorie','filtreEtat','filtreStatut'].forEach(id=>document.getElementById(id).addEventListener('change',filtrerTableau));

function toggleMenu(btn) {
  document.querySelectorAll('.action-menu').forEach(m => { if (m !== btn.nextElementSibling) m.style.display = 'none'; });
  const menu = btn.nextElementSibling;
  menu.style.display = menu.style.display === 'none' || menu.style.display === '' ? 'block' : 'none';
}
document.querySelectorAll('[data-menu-btn]').forEach(btn=>btn.addEventListener('click', (e)=>{e.stopPropagation();toggleMenu(btn);}));
document.addEventListener('click', function(e) {
  if (!e.target.closest('[data-menu-btn]') && !e.target.closest('.action-menu')) {
    document.querySelectorAll('.action-menu').forEach(m => m.style.display = 'none');
  }
});

const couleursAuto = {'toner_noir':'Noir','toner_cyan':'Cyan','toner_magenta':'Magenta','toner_jaune':'Jaune'};
document.getElementById('categorie').addEventListener('change', function() {
  const cat = this.value;
  document.querySelectorAll('.champ-conditionnel').forEach(el => {
    el.style.display = 'none';
    const input = el.querySelector('input,select,textarea');
    if (input && !input.readOnly) input.value = '';
  });
  const avecDetails = ['pc','ecran_lcd','imprimante','toner_noir','toner_cyan','toner_magenta','toner_jaune','papier'];
  document.getElementById('sectionDetails').style.display = avecDetails.includes(cat) ? 'block' : 'none';
  document.querySelectorAll('.champ-conditionnel').forEach(el => {
    const cats = (el.dataset.cats || '').split(',');
    if (cats.includes(cat)) el.style.display = 'block';
  });
  if (couleursAuto[cat]) document.getElementById('couleur_toner').value = couleursAuto[cat];
  if (cat === 'papier') {
    document.getElementById('unite').value = 'carton';
    if (!document.getElementById('contenance').value) document.getElementById('contenance').value = 2500;
  }
});

document.getElementById('formArticle').addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = new FormData(this);
  const m2 = document.getElementById('modele_compatible_2').value;
  if (m2 && !data.get('modele_compatible')) data.set('modele_compatible', m2);
  const res = await fetch('../API/stock_save.php', { method:'POST', body: data, credentials:'include' });
  const json = await res.json();
  if (json.success) {
    fermerModal('modalArticle');
    location.reload();
  } else {
    alert(json.message || 'Erreur lors de la sauvegarde');
  }
});

document.querySelectorAll('[data-edit]').forEach(btn=>btn.addEventListener('click', ()=>{
  if (!canWrite) return;
  const id = btn.dataset.edit;
  const tr = btn.closest('tr');
  document.getElementById('id').value = id;
  document.getElementById('designation').value = tr.children[1].childNodes[0].textContent.trim();
  document.getElementById('reference').value = tr.dataset.ref;
  document.getElementById('categorie').value = tr.dataset.categorie;
  document.getElementById('etat').value = tr.dataset.etat;
  document.getElementById('quantite').value = tr.dataset.qte;
  document.getElementById('quantite_min').value = tr.dataset.qteMin;
  document.getElementById('categorie').dispatchEvent(new Event('change'));
  ouvrirModal('modalArticle');
}));
document.querySelectorAll('[data-entree]').forEach(btn=>btn.addEventListener('click', ()=>{
  if (!canWrite) return;
  document.getElementById('mv_stock_id').value = btn.dataset.entree;
  document.getElementById('mv_type').value = 'entree';
  document.getElementById('mv_article').value = btn.dataset.designation || '';
  document.getElementById('mv_quantite').value = '';
  ouvrirModal('modalMouvement');
}));
document.querySelectorAll('[data-sortie]').forEach(btn=>btn.addEventListener('click', ()=>{
  if (!canWrite) return;
  document.getElementById('mv_stock_id').value = btn.dataset.sortie;
  document.getElementById('mv_type').value = 'sortie';
  document.getElementById('mv_article').value = btn.dataset.designation || '';
  document.getElementById('mv_quantite').value = '';
  ouvrirModal('modalMouvement');
}));
document.querySelectorAll('[data-delete]').forEach(btn=>btn.addEventListener('click', async()=>{
  if (!canWrite) return;
  if (!confirm('Supprimer cet article ?')) return;
  const fd = new FormData();
  fd.append('stock_id', btn.dataset.delete);
  fd.append('csrf_token', document.body.dataset.csrfToken || '');
  const res = await fetch('../API/stock_delete.php', { method:'POST', body: fd, credentials:'include' });
  const json = await res.json();
  if (json.success) location.reload(); else alert(json.message || 'Erreur');
}));
document.querySelectorAll('[data-historique]').forEach(btn=>btn.addEventListener('click', async()=>{
  const stockId = btn.dataset.historique;
  const res = await fetch('../API/stock_historique.php?stock_id=' + encodeURIComponent(stockId), { credentials:'include' });
  const rows = await res.json();
  const body = document.getElementById('historiqueBody');
  body.innerHTML = Array.isArray(rows) && rows.length ? rows.map(r=>`<tr><td>${r.created_at||''}</td><td>${r.type_mouvement||''}</td><td>${r.quantite||''}</td><td>${r.quantite_avant||''}</td><td>${r.quantite_apres||''}</td><td>${r.motif||''}</td></tr>`).join('') : '<tr><td colspan="6">Aucun mouvement</td></tr>';
  ouvrirModal('modalHistorique');
}));
document.getElementById('formMouvement').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const fd = new FormData(e.target);
  const res = await fetch('../API/stock_mouvement.php', { method:'POST', body: fd, credentials:'include' });
  const json = await res.json();
  if (json.success) { fermerModal('modalMouvement'); location.reload(); } else { alert(json.message || 'Erreur'); }
});

async function ouvrirScanner(){
  ouvrirModal('modalScanner');
  if (!navigator.mediaDevices?.getUserMedia) return;
  const cams = await navigator.mediaDevices.enumerateDevices();
  const sel = document.getElementById('cameraSelect');
  sel.innerHTML = cams.filter(d=>d.kind==='videoinput').map((c,i)=>`<option value="${c.deviceId}">${c.label||('Caméra '+(i+1))}</option>`).join('');
  startScan(sel.value || undefined);
  sel.onchange = ()=>startScan(sel.value || undefined);
}
async function startScan(deviceId){
  stopScan();
  const constraints = deviceId ? { video:{ deviceId:{ exact: deviceId } } } : { video:{ facingMode:'environment', width:400, height:300 } };
  scanStream = await navigator.mediaDevices.getUserMedia(constraints);
  const video = document.getElementById('qrVideo');
  video.srcObject = scanStream;
  await video.play();
  scanning = true;
  requestAnimationFrame(scanFrame);
}
function stopScan(){ scanning=false; if(scanStream){ scanStream.getTracks().forEach(t=>t.stop()); scanStream=null; } }
function scanFrame(){
  if (!scanning || typeof jsQR === 'undefined') return;
  const video = document.getElementById('qrVideo');
  const canvas = document.getElementById('qrCanvas');
  const ctx = canvas.getContext('2d');
  if (video.readyState === video.HAVE_ENOUGH_DATA) {
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
    const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'dontInvert' });
    if (code) {
      const ref = code.data;
      document.getElementById('recherche').value = ref;
      filtrerTableau();
      const row = [...document.querySelectorAll('#tableauStock tbody tr[data-ref]')].find(tr => tr.dataset.ref === ref);
      if (row) { row.style.background = '#dcfce7'; setTimeout(()=>{row.style.background='';}, 2000); }
      stopScan();
      fermerModal('modalScanner');
      return;
    }
  }
  requestAnimationFrame(scanFrame);
}
document.getElementById('btnScanner').addEventListener('click', ouvrirScanner);
document.getElementById('manualSearch').addEventListener('click', ()=>{ stopScan(); fermerModal('modalScanner'); document.getElementById('recherche').focus(); });

</script>
</body>
</html>
<?php __halt_compiler(); ?>
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();
$articles = $pdo->query("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$totalArticles = count($articles);
$valeurStock = array_sum(array_map(static fn($a) => ((float)$a['quantite'] * (float)$a['prix_unitaire_ht']), $articles));
$enAlerte = count(array_filter($articles, static fn($a) => ((int)$a['quantite'] > 0 && (int)$a['quantite'] <= (int)$a['quantite_min'])));
$enRupture = count(array_filter($articles, static fn($a) => (int)$a['quantite'] === 0));
$csrfToken = $_SESSION['csrf_token'] ?? '';
$canWrite = in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true);

function h2(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Gestion du Stock</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body{background:#f8f9fb}
    .wrap{padding:20px}
    .cards{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
    .card{background:#fff;border-radius:12px;padding:20px 24px;box-shadow:0 1px 4px rgba(0,0,0,.07)}
    .toolbar{background:#fff;border-radius:12px;padding:14px 20px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px}
    .btn{border:none;border-radius:8px;padding:8px 16px;font-weight:600;cursor:pointer}
    .btn-primary{background:#6366f1;color:#fff}
    .btn-soft{background:#f3f4f6;color:#374151}
    .input,.select{border:1px solid #e5e7eb;border-radius:8px;padding:8px 12px}
    .tableWrap{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.07);overflow:hidden}
    table{width:100%;border-collapse:collapse}
    thead th{background:#f9fafb;font-size:11px;font-weight:600;text-transform:uppercase;color:#6b7280;padding:12px 16px;text-align:left}
    tbody td{padding:12px 16px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
    tbody tr:hover{background:#fafafa}
    .badge{padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;display:inline-block}
    .menu{position:relative;display:inline-block}
    .menu-items{display:none;position:absolute;right:0;top:100%;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);min-width:170px;z-index:20}
    .menu-items a,.menu-items button{display:block;width:100%;padding:8px 16px;border:none;background:none;text-align:left;color:#374151;cursor:pointer}
    .modal-bg{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;z-index:90}
    .modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;border-radius:12px;width:min(900px,95vw);max-height:90vh;overflow:auto;display:none;z-index:91;padding:16px}
    .grid{display:grid;grid-template-columns:repeat(3,minmax(140px,1fr));gap:10px}
    .field{display:flex;flex-direction:column;gap:4px}
    .scan-box{position:relative;width:400px;height:300px;background:#000;margin:auto;overflow:hidden;border-radius:8px}
    .scan-line{position:absolute;left:0;right:0;height:2px;background:#22c55e;animation:scan 2s linear infinite}
    .corners:before,.corners:after{content:"";position:absolute;width:60px;height:60px;border:3px solid #22c55e}
    .corners:before{top:10px;left:10px;border-right:none;border-bottom:none}
    .corners:after{right:10px;bottom:10px;border-left:none;border-top:none}
    @keyframes scan{0%{top:0}100%{top:298px}}
    @media(max-width:1000px){.cards{grid-template-columns:repeat(2,1fr)} .grid{grid-template-columns:1fr}}
  </style>
</head>
<body data-csrf-token="<?= h2((string)$csrfToken) ?>" data-can-write="<?= $canWrite ? '1' : '0' ?>">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <div style="margin-bottom:24px;">
    <h1 style="font-size:24px;font-weight:700;color:#111827;margin:0 0 4px;">Gestion du Stock</h1>
    <p style="color:#6b7280;font-size:14px;margin:0;">Inventaire complet — <?= $totalArticles ?> articles</p>
  </div>

  <div class="cards">
    <div class="card" style="border-left:4px solid #6366f1;"><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Total articles</div><div style="font-size:28px;font-weight:700;color:#111827"><?= $totalArticles ?></div></div>
    <div class="card" style="border-left:4px solid #10b981;"><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">Valeur stock HT</div><div style="font-size:28px;font-weight:700;color:#111827"><?= number_format($valeurStock, 2, ',', ' ') ?> €</div></div>
    <div class="card" style="border-left:4px solid #f59e0b;"><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">En alerte</div><div style="font-size:28px;font-weight:700;color:#111827"><?= $enAlerte ?></div></div>
    <div class="card" style="border-left:4px solid #ef4444;"><div style="font-size:11px;color:#6b7280;text-transform:uppercase;letter-spacing:.5px">En rupture</div><div style="font-size:28px;font-weight:700;color:#111827"><?= $enRupture ?></div></div>
  </div>

  <div class="toolbar">
    <button id="btnAdd" class="btn btn-primary" type="button">+ Ajouter article</button>
    <input id="search" class="input" placeholder="Recherche (réf, désignation)">
    <select id="filtreCategorie" class="select"><option value="">Toutes catégories</option></select>
    <select id="filtreEtat" class="select"><option value="">Tous états</option><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select>
    <button id="btnEtiquettes" class="btn btn-soft" type="button">Imprimer étiquettes</button>
    <button id="btnScan" class="btn btn-soft" type="button">Scanner QR</button>
  </div>

  <div class="tableWrap">
    <table id="stockTable">
      <thead><tr><th>Référence</th><th>Désignation</th><th>Catégorie</th><th>Détails</th><th>Quantité</th><th>État</th><th>Actions</th></tr></thead>
      <tbody id="tbody">
      <?php if ($totalArticles === 0): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:#9ca3af;">Aucun article en stock — <button type="button" onclick="ouvrirModal()">Ajouter le premier article</button></td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</main>

<div id="modalBg" class="modal-bg"></div>

<div id="modalForm" class="modal">
  <h3>Ajouter / Modifier article</h3>
  <input type="hidden" id="id">
  <div class="grid">
    <div class="field"><label>Désignation*</label><input id="designation" class="input"></div>
    <div class="field"><label>Référence</label><input id="reference" class="input" placeholder="Auto-générée si vide"></div>
    <div class="field"><label>Catégorie</label><select id="categorie" class="select"><option value="papier">papier</option><option value="toner_noir">toner_noir</option><option value="toner_cyan">toner_cyan</option><option value="toner_magenta">toner_magenta</option><option value="toner_jaune">toner_jaune</option><option value="pc">pc</option><option value="ecran_lcd">ecran_lcd</option><option value="imprimante">imprimante</option><option value="piece_detachee">piece_detachee</option><option value="consommable">consommable</option><option value="autre">autre</option></select></div>
    <div class="field"><label>Marque</label><input id="marque" class="input"></div>
    <div class="field"><label>Quantité initiale</label><input id="quantite" type="number" min="0" class="input" value="0"></div>
    <div class="field"><label>Seuil minimum</label><input id="quantite_min" type="number" min="0" class="input" value="5"></div>
    <div class="field"><label>Prix unitaire HT</label><input id="prix_unitaire_ht" type="number" step="0.01" min="0" class="input" value="0"></div>
    <div class="field"><label>État</label><select id="etat" class="select"><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select></div>
    <div class="field"><label>Emplacement</label><input id="emplacement" class="input"></div>
    <div class="field" style="grid-column:span 3"><label>Notes</label><textarea id="notes" class="input"></textarea></div>

    <div id="row_numero_serie" class="field champ-conditionnel" style="display:none"><label>Numéro de série</label><input id="numero_serie" class="input"></div>
    <div id="row_adresse_mac" class="field champ-conditionnel" style="display:none"><label>MAC</label><input id="adresse_mac" class="input"></div>
    <div id="row_cpu" class="field champ-conditionnel" style="display:none"><label>CPU</label><input id="cpu" class="input"></div>
    <div id="row_ram" class="field champ-conditionnel" style="display:none"><label>RAM</label><input id="ram" class="input"></div>
    <div id="row_stockage" class="field champ-conditionnel" style="display:none"><label>Stockage</label><input id="stockage" class="input"></div>
    <div id="row_modele_compatible" class="field champ-conditionnel" style="display:none"><label>Modèle compatible</label><input id="modele_compatible" class="input"></div>
    <div id="row_couleur_toner" class="field champ-conditionnel" style="display:none"><label>Couleur</label><input id="couleur_toner" readonly class="input"></div>
    <div id="row_rendement_pages" class="field champ-conditionnel" style="display:none"><label>Rendement pages</label><input id="rendement_pages" type="number" min="0" class="input"></div>
    <div id="row_taille_ecran" class="field champ-conditionnel" style="display:none"><label>Taille écran</label><input id="taille_ecran" class="input"></div>
    <div id="row_resolution" class="field champ-conditionnel" style="display:none"><label>Résolution</label><input id="resolution" class="input"></div>
    <div id="row_unite" class="field champ-conditionnel" style="display:none"><label>Unité</label><select id="unite" class="select"><option value="unite">unite</option><option value="carton">carton</option><option value="rame">rame</option></select></div>
    <div id="row_contenance" class="field champ-conditionnel" style="display:none"><label>Contenance</label><input id="contenance" type="number" min="0" class="input"></div>
    <div id="row_grammage" class="field champ-conditionnel" style="display:none"><label>Grammage</label><input id="grammage" class="input"></div>
  </div>
  <div style="margin-top:12px;display:flex;gap:8px;justify-content:flex-end">
    <button type="button" class="btn btn-soft" onclick="fermerModal()">Fermer</button>
    <button type="button" id="saveBtn" class="btn btn-primary">Enregistrer</button>
  </div>
</div>

<div id="modalScan" class="modal">
  <h3>Scanner QR</h3>
  <div class="scan-box">
    <video id="qrVideo" width="400" height="300" style="width:100%;height:100%;object-fit:cover"></video>
    <canvas id="qrCanvas" style="display:none"></canvas>
    <div class="corners"></div>
    <div class="scan-line"></div>
  </div>
  <div style="margin-top:10px;display:flex;justify-content:flex-end;gap:8px">
    <button type="button" class="btn btn-soft" id="manualSearch">Saisir manuellement</button>
    <button type="button" class="btn btn-soft" onclick="fermerModal()">Fermer</button>
  </div>
</div>

<div id="modalHist" class="modal">
  <h3>Historique mouvements</h3>
  <table><thead><tr><th>Date</th><th>Type</th><th>Quantité</th><th>Avant</th><th>Après</th><th>Motif</th></tr></thead><tbody id="histBody"></tbody></table>
  <div style="margin-top:10px;text-align:right"><button type="button" class="btn btn-soft" onclick="fermerModal()">Fermer</button></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script <?= csp_nonce() ?>>
const csrfToken = document.body.dataset.csrfToken || '';
const canWrite = document.body.dataset.canWrite === '1';
let items = <?= json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
const catColor = {'papier':'background:#dbeafe;color:#1d4ed8','toner_noir':'background:#1f2937;color:#fff','toner_cyan':'background:#cffafe;color:#0e7490','toner_magenta':'background:#fce7f3;color:#9d174d','toner_jaune':'background:#fef9c3;color:#854d0e','pc':'background:#ede9fe;color:#5b21b6','ecran_lcd':'background:#f3e8ff;color:#7e22ce','imprimante':'background:#dcfce7;color:#166534','piece_detachee':'background:#ffedd5;color:#9a3412','consommable':'background:#e0f2fe;color:#0369a1','autre':'background:#f3f4f6;color:#374151'};
const stateColor = {'neuf':'background:#d1fae5;color:#065f46','bon':'background:#dbeafe;color:#1e40af','use':'background:#fef3c7;color:#92400e','hs':'background:#fee2e2;color:#991b1b'};

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));}
function qtyCell(a){const q=Number(a.quantite||0),m=Math.max(1,Number(a.quantite_min||1));const pct=Math.min(100,Math.round((q/m)*100));const c=q===0?'#ef4444':(q<=m?'#f59e0b':'#10b981');return `<div style="display:flex;align-items:center;gap:8px;"><span style="font-weight:600;color:${c}">${q}</span><div style="width:60px;height:6px;background:#e5e7eb;border-radius:3px;"><div style="width:${pct}%;height:100%;background:${c};border-radius:3px;"></div></div></div>`;}
function details(a){if(a.categorie==='papier'){const q=Number(a.quantite||0),c=Number(a.contenance||0);return (a.unite==='carton'&&c>0)?`${q} cartons (${q*c} feuilles)`:(a.emplacement||'—');}if((a.categorie||'').startsWith('toner_')){const dot={'toner_noir':'#111827','toner_cyan':'#06b6d4','toner_magenta':'#db2777','toner_jaune':'#eab308'}[a.categorie]||'#6b7280';return `<span style="display:inline-block;width:8px;height:8px;border-radius:999px;background:${dot};margin-right:6px"></span>${esc(a.modele_compatible||'—')}`;}if(a.categorie==='pc')return esc([a.cpu,a.ram].filter(Boolean).join(' | ')||'—');if(a.categorie==='imprimante'||a.categorie==='ecran_lcd'){const sn=String(a.numero_serie||'');return esc(sn?sn.slice(0,12)+(sn.length>12?'...':''):'—');}return esc(a.emplacement||'—');}
function render(){const s=document.getElementById('search').value.toLowerCase().trim();const c=document.getElementById('filtreCategorie').value;const e=document.getElementById('filtreEtat').value;const rows=items.filter(a=>{if(c&&a.categorie!==c)return false;if(e&&a.etat!==e)return false;if(s){const hay=`${a.reference||''} ${a.designation||''}`.toLowerCase();if(!hay.includes(s))return false;}return true;});const tbody=document.getElementById('tbody');tbody.innerHTML=rows.map(a=>`<tr id="row-${a.id}"><td>${esc(a.reference||'')}</td><td>${esc(a.designation||'')}</td><td><span class="badge" style="${catColor[a.categorie]||catColor.autre}">${esc(a.categorie||'')}</span></td><td>${details(a)}</td><td>${qtyCell(a)}</td><td><span class="badge" style="${stateColor[a.etat]||stateColor.neuf}">${esc((a.etat||'neuf').toUpperCase())}</span></td><td><div class="menu"><button type="button" class="btn btn-soft" data-menu>⋮</button><div class="menu-items"><button data-edit="${a.id}">Modifier</button><button data-in="${a.id}">Entrée stock</button><button data-out="${a.id}">Sortie stock</button><button data-label="${a.id}">Étiquette</button><button data-hist="${a.id}">Historique</button><button data-del="${a.id}" style="color:#ef4444">Supprimer</button></div></div></td></tr>`).join('') || `<tr><td colspan="7" style="text-align:center;padding:40px;color:#9ca3af;">Aucun article en stock — <button type="button" onclick="ouvrirModal()">Ajouter le premier article</button></td></tr>`;
  document.querySelectorAll('[data-menu]').forEach(b=>b.onclick=(ev)=>{ev.stopPropagation();document.querySelectorAll('.menu-items').forEach(m=>m.style.display='none');b.nextElementSibling.style.display='block';});
  bindActions();
}
function bindActions(){
  document.querySelectorAll('[data-edit]').forEach(b=>b.onclick=()=>openEdit(Number(b.dataset.edit)));
  document.querySelectorAll('[data-del]').forEach(b=>b.onclick=()=>removeItem(Number(b.dataset.del)));
  document.querySelectorAll('[data-in]').forEach(b=>b.onclick=()=>moveStock(Number(b.dataset.in),'entree'));
  document.querySelectorAll('[data-out]').forEach(b=>b.onclick=()=>moveStock(Number(b.dataset.out),'sortie'));
  document.querySelectorAll('[data-hist]').forEach(b=>b.onclick=()=>showHistorique(Number(b.dataset.hist)));
  document.querySelectorAll('[data-label]').forEach(b=>b.onclick=()=>window.open('/public/stock_etiquettes.php?stock_id='+Number(b.dataset.label),'_blank'));
}
document.addEventListener('click',()=>document.querySelectorAll('.menu-items').forEach(m=>m.style.display='none'));

function ouvrirModal(){if(!canWrite)return;document.getElementById('modalBg').style.display='block';document.getElementById('modalForm').style.display='block';}
function fermerModal(){document.getElementById('modalBg').style.display='none';document.querySelectorAll('.modal').forEach(m=>m.style.display='none');stopScan();}
window.ouvrirModal = ouvrirModal; window.fermerModal = fermerModal;

function openEdit(id){if(!canWrite)return;const a=items.find(x=>Number(x.id)===id);if(!a)return;ouvrirModal();['id','designation','reference','categorie','marque','quantite','quantite_min','prix_unitaire_ht','etat','emplacement','notes','numero_serie','adresse_mac','cpu','ram','stockage','modele_compatible','couleur_toner','rendement_pages','taille_ecran','resolution','unite','contenance','grammage'].forEach(k=>{const el=document.getElementById(k);if(el)el.value=a[k]??'';});onCategorieChange();}

const champsCat = {'pc':['numero_serie','adresse_mac','cpu','ram','stockage'],'imprimante':['numero_serie','modele_compatible'],'ecran_lcd':['numero_serie','taille_ecran','resolution'],'toner_noir':['couleur_toner','modele_compatible','rendement_pages'],'toner_cyan':['couleur_toner','modele_compatible','rendement_pages'],'toner_magenta':['couleur_toner','modele_compatible','rendement_pages'],'toner_jaune':['couleur_toner','modele_compatible','rendement_pages'],'papier':['unite','contenance','grammage']};
const couleursAuto = {'toner_noir':'Noir','toner_cyan':'Cyan','toner_magenta':'Magenta','toner_jaune':'Jaune'};
function onCategorieChange(){const c=document.getElementById('categorie').value;document.querySelectorAll('.champ-conditionnel').forEach(el=>{el.style.display='none';const inp=el.querySelector('input,select,textarea');if(inp)inp.value='';});(champsCat[c]||[]).forEach(k=>{const row=document.getElementById('row_'+k);if(row)row.style.display='block';});if(couleursAuto[c])document.getElementById('couleur_toner').value=couleursAuto[c];if(c==='papier'){document.getElementById('unite').value='carton';document.getElementById('contenance').value=2500;}}
document.getElementById('categorie').addEventListener('change', onCategorieChange);

async function saveItem(){if(!canWrite)return;const payload={};['id','designation','reference','categorie','marque','quantite','quantite_min','prix_unitaire_ht','unite','contenance','etat','emplacement','notes','numero_serie','adresse_mac','cpu','ram','stockage','modele_compatible','couleur_toner','rendement_pages','taille_ecran','resolution','grammage'].forEach(k=>payload[k]=document.getElementById(k)?.value??'');payload.csrf_token=csrfToken;const r=await fetch('/API/stock_save.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});const d=await r.json();if(!d.success){alert(d.message||'Erreur');return;}location.reload();}
document.getElementById('saveBtn').addEventListener('click', saveItem);

async function removeItem(stock_id){if(!canWrite||!confirm('Supprimer cet article ?'))return;const r=await fetch('/API/stock_delete.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({stock_id,csrf_token:csrfToken})});const d=await r.json();if(!d.success){alert(d.message||'Erreur');return;}location.reload();}
async function moveStock(stock_id,type){if(!canWrite)return;const quantite=parseInt(prompt('Quantité', '1')||'0',10);if(!quantite||quantite<=0)return;const motif=prompt('Motif','')||'';const reference_doc=prompt('Référence document','')||'';const r=await fetch('/API/stock_mouvement.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json'},body:JSON.stringify({stock_id,type,quantite,motif,reference_doc,csrf_token:csrfToken})});const d=await r.json();if(!d.success){alert(d.message||'Erreur');return;}location.reload();}
async function showHistorique(stock_id){const r=await fetch('/API/stock_historique.php?stock_id='+stock_id,{credentials:'include'});const d=await r.json();const rows=Array.isArray(d)?d:(d.items||[]);document.getElementById('histBody').innerHTML=rows.map(x=>`<tr><td>${esc(x.created_at||'')}</td><td>${esc(x.type_mouvement||'')}</td><td>${esc(x.quantite||'')}</td><td>${esc(x.quantite_avant||'')}</td><td>${esc(x.quantite_apres||'')}</td><td>${esc(x.motif||'')}</td></tr>`).join('')||'<tr><td colspan="6">Aucun mouvement</td></tr>';document.getElementById('modalBg').style.display='block';document.getElementById('modalHist').style.display='block';}

let stream=null,scanning=false;
async function startScan(){document.getElementById('modalBg').style.display='block';document.getElementById('modalScan').style.display='block';stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:400,height:300}});const video=document.getElementById('qrVideo');video.srcObject=stream;await video.play();scanning=true;requestAnimationFrame(scanFrame);}
function stopScan(){scanning=false;if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}const v=document.getElementById('qrVideo');if(v)v.srcObject=null;}
function scanFrame(){if(!scanning||typeof jsQR==='undefined')return;const video=document.getElementById('qrVideo'),canvas=document.getElementById('qrCanvas'),ctx=canvas.getContext('2d');if(video.readyState===video.HAVE_ENOUGH_DATA){canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0,canvas.width,canvas.height);const img=ctx.getImageData(0,0,canvas.width,canvas.height);const code=jsQR(img.data,img.width,img.height,{inversionAttempts:'dontInvert'});if(code){document.getElementById('search').value=code.data;render();const tr=[...document.querySelectorAll('#tbody tr')].find(t=>(t.children[0]?.textContent||'').trim()===code.data);if(tr){tr.style.background='#dcfce7';setTimeout(()=>tr.style.background='',2000);}fermerModal();return;}}requestAnimationFrame(scanFrame);}

document.getElementById('btnAdd').addEventListener('click', ()=>ouvrirModal());
document.getElementById('btnScan').addEventListener('click', ()=>startScan());
document.getElementById('manualSearch').addEventListener('click', ()=>{fermerModal();document.getElementById('search').focus();});
document.getElementById('btnEtiquettes').addEventListener('click', ()=>window.open('/public/stock_etiquettes.php?all=1','_blank'));
document.getElementById('search').addEventListener('input', render);
document.getElementById('filtreCategorie').addEventListener('change', render);
document.getElementById('filtreEtat').addEventListener('change', render);
document.getElementById('modalBg').addEventListener('click', fermerModal);

const cats = [...new Set(items.map(x=>x.categorie).filter(Boolean))];
document.getElementById('filtreCategorie').innerHTML += cats.map(c=>`<option value="${esc(c)}">${esc(c)}</option>`).join('');
render();
</script>
</body>
</html>
<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';

authorize_page('stock', []);
ensureCsrfToken();

$emploi = (string)($_SESSION['emploi'] ?? '');
$allowedRead = ['Admin', 'Dirigeant', 'Secrétaire', 'Livreur', 'Technicien'];
if (!in_array($emploi, $allowedRead, true)) {
    http_response_code(403);
    exit('Accès refusé');
}
$canWrite = in_array($emploi, ['Admin', 'Dirigeant', 'Secrétaire'], true);

$pdo = getPdo();
$stmt = $pdo->prepare("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation");
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$kpiTotal = 0;
$kpiValue = 0.0;
$kpiAlert = 0;
$kpiRupture = 0;
foreach ($articles as $a) {
    $kpiTotal++;
    $q = (int)($a['quantite'] ?? 0);
    $min = (int)($a['quantite_min'] ?? 0);
    $kpiValue += $q * (float)($a['prix_unitaire_ht'] ?? 0);
    if ($q <= 0) {
        $kpiRupture++;
    } elseif ($q < $min) {
        $kpiAlert++;
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <title>Stock - CCComputer</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body { background:#f8fafc; }
    .wrap { padding:14px; }
    .toolbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
    .kpis { display:flex; gap:16px; flex-wrap:wrap; margin-bottom:10px; }
    .kpis b { margin-left:4px; }
    .input, .select, .btn { height:34px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; padding:0 10px; }
    .btn { cursor:pointer; }
    .table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:auto; }
    table { width:100%; border-collapse:collapse; }
    th, td { padding:8px; border-bottom:1px solid #e2e8f0; text-align:left; font-size:13px; }
    .pill { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2ff; font-size:11px; }
    .muted { color:#64748b; }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap" data-can-write="<?= $canWrite ? '1' : '0' ?>">
  <h1>Stock</h1>

  <div class="kpis">
    <div>Total articles <b><?= (int)$kpiTotal ?></b></div>
    <div>Valeur stock HT <b><?= number_format($kpiValue, 2, ',', ' ') ?> €</b></div>
    <div>En alerte <b><?= (int)$kpiAlert ?></b></div>
    <div>En rupture <b><?= (int)$kpiRupture ?></b></div>
  </div>

  <div class="toolbar">
    <input id="q" class="input" placeholder="Recherche (réf, désignation, CPU...)">
    <select id="fCategorie" class="select"><option value="">Toutes catégories</option></select>
    <select id="fEtat" class="select">
      <option value="">Tous états</option>
      <option value="neuf">Neuf</option>
      <option value="bon">Bon</option>
      <option value="use">Usé</option>
      <option value="hs">HS</option>
    </select>
    <button id="btnPrint" class="btn" type="button">Imprimer étiquettes</button>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
      <tr>
        <th>Référence</th>
        <th>Désignation</th>
        <th>Catégorie</th>
        <th>Détails</th>
        <th>Quantité</th>
        <th>État</th>
      </tr>
      </thead>
      <tbody id="tb"></tbody>
    </table>
  </div>
</main>

<script <?= csp_nonce() ?>>
(() => {
  let items = <?= json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
  const tb = document.getElementById('tb');
  const q = document.getElementById('q');
  const fCategorie = document.getElementById('fCategorie');
  const fEtat = document.getElementById('fEtat');

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));
  const cats = [...new Set(items.map(i => i.categorie).filter(Boolean))];
  fCategorie.innerHTML = '<option value="">Toutes catégories</option>' + cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');

  const details = (i) => {
    if (i.categorie === 'pc') return [i.cpu, i.ram, i.stockage].filter(Boolean).join(' | ');
    if (i.categorie === 'imprimante') return [i.modele_compatible, i.numero_serie].filter(Boolean).join(' | ');
    if ((i.categorie || '').startsWith('toner_')) return [i.couleur_toner, i.modele_compatible, i.rendement_pages ? `${i.rendement_pages} pages` : ''].filter(Boolean).join(' | ');
    if (i.categorie === 'ecran_lcd') return [i.taille_ecran, i.resolution].filter(Boolean).join(' | ');
    if (i.categorie === 'papier') {
      const qte = Number(i.quantite || 0);
      const cont = Number(i.contenance || 0);
      return cont > 0 ? `${qte} cartons (${qte * cont} feuilles)` : `${qte} unités`;
    }
    return i.modele_compatible || '';
  };

  const render = () => {
    const term = q.value.trim().toLowerCase();
    const cat = fCategorie.value;
    const etat = fEtat.value;
    const rows = items.filter((i) => {
      if (cat && i.categorie !== cat) return false;
      if (etat && i.etat !== etat) return false;
      if (!term) return true;
      const hay = `${i.reference || ''} ${i.designation || ''} ${i.numero_serie || ''} ${i.adresse_mac || ''} ${i.cpu || ''}`.toLowerCase();
      return hay.includes(term);
    });

    tb.innerHTML = rows.map((i) => `
      <tr>
        <td>${esc(i.reference || '')}</td>
        <td>${esc(i.designation || '')}</td>
        <td><span class="pill">${esc(i.categorie || '')}</span></td>
        <td>${esc(details(i))}</td>
        <td>${esc(i.quantite ?? 0)}</td>
        <td>${esc((i.etat || 'neuf').toUpperCase())}</td>
      </tr>
    `).join('') || '<tr><td colspan="6" class="muted">Aucun article</td></tr>';
  };

  q.addEventListener('input', render);
  fCategorie.addEventListener('change', render);
  fEtat.addEventListener('change', render);
  document.getElementById('btnPrint').addEventListener('click', () => {
    window.open('/public/stock_etiquettes.php?all=1', '_blank');
  });

  const refreshCategories = () => {
    const cats = [...new Set(items.map(i => i.categorie).filter(Boolean))];
    fCategorie.innerHTML = '<option value="">Toutes catégories</option>' + cats.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
  };

  const loadItems = async () => {
    try {
      const r = await fetch('/API/stock_items.php', { credentials: 'include' });
      const d = await r.json();
      if (d && d.ok && Array.isArray(d.items) && d.items.length) {
        items = d.items;
      }
    } catch (e) {
      // Fallback silencieux sur les données PHP déjà chargées
    }
    refreshCategories();
    render();
  };

  loadItems();
})();
</script>
</body>
</html>
<?php __halt_compiler(); ?>

<?php
/* duplicate block starts - strict_types removed */
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';

authorize_page('stock', []);
ensureCsrfToken();

$emploi = (string)($_SESSION['emploi'] ?? '');
$allowedRead = ['Admin', 'Dirigeant', 'Secrétaire', 'Livreur', 'Technicien'];
if (!in_array($emploi, $allowedRead, true)) {
    http_response_code(403);
    exit('Accès refusé');
}
$canWrite = in_array($emploi, ['Admin', 'Dirigeant', 'Secrétaire'], true);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashWarning = $_SESSION['flash_warning'] ?? null;
unset($_SESSION['flash_warning']);

$totalPapier = 0;
$totalToners = 0;
$totalLCD = 0;
$totalPC = 0;

$pdo = getPdo();
$stmt = $pdo->prepare("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation");
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$kpiTotal = 0;
$kpiValue = 0.0;
$kpiAlert = 0;
$kpiRupture = 0;
foreach ($articles as $a) {
    $kpiTotal++;
    $q = (int)($a['quantite'] ?? 0);
    $min = (int)($a['quantite_min'] ?? 0);
    $kpiValue += $q * (float)($a['prix_unitaire_ht'] ?? 0);
    if ($q <= 0) {
        $kpiRupture++;
    } elseif ($q < $min) {
        $kpiAlert++;
    }
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <title>Stock - CCComputer</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main data-can-write="<?= $canWrite ? '1' : '0' ?>" style="padding:14px">
  <h1>Stock</h1>

  <?php if ($flash): ?><div><?= h((string)$flash) ?></div><?php endif; ?>
  <?php if ($flashWarning): ?><div><?= h((string)$flashWarning) ?></div><?php endif; ?>

  <div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">
    <div>Total articles: <strong><?= (int)$kpiTotal ?></strong></div>
    <div>Valeur stock HT: <strong><?= number_format($kpiValue, 2, ',', ' ') ?> €</strong></div>
    <div>En alerte: <strong><?= (int)$kpiAlert ?></strong></div>
    <div>En rupture: <strong><?= (int)$kpiRupture ?></strong></div>
  </div>

  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <button id="btnAdd" type="button">+ Ajouter article</button>
    <input id="q" placeholder="Recherche">
    <select id="fCategorie"><option value="">Filtre catégorie</option></select>
    <select id="fEtat">
      <option value="">Filtre état</option>
      <option value="neuf">Neuf</option>
      <option value="bon">Bon</option>
      <option value="use">Usé</option>
      <option value="hs">HS</option>
    </select>
    <button id="btnPrint" type="button">Imprimer étiquettes</button>
    <button id="btnScan" type="button">Scanner QR</button>
  </div>

  <table border="1" cellpadding="6" cellspacing="0" width="100%">
    <thead>
      <tr>
        <th>Référence</th>
        <th>Désignation</th>
        <th>Catégorie</th>
        <th>Détails</th>
        <th>Quantité</th>
        <th>État</th>
      </tr>
    </thead>
    <tbody id="tb"></tbody>
  </table>
</main>

<script <?= csp_nonce() ?>>
(() => {
  const items = <?= json_encode($articles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
  const tb = document.getElementById('tb');
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  tb.innerHTML = items.map((i) => `
    <tr>
      <td>${esc(i.reference || '')}</td>
      <td>${esc(i.designation || '')}</td>
      <td>${esc(i.categorie || '')}</td>
      <td>${esc(i.modele_compatible || '')}</td>
      <td>${esc(i.quantite || 0)}</td>
      <td>${esc(i.etat || 'neuf')}</td>
    </tr>
  `).join('') || '<tr><td colspan="6"><em>Aucun article</em></td></tr>';
})();
</script>
</body>
</html>
function details(i){if(i.categorie==='pc')return [i.cpu,i.ram,i.stockage].filter(Boolean).join(' | ');if(i.categorie==='imprimante')return [i.modele_compatible,i.numero_serie].filter(Boolean).join(' | ');if((i.categorie||'').startsWith('toner_'))return `${i.couleur_toner||i.categorie.replace('toner_','')} | ${i.modele_compatible||''}`;if(i.categorie==='papier'){const q=Number(i.quantite||0),c=Number(i.contenance||0);return c>0?`${q} cartons (${q*c} feuilles)`:`${q} unités`;}if(i.categorie==='ecran_lcd')return [i.taille_ecran,i.resolution].filter(Boolean).join(' | ');return i.modele_compatible||'';}
function state(i){const q=Number(i.quantite||0),m=Number(i.quantite_min||0);return q<=0?'out':(q<m?'alert':'normal');}
function apply(){const q=document.getElementById('q').value.trim().toLowerCase(),c=document.getElementById('fCategorie').value,e=document.getElementById('fEtat').value,maxQ=Number(document.getElementById('qRange').value||1000),s=(document.querySelector('input[name="stockState"]:checked')||{}).value||'all';filtered=items.filter(i=>{if(c&&i.categorie!==c)return false;if(e&&i.etat!==e)return false;if(Number(i.quantite||0)>maxQ)return false;if(s!=='all'&&state(i)!==s)return false;if(q){const h=`${i.reference||''} ${i.designation||''} ${i.numero_serie||''} ${i.adresse_mac||''} ${i.cpu||''}`.toLowerCase();if(!h.includes(q))return false;}return true;});filtered.sort((a,b)=>{const va=a[sortKey]??'',vb=b[sortKey]??'';const na=Number(va),nb=Number(vb);if(!Number.isNaN(na)&&!Number.isNaN(nb))return sortDir==='asc'?na-nb:nb-na;return sortDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));});page=1;render();}
function render(){const tb=document.getElementById('tb');const rows=filtered.slice((page-1)*size,page*size);tb.innerHTML=rows.map(i=>{const q=Number(i.quantite||0),m=Number(i.quantite_min||0),pct=m>0?Math.min(100,Math.round((q/m)*100)):100,color=q<=0?'#dc2626':(q<m?'#f59e0b':'#16a34a');return `<tr data-id="${i.id}"><td>${esc(i.reference||'')}</td><td>${esc(i.designation||'')}</td><td><span class="badge" style="${badge[i.categorie]||badge.autre}">${esc(catMap[i.categorie]||i.categorie||'')}</span></td><td>${esc(details(i))}</td><td>${q}<div class="qprog"><div class="qbar" style="width:${pct}%;background:${color}"></div></div></td><td>${esc((i.etat||'neuf').toUpperCase())}</td><td><div class="menuWrap"><button class="menuBtn">⋮</button><div class="menu"><button onclick="openEdit(${i.id})" ${canWrite?'':'disabled'}>Modifier</button><button onclick="moveItem(${i.id},'entree')" ${canWrite?'':'disabled'}>Entrée</button><button onclick="moveItem(${i.id},'sortie')" ${canWrite?'':'disabled'}>Sortie</button><button onclick="showHist(${i.id})">Historique</button><button onclick="delItem(${i.id})" ${canWrite?'':'disabled'}>Supprimer</button></div></div></td></tr>`;}).join('')||'<tr><td colspan="7"><em>Aucun article</em></td></tr>';const p=Math.max(1,Math.ceil(filtered.length/size));document.getElementById('pg').textContent=`${page}/${p}`;document.getElementById('prev').disabled=page<=1;document.getElementById('next').disabled=page>=p;}
function openModal(id){editId=id||0;document.getElementById('modalBg').style.display='block';document.getElementById('editModal').style.display='block';if(!id){document.querySelectorAll('#editModal input,#editModal textarea').forEach(el=>el.value='');document.getElementById('f_quantite').value='0';document.getElementById('f_quantite_min').value='5';document.getElementById('f_prix_unitaire_ht').value='0';}else{const it=items.find(x=>Number(x.id)===Number(id));if(it){Object.keys(it).forEach(k=>{const el=document.getElementById('f_'+k);if(el)el.value=it[k]??'';});}}}
function closeAll(){document.getElementById('modalBg').style.display='none';document.querySelectorAll('.modal').forEach(m=>m.style.display='none');stopScan();}
async function save(){if(!canWrite){toast('Lecture seule','warn');return;}const payload={csrf_token:csrf};if(editId)payload.id=editId;['reference','designation','categorie','marque','quantite','quantite_min','prix_unitaire_ht','etat','emplacement','notes'].forEach(k=>{const el=document.getElementById('f_'+k);payload[k]=el?el.value:'';});const r=await fetch('/API/stock_save.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)});const d=await r.json();if(!d.ok){toast(d.error||'Erreur','bad');return;}await reload();closeAll();toast('Enregistré','ok');}
async function reload(){const r=await fetch('/API/stock_items.php?actif=1',{credentials:'include'});const d=await r.json();items=(d&&d.ok&&Array.isArray(d.items))?d.items:[];apply();}
window.openEdit=id=>openModal(id);window.moveItem=async(id,type)=>{if(!canWrite){toast('Lecture seule','warn');return;}const q=parseInt(prompt('Quantité','1')||'0',10);if(!q||q<=0)return;const motif=prompt('Motif','')||'';const ref=prompt('Réf doc','')||'';const r=await fetch('/API/stock_mouvement.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({stock_id:id,type_mouvement:type,quantite:q,motif,reference_doc:ref})});const d=await r.json();if(!d.ok){toast(d.error||'Erreur','bad');return;}await reload();toast('Mouvement enregistré','ok');};
window.showHist=async(id)=>{const r=await fetch('/API/stock_mouvement.php?stock_id='+encodeURIComponent(id),{credentials:'include'});const d=await r.json();if(!d.ok){toast(d.error||'Erreur','bad');return;}document.getElementById('histBody').innerHTML=(d.items||[]).map(m=>`<tr><td>${esc(m.created_at||'')}</td><td>${esc(m.type_mouvement||'')}</td><td>${esc(m.quantite_avant||'')}</td><td>${esc(m.quantite_apres||'')}</td><td>${esc(m.motif||'')}</td></tr>`).join('')||'<tr><td colspan="5"><em>Aucun</em></td></tr>';document.getElementById('modalBg').style.display='block';document.getElementById('histModal').style.display='block';};
window.delItem=async(id)=>{if(!canWrite){toast('Lecture seule','warn');return;}if(!confirm('Désactiver cet article ?'))return;const r=await fetch('/API/stock_delete.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id})});const d=await r.json();if(!d.ok){toast(d.error||'Erreur','bad');return;}await reload();toast('Article désactivé','ok');};
let stream=null,scanning=false;const video=document.getElementById('qrVideo'),canvas=document.getElementById('qrCanvas'),ctx=canvas.getContext('2d');async function startScan(){stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment',width:400,height:300}});video.srcObject=stream;await video.play();scanning=true;requestAnimationFrame(scanFrame);}function stopScan(){scanning=false;if(stream){stream.getTracks().forEach(t=>t.stop());stream=null;}video.srcObject=null;}function scanFrame(){if(!scanning)return;if(video.readyState===video.HAVE_ENOUGH_DATA){canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0,canvas.width,canvas.height);const img=ctx.getImageData(0,0,canvas.width,canvas.height);const code=jsQR(img.data,img.width,img.height,{inversionAttempts:'dontInvert'});if(code){document.getElementById('q').value=code.data;apply();closeAll();toast('QR détecté','ok');return;}}requestAnimationFrame(scanFrame);}
document.getElementById('btnAdd').addEventListener('click',()=>openModal(0));document.getElementById('mClose').addEventListener('click',closeAll);document.getElementById('mSave').addEventListener('click',save);document.getElementById('modalBg').addEventListener('click',closeAll);document.getElementById('histClose').addEventListener('click',closeAll);document.getElementById('scanClose').addEventListener('click',closeAll);document.getElementById('btnPrint').addEventListener('click',()=>window.open('/public/stock_etiquettes.php?all=1','_blank'));document.getElementById('btnScan').addEventListener('click',async()=>{document.getElementById('modalBg').style.display='block';document.getElementById('scanModal').style.display='block';await startScan();});
document.querySelectorAll('.tbl th[data-sort]').forEach(th=>th.addEventListener('click',()=>{const k=th.dataset.sort;sortDir=(sortKey===k&&sortDir==='asc')?'desc':'asc';sortKey=k;apply();}));
document.getElementById('q').addEventListener('input',apply);document.getElementById('fCategorie').addEventListener('change',apply);document.getElementById('fEtat').addEventListener('change',apply);document.querySelectorAll('input[name="stockState"]').forEach(el=>el.addEventListener('change',apply));document.getElementById('qRange').addEventListener('input',()=>{document.getElementById('qRangeVal').textContent=document.getElementById('qRange').value;apply();});document.getElementById('prev').addEventListener('click',()=>{if(page>1){page--;render();}});document.getElementById('next').addEventListener('click',()=>{const p=Math.max(1,Math.ceil(filtered.length/size));if(page<p){page++;render();}});document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll();if(e.key.toLowerCase()==='n'){e.preventDefault();openModal(0);}if(e.key.toLowerCase()==='f'){e.preventDefault();document.getElementById('q').focus();}if(e.key.toLowerCase()==='s'){e.preventDefault();document.getElementById('btnScan').click();}});
if(!canWrite)document.getElementById('btnAdd').disabled=true;apply();
})();
</script>
</body>
</html>

<?php
/* duplicate block starts - strict_types removed */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';

authorize_page('stock', []);
ensureCsrfToken();

$emploi = (string)($_SESSION['emploi'] ?? '');
$allowedRead = ['Admin', 'Dirigeant', 'Secrétaire', 'Livreur', 'Technicien'];
if (!in_array($emploi, $allowedRead, true)) {
    http_response_code(403);
    exit('Accès refusé');
}
$canWrite = in_array($emploi, ['Admin', 'Dirigeant', 'Secrétaire'], true);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$flashWarning = $_SESSION['flash_warning'] ?? null;
unset($_SESSION['flash_warning']);
$totalPapier = 0;
$totalToners = 0;
$totalLCD = 0;
$totalPC = 0;

$pdo = getPdo();
$stmt = $pdo->prepare("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation");
$stmt->execute();
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$kpiTotal = 0;
$kpiValue = 0.0;
$kpiAlert = 0;
$kpiRupture = 0;

foreach ($articles as $a) {
    $kpiTotal++;
    $q = (int)($a['quantite'] ?? 0);
    $min = (int)($a['quantite_min'] ?? 0);
    $price = (float)($a['prix_unitaire_ht'] ?? 0);
    $kpiValue += $q * $price;
    if ($q <= 0) {
        $kpiRupture++;
    } elseif ($q < $min) {
        $kpiAlert++;
    }

    $cat = (string)($a['categorie'] ?? '');
    if ($cat === 'papier') $totalPapier += $q;
    if (str_starts_with($cat, 'toner_')) $totalToners += $q;
    if ($cat === 'ecran_lcd') $totalLCD += $q;
    if ($cat === 'pc') $totalPC += $q;
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <title>Stock - CCComputer</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body{background:#f8fafc}.wrap{padding:14px}.bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
    .btn,.in,.sel{height:36px;border:1px solid #d1d5db;border-radius:8px;background:#fff;padding:0 10px}.btn{cursor:pointer}.btnP{background:#2563eb;color:#fff;border-color:#2563eb}
    .kpi{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:8px;margin-bottom:10px}.k{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px}
    .k b{display:block;font-size:20px}.layout{display:grid;grid-template-columns:240px 1fr;gap:10px}.side,.main{background:#fff;border:1px solid #e5e7eb;border-radius:8px}
    .side{padding:10px}.main{padding:10px;overflow:auto}.tbl{width:100%;border-collapse:collapse}.tbl th,.tbl td{padding:8px;border-bottom:1px solid #e5e7eb;font-size:13px}
    .tbl th{cursor:pointer;user-select:none}.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
    .qprog{width:120px;height:8px;background:#e2e8f0;border-radius:99px;overflow:hidden}.qbar{height:100%}
    .menuWrap{position:relative}.menuBtn{border:none;background:transparent;cursor:pointer;font-size:18px}.menu{display:none;position:absolute;right:0;top:22px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;min-width:140px;z-index:10}
    .menu button{display:block;width:100%;text-align:left;border:none;background:transparent;padding:6px 8px;cursor:pointer}.menu button:hover{background:#f1f5f9}
    .menuWrap:hover .menu{display:block}.paging{display:flex;justify-content:flex-end;gap:8px;align-items:center;margin-top:8px}
    .modalBg{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;z-index:50}.modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);width:min(880px,95vw);max-height:90vh;overflow:auto;background:#fff;border-radius:10px;border:1px solid #e5e7eb;display:none;z-index:51}
    .mHead,.mFoot{padding:10px;border-bottom:1px solid #e5e7eb}.mFoot{border-top:1px solid #e5e7eb;border-bottom:none;display:flex;justify-content:space-between}.mBody{padding:10px}.grid{display:grid;grid-template-columns:repeat(3,minmax(140px,1fr));gap:8px}
    .field{display:flex;flex-direction:column;gap:4px}.field label{font-size:12px;color:#64748b}.toastWrap{position:fixed;right:12px;bottom:12px;display:flex;flex-direction:column;gap:8px;z-index:60}
    .toast{color:#fff;padding:10px;border-radius:8px;min-width:220px}.ok{background:#16a34a}.bad{background:#dc2626}.warn{background:#f59e0b}
    .scanBox{position:relative;width:400px;height:300px;background:#000;margin:auto;border-radius:8px;overflow:hidden}.scanLine{position:absolute;left:0;right:0;height:2px;background:#22c55e;animation:scan 2s linear infinite}
    @keyframes scan{0%{top:0}100%{top:298px}} .hi{animation:hi 2s}@keyframes hi{0%{background:#bbf7d0}100%{background:transparent}}
    @media(max-width:980px){.layout{grid-template-columns:1fr}.kpi{grid-template-columns:repeat(2,minmax(120px,1fr))}}
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap" data-can-write="<?= $canWrite ? '1' : '0' ?>">
  <h1 style="margin:0 0 8px 0">Stock</h1>

  <div class="bar">
    <button id="btnAdd" class="btn btnP">+ Ajouter article</button>
    <input id="q" class="in" style="min-width:240px" placeholder="Recherche">
    <select id="fCategorie" class="sel"><option value="">Filtre catégorie</option></select>
    <select id="fEtat" class="sel"><option value="">Filtre état</option><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select>
    <button id="btnPrint" class="btn">Imprimer étiquettes</button>
    <button id="btnScan" class="btn">Scanner QR</button>
  </div>

  <?php if ($flash): ?><div style="margin-bottom:8px;color:#065f46"><?= h((string)$flash) ?></div><?php endif; ?>
  <?php if ($flashWarning): ?><div style="margin-bottom:8px;color:#92400e"><?= h((string)$flashWarning) ?></div><?php endif; ?>

  <div class="kpi">
    <div class="k"><span>Total articles</span><b id="kTot"><?= (int)$kpiTotal ?></b></div>
    <div class="k"><span>Valeur stock HT</span><b id="kVal"><?= number_format($kpiValue,2,',',' ') ?> €</b></div>
    <div class="k"><span>En alerte</span><b id="kAlert"><?= (int)$kpiAlert ?></b></div>
    <div class="k"><span>En rupture</span><b id="kOut"><?= (int)$kpiRupture ?></b></div>
  </div>

  <div class="layout">
    <aside class="side">
      <h3 style="margin:4px 0">Filtres</h3>
      <div id="etatChecks">
        <label><input type="checkbox" value="neuf" checked> Neuf</label><br>
        <label><input type="checkbox" value="bon" checked> Bon</label><br>
        <label><input type="checkbox" value="use" checked> Usé</label><br>
        <label><input type="checkbox" value="hs" checked> HS</label>
      </div><hr>
      <div>
        <label><input type="radio" name="stockState" value="all" checked> Tout</label><br>
        <label><input type="radio" name="stockState" value="alert"> Alerte</label><br>
        <label><input type="radio" name="stockState" value="out"> Rupture</label><br>
        <label><input type="radio" name="stockState" value="normal"> Normal</label>
      </div><hr>
      <label>Quantité max affichée</label>
      <input id="qRange" type="range" min="0" max="1000" value="1000" style="width:100%">
      <div><small id="qRangeVal">1000</small></div>
    </aside>

    <section class="main">
      <table class="tbl">
        <thead>
          <tr>
            <th data-sort="reference">Référence</th>
            <th data-sort="designation">Désignation</th>
            <th data-sort="categorie">Catégorie</th>
            <th>Détails</th>
            <th data-sort="quantite">Quantité</th>
            <th data-sort="etat">État</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="tb"></tbody>
      </table>
      <div class="paging"><button id="prev" class="btn">Préc.</button><span id="pg"></span><button id="next" class="btn">Suiv.</button></div>
    </section>
  </div>
</main>

<div id="modalBg" class="modalBg"></div>
<div id="editModal" class="modal">
  <div class="mHead"><strong id="mTitle">Ajouter / Modifier article</strong></div>
  <div class="mBody">
    <div class="grid">
      <div class="field"><label>Référence</label><input id="f_reference" class="in" placeholder="Auto si vide"></div>
      <div class="field"><label>Désignation</label><input id="f_designation" class="in"></div>
      <div class="field"><label>Catégorie</label><select id="f_categorie" class="sel"></select></div>
      <div class="field"><label>Marque</label><input id="f_marque" class="in"></div>
      <div class="field"><label>Quantité</label><input id="f_quantite" type="number" min="0" class="in" value="0"></div>
      <div class="field"><label>Seuil min</label><input id="f_quantite_min" type="number" min="0" class="in" value="5"></div>
      <div class="field"><label>Prix HT</label><input id="f_prix_unitaire_ht" type="number" step="0.01" min="0" class="in" value="0"></div>
      <div class="field"><label>État</label><select id="f_etat" class="sel"><option value="neuf">Neuf</option><option value="bon">Bon</option><option value="use">Usé</option><option value="hs">HS</option></select></div>
      <div class="field"><label>Emplacement</label><input id="f_emplacement" class="in"></div>
      <div class="field" style="grid-column:span 3"><label>Notes</label><textarea id="f_notes" class="in" style="height:72px;padding:8px"></textarea></div>

      <div class="field tech" data-k="numero_serie"><label>N° série</label><input id="f_numero_serie" class="in"></div>
      <div class="field tech" data-k="adresse_mac"><label>Adresse MAC</label><input id="f_adresse_mac" class="in"></div>
      <div class="field tech" data-k="cpu"><label>CPU</label><input id="f_cpu" class="in"></div>
      <div class="field tech" data-k="ram"><label>RAM</label><input id="f_ram" class="in"></div>
      <div class="field tech" data-k="stockage"><label>Stockage</label><input id="f_stockage" class="in"></div>
      <div class="field tech" data-k="modele_compatible"><label>Modèle compatible</label><input id="f_modele_compatible" class="in"></div>
      <div class="field tech" data-k="couleur_toner"><label>Couleur toner</label><input id="f_couleur_toner" class="in" readonly></div>
      <div class="field tech" data-k="rendement_pages"><label>Rendement pages</label><input id="f_rendement_pages" type="number" min="0" class="in"></div>
      <div class="field tech" data-k="grammage"><label>Grammage</label><input id="f_grammage" class="in"></div>
      <div class="field tech" data-k="taille_ecran"><label>Taille écran</label><input id="f_taille_ecran" class="in"></div>
      <div class="field tech" data-k="resolution"><label>Résolution</label><input id="f_resolution" class="in"></div>

      <div class="field"><label>Unité</label><select id="f_unite" class="sel"><option value="unite">unité</option><option value="carton">carton</option><option value="rame">rame</option></select></div>
      <div class="field"><label>Contenance</label><input id="f_contenance" type="number" min="0" class="in"></div>
    </div>
  </div>
  <div class="mFoot"><button id="mClose" class="btn">Fermer</button><button id="mSave" class="btn btnP">Enregistrer</button></div>
</div>

<div id="scanModal" class="modal">
  <div class="mHead"><strong>Scanner un article</strong></div>
  <div class="mBody">
    <div style="display:flex;gap:8px;justify-content:center;margin-bottom:8px">
      <select id="camSel" class="sel"></select>
      <button id="manualSearch" class="btn">Saisir manuellement</button>
    </div>
    <div class="scanBox"><video id="qrVideo" width="400" height="300" style="width:100%;height:100%;object-fit:cover"></video><canvas id="qrCanvas" style="display:none"></canvas><div class="scanLine"></div></div>
  </div>
  <div class="mFoot"><span></span><button id="scanClose" class="btn">Fermer</button></div>
</div>

<div id="histModal" class="modal">
  <div class="mHead"><strong>Historique des mouvements</strong></div>
  <div class="mBody"><table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>Avant</th><th>Après</th><th>Motif</th></tr></thead><tbody id="histBody"></tbody></table></div>
  <div class="mFoot"><span></span><button id="histClose" class="btn">Fermer</button></div>
</div>

<div id="toasts" class="toastWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js"></script>
<script <?= csp_nonce() ?>>
(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]').content || '';
  const canWrite = document.querySelector('main').dataset.canWrite === '1';
  const raw = <?= json_encode($articles, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?> || [];
  const CATS = ['papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre'];
  const catMap = {'papier':'Papier','toner_noir':'Toner Noir','toner_cyan':'Toner Cyan','toner_magenta':'Toner Magenta','toner_jaune':'Toner Jaune','pc':'PC','ecran_lcd':'LCD','imprimante':'Imprimante','piece_detachee':'Pièce détachée','consommable':'Consommable','autre':'Autre'};
  const catBadge = {'papier':'background:#dbeafe;color:#1e3a8a','toner_noir':'background:#111827;color:#fff','toner_cyan':'background:#0891b2;color:#fff','toner_magenta':'background:#db2777;color:#fff','toner_jaune':'background:#fde047;color:#111827','pc':'background:#4f46e5;color:#fff','ecran_lcd':'background:#7c3aed;color:#fff','imprimante':'background:#166534;color:#fff','piece_detachee':'background:#ea580c;color:#fff','consommable':'background:#0f766e;color:#fff','autre':'background:#6b7280;color:#fff'};
  const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const eur = n => Number(n||0).toFixed(2)+' €';
  const toast=(m,t='ok')=>{const d=document.createElement('div');d.className='toast '+t;d.textContent=m;document.getElementById('toasts').appendChild(d);setTimeout(()=>d.remove(),3000);};
  let items=[...raw], filtered=[...raw], page=1, size=25, sortKey='designation', sortDir='asc', editId=0;

  document.getElementById('fCategorie').innerHTML = '<option value="">Filtre catégorie</option>'+CATS.map(c=>`<option value="${c}">${catMap[c]}</option>`).join('');
  document.getElementById('f_categorie').innerHTML = CATS.map(c=>`<option value="${c}">${catMap[c]}</option>`).join('');

  function details(i){
    if(i.categorie==='pc') return [i.cpu,i.ram,i.stockage].filter(Boolean).join(' | ');
    if(i.categorie==='imprimante'){const sn=String(i.numero_serie||''); return [i.modele_compatible, sn?('SN '+sn.slice(0,10)+(sn.length>10?'...':'')):'' ].filter(Boolean).join(' | ');}
    if((i.categorie||'').startsWith('toner_')){const c=(i.couleur_toner||i.categorie.replace('toner_','')).toLowerCase();const dot=`<span style="display:inline-block;width:10px;height:10px;border-radius:99px;background:${c==='noir'?'#111827':c==='cyan'?'#0891b2':c==='magenta'?'#db2777':'#fde047'};vertical-align:middle;margin-right:6px"></span>`;return `${dot}${esc(i.modele_compatible||'')} ${i.rendement_pages?('('+i.rendement_pages+' p.)'):''}`;}
    if(i.categorie==='papier'){const q=Number(i.quantite||0),c=Number(i.contenance||0);return `${i.format_papier||''} ${i.grammage||''} ${c>0?`${q} cartons (${q*c} feuilles)`:`${q} unités`}`;}
    if(i.categorie==='ecran_lcd') return [i.taille_ecran,i.resolution].filter(Boolean).join(' | ');
    return esc(i.modele_compatible||'');
  }
  function state(i){const q=Number(i.quantite||0),m=Number(i.quantite_min||0);return q<=0?'out':(q<m?'alert':'normal');}

  function applyFilters(){
    const q=document.getElementById('q').value.trim().toLowerCase();
    const c=document.getElementById('fCategorie').value;
    const e=document.getElementById('fEtat').value;
    const maxQ=Number(document.getElementById('qRange').value||1000);
    const etats=[...document.querySelectorAll('#etatChecks input:checked')].map(x=>x.value);
    const s=(document.querySelector('input[name="stockState"]:checked')||{}).value||'all';
    filtered = items.filter(i=>{
      if(c && i.categorie!==c) return false;
      if(e && i.etat!==e) return false;
      if(etats.length && !etats.includes(i.etat||'neuf')) return false;
      if(Number(i.quantite||0)>maxQ) return false;
      if(s!=='all' && state(i)!==s) return false;
      if(q){
        const hay=`${i.reference||''} ${i.designation||''} ${i.numero_serie||''} ${i.adresse_mac||''} ${i.cpu||''}`.toLowerCase();
        if(!hay.includes(q)) return false;
      }
      return true;
    });
    filtered.sort((a,b)=>{const va=a[sortKey]??'',vb=b[sortKey]??'';const na=Number(va),nb=Number(vb);if(!Number.isNaN(na)&&!Number.isNaN(nb)) return sortDir==='asc'?na-nb:nb-na;return sortDir==='asc'?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));});
    page=1; render();
  }

  function render(){
    const tb=document.getElementById('tb');
    const rows=filtered.slice((page-1)*size,page*size);
    tb.innerHTML = rows.map(i=>{
      const q=Number(i.quantite||0),m=Number(i.quantite_min||0),pct=m>0?Math.min(100,Math.round((q/m)*100)):100,color=q<=0?'#dc2626':(q<m?'#f59e0b':'#16a34a');
      const et=i.etat||'neuf';
      return `<tr data-id="${i.id}">
        <td>${esc(i.reference||'')}</td>
        <td>${esc(i.designation||'')}</td>
        <td><span class="badge" style="${catBadge[i.categorie]||catBadge.autre}">${esc(catMap[i.categorie]||i.categorie||'')}</span></td>
        <td>${details(i)}</td>
        <td>${q}<div class="qprog"><div class="qbar" style="width:${pct}%;background:${color}"></div></div></td>
        <td><span class="badge" style="${et==='neuf'?'background:#dcfce7;color:#166534':et==='bon'?'background:#dbeafe;color:#1e3a8a':et==='use'?'background:#fef3c7;color:#854d0e':'background:#fee2e2;color:#991b1b'}">${esc(et.toUpperCase())}</span></td>
        <td><div class="menuWrap"><button class="menuBtn">⋮</button><div class="menu">
          <button onclick="openEdit(${i.id})" ${canWrite?'':'disabled'}>✏️ Modifier</button>
          <button onclick="moveItem(${i.id},'entree')" ${canWrite?'':'disabled'}>➕ Entrée</button>
          <button onclick="moveItem(${i.id},'sortie')" ${canWrite?'':'disabled'}>➖ Sortie</button>
          <button onclick="window.open('/public/stock_etiquettes.php?stock_id=${i.id}','_blank')">🏷️ Étiquette</button>
          <button onclick="showHist(${i.id})">📋 Historique</button>
          <button onclick="delItem(${i.id})" ${canWrite?'':'disabled'}>🗑️ Supprimer</button>
        </div></div></td>
      </tr>`;
    }).join('') || '<tr><td colspan="7"><em>Aucun article</em></td></tr>';
    const pages=Math.max(1,Math.ceil(filtered.length/size)); document.getElementById('pg').textContent=`${page}/${pages}`;
    document.getElementById('prev').disabled=page<=1; document.getElementById('next').disabled=page>=pages;
  }

  function openModal(id){editId=id||0;document.getElementById('modalBg').style.display='block';document.getElementById('editModal').style.display='block';if(!id){document.querySelectorAll('#editModal input,#editModal textarea').forEach(el=>{if(el.id!=='f_quantite'&&el.id!=='f_quantite_min'&&el.id!=='f_prix_unitaire_ht')el.value='';});document.getElementById('f_quantite').value='0';document.getElementById('f_quantite_min').value='5';document.getElementById('f_prix_unitaire_ht').value='0';}else{const it=items.find(x=>Number(x.id)===Number(id));if(it){Object.keys(it).forEach(k=>{const el=document.getElementById('f_'+k);if(el)el.value=it[k]??'';});}}applyTech();}
  function closeAll(){document.getElementById('modalBg').style.display='none';document.querySelectorAll('.modal').forEach(m=>m.style.display='none');stopScan();}
  function applyTech(){
    const map={'pc':['numero_serie','adresse_mac','cpu','ram','stockage'],'ecran_lcd':['numero_serie','taille_ecran','resolution'],'imprimante':['numero_serie','modele_compatible'],'toner_noir':['couleur_toner','modele_compatible','rendement_pages'],'toner_cyan':['couleur_toner','modele_compatible','rendement_pages'],'toner_magenta':['couleur_toner','modele_compatible','rendement_pages'],'toner_jaune':['couleur_toner','modele_compatible','rendement_pages'],'papier':['grammage'],'piece_detachee':['numero_serie','modele_compatible'],'consommable':['modele_compatible'],'autre':[]};
    const cat=document.getElementById('f_categorie').value||'autre'; const allow=map[cat]||[];
    document.querySelectorAll('.tech').forEach(r=>{const k=r.dataset.k,inp=document.getElementById('f_'+k),show=allow.includes(k);r.style.display=show?'':'none';if(!show&&inp)inp.value='';});
    if(cat==='papier'){document.getElementById('f_unite').value='carton';document.getElementById('f_contenance').value='2500';}
    const t={'toner_noir':'Noir','toner_cyan':'Cyan','toner_magenta':'Magenta','toner_jaune':'Jaune'}; if(t[cat]) document.getElementById('f_couleur_toner').value=t[cat];
  }

  async function save(){
    if(!canWrite){toast('Lecture seule','warn');return;}
    const payload={csrf_token:csrf}; if(editId) payload.id=editId;
    ['reference','designation','categorie','marque','quantite','quantite_min','prix_unitaire_ht','etat','emplacement','notes','numero_serie','adresse_mac','cpu','ram','stockage','modele_compatible','couleur_toner','rendement_pages','grammage','taille_ecran','resolution','unite','contenance'].forEach(k=>{const el=document.getElementById('f_'+k); payload[k]=el?el.value:'';});
    const res=await fetch('/API/stock_save.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(payload)}); const d=await res.json();
    if(!d.ok){toast(d.error||'Erreur','bad');return;}
    await reloadItems(); closeAll(); toast(editId?'Article modifié':'Article ajouté','ok');
  }
  async function reloadItems(){const r=await fetch('/API/stock_items.php?actif=1',{credentials:'include'});const d=await r.json();items=(d&&d.ok&&Array.isArray(d.items))?d.items:[];applyFilters();}

  window.openEdit = (id)=>openModal(id);
  window.moveItem = async (id,type)=>{if(!canWrite){toast('Lecture seule','warn');return;} const q=parseInt(prompt('Quantité','1')||'0',10);if(!q||q<=0)return; const motif=prompt('Motif','')||''; const ref=prompt('Réf doc','')||''; const r=await fetch('/API/stock_mouvement.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({stock_id:id,type_mouvement:type,quantite:q,motif:motif,reference_doc:ref})}); const d=await r.json(); if(!d.ok){toast(d.error||'Erreur','bad');return;} toast('Mouvement enregistré','ok'); await reloadItems();};
  window.showHist = async (id)=>{const r=await fetch('/API/stock_mouvement.php?stock_id='+encodeURIComponent(id),{credentials:'include'}); const d=await r.json(); if(!d.ok){toast(d.error||'Erreur','bad');return;} document.getElementById('histBody').innerHTML=(d.items||[]).map(m=>`<tr><td>${esc(m.created_at||'')}</td><td>${esc(m.type_mouvement||'')}</td><td>${esc(m.quantite_avant||'')}</td><td>${esc(m.quantite_apres||'')}</td><td>${esc(m.motif||'')}</td></tr>`).join('')||'<tr><td colspan="5"><em>Aucun mouvement</em></td></tr>'; document.getElementById('modalBg').style.display='block'; document.getElementById('histModal').style.display='block';};
  window.delItem = async (id)=>{if(!canWrite){toast('Lecture seule','warn');return;} if(!confirm('Désactiver cet article ?'))return; const r=await fetch('/API/stock_delete.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id})}); const d=await r.json(); if(!d.ok){toast(d.error||'Erreur','bad');return;} toast('Article désactivé','ok'); await reloadItems();};

  // Scanner QR
  let stream=null,scanning=false; const video=document.getElementById('qrVideo'),canvas=document.getElementById('qrCanvas'),ctx=canvas.getContext('2d');
  async function listCams(){const dev=await navigator.mediaDevices.enumerateDevices(); const cams=dev.filter(x=>x.kind==='videoinput'); const sel=document.getElementById('camSel'); sel.innerHTML=cams.map((c,i)=>`<option value="${esc(c.deviceId)}">${esc(c.label||('Caméra '+(i+1)))}</option>`).join('');}
  async function startScan(deviceId=''){await listCams(); stream=await navigator.mediaDevices.getUserMedia({video:deviceId?{deviceId:{exact:deviceId},width:400,height:300}:{facingMode:'environment',width:400,height:300}}); video.srcObject=stream; await video.play(); scanning=true; requestAnimationFrame(scanFrame);}
  function stopScan(){scanning=false; if(stream){stream.getTracks().forEach(t=>t.stop()); stream=null;} video.srcObject=null;}
  function beep(){const ac=new (window.AudioContext||window.webkitAudioContext)(); const o=ac.createOscillator(); const g=ac.createGain(); o.connect(g); g.connect(ac.destination); o.frequency.value=880; o.start(); g.gain.exponentialRampToValueAtTime(0.0001, ac.currentTime+0.1); o.stop(ac.currentTime+0.1);}
  function rechercherArticle(ref){document.getElementById('q').value=ref; applyFilters(); const tr=[...document.querySelectorAll('#tb tr')].find(r=>(r.children[0]?.textContent||'').trim()===ref); if(tr){tr.classList.add('hi');setTimeout(()=>tr.classList.remove('hi'),2000);toast('Article trouvé','ok');}else{toast('Article non trouvé — référence: '+ref,'warn');}}
  function scanFrame(){if(!scanning) return; if(video.readyState===video.HAVE_ENOUGH_DATA){canvas.width=video.videoWidth;canvas.height=video.videoHeight;ctx.drawImage(video,0,0,canvas.width,canvas.height);const img=ctx.getImageData(0,0,canvas.width,canvas.height);const code=jsQR(img.data,img.width,img.height,{inversionAttempts:'dontInvert'});if(code){beep();stopScan();closeAll();rechercherArticle(code.data);return;}} requestAnimationFrame(scanFrame);}

  // events
  document.getElementById('btnAdd').addEventListener('click',()=>openModal(0));
  document.getElementById('mClose').addEventListener('click',closeAll);
  document.getElementById('mSave').addEventListener('click',save);
  document.getElementById('modalBg').addEventListener('click',closeAll);
  document.getElementById('histClose').addEventListener('click',closeAll);
  document.getElementById('scanClose').addEventListener('click',closeAll);
  document.getElementById('f_categorie').addEventListener('change',applyTech);
  document.getElementById('btnPrint').addEventListener('click',()=>window.open('/public/stock_etiquettes.php?all=1','_blank'));
  document.getElementById('btnScan').addEventListener('click',async()=>{document.getElementById('modalBg').style.display='block';document.getElementById('scanModal').style.display='block';await startScan();});
  document.getElementById('camSel').addEventListener('change',async e=>{stopScan();await startScan(e.target.value);});
  document.getElementById('manualSearch').addEventListener('click',()=>{closeAll();document.getElementById('q').focus();});
  document.querySelectorAll('.tbl th[data-sort]').forEach(th=>th.addEventListener('click',()=>{const k=th.dataset.sort;sortDir=(sortKey===k&&sortDir==='asc')?'desc':'asc';sortKey=k;applyFilters();}));
  document.getElementById('q').addEventListener('input',applyFilters); document.getElementById('fCategorie').addEventListener('change',applyFilters); document.getElementById('fEtat').addEventListener('change',applyFilters);
  document.querySelectorAll('#etatChecks input,input[name="stockState"]').forEach(el=>el.addEventListener('change',applyFilters));
  document.getElementById('qRange').addEventListener('input',()=>{document.getElementById('qRangeVal').textContent=document.getElementById('qRange').value; applyFilters();});
  document.getElementById('prev').addEventListener('click',()=>{if(page>1){page--;render();}});
  document.getElementById('next').addEventListener('click',()=>{const p=Math.max(1,Math.ceil(filtered.length/size)); if(page<p){page++;render();}});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')closeAll(); if(e.key.toLowerCase()==='n'){e.preventDefault();openModal(0);} if(e.key.toLowerCase()==='f'){e.preventDefault();document.getElementById('q').focus();} if(e.key.toLowerCase()==='s'){e.preventDefault();document.getElementById('btnScan').click();}});
  if(!canWrite){document.getElementById('btnAdd').disabled=true;}
  applyFilters();
})();
</script>
</body>
</html>

// ====================================================================
// FONCTIONS UTILITAIRES
// ====================================================================

// La fonction formatDateTime() est définie dans includes/helpers.php

/**
 * Extrait la marque depuis un modèle
 */
function extractMarque(string $model): string
{
    $model = trim($model);
    if (empty($model)) {
        return '—';
    }
    
    $parts = preg_split('/\s+/', $model);
    return ($parts && $parts[0] !== '') ? $parts[0] : '—';
}

/**
 * Détermine le statut d'un photocopieur
 */
function determineStatut(string $rawStatus): string
{
    $raw = strtoupper(trim($rawStatus));
    if (empty($raw)) {
        return 'stock';
    }
    
    $okValues = ['OK', 'ONLINE', 'NORMAL', 'READY', 'PRINT', 'IDLE', 'STANDBY', 'SLEEP', 'AVAILABLE'];
    return in_array($raw, $okValues, true) ? 'stock' : 'en panne';
}

/**
 * Retourne la classe badge stock (stock-out, stock-low, stock-ok)
 * Seuils : papier 5, toner 3, lcd 2, pc 2
 */
function stockBadgeClass(int $qty, string $type): string
{
    $seuils = ['papier' => 5, 'toners' => 3, 'lcd' => 2, 'pc' => 2];
    $seuil = $seuils[$type] ?? 2;
    if ($qty === 0) {
        return 'stock-out';
    }
    if ($qty <= $seuil) {
        return 'stock-low';
    }
    return 'stock-ok';
}

// ====================================================================
// RÉCUPÉRATION DES PHOTOCOPIEURS NON ATTRIBUÉS
// ====================================================================
$copiers = [];
try {
    $sql = "
        WITH v_compteur_last AS (
            SELECT r.*,
                   ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
            FROM compteur_relevee r
            WHERE r.mac_norm IS NOT NULL AND r.mac_norm <> ''
        )
        SELECT
            v.mac_norm,
            v.MacAddress,
            v.SerialNumber,
            v.Model,
            v.Nom,
            v.`Timestamp` AS last_ts,
            v.TotalBW,
            v.TotalColor,
            v.Status AS raw_status
        FROM v_compteur_last v
        LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = v.mac_norm
        WHERE v.rn = 1
          AND pc.id_client IS NULL
        ORDER BY
            v.Model IS NULL, v.Model,
            v.SerialNumber IS NULL, v.SerialNumber,
            v.MacAddress
        LIMIT 500
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $r) {
        $model = trim($r['Model'] ?? '');
        $marque = extractMarque($model);
        $statut = determineStatut($r['raw_status'] ?? '');
        
        $copiers[] = [
            'id' => $r['mac_norm'] ?? '',
            'mac' => $r['MacAddress'] ?: '',
            'marque' => $marque,
            'modele' => $model ?: ($r['Nom'] ?: '—'),
            'sn' => $r['SerialNumber'] ?: '—',
            'compteur_bw' => is_numeric($r['TotalBW']) ? (int)$r['TotalBW'] : null,
            'compteur_color' => is_numeric($r['TotalColor']) ? (int)$r['TotalColor'] : null,
            'statut' => $statut,
            'emplacement' => 'dépôt',
            'last_ts' => formatDateTime($r['last_ts'] ?? null, 'Y-m-d H:i:s'),
        ];
    }
} catch (PDOException $e) {
    error_log('stock.php (photocopieurs non attribués) SQL error: ' . $e->getMessage());
    $copiers = [];
}

// ====================================================================
// RÉCUPÉRATION DU PAPIER
// ====================================================================
$papers = safeFetchAll(
    $pdo,
    "SELECT v.paper_id, v.marque, v.modele, v.poids, v.qty_stock, c.barcode 
     FROM v_paper_stock v 
     LEFT JOIN paper_catalog c ON c.id = v.paper_id 
     ORDER BY v.marque, v.modele, v.poids",
    [],
    'stock_papier'
);

// ====================================================================
// RÉCUPÉRATION DES TONERS
// ====================================================================
$tonersRaw = safeFetchAll(
    $pdo,
    "SELECT v.toner_id, v.marque, v.modele, v.couleur, v.qty_stock, c.barcode 
     FROM v_toner_stock v 
     LEFT JOIN toner_catalog c ON c.id = v.toner_id 
     ORDER BY v.marque, v.modele, v.couleur",
    [],
    'stock_toner'
);

$toners = [];
foreach ($tonersRaw as $r) {
    $toners[] = [
        'id' => (int)($r['toner_id'] ?? 0),
        'marque' => $r['marque'] ?? '',
        'modele' => $r['modele'] ?? '',
        'couleur' => $r['couleur'] ?? '',
        'qty' => (int)($r['qty_stock'] ?? 0),
        'barcode' => trim($r['barcode'] ?? ''),
    ];
}

// ====================================================================
// RÉCUPÉRATION DES LCD
// ====================================================================
$lcdRaw = safeFetchAll(
    $pdo,
    "SELECT v.lcd_id, v.marque, v.reference, v.etat, v.modele, v.taille, v.resolution, v.connectique, v.prix, v.qty_stock, c.barcode 
     FROM v_lcd_stock v 
     LEFT JOIN lcd_catalog c ON c.id = v.lcd_id 
     ORDER BY v.marque, v.modele, v.taille",
    [],
    'stock_lcd'
);

$lcd = [];
foreach ($lcdRaw as $r) {
    $lcd[] = [
        'id' => (int)($r['lcd_id'] ?? 0),
        'marque' => $r['marque'] ?? '',
        'reference' => $r['reference'] ?? '',
        'etat' => $r['etat'] ?? '',
        'modele' => $r['modele'] ?? '',
        'taille' => (int)($r['taille'] ?? 0),
        'resolution' => $r['resolution'] ?? '',
        'connectique' => $r['connectique'] ?? '',
        'prix' => isset($r['prix']) && $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty' => (int)($r['qty_stock'] ?? 0),
        'barcode' => trim($r['barcode'] ?? ''),
    ];
}

// ====================================================================
// RÉCUPÉRATION DES PC
// ====================================================================
$pcRaw = safeFetchAll(
    $pdo,
    "SELECT v.pc_id, v.etat, v.reference, v.marque, v.modele, v.cpu, v.ram, v.stockage, v.os, v.gpu, v.reseau, v.ports, v.prix, v.qty_stock, c.barcode 
     FROM v_pc_stock v 
     LEFT JOIN pc_catalog c ON c.id = v.pc_id 
     ORDER BY v.marque, v.modele, v.reference",
    [],
    'stock_pc'
);

$pc = [];
foreach ($pcRaw as $r) {
    $pc[] = [
        'id' => (int)($r['pc_id'] ?? 0),
        'etat' => $r['etat'] ?? '',
        'reference' => $r['reference'] ?? '',
        'marque' => $r['marque'] ?? '',
        'modele' => $r['modele'] ?? '',
        'cpu' => $r['cpu'] ?? '',
        'ram' => $r['ram'] ?? '',
        'stockage' => $r['stockage'] ?? '',
        'os' => $r['os'] ?? '',
        'gpu' => $r['gpu'] ?? '',
        'reseau' => $r['reseau'] ?? '',
        'ports' => $r['ports'] ?? '',
        'prix' => isset($r['prix']) && $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty' => (int)($r['qty_stock'] ?? 0),
        'barcode' => trim($r['barcode'] ?? ''),
    ];
}

// ====================================================================
// CALCUL DES STATISTIQUES
// ====================================================================
$totalPapier = array_sum(array_map(function ($p) {
    return (int)($p['qty_stock'] ?? 0);
}, $papers));

$totalToners = array_sum(array_map(function ($t) {
    return (int)($t['qty'] ?? 0);
}, $toners));

$totalLCD = array_sum(array_map(function ($l) {
    return (int)($l['qty'] ?? 0);
}, $lcd));

$totalPC = array_sum(array_map(function ($p) {
    return (int)($p['qty'] ?? 0);
}, $pc));

// Détection des stocks faibles
$stockFaible = [
    'papier' => array_filter($papers, function ($p) {
        return (int)($p['qty_stock'] ?? 0) <= 5;
    }),
    'toners' => array_filter($toners, function ($t) {
        return (int)($t['qty'] ?? 0) <= 3;
    }),
    'lcd' => array_filter($lcd, function ($l) {
        return (int)($l['qty'] ?? 0) <= 2;
    }),
    'pc' => array_filter($pc, function ($p) {
        return (int)($p['qty'] ?? 0) <= 2;
    }),
];

$nbStockFaible = count($stockFaible['papier']) 
    + count($stockFaible['toners']) 
    + count($stockFaible['lcd']) 
    + count($stockFaible['pc']);

// Normalisation des données papier pour le dataset
$papersNormalized = [];
foreach ($papers as $p) {
    $paperId = $p['paper_id'] ?? null;
    if (empty($paperId)) {
        continue;
    }
    
    $papersNormalized[] = [
        'id' => (int)$paperId,
        'paper_id' => (int)$paperId,
        'marque' => $p['marque'] ?? '',
        'modele' => $p['modele'] ?? '',
        'poids' => $p['poids'] ?? '',
        'qty' => (int)($p['qty_stock'] ?? 0),
        'qty_stock' => (int)($p['qty_stock'] ?? 0),
        'barcode' => trim($p['barcode'] ?? ''),
    ];
}

// Préparation des datasets pour JavaScript
$datasets = [
    'copiers' => $copiers,
    'lcd' => $lcd,
    'pc' => $pc,
    'toners' => $toners,
    'papier' => $papersNormalized
];

// Images des sections : créer assets/img/stock/ avec photocopieurs.jpg, lcd.jpg, pc.jpg, toners.jpg, papier.jpg
// En attendant, fallback sur le logo pour éviter les images cassées
$logoFallback = '/assets/logos/logo.png';
$sectionImages = [
    'photocopieurs' => file_exists(__DIR__ . '/../assets/img/stock/photocopieurs.jpg') ? '/assets/img/stock/photocopieurs.jpg' : $logoFallback,
    'lcd' => file_exists(__DIR__ . '/../assets/img/stock/lcd.jpg') ? '/assets/img/stock/lcd.jpg' : $logoFallback,
    'pc' => file_exists(__DIR__ . '/../assets/img/stock/pc.jpg') ? '/assets/img/stock/pc.jpg' : $logoFallback,
    'toners' => file_exists(__DIR__ . '/../assets/img/stock/toners.jpg') ? '/assets/img/stock/toners.jpg' : $logoFallback,
    'papier' => file_exists(__DIR__ . '/../assets/img/stock/papier.jpg') ? '/assets/img/stock/papier.jpg' : $logoFallback,
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
    <title>Stock - CCComputer</title>
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/main.css" />
    <link rel="stylesheet" href="/assets/css/stock.css" />
    <style>
        /* Typographie professionnelle */
        .page-stock .page-title { font-family: 'Plus Jakarta Sans', sans-serif; }
        .page-stock .page-sub { font-family: 'Plus Jakarta Sans', sans-serif; }
        .page-stock .dashboard-label,
        .page-stock .dashboard-value,
        .page-stock .stock-tab,
        .page-stock .section-title { font-family: 'Plus Jakarta Sans', sans-serif; }
        
        /* Layout avec sidebar gauche */
        .stock-layout {
            display: flex;
            gap: 1.5rem;
            position: relative;
        }
        
        /* Bouton caméra fixe — style professionnel */
        .camera-fixed-btn {
            position: fixed;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 20px rgba(37, 99, 235, 0.4);
            z-index: 100;
            text-decoration: none;
        }
        
        .camera-fixed-btn:hover {
            transform: translateY(-50%) scale(1.08);
            box-shadow: 0 8px 30px rgba(37, 99, 235, 0.5);
        }
        
        .camera-fixed-btn svg {
            width: 24px;
            height: 24px;
        }
        
        /* Sidebar scanner à gauche */
        .scanner-sidebar {
            width: 380px;
            min-width: 380px;
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1rem;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 1rem;
            height: fit-content;
            max-height: calc(100vh - 2rem);
            overflow-y: auto;
        }
        
        .scanner-sidebar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .scanner-sidebar-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .scanner-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .scanner-close-btn:hover {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }
        
        /* Contenu principal */
        .stock-main-content {
            flex: 1;
            min-width: 0;
        }
        
        /* Masquer le bouton fixe quand la sidebar est ouverte */
        .scanner-sidebar[style*="display: block"] ~ .stock-main-content .camera-fixed-btn {
            display: none !important;
        }
        
        /* Support :has() pour navigateurs modernes */
        @supports selector(:has(*)) {
            .stock-layout:has(#scannerSection[style*="display: block"]) .camera-fixed-btn {
                display: none !important;
            }
        }
        
        /* Ajuster le contenu principal quand la sidebar est ouverte */
        .stock-layout:has(#scannerSection[style*="display: block"]) .stock-main-content {
            margin-left: 0;
        }
        
        /* Boutons style livraison.php */
        .btn-modern {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.55rem 0.9rem;
            font-size: 0.9rem;
            font-weight: 500;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-primary);
            color: var(--text-primary);
            text-decoration: none;
        }
        
        .btn-modern:hover {
            background: var(--bg-secondary);
            border-color: #2563eb;
        }
        
        .btn-modern svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        
        .btn-print {
            background: var(--bg-primary);
        }
        
        .btn-print:hover {
            background: var(--bg-secondary);
        }
        
        /* Styles pour le scanner dans la sidebar */
        #scannerContainer {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        /* Styles spécifiques pour le scanner de caméra - Taille compacte QR code */
        #reader {
            position: relative;
            background: #000;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            max-width: 400px;
            margin: 0 auto;
            min-height: 300px;
        }
        
        #reader video,
        #reader canvas {
            width: 100% !important;
            max-width: 100% !important;
            height: auto !important;
            display: block !important;
            border-radius: var(--radius-md);
            object-fit: cover;
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }
        
        /* Zone de scan compacte - taille QR code (250x250px) */
        #reader #qr-shaded-region {
            border: 3px solid #10b981 !important;
            border-radius: 8px !important;
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3),
                        0 0 15px rgba(16, 185, 129, 0.5) !important;
            animation: scanPulse 1.5s ease-in-out infinite;
        }
        
        @keyframes scanPulse {
            0%, 100% {
                box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.3),
                            0 0 15px rgba(16, 185, 129, 0.5);
            }
            50% {
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.4),
                            0 0 20px rgba(16, 185, 129, 0.7);
            }
        }
        
        /* Forcer l'affichage de la vidéo en haute qualité */
        #reader video[style*="display: none"] {
            display: block !important;
        }
        
        #reader video {
            transform: scale(1);
            filter: contrast(1.1) brightness(1.05) saturate(1.1);
        }
        
        /* Style pour le conteneur de scan */
        #cameraScanArea {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 1rem;
            position: relative;
        }
        
        /* Amélioration de la qualité d'affichage */
        #reader img {
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }
        
        /* Optimisation des performances */
        #reader video {
            will-change: transform;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
        }
        
        /* Overrides tableaux — cohérence avec stock.css */
        .tbl-stock .td-metric {
            font-size: 1.05rem;
            font-weight: 700;
            color: #2563eb;
        }
        
        .tbl-stock .td-metric.is-zero {
            color: #dc2626;
        }
        
        /* Boutons Ajouter — style call-to-action professionnel */
        .btn-modern.btn-add {
            font-size: 0.9rem;
            padding: 0.65rem 1.25rem;
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
            color: white !important;
            border: none !important;
            font-weight: 600;
            box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3);
        }
        .btn-modern.btn-add:hover {
            background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 100%) !important;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4);
            transform: translateY(-1px);
        }
        
        /* Bloc Mouvement dans modale détail */
        .detail-moves-section {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }
        .moves-section-title {
            margin: 0 0 0.75rem 0;
            font-size: 1rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .move-form {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }
        .move-form-row {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }
        .move-form-row label {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-secondary);
        }
        .move-qty-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .move-qty-group input {
            width: 80px;
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
        }
        .move-quick-btns {
            display: flex;
            gap: 0.25rem;
        }
        .btn-quick {
            width: 36px;
            height: 36px;
            padding: 0;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-quick:hover {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
        }
        .move-form-row select,
        .move-form-row input[type="text"] {
            padding: 0.5rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
        }
        .move-form-actions {
            display: flex;
            gap: 0.5rem;
            margin-top: 0.5rem;
        }
        .move-form-actions .btn-success { background: #10b981; color: white; border-color: #10b981; }
        .move-form-actions .btn-success:hover { background: #059669; }
        .move-form-actions .btn-danger { background: #ef4444; color: white; border-color: #ef4444; }
        .move-form-actions .btn-danger:hover { background: #dc2626; }
        .form-warning {
            color: #f59e0b;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        .move-history {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
        }
        .move-history-item {
            padding: 0.5rem 0.75rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.875rem;
            display: grid;
            grid-template-columns: 1fr auto auto 1fr;
            gap: 0.5rem;
            align-items: center;
        }
        .move-history-item:last-child { border-bottom: none; }
        .move-history-item .qty-in { color: #10b981; font-weight: 600; }
        .move-history-item .qty-out { color: #ef4444; font-weight: 600; }
        .move-history-empty {
            padding: 1rem;
            color: var(--text-secondary);
            font-style: italic;
            text-align: center;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .stock-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }
            
            .camera-icon-btn {
                width: 48px;
                height: 48px;
            }
            
            .stock-header .page-title {
                font-size: 1.5rem;
            }
            
            .tbl-stock th,
            .tbl-stock td {
                padding: 0.875rem 1rem;
                font-size: 0.875rem;
            }
            
            .btn-modern {
                padding: 0.75rem 1.25rem;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
    <!-- Header simple comme livraison.php/sav.php -->
    <div class="page-header">
        <h2 class="page-title">Gestion du Stock</h2>
        <p class="page-sub">
            Vue d'ensemble complète de votre inventaire — disposition <strong>dynamique</strong> selon le contenu
        </p>
    </div>

    <!-- Messages flash -->
    <?php if ($flash && isset($flash['type'])): ?>
        <div class="flash <?= h($flash['type']) ?>" role="alert">
            <?= h($flash['msg'] ?? '') ?>
        </div>
    <?php endif; ?>
    <?php if ($flashWarning): ?>
        <div class="flash flash-warning" role="status">
            <?= h($flashWarning) ?>
        </div>
    <?php endif; ?>

    <!-- Mini dashboard (2 cards) -->
    <section class="stock-dashboard" aria-label="Dashboard stock">
        <div class="dashboard-card dashboard-total">
            <span class="dashboard-label">Stock total</span>
            <strong class="dashboard-value"><?= h((string)($totalPapier + $totalToners + $totalLCD + $totalPC)) ?></strong>
        </div>
        <div class="dashboard-card dashboard-low <?= $nbStockFaible > 0 ? 'has-low' : '' ?>">
            <span class="dashboard-label">Articles en stock faible</span>
            <strong class="dashboard-value"><?= h((string)$nbStockFaible) ?></strong>
        </div>
    </section>

    <!-- Onglets -->
    <div class="stock-tabs" role="tablist" aria-label="Types de stock">
        <button type="button" class="stock-tab" role="tab" data-tab="copiers" aria-selected="false">Photocopieurs</button>
        <button type="button" class="stock-tab" role="tab" data-tab="papier" aria-selected="false">Papier</button>
        <button type="button" class="stock-tab" role="tab" data-tab="toners" aria-selected="false">Toners</button>
        <button type="button" class="stock-tab" role="tab" data-tab="lcd" aria-selected="false">LCD</button>
        <button type="button" class="stock-tab" role="tab" data-tab="pc" aria-selected="false">PC</button>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem;">
        <a class="btn btn-secondary" target="_blank" href="/public/stock_etiquettes.php?all=1">🖨️ Imprimer toutes les étiquettes</a>
        <select id="labelsCategorieSelect" class="btn btn-secondary" style="padding:.5rem .75rem;">
            <option value="">Étiquettes par catégorie...</option>
            <option value="papier">Papier</option>
            <option value="toner_noir">Toner noir</option>
            <option value="toner_cyan">Toner cyan</option>
            <option value="toner_magenta">Toner magenta</option>
            <option value="toner_jaune">Toner jaune</option>
            <option value="pc">PC</option>
            <option value="ecran_lcd">Écran LCD</option>
            <option value="imprimante">Imprimante / Photocopieur</option>
            <option value="piece_detachee">Pièce détachée</option>
            <option value="consommable">Consommable</option>
            <option value="autre">Autre</option>
        </select>
    </div>

    <!-- Barre de recherche - Pleine largeur -->
    <div class="search-bar-full">
        <input 
            type="text" 
            id="q" 
            class="search-input-full"
            placeholder="Rechercher dans le stock (référence, modèle, SN, MAC, CPU…)" 
            aria-label="Filtrer le stock"
            autocomplete="off" />
        <span class="search-results-count" id="searchResultsCount" style="display: none; color: var(--text-secondary); font-size: 0.875rem; margin-top: 0.5rem;" aria-live="polite"></span>
    </div>

    <section class="card-section" style="margin-bottom:1rem;">
        <div class="section-head">
            <div class="head-left"><h2 class="section-title">Nouveau stock (catégories avancées)</h2></div>
        </div>
        <div class="table-wrapper" style="padding:12px;">
            <?php if ($canWriteStock): ?>
            <div style="display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:.5rem;margin-bottom:.75rem;">
                <input id="nsReference" placeholder="Référence (auto si vide)">
                <input id="nsDesignation" placeholder="Désignation">
                <select id="nsCategorie">
                    <option value="papier">Papier (carton A4)</option>
                    <option value="toner_noir">Toner Noir</option>
                    <option value="toner_cyan">Toner Cyan</option>
                    <option value="toner_magenta">Toner Magenta</option>
                    <option value="toner_jaune">Toner Jaune</option>
                    <option value="pc">PC</option>
                    <option value="ecran_lcd">Écran LCD</option>
                    <option value="imprimante">Imprimante / Photocopieur</option>
                    <option value="piece_detachee">Pièce détachée</option>
                    <option value="consommable">Consommable</option>
                    <option value="autre">Autre</option>
                </select>
                <input id="nsQuantite" type="number" min="0" value="0" placeholder="Quantité">
                <select id="nsUnite"><option value="unite">unité</option><option value="carton">carton</option><option value="rame">rame</option></select>
                <div id="nsContenanceRow" style="display:none;"><input id="nsContenance" type="number" min="1" placeholder="Contenance"></div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;">
                <small id="nsContenanceHint" style="color:#6b7280;"></small>
                <button type="button" id="nsAddBtn" class="btn btn-primary">Ajouter article</button>
            </div>
            <?php else: ?>
            <div style="margin-bottom:.75rem;color:#6b7280;">Lecture seule (rôle Livreur).</div>
            <?php endif; ?>
            <div style="overflow:auto;">
                <table class="tbl-stock">
                    <thead><tr><th>Référence</th><th>Désignation</th><th>Catégorie</th><th>Quantité</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php if (empty($stockItemsNew)): ?>
                        <tr><td colspan="5"><em>Aucun article dans la table stock</em></td></tr>
                    <?php else: foreach ($stockItemsNew as $s): ?>
                        <?php
                          $cat=(string)($s['categorie'] ?? 'autre');
                          $badgeMap=[
                            'papier'=>'background:#dbeafe;color:#1e3a8a;','toner_noir'=>'background:#111827;color:#fff;','toner_cyan'=>'background:#0891b2;color:#fff;',
                            'toner_magenta'=>'background:#db2777;color:#fff;','toner_jaune'=>'background:#fde047;color:#111827;','pc'=>'background:#4f46e5;color:#fff;',
                            'ecran_lcd'=>'background:#7c3aed;color:#fff;','imprimante'=>'background:#166534;color:#fff;','piece_detachee'=>'background:#ea580c;color:#fff;',
                            'consommable'=>'background:#0f766e;color:#fff;','autre'=>'background:#6b7280;color:#fff;'
                          ];
                          $q=(int)($s['quantite'] ?? 0); $u=(string)($s['unite'] ?? 'unite'); $c=(int)($s['contenance'] ?? 0);
                          $qLabel=($u==='carton'&&$c>0)?($q.' cartons ('.($q*$c).' feuilles)'):($q.' unité(s)');
                        ?>
                        <tr>
                            <td><?= h((string)$s['reference']) ?></td>
                            <td><?= h((string)$s['designation']) ?></td>
                            <td><span style="padding:2px 8px;border-radius:999px;font-size:11px;<?= $badgeMap[$cat] ?? $badgeMap['autre'] ?>"><?= h($cat) ?></span></td>
                            <td><?= h($qLabel) ?></td>
                            <td><a class="btn btn-secondary" target="_blank" href="/public/stock_etiquettes.php?stock_id=<?= (int)$s['id'] ?>">🔳 Étiquettes</a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- Layout avec sidebar gauche pour le scanner -->
    <div class="stock-layout">
        <!-- Sidebar gauche pour le scanner -->
        <aside class="scanner-sidebar" id="scannerSection" style="display: none;">
            <div class="scanner-sidebar-header">
                <h3>Scanner Code-Barres</h3>
                <button 
                    type="button" 
                    id="toggleScanner" 
                    class="scanner-close-btn"
                    aria-label="Fermer le scanner">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>
            
            <div id="scannerContainer" style="display: none;">
            <div style="display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center;">
                <button 
                    type="button" 
                    id="startCameraScan" 
                    class="btn btn-primary"
                    style="flex: 1;">
                    📹 Démarrer la Caméra
                </button>
                <button 
                    type="button" 
                    id="stopCameraScan" 
                    class="btn btn-secondary"
                    style="flex: 1; display: none;">
                    ⏹️ Arrêter Scanner
                </button>
                <div id="libraryStatus" style="font-size: 0.75rem; color: var(--text-muted); padding: 0.5rem; min-width: 200px;">
                    <span id="libraryStatusText">⏳ Chargement de la bibliothèque...</span>
                    <div id="libraryHelp" style="display: none; margin-top: 0.25rem; font-size: 0.7rem; color: var(--text-muted);">
                        Si le chargement échoue, rechargez la page (F5)
                    </div>
                </div>
            </div>
            
            <!-- Zone de prévisualisation vidéo caméra -->
            <div id="cameraScanArea" style="display: none; margin-bottom: 1rem;">
                <div style="text-align: center; margin-bottom: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                    Positionnez le code-barres dans le cadre
                </div>
                <div id="reader" style="width: 100%; min-height: 300px; border: 2px solid var(--accent-primary); border-radius: var(--radius-md); padding: 1rem; background: var(--bg-secondary); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center;"></div>
                <div style="text-align: center; margin-top: 0.5rem; color: var(--text-muted); font-size: 0.75rem;">
                    Le scan se fera automatiquement dès la détection
                </div>
            </div>
            
            <!-- Zone de résultat -->
            <div id="scanResult" style="display: none; margin-top: 1rem; padding: 1rem; background: #dcfce7; border-radius: var(--radius-md); border: 1px solid #86efac;">
                <div id="scanResultContent" style="color: #166534; font-weight: 600;"></div>
            </div>
            
            <!-- Messages d'erreur -->
            <div id="scanError" style="display: none; margin-top: 1rem; padding: 1rem; background: #fee2e2; color: #991b1b; border-radius: var(--radius-md); border: 1px solid #fecaca;">
                <strong>Erreur :</strong> <span id="scanErrorText"></span>
            </div>
            </div>
        </aside>
        
        <!-- Contenu principal -->
        <main class="stock-main-content">
            <!-- Bouton caméra fixe à gauche - Redirige vers la page scanner -->
            <a 
                href="/public/scan_barcode.php" 
                class="camera-fixed-btn"
                aria-label="Ouvrir le scanner">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 15C13.6569 15 15 13.6569 15 12C15 10.3431 13.6569 9 12 9C10.3431 9 9 10.3431 9 12C9 13.6569 10.3431 15 12 15Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M2 12.88V19C2 20.1046 2.89543 21 4 21H20C21.1046 21 22 20.1046 22 19V5C22 3.89543 21.1046 3 20 3H4C2.89543 3 2 3.89543 2 5V11.12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M18 7L16 5L14 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M18 7L16 9L14 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </a>

            <!-- Grille Masonry 2 colonnes -->
            <div id="stockMasonry" class="stock-masonry">
        
        <!-- Section Photocopieurs -->
        <section class="card-section" data-section="copiers" aria-labelledby="section-copiers-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['photocopieurs']) ?>" 
                        class="section-icon" 
                        alt="Photocopieurs" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-copiers-title" class="section-title">Photocopieurs</h2>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="copiers" role="table" aria-label="Liste des photocopieurs non attribués">
                    <colgroup>
                        <col class="col-text">
                        <col class="col-text">
                        <col class="col-text">
                        <col class="col-state">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-text">N° Série</th>
                            <th scope="col" class="col-text">MAC</th>
                            <th scope="col" class="col-state">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($copiers)): ?>
                            <tr>
                                <td colspan="4" class="col-empty">
                                    <em>Aucun photocopieur non attribué</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($copiers as $c): ?>
                                <tr 
                                    data-type="copiers" 
                                    data-id="<?= h((string)$c['id']) ?>"
                                    data-search="<?= h(strtolower(($c['marque'] ?? '') . ' ' . ($c['modele'] ?? '') . ' ' . ($c['sn'] ?? '') . ' ' . ($c['mac'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du photocopieur <?= h($c['modele']) ?>">
                                    <td class="col-text" title="<?= h($c['modele']) ?>"><?= h($c['modele']) ?></td>
                                    <td class="col-text" title="<?= h($c['sn']) ?>"><?= h($c['sn']) ?></td>
                                    <td class="col-text" title="<?= h($c['mac']) ?>"><?= h($c['mac']) ?></td>
                                    <td class="col-state"><?= h($c['statut']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section Toners -->
        <section class="card-section" data-section="toners" aria-labelledby="section-toners-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['toners']) ?>" 
                        class="section-icon" 
                        alt="Toners" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-toners-title" class="section-title">Toners</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn-modern btn-add" 
                        data-add-type="toner"
                        aria-label="Ajouter un toner">
                        <span aria-hidden="true">+</span> Ajouter toner
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="toners" role="table" aria-label="Liste des toners">
                    <colgroup>
                        <col class="col-couleur">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-text">Couleur</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($toners)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun toner en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($toners as $t): ?>
                                <tr 
                                    data-type="toners" 
                                    data-id="<?= h((string)$t['id']) ?>"
                                    data-search="<?= h(strtolower(($t['marque'] ?? '') . ' ' . ($t['modele'] ?? '') . ' ' . ($t['couleur'] ?? '') . ' ' . ($t['barcode'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du toner <?= h($t['modele']) ?>">
                                    <td class="col-text" title="<?= h($t['couleur']) ?>"><?= h($t['couleur']) ?></td>
                                    <td class="col-text" title="<?= h($t['modele']) ?>"><?= h($t['modele']) ?></td>
                                    <td class="col-number td-metric stock-badge <?= stockBadgeClass((int)$t['qty'], 'toners') ?>"><?= (int)$t['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section Papier -->
        <section class="card-section" data-section="papier" aria-labelledby="section-papier-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['papier']) ?>" 
                        class="section-icon" 
                        alt="Papier" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-papier-title" class="section-title">Papier</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn-modern btn-add" 
                        data-add-type="papier"
                        aria-label="Ajouter du papier">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span>Ajouter papier</span>
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="papier" role="table" aria-label="Liste du papier">
                    <colgroup>
                        <col class="col-qty">
                        <col class="col-modele">
                        <col class="col-poids">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-number">Qté</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-text">Poids</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($papers)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun papier en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($papers as $p): ?>
                                <?php if (!empty($p['paper_id'])): ?>
                                <tr 
                                    data-type="papier" 
                                    data-id="<?= h((string)$p['paper_id']) ?>"
                                    data-search="<?= h(strtolower(($p['marque'] ?? '') . ' ' . ($p['modele'] ?? '') . ' ' . ($p['poids'] ?? '') . ' ' . ($p['barcode'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du papier <?= h($p['modele'] ?? '') ?>">
                                    <td class="col-number td-metric stock-badge <?= stockBadgeClass((int)($p['qty_stock'] ?? 0), 'papier') ?>"><?= (int)($p['qty_stock'] ?? 0) ?></td>
                                    <td class="col-text" title="<?= h($p['modele'] ?? '—') ?>"><?= h($p['modele'] ?? '—') ?></td>
                                    <td class="col-text" title="<?= h($p['poids'] ?? '—') ?>"><?= h($p['poids'] ?? '—') ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section LCD -->
        <section class="card-section" data-section="lcd" aria-labelledby="section-lcd-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['lcd']) ?>" 
                        class="section-icon" 
                        alt="Écrans LCD" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-lcd-title" class="section-title">LCD</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn-modern btn-add" 
                        data-add-type="lcd"
                        aria-label="Ajouter un écran LCD">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span>Ajouter LCD</span>
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="lcd" role="table" aria-label="Liste des écrans LCD">
                    <colgroup>
                        <col class="col-etat">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-state">État</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lcd)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun LCD en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lcd as $row): ?>
                                <tr
                                    data-type="lcd" 
                                    data-id="<?= h((string)$row['id']) ?>"
                                    data-search="<?= h(strtolower(($row['modele'] ?? '') . ' ' . ($row['reference'] ?? '') . ' ' . ($row['marque'] ?? '') . ' ' . ($row['resolution'] ?? '') . ' ' . ($row['connectique'] ?? '') . ' ' . ($row['etat'] ?? '') . ' ' . ($row['barcode'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails de l'écran LCD <?= h($row['modele']) ?>">
                                    <td class="col-state"><?= stateBadge($row['etat']) ?></td>
                                    <td class="col-text" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                                    <td class="col-number td-metric stock-badge <?= stockBadgeClass((int)$row['qty'], 'lcd') ?>"><?= (int)$row['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section PC -->
        <section class="card-section" data-section="pc" aria-labelledby="section-pc-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['pc']) ?>" 
                        class="section-icon" 
                        alt="PC reconditionnés" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-pc-title" class="section-title">PC reconditionnés</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn-modern btn-add" 
                        data-add-type="pc"
                        aria-label="Ajouter un PC">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 5V19M5 12H19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <span>Ajouter PC</span>
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="pc" role="table" aria-label="Liste des PC reconditionnés">
                    <colgroup>
                        <col class="col-etat">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-state">État</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pc)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun PC en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pc as $row): ?>
                                <tr
                                    data-type="pc" 
                                    data-id="<?= h((string)$row['id']) ?>"
                                    data-search="<?= h(strtolower(($row['modele'] ?? '') . ' ' . ($row['reference'] ?? '') . ' ' . ($row['marque'] ?? '') . ' ' . ($row['cpu'] ?? '') . ' ' . ($row['os'] ?? '') . ' ' . ($row['ram'] ?? '') . ' ' . ($row['stockage'] ?? '') . ' ' . ($row['etat'] ?? '') . ' ' . ($row['barcode'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du PC <?= h($row['modele']) ?>">
                                    <td class="col-state"><?= stateBadge($row['etat']) ?></td>
                                    <td class="col-text" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                                    <td class="col-number td-metric stock-badge <?= stockBadgeClass((int)$row['qty'], 'pc') ?>"><?= (int)$row['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
            </div><!-- /#stockMasonry -->
        </main>
    </div><!-- /.stock-layout -->
</div><!-- /.page-container -->

<!-- ===== Modale détails (Photocopieurs / LCD / PC) ===== -->
<div id="detailOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="detailModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="modalTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="modalTitle">Détails</h3>
        <button 
            type="button" 
            id="modalClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <div class="detail-grid" id="detailGrid"></div>
        <div id="detailMovesSection" class="detail-moves-section" style="display:none;">
            <h4 class="moves-section-title">Mouvement de stock</h4>
            <form id="moveForm" class="move-form">
                <div class="move-form-row">
                    <label for="moveQty">Quantité</label>
                    <div class="move-qty-group">
                        <input type="number" id="moveQty" name="moveQty" min="1" value="1" required aria-label="Quantité">
                        <div class="move-quick-btns">
                            <button type="button" class="btn-quick" data-delta="-1" aria-label="Moins 1">−1</button>
                            <button type="button" class="btn-quick" data-delta="1" aria-label="Plus 1">+1</button>
                        </div>
                    </div>
                </div>
                <div class="move-form-row">
                    <label for="moveReason">Raison</label>
                    <select id="moveReason" name="moveReason" aria-label="Raison du mouvement">
                        <option value="ajustement">Ajustement</option>
                        <option value="achat">Achat</option>
                        <option value="retour">Retour</option>
                        <option value="correction">Correction</option>
                    </select>
                </div>
                <div class="move-form-row">
                    <label for="moveRef">Référence</label>
                    <input type="text" id="moveRef" name="moveRef" placeholder="ex: BL-123" aria-label="Référence">
                </div>
                <div class="move-form-actions">
                    <button type="button" id="moveBtnEntry" class="btn btn-success">Entrée</button>
                    <button type="button" id="moveBtnExit" class="btn btn-danger">Sortie</button>
                </div>
                <div id="moveError" class="form-error" role="alert" aria-live="assertive" style="display:none;"></div>
                <div id="moveWarning" class="form-warning" role="alert" aria-live="polite" style="display:none;"></div>
            </form>
            <h4 class="moves-section-title">Historique (20 derniers)</h4>
            <div id="moveHistory" class="move-history"></div>
        </div>
    </div>
    <div class="modal-footer" style="padding: 1rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button type="button" id="modalCloseFooter" class="btn btn-secondary">Fermer</button>
    </div>
</div>

<!-- ===== Modale ajout produit ===== -->
<div id="addOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="addModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="addModalTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="addModalTitle">Ajouter</h3>
        <button 
            type="button" 
            id="addModalClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <form id="addForm" novalidate>
            <div id="addFields" class="detail-grid"></div>
            <div class="modal-actions" style="margin-top:1rem; display:flex; gap:.5rem; justify-content:flex-end;">
                <button type="button" id="addCancel" class="btn btn-secondary">Annuler</button>
                <button type="submit" id="addSubmit" class="btn btn-primary">
                    <span class="btn-text">Enregistrer</span>
                    <span class="btn-spinner" aria-hidden="true" role="status" style="display:none;"></span>
                </button>
            </div>
            <div id="addError" class="form-error" role="alert" aria-live="assertive"></div>
            <div id="addSuccess" class="form-success" aria-live="polite" hidden></div>
        </form>
    </div>
</div>

<!-- ===== Modale résultats scan code-barres ===== -->
<div id="barcodeResultOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="barcodeResultModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="barcodeResultTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="barcodeResultTitle">Résultat du Scan</h3>
        <button 
            type="button" 
            id="barcodeResultClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <div id="barcodeResultContent" class="detail-grid"></div>
    </div>
</div>

<!-- Bibliothèque html5-qrcode via CDN avec fallback -->
<script <?= csp_nonce() ?>>
(function() {
    'use strict';
    
    // Fonction pour charger la bibliothèque html5-qrcode
    function loadHtml5Qrcode() {
        return new Promise(function(resolve, reject) {
            // Vérifier si déjà chargé
            if (typeof Html5Qrcode !== 'undefined') {
                resolve();
                return;
            }
            
            // Liste des CDN à essayer
            const cdnUrls = [
                'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
                'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js'
            ];
            
            let currentIndex = 0;
            
            function tryLoadCDN(index) {
                if (index >= cdnUrls.length) {
                    const errorMsg = 'Impossible de charger html5-qrcode depuis tous les CDN. Vérifiez votre connexion internet.';
                    console.error(errorMsg);
                    reject(new Error(errorMsg));
                    return;
                }
                
                const script = document.createElement('script');
                script.src = cdnUrls[index];
                script.async = true;
                script.crossOrigin = 'anonymous';
                
                script.onload = function() {
                    // Attendre que la bibliothèque s'initialise (augmenter le délai)
                    let attempts = 0;
                    const maxAttempts = 20; // 2 secondes max
                    
                    const checkLibrary = setInterval(function() {
                        attempts++;
                        if (typeof Html5Qrcode !== 'undefined') {
                            clearInterval(checkLibrary);
                            resolve();
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkLibrary);
                            // Essayer le CDN suivant
                            console.warn('Timeout: Html5Qrcode non défini après chargement, essai CDN suivant...');
                            tryLoadCDN(index + 1);
                        }
                    }, 100);
                };
                
                script.onerror = function() {
                    console.warn('✗ Échec chargement depuis:', cdnUrls[index]);
                    // Essayer le CDN suivant
                    tryLoadCDN(index + 1);
                };
                
                document.head.appendChild(script);
            }
            
            tryLoadCDN(0);
        });
    }
    
    // Charger la bibliothèque au chargement de la page
    window.html5QrcodeLoaded = loadHtml5Qrcode();
    
    // Mettre à jour le statut de chargement
    window.html5QrcodeLoaded.then(function() {
        window.html5QrcodeReady = true;
        // Mettre à jour l'indicateur visuel
        setTimeout(function() {
            const statusEl = document.getElementById('libraryStatusText');
            if (statusEl) {
                statusEl.textContent = '✓ Bibliothèque prête';
                statusEl.style.color = '#16a34a';
            }
        }, 100);
    }).catch(function(err) {
        console.error('Erreur chargement html5-qrcode:', err);
        window.html5QrcodeLoadError = true;
        window.html5QrcodeReady = false;
        // Mettre à jour l'indicateur visuel
        setTimeout(function() {
            const statusEl = document.getElementById('libraryStatusText');
            if (statusEl) {
                statusEl.textContent = '✗ Erreur de chargement - Rechargez la page';
                statusEl.style.color = '#dc2626';
            }
        }, 100);
        
        // Afficher l'aide si erreur après 5 secondes
        setTimeout(function() {
            if (typeof Html5Qrcode === 'undefined' && !window.html5QrcodeReady) {
                const helpEl = document.getElementById('libraryHelp');
                if (helpEl) {
                    helpEl.style.display = 'block';
                }
            }
        }, 5000);
    });
})();

// [Nouveau module stock] Catégories avancées + étiquettes
(function() {
    const sel = document.getElementById('labelsCategorieSelect');
    if (sel) {
        sel.addEventListener('change', function() {
            if (!this.value) return;
            window.open('/public/stock_etiquettes.php?categorie=' + encodeURIComponent(this.value), '_blank');
            this.value = '';
        });
    }

    const categorieSelect = document.getElementById('nsCategorie');
    const uniteField = document.getElementById('nsUnite');
    const contenanceField = document.getElementById('nsContenance');
    const contenanceRow = document.getElementById('nsContenanceRow');
    const contenanceHint = document.getElementById('nsContenanceHint');
    const addBtn = document.getElementById('nsAddBtn');

    function applyCategorieRules() {
        if (!categorieSelect || !uniteField || !contenanceField || !contenanceRow || !contenanceHint) return;
        if (categorieSelect.value === 'papier') {
            uniteField.value = 'carton';
            contenanceField.value = 2500;
            contenanceRow.style.display = 'flex';
            contenanceHint.textContent = '1 carton = 2500 feuilles A4';
        } else {
            uniteField.value = 'unite';
            contenanceField.value = '';
            contenanceRow.style.display = 'none';
            contenanceHint.textContent = '';
        }
    }
    if (categorieSelect) {
        categorieSelect.addEventListener('change', applyCategorieRules);
        applyCategorieRules();
    }

    if (addBtn) {
        addBtn.addEventListener('click', async function() {
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
            const payload = {
                categorie: document.getElementById('nsCategorie')?.value || '',
                reference: document.getElementById('nsReference')?.value || '',
                designation: document.getElementById('nsDesignation')?.value || '',
                quantite: parseInt(document.getElementById('nsQuantite')?.value || '0', 10),
                unite: document.getElementById('nsUnite')?.value || 'unite',
                contenance: document.getElementById('nsContenance')?.value || '',
            };
            const res = await fetch('/API/stock_add.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'include',
                body: JSON.stringify({data: payload, csrf_token: csrfToken}),
            });
            const j = await res.json();
            if (!j.ok) {
                alert('Erreur: ' + (j.error || 'inconnue'));
                return;
            }
            window.location.reload();
        });
    }
})();
</script>

<script <?= csp_nonce() ?>>
// S'assurer que le DOM est chargé avant d'exécuter les scripts
(function() {
    'use strict';
    
    // Référence globale pour la fonction open de la modale détails
    let detailModalOpen = null;
    
    let activeTab = 'copiers';
    let applyFilterRef = null;

    function initStockScripts() {
        initFilter();
        initTabs();
        initDetailModal();
        initAddModal();
        // Scripts stock initialisés
    }

    /* ===== Onglets ===== */
    function initTabs() {
        const tabs = document.querySelectorAll('.stock-tab');
        const sections = document.querySelectorAll('.card-section[data-section]');
        const STORAGE_KEY = 'stock_active_tab';

        function setActiveTab(tabId) {
            activeTab = tabId;
            try {
                localStorage.setItem(STORAGE_KEY, tabId);
            } catch (e) {}
            tabs.forEach(function(btn) {
                const isActive = btn.getAttribute('data-tab') === tabId;
                btn.setAttribute('aria-selected', isActive ? 'true' : 'false');
                btn.classList.toggle('active', isActive);
            });
            sections.forEach(function(section) {
                const sectionId = section.getAttribute('data-section');
                section.style.display = sectionId === tabId ? '' : 'none';
            });
            if (applyFilterRef) applyFilterRef();
        }

        tabs.forEach(function(btn) {
            btn.addEventListener('click', function() {
                setActiveTab(btn.getAttribute('data-tab'));
            });
        });

        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            const validTabs = ['copiers', 'papier', 'toners', 'lcd', 'pc'];
            if (saved && validTabs.indexOf(saved) >= 0) {
                setActiveTab(saved);
            } else {
                setActiveTab('copiers');
            }
        } catch (e) {
            setActiveTab('copiers');
        }
    }

    function switchToTab(tabId) {
        activeTab = tabId;
        const btn = document.querySelector('.stock-tab[data-tab="' + tabId + '"]');
        if (btn) {
            btn.click();
        }
    }

    // Attendre que le DOM soit complètement chargé
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initStockScripts, 100);
        });
    } else {
        setTimeout(initStockScripts, 100);
    }

    /* ===== Filtre + réordonnancement ===== */
    function initFilter() {
        const q = document.getElementById('q');
        const mason = document.getElementById('stockMasonry');
        const resultsCount = document.getElementById('searchResultsCount');
        const allRows = Array.from(document.querySelectorAll('.tbl-stock tbody tr'));

        function visibleRowCount(section) {
            const rows = section.querySelectorAll('tbody tr');
            let n = 0;
            rows.forEach(function(r) {
                if (r.style.display !== 'none') {
                    n++;
                }
            });
            return n;
        }
        
        function getActiveSectionRows() {
            var section = document.querySelector('.card-section[data-section="' + activeTab + '"]');
            return section ? Array.from(section.querySelectorAll('.tbl-stock tbody tr')) : [];
        }
        
        function getTotalVisibleRows() {
            var rows = getActiveSectionRows();
            return rows.filter(function(tr) {
                return tr.style.display !== 'none';
            }).length;
        }
        
        function updateResultsCount() {
            if (!resultsCount) {
                return;
            }
            const visible = getTotalVisibleRows();
            const total = getActiveSectionRows().length;
            if (q && q.value.trim()) {
                resultsCount.textContent = visible + ' / ' + total + ' résultats';
                resultsCount.style.display = 'inline-block';
            } else {
                resultsCount.style.display = 'none';
            }
        }
        
        function reorderSections() {
            if (!mason) {
                return;
            }
            const sections = Array.from(mason.querySelectorAll('.card-section'));
            const scored = sections.map(function(s, i) {
                return {
                    el: s,
                    score: visibleRowCount(s),
                    idx: i
                };
            });
            scored.sort(function(a, b) {
                return (b.score - a.score) || (a.idx - b.idx);
            });
            scored.forEach(function(x) {
                mason.appendChild(x.el);
            });
        }
        
        function norm(s) {
            return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        
        let filterTimeout = null;
        function applyFilter() {
            if (!q) {
                return;
            }
            const v = norm(q.value || '');
            document.querySelectorAll('.tbl-stock tbody tr').forEach(function(tr) {
                var section = tr.closest('.card-section');
                var sectionId = section ? section.getAttribute('data-section') : '';
                if (sectionId !== activeTab) {
                    tr.style.display = 'none';
                    return;
                }
                const t = norm(tr.getAttribute('data-search') || '');
                const isVisible = !v || t.includes(v);
                tr.style.display = isVisible ? '' : 'none';
            });
            reorderSections();
            updateResultsCount();
            
            // Masquer les sections vides avec animation
            document.querySelectorAll('.card-section').forEach(function(section) {
                const hasVisible = section.querySelectorAll('tbody tr[style=""]').length > 0;
                if (!hasVisible && v) {
                    section.style.opacity = '0.5';
                    section.style.transform = 'scale(0.98)';
                } else {
                    section.style.opacity = '1';
                    section.style.transform = 'scale(1)';
                }
            });
        }
        
        // Tri automatique à chaque frappe (debounce pour performance)
        if (q) {
            q.addEventListener('input', function() {
                clearTimeout(filterTimeout);
                // Appliquer le filtre immédiatement (pas de délai pour réactivité)
                filterTimeout = setTimeout(applyFilter, 100);
            });
            
            // Quand on supprime le contenu, le filtre se réinitialise automatiquement
            q.addEventListener('keydown', function(e) {
                // Si on appuie sur Suppr ou Backspace et que le champ est vide, réinitialiser
                if ((e.key === 'Delete' || e.key === 'Backspace') && q.value.length <= 1) {
                    setTimeout(applyFilter, 50);
                }
            });
        }
        
        // Le tri se fait automatiquement via l'événement 'input' ci-dessus
        // Quand on supprime le contenu, le filtre se réinitialise automatiquement
        
        applyFilterRef = applyFilter;
        reorderSections();
        updateResultsCount();
        
        if ('ResizeObserver' in window) {
            const ro = new ResizeObserver(function() {
                reorderSections();
            });
            mason.querySelectorAll('.card-section').forEach(function(sec) {
                ro.observe(sec);
            });
        }
    }

    /* ===== Datasets popup ===== */
    const DATASETS = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    /* Helpers sûrs (XSS) */
    function escapeText(s) {
        return (s == null) ? '—' : String(s);
    }
    
    function addField(grid, label, value, options) {
        options = options || {};
        const card = document.createElement('div');
        card.className = 'field-card';
        const lbl = document.createElement('div');
        lbl.className = 'lbl';
        lbl.textContent = label;
        const val = document.createElement('div');
        val.className = 'val';
        if (options.html) {
            val.innerHTML = value ?? '—';
        } else {
            val.textContent = escapeText(value);
        }
        card.appendChild(lbl);
        card.appendChild(val);
        grid.appendChild(card);
    }
    
    function badgeEtat(e) {
        e = String(e || '').toUpperCase();
        if (!['A', 'B', 'C'].includes(e)) {
            return '<span class="state state-na">—</span>';
        }
        return '<span class="state state-' + e + '">' + e + '</span>';
    }

    /* ===== Modal détails ===== */
    function initDetailModal() {
        const overlay = document.getElementById('detailOverlay');
        const modal = document.getElementById('detailModal');
        const close = document.getElementById('modalClose');
        const grid = document.getElementById('detailGrid');
        const titleEl = document.getElementById('modalTitle');

        const movesSection = document.getElementById('detailMovesSection');
        const moveForm = document.getElementById('moveForm');
        const moveQty = document.getElementById('moveQty');
        const moveReason = document.getElementById('moveReason');
        const moveRef = document.getElementById('moveRef');
        const moveHistory = document.getElementById('moveHistory');
        const moveError = document.getElementById('moveError');
        const moveWarning = document.getElementById('moveWarning');
        const moveBtnEntry = document.getElementById('moveBtnEntry');
        const moveBtnExit = document.getElementById('moveBtnExit');

        if (!overlay || !modal || !close || !grid || !titleEl) {
            console.error('Éléments de la modale de détails manquants');
            return;
        }

        let lastFocused = null;
        let currentMoveProduct = null;
        
        function focusFirst() {
            const f = modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
            if (f.length) {
                f[0].focus();
            }
        }
        
        function trapFocus(e) {
            if (e.key !== 'Tab') {
                return;
            }
            const f = Array.from(modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])'));
            if (!f.length) {
                return;
            }
            const first = f[0];
            const last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        
        function onKeydown(e) {
            if (e.key === 'Escape') {
                closeFn();
            }
            if (e.key === 'Tab') {
                trapFocus(e);
            }
        }
        
        function open() {
            lastFocused = document.activeElement;
            document.body.classList.add('modal-open');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.style.display = 'block';
            modal.style.display = 'block';
            document.addEventListener('keydown', onKeydown);
            focusFirst();
        }
        
        function closeFn() {
            document.body.classList.remove('modal-open');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.style.display = 'none';
            modal.style.display = 'none';
            document.removeEventListener('keydown', onKeydown);
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }

        function loadMoves(apiType, productId) {
            if (!moveHistory) return;
            moveHistory.innerHTML = '<div class="move-history-empty">Chargement…</div>';
            fetch('/API/stock_move.php?type=' + encodeURIComponent(apiType) + '&id=' + encodeURIComponent(productId), {
                credentials: 'same-origin'
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.ok || !Array.isArray(data.moves)) {
                    moveHistory.innerHTML = '<div class="move-history-empty">Erreur de chargement</div>';
                    return;
                }
                if (data.moves.length === 0) {
                    moveHistory.innerHTML = '<div class="move-history-empty">Aucun mouvement</div>';
                    return;
                }
                let html = '';
                data.moves.forEach(function(m) {
                    const qty = parseInt(m.qty_delta, 10);
                    const qtyClass = qty >= 0 ? 'qty-in' : 'qty-out';
                    const qtyStr = (qty >= 0 ? '+' : '') + qty;
                    const date = (m.created_at || '').replace(' ', ' à ');
                    html += '<div class="move-history-item">';
                    html += '<span class="' + qtyClass + '">' + escapeText(qtyStr) + '</span>';
                    html += '<span>' + escapeText(m.reason || '—') + '</span>';
                    html += '<span>' + escapeText(m.reference || '') + '</span>';
                    html += '<span>' + escapeText(m.user_name || '—') + ' — ' + escapeText(date) + '</span>';
                    html += '</div>';
                });
                moveHistory.innerHTML = html;
            })
            .catch(function() {
                moveHistory.innerHTML = '<div class="move-history-empty">Erreur réseau</div>';
            });
        }

        function submitMove(qtyDelta) {
            if (!currentMoveProduct || !moveForm || !moveQty || !moveReason) return;
            const qty = Math.abs(parseInt(moveQty.value, 10) || 1);
            const delta = qtyDelta === 'entry' ? qty : -qty;
            const csrfMeta = document.querySelector('meta[name="csrf-token"]');
            const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
            if (moveError) { moveError.style.display = 'none'; moveError.textContent = ''; }
            if (moveWarning) { moveWarning.style.display = 'none'; moveWarning.textContent = ''; }

            fetch('/API/stock_move.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: currentMoveProduct.apiType,
                    product_id: currentMoveProduct.productId,
                    qty_delta: delta,
                    reason: moveReason.value || 'ajustement',
                    reference: (moveRef && moveRef.value) ? moveRef.value.trim() : '',
                    csrf_token: csrfToken
                })
            })
            .then(function(r) { return r.json().then(function(j) { return { ok: r.ok, status: r.status, json: j }; }); })
            .then(function(res) {
                if (res.json.ok) {
                    loadMoves(currentMoveProduct.apiType, currentMoveProduct.productId);
                    var cards = grid.querySelectorAll('.field-card');
                    for (var i = 0; i < cards.length; i++) {
                        var lbl = cards[i].querySelector('.lbl');
                        if (lbl && lbl.textContent === 'Quantité') {
                            var val = cards[i].querySelector('.val');
                            if (val) val.textContent = res.json.new_stock;
                            break;
                        }
                    }
                    if (res.json.warning && moveWarning) {
                        moveWarning.textContent = res.json.warning;
                        moveWarning.style.display = 'block';
                    }
                } else {
                    if (moveError) {
                        moveError.textContent = res.json.error || 'Erreur';
                        moveError.style.display = 'block';
                    }
                }
            })
            .catch(function() {
                if (moveError) {
                    moveError.textContent = 'Erreur réseau';
                    moveError.style.display = 'block';
                }
            });
        }
        
        // Exposer open globalement pour être accessible depuis handleRowClick
        detailModalOpen = open;

        window.openDetailForProduct = function(apiType, productId) {
            var typeDisplay = apiType === 'toner' ? 'toners' : apiType;
            var rows = DATASETS[typeDisplay] || [];
            var row = rows.find(function(r) {
                if (typeDisplay === 'papier') return (r.paper_id && r.paper_id == productId) || (r.id && r.id == productId);
                return r.id && r.id == productId;
            });
            if (!row) return;
            if (typeof switchToTab === 'function') switchToTab(typeDisplay);
            renderDetails(typeDisplay, row);
            if (detailModalOpen) detailModalOpen();
            setTimeout(function() {
                var mq = document.getElementById('moveQty');
                if (mq) mq.focus();
            }, 150);
        };
        
        if (close) {
            close.addEventListener('click', closeFn);
        }
        const closeFooter = document.getElementById('modalCloseFooter');
        if (closeFooter) {
            closeFooter.addEventListener('click', closeFn);
        }
        if (overlay) {
            overlay.addEventListener('click', closeFn);
        }

        if (moveBtnEntry) moveBtnEntry.addEventListener('click', function() { submitMove('entry'); });
        if (moveBtnExit) moveBtnExit.addEventListener('click', function() { submitMove('exit'); });
        var quickBtns = modal.querySelectorAll('.btn-quick[data-delta]');
        quickBtns.forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!moveQty) return;
                var v = parseInt(moveQty.value, 10) || 1;
                var d = parseInt(btn.getAttribute('data-delta'), 10);
                v = Math.max(1, v + d);
                moveQty.value = String(v);
            });
        });
        if (moveForm) {
            moveForm.addEventListener('submit', function(e) {
                e.preventDefault();
                submitMove('entry');
            });
        }

        function renderDetails(type, row) {
            if (!grid || !titleEl) {
                console.error('Grid ou titleEl manquant');
                return;
            }
            
            grid.innerHTML = '';
            const typeNames = {
                'copiers': 'PHOTOCOPIEUR',
                'lcd': 'LCD',
                'pc': 'PC',
                'toners': 'TONER',
                'papier': 'PAPIER'
            };
            const displayName = row.modele ?? row.reference ?? row.marque ?? 'Détails';
            titleEl.textContent = displayName + ' — ' + (typeNames[type] || type.toUpperCase());
            
            if (type === 'copiers') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'N° Série', row.sn);
                addField(grid, 'Adresse MAC', row.mac);
                addField(grid, 'Compteur N&B', new Intl.NumberFormat('fr-FR').format(row.compteur_bw || 0));
                addField(grid, 'Compteur Couleur', new Intl.NumberFormat('fr-FR').format(row.compteur_color || 0));
                addField(grid, 'Statut', row.statut);
                addField(grid, 'Emplacement', row.emplacement);
                if (row.last_ts) {
                    addField(grid, 'Dernière relève', row.last_ts);
                }
            } else if (type === 'lcd') {
                addField(grid, 'État', badgeEtat(row.etat), {html: true});
                addField(grid, 'Référence', row.reference);
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Taille', (row.taille ? row.taille + '"' : '—'));
                addField(grid, 'Résolution', row.resolution);
                addField(grid, 'Connectique', row.connectique);
                addField(grid, 'Prix', row.prix != null ? new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(row.prix) : '—');
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'pc') {
                addField(grid, 'État', badgeEtat(row.etat), {html: true});
                addField(grid, 'Référence', row.reference);
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'CPU', row.cpu);
                addField(grid, 'RAM', row.ram);
                addField(grid, 'Stockage', row.stockage);
                addField(grid, 'OS', row.os);
                addField(grid, 'GPU', row.gpu);
                addField(grid, 'Réseau', row.reseau);
                addField(grid, 'Ports', row.ports);
                addField(grid, 'Prix', row.prix != null ? new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(row.prix) : '—');
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'toners') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Couleur', row.couleur);
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'papier') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Poids', row.poids);
                addField(grid, 'Quantité', row.qty_stock ?? row.qty ?? 0);
            }
            
            // Bloc Mouvement (papier, toners, lcd, pc uniquement)
            const movableTypes = ['papier', 'toners', 'lcd', 'pc'];
            if (movesSection && movableTypes.indexOf(type) >= 0) {
                movesSection.style.display = 'block';
                const apiType = type === 'toners' ? 'toner' : type;
                const productId = type === 'papier' ? (row.paper_id ?? row.id) : row.id;
                currentMoveProduct = { apiType: apiType, productId: productId, displayType: type };
                loadMoves(apiType, productId);
                if (moveQty) moveQty.value = '1';
                if (moveError) { moveError.style.display = 'none'; moveError.textContent = ''; }
                if (moveWarning) { moveWarning.style.display = 'none'; moveWarning.textContent = ''; }
            } else if (movesSection) {
                movesSection.style.display = 'none';
                currentMoveProduct = null;
            }

            // Ajouter le bouton d'impression d'étiquettes
            if (row.id && type !== 'copiers') {
                const printBtnWrapper = document.createElement('div');
                printBtnWrapper.className = 'field-card';
                printBtnWrapper.style.gridColumn = '1 / -1';
                printBtnWrapper.style.textAlign = 'center';
                printBtnWrapper.style.padding = '1rem';
                
                const printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-primary';
                printBtn.textContent = '🖨️ Imprimer Étiquettes (24)';
                printBtn.style.padding = '0.75rem 1.5rem';
                printBtn.style.fontSize = '1rem';
                printBtn.addEventListener('click', function() {
                    printLabels(type, row.id, row.modele || row.reference || row.marque || 'Produit');
                });
                
                printBtnWrapper.appendChild(printBtn);
                grid.appendChild(printBtnWrapper);
            }
        }
        
        // Fonction pour ouvrir la page d'impression
        function printLabels(type, productId, productName) {
            const url = `/public/print_labels.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(productId)}&name=${encodeURIComponent(productName)}`;
            window.open(url, '_blank');
        }

        // Fonction pour gérer le clic sur une ligne
        function handleRowClick(tr, e) {
            // Ne pas ouvrir si on clique sur un bouton, un lien ou un input
            if (e && e.target) {
                const clickedElement = e.target.closest('button, a, input, select, .btn-add');
                if (clickedElement) {
                    return;
                }
            }
            
            // Ne pas ouvrir si l'utilisateur est en train de sélectionner du texte
            if (e) {
                const selection = window.getSelection();
                if (selection && selection.toString().trim().length > 0) {
                    return;
                }
            }
            
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            const type = tr.getAttribute('data-type');
            const id = tr.getAttribute('data-id');
            
            if (!type || !id) {
                console.warn('Type ou ID manquant:', {type: type, id: id});
                return;
            }
            
            const rows = (DATASETS[type] || []);
            
            if (rows.length === 0) {
                console.warn('Dataset vide pour type:', type);
                return;
            }
            
            // Chercher la ligne correspondante (gérer différents formats d'ID)
            const searchId = String(id).trim();
            let row = rows.find(function(r) {
                // Essayer avec id
                if (r.id !== undefined && String(r.id).trim() === searchId) {
                    return true;
                }
                // Essayer avec paper_id
                if (r.paper_id !== undefined && String(r.paper_id).trim() === searchId) {
                    return true;
                }
                // Essayer avec toner_id
                if (r.toner_id !== undefined && String(r.toner_id).trim() === searchId) {
                    return true;
                }
                return false;
            });
            
            if (!row) {
                console.warn('Ligne non trouvée dans le dataset:', {
                    type: type,
                    searchedId: id
                });
                return;
            }
            
            renderDetails(type, row);
            
            // Utiliser la référence globale
            if (detailModalOpen && typeof detailModalOpen === 'function') {
                detailModalOpen();
            } else {
                console.error('La fonction open() n\'est pas définie!');
            }
        }
        
        // Utiliser la délégation d'événements au niveau du document
        document.addEventListener('click', function(e) {
            // Ignorer si on clique sur un bouton d'ajout
            if (e.target.closest('.btn-add')) {
                return;
            }
            
            // Trouver la ligne la plus proche avec data-type et data-id
            const tr = e.target.closest('tbody tr[data-type][data-id]');
            if (tr) {
                handleRowClick(tr, e);
            }
        });
        
        // Rendre les lignes visuellement cliquables et ajouter support clavier
        const clickableRows = document.querySelectorAll('tbody tr[data-type][data-id]');
        
        clickableRows.forEach(function(tr) {
            tr.style.cursor = 'pointer';
            tr.tabIndex = 0;
            tr.setAttribute('role', 'button');
            tr.setAttribute('aria-label', 'Afficher les détails');
            
            // Support clavier
            tr.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    handleRowClick(tr, null);
                }
            });
        });
    }

    /* ===== Modale ajout produit (papier / toner / lcd / pc) ===== */
    function initAddModal() {
        const overlay = document.getElementById('addOverlay');
        const modal = document.getElementById('addModal');
        const titleEl = document.getElementById('addModalTitle');
        const btnClose = document.getElementById('addModalClose');
        const btnCancel = document.getElementById('addCancel');
        const form = document.getElementById('addForm');
        const fieldsContainer = document.getElementById('addFields');
        const errorBox = document.getElementById('addError');
        const successBox = document.getElementById('addSuccess');

        let currentType = null;

        const SELECT_OPTIONS = {
            toner_couleur: [
                {value: '', label: '-- Choisir --'},
                {value: 'Noir', label: 'Noir'},
                {value: 'Cyan', label: 'Cyan'},
                {value: 'Magenta', label: 'Magenta'},
                {value: 'Jaune', label: 'Jaune'},
                {value: 'Autre', label: 'Autre'}
            ],
            papier_poids: [
                {value: '', label: '-- Choisir --'},
                {value: '70', label: '70 g'},
                {value: '80', label: '80 g'},
                {value: '90', label: '90 g'},
                {value: '100', label: '100 g'},
                {value: 'Autre', label: 'Autre'}
            ],
            etat_abc: [
                {value: '', label: '-- Choisir --'},
                {value: 'A', label: 'A (excellent)'},
                {value: 'B', label: 'B (bon)'},
                {value: 'C', label: 'C (correct)'}
            ],
            lcd_resolution: [
                {value: '', label: '-- Choisir --'},
                {value: '1920x1080', label: '1920×1080 (Full HD)'},
                {value: '2560x1440', label: '2560×1440 (QHD)'},
                {value: '3840x2160', label: '3840×2160 (4K)'},
                {value: '1366x768', label: '1366×768'},
                {value: '1680x1050', label: '1680×1050'},
                {value: 'Autre', label: 'Autre'}
            ],
            lcd_connectique: [
                {value: '', label: '-- Choisir --'},
                {value: 'HDMI', label: 'HDMI'},
                {value: 'DisplayPort', label: 'DisplayPort'},
                {value: 'VGA', label: 'VGA'},
                {value: 'DVI', label: 'DVI'},
                {value: 'USB-C', label: 'USB-C'},
                {value: 'HDMI+VGA', label: 'HDMI + VGA'},
                {value: 'Autre', label: 'Autre'}
            ],
            pc_os: [
                {value: '', label: '-- Choisir --'},
                {value: 'Windows 10', label: 'Windows 10'},
                {value: 'Windows 11', label: 'Windows 11'},
                {value: 'Linux', label: 'Linux'},
                {value: 'macOS', label: 'macOS'},
                {value: 'Sans OS', label: 'Sans OS'},
                {value: 'Autre', label: 'Autre'}
            ],
            pc_ram: [
                {value: '', label: '-- Choisir --'},
                {value: '4 GB', label: '4 GB'},
                {value: '8 GB', label: '8 GB'},
                {value: '16 GB', label: '16 GB'},
                {value: '32 GB', label: '32 GB'},
                {value: '64 GB', label: '64 GB'},
                {value: 'Autre', label: 'Autre'}
            ],
            pc_stockage: [
                {value: '', label: '-- Choisir --'},
                {value: '128 GB SSD', label: '128 GB SSD'},
                {value: '256 GB SSD', label: '256 GB SSD'},
                {value: '512 GB SSD', label: '512 GB SSD'},
                {value: '1 TB SSD', label: '1 TB SSD'},
                {value: '1 TB HDD', label: '1 TB HDD'},
                {value: '2 TB HDD', label: '2 TB HDD'},
                {value: 'Autre', label: 'Autre'}
            ]
        };

        const FORM_SCHEMAS = {
            papier: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'poids', label: 'Poids (g/m²)', type: 'select', required: true, options: SELECT_OPTIONS.papier_poids},
                {name: 'poids_autre', label: 'Précisez le poids', type: 'text', required: false, showWhen: 'poids', showWhenValue: 'Autre'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference', label: 'Référence (BL, facture…)', type: 'text'}
            ],
            toner: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'couleur', label: 'Couleur', type: 'select', required: true, options: SELECT_OPTIONS.toner_couleur},
                {name: 'couleur_autre', label: 'Précisez la couleur', type: 'text', required: false, showWhen: 'couleur', showWhenValue: 'Autre'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference', label: 'Référence (BL, facture…)', type: 'text'}
            ],
            lcd: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'reference', label: 'Référence', type: 'text', required: true},
                {name: 'etat', label: 'État', type: 'select', required: true, options: SELECT_OPTIONS.etat_abc},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'taille', label: 'Taille (pouces)', type: 'number', required: true, min: 10},
                {name: 'resolution', label: 'Résolution', type: 'select', required: true, options: SELECT_OPTIONS.lcd_resolution},
                {name: 'resolution_autre', label: 'Précisez la résolution', type: 'text', required: false, showWhen: 'resolution', showWhenValue: 'Autre'},
                {name: 'connectique', label: 'Connectique', type: 'select', required: true, options: SELECT_OPTIONS.lcd_connectique},
                {name: 'connectique_autre', label: 'Précisez la connectique', type: 'text', required: false, showWhen: 'connectique', showWhenValue: 'Autre'},
                {name: 'prix', label: 'Prix (EUR)', type: 'number', step: '0.01'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference_move', label: 'Référence mouvement (BL, facture…)', type: 'text'}
            ],
            pc: [
                {name: 'etat', label: 'État', type: 'select', required: true, options: SELECT_OPTIONS.etat_abc},
                {name: 'reference', label: 'Référence', type: 'text', required: true},
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'cpu', label: 'CPU', type: 'text', required: true},
                {name: 'ram', label: 'RAM', type: 'select', required: true, options: SELECT_OPTIONS.pc_ram},
                {name: 'ram_autre', label: 'Précisez la RAM', type: 'text', required: false, showWhen: 'ram', showWhenValue: 'Autre'},
                {name: 'stockage', label: 'Stockage', type: 'select', required: true, options: SELECT_OPTIONS.pc_stockage},
                {name: 'stockage_autre', label: 'Précisez le stockage', type: 'text', required: false, showWhen: 'stockage', showWhenValue: 'Autre'},
                {name: 'os', label: 'OS', type: 'select', required: true, options: SELECT_OPTIONS.pc_os},
                {name: 'os_autre', label: 'Précisez l\'OS', type: 'text', required: false, showWhen: 'os', showWhenValue: 'Autre'},
                {name: 'gpu', label: 'GPU', type: 'text'},
                {name: 'reseau', label: 'Réseau', type: 'text'},
                {name: 'ports', label: 'Ports', type: 'text'},
                {name: 'prix', label: 'Prix (EUR)', type: 'number', step: '0.01'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference_move', label: 'Référence mouvement (BL, facture…)', type: 'text'}
            ]
        };

        function clearForm() {
            if (fieldsContainer) {
                fieldsContainer.innerHTML = '';
            }
            if (errorBox) {
                errorBox.style.display = 'none';
                errorBox.textContent = '';
                errorBox.className = 'form-error';
            }
            if (successBox) {
                successBox.textContent = '';
                successBox.hidden = true;
            }
        }

        function buildForm(type) {
            clearForm();
            const schema = FORM_SCHEMAS[type];
            if (!schema || !fieldsContainer) {
                return;
            }
            schema.forEach(function(f) {
                const wrapper = document.createElement('div');
                wrapper.className = 'field-card';
                if (f.showWhen) {
                    wrapper.dataset.showWhen = f.showWhen;
                    wrapper.dataset.showWhenValue = f.showWhenValue || '';
                    wrapper.style.display = 'none';
                }

                const lbl = document.createElement('label');
                lbl.className = 'lbl';
                lbl.textContent = f.label;
                lbl.htmlFor = 'add_' + f.name;

                let control;
                if (f.type === 'select') {
                    control = document.createElement('select');
                    control.className = 'val';
                    control.id = 'add_' + f.name;
                    control.name = f.name;
                    (f.options || []).forEach(function(opt) {
                        const option = document.createElement('option');
                        option.value = opt.value;
                        option.textContent = opt.label;
                        control.appendChild(option);
                    });
                    if (f.required) {
                        control.required = true;
                    }
                } else {
                    control = document.createElement('input');
                    control.className = 'val';
                    control.id = 'add_' + f.name;
                    control.name = f.name;
                    control.type = f.type || 'text';
                    if (f.required) {
                        control.required = true;
                    }
                    if (f.min != null) {
                        control.min = f.min;
                    }
                    if (f.step != null) {
                        control.step = f.step;
                    }
                    if (f.maxLength != null) {
                        control.maxLength = f.maxLength;
                    }
                }

                wrapper.appendChild(lbl);
                wrapper.appendChild(control);
                fieldsContainer.appendChild(wrapper);
            });

            // Gestion conditionnelle des champs *_autre (générique)
            fieldsContainer.querySelectorAll('[data-show-when]').forEach(function(wrapper) {
                const triggerName = wrapper.dataset.showWhen;
                const triggerValue = wrapper.dataset.showWhenValue || 'Autre';
                const triggerEl = document.getElementById('add_' + triggerName);
                const autreInput = wrapper.querySelector('input, select');
                if (!triggerEl || !autreInput) return;
                function toggle() {
                    const show = triggerEl.value === triggerValue;
                    wrapper.style.display = show ? '' : 'none';
                    autreInput.required = show;
                }
                triggerEl.addEventListener('change', toggle);
                toggle();
            });
        }

        function openModal(type) {
            if (!type) {
                console.error('Type manquant pour openModal');
                return;
            }
            currentType = type;
            const typeNames = {
                'toner': 'toner',
                'papier': 'papier',
                'lcd': 'LCD',
                'pc': 'PC'
            };
            if (titleEl) {
                titleEl.textContent = 'Ajouter ' + (typeNames[type] || type);
            }
            buildForm(type);
            
            // Focus sur le premier champ du formulaire après un court délai
            setTimeout(function() {
                const firstInput = fieldsContainer.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            }, 100);
            
            document.body.classList.add('modal-open');
            if (overlay) {
                overlay.style.display = 'block';
                overlay.setAttribute('aria-hidden', 'false');
            }
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeModal() {
            document.body.classList.remove('modal-open');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
            if (modal) {
                modal.style.display = 'none';
            }
            currentType = null;
            clearForm();
        }

        // Vérifier que tous les éléments nécessaires existent
        if (!overlay || !modal || !titleEl || !fieldsContainer || !form || !errorBox) {
            console.error('Éléments DOM manquants pour la modale d\'ajout');
            return;
        }

        if (btnClose) {
            btnClose.addEventListener('click', closeModal);
        }
        if (btnCancel) {
            btnCancel.addEventListener('click', function(e) {
                e.preventDefault();
                closeModal();
            });
        }
        if (overlay) {
            overlay.addEventListener('click', closeModal);
        }

        // Attacher les event listeners aux boutons d'ajout
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-add[data-add-type]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const t = btn.getAttribute('data-add-type');
                if (t) {
                    openModal(t);
                } else {
                    console.error('Attribut data-add-type manquant sur le bouton');
                }
            }
        });

        if (form) {
            const submitBtn = document.getElementById('addSubmit');
            let isSubmitting = false;

            function setLoading(loading) {
                if (!submitBtn) return;
                const btnText = submitBtn.querySelector('.btn-text');
                const btnSpinner = submitBtn.querySelector('.btn-spinner');
                submitBtn.disabled = loading;
                if (btnText) btnText.style.display = loading ? 'none' : '';
                if (btnSpinner) {
                    btnSpinner.style.display = loading ? 'inline-block' : 'none';
                    btnSpinner.setAttribute('aria-hidden', loading ? 'false' : 'true');
                }
            }

            function clearMessages() {
                if (errorBox) {
                    errorBox.textContent = '';
                    errorBox.className = 'form-error';
                    errorBox.style.display = 'none';
                }
                if (successBox) {
                    successBox.textContent = '';
                    successBox.hidden = true;
                }
            }

            function showError(msg) {
                if (errorBox) {
                    errorBox.textContent = msg;
                    errorBox.className = 'form-error form-error--visible';
                    errorBox.setAttribute('tabindex', '-1');
                    errorBox.focus();
                }
            }

            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!currentType) return;
                if (isSubmitting) return;

                isSubmitting = true;
                clearMessages();
                setLoading(true);

                try {
                    const formData = new FormData(form);
                    const payload = {};
                    formData.forEach(function(v, k) {
                        payload[k] = v;
                    });

                    // Normalisation des champs "Autre" -> valeur *_autre
                    const autreMappings = {
                        toner: [['couleur', 'couleur_autre']],
                        papier: [['poids', 'poids_autre']],
                        lcd: [['resolution', 'resolution_autre'], ['connectique', 'connectique_autre']],
                        pc: [['ram', 'ram_autre'], ['stockage', 'stockage_autre'], ['os', 'os_autre']]
                    };
                    (autreMappings[currentType] || []).forEach(function(pair) {
                        const [mainField, autreField] = pair;
                        if (payload[mainField] === 'Autre') {
                            payload[mainField] = (payload[autreField] || '').trim();
                        }
                        delete payload[autreField];
                    });

                    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

                    const res = await fetch('/API/stock_add.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            type: currentType,
                            data: payload,
                            csrf_token: csrfToken
                        })
                    });

                    const text = await res.text();
                    let json;
                    try {
                        json = JSON.parse(text);
                    } catch (parseErr) {
                        console.error('Réponse non JSON de /API/stock_add.php :', text);
                        showError('Réponse invalide du serveur (pas du JSON).');
                        return;
                    }

                    if (!res.ok || !json.ok) {
                        console.error('Erreur API :', json);
                        showError(json.error || "Erreur lors de l'enregistrement.");
                        return;
                    }

                    // Succès : fermer et recharger avec message flash
                    closeModal();
                    const url = new URL(window.location.href);
                    url.searchParams.set('added', currentType);
                    if (json.warning) {
                        url.searchParams.set('warning', '1');
                    }
                    window.location.href = url.toString();
                } catch (err) {
                    console.error('Erreur fetch :', err);
                    showError('Erreur réseau ou serveur. Réessayez.');
                } finally {
                    isSubmitting = false;
                    setLoading(false);
                }
            });
        }
    }
})();

    /* ===== Scanner Code-Barres ===== */
    (function() {
        'use strict';
        
        let html5QrcodeScanner = null;
        let isScanning = false;
        
        const toggleBtn = document.getElementById('toggleScanner');
        const scannerContainer = document.getElementById('scannerContainer');
        const startCameraBtn = document.getElementById('startCameraScan');
        const stopCameraBtn = document.getElementById('stopCameraScan');
        const cameraScanArea = document.getElementById('cameraScanArea');
        const scanResult = document.getElementById('scanResult');
        const scanResultContent = document.getElementById('scanResultContent');
        const scanError = document.getElementById('scanError');
        const scanErrorText = document.getElementById('scanErrorText');
        const searchInput = document.getElementById('q'); // Champ de recherche principal
        
        // Toggle scanner container (depuis le bouton de fermeture dans la sidebar)
        if (toggleBtn && scannerContainer) {
            toggleBtn.addEventListener('click', function() {
                const scannerSection = document.getElementById('scannerSection');
                const isVisible = scannerSection ? (scannerSection.style.display !== 'none' && scannerSection.style.display !== '') : false;
                
                if (scannerSection) {
                    scannerSection.style.display = isVisible ? 'none' : 'block';
                }
                scannerContainer.style.display = isVisible ? 'none' : 'block';
                
                if (!isVisible && isScanning) {
                    stopScanning();
                }
            });
        }
        
        // Toggle scanner depuis le bouton fixe à gauche
        const toggleScannerMain = document.getElementById('toggleScannerMain');
        if (toggleScannerMain) {
            toggleScannerMain.addEventListener('click', function() {
                const scannerSection = document.getElementById('scannerSection');
                const scannerContainer = document.getElementById('scannerContainer');
                
                if (scannerSection && scannerContainer) {
                    const isVisible = scannerSection.style.display !== 'none' && scannerSection.style.display !== '';
                    scannerSection.style.display = isVisible ? 'none' : 'block';
                    scannerContainer.style.display = isVisible ? 'none' : 'block';
                    
                    // Masquer/afficher le bouton fixe
                    if (toggleScannerMain) {
                        toggleScannerMain.style.display = isVisible ? 'flex' : 'none';
                    }
                    
                    if (!isVisible && isScanning) {
                        stopScanning();
                    }
                }
            });
        }
        
        // Observer pour masquer le bouton fixe quand la sidebar est ouverte
        const scannerSection = document.getElementById('scannerSection');
        const toggleScannerMainBtn = document.getElementById('toggleScannerMain');
        if (scannerSection && toggleScannerMainBtn) {
            const observer = new MutationObserver(function(mutations) {
                const isVisible = scannerSection.style.display !== 'none' && scannerSection.style.display !== '';
                toggleScannerMainBtn.style.display = isVisible ? 'none' : 'flex';
            });
            observer.observe(scannerSection, { attributes: true, attributeFilter: ['style'] });
        }
        
        // Démarrer le scan caméra
        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', async function() {
                // Désactiver le bouton pendant le chargement
                const originalText = startCameraBtn.textContent;
                startCameraBtn.disabled = true;
                startCameraBtn.textContent = '⏳ Chargement de la bibliothèque...';
                hideError();
                
                try {
                    // Attendre que la bibliothèque soit chargée avec timeout
                    let libraryReady = false;
                    
                    if (window.html5QrcodeLoaded) {
                        try {
                            // Attendre la promesse avec timeout
                            await Promise.race([
                                window.html5QrcodeLoaded,
                                new Promise(function(_, reject) {
                                    setTimeout(function() {
                                        reject(new Error('Timeout: La bibliothèque prend trop de temps à charger'));
                                    }, 10000); // 10 secondes max
                                })
                            ]);
                            libraryReady = true;
                        } catch (promiseErr) {
                            console.warn('Erreur promesse html5QrcodeLoaded:', promiseErr);
                            // Continuer avec la vérification directe
                        }
                    }
                    
                    // Vérification directe avec plusieurs tentatives
                    if (!libraryReady) {
                        startCameraBtn.textContent = '⏳ Vérification de la bibliothèque...';
                        
                        for (let i = 0; i < 30; i++) {
                            if (typeof Html5Qrcode !== 'undefined') {
                                libraryReady = true;
                                break;
                            }
                            await new Promise(function(resolve) {
                                setTimeout(resolve, 100);
                            });
                        }
                    }
                    
                    // Vérification finale
                    if (typeof Html5Qrcode === 'undefined') {
                        const errorDetails = [
                            'Bibliothèque html5-qrcode non disponible.',
                            '',
                            'Causes possibles:',
                            '• Problème de connexion internet',
                            '• Bloqueur de scripts/CDN',
                            '• CDN inaccessible',
                            '',
                            'Solutions:',
                            '1. Rechargez la page (F5 ou Ctrl+R)',
                            '2. Vérifiez votre connexion internet',
                            '3. Désactivez temporairement les bloqueurs de publicités',
                            '4. Vérifiez la console du navigateur (F12) pour plus de détails'
                        ].join('\n');
                        console.error(errorDetails);
                        throw new Error('Bibliothèque html5-qrcode non disponible. Veuillez recharger la page (F5) ou vérifier votre connexion internet.');
                    }
                    
                    startCameraBtn.textContent = '⏳ Démarrage de la caméra...';
                    
                    // Vérifier HTTPS (requis pour la caméra)
                    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                        throw new Error('La caméra nécessite une connexion HTTPS sécurisée. Veuillez utiliser https://');
                    }
                    
                    // Vérifier que l'API MediaDevices est disponible
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        throw new Error('Votre navigateur ne supporte pas l\'accès à la caméra. Veuillez utiliser un navigateur moderne (Chrome, Firefox, Edge).');
                    }
                    
                    await startCameraScanning();
                    
                } catch (err) {
                    console.error('Erreur démarrage caméra:', err);
                    showError('Erreur: ' + (err.message || err));
                } finally {
                    // Réactiver le bouton
                    startCameraBtn.disabled = false;
                    startCameraBtn.textContent = originalText;
                }
            });
        }
        
        // Arrêter le scan caméra
        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', function() {
                stopScanning();
            });
        }
        
        // Fonction pour démarrer le scan caméra
        async function startCameraScanning() {
            if (isScanning) {
                return;
            }
            
            try {
                // Vérifier si html5-qrcode est disponible
                if (typeof Html5Qrcode === 'undefined') {
                    throw new Error('Bibliothèque html5-qrcode non chargée. Vérifiez votre connexion internet.');
                }
                
                const reader = document.getElementById('reader');
                if (!reader) {
                    throw new Error('Zone de scan introuvable');
                }
                
                // Afficher la zone de scan AVANT de démarrer la caméra
                cameraScanArea.style.display = 'block';
                startCameraBtn.style.display = 'none';
                stopCameraBtn.style.display = 'block';
                hideError();
                hideResult();
                
                // Afficher un message de chargement
                reader.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-secondary);"><div style="margin-bottom: 1rem; font-size: 1.2rem;">⏳</div><div style="margin-bottom: 0.5rem; font-weight: 600;">Démarrage de la caméra...</div><div style="font-size: 0.75rem; color: var(--text-muted);">Si la caméra ne s\'affiche pas, vérifiez les permissions de votre navigateur.</div></div>';
                
                html5QrcodeScanner = new Html5Qrcode('reader');
                
                // Essayer d'abord avec la caméra arrière, puis la caméra avant
                let cameraConfig = { facingMode: 'environment' };
                let started = false;
                
                try {
                    // Essayer la caméra arrière (environment) avec qualité professionnelle
                    await html5QrcodeScanner.start(
                        cameraConfig,
                        {
                            // FPS optimal pour détection rapide et stable
                            fps: 10,
                            
                            // Zone de scan adaptative - taille QR code
                            qrbox: { width: 250, height: 250 },
                            
                            // Paramètres de qualité
                            aspectRatio: 1.0,
                            
                            // Contraintes vidéo simplifiées pour meilleure compatibilité
                            videoConstraints: {
                                facingMode: 'environment'
                            }
                        },
                        onScanSuccess,
                        onScanError
                    );
                    started = true;
                } catch (envError) {
                    console.warn('⚠️ Caméra arrière non disponible, essai caméra avant:', envError);
                    // Essayer la caméra avant (user) avec qualité professionnelle
                    try {
                        await html5QrcodeScanner.stop();
                        html5QrcodeScanner.clear();
                    } catch (e) {
                        // Ignorer
                    }
                    
                    cameraConfig = { facingMode: 'user' };
                    html5QrcodeScanner = new Html5Qrcode('reader');
                    
                    // Démarrage caméra avant
                    await html5QrcodeScanner.start(
                        cameraConfig,
                        {
                            // FPS optimal pour détection rapide et stable
                            fps: 10,
                            
                            // Zone de scan adaptative - taille QR code
                            qrbox: { width: 250, height: 250 },
                            
                            // Paramètres de qualité
                            aspectRatio: 1.0,
                            
                            // Contraintes vidéo simplifiées pour meilleure compatibilité
                            videoConstraints: {
                                facingMode: 'user'
                            }
                        },
                        onScanSuccess,
                        onScanError
                    );
                    started = true;
                    // Caméra avant démarrée
                }
                
                if (started) {
                    isScanning = true;
                    // Scanner prêt
                } else {
                    console.error('❌ Échec démarrage caméra');
                }
                
            } catch (err) {
                console.error('Erreur démarrage caméra:', err);
                
                // Réinitialiser l'interface
                startCameraBtn.style.display = 'block';
                stopCameraBtn.style.display = 'none';
                cameraScanArea.style.display = 'none';
                isScanning = false;
                
                // Afficher un message d'erreur détaillé
                let errorMsg = 'Impossible de démarrer la caméra. ';
                
                if (err.name === 'NotAllowedError' || err.message.includes('permission')) {
                    errorMsg += 'Veuillez autoriser l\'accès à la caméra dans les paramètres de votre navigateur.';
                } else if (err.name === 'NotFoundError' || err.message.includes('device')) {
                    errorMsg += 'Aucune caméra détectée sur cet appareil.';
                } else if (err.message.includes('HTTPS') || err.message.includes('secure')) {
                    errorMsg += 'La caméra nécessite une connexion HTTPS sécurisée.';
                } else {
                    errorMsg += err.message || 'Erreur inconnue.';
                }
                
                showError(errorMsg);
                
                // Nettoyer
                if (html5QrcodeScanner) {
                    try {
                        await html5QrcodeScanner.stop();
                        html5QrcodeScanner.clear();
                    } catch (e) {
                        // Ignorer
                    }
                    html5QrcodeScanner = null;
                }
            }
        }
        
        // Fonction pour arrêter le scan
        function stopScanning() {
            if (html5QrcodeScanner && isScanning) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    html5QrcodeScanner = null;
                    isScanning = false;
                    startCameraBtn.style.display = 'block';
                    stopCameraBtn.style.display = 'none';
                    cameraScanArea.style.display = 'none';
                    
                    // Nettoyer le contenu de la zone de scan
                    const reader = document.getElementById('reader');
                    if (reader) {
                        reader.innerHTML = '';
                    }
                }).catch((err) => {
                    console.error('Erreur arrêt caméra:', err);
                    // Forcer la réinitialisation même en cas d'erreur
                    html5QrcodeScanner = null;
                    isScanning = false;
                    startCameraBtn.style.display = 'block';
                    stopCameraBtn.style.display = 'none';
                    cameraScanArea.style.display = 'none';
                });
            } else {
                // Réinitialiser même si le scanner n'est pas actif
                isScanning = false;
                startCameraBtn.style.display = 'block';
                stopCameraBtn.style.display = 'none';
                cameraScanArea.style.display = 'none';
            }
        }
        
        // Variable pour éviter les scans multiples (cooldown court pour rapidité)
        let lastScannedCode = '';
        let lastScanTime = 0;
        const SCAN_COOLDOWN_MS = 500; // 500ms entre chaque scan (rapide)
        
        // Callback succès scan - Optimisé pour détection ultra-rapide
        function onScanSuccess(decodedText, decodedResult) {
            if (!decodedText) {
                return;
            }
            
            const now = Date.now();
            
            // Éviter les scans multiples du même code (déduplication rapide)
            if (decodedText === lastScannedCode && (now - lastScanTime) < SCAN_COOLDOWN_MS) {
                // Scan ignoré (déjà scanné récemment)
                return;
            }
            
            // Mettre à jour les variables
            lastScannedCode = decodedText;
            lastScanTime = now;
            
            // Code scanné avec succès
            
            // Remplir automatiquement le champ de recherche IMMÉDIATEMENT
            fillSearchField(decodedText);
            
            // Afficher un message de succès avec feedback visuel
            showResult('✓ Code scanné : <strong>' + decodedText + '</strong>');
            
            // Rechercher le produit en arrière-plan (ne bloque pas le scan)
            processBarcode(decodedText).catch(err => {
                console.error('Erreur traitement barcode:', err);
            });
            
            // NE PAS arrêter le scan - permettre de scanner plusieurs codes rapidement
        }
        
        // Callback erreur scan
        function onScanError(errorMessage) {
            if (errorMessage) {
                // Ignorer les erreurs normales (pas de code détecté)
                if (errorMessage.includes('No QR code') || 
                    errorMessage.includes('NotFoundException') ||
                    errorMessage.includes('No MultiFormat Readers')) {
                    // Erreur normale, on ignore silencieusement
                    return;
                }
            }
        }
        
        // Fonction pour remplir le champ de recherche
        function fillSearchField(barcode) {
            if (searchInput) {
                searchInput.value = barcode;
                searchInput.focus();
                
                // Déclencher l'événement input pour activer le filtre
                const inputEvent = new Event('input', { bubbles: true });
                searchInput.dispatchEvent(inputEvent);
            }
        }
        
        // Fonction pour traiter le code-barres scanné (recherche produit)
        async function processBarcode(barcode) {
            hideError();
            
            try {
                const response = await fetch(`/API/get_product_by_barcode.php?barcode=${encodeURIComponent(barcode)}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (!response.ok || !data.ok) {
                    showError(data.error || 'Produit non trouvé');
                    return;
                }
                
                // Produit trouvé : ouvrir la modale détail + focus sur quantité mouvement
                if (typeof window.openDetailForProduct === 'function') {
                    window.openDetailForProduct(data.type, data.product.id);
                    showResult('✓ ' + (data.product.nom || barcode) + ' — Stock: ' + (data.product.qty_stock || 0));
                } else {
                    showResult('✓ Produit trouvé : ' + (data.product.nom || barcode) + ' (Stock: ' + (data.product.qty_stock || 0) + ')');
                }
                
            } catch (err) {
                console.error('Erreur récupération produit:', err);
                showError('Erreur réseau');
            }
        }
        
        // Fonctions helper pour afficher/masquer les messages
        function showResult(html) {
            if (scanResult && scanResultContent) {
                scanResultContent.innerHTML = html;
                scanResult.style.display = 'block';
            }
        }
        
        function hideResult() {
            if (scanResult) {
                scanResult.style.display = 'none';
            }
        }
        
        function showError(message) {
            if (scanError && scanErrorText) {
                scanErrorText.textContent = message;
                scanError.style.display = 'block';
            }
        }
        
        function hideError() {
            if (scanError) {
                scanError.style.display = 'none';
            }
        }
        
        
        // Nettoyer à la fermeture de la page
        window.addEventListener('beforeunload', function() {
            stopScanning();
        });
    })();
</script>
</body>
</html>
