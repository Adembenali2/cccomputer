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

// ==================================================================
// Compteurs réels depuis la BDD
// ==================================================================
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM paiements WHERE statut = 'en_attente'");
    $nb_paiements_en_attente = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur SQL (paiements): " . $e->getMessage());
    $nb_paiements_en_attente = 0;
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM sav WHERE statut = 'a_traiter'");
    $nb_sav_a_traiter = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur SQL (sav): " . $e->getMessage());
    $nb_sav_a_traiter = 0;
}

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM livraisons WHERE statut = 'en_attente'");
    $nb_livraisons_a_faire = (int)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur SQL (livraisons): " . $e->getMessage());
    $nb_livraisons_a_faire = 0;
}

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
                email,
                adresse,
                code_postal,
                ville,
                adresse_livraison,
                livraison_identique,
                siret,
                numero_tva,
                depot_mode,
                telephone1,
                telephone2,
                parrain,
                offre,
                date_creation,
                date_dajout,
                pdf1, pdf2, pdf3, pdf4, pdf5,
                pdfcontrat,
                iban
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
</head>
<body class="page-dashboard">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Tableau de Bord</h2>

            <div class="import-badge" id="importBadge" aria-live="polite" title="État du dernier import SFTP">
                <span class="ico run" id="impIco">⏳</span>
                <span class="txt" id="impTxt">Import SFTP : vérification…</span>
            </div>
        </div>

        <div class="dashboard-grid">
            <div class="dash-card" data-href="paiements.php" tabindex="0" role="button" aria-label="Voir les paiements en attente">
                <div class="card-icon payments" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
                <h3 class="card-title">Paiements</h3>
                <p class="card-count <?= htmlspecialchars($payClass, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($nb_paiements_en_attente, ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>

            <div class="dash-card" data-href="sav.php" tabindex="0" role="button" aria-label="Accéder au SAV">
                <div class="card-icon sav" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <h3 class="card-title">SAV</h3>
                <p class="card-count"><?= htmlspecialchars($nb_sav_a_traiter, ENT_QUOTES, 'UTF-8') ?></p>
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
                <p class="card-count"><?= htmlspecialchars($nb_livraisons_a_faire, ENT_QUOTES, 'UTF-8') ?></p>
            </div>

            <div class="dash-card" data-href="clients.php" id="clientsCard" tabindex="0" role="button" aria-label="Accéder aux clients">
                <div class="card-icon clients" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h3 class="card-title">Clients</h3>
                <p class="card-count"><?= htmlspecialchars($nbClients, ENT_QUOTES, 'UTF-8') ?></p>
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

    <!-- Popup Support -->
    <div class="popup-overlay" id="supportOverlay"></div>
    <div class="support-popup" id="supportPopup" role="dialog" aria-labelledby="popupTitle" aria-modal="true">
        <div class="popup-header">
            <h3 class="popup-title" id="popupTitle">Liste des Clients</h3>
            <button class="close-btn" id="closePopup" aria-label="Fermer">&times;</button>
        </div>

        <div id="clientListView">
            <input type="text" id="clientSearchInput" class="client-search-bar" placeholder="🔍 Rechercher un client..." aria-label="Rechercher un client"/>
            <div class="clients-list" id="clientsList">
                <?php foreach ($clients as $client): ?>
                    <a href="#" class="client-card" 
                       data-client-id="<?= htmlspecialchars($client['id'], ENT_QUOTES, 'UTF-8') ?>"
                       data-raison-l="<?= htmlspecialchars(strtolower($client['raison_sociale'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-nom-l="<?= htmlspecialchars(strtolower($client['nom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-prenom-l="<?= htmlspecialchars(strtolower($client['prenom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-numero-l="<?= htmlspecialchars(strtolower($client['numero_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       aria-label="Voir la fiche de <?= htmlspecialchars($client['raison_sociale'] ?? 'Client', ENT_QUOTES, 'UTF-8') ?>">
                        <div class="client-info">
                            <strong><?= htmlspecialchars($client['raison_sociale'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($client['nom_dirigeant'] ?? '', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($client['prenom_dirigeant'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                            <span><?= htmlspecialchars($client['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Vue attribution photocopieuse -->
        <div class="client-assign-view" id="clientAssignView" style="display:none;" aria-hidden="true">
            <div class="cdv-wrap">
                <div class="cdv-header">
                    <div class="cdv-title">Attribuer une photocopieuse</div>
                    <div class="cdv-sub">Client : <span id="assign-client-name">—</span></div>
                </div>
                <form method="post" action="/api/attribuer_photocopieur.php" class="assign-form">
                    <input type="hidden" name="id_client" id="assign-id-client" value="">

                    <div class="cdv-field">
                        <div class="lbl">Adresse MAC de la photocopieuse</div>
                        <div class="val">
                            <input type="text" name="mac_address" id="assign-mac-address"
                                   placeholder="Ex : AA:BB:CC:DD:EE:FF"
                                   required />
                        </div>
                    </div>

                    <div class="cdv-actions">
                        <button type="submit" class="btn-primary">Attribuer</button>
                        <button type="button" class="btn-secondary" id="assign-cancel-btn">Annuler</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="client-detail-view" id="clientDetailView" role="region" aria-label="Détails du client" aria-hidden="true">
            <div class="cdv-wrap">
                <div class="cdv-sidebar">
                    <button class="cdv-back" id="cdvBackBtn" aria-label="Retour à la liste">← Retour</button>
                    <button class="cdv-nav-btn" data-tab="home" aria-selected="true">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        </svg>
                        Accueil
                    </button>
                    <button class="cdv-nav-btn" data-tab="info" aria-selected="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <line x1="12" y1="16" x2="12" y2="12"/>
                            <line x1="12" y1="8" x2="12.01" y2="8"/>
                        </svg>
                        Informations
                    </button>
                    <button class="cdv-nav-btn" data-tab="devices" aria-selected="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="2" y="3" width="20" height="14" rx="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        Appareils
                    </button>
                </div>
                <div class="cdv-main">
                    <div class="cdv-tab" data-tab="home">
                        <div class="cdv-header">
                            <div>
                                <div class="cdv-title" id="cf-raison_sociale">—</div>
                                <div class="cdv-sub">N° <span id="cf-numero_client">—</span></div>
                            </div>
                        </div>
                        <div class="cdv-grid">
                            <div class="cdv-field">
                                <div class="lbl">Dirigeant</div>
                                <div class="val">
                                    <span id="cf-prenom_dirigeant">—</span> <span id="cf-nom_dirigeant">—</span>
                                </div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Email</div>
                                <div class="val" id="cf-email">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Téléphone 1</div>
                                <div class="val" id="cf-telephone1">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Offre</div>
                                <div class="val" id="cf-offre">—</div>
                            </div>
                        </div>
                    </div>

                    <div class="cdv-tab" data-tab="info" style="display:none;">
                        <div class="cdv-header">
                            <div class="cdv-title">Informations complètes</div>
                        </div>
                        <div class="cdv-grid">
                            <div class="cdv-field"><div class="lbl">Adresse</div><div class="val" id="cf-adresse">—</div></div>
                            <div class="cdv-field"><div class="lbl">Code Postal</div><div class="val" id="cf-code_postal">—</div></div>
                            <div class="cdv-field"><div class="lbl">Ville</div><div class="val" id="cf-ville">—</div></div>
                            <div class="cdv-field"><div class="lbl">Adresse Livraison</div><div class="val" id="cf-adresse_livraison">—</div></div>
                            <div class="cdv-field"><div class="lbl">Livraison Identique</div><div class="val" id="cf-livraison_identique">—</div></div>
                            <div class="cdv-field"><div class="lbl">SIRET</div><div class="val" id="cf-siret">—</div></div>
                            <div class="cdv-field"><div class="lbl">N° TVA</div><div class="val" id="cf-numero_tva">—</div></div>
                            <div class="cdv-field"><div class="lbl">Mode Dépôt</div><div class="val" id="cf-depot_mode">—</div></div>
                            <div class="cdv-field"><div class="lbl">Téléphone 2</div><div class="val" id="cf-telephone2">—</div></div>
                            <div class="cdv-field"><div class="lbl">Parrain</div><div class="val" id="cf-parrain">—</div></div>
                            <div class="cdv-field"><div class="lbl">Date Création</div><div class="val" id="cf-date_creation">—</div></div>
                            <div class="cdv-field"><div class="lbl">Date Ajout</div><div class="val" id="cf-date_dajout">—</div></div>
                            <div class="cdv-field"><div class="lbl">IBAN</div><div class="val" id="cf-iban">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF 1</div><div class="val" id="cf-pdf1">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF 2</div><div class="val" id="cf-pdf2">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF 3</div><div class="val" id="cf-pdf3">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF 4</div><div class="val" id="cf-pdf4">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF 5</div><div class="val" id="cf-pdf5">—</div></div>
                            <div class="cdv-field"><div class="lbl">PDF Contrat</div><div class="val" id="cf-pdfcontrat">—</div></div>
                        </div>
                    </div>

                    <div class="cdv-tab" data-tab="devices" style="display:none;">
                        <div class="cdv-header">
                            <div class="cdv-title">Appareils liés</div>
                        </div>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Série</th><th>MAC</th><th>Modèle</th><th>Statut</th>
                                    <th>Toner N</th><th>Toner C</th><th>Toner M</th><th>Toner Y</th>
                                    <th>Total N&B</th><th>Total Couleur</th><th>Dernière MAJ</th>
                                </tr>
                            </thead>
                            <tbody id="devicesTbody">
                                <tr><td colspan="11">Chargement...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button class="support-btn" id="supportButton" aria-label="Ouvrir le support clients">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
            <circle cx="9" cy="7" r="4"/>
            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
            <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
        </svg>
        <span class="support-badge"><?= htmlspecialchars($nbClients, ENT_QUOTES, 'UTF-8') ?></span>
    </button>

    <script>
    // Données clients fournies côté serveur
    const CLIENTS_DATA = <?= json_encode($clients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

    // --- Ouverture / fermeture popup ---
    (function() {
        const btn = document.getElementById('supportButton');
        const overlay = document.getElementById('supportOverlay');
        const popup = document.getElementById('supportPopup');
        const closeBtn = document.getElementById('closePopup');
        const clientsCard = document.getElementById('clientsCard');

        function openPopup(e){
            if(e) e.preventDefault();
            overlay.classList.add('active');
            popup.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closePopup(e){
            if(e) e.preventDefault();
            overlay.classList.remove('active');
            popup.classList.remove('active');
            document.body.style.overflow = '';
        }

        // SEUL le bouton flottant ouvre la popup
        btn && btn.addEventListener('click', openPopup);

        overlay && overlay.addEventListener('click', closePopup);
        closeBtn && closeBtn.addEventListener('click', closePopup);

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && popup.classList.contains('active')) {
                closePopup();
            }
        });
    })();

    // --- Recherche côté client ---
    (function(){
        const input = document.getElementById('clientSearchInput');
        const list  = document.getElementById('clientsList');
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

    // --- Fiche client + attribution photocopieuse ---
    (function(){
        const listView   = document.getElementById('clientListView');
        const detailView = document.getElementById('clientDetailView');
        const assignView = document.getElementById('clientAssignView');
        const list       = document.getElementById('clientsList');
        if(!list || !detailView || !listView) return;

        const navButtons = detailView.querySelectorAll('.cdv-nav-btn[data-tab]');

        function activateTab(tab){
            navButtons.forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab === tab)));
            detailView.querySelectorAll('.cdv-tab').forEach(p => {
                p.style.display = (p.dataset.tab === tab) ? 'block' : 'none';
            });
        }

        function showDetail(){
            listView.style.display   = 'none';
            if (assignView) {
                assignView.style.display = 'none';
                assignView.setAttribute('aria-hidden','true');
            }
            detailView.style.display = 'block';
            detailView.setAttribute('aria-hidden','false');
            document.getElementById('popupTitle').textContent = 'Fiche Client';
            activateTab('home');
        }

        function showList(){
            detailView.style.display = 'none';
            detailView.setAttribute('aria-hidden','true');
            if (assignView) {
                assignView.style.display = 'none';
                assignView.setAttribute('aria-hidden','true');
            }
            listView.style.display   = 'block';
            document.getElementById('popupTitle').textContent = 'Liste des Clients';
        }

        const backBtn = document.getElementById('cdvBackBtn');
        if (backBtn) {
            backBtn.addEventListener('click', function(e){
                e.preventDefault();
                showList();
            });
        }

        navButtons.forEach(btn => btn.addEventListener('click', function(){
            activateTab(this.dataset.tab);
        }));

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

            Object.keys(map).forEach(k => {
                const el = document.getElementById('cf-'+k);
                if(!el) return;
                let v = map[k];
                if(!v || v === null || v === '') v = '—';

                if(k.startsWith('pdf') || k === 'pdfcontrat'){
                    if(v !== '—') {
                        const safe = String(v)
                            .replace(/"/g,'&quot;')
                            .replace(/</g,'&lt;')
                            .replace(/>/g,'&gt;');
                        el.innerHTML = '<a href="'+safe+'" target="_blank" rel="noopener noreferrer">Ouvrir</a>';
                    } else {
                        el.textContent = '—';
                    }
                } else {
                    el.textContent = v;
                }
            });
        }

        function fillDevices(devices){
            const tbody = document.getElementById('devicesTbody');
            if (!tbody) return;
            tbody.innerHTML = '';

            if(!devices || devices.length === 0){
                tbody.innerHTML = '<tr><td colspan="11">Aucun appareil lié.</td></tr>';
                return;
            }

            devices.forEach(d => {
                const tr = document.createElement('tr');
                const td = (t) => {
                    const x = document.createElement('td');
                    x.textContent = (t ?? '—');
                    return x;
                };

                tr.appendChild(td(d.SerialNumber));
                tr.appendChild(td(d.MacAddress));
                tr.appendChild(td(d.Model));
                tr.appendChild(td(d.Status));
                tr.appendChild(td(d.TonerBlack != null ? d.TonerBlack + '%' : '—'));
                tr.appendChild(td(d.TonerCyan  != null ? d.TonerCyan  + '%' : '—'));
                tr.appendChild(td(d.TonerMagenta != null ? d.TonerMagenta + '%' : '—'));
                tr.appendChild(td(d.TonerYellow  != null ? d.TonerYellow  + '%' : '—'));
                tr.appendChild(td(d.TotalBW));
                tr.appendChild(td(d.TotalColor));
                tr.appendChild(td(d.Timestamp));
                tbody.appendChild(tr);
            });
        }

        async function loadClientDetail(id){
            const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(id)) || {};
            fillClientFields(client);
            showDetail();

            const tbody = document.getElementById('devicesTbody');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="11">Chargement...</td></tr>';
            }

            try {
                const res = await fetch('/api/client_devices.php?id=' + encodeURIComponent(id), {
                    credentials: 'same-origin'
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const data = await res.json();
                if (Array.isArray(data)) {
                    fillDevices(data);
                } else {
                    fillDevices([]);
                }
            } catch (err) {
                console.error('Erreur chargement appareils', err);
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="11">Erreur de chargement des appareils.</td></tr>';
                }
            }
        }

        // Gestion formulaire attribution : bouton Annuler
        const assignCancelBtn = document.getElementById('assign-cancel-btn');
        if (assignCancelBtn && assignView && listView) {
            assignCancelBtn.addEventListener('click', function(e){
                e.preventDefault();
                assignView.style.display = 'none';
                assignView.setAttribute('aria-hidden','true');
                listView.style.display = 'block';
                document.getElementById('popupTitle').textContent = 'Liste des Clients';
            });
        }

        if (list) {
            async function handleClientClick(card) {
                const id = card.dataset.clientId;
                if (!id) return;

                const clientName = card.querySelector('strong')?.textContent || 'Client';

                try {
                    const res = await fetch('/api/get_client_photocopieur.php?id=' + encodeURIComponent(id), {
                        credentials: 'same-origin'
                    });
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    const data = await res.json();

                    if (data.ok && data.assigned && data.redirect_url) {
                        // Le client a déjà une photocopieuse → redirection
                        window.location.href = data.redirect_url;
                        return;
                    }

                    // Pas de photocopieuse, on affiche le formulaire d’attribution
                    if (assignView && listView) {
                        listView.style.display = 'none';
                        detailView.style.display = 'none';
                        detailView.setAttribute('aria-hidden','true');
                        assignView.style.display = 'block';
                        assignView.setAttribute('aria-hidden','false');

                        const assignNameSpan = document.getElementById('assign-client-name');
                        const assignIdInput  = document.getElementById('assign-id-client');
                        if (assignNameSpan) assignNameSpan.textContent = clientName;
                        if (assignIdInput)  assignIdInput.value = id;

                        document.getElementById('popupTitle').textContent = 'Attribuer une photocopieuse';
                    }

                } catch (err) {
                    console.error('Erreur get_client_photocopieur', err);
                    // En cas d’erreur, retour au comportement fiche client classique
                    loadClientDetail(id);
                }
            }

            list.addEventListener('click', function(e){
                const card = e.target.closest('.client-card');
                if(!card) return;
                e.preventDefault();
                handleClientClick(card);
            });

            // Accessibilité clavier
            list.addEventListener('keydown', function(e){
                if (e.key !== 'Enter' && e.key !== ' ') return;
                const card = e.target.closest('.client-card');
                if(!card) return;
                e.preventDefault();
                handleClientClick(card);
            });
        }
    })();

    // --- Import auto silencieux SFTP + badge (tick 20s, batch 10) ---
    (function(){
        const SFTP_URL  = '/import/run_import_if_due.php';

        const badge = document.getElementById('importBadge');
        const ico   = document.getElementById('impIco');
        const txt   = document.getElementById('impTxt');

        function setState(state, label, titleFiles) {
            ico.classList.remove('ok','run','fail');

            if (state === 'ok') {
                ico.textContent = '✓';
                ico.classList.add('ok');
            } else if (state === 'run') {
                ico.textContent = '⏳';
                ico.classList.add('run');
            } else if (state === 'fail') {
                ico.textContent = '!';
                ico.classList.add('fail');
            } else {
                ico.textContent = '⏳';
                ico.classList.add('run');
            }

            if (label) txt.textContent = label;
            if (titleFiles && Array.isArray(titleFiles) && titleFiles.length) {
                badge.title = 'Fichiers ajoutés : ' + titleFiles.join(', ');
            }
        }

        async function callJSON(url){
            try{
                const res = await fetch(url, {method:'POST', credentials:'same-origin'});
                const text = await res.text();
                let data = null;
                try { data = text ? JSON.parse(text) : null; } catch(e){}
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
                    setState('none', 'Import SFTP : —');
                    return;
                }

                const files = (d.summary && d.summary.files) ? d.summary.files : null;

                if (d.ok === 1) {
                    const label = `Import SFTP OK — ${d.imported} élément(s) — ${d.ran_at}` + (d.recent ? ' (récent)' : '');
                    setState('ok', label, files);
                } else {
                    const label = `Import SFTP KO — ${d.ran_at}`;
                    setState('fail', label, files);
                }
            } catch(e){
                setState('fail', 'Import SFTP : erreur de lecture');
            }
        }

        async function tick(){
            await callJSON(SFTP_URL + '?limit=10'); // on ne déclenche QUE le SFTP
            setTimeout(refresh, 1500);
        }

        tick();        // premier run
        refresh();     // premier badge
        setInterval(tick, 20000); // toutes les 20s
    })();
    </script>
</body>
</html>
