<?php
// /public/dashboard.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// ==================================================================
// Historique des actions (requêtes SQL réelles)
// ==================================================================
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM historique");
    $nHistorique = (string)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de requête SQL (nHistorique) : " . $e->getMessage());
    $nHistorique = 'Erreur';
}

try {
    $sql = "SELECT DATE(date_action) AS date, COUNT(*) AS total_historique
            FROM historique
            GROUP BY DATE(date_action)
            ORDER BY date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $historique_par_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de requête SQL (historique_par_jour) : " . $e->getMessage());
    $historique_par_jour = [];
}

// Compteurs “dummy”
$nb_paiements_en_attente = 3;
$nb_sav_a_traiter        = 5;
$nb_livraisons_a_faire   = 8;
$payClass = ($nb_paiements_en_attente > 0) ? 'count-bad' : 'count-ok';

// ==================================================================
// Récupération clients depuis la BDD
// ==================================================================
try {
    $sql = "SELECT 
                id,
                numero_client,
                raison_sociale,
                nom_dirigeant,
                prenom_dirigeant,
                email
            FROM clients
            ORDER BY raison_sociale ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $nbClients = is_array($clients) ? count($clients) : 0;
} catch (PDOException $e) {
    error_log('Erreur SQL (clients): ' . $e->getMessage());
    $clients = [];
    $nbClients = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Dashboard - CCComputer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="/assets/js/dashboard.js" defer></script>
    <style>
        .popup-content { display: flex; flex-direction: column; gap: 12px; }
        .clients-list { display: grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap: 10px; }
        .client-card { display: block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; text-decoration: none; color: inherit; background:#fff; transition: box-shadow .15s, transform .05s; }
        .client-card:hover { box-shadow: 0 6px 18px #00000014; transform: translateY(-1px); }
        .client-info strong { display:block; font-size: 15px; margin-bottom: 4px; }
        .client-info span { display:block; font-size: 13px; color:#4b5563; }

        .client-detail-view { display:none; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background:#fff; }
        .cdv-wrap { display: grid; grid-template-columns: 200px 1fr; min-height: 420px; }
        .cdv-sidebar { background:#f9fafb; border-right:1px solid #e5e7eb; padding: 12px; display:flex; flex-direction:column; gap:6px; }
        .cdv-sidebar .cdv-back { margin-bottom:8px; }
        .cdv-nav-btn { display:flex; align-items:center; gap:8px; padding:8px 10px; border-radius:8px; border:none; background:transparent; cursor:pointer; text-align:left; font-size:14px; }
        .cdv-nav-btn[aria-selected="true"] { background:#eef2ff; color:#4338ca; font-weight:600; }
        .cdv-main { padding: 14px; }
        .cdv-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .cdv-title { font-size:18px; font-weight:700; }
        .cdv-sub { font-size:13px; color:#6b7280; }
        .cdv-grid { display:grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap:10px; }
        .cdv-field { border:1px solid #e5e7eb; border-radius:10px; padding:10px; background:#fff; }
        .cdv-field .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; }
        .cdv-field .val { font-size:14px; margin-top:4px; word-break: break-word; }

        .client-search-bar { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px; }
        .popup-header { display:flex; align-items:center; justify-content:space-between; }

        .table { width:100%; border-collapse: collapse; }
        .table th, .table td { border:1px solid #e5e7eb; padding:8px 10px; font-size:13px; }
        .table th { background:#f3f4f6; text-align:left; }
        .chip { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid #e5e7eb; }

        .dashboard-header { position: relative; }
        .import-badge {
            position:absolute; right:0; top:2px;
            display:flex; align-items:center; gap:8px;
            background:rgba(255,255,255,.85);
            -webkit-backdrop-filter:saturate(180%) blur(6px);
            backdrop-filter:saturate(180%) blur(6px);
            border:1px solid #e5e7eb;
            box-shadow: 0 4px 12px rgba(0,0,0,.06);
            border-radius:999px;
            padding:6px 10px;
            font-size:12px; color:#374151;
            user-select:none;
        }
        .import-badge .ico {
            width:18px; height:18px; display:inline-flex; align-items:center; justify-content:center;
            border-radius:999px; font-weight:700; line-height:1;
        }
        .import-badge .ico.ok { background:#dcfce7; color:#16a34a; }
        .import-badge .ico.run { background:#e0e7ff; color:#4338ca; }
        .import-badge .ico.fail{ background:#fee2e2; color:#dc2626; }
        .import-badge .txt { white-space:nowrap; max-width:40vw; overflow:hidden; text-overflow:ellipsis; }
        @media (max-width: 640px){
            .import-badge .txt { display:none; }
        }
        .import-badge[hidden]{ display:none !important; }
    </style>
</head>
<body class="page-dashboard">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Tableau de Bord</h2>

            <div class="import-badge" id="importBadge" aria-live="polite" title="État du dernier import">
                <span class="ico run" id="impIco">⏳</span>
                <span class="txt" id="impTxt">Import : vérification…</span>
            </div>
        </div>

        <div class="dashboard-grid">
            <!-- Cartes inchangées -->
            <div class="dash-card" data-href="paiements.php" tabindex="0" role="button" aria-label="Voir les paiements en attente">
                <div class="card-icon payments" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
                <h3 class="card-title">Paiements</h3>
                <p class="card-count <?= htmlspecialchars($payClass, ENT_QUOTES, 'UTF-8') ?>"><?= (int)$nb_paiements_en_attente ?></p>
            </div>

            <div class="dash-card" data-href="sav.php" tabindex="0" role="button" aria-label="Accéder au SAV">
                <div class="card-icon sav" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <h3 class="card-title">SAV</h3>
                <p class="card-count"><?= (int)$nb_sav_a_traiter ?></p>
            </div>

            <div class="dash-card" data-href="livraison.php" tabindex="0" role="button" aria-label="Accéder aux livraisons">
                <div class="card-icon deliveries" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                        <rect x="1" y="3" width="15" height="13"/>
                        <polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/>
                        <circle cx="5.5" cy="18.5" r="2.5"/>
                        <circle cx="18.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <h3 class="card-title">Livraisons</h3>
                <p class="card-count"><?= (int)$nb_livraisons_a_faire ?></p>
            </div>

            <div class="dash-card" data-href="clients.php" tabindex="0" role="button" aria-label="Accéder aux clients">
                <div class="card-icon clients" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h3 class="card-title">Clients</h3>
                <p class="card-count"><?= (int)$nbClients ?></p>
            </div>

            <div class="dash-card" data-href="stock.php" tabindex="0" role="button" aria-label="Accéder au stock">
                <div class="card-icon stock" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ec4899" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27,6.96 12,12.01 20.73,6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <h3 class="card-title">Stock</h3>
                <div class="card-multi-count" aria-label="Indicateurs stock">
                    <div class="count-item" title="Catégories">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <span>3</span>
                    </div>
                    <div class="count-item" title="Références actives">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <circle cx="12" cy="12" r="6"/>
                            <circle cx="12" cy="12" r="2"/>
                        </svg>
                        <span>124</span>
                    </div>
                    <div class="count-item" title="Alertes stock">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10,9 9,9 8,9"/>
                        </svg>
                        <span>89</span>
                    </div>
                </div>
            </div>

            <div class="dash-card" data-href="historique.php" tabindex="0" role="button" aria-label="Accéder aux historiques">
                <div class="card-icon history" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12,6 12,12 16,14"/>
                    </svg>
                </div>
                <h3 class="card-title">Historiques</h3>
                <p class="card-count"><?= htmlspecialchars($nHistorique, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <script>
    // --- Ouverture / fermeture popup ---
    (function() {
        const btn = document.getElementById('supportButton');
        const overlay = document.getElementById('supportOverlay');
        const popup = document.getElementById('supportPopup');
        const closeBtn = document.getElementById('closePopup');

        function openPopup(e){ if(e) e.preventDefault(); overlay.classList.add('open'); popup.classList.add('open'); }
        function closePopup(e){ if(e) e.preventDefault(); overlay.classList.remove('open'); popup.classList.remove('open'); }

        btn && btn.addEventListener('click', openPopup);
        overlay && overlay.addEventListener('click', closePopup);
        closeBtn && closeBtn.addEventListener('click', closePopup);
    })();

    // --- Recherche côté client ---
    (function(){
        const input = document.getElementById('clientSearchInput');
        const list = document.getElementById('clientsList');
        if(!input || !list) return;

        input.addEventListener('input', function(){
            const q = this.value.trim().toLowerCase();
            list.querySelectorAll('.client-card').forEach(card => {
                const hay = [
                    card.dataset['raisonL'],
                    card.dataset['nomL'],
                    card.dataset['prenomL'],
                    card.dataset['numeroL']
                ].join(' ');
                card.style.display = hay.includes(q) ? '' : 'none';
            });
        });
    })();

    // --- Fiche client (inchangé) ---
    (function(){
        const listView   = document.getElementById('clientListView');
        const detailView = document.getElementById('clientDetailView');
        const list       = document.getElementById('clientsList');
        if(!list || !detailView || !listView) return;

        const navButtons = detailView.querySelectorAll('.cdv-nav-btn[data-tab]');
        function activateTab(tab){
            navButtons.forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
            detailView.querySelectorAll('.cdv-tab').forEach(p => { p.style.display = (p.dataset.tab === tab) ? 'block' : 'none'; });
        }

        function showDetail(){ listView.style.display='none'; detailView.style.display='block'; detailView.setAttribute('aria-hidden','false'); document.getElementById('popupTitle').textContent='Fiche Client'; activateTab('home'); }
        function showList(){ detailView.style.display='none'; detailView.setAttribute('aria-hidden','true'); listView.style.display='block'; document.getElementById('popupTitle').textContent='Liste des Clients'; }

        document.getElementById('cdvBackBtn').addEventListener('click', function(e){ e.preventDefault(); showList(); });

        navButtons.forEach(btn => btn.addEventListener('click', function(){ activateTab(this.dataset.tab); }));

        function fillClientFields(client){
            const map = {
                numero_client: client.numero_client,
                raison_sociale: client.raison_sociale,
                adresse: client.adresse,
                code_postal: client.code_postal,
                ville: client.ville,
                adresse_livraison: client.adresse_livraison,
                livraison_identique: client.livraison_identique ? 'Oui' : 'Non',
                siret: client.siret,
                numero_tva: client.numero_tva,
                depot_mode: client.depot_mode,
                nom_dirigeant: client.nom_dirigeant,
                prenom_dirigeant: client.prenom_dirigeant,
                telephone1: client.telephone1,
                telephone2: client.telephone2,
                email: client.email,
                parrain: client.parrain,
                offre: client.offre,
                date_creation: client.date_creation,
                date_dajout: client.date_dajout,
                pdf1: client.pdf1, pdf2: client.pdf2, pdf3: client.pdf3, pdf4: client.pdf4, pdf5: client.pdf5,
                pdfcontrat: client.pdfcontrat,
                iban: client.iban
            };
            Object.keys(map).forEach(k=>{
                const el = document.getElementById('cf-'+k);
                if(!el) return;
                let v = map[k];
                if(!v || v===null) v='—';
                if(k.startsWith('pdf') || k==='pdfcontrat'){
                    if(v !== '—') {
                        const safe = String(v).replace(/"/g,'&quot;');
                        el.innerHTML = '<a href="'+safe+'" target="_blank" rel="noopener">Ouvrir</a>';
                    } else el.textContent = '—';
                } else {
                    el.textContent = v;
                }
            });
        }

        function fillDevices(devices){
            const tbody = document.getElementById('devicesTbody');
            tbody.innerHTML = '';
            if(!devices || devices.length===0){
                tbody.innerHTML = '<tr><td colspan="11">Aucun appareil lié.</td></tr>';
                return;
            }
            devices.forEach(d=>{
                const tr = document.createElement('tr');
                const td = (t)=>{ const x=document.createElement('td'); x.textContent = (t ?? '—'); return x; };
                tr.appendChild(td(d.SerialNumber));
                tr.appendChild(td(d.MacAddress));
                tr.appendChild(td(d.Model));
                tr.appendChild(td(d.Status));
                tr.appendChild(td(d.TonerBlack!=null? d.TonerBlack+'%':'—'));
                tr.appendChild(td(d.TonerCyan!=null? d.TonerCyan+'%':'—'));
                tr.appendChild(td(d.TonerMagenta!=null? d.TonerMagenta+'%':'—'));
                tr.appendChild(td(d.TonerYellow!=null? d.TonerYellow+'%':'—'));
                tr.appendChild(td(d.TotalBW));
                tr.appendChild(td(d.TotalColor));
                tr.appendChild(td(d.Timestamp));
                tbody.appendChild(tr);
            });
        }

        async function loadClientDetail(id){
            document.getElementById('cdvTitle').textContent = 'Chargement…';
            document.getElementById('cdvSub').textContent   = '';
            fillClientFields({});
            fillDevices([]);

            try{
                const res = await fetch('/ajax/client_detail.php?id='+encodeURIComponent(id), { credentials:'same-origin' });
                if(!res.ok) throw new Error('HTTP '+res.status);
                const data = await res.json();

                if(!data || !data.client){
                    document.getElementById('cdvTitle').textContent='Client introuvable';
                    return;
                }
                const c = data.client;

                document.getElementById('cdvTitle').textContent = c.raison_sociale || 'Client';
                document.getElementById('cdvSub').textContent   = [c.nom_dirigeant, c.prenom_dirigeant, '·', c.numero_client].filter(Boolean).join(' ');

                fillClientFields(c);
                fillDevices(data.devices || []);
            } catch(err){
                console.error(err);
                document.getElementById('cdvTitle').textContent = 'Erreur de chargement';
                document.getElementById('cdvSub').textContent   = 'Impossible de récupérer les données.';
            }
        }

        list.addEventListener('click', function(e){
            const card = e.target.closest('.client-card');
            if(!card) return;
            e.preventDefault();
            const id = card.dataset.clientId;
            showDetail();
            loadClientDetail(id);
        });
    })();

    // --- Import auto silencieux + badge (tick 20s, batch 10 + 10) ---
    (function(){
        const SFTP_URL  = '/import/run_import_if_due.php';  // public
        // ⚠️ IONOS passe maintenant par l’endpoint “tout-en-un” /import/ionos_sync.php
        const IONOS_URL = '/import/ionos_sync.php';

        const badge = document.getElementById('importBadge');
        const ico   = document.getElementById('impIco');
        const txt   = document.getElementById('impTxt');

        function setState(state, label, titleFiles) {
            ico.classList.remove('ok','run','fail');
            if (state === 'ok')   { ico.textContent = '✓'; ico.classList.add('ok'); }
            else if (state === 'run') { ico.textContent = '⏳'; ico.classList.add('run'); }
            else if (state === 'fail'){ ico.textContent = '!';  ico.classList.add('fail'); }
            else { ico.textContent = '⏳'; ico.classList.add('run'); }

            if (label) txt.textContent = label;
            if (titleFiles && Array.isArray(titleFiles) && titleFiles.length) {
                badge.title = 'Fichiers ajoutés : ' + titleFiles.join(', ');
            }
        }

        async function callJSON(url){
            try{
                const res = await fetch(url, {
                    method:'POST',
                    credentials:'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                const text = await res.text();
                let data = null; try{ data = text ? JSON.parse(text) : null; }catch(e){}
                if(!res.ok){
                    console.error(`[IMPORT] ${url} → ${res.status} ${res.statusText}`, data || text);
                    return { ok:false, status:res.status, body:(data||text) };
                }
                return { ok:true, status:res.status, body:data };
            }catch(err){
                console.error(`[IMPORT] ${url} → fetch failed`, err);
                return { ok:false, error:String(err) };
            }
        }

        async function refresh(){
            try{
                const r = await fetch('/import/last_import.php', {credentials:'same-origin'});
                if (!r.ok) throw new Error('HTTP '+r.status);
                const d = await r.json();

                if (!d || !d.has_run) {
                    setState('none', 'Import : —');
                    return;
                }

                const files = (d.summary && d.summary.files) ? d.summary.files : null;

                if (d.ok === 1) {
                    const label = `Import OK — ${d.imported} élément(s) — ${d.ran_at}` + (d.recent ? ' (récent)' : '');
                    setState('ok', label, files);
                } else {
                    const label = `Import KO — ${d.ran_at}`;
                    setState('fail', label, files);
                }
            } catch(e){
                setState('fail', 'Import : erreur de lecture');
            }
        }

        async function tick(){
            // Lance SFTP (batch 10) + IONOS (latest, batch 10). L’endpoint IONOS ignore si "not_due".
            const [sftp, ionos] = await Promise.allSettled([
                callJSON(SFTP_URL + '?limit=10'),
                callJSON(IONOS_URL + '?limit=10') // mode=latest par défaut dans ionos_sync.php
            ]);
            // Petite latence pour laisser le worker écrire dans import_run
            setTimeout(refresh, 1500);
        }

        tick();        // premier run
        refresh();     // premier badge
        setInterval(tick, 20000); // toutes les 20s
    })();
    </script>
</body>
</html>
