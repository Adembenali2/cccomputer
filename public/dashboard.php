<?php
// /public/dashboard.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// ==================================================================
// Historique des actions (requêtes SQL réelles)
// ==================================================================
// 1. Nombre total d'historiques
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM historique");
    $nHistorique = (string)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de requête SQL (nHistorique) : " . $e->getMessage());
    $nHistorique = 'Erreur';
}

// 2. Historique par jour (si tu l’utilises côté JS/graph)
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

// Classe de couleur pour le compteur Paiements
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
    <!-- Chemins absolus -->
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="/assets/js/dashboard.js" defer></script>
    <style>
        /* --------- Petits ajouts pour le popup en 2 colonnes --------- */
        .popup-content { display: flex; flex-direction: column; gap: 12px; }
        .clients-list { display: grid; grid-template-columns: repeat(auto-fill,minmax(260px,1fr)); gap: 10px; }
        .client-card { display: block; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px; text-decoration: none; color: inherit; background:#fff; transition: box-shadow .15s, transform .05s; }
        .client-card:hover { box-shadow: 0 6px 18px #00000014; transform: translateY(-1px); }
        .client-info strong { display:block; font-size: 15px; margin-bottom: 4px; }
        .client-info span { display:block; font-size: 13px; color:#4b5563; }

        .client-detail-view { display:none; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; background:#fff; }
        .cdv-wrap { display: grid; grid-template-columns: 200px 1fr; min-height: 360px; }
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
        .cdv-field .val { font-size:14px; margin-top:4px; }

        .client-search-bar { width:100%; padding:10px 12px; border:1px solid #e5e7eb; border-radius:10px; font-size:14px; }
        .popup-header { display:flex; align-items:center; justify-content:space-between; }

        /* Overlay & popup déjà présents dans votre CSS global */
    </style>
</head>
<body class="page-dashboard">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Tableau de Bord</h2>
        </div>

        <div class="dashboard-grid">
            <!-- Paiements -->
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

            <!-- SAV -->
            <div class="dash-card" data-href="sav.php" tabindex="0" role="button" aria-label="Accéder au SAV">
                <div class="card-icon sav" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <h3 class="card-title">SAV</h3>
                <p class="card-count"><?= (int)$nb_sav_a_traiter ?></p>
            </div>

            <!-- Livraisons -->
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

            <!-- Clients -->
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

            <!-- Stock -->
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

            <!-- Historiques -->
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

    <!-- Bouton Support -->
    <a href="#" class="support-btn" id="supportButton" aria-label="Support client">
        <span class="support-badge"><?= (int)$nbClients ?></span>
        <svg width="32" height="32" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="8" r="5" fill="currentColor" fill-opacity="0.22"/>
        <path d="M3.5 10a8.5 8.5 0 0 1 17 0"/>
        <rect x="1.5" y="9" width="5" height="8" rx="2.5" ry="2.5"/>
        <rect x="17.5" y="9" width="5" height="8" rx="2.5" ry="2.5"/>
        <path d="M18.5 16.5a3.5 3.5 0 0 1-3.5 3.5H11"/>
        <circle cx="10.5" cy="20" r="0.9" fill="currentColor"/>
        <path d="M4 22a8 4 0 0 1 16 0"/>
        </svg>
    </a>

    <!-- Popup Support -->
    <div class="popup-overlay" id="supportOverlay"></div>
    <div class="support-popup" id="supportPopup" role="dialog" aria-modal="true" aria-labelledby="popupTitle">
        <div class="popup-header">
            <h3 class="popup-title" id="popupTitle">Liste des Clients</h3>
            <button class="close-btn" id="closePopup" aria-label="Fermer la fenêtre">&times;</button>
        </div>

        <div class="popup-content">
            <!-- Vue LISTE -->
            <div id="clientListView">
                <input
                    type="text"
                    id="clientSearchInput"
                    class="client-search-bar"
                    placeholder="Filtrer par nom, raison sociale, prénom ou numéro client…"
                    autocomplete="off"
                    aria-label="Rechercher un client"
                >
                <div class="clients-list" id="clientsList">
                    <?php foreach ($clients as $client): ?>
                        <?php
                            $cId     = (int)($client['id'] ?? 0);
                            $raison  = htmlspecialchars($client['raison_sociale']   ?? '', ENT_QUOTES, 'UTF-8');
                            $nom     = htmlspecialchars($client['nom_dirigeant']    ?? '', ENT_QUOTES, 'UTF-8');
                            $prenom  = htmlspecialchars($client['prenom_dirigeant'] ?? '', ENT_QUOTES, 'UTF-8');
                            $numero  = htmlspecialchars($client['numero_client']    ?? '', ENT_QUOTES, 'UTF-8');
                            $email   = htmlspecialchars($client['email']            ?? '', ENT_QUOTES, 'UTF-8');

                            // Data-* pour recherche & pour remplir la fiche
                            $dNom    = htmlspecialchars(strtolower($client['nom_dirigeant']    ?? ''), ENT_QUOTES, 'UTF-8');
                            $dPrenom = htmlspecialchars(strtolower($client['prenom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8');
                            $dRaison = htmlspecialchars(strtolower($client['raison_sociale']   ?? ''), ENT_QUOTES, 'UTF-8');
                            $dNum    = htmlspecialchars(strtolower($client['numero_client']    ?? ''), ENT_QUOTES, 'UTF-8');
                        ?>
                        <a href="#"
                           class="client-card"
                           data-client-id="<?= $cId ?>"
                           data-raison="<?= $raison ?>"
                           data-nom="<?= $nom ?>"
                           data-prenom="<?= $prenom ?>"
                           data-numero="<?= $numero ?>"
                           data-email="<?= $email ?>"
                           data-nom-l="<?= $dNom ?>"
                           data-prenom-l="<?= $dPrenom ?>"
                           data-raison-l="<?= $dRaison ?>"
                           data-numero-l="<?= $dNum ?>"
                           aria-label="Ouvrir la fiche du client <?= $raison ?>">
                            <div class="client-info">
                                <strong><?= $raison ?></strong>
                                <span><?= $nom ?> <?= $prenom ?></span>
                                <span><?= $numero ?></span>
                                <span><?= $email ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Vue FICHE -->
            <div id="clientDetailView" class="client-detail-view" aria-hidden="true">
                <div class="cdv-wrap">
                    <aside class="cdv-sidebar" role="tablist" aria-label="Sections de la fiche client">
                        <button class="cdv-nav-btn cdv-back" id="cdvBackBtn" type="button">
                            ← Retour à la liste
                        </button>
                        <button class="cdv-nav-btn" data-tab="home" role="tab" aria-selected="true">Accueil</button>
                        <button class="cdv-nav-btn" data-tab="call" role="tab" aria-selected="false">Appel</button>
                        <button class="cdv-nav-btn" data-tab="sav" role="tab" aria-selected="false">SAV</button>
                        <button class="cdv-nav-btn" data-tab="buy" role="tab" aria-selected="false">Achat</button>
                    </aside>

                    <section class="cdv-main">
                        <div class="cdv-header">
                            <div>
                                <div class="cdv-title" id="cdvTitle">Client</div>
                                <div class="cdv-sub" id="cdvSub"></div>
                            </div>
                            <div class="cdv-actions">
                                <!-- Actions futures si besoin -->
                            </div>
                        </div>

                        <!-- Contenu des onglets -->
                        <div id="cdvTab-home" class="cdv-tab" data-tab="home">
                            <div class="cdv-grid">
                                <div class="cdv-field"><div class="lbl">Raison sociale</div><div class="val" id="f-raison">—</div></div>
                                <div class="cdv-field"><div class="lbl">Numéro client</div><div class="val" id="f-numero">—</div></div>
                                <div class="cdv-field"><div class="lbl">Nom dirigeant</div><div class="val" id="f-nom">—</div></div>
                                <div class="cdv-field"><div class="lbl">Prénom dirigeant</div><div class="val" id="f-prenom">—</div></div>
                                <div class="cdv-field"><div class="lbl">Email</div><div class="val" id="f-email">—</div></div>
                                <div class="cdv-field"><div class="lbl">ID</div><div class="val" id="f-id">—</div></div>
                            </div>
                        </div>

                        <div id="cdvTab-call" class="cdv-tab" data-tab="call" style="display:none;">
                            <div class="cdv-field">
                                <div class="lbl">Historique d’appels</div>
                                <div class="val">À intégrer (liste d’appels, dernier contact, etc.).</div>
                            </div>
                        </div>

                        <div id="cdvTab-sav" class="cdv-tab" data-tab="sav" style="display:none;">
                            <div class="cdv-field">
                                <div class="lbl">SAV</div>
                                <div class="val">À intégrer (tickets SAV liés au client).</div>
                            </div>
                        </div>

                        <div id="cdvTab-buy" class="cdv-tab" data-tab="buy" style="display:none;">
                            <div class="cdv-field">
                                <div class="lbl">Achats</div>
                                <div class="val">À intégrer (commandes / factures du client).</div>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
            <!-- /Vue FICHE -->
        </div>
    </div>

    <script>
    // --- Popup open/close from existing dashboard.js triggers (fallback here) ---
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

    // --- Fiche client dans le même popup ---
    (function(){
        const listView = document.getElementById('clientListView');
        const detailView = document.getElementById('clientDetailView');
        const list = document.getElementById('clientsList');
        if(!list || !detailView || !listView) return;

        // Champs de la fiche
        const f = {
            id:     document.getElementById('f-id'),
            raison: document.getElementById('f-raison'),
            numero: document.getElementById('f-numero'),
            nom:    document.getElementById('f-nom'),
            prenom: document.getElementById('f-prenom'),
            email:  document.getElementById('f-email'),
            title:  document.getElementById('cdvTitle'),
            sub:    document.getElementById('cdvSub')
        };

        function showDetail(){
            listView.style.display = 'none';
            detailView.style.display = 'block';
            detailView.setAttribute('aria-hidden','false');
            document.getElementById('popupTitle').textContent = 'Fiche Client';
            activateTab('home');
        }
        function showList(){
            detailView.style.display = 'none';
            detailView.setAttribute('aria-hidden','true');
            listView.style.display = 'block';
            document.getElementById('popupTitle').textContent = 'Liste des Clients';
        }

        // Ouvrir une fiche
        list.addEventListener('click', function(e){
            const card = e.target.closest('.client-card');
            if(!card) return;
            e.preventDefault();

            // Remplissage
            f.id.textContent     = card.dataset.clientId || '—';
            f.raison.textContent = card.dataset.raison || '—';
            f.numero.textContent = card.dataset.numero || '—';
            f.nom.textContent    = card.dataset.nom || '—';
            f.prenom.textContent = card.dataset.prenom || '—';
            f.email.textContent  = card.dataset.email || '—';

            f.title.textContent  = card.dataset.raison || 'Client';
            f.sub.textContent    = (card.dataset.nom || '') + ' ' + (card.dataset.prenom || '') + ' · ' + (card.dataset.numero || '');

            showDetail();
        });

        // Retour
        document.getElementById('cdvBackBtn').addEventListener('click', function(e){
            e.preventDefault();
            showList();
        });

        // Gestion des onglets
        const navButtons = detailView.querySelectorAll('.cdv-nav-btn[data-tab]');
        navButtons.forEach(btn => {
            btn.addEventListener('click', function(){
                activateTab(this.dataset.tab);
            });
        });

        function activateTab(tab){
            // aria-selected
            navButtons.forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
            // afficher le bon contenu
            detailView.querySelectorAll('.cdv-tab').forEach(p => {
                p.style.display = (p.dataset.tab === tab) ? 'block' : 'none';
            });
        }
    })();
    </script>
</body>
</html>
