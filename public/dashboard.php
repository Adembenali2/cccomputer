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
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/dashboard.js" defer></script>
    <style>
        /* Styles pour les cartes de statistiques (SAV, Livraisons, Factures) */
        .cdv-stats-card {
            background: linear-gradient(135deg, var(--bg-primary) 0%, var(--bg-secondary) 100%);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.25rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            position: relative;
            overflow: hidden;
        }
        
        .cdv-stats-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent-primary), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .cdv-stats-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            border-color: var(--accent-primary);
        }
        
        .cdv-stats-card:hover::before {
            opacity: 1;
        }
        
        .cdv-stats-sav {
            border-left: 4px solid #f59e0b;
        }
        
        .cdv-stats-sav .stats-card-icon {
            background: linear-gradient(135deg, rgba(245, 158, 11, 0.15), rgba(245, 158, 11, 0.05));
            color: #f59e0b;
        }
        
        .cdv-stats-livraisons {
            border-left: 4px solid #10b981;
        }
        
        .cdv-stats-livraisons .stats-card-icon {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.15), rgba(16, 185, 129, 0.05));
            color: #10b981;
        }
        
        .cdv-stats-factures {
            border-left: 4px solid #3b82f6;
        }
        
        .cdv-stats-factures .stats-card-icon {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.15), rgba(59, 130, 246, 0.05));
            color: #3b82f6;
        }
        
        .stats-card-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        
        .stats-card-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: transform 0.3s ease;
        }
        
        .cdv-stats-card:hover .stats-card-icon {
            transform: scale(1.1) rotate(5deg);
        }
        
        .stats-card-icon svg {
            width: 24px;
            height: 24px;
        }
        
        .stats-card-title {
            font-size: 1rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: 0.02em;
        }
        
        .stats-card-content {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .stats-card-count {
            font-size: 2rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1;
            letter-spacing: -0.02em;
        }
        
        .stats-card-status {
            font-size: 0.85rem;
            color: var(--text-secondary);
            line-height: 1.4;
            min-height: 1.2em;
        }
        
        .cdv-stats-card .lbl {
            display: none;
        }
        
        .cdv-stats-card .val {
            width: 100%;
        }
        
        @media (max-width: 768px) {
            .cdv-stats-card {
                padding: 1rem;
            }
            
            .stats-card-icon {
                width: 40px;
                height: 40px;
            }
            
            .stats-card-icon svg {
                width: 20px;
                height: 20px;
            }
            
            .stats-card-count {
                font-size: 1.75rem;
            }
        }
    </style>
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

            <div class="dash-card" data-href="/public/livraison.php" tabindex="0" role="button" aria-label="Accéder aux livraisons">
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

            <div class="dash-card" data-href="/public/clients.php" id="clientsCard" tabindex="0" role="button" aria-label="Accéder aux clients">
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

            <div class="dash-card" data-href="/public/stock.php" tabindex="0" role="button" aria-label="Accéder au stock">
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

            <div class="dash-card" data-href="/public/paiements.php" tabindex="0" role="button" aria-label="Accéder aux paiements">
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

            <div class="dash-card" data-href="/public/historique.php" tabindex="0" role="button" aria-label="Accéder aux historiques">
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

        <?php
        // Afficher la card Import SFTP
        // Pour limiter aux admins, décommenter les lignes suivantes:
        // $userRole = currentUserRole();
        // if ($userRole === 'Admin'):
        ?>
        <div class="sftp-import-card">
            <div class="sftp-import-header">
                <h3 class="sftp-import-title">Import SFTP</h3>
                <div class="sftp-import-actions">
                    <button class="sftp-import-trigger" id="sftpTriggerBtn" aria-label="Lancer l'import">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                        Lancer l'import
                    </button>
                    <button class="sftp-import-refresh" id="sftpRefreshBtn" aria-label="Rafraîchir le statut">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                            <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div class="sftp-import-content" id="sftpImportContent">
                <div class="sftp-import-loading" id="sftpImportLoading">
                    <span>Chargement...</span>
                </div>
                
                <div class="sftp-import-status" id="sftpImportStatus" style="display: none;">
                    <div class="sftp-status-badge" id="sftpStatusBadge">
                        <span class="status-unknown">Inconnu</span>
                    </div>
                    
                    <div class="sftp-import-metrics" id="sftpImportMetrics">
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Dernière exécution:</span>
                            <span class="sftp-metric-value" id="sftpLastRun">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Fichiers traités:</span>
                            <span class="sftp-metric-value" id="sftpFilesProcessed">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Fichiers supprimés:</span>
                            <span class="sftp-metric-value" id="sftpFilesDeleted">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Lignes insérées:</span>
                            <span class="sftp-metric-value" id="sftpInsertedRows">—</span>
                        </div>
                    </div>
                    
                    <div class="sftp-import-error" id="sftpImportError" style="display: none;">
                        <strong>Erreur:</strong>
                        <span id="sftpErrorText"></span>
                    </div>
                </div>
            </div>
        </div>
        <?php // endif; ?>
        
        <!-- Import IONOS Card -->
        <?php
        // Pour limiter aux admins, décommenter les lignes suivantes:
        // $userRole = currentUserRole();
        // if ($userRole === 'Admin'):
        ?>
        <div class="sftp-import-card ionos-import-card">
            <div class="sftp-import-header">
                <h3 class="sftp-import-title">Import IONOS</h3>
                <div class="sftp-import-actions">
                    <button class="sftp-import-trigger" id="ionosTriggerBtn" aria-label="Lancer l'import">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14M12 5l7 7-7 7"/>
                        </svg>
                        Lancer l'import
                    </button>
                    <button class="sftp-import-refresh" id="ionosRefreshBtn" aria-label="Rafraîchir le statut">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/>
                            <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/>
                        </svg>
                    </button>
                </div>
            </div>
            
            <div class="sftp-import-content" id="ionosImportContent">
                <div class="sftp-import-loading" id="ionosImportLoading">
                    <span>Chargement...</span>
                </div>
                
                <div class="sftp-import-status" id="ionosImportStatus" style="display: none;">
                    <div class="sftp-status-badge" id="ionosStatusBadge">
                        <span class="status-unknown">Inconnu</span>
                    </div>
                    
                    <div class="sftp-import-metrics" id="ionosImportMetrics">
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Dernière exécution:</span>
                            <span class="sftp-metric-value" id="ionosLastRun">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Lignes vues:</span>
                            <span class="sftp-metric-value" id="ionosRowsSeen">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Lignes traitées:</span>
                            <span class="sftp-metric-value" id="ionosRowsProcessed">—</span>
                        </div>
                        <div class="sftp-metric">
                            <span class="sftp-metric-label">Lignes insérées:</span>
                            <span class="sftp-metric-value" id="ionosRowsInserted">—</span>
                        </div>
                    </div>
                    
                    <div class="sftp-import-error" id="ionosImportError" style="display: none;">
                        <strong>Erreur:</strong>
                        <span id="ionosErrorText"></span>
                    </div>
                </div>
            </div>
        </div>
        <?php // endif; ?>
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
                       data-raison="<?= htmlspecialchars(strtolower($client['raison_sociale'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-nom-l="<?= htmlspecialchars(strtolower($client['nom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-nom="<?= htmlspecialchars(strtolower($client['nom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-prenom-l="<?= htmlspecialchars(strtolower($client['prenom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-prenom="<?= htmlspecialchars(strtolower($client['prenom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-numero-l="<?= htmlspecialchars(strtolower($client['numero_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                       data-numero="<?= htmlspecialchars(strtolower($client['numero_client'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
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
                            <div class="cdv-field cdv-stats-card cdv-stats-sav">
                                <div class="stats-card-header">
                                    <div class="stats-card-icon">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                                        </svg>
                                    </div>
                                    <div class="stats-card-title">SAV</div>
                                </div>
                                <div class="stats-card-content" id="cf-sav-stats">
                                    <div class="stats-card-count" id="cf-sav-count">—</div>
                                    <div class="stats-card-status" id="cf-sav-status"></div>
                                </div>
                            </div>
                            <div class="cdv-field cdv-stats-card cdv-stats-livraisons">
                                <div class="stats-card-header">
                                    <div class="stats-card-icon">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="1" y="3" width="15" height="13"/>
                                            <polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/>
                                            <circle cx="5.5" cy="18.5" r="2.5"/>
                                            <circle cx="18.5" cy="18.5" r="2.5"/>
                                        </svg>
                                    </div>
                                    <div class="stats-card-title">Livraisons</div>
                                </div>
                                <div class="stats-card-content" id="cf-livraisons-stats">
                                    <div class="stats-card-count" id="cf-livraisons-count">—</div>
                                    <div class="stats-card-status" id="cf-livraisons-status"></div>
                                </div>
                            </div>
                            <div class="cdv-field cdv-stats-card cdv-stats-factures">
                                <div class="stats-card-header">
                                    <div class="stats-card-icon">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                                            <line x1="1" y1="10" x2="23" y2="10"/>
                                            <path d="M7 14h.01M11 14h2"/>
                                        </svg>
                                    </div>
                                    <div class="stats-card-title">Factures</div>
                                </div>
                                <div class="stats-card-content" id="cf-factures-stats">
                                    <div class="stats-card-count" id="cf-factures-count">—</div>
                                    <div class="stats-card-status" id="cf-factures-status"></div>
                                </div>
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

    // Vérifier que apiClient est disponible
    if (typeof apiClient === 'undefined') {
        console.error('apiClient n\'est pas défini. Vérifiez que api.js est chargé.');
    }

    // Helper pour échapper HTML (défini en premier pour être disponible partout)
    function escapeHtml(text) {
        if (text == null || text === undefined) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    }

    // Fonction pour afficher une notification toast (partagée entre SFTP et IONOS)
    // Utilise window.showNotification si disponible (défini dans api.js), sinon définit une version locale
    function showNotificationToast(title, message, type = 'info') {
        // Si window.showNotification existe déjà (défini dans api.js), l'utiliser
        if (typeof window.showNotification === 'function') {
            window.showNotification(title + ': ' + message, type);
            return;
        }
        // Sinon, créer une notification toast personnalisée
        const notification = document.createElement('div');
        notification.className = `sftp-notification sftp-notification-${type}`;
        notification.innerHTML = `
            <div class="sftp-notification-content">
                <strong>${escapeHtml(title)}</strong>
                <span>${escapeHtml(message)}</span>
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
            closeNotificationToast(notification);
        }, 5000);
        
        // Bouton de fermeture
        const closeBtn = notification.querySelector('.sftp-notification-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                clearTimeout(autoClose);
                closeNotificationToast(notification);
            });
        }
    }
    
    function closeNotificationToast(notification) {
        if (!notification) return;
        notification.classList.remove('sftp-notification-show');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }
    
    // Note: On utilise showNotificationToast directement pour éviter les conflits avec window.showNotification de api.js

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

        // Charger les statistiques du client (SAV, livraisons, factures)
        async function loadClientStats(clientId) {
            const savCountEl = document.getElementById('cf-sav-count');
            const savStatusEl = document.getElementById('cf-sav-status');
            const livraisonsCountEl = document.getElementById('cf-livraisons-count');
            const livraisonsStatusEl = document.getElementById('cf-livraisons-status');
            const facturesCountEl = document.getElementById('cf-factures-count');
            const facturesStatusEl = document.getElementById('cf-factures-status');
            
            // Réinitialiser les valeurs
            if (savCountEl) savCountEl.textContent = '—';
            if (savStatusEl) savStatusEl.textContent = '';
            if (livraisonsCountEl) livraisonsCountEl.textContent = '—';
            if (livraisonsStatusEl) livraisonsStatusEl.textContent = '';
            if (facturesCountEl) facturesCountEl.textContent = '—';
            if (facturesStatusEl) facturesStatusEl.textContent = '';
            
            try {
                if (typeof apiClient === 'undefined') {
                    throw new Error('apiClient n\'est pas disponible');
                }
                const data = await apiClient.json(`/API/dashboard_get_client_stats.php?client_id=${clientId}`, {
                    method: 'GET'
                }, {
                    abortKey: 'load_client_stats_' + clientId
                });
                
                if (!data || !data.ok || !data.stats) {
                    return;
                }
                
                const stats = data.stats;
                
                // Afficher les stats SAV
                if (stats.sav) {
                    const total = stats.sav.total || 0;
                    const ouvert = stats.sav.ouvert || 0;
                    const enCours = stats.sav.en_cours || 0;
                    const resolu = stats.sav.resolu || 0;
                    const annule = stats.sav.annule || 0;
                    
                    if (savCountEl) savCountEl.textContent = total;
                    
                    if (savStatusEl) {
                        const statusParts = [];
                        if (ouvert > 0) statusParts.push(`<span style="color: #3b82f6; font-weight: 600;">${ouvert} ouvert${ouvert > 1 ? 's' : ''}</span>`);
                        if (enCours > 0) statusParts.push(`<span style="color: #f59e0b; font-weight: 600;">${enCours} en cours</span>`);
                        if (resolu > 0) statusParts.push(`<span style="color: #16a34a; font-weight: 600;">${resolu} résolu${resolu > 1 ? 's' : ''}</span>`);
                        if (annule > 0) statusParts.push(`<span style="color: #6b7280;">${annule} annulé${annule > 1 ? 's' : ''}</span>`);
                        
                        if (statusParts.length > 0) {
                            savStatusEl.innerHTML = statusParts.join(' • ');
                        } else {
                            savStatusEl.innerHTML = '<span style="color: var(--text-secondary);">Aucun SAV</span>';
                        }
                    }
                }
                
                // Afficher les stats Livraisons
                if (stats.livraisons) {
                    const total = stats.livraisons.total || 0;
                    const planifiee = stats.livraisons.planifiee || 0;
                    const enCours = stats.livraisons.en_cours || 0;
                    const livree = stats.livraisons.livree || 0;
                    const annulee = stats.livraisons.annulee || 0;
                    
                    if (livraisonsCountEl) livraisonsCountEl.textContent = total;
                    
                    if (livraisonsStatusEl) {
                        const statusParts = [];
                        if (planifiee > 0) statusParts.push(`<span style="color: #f59e0b; font-weight: 600;">${planifiee} planifiée${planifiee > 1 ? 's' : ''}</span>`);
                        if (enCours > 0) statusParts.push(`<span style="color: #3b82f6; font-weight: 600;">${enCours} en cours</span>`);
                        if (livree > 0) statusParts.push(`<span style="color: #16a34a; font-weight: 600;">${livree} livrée${livree > 1 ? 's' : ''}</span>`);
                        if (annulee > 0) statusParts.push(`<span style="color: #6b7280;">${annulee} annulée${annulee > 1 ? 's' : ''}</span>`);
                        
                        if (statusParts.length > 0) {
                            livraisonsStatusEl.innerHTML = statusParts.join(' • ');
                        } else {
                            livraisonsStatusEl.innerHTML = '<span style="color: var(--text-secondary);">Aucune livraison</span>';
                        }
                    }
                }
                
                // Afficher les stats Factures
                if (stats.factures) {
                    const total = stats.factures.total || 0;
                    const brouillon = stats.factures.brouillon || 0;
                    const enAttente = stats.factures.en_attente || 0;
                    const envoyee = stats.factures.envoyee || 0;
                    const payee = stats.factures.payee || 0;
                    const enRetard = stats.factures.en_retard || 0;
                    const annulee = stats.factures.annulee || 0;
                    
                    if (facturesCountEl) facturesCountEl.textContent = total;
                    
                    if (facturesStatusEl) {
                        const statusParts = [];
                        if (brouillon > 0) statusParts.push(`<span style="color: #6b7280; font-weight: 600;">${brouillon} brouillon${brouillon > 1 ? 's' : ''}</span>`);
                        if (enAttente > 0) statusParts.push(`<span style="color: #f59e0b; font-weight: 600;">${enAttente} en attente</span>`);
                        if (envoyee > 0) statusParts.push(`<span style="color: #3b82f6; font-weight: 600;">${envoyee} envoyée${envoyee > 1 ? 's' : ''}</span>`);
                        if (enRetard > 0) statusParts.push(`<span style="color: #ef4444; font-weight: 600;">${enRetard} en retard</span>`);
                        if (payee > 0) statusParts.push(`<span style="color: #16a34a; font-weight: 600;">${payee} payée${payee > 1 ? 's' : ''}</span>`);
                        if (annulee > 0) statusParts.push(`<span style="color: #6b7280;">${annulee} annulée${annulee > 1 ? 's' : ''}</span>`);
                        
                        if (statusParts.length > 0) {
                            facturesStatusEl.innerHTML = statusParts.join(' • ');
                        } else {
                            facturesStatusEl.innerHTML = '<span style="color: var(--text-secondary);">Aucune facture</span>';
                        }
                    }
                }
                
            } catch (err) {
                if (err.name !== 'AbortError') {
                    console.error('Erreur chargement stats client:', err);
                }
            }
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
            
            // Charger les statistiques du client
            const clientIdNum = parseInt(id, 10);
            if (clientIdNum && clientIdNum > 0) {
                loadClientStats(clientIdNum);
            }
            
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
                if (typeof apiClient === 'undefined') {
                    throw new Error('apiClient n\'est pas disponible');
                }
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
                    console.error('Erreur chargement livraisons:', err);
                    showNotificationToast('Erreur', 'Erreur de chargement des livraisons', 'error');
                }
                deliveryList.innerHTML = '<p class="hint" style="color: #dc2626;">Erreur de chargement des livraisons.</p>';
            }
        }
        
        // Charger les livreurs
        async function loadLivreurs() {
            const select = document.getElementById('deliveryLivreur');
            if (!select) return;
            
            try {
                if (typeof apiClient === 'undefined') {
                    throw new Error('apiClient n\'est pas disponible');
                }
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
                const response = await fetch(`/API/dashboard_get_stock_products.php?type=${encodeURIComponent(type)}`, {
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data || !data.ok) {
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
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result || !result.ok) {
                    const errorMsg = result && result.error ? result.error : 'Erreur lors de la création de la livraison';
                    errorDiv.textContent = errorMsg;
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Succès : recharger les livraisons et cacher le formulaire
                const clientId = data.client_id;
                await loadDeliveries(clientId);
                
                deliveryForm.style.display = 'none';
                deliveryForm.reset();
                const productContainer = document.getElementById('deliveryProductContainer');
                const quantityContainer = document.getElementById('deliveryQuantityContainer');
                if (productContainer) productContainer.style.display = 'none';
                if (quantityContainer) quantityContainer.style.display = 'none';
                if (toggleDeliveryForm) toggleDeliveryForm.textContent = '➕ Ajouter';
                errorDiv.style.display = 'none';
                
                // Afficher un message de succès temporaire
                const successMsg = document.createElement('div');
                successMsg.style.cssText = 'padding: 0.5rem; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 1rem;';
                const successText = result && result.message ? result.message : 'Livraison créée avec succès';
                successMsg.textContent = '✅ ' + successText;
                if (deliveryFormContainer) {
                    deliveryFormContainer.insertBefore(successMsg, deliveryFormContainer.firstChild);
                    setTimeout(() => {
                        if (successMsg.parentNode) {
                            successMsg.remove();
                        }
                    }, 3000);
                }
                
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
                const response = await fetch(`/API/dashboard_get_sav.php?client_id=${clientId}`, {
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    let errorData = null;
                    try {
                        errorData = JSON.parse(errorText);
                    } catch (e) {
                        // Pas de JSON, utiliser le texte brut
                    }
                    const errorMsg = errorData && errorData.error ? errorData.error : (errorText || 'Erreur de chargement');
                    savList.innerHTML = `<p class="hint" style="color: #dc2626;">Erreur ${response.status}: ${escapeHtml(errorMsg)}</p>`;
                    return;
                }
                
                const data = await response.json();
                
                if (!data || !data.ok) {
                    const errorMsg = data && data.error ? data.error : 'Erreur de chargement';
                    savList.innerHTML = `<p class="hint" style="color: #dc2626;">Erreur: ${escapeHtml(errorMsg)}</p>`;
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
                if (typeof apiClient === 'undefined') {
                    throw new Error('apiClient n\'est pas disponible');
                }
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
                    console.error('Erreur chargement techniciens:', err);
                    showNotificationToast('Erreur', 'Erreur de chargement des techniciens', 'error');
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
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(data)
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const result = await response.json();
                
                if (!result || !result.ok) {
                    const errorMsg = result && result.error ? result.error : 'Erreur lors de la création du SAV';
                    errorDiv.textContent = errorMsg;
                    errorDiv.style.display = 'block';
                    return;
                }
                
                // Succès : recharger les SAV et cacher le formulaire
                const clientId = data.client_id;
                await loadSavs(clientId);
                
                savForm.style.display = 'none';
                savForm.reset();
                if (toggleSavForm) toggleSavForm.textContent = '➕ Ajouter';
                errorDiv.style.display = 'none';
                
                // Afficher un message de succès temporaire
                const successMsg = document.createElement('div');
                successMsg.style.cssText = 'padding: 0.5rem; background: #d1fae5; color: #065f46; border-radius: 4px; margin-bottom: 1rem;';
                const successText = result && result.message ? result.message : 'SAV créé avec succès';
                successMsg.textContent = '✅ ' + successText;
                if (savFormContainer) {
                    savFormContainer.insertBefore(successMsg, savFormContainer.firstChild);
                    setTimeout(() => {
                        if (successMsg.parentNode) {
                            successMsg.remove();
                        }
                    }, 3000);
                }
                
            } catch (err) {
                console.error('Erreur création SAV:', err);
                errorDiv.textContent = 'Erreur de connexion lors de la création du SAV';
                errorDiv.style.display = 'block';
            }
        });
        
    })();

    // --- Import SFTP Status (Admin uniquement) ---
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
                    credentials: 'include',
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
                
                // Récupérer les éléments une seule fois
                const errorTextEl = document.getElementById('sftpErrorText');
                const errorEl = document.getElementById('sftpImportError');
                
                if (!response.ok || !data || !data.ok) {
                    const errorMsg = (data && data.error) ? data.error : `HTTP ${response.status}`;
                    if (errorTextEl) errorTextEl.textContent = errorMsg;
                    if (errorEl) errorEl.style.display = 'block';
                    setStatusBadge('UNKNOWN');
                    return;
                }
                
                // Masquer l'erreur si succès
                if (errorEl) errorEl.style.display = 'none';
                
                if (!data.has_run || !data.lastRun) {
                    setStatusBadge('UNKNOWN');
                    const lastRunEl = document.getElementById('sftpLastRun');
                    const filesProcessedEl = document.getElementById('sftpFilesProcessed');
                    const filesDeletedEl = document.getElementById('sftpFilesDeleted');
                    const insertedRowsEl = document.getElementById('sftpInsertedRows');
                    if (lastRunEl) lastRunEl.textContent = 'Aucune exécution';
                    if (filesProcessedEl) filesProcessedEl.textContent = '—';
                    if (filesDeletedEl) filesDeletedEl.textContent = '—';
                    if (insertedRowsEl) insertedRowsEl.textContent = '—';
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
                const lastRunEl = document.getElementById('sftpLastRun');
                const filesProcessedEl = document.getElementById('sftpFilesProcessed');
                const filesDeletedEl = document.getElementById('sftpFilesDeleted');
                const insertedRowsEl = document.getElementById('sftpInsertedRows');
                if (lastRunEl) lastRunEl.textContent = formatDateTime(run.ended_at);
                if (filesProcessedEl) filesProcessedEl.textContent = run.files_processed ?? '—';
                if (filesDeletedEl) filesDeletedEl.textContent = run.files_deleted ?? '—';
                if (insertedRowsEl) insertedRowsEl.textContent = run.inserted_rows ?? '—';
                
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
                        showNotificationToast(
                            '✅ Import réussi',
                            `${run.files_processed} ${filesText} importé(s) en ${durationSeconds}s`,
                            'success'
                        );
                    } else if (displayStatus === 'PARTIAL') {
                        showNotificationToast(
                            '⚠️ Import partiel',
                            `${run.files_processed} ${filesText} traité(s) en ${durationSeconds}s`,
                            'info'
                        );
                    } else if (displayStatus === 'RUN_FAILED') {
                        showNotificationToast(
                            '❌ Erreur import',
                            `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import SFTP'}`,
                            'error'
                        );
                    }
                } else if (isNewRun && displayStatus === 'RUN_FAILED') {
                    // Notification même si aucun fichier traité mais erreur
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    showNotificationToast(
                        '❌ Erreur import',
                        `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import SFTP'}`,
                        'error'
                    );
                }
                
                // Mettre à jour le dernier run_id vu
                lastRunId = currentRunId;
                
                // Afficher l'erreur si présente (réutiliser les variables déclarées plus haut)
                if (run.error) {
                    if (errorTextEl) errorTextEl.textContent = run.error;
                    if (errorEl) errorEl.style.display = 'block';
                } else {
                    if (errorEl) errorEl.style.display = 'none';
                }
                
            } catch(error) {
                console.error('[SFTP] Erreur refresh:', error);
                loading.style.display = 'none';
                status.style.display = 'block';
                // Récupérer les éléments dans le catch (scope différent)
                const catchErrorTextEl = document.getElementById('sftpErrorText');
                const catchErrorEl = document.getElementById('sftpImportError');
                if (catchErrorTextEl) catchErrorTextEl.textContent = error.message || 'Erreur de connexion';
                if (catchErrorEl) catchErrorEl.style.display = 'block';
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
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        credentials: 'include',
                        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '')
                    });
                    
                    const result = await response.json();
                    
                    if (result && result.ok && result.last_run) {
                        const run = result.last_run;
                        const durationSeconds = result.duration_ms ? (result.duration_ms / 1000).toFixed(1) : '?';
                        const filesText = run.files_processed === 1 ? 'fichier' : 'fichiers';
                        
                        showNotificationToast(
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
                        const errorMsg = result && result.error ? result.error : 'Erreur lors de l\'import';
                        showNotificationToast(
                            '❌ Erreur',
                            errorMsg,
                            'error'
                        );
                    }
                } catch (error) {
                    console.error('[SFTP] Erreur trigger:', error);
                    showNotificationToast(
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
    
    // === Import IONOS ===
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
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                
                const data = await response.json();
                
                // Récupérer les éléments une seule fois au début
                const errorTextEl = document.getElementById('ionosErrorText');
                const errorEl = document.getElementById('ionosImportError');
                
                if (!data || !data.ok || !data.has_run) {
                    loading.style.display = 'none';
                    status.style.display = 'block';
                    setStatusBadge('UNKNOWN');
                    const lastRunEl = document.getElementById('ionosLastRun');
                    const rowsSeenEl = document.getElementById('ionosRowsSeen');
                    const rowsProcessedEl = document.getElementById('ionosRowsProcessed');
                    const rowsInsertedEl = document.getElementById('ionosRowsInserted');
                    if (lastRunEl) lastRunEl.textContent = '—';
                    if (rowsSeenEl) rowsSeenEl.textContent = '—';
                    if (rowsProcessedEl) rowsProcessedEl.textContent = '—';
                    if (rowsInsertedEl) rowsInsertedEl.textContent = '—';
                    if (errorEl) errorEl.style.display = 'none';
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
                const lastRunEl = document.getElementById('ionosLastRun');
                const rowsSeenEl = document.getElementById('ionosRowsSeen');
                const rowsProcessedEl = document.getElementById('ionosRowsProcessed');
                const rowsInsertedEl = document.getElementById('ionosRowsInserted');
                if (lastRunEl) lastRunEl.textContent = formatDateTime(run.ended_at);
                if (rowsSeenEl) rowsSeenEl.textContent = run.rows_seen ?? '—';
                if (rowsProcessedEl) rowsProcessedEl.textContent = run.rows_processed ?? '—';
                if (rowsInsertedEl) rowsInsertedEl.textContent = run.rows_inserted ?? '—';
                
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
                        showNotificationToast(
                            '✅ Import IONOS réussi',
                            `${run.rows_processed} ${rowsText} importée(s) en ${durationSeconds}s`,
                            'success'
                        );
                    } else if (displayStatus === 'PARTIAL') {
                        showNotificationToast(
                            '⚠️ Import IONOS partiel',
                            `${run.rows_processed} ${rowsText} traitée(s) en ${durationSeconds}s`,
                            'info'
                        );
                    } else if (displayStatus === 'RUN_FAILED') {
                        showNotificationToast(
                            '❌ Erreur import IONOS',
                            `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import IONOS'}`,
                            'error'
                        );
                    }
                } else if (isNewRun && displayStatus === 'RUN_FAILED') {
                    const durationSeconds = run.duration_ms ? (run.duration_ms / 1000).toFixed(1) : '?';
                    showNotificationToast(
                        '❌ Erreur import IONOS',
                        `Échec après ${durationSeconds}s: ${run.error || 'Erreur lors de l\'import IONOS'}`,
                        'error'
                    );
                }
                
                lastRunId = currentRunId;
                
                // Afficher l'erreur si présente (réutiliser les variables déclarées plus haut)
                if (run.error) {
                    if (errorTextEl) errorTextEl.textContent = run.error;
                    if (errorEl) errorEl.style.display = 'block';
                } else {
                    if (errorEl) errorEl.style.display = 'none';
                }
                
            } catch(error) {
                console.error('[IONOS] Erreur refresh:', error);
                loading.style.display = 'none';
                status.style.display = 'block';
                // Récupérer les éléments dans le catch (scope différent)
                const catchErrorTextEl = document.getElementById('ionosErrorText');
                const catchErrorEl = document.getElementById('ionosImportError');
                if (catchErrorTextEl) catchErrorTextEl.textContent = error.message || 'Erreur de connexion';
                if (catchErrorEl) catchErrorEl.style.display = 'block';
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
                        credentials: 'include',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'csrf_token=' + encodeURIComponent(window.CSRF_TOKEN || '')
                    });
                    
                    const result = await response.json();
                    
                    if (result && result.ok && result.last_run) {
                        const run = result.last_run;
                        const durationSeconds = result.duration_ms ? (result.duration_ms / 1000).toFixed(1) : '?';
                        const rowsText = run.rows_processed === 1 ? 'ligne' : 'lignes';
                        
                        showNotificationToast(
                            '✅ Import IONOS terminé',
                            `${run.rows_processed} ${rowsText} traitée(s) en ${durationSeconds}s`,
                            'success'
                        );
                        
                        setTimeout(() => {
                            lastRunId = null;
                            refreshStatus();
                        }, 1000);
                    } else {
                        const errorMsg = result && result.error ? result.error : 'Erreur lors de l\'import IONOS';
                        showNotificationToast(
                            '❌ Erreur',
                            errorMsg,
                            'error'
                        );
                    }
                } catch (error) {
                    console.error('[IONOS] Erreur trigger:', error);
                    showNotificationToast(
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

    </script>
</body>
</html>