<?php
// /public/dashboard.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('dashboard', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// Les fonctions safeFetchColumn(), safeFetchAll(), ensureCsrfToken() sont définies dans includes/helpers.php

/** CSRF minimal **/
// La fonction ensureCsrfToken() est définie dans includes/helpers.php

// Générer un token CSRF si manquant
ensureCsrfToken();

// ==================================================================
// Historique des actions (requêtes SQL réelles)
// ==================================================================
$nHistorique = (string)(safeFetchColumn(
    $pdo,
    "SELECT COUNT(*) FROM historique",
    [],
    'Erreur',
    'historique_count'
) ?? 'Erreur');

$historique_par_jour = safeFetchAll(
    $pdo,
    "SELECT DATE(date_action) AS date, COUNT(*) AS total_historique
     FROM historique
     GROUP BY DATE(date_action)
     ORDER BY date DESC",
    [],
    'historique_par_jour'
);

// ==================================================================
// Compteurs réels depuis la BDD
// ==================================================================
// Pour SAV, les statuts ENUM sont: 'ouvert','en_cours','resolu','annule'
// On compte les SAV ouverts et en cours comme "à traiter"
$nb_sav_a_traiter = (int)(safeFetchColumn(
    $pdo,
    "SELECT COUNT(*) FROM sav WHERE statut IN (:stat1, :stat2)",
    ['stat1' => 'ouvert', 'stat2' => 'en_cours'],
    0,
    'sav_a_traiter'
) ?? 0);

// Pour livraisons, les statuts ENUM sont: 'planifiee','en_cours','livree','annulee'
// On compte les livraisons planifiées et en cours comme "à faire"
$nb_livraisons_a_faire = (int)(safeFetchColumn(
    $pdo,
    "SELECT COUNT(*) FROM livraisons WHERE statut IN (:stat1, :stat2)",
    ['stat1' => 'planifiee', 'stat2' => 'en_cours'],
    0,
    'livraisons_a_faire'
) ?? 0);

// ==================================================================
// Récupération clients depuis la BDD (optimisé pour performance)
// ==================================================================
// Utilisation de cache partagé (APCu ou fichier) pour améliorer les performances
require_once __DIR__ . '/../includes/CacheHelper.php';

// Charger la configuration centralisée
$config = require __DIR__ . '/../config/app.php';
$limit = $config['limits']['clients_per_page'] ?? 500;
$cacheTtl = $config['limits']['cache_ttl'] ?? 300;

// Récupérer l'ID utilisateur depuis la session (défini dans auth.php)
$user_id = currentUserId() ?? 0;
$cacheKey = 'dashboard_clients_list_' . md5($user_id);
$clients = CacheHelper::get($cacheKey, null);

if ($clients === null) {
    $clients = safeFetchAll(
        $pdo,
        "SELECT 
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
        ORDER BY raison_sociale ASC
        LIMIT :limit",
        [':limit' => $limit],
        'clients_list'
    );
    
    // Sauvegarder dans le cache
    CacheHelper::set($cacheKey, $clients, $cacheTtl);
}

$nbClients = is_array($clients) ? count($clients) : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Dashboard - CCComputer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/dashboard.js" defer></script>
    <script>
        // CSRF token pour les requêtes AJAX
        window.CSRF_TOKEN = '<?= htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8') ?>';
    </script>
</head>
<body class="page-dashboard">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Tableau de Bord</h2>
            <button class="btn-check-import" id="checkImportBtn" aria-label="Vérifier l'import">
                Vérifier l'import
            </button>
        </div>

        <div class="dashboard-grid">
            <div class="dash-card" data-href="/public/sav.php" tabindex="0" role="button" aria-label="Accéder au SAV">
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

            <div class="dash-card" data-href="paiements.php" tabindex="0" role="button" aria-label="Accéder aux paiements">
                <div class="card-icon payments" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                        <path d="M7 14h.01M11 14h2"/>
                    </svg>
                </div>
                <h3 class="card-title">Paiements</h3>
                <p class="card-count">—</p>
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
                    <form method="post" action="/API/clients/attribuer_photocopieur.php" class="assign-form">
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
                    <button class="cdv-nav-btn" data-tab="livraison" aria-selected="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="3" width="15" height="13"/>
                            <polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/>
                            <circle cx="5.5" cy="18.5" r="2.5"/>
                            <circle cx="18.5" cy="18.5" r="2.5"/>
                        </svg>
                        Livraisons
                    </button>
                    <button class="cdv-nav-btn" data-tab="sav" aria-selected="false">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                        </svg>
                        SAV
                    </button>
                </div>
                <div class="cdv-main">
                    <div class="cdv-tab" data-tab="home">
                        <div class="cdv-header">
                            <div>
                                <div class="cdv-title" id="cf-raison_sociale">—</div>
                                <div class="cdv-sub">N° client : <span id="cf-numero_client">—</span></div>
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
                                <div class="val" id="cf-email-home">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Téléphone</div>
                                <div class="val" id="cf-telephone1-home">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Offre</div>
                                <div class="val" id="cf-offre-home">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Ville</div>
                                <div class="val" id="cf-ville-home">—</div>
                            </div>
                            <div class="cdv-field">
                                <div class="lbl">Code Postal</div>
                                <div class="val" id="cf-code_postal-home">—</div>
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

                    <div class="cdv-tab" data-tab="livraison" style="display:none;">
                        <div class="cdv-header">
                            <div class="cdv-title">Gestion des livraisons</div>
                            <div class="cdv-sub">Client : <span id="livraison-client-name">—</span></div>
                        </div>
                        
                        <!-- Liste des livraisons existantes -->
                        <div id="deliveryListContainer" style="margin-bottom: 1.5rem;">
                            <h4 style="margin-bottom: 0.5rem;">Livraisons existantes</h4>
                            <div id="deliveryList" style="max-height: 300px; overflow-y: auto;">
                                <p class="hint">Chargement...</p>
                            </div>
                        </div>

                        <!-- Formulaire de nouvelle livraison -->
                        <div id="deliveryFormContainer">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0;">Nouvelle livraison</h4>
                                <button type="button" id="toggleDeliveryForm" class="btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.9rem;">➕ Ajouter</button>
                            </div>
                            
                            <form id="deliveryForm" style="display: none;" class="standard-form">
                                <input type="hidden" id="deliveryClientId" name="client_id">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                
                                <div class="form-row">
                                    <label>Référence*</label>
                                    <input type="text" id="deliveryReference" name="reference" required 
                                           placeholder="Ex: LIV-2024-001" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                
                                <div class="form-row">
                                    <label>Adresse de livraison*</label>
                                    <textarea id="deliveryAddress" name="adresse_livraison" required rows="2"
                                              placeholder="Adresse complète de livraison" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <label>Type de produit*</label>
                                    <select id="deliveryProductType" name="product_type" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">-- Sélectionner le type --</option>
                                        <option value="papier">Papier</option>
                                        <option value="toner">Toner</option>
                                        <option value="lcd">LCD</option>
                                        <option value="pc">PC</option>
                                        <option value="autre">Autre</option>
                                    </select>
                                </div>
                                
                                <div id="deliveryProductContainer" class="form-row" style="display: none;">
                                    <label>Produit spécifique*</label>
                                    <select id="deliveryProduct" name="product_id" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">-- Chargement... --</option>
                                    </select>
                                </div>
                                
                                <div id="deliveryQuantityContainer" class="form-row" style="display: none;">
                                    <label>Quantité*</label>
                                    <input type="number" id="deliveryQuantity" name="product_qty" min="1" value="1" required 
                                           placeholder="Quantité à livrer" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                    <small style="color: #666; font-size: 0.85rem;">Cette quantité sera déduite du stock et enregistrée pour le client.</small>
                                </div>
                                
                                <div class="form-row">
                                    <label>Objet / Description*</label>
                                    <input type="text" id="deliveryObjet" name="objet" required 
                                           placeholder="Description de la livraison" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                
                                <div class="form-row">
                                    <label>Livreur*</label>
                                    <select id="deliveryLivreur" name="id_livreur" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">-- Chargement... --</option>
                                    </select>
                                </div>
                                
                                <div class="form-row">
                                    <label>Date prévue*</label>
                                    <input type="date" id="deliveryDatePrevue" name="date_prevue" required 
                                           style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                
                                <div class="form-row">
                                    <label>Commentaire</label>
                                    <textarea id="deliveryCommentaire" name="commentaire" rows="3"
                                              placeholder="Notes supplémentaires..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                                </div>
                                
                                <div id="deliveryError" class="error-message" style="display: none; padding: 0.5rem; background: #fee2e2; color: #dc2626; border-radius: 4px; margin-bottom: 1rem;"></div>
                                
                                <div class="form-actions" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                    <button type="submit" class="btn-primary">✅ Créer la livraison</button>
                                    <button type="button" id="cancelDeliveryForm" class="btn-secondary">Annuler</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="cdv-tab" data-tab="sav" style="display:none;">
                        <div class="cdv-header">
                            <div class="cdv-title">Gestion des SAV</div>
                            <div class="cdv-sub">Client : <span id="sav-client-name">—</span></div>
                        </div>
                        
                        <!-- Liste des SAV existants -->
                        <div id="savListContainer" style="margin-bottom: 1.5rem;">
                            <h4 style="margin-bottom: 0.5rem;">SAV existants</h4>
                            <div id="savList" style="max-height: 300px; overflow-y: auto;">
                                <p class="hint">Chargement...</p>
                            </div>
                        </div>

                        <!-- Formulaire de nouveau SAV -->
                        <div id="savFormContainer">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                                <h4 style="margin: 0;">Nouveau SAV</h4>
                                <button type="button" id="toggleSavForm" class="btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.9rem;">➕ Ajouter</button>
                            </div>
                            
                            <form id="savForm" style="display: none;" class="standard-form">
                                <input type="hidden" id="savClientId" name="client_id">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(ensureCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                
                                <div class="form-row">
                                    <label>Référence*</label>
                                    <input type="text" id="savReference" name="reference" required 
                                           placeholder="Ex: SAV-2024-001" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                
                                <div class="form-row">
                                    <label>Description du problème*</label>
                                    <textarea id="savDescription" name="description" required rows="4"
                                              placeholder="Décrivez le problème rencontré..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                                </div>
                                
                                <div class="form-row">
                                    <label>Priorité*</label>
                                    <select id="savPriorite" name="priorite" required style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="normale">Normale</option>
                                        <option value="basse">Basse</option>
                                        <option value="haute">Haute</option>
                                        <option value="urgente">Urgente</option>
                                    </select>
                                </div>
                                
                                <div class="form-row">
                                    <label>Type de panne</label>
                                    <select id="savTypePanne" name="type_panne" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">— Non spécifié —</option>
                                        <option value="logiciel">Logiciel</option>
                                        <option value="materiel">Matériel</option>
                                        <option value="piece_rechangeable">Pièce rechargeable</option>
                                    </select>
                                </div>
                                
                                <div class="form-row">
                                    <label>Technicien</label>
                                    <select id="savTechnicien" name="id_technicien" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                        <option value="">-- Non assigné --</option>
                                    </select>
                                </div>
                                
                                <div class="form-row">
                                    <label>Date d'ouverture*</label>
                                    <input type="date" id="savDateOuverture" name="date_ouverture" required 
                                           style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">
                                </div>
                                
                                <div class="form-row">
                                    <label>Commentaire</label>
                                    <textarea id="savCommentaire" name="commentaire" rows="3"
                                              placeholder="Notes supplémentaires..." style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;"></textarea>
                                </div>
                                
                                <div id="savError" class="error-message" style="display: none; padding: 0.5rem; background: #fee2e2; color: #dc2626; border-radius: 4px; margin-bottom: 1rem;"></div>
                                
                                <div class="form-actions" style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                                    <button type="submit" class="btn-primary">✅ Créer le SAV</button>
                                    <button type="button" id="cancelSavForm" class="btn-secondary">Annuler</button>
                                </div>
                            </form>
                        </div>
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

    // Fonction pour afficher une notification toast (partagée entre SFTP et IONOS)
    function showNotification(title, message, type = 'info') {
        // Créer l'élément de notification
        const notification = document.createElement('div');
        notification.className = `sftp-notification sftp-notification-${type}`;
        notification.innerHTML = `
            <div class="sftp-notification-content">
                <strong>${title}</strong>
                <span>${message}</span>
            </div>
            <button class="sftp-notification-close" aria-label="Fermer">&times;</button>
        `;
        
        // Ajouter au body
        document.body.appendChild(notification);
        
        // Animation d'entrée
        setTimeout(() => {
            notification.classList.add('sftp-notification-show');
        }, 10);
        
        // Fermeture automatique après 5 secondes
        const autoClose = setTimeout(() => {
            closeNotification(notification);
        }, 5000);
        
        // Bouton de fermeture
        const closeBtn = notification.querySelector('.sftp-notification-close');
        closeBtn.addEventListener('click', () => {
            clearTimeout(autoClose);
            closeNotification(notification);
        });
    }
    
    function closeNotification(notification) {
        notification.classList.remove('sftp-notification-show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }

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

            // Reset recherche pour afficher tous les clients
            const input = document.getElementById('clientSearchInput');
            const list  = document.getElementById('clientsList');
            if (input && list) {
                input.value = '';
                list.querySelectorAll('.client-card').forEach(card => {
                    card.style.display = '';
                });
            }
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

        function formatDate(dateStr) {
            if (!dateStr || dateStr === '—') return '—';
            try {
                const dt = new Date(dateStr);
                return dt.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
            } catch (e) {
                return dateStr;
            }
        }

        function formatOffre(offre) {
            if (!offre) return '—';
            const map = { 'packbronze': 'Pack Bronze', 'packargent': 'Pack Argent' };
            return map[offre] || offre;
        }

        function formatDepotMode(mode) {
            if (!mode) return '—';
            const map = { 'espece': 'Espèces', 'cheque': 'Chèque', 'virement': 'Virement', 'paiement_carte': 'Carte' };
            return map[mode] || mode;
        }

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
                depot_mode: formatDepotMode(client.depot_mode),
                nom_dirigeant: client.nom_dirigeant,
                prenom_dirigeant: client.prenom_dirigeant,
                telephone1: client.telephone1,
                telephone2: client.telephone2,
                email: client.email,
                parrain: client.parrain,
                offre: formatOffre(client.offre),
                date_creation: formatDate(client.date_creation),
                date_dajout: formatDate(client.date_dajout),
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
                        el.innerHTML = '<a href="'+safe+'" target="_blank" rel="noopener noreferrer" class="pdf-link">📄 Ouvrir le PDF</a>';
                    } else {
                        el.textContent = '—';
                    }
                } else if(k === 'email' && v !== '—') {
                    el.innerHTML = '<a href="mailto:' + v.replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '" class="email-link">' + v.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>';
                } else if(k === 'telephone1' || k === 'telephone2') {
                    if(v !== '—') {
                        el.innerHTML = '<a href="tel:' + v.replace(/[^\d+]/g,'') + '" class="tel-link">' + v.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>';
                    } else {
                        el.textContent = '—';
                    }
                } else {
                    el.textContent = v;
                }
                
                // Mise à jour de l'onglet home
                if (k === 'email') {
                    const homeEl = document.getElementById('cf-email-home');
                    if (homeEl && v !== '—') {
                        homeEl.innerHTML = '<a href="mailto:' + v.replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '" class="email-link">' + v.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>';
                    } else if (homeEl) {
                        homeEl.textContent = '—';
                    }
                }
                if (k === 'telephone1') {
                    const homeEl = document.getElementById('cf-telephone1-home');
                    if (homeEl && v !== '—') {
                        homeEl.innerHTML = '<a href="tel:' + v.replace(/[^\d+]/g,'') + '" class="tel-link">' + v.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</a>';
                    } else if (homeEl) {
                        homeEl.textContent = '—';
                    }
                }
                if (k === 'offre') {
                    const homeEl = document.getElementById('cf-offre-home');
                    if (homeEl) homeEl.textContent = formatOffre(client.offre) || '—';
                }
                if (k === 'ville') {
                    const homeEl = document.getElementById('cf-ville-home');
                    if (homeEl) homeEl.textContent = v;
                }
                if (k === 'code_postal') {
                    const homeEl = document.getElementById('cf-code_postal-home');
                    if (homeEl) homeEl.textContent = v;
                }
                if (k === 'raison_sociale') {
                    const homeEl = document.getElementById('cf-raison_sociale');
                    if (homeEl) homeEl.textContent = v;
                }
                if (k === 'numero_client') {
                    const homeEl = document.getElementById('cf-numero_client');
                    if (homeEl) homeEl.textContent = v;
                }
                if (k === 'prenom_dirigeant') {
                    const homeEl = document.getElementById('cf-prenom_dirigeant');
                    if (homeEl) homeEl.textContent = v;
                }
                if (k === 'nom_dirigeant') {
                    const homeEl = document.getElementById('cf-nom_dirigeant');
                    if (homeEl) homeEl.textContent = v;
                }
            });
        }

        function loadClientDetail(id){
            const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(id)) || {};
            fillClientFields(client);
            
            // Mettre à jour l'ID client dans le formulaire de livraison
            const deliveryClientIdEl = document.getElementById('deliveryClientId');
            if (deliveryClientIdEl) {
                deliveryClientIdEl.value = id;
            }
            
            // Mettre à jour l'ID client dans le formulaire de SAV
            const savClientIdEl = document.getElementById('savClientId');
            if (savClientIdEl) {
                savClientIdEl.value = id;
            }
            
            // Mettre à jour le nom du client dans l'onglet livraison
            const clientNameEl = document.getElementById('livraison-client-name');
            if (clientNameEl && client.raison_sociale) {
                clientNameEl.textContent = client.raison_sociale;
            }
            
            // Mettre à jour le nom du client dans l'onglet SAV
            const savClientNameEl = document.getElementById('sav-client-name');
            if (savClientNameEl && client.raison_sociale) {
                savClientNameEl.textContent = client.raison_sociale;
            }
            
            showDetail();
            
            // Charger les livreurs
            loadLivreurs();
            
            // Charger les techniciens
            loadTechniciens();
            
            // Si l'onglet livraison est actif, charger les livraisons
            const livraisonTab = document.querySelector('.cdv-nav-btn[data-tab="livraison"]');
            if (livraisonTab && livraisonTab.getAttribute('aria-selected') === 'true') {
                loadDeliveries(id);
            }
            
            // Si l'onglet sav est actif, charger les SAV
            const savTab = document.querySelector('.cdv-nav-btn[data-tab="sav"]');
            if (savTab && savTab.getAttribute('aria-selected') === 'true') {
                const clientIdNum = parseInt(id, 10);
                if (clientIdNum && clientIdNum > 0) {
                    loadSavs(clientIdNum);
                }
            }
        }
        
        // Charger les livraisons du client
        async function loadDeliveries(clientId) {
            const deliveryList = document.getElementById('deliveryList');
            if (!deliveryList) return;
            
            deliveryList.innerHTML = '<p class="hint">Chargement...</p>';
            
            try {
                const data = await apiClient.json(`/API/dashboard_get_deliveries.php?client_id=${clientId}`, {
                    method: 'GET'
                }, {
                    abortKey: 'load_deliveries_' + clientId
                });
                
                if (!data.ok) {
                    deliveryList.innerHTML = `<p class="hint" style="color: #dc2626;">Erreur: ${data.error || 'Erreur de chargement'}</p>`;
                    return;
                }
                
                const livraisons = data.livraisons || [];
                
                if (livraisons.length === 0) {
                    deliveryList.innerHTML = '<p class="hint">Aucune livraison pour ce client.</p>';
                    return;
                }
                
                deliveryList.innerHTML = '';
                livraisons.forEach(liv => {
                    const statutLabels = {
                        'planifiee': 'Planifiée',
                        'en_cours': 'En cours',
                        'livree': 'Livrée',
                        'annulee': 'Annulée'
                    };
                    const statutColors = {
                        'planifiee': '#f59e0b',
                        'en_cours': '#3b82f6',
                        'livree': '#16a34a',
                        'annulee': '#dc2626'
                    };
                    
                    const item = document.createElement('div');
                    item.style.cssText = 'padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 0.5rem; background: #f9fafb;';
                    item.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <div>
                                <strong>${escapeHtml(liv.reference)}</strong>
                                <span style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: ${statutColors[liv.statut] || '#666'}; color: white; font-size: 0.75rem;">
                                    ${statutLabels[liv.statut] || liv.statut}
                                </span>
                            </div>
                        </div>
                        <div style="font-size: 0.9rem; margin-bottom: 0.25rem;">
                            <strong>Objet:</strong> ${escapeHtml(liv.objet)}
                        </div>
                        <div style="font-size: 0.85rem; color: #666; margin-bottom: 0.25rem;">
                            <strong>Adresse:</strong> ${escapeHtml(liv.adresse_livraison)}
                        </div>
                        <div style="font-size: 0.85rem; color: #666;">
                            <strong>Date prévue:</strong> ${escapeHtml(liv.date_prevue)}
                            ${liv.livreur_nom ? `<br><strong>Livreur:</strong> ${escapeHtml(liv.livreur_prenom + ' ' + liv.livreur_nom)}` : ''}
                        </div>
                    `;
                    deliveryList.appendChild(item);
                });
                
            } catch (err) {
                if (err.name !== 'AbortError') {
                    showNotification('Erreur de chargement des livraisons', 'error');
                }
                deliveryList.innerHTML = '<p class="hint" style="color: #dc2626;">Erreur de chargement des livraisons.</p>';
            }
        }
        
        // Charger les livreurs
        async function loadLivreurs() {
            const select = document.getElementById('deliveryLivreur');
            if (!select) return;
            
            try {
                const data = await apiClient.json('/API/dashboard_get_livreurs.php', {
                    method: 'GET'
                }, {
                    abortKey: 'load_livreurs'
                });
                
                if (!data.ok) {
                    select.innerHTML = '<option value="">Erreur de chargement</option>';
                    return;
                }
                
                const livreurs = data.livreurs || [];
                select.innerHTML = '<option value="">-- Sélectionner un livreur --</option>';
                
                livreurs.forEach(liv => {
                    const option = document.createElement('option');
                    option.value = liv.id;
                    option.textContent = liv.full_name + (liv.telephone ? ' (' + liv.telephone + ')' : '');
                    select.appendChild(option);
                });
                
            } catch (err) {
                console.error('Erreur chargement livreurs:', err);
                select.innerHTML = '<option value="">Erreur de chargement</option>';
            }
        }
        
        // Charger les produits du stock par type
        async function loadStockProducts(type) {
            const select = document.getElementById('deliveryProduct');
            const container = document.getElementById('deliveryProductContainer');
            if (!select || !container) return;
            
            if (!type || type === 'autre') {
                container.style.display = 'none';
                select.innerHTML = '<option value="">-- Sélectionner un produit --</option>';
                return;
            }
            
            container.style.display = 'block';
            select.innerHTML = '<option value="">Chargement...</option>';
            
            try {
                const response = await fetch(`/API/dashboard_get_stock_products.php?type=${encodeURIComponent(type)}`);
                const data = await response.json();
                
                if (!data.ok) {
                    select.innerHTML = '<option value="">Erreur de chargement</option>';
                    return;
                }
                
                const products = data.products || [];
                select.innerHTML = '<option value="">-- Sélectionner un produit --</option>';
                
                products.forEach(prod => {
                    const option = document.createElement('option');
                    option.value = prod.id;
                    option.setAttribute('data-type', prod.type);
                    option.textContent = prod.label + ' (Stock: ' + prod.qty_stock + ')';
                    select.appendChild(option);
                });
                
            } catch (err) {
                console.error('Erreur chargement produits:', err);
                select.innerHTML = '<option value="">Erreur de chargement</option>';
            }
        }
        
        // Helper pour échapper HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
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

                // Afficher directement la fiche client
                loadClientDetail(id);
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
        
        // Gestion de l'onglet Livraisons
        const deliveryTab = document.querySelector('.cdv-nav-btn[data-tab="livraison"]');
        const deliveryFormContainer = document.getElementById('deliveryFormContainer');
        const toggleDeliveryForm = document.getElementById('toggleDeliveryForm');
        const cancelDeliveryForm = document.getElementById('cancelDeliveryForm');
        const deliveryForm = document.getElementById('deliveryForm');
        const deliveryProductType = document.getElementById('deliveryProductType');
        
        // Quand on active l'onglet livraison, mettre à jour le nom du client
        deliveryTab && deliveryTab.addEventListener('click', function() {
            const clientId = document.getElementById('deliveryClientId')?.value;
            const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(clientId)) || {};
            const clientNameEl = document.getElementById('livraison-client-name');
            if (clientNameEl && client.raison_sociale) {
                clientNameEl.textContent = client.raison_sociale;
            }
            if (clientId) {
                loadDeliveries(clientId);
            }
        });
        
        // Gestion de l'onglet SAV
        const savTab = document.querySelector('.cdv-nav-btn[data-tab="sav"]');
        const savFormContainer = document.getElementById('savFormContainer');
        const toggleSavForm = document.getElementById('toggleSavForm');
        const cancelSavForm = document.getElementById('cancelSavForm');
        const savForm = document.getElementById('savForm');
        
        // Quand on active l'onglet SAV, mettre à jour le nom du client
        savTab && savTab.addEventListener('click', function() {
            const clientId = document.getElementById('savClientId')?.value;
            const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(clientId)) || {};
            const clientNameEl = document.getElementById('sav-client-name');
            if (clientNameEl && client.raison_sociale) {
                clientNameEl.textContent = client.raison_sociale;
            }
            const clientIdNum = parseInt(clientId, 10);
            if (clientIdNum && clientIdNum > 0) {
                loadSavs(clientIdNum);
            } else {
                const savList = document.getElementById('savList');
                if (savList) {
                    savList.innerHTML = '<p class="hint">Sélectionnez un client d\'abord</p>';
                }
            }
        });
        
        // Toggle formulaire de livraison
        toggleDeliveryForm && toggleDeliveryForm.addEventListener('click', function() {
            if (deliveryForm.style.display === 'none') {
                const clientId = document.getElementById('deliveryClientId')?.value;
                const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(clientId)) || {};
                
                // Préremplir les champs
                if (client.id) {
                    document.getElementById('deliveryClientId').value = client.id;
                    if (client.adresse_livraison) {
                        document.getElementById('deliveryAddress').value = client.adresse_livraison;
                    } else {
                        const address = [client.adresse, client.code_postal, client.ville].filter(Boolean).join(' ');
                        document.getElementById('deliveryAddress').value = address;
                    }
                }
                
                // Générer une référence automatique
                const now = new Date();
                const ref = 'LIV-' + now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(Math.floor(Math.random() * 10000)).padStart(4, '0');
                document.getElementById('deliveryReference').value = ref;
                
                // Définir la date prévue par défaut (aujourd'hui)
                const today = now.toISOString().split('T')[0];
                document.getElementById('deliveryDatePrevue').value = today;
                
                // Charger automatiquement la liste des livreurs depuis la table utilisateurs
                loadLivreurs();
                
                deliveryForm.style.display = 'block';
                toggleDeliveryForm.textContent = '❌ Annuler';
            } else {
                deliveryForm.style.display = 'none';
                deliveryForm.reset();
                document.getElementById('deliveryProductContainer').style.display = 'none';
                toggleDeliveryForm.textContent = '➕ Ajouter';
                document.getElementById('deliveryError').style.display = 'none';
            }
        });
        
        cancelDeliveryForm && cancelDeliveryForm.addEventListener('click', function() {
            deliveryForm.style.display = 'none';
            deliveryForm.reset();
            document.getElementById('deliveryProductContainer').style.display = 'none';
            toggleDeliveryForm.textContent = '➕ Ajouter';
            document.getElementById('deliveryError').style.display = 'none';
        });
        
        // Sélection du type de produit
        deliveryProductType && deliveryProductType.addEventListener('change', function() {
            const type = this.value;
            loadStockProducts(type);
            
            // Afficher/masquer le champ quantité selon le type de produit
            const quantityContainer = document.getElementById('deliveryQuantityContainer');
            if (quantityContainer) {
                if (type && type !== 'autre') {
                    quantityContainer.style.display = 'block';
                } else {
                    quantityContainer.style.display = 'none';
                }
            }
        });
        
        // Afficher le champ quantité quand un produit est sélectionné
        const deliveryProduct = document.getElementById('deliveryProduct');
        deliveryProduct && deliveryProduct.addEventListener('change', function() {
            const quantityContainer = document.getElementById('deliveryQuantityContainer');
            const productContainer = document.getElementById('deliveryProductContainer');
            if (quantityContainer && productContainer && this.value) {
                quantityContainer.style.display = 'block';
                // Mettre à jour la quantité max selon le stock disponible
                const selectedOption = this.options[this.selectedIndex];
                if (selectedOption) {
                    const stockMatch = selectedOption.textContent.match(/Stock: (\d+)/);
                    if (stockMatch) {
                        const maxStock = parseInt(stockMatch[1], 10);
                        const qtyInput = document.getElementById('deliveryQuantity');
                        if (qtyInput) {
                            qtyInput.max = maxStock;
                            if (parseInt(qtyInput.value, 10) > maxStock) {
                                qtyInput.value = maxStock;
                            }
                        }
                    }
                }
            }
        });
        
        // Soumission du formulaire de livraison
        deliveryForm && deliveryForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const errorDiv = document.getElementById('deliveryError');
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
            
            const formData = new FormData(deliveryForm);
            const data = {
                client_id: parseInt(formData.get('client_id'), 10),
                reference: formData.get('reference').trim(),
                adresse_livraison: formData.get('adresse_livraison').trim(),
                objet: formData.get('objet').trim(),
                id_livreur: parseInt(formData.get('id_livreur'), 10),
                date_prevue: formData.get('date_prevue'),
                commentaire: formData.get('commentaire').trim(),
                csrf_token: formData.get('csrf_token')
            };
            
            // Ajouter le produit sélectionné si disponible
            const productType = formData.get('product_type');
            const productId = formData.get('product_id');
            const productQty = formData.get('product_qty');
            if (productType && productId && productType !== 'autre') {
                data.product_type = productType;
                data.product_id = parseInt(productId, 10);
                // Utiliser la quantité saisie, ou 1 par défaut si non spécifiée
                data.product_qty = productQty ? parseInt(productQty, 10) : 1;
                
                // Validation : la quantité doit être > 0
                if (data.product_qty <= 0) {
                    errorDiv.textContent = 'La quantité doit être supérieure à 0';
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Vérifier que la quantité ne dépasse pas le stock disponible
                const qtyInput = document.getElementById('deliveryQuantity');
                if (qtyInput && qtyInput.hasAttribute('data-max')) {
                    const maxStock = parseInt(qtyInput.getAttribute('data-max'), 10);
                    if (data.product_qty > maxStock) {
                        errorDiv.textContent = `La quantité (${data.product_qty}) dépasse le stock disponible (${maxStock})`;
                        errorDiv.style.display = 'block';
                        return;
                    }
                }
            }
            
            try {
                const response = await fetch('/API/dashboard_create_delivery.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (!result.ok) {
                    errorDiv.textContent = result.error || 'Erreur lors de la création de la livraison';
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Succès : recharger les livraisons et cacher le formulaire
                const clientId = data.client_id;
                await loadDeliveries(clientId);
                
                deliveryForm.style.display = 'none';
                deliveryForm.reset();
                document.getElementById('deliveryProductContainer').style.display = 'none';
                document.getElementById('deliveryQuantityContainer').style.display = 'none';
                toggleDeliveryForm.textContent = '➕ Ajouter';
                errorDiv.style.display = 'none';
                
                // Afficher un message de succès temporaire
                const successMsg = document.createElement('div');
                successMsg.style.cssText = 'padding: 0.5rem; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 1rem;';
                successMsg.textContent = '✅ ' + (result.message || 'Livraison créée avec succès');
                deliveryFormContainer.insertBefore(successMsg, deliveryFormContainer.firstChild);
                setTimeout(() => successMsg.remove(), 3000);
                
            } catch (err) {
                console.error('Erreur création livraison:', err);
                errorDiv.textContent = 'Erreur de connexion lors de la création de la livraison';
                errorDiv.style.display = 'block';
            }
        });
        
        // Charger les SAV du client
        async function loadSavs(clientId) {
            const savList = document.getElementById('savList');
            if (!savList) return;
            
            // Vérifier que clientId est valide
            if (!clientId || clientId <= 0) {
                savList.innerHTML = '<p class="hint">ID client invalide</p>';
                return;
            }
            
            savList.innerHTML = '<p class="hint">Chargement...</p>';
            
            try {
                const response = await fetch(`/API/dashboard_get_sav.php?client_id=${clientId}`);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    let errorData = null;
                    try {
                        errorData = JSON.parse(errorText);
                    } catch (e) {
                        // Pas de JSON, utiliser le texte brut
                    }
                    savList.innerHTML = `<p class="hint" style="color: #dc2626;">Erreur ${response.status}: ${errorData?.error || errorText || 'Erreur de chargement'}</p>`;
                    return;
                }
                
                const data = await response.json();
                
                if (!data.ok) {
                    savList.innerHTML = `<p class="hint" style="color: #dc2626;">Erreur: ${data.error || 'Erreur de chargement'}</p>`;
                    return;
                }
                
                const savs = data.savs || [];
                
                if (savs.length === 0) {
                    savList.innerHTML = '<p class="hint">Aucun SAV pour ce client.</p>';
                    return;
                }
                
                savList.innerHTML = '';
                savs.forEach(sav => {
                    const statutLabels = {
                        'ouvert': 'Ouvert',
                        'en_cours': 'En cours',
                        'resolu': 'Résolu',
                        'annule': 'Annulé'
                    };
                    const prioriteLabels = {
                        'basse': 'Basse',
                        'normale': 'Normale',
                        'haute': 'Haute',
                        'urgente': 'Urgente'
                    };
                    const prioriteColors = {
                        'basse': '#6b7280',
                        'normale': '#3b82f6',
                        'haute': '#f59e0b',
                        'urgente': '#dc2626'
                    };
                    const typePanneLabels = {
                        'logiciel': 'Logiciel',
                        'materiel': 'Matériel',
                        'piece_rechangeable': 'Pièce rechargeable'
                    };
                    const typePanneColors = {
                        'logiciel': '#8b5cf6',
                        'materiel': '#ec4899',
                        'piece_rechangeable': '#10b981'
                    };
                    
                    const item = document.createElement('div');
                    item.style.cssText = 'padding: 0.75rem; border: 1px solid #ddd; border-radius: 4px; margin-bottom: 0.5rem; background: #f9fafb;';
                    item.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <div>
                                <strong>${escapeHtml(sav.reference)}</strong>
                                <span style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: ${prioriteColors[sav.priorite] || '#666'}; color: white; font-size: 0.75rem;">
                                    ${prioriteLabels[sav.priorite] || sav.priorite}
                                </span>
                                <span style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: #6b7280; color: white; font-size: 0.75rem;">
                                    ${statutLabels[sav.statut] || sav.statut}
                                </span>
                                ${sav.type_panne ? `<span style="margin-left: 0.5rem; padding: 0.2rem 0.5rem; border-radius: 4px; background: ${typePanneColors[sav.type_panne] || '#666'}; color: white; font-size: 0.75rem;">
                                    ${typePanneLabels[sav.type_panne] || sav.type_panne}
                                </span>` : ''}
                            </div>
                        </div>
                        <div style="font-size: 0.9rem; margin-bottom: 0.25rem;">
                            <strong>Description:</strong> ${escapeHtml(sav.description)}
                        </div>
                        <div style="font-size: 0.85rem; color: #666;">
                            <strong>Date ouverture:</strong> ${escapeHtml(sav.date_ouverture)}
                            ${sav.date_fermeture ? `<br><strong>Date fermeture:</strong> ${escapeHtml(sav.date_fermeture)}` : ''}
                            ${sav.technicien_nom ? `<br><strong>Technicien:</strong> ${escapeHtml(sav.technicien_prenom + ' ' + sav.technicien_nom)}` : '<br><strong>Technicien:</strong> Non assigné'}
                        </div>
                    `;
                    savList.appendChild(item);
                });
                
            } catch (err) {
                console.error('Erreur chargement SAV:', err);
                savList.innerHTML = '<p class="hint" style="color: #dc2626;">Erreur de chargement des SAV.</p>';
            }
        }
        
        // Charger les techniciens
        async function loadTechniciens() {
            const select = document.getElementById('savTechnicien');
            if (!select) return;
            
            try {
                const data = await apiClient.json('/API/dashboard_get_techniciens.php', {
                    method: 'GET'
                }, {
                    abortKey: 'load_techniciens'
                });
                
                if (!data.ok) {
                    select.innerHTML = '<option value="">Erreur de chargement</option>';
                    return;
                }
                
                const techniciens = data.techniciens || [];
                select.innerHTML = '<option value="">-- Non assigné --</option>';
                
                techniciens.forEach(tech => {
                    const option = document.createElement('option');
                    option.value = tech.id;
                    option.textContent = tech.full_name + (tech.telephone ? ' (' + tech.telephone + ')' : '');
                    select.appendChild(option);
                });
                
            } catch (err) {
                if (err.name !== 'AbortError') {
                    showNotification('Erreur de chargement des techniciens', 'error');
                }
                select.innerHTML = '<option value="">Erreur de chargement</option>';
            }
        }
        
        // Toggle formulaire de SAV
        toggleSavForm && toggleSavForm.addEventListener('click', function() {
            if (savForm.style.display === 'none') {
                const clientId = document.getElementById('savClientId')?.value;
                const client = (CLIENTS_DATA || []).find(c => String(c.id) === String(clientId)) || {};
                
                // Préremplir les champs
                if (client.id) {
                    document.getElementById('savClientId').value = client.id;
                }
                
                // Générer une référence automatique
                const now = new Date();
                const ref = 'SAV-' + now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-' + String(Math.floor(Math.random() * 10000)).padStart(4, '0');
                document.getElementById('savReference').value = ref;
                
                // Définir la date d'ouverture par défaut (aujourd'hui)
                const today = now.toISOString().split('T')[0];
                document.getElementById('savDateOuverture').value = today;
                
                // Charger automatiquement la liste des techniciens
                loadTechniciens();
                
                savForm.style.display = 'block';
                toggleSavForm.textContent = '❌ Annuler';
            } else {
                savForm.style.display = 'none';
                savForm.reset();
                toggleSavForm.textContent = '➕ Ajouter';
                document.getElementById('savError').style.display = 'none';
            }
        });
        
        cancelSavForm && cancelSavForm.addEventListener('click', function() {
            savForm.style.display = 'none';
            savForm.reset();
            toggleSavForm.textContent = '➕ Ajouter';
            document.getElementById('savError').style.display = 'none';
        });
        
        // Soumission du formulaire de SAV
        savForm && savForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const errorDiv = document.getElementById('savError');
            errorDiv.style.display = 'none';
            errorDiv.textContent = '';
            
            const formData = new FormData(savForm);
            const data = {
                client_id: parseInt(formData.get('client_id'), 10),
                reference: formData.get('reference').trim(),
                description: formData.get('description').trim(),
                priorite: formData.get('priorite'),
                type_panne: formData.get('type_panne').trim(),
                date_ouverture: formData.get('date_ouverture'),
                commentaire: formData.get('commentaire').trim(),
                csrf_token: formData.get('csrf_token')
            };
            
            // Ajouter le technicien si sélectionné
            const technicienId = formData.get('id_technicien');
            if (technicienId) {
                data.id_technicien = parseInt(technicienId, 10);
            }
            
            try {
                const response = await fetch('/API/dashboard_create_sav.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (!result.ok) {
                    errorDiv.textContent = result.error || 'Erreur lors de la création du SAV';
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Succès : recharger les SAV et cacher le formulaire
                const clientId = data.client_id;
                await loadSavs(clientId);
                
                savForm.style.display = 'none';
                savForm.reset();
                toggleSavForm.textContent = '➕ Ajouter';
                errorDiv.style.display = 'none';
                
                // Afficher un message de succès temporaire
                const successMsg = document.createElement('div');
                successMsg.style.cssText = 'padding: 0.5rem; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 1rem;';
                successMsg.textContent = '✅ ' + (result.message || 'SAV créé avec succès');
                savFormContainer.insertBefore(successMsg, savFormContainer.firstChild);
                setTimeout(() => successMsg.remove(), 3000);
                
            } catch (err) {
                console.error('Erreur création SAV:', err);
                errorDiv.textContent = 'Erreur de connexion lors de la création du SAV';
                errorDiv.style.display = 'block';
            }
        });
        
    })();

    // --- Vérification Import (SFTP + IONOS) ---
    (function(){
        const checkImportBtn = document.getElementById('checkImportBtn');
        if (!checkImportBtn) return;
        
        checkImportBtn.addEventListener('click', async function() {
            checkImportBtn.disabled = true;
            const originalText = checkImportBtn.textContent;
            checkImportBtn.textContent = 'Vérification...';
            
            try {
                // Charger les statuts SFTP et IONOS en parallèle
                const [sftpResponse, ionosResponse] = await Promise.all([
                    fetch('/API/import/sftp_status.php', { cache: 'no-store', credentials: 'same-origin' }).catch(() => null),
                    fetch('/API/import/ionos_status.php', { cache: 'no-store', credentials: 'same-origin' }).catch(() => null)
                ]);
                
                // Traiter SFTP - Afficher uniquement si résultat réel
                if (sftpResponse && sftpResponse.ok) {
                    const sftpData = await sftpResponse.json();
                    if (sftpData.ok && sftpData.has_run && sftpData.lastRun) {
                        const run = sftpData.lastRun;
                        const status = run.status || 'UNKNOWN';
                        const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                        const filesText = run.files_processed === 1 ? 'fichier' : 'fichiers';
                        
                        // Afficher uniquement pour les statuts significatifs (succès, erreur, partiel)
                        if (status === 'RUN_OK') {
                            showNotification(
                                '✅ Import SFTP réussi',
                                `${run.files_processed} ${filesText} importé(s) en ${durationSeconds}s`,
                                'success'
                            );
                        } else if (status === 'PARTIAL') {
                            showNotification(
                                '⚠️ Import SFTP partiel',
                                `${run.files_processed} ${filesText} traité(s) en ${durationSeconds}s`,
                                'info'
                            );
                        } else if (status === 'RUN_FAILED') {
                            showNotification(
                                '❌ Erreur import SFTP',
                                run.error || 'Échec lors de l\'import',
                                'error'
                            );
                        }
                        // Ne rien afficher si statut UNKNOWN ou autre
                    }
                    // Ne rien afficher si aucune exécution enregistrée
                }
                
                // Traiter IONOS - Afficher uniquement si résultat réel
                if (ionosResponse && ionosResponse.ok) {
                    const ionosData = await ionosResponse.json();
                    if (ionosData.ok && ionosData.has_run && ionosData.lastRun) {
                        const run = ionosData.lastRun;
                        const status = run.status || 'UNKNOWN';
                        const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                        const rowsText = run.rows_processed === 1 ? 'ligne' : 'lignes';
                        
                        // Afficher uniquement pour les statuts significatifs (succès, erreur, partiel)
                        if (status === 'RUN_OK') {
                            showNotification(
                                '✅ Import IONOS réussi',
                                `${run.rows_processed} ${rowsText} importée(s) en ${durationSeconds}s`,
                                'success'
                            );
                        } else if (status === 'PARTIAL') {
                            showNotification(
                                '⚠️ Import IONOS partiel',
                                `${run.rows_processed} ${rowsText} traitée(s) en ${durationSeconds}s`,
                                'info'
                            );
                        } else if (status === 'RUN_FAILED') {
                            showNotification(
                                '❌ Erreur import IONOS',
                                run.error || 'Échec lors de l\'import',
                                'error'
                            );
                        }
                        // Ne rien afficher si statut UNKNOWN ou autre
                    }
                    // Ne rien afficher si aucune exécution enregistrée
                }
                
                // Si aucune notification n'a été affichée, informer l'utilisateur
                // (cette partie est gérée par l'absence de notifications)
                
            } catch (error) {
                console.error('[Import] Erreur:', error);
                showNotification(
                    '❌ Erreur',
                    'Impossible de vérifier les imports: ' + error.message,
                    'error'
                );
            } finally {
                checkImportBtn.disabled = false;
                checkImportBtn.textContent = originalText;
            }
        });
    })();
    
    // --- Ancien code Import SFTP (supprimé) ---
    /*
    (function(){
        const content = document.getElementById('sftpImportContent');
        const loading = document.getElementById('sftpImportLoading');
        const status = document.getElementById('sftpImportStatus');
        const refreshBtn = document.getElementById('sftpRefreshBtn');
        
        if (!content || !loading || !status || !refreshBtn) return;
        
        let isFetching = false;
        let refreshInterval = null;
        const REFRESH_INTERVAL_MS = 30000; // 30 secondes
        let lastRunId = null; // Pour détecter les nouveaux runs
        
        function setStatusBadge(statusValue) {
            const badge = document.getElementById('sftpStatusBadge');
            if (!badge) return;
            
            badge.className = 'sftp-status-badge';
            let text = 'Inconnu';
            let className = 'status-unknown';
            
            switch(statusValue) {
                case 'RUN_OK':
                    text = 'OK';
                    className = 'status-ok';
                    break;
                case 'RUN_FAILED':
                    text = 'KO';
                    className = 'status-ko';
                    break;
                case 'PARTIAL':
                    text = 'Partiel';
                    className = 'status-warn';
                    break;
                default:
                    text = 'Inconnu';
                    className = 'status-unknown';
            }
            
            badge.className = `sftp-status-badge ${className}`;
            badge.innerHTML = `<span class="${className}">${text}</span>`;
        }
        
        function formatDateTime(dateStr) {
            if (!dateStr) return '—';
            try {
                const date = new Date(dateStr);
                return date.toLocaleString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch(e) {
                return dateStr;
            }
        }
        
        function checkIfDelayed(endedAt) {
            if (!endedAt) return false;
            try {
                const endDate = new Date(endedAt);
                const now = new Date();
                const diffMinutes = (now - endDate) / (1000 * 60);
                return diffMinutes > 10; // Plus de 10 minutes
            } catch(e) {
                return false;
            }
        }
        
        async function refreshStatus() {
            // Ne pas rafraîchir si une requête est en cours
            if (isFetching) {
                return Promise.resolve();
            }
            
            // Ne pas rafraîchir si l'onglet est caché
            if (document.hidden) {
                return;
            }
            
            isFetching = true;
            loading.style.display = 'block';
            status.style.display = 'none';
            
            try {
                const response = await fetch('/API/import/sftp_status.php', {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'Cache-Control': 'no-cache'
                    }
                });
                
                const text = await response.text();
                let data;
                
                try {
                    data = JSON.parse(text);
                } catch(parseError) {
                    console.error('[SFTP] Réponse non JSON:', text.substring(0, 200));
                    throw new Error('Réponse invalide du serveur');
                }
                
                loading.style.display = 'none';
                status.style.display = 'block';
                
                if (!response.ok || !data.ok) {
                    const errorMsg = data.error || `HTTP ${response.status}`;
                    document.getElementById('sftpErrorText').textContent = errorMsg;
                    document.getElementById('sftpImportError').style.display = 'block';
                    setStatusBadge('UNKNOWN');
                    return;
                }
                
                // Masquer l'erreur si succès
                document.getElementById('sftpImportError').style.display = 'none';
                
                if (!data.has_run || !data.lastRun) {
                    setStatusBadge('UNKNOWN');
                    document.getElementById('sftpLastRun').textContent = 'Aucune exécution';
                    document.getElementById('sftpFilesProcessed').textContent = '—';
                    document.getElementById('sftpFilesDeleted').textContent = '—';
                    document.getElementById('sftpInsertedRows').textContent = '—';
                    // Initialiser lastRunId si c'est le premier chargement
                    if (lastRunId === null) {
                        lastRunId = null; // Pas de run encore
                    }
                    return;
                }
                
                const run = data.lastRun;
                
                // Déterminer le statut (avec vérification retard)
                let displayStatus = run.status || 'UNKNOWN';
                if (displayStatus === 'RUN_OK' && checkIfDelayed(run.ended_at)) {
                    displayStatus = 'PARTIAL'; // Afficher comme warning si retard
                }
                setStatusBadge(displayStatus);
                
                // Afficher les métriques
                document.getElementById('sftpLastRun').textContent = formatDateTime(run.ended_at);
                document.getElementById('sftpFilesProcessed').textContent = run.files_processed ?? '—';
                document.getElementById('sftpFilesDeleted').textContent = run.files_deleted ?? '—';
                document.getElementById('sftpInsertedRows').textContent = run.inserted_rows ?? '—';
                
                // Détecter les nouveaux runs et afficher les notifications
                const currentRunId = run.id;
                // Initialiser lastRunId au premier chargement (pour ne pas notifier le run actuel)
                if (lastRunId === null) {
                    lastRunId = currentRunId;
                }
                const isNewRun = lastRunId !== null && currentRunId !== lastRunId;
                
                if (isNewRun && run.files_processed > 0) {
                    // Nouveau run détecté - afficher notification avec nombre de fichiers et temps
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    const filesText = run.files_processed === 1 ? 'fichier' : 'fichiers';
                    
                    if (displayStatus === 'RUN_OK') {
                        showNotification(
                            '✅ Import réussi',
                            `${run.files_processed} ${filesText} importé(s) en ${durationSeconds}s`,
                            'success'
                        );
                    } else if (displayStatus === 'PARTIAL') {
                        showNotification(
                            '⚠️ Import partiel',
                            `${run.files_processed} ${filesText} traité(s) en ${durationSeconds}s`,
                            'info'
                        );
                    } else if (displayStatus === 'RUN_FAILED') {
                        showNotification(
                            '❌ Erreur import',
                            `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import SFTP'}`,
                            'error'
                        );
                    }
                } else if (isNewRun && displayStatus === 'RUN_FAILED') {
                    // Notification même si aucun fichier traité mais erreur
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    showNotification(
                        '❌ Erreur import',
                        `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import SFTP'}`,
                        'error'
                    );
                }
                
                // Mettre à jour le dernier run_id vu
                lastRunId = currentRunId;
                
                // Afficher l'erreur si présente
                if (run.error) {
                    document.getElementById('sftpErrorText').textContent = run.error;
                    document.getElementById('sftpImportError').style.display = 'block';
                } else {
                    document.getElementById('sftpImportError').style.display = 'none';
                }
                
            } catch(error) {
                console.error('[SFTP] Erreur refresh:', error);
                loading.style.display = 'none';
                status.style.display = 'block';
                document.getElementById('sftpErrorText').textContent = error.message || 'Erreur de connexion';
                document.getElementById('sftpImportError').style.display = 'block';
                setStatusBadge('UNKNOWN');
            } finally {
                isFetching = false;
            }
        }
        
        // Bouton refresh
        refreshBtn.addEventListener('click', refreshStatus);
        
        // Bouton trigger (lancer l'import)
        const triggerBtn = document.getElementById('sftpTriggerBtn');
        if (triggerBtn) {
            triggerBtn.addEventListener('click', async function() {
                // Désactiver le bouton pendant l'exécution
                triggerBtn.disabled = true;
                const originalText = triggerBtn.innerHTML;
                triggerBtn.innerHTML = '<span>Import en cours...</span>';
                
                try {
                    const response = await fetch('/API/import/sftp_trigger.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        credentials: 'same-origin',
                        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '')
                    });
                    
                    const result = await response.json();
                    
                    if (result.ok && result.last_run) {
                        const run = result.last_run;
                        const durationSeconds = result.duration_ms ? (result.duration_ms / 1000).toFixed(1) : '?';
                        const filesText = run.files_processed === 1 ? 'fichier' : 'fichiers';
                        
                        showNotification(
                            '✅ Import terminé',
                            `${run.files_processed} ${filesText} traité(s) en ${durationSeconds}s`,
                            'success'
                        );
                        
                        // Rafraîchir le statut après un court délai
                        setTimeout(() => {
                            lastRunId = null; // Forcer la détection du nouveau run
                            refreshStatus();
                        }, 1000);
                    } else {
                        showNotification(
                            '❌ Erreur',
                            result.error || 'Erreur lors de l\'import',
                            'error'
                        );
                    }
                } catch (error) {
                    console.error('[SFTP] Erreur trigger:', error);
                    showNotification(
                        '❌ Erreur',
                        'Impossible de lancer l\'import: ' + error.message,
                        'error'
                    );
                } finally {
                    // Réactiver le bouton
                    triggerBtn.disabled = false;
                    triggerBtn.innerHTML = originalText;
                }
            });
        }
        
        // Rafraîchir immédiatement
        refreshStatus();
        
        // Rafraîchir automatiquement toutes les 30 secondes
        refreshInterval = setInterval(() => {
            if (!document.hidden && !isFetching) {
                refreshStatus();
            }
        }, REFRESH_INTERVAL_MS);
        
        // Pause/resume selon visibilité onglet
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !isFetching) {
                // Reprendre le refresh si l'onglet redevient visible
                refreshStatus();
            }
        });
        
        // Nettoyer l'intervalle si la page est déchargée
        window.addEventListener('beforeunload', () => {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    })();
    */
    
    // --- Ancien code Import IONOS (supprimé) ---
    /*
    (function() {
        const content = document.getElementById('ionosImportContent');
        const loading = document.getElementById('ionosImportLoading');
        const status = document.getElementById('ionosImportStatus');
        const refreshBtn = document.getElementById('ionosRefreshBtn');
        
        if (!content || !loading || !status || !refreshBtn) return;
        
        let isFetching = false;
        let refreshInterval = null;
        const REFRESH_INTERVAL_MS = 30000; // 30 secondes
        let lastRunId = null; // Pour détecter les nouveaux runs
        
        function setStatusBadge(statusValue) {
            const badge = document.getElementById('ionosStatusBadge');
            if (!badge) return;
            
            badge.className = 'sftp-status-badge';
            let text = 'Inconnu';
            let className = 'status-unknown';
            
            switch(statusValue) {
                case 'RUN_OK':
                    text = 'OK';
                    className = 'status-ok';
                    break;
                case 'RUN_FAILED':
                    text = 'KO';
                    className = 'status-ko';
                    break;
                case 'PARTIAL':
                    text = 'Partiel';
                    className = 'status-warn';
                    break;
                default:
                    text = 'Inconnu';
                    className = 'status-unknown';
            }
            
            badge.innerHTML = `<span class="${className}">${text}</span>`;
        }
        
        function formatDateTime(dateStr) {
            if (!dateStr || dateStr === '—') return '—';
            try {
                const date = new Date(dateStr);
                return date.toLocaleString('fr-FR', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch(e) {
                return dateStr;
            }
        }
        
        function checkIfDelayed(dateStr) {
            if (!dateStr) return false;
            try {
                const date = new Date(dateStr);
                const now = new Date();
                const diffMinutes = (now - date) / (1000 * 60);
                return diffMinutes > 10; // Plus de 10 minutes = retard
            } catch(e) {
                return false;
            }
        }
        
        async function refreshStatus() {
            if (isFetching) return;
            isFetching = true;
            
            loading.style.display = 'block';
            status.style.display = 'none';
            
            try {
                const response = await fetch('/API/import/ionos_status.php', {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.ok || !data.has_run) {
                    loading.style.display = 'none';
                    status.style.display = 'block';
                    setStatusBadge('UNKNOWN');
                    document.getElementById('ionosLastRun').textContent = '—';
                    document.getElementById('ionosRowsSeen').textContent = '—';
                    document.getElementById('ionosRowsProcessed').textContent = '—';
                    document.getElementById('ionosRowsInserted').textContent = '—';
                    document.getElementById('ionosImportError').style.display = 'none';
                    return;
                }
                
                loading.style.display = 'none';
                status.style.display = 'block';
                
                const run = data.lastRun;
                
                // Déterminer le statut (avec vérification retard)
                let displayStatus = run.status || 'UNKNOWN';
                if (displayStatus === 'RUN_OK' && checkIfDelayed(run.ended_at)) {
                    displayStatus = 'PARTIAL'; // Afficher comme warning si retard
                }
                setStatusBadge(displayStatus);
                
                // Afficher les métriques
                document.getElementById('ionosLastRun').textContent = formatDateTime(run.ended_at);
                document.getElementById('ionosRowsSeen').textContent = run.rows_seen ?? '—';
                document.getElementById('ionosRowsProcessed').textContent = run.rows_processed ?? '—';
                document.getElementById('ionosRowsInserted').textContent = run.rows_inserted ?? '—';
                
                // Détecter les nouveaux runs et afficher les notifications
                const currentRunId = run.id;
                if (lastRunId === null) {
                    lastRunId = currentRunId;
                }
                const isNewRun = lastRunId !== null && currentRunId !== lastRunId;
                
                if (isNewRun && run.rows_processed > 0) {
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    const rowsText = run.rows_processed === 1 ? 'ligne' : 'lignes';
                    
                    if (displayStatus === 'RUN_OK') {
                        showNotification(
                            '✅ Import IONOS réussi',
                            `${run.rows_processed} ${rowsText} importée(s) en ${durationSeconds}s`,
                            'success'
                        );
                    } else if (displayStatus === 'PARTIAL') {
                        showNotification(
                            '⚠️ Import IONOS partiel',
                            `${run.rows_processed} ${rowsText} traitée(s) en ${durationSeconds}s`,
                            'info'
                        );
                    } else if (displayStatus === 'RUN_FAILED') {
                        showNotification(
                            '❌ Erreur import IONOS',
                            `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import IONOS'}`,
                            'error'
                        );
                    }
                } else if (isNewRun && displayStatus === 'RUN_FAILED') {
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    showNotification(
                        '❌ Erreur import IONOS',
                        `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import IONOS'}`,
                        'error'
                    );
                }
                
                lastRunId = currentRunId;
                
                // Afficher l'erreur si présente
                if (run.error) {
                    document.getElementById('ionosErrorText').textContent = run.error;
                    document.getElementById('ionosImportError').style.display = 'block';
                } else {
                    document.getElementById('ionosImportError').style.display = 'none';
                }
                
            } catch(error) {
                console.error('[IONOS] Erreur refresh:', error);
                loading.style.display = 'none';
                status.style.display = 'block';
                document.getElementById('ionosErrorText').textContent = error.message || 'Erreur de connexion';
                document.getElementById('ionosImportError').style.display = 'block';
                setStatusBadge('UNKNOWN');
            } finally {
                isFetching = false;
            }
        }
        
        // Bouton refresh
        refreshBtn.addEventListener('click', refreshStatus);
        
        // Bouton trigger (lancer l'import)
        const triggerBtn = document.getElementById('ionosTriggerBtn');
        if (triggerBtn) {
            triggerBtn.addEventListener('click', async function() {
                triggerBtn.disabled = true;
                const originalText = triggerBtn.innerHTML;
                triggerBtn.innerHTML = '<span>Import en cours...</span>';
                
                try {
                    const response = await fetch('/API/import/ionos_trigger.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        credentials: 'same-origin',
                        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '')
                    });
                    
                    const result = await response.json();
                    
                    if (result.ok && result.last_run) {
                        const run = result.last_run;
                        const durationSeconds = result.duration_ms ? (result.duration_ms / 1000).toFixed(1) : '?';
                        const rowsText = run.rows_processed === 1 ? 'ligne' : 'lignes';
                        
                        showNotification(
                            '✅ Import IONOS terminé',
                            `${run.rows_processed} ${rowsText} traitée(s) en ${durationSeconds}s`,
                            'success'
                        );
                        
                        setTimeout(() => {
                            lastRunId = null;
                            refreshStatus();
                        }, 1000);
                    } else {
                        showNotification(
                            '❌ Erreur',
                            result.error || 'Erreur lors de l\'import IONOS',
                            'error'
                        );
                    }
                } catch (error) {
                    console.error('[IONOS] Erreur trigger:', error);
                    showNotification(
                        '❌ Erreur',
                        'Impossible de lancer l\'import IONOS: ' + error.message,
                        'error'
                    );
                } finally {
                    triggerBtn.disabled = false;
                    triggerBtn.innerHTML = originalText;
                }
            });
        }
        
        // Rafraîchir immédiatement
        refreshStatus();
        
        // Rafraîchir automatiquement toutes les 30 secondes
        refreshInterval = setInterval(() => {
            if (!document.hidden && !isFetching) {
                refreshStatus();
            }
        }, REFRESH_INTERVAL_MS);
        
        // Pause/resume selon visibilité onglet
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && !isFetching) {
                refreshStatus();
            }
        });
        
        // Nettoyer l'intervalle si la page est déchargée
        window.addEventListener('beforeunload', () => {
            if (refreshInterval) {
                clearInterval(refreshInterval);
            }
        });
    })();
    */

    </script>
</body>
</html>
