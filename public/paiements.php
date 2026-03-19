<?php
header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('paiements', []); // Accessible à tous les utilisateurs connectés
ensureCsrfToken(); // Génère le token CSRF si manquant (pour le formulaire paiement)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiement - CC Computer</title>
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <link rel="stylesheet" href="/assets/css/paiements.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
    <?php
    require_once __DIR__ . '/../source/templates/header.php';
    ?>

    <div class="paiement-page">
        <!-- Header Section -->
        <div class="paiement-header">
            <div class="paiement-header-content">
                <h1>Paiements & Factures</h1>
                <p>Gérez les paiements et factures de vos clients</p>
            </div>
            <div class="paiement-header-icon">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 4H4c-1.11 0-1.99.89-1.99 2L2 18c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/>
                </svg>
            </div>
        </div>

        <!-- Message Container -->
        <div id="messageContainer" class="message-container"></div>

        <!-- Statistics Section with Graph -->
        <div class="stats-section">
            <div class="stats-header">
                <h2 class="stats-title">Statistiques d'impression</h2>
                <div class="stats-header-actions">
                    <button class="btn-export" id="btnExportExcel">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                        <polyline points="7 10 12 15 17 10"></polyline>
                        <line x1="12" y1="15" x2="12" y2="3"></line>
                    </svg>
                        Exporter Excel
                    </button>
                </div>
            </div>

            <div class="stats-view-mode" id="statsViewMode">
                <span class="stats-segment" data-mode="monthly" id="segmentMonthly">Mensuel</span>
                <span class="stats-segment" data-mode="daily" id="segmentDaily">Journalier</span>
            </div>

            <div class="stats-filters-card">
                <div class="stats-filters">
                    <div class="stats-filter-group">
                        <label for="filterClient">Client</label>
                        <select id="filterClient">
                            <option value="">Tous les clients</option>
                        </select>
                    </div>
                    <div class="stats-filter-group">
                        <label for="filterMois">Mois</label>
                        <select id="filterMois">
                            <option value="">Tous les mois</option>
                            <option value="1">Janvier</option>
                            <option value="2">Février</option>
                            <option value="3">Mars</option>
                            <option value="4">Avril</option>
                            <option value="5">Mai</option>
                            <option value="6">Juin</option>
                            <option value="7">Juillet</option>
                            <option value="8">Août</option>
                            <option value="9">Septembre</option>
                            <option value="10">Octobre</option>
                            <option value="11">Novembre</option>
                            <option value="12">Décembre</option>
                        </select>
                    </div>
                    <div class="stats-filter-group">
                        <label for="filterAnnee">Année</label>
                        <select id="filterAnnee">
                            <option value="">Toutes les années</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="stats-chart-card">
                <div class="stats-chart-header">
                    <div class="stats-chart-header-left">
                        <h3 class="stats-chart-title" id="statsChartTitle">Consommation</h3>
                        <p class="stats-chart-subtitle" id="statsChartSubtitle">Pages imprimées par période</p>
                    </div>
                    <div id="statsEstimateText" class="stats-estimate-pill"></div>
                </div>
                <div class="chart-container">
                    <div class="chart-loading" id="chartLoading">
                        <div class="chart-skeleton" id="chartSkeleton">
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line"></div>
                            <div class="skeleton-line"></div>
                        </div>
                        <span class="chart-loading-text">Chargement des données...</span>
                    </div>
                    <canvas id="statsChart" style="display: none; width: 100% !important; height: 100% !important;"></canvas>
                </div>
            </div>
        </div>

        <!-- Sections Grid -->
        <div class="sections-grid">
            <!-- Section Factures (fusionnée avec paiements) -->
            <div class="section-card" id="sectionFactures">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Factures</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Liste des factures, paiements, modifier, supprimer et gérer</p>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="section-card-btn" onclick="openSection('factures')">
                            Gérer les factures et paiements
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14"></path>
                                <path d="M12 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Section Générer facture -->
            <div class="section-card" id="sectionGenererFacture">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="12" y1="18" x2="12" y2="12"></line>
                            <line x1="9" y1="15" x2="15" y2="15"></line>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Générer facture</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Créez une nouvelle facture pour un client</p>
                    <button class="section-card-btn" onclick="openSection('generer-facture')">
                        Créer une facture
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Payer -->
            <div class="section-card" id="sectionPayer">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="1" x2="12" y2="23"></line>
                            <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Payer</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Enregistrez un nouveau paiement</p>
                    <button class="section-card-btn" onclick="openSection('payer')">
                        Enregistrer un paiement
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Facture Mail -->
            <div class="section-card" id="sectionFactureMail">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Facture Mail</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Envoyez une facture par email à un client</p>
                    <button class="section-card-btn" onclick="openSection('facture-mail')">
                        Envoyer par email
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Génération Facture Clients -->
            <div class="section-card" id="sectionGenerationFactureClients">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                            <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Génération Facture Clients</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Générez des factures pour plusieurs clients</p>
                    <button class="section-card-btn" onclick="openSection('generation-facture-clients')">
                        Générer des factures
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Envoi en masse -->
            <div class="section-card" id="sectionEnvoiMasse">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                            <polyline points="22,6 12,13 2,6"></polyline>
                            <path d="M22 6l-10 7L2 6"></path>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Envoi en masse</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Envoyez plusieurs factures à leurs clients respectifs</p>
                    <button class="section-card-btn" onclick="openSection('envoi-masse')">
                        Envoyer en masse
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Programmer envois -->
            <div class="section-card" id="sectionProgrammerEnvois">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #6366f1, #4f46e5);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <polyline points="12 6 12 12 16 14"></polyline>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Programmer envois</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Programmez l'envoi automatique de factures par email à une date/heure définie</p>
                    <button class="section-card-btn" onclick="openSection('programmer-envois')">
                        Programmer un envoi
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal PDF Viewer -->
    <div class="modal-overlay" id="pdfViewerModalOverlay" onclick="closePDFViewer()">
        <div class="modal" id="pdfViewerModal" onclick="event.stopPropagation()" style="max-width: 95%; max-height: 95vh;">
            <div class="modal-header">
                <h2 class="modal-title" id="pdfViewerTitle">Facture PDF</h2>
                <button class="modal-close" onclick="closePDFViewer()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 0; height: calc(95vh - 100px); position: relative;">
                <embed id="pdfViewerEmbed" src="" type="application/pdf" style="width: 100%; height: 100%; border: none;" />
                <div id="pdfViewerFallback" style="display: none; padding: 2rem; text-align: center; color: var(--text-secondary);">
                    <p>Le PDF ne peut pas être affiché directement dans cette page.</p>
                    <button type="button" class="btn btn-primary" id="pdfViewerOpenBtn" onclick="openPDFInNewTab()">Ouvrir le PDF dans un nouvel onglet</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePDFViewer()">Fermer</button>
                <button type="button" class="btn btn-primary" id="pdfViewerDownloadBtn" onclick="openPDFInNewTab()">Ouvrir dans un nouvel onglet</button>
            </div>
        </div>
    </div>


    <!-- Modal Factures (fusionné avec paiements) -->
    <div class="modal-overlay" id="facturesListModalOverlay" onclick="closeFacturesListModal()">
        <div class="modal" id="facturesListModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Factures et paiements</h2>
                <button class="modal-close" onclick="closeFacturesListModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="facturesListLoading" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    Chargement des factures...
                </div>
                <div id="facturesListContainer" style="display: none;">
                    <!-- Onglets Mois en cours / Archive -->
                    <div style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; border-bottom: 2px solid var(--border-color);">
                        <button type="button" id="facturesTabMoisEnCours" onclick="switchFacturesTab('mois_en_cours')" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.95rem; border: none; border-bottom: 3px solid var(--accent-primary); margin-bottom: -2px; background: none; color: var(--accent-primary); cursor: pointer; transition: all 0.2s;">
                            Mois en cours <span id="facturesTabMoisCount"></span>
                        </button>
                        <button type="button" id="facturesTabArchive" onclick="switchFacturesTab('archive')" style="padding: 0.75rem 1.25rem; font-weight: 600; font-size: 0.95rem; border: none; border-bottom: 3px solid transparent; margin-bottom: -2px; background: none; color: var(--text-secondary); cursor: pointer; transition: all 0.2s;">
                            Archive <span id="facturesTabArchiveCount"></span>
                        </button>
                    </div>
                    <!-- Recherche -->
                    <div style="margin-bottom: 1rem;">
                        <input type="text" id="facturesSearchInput" placeholder="Rechercher par numéro, client ou date..." style="width: 100%; padding: 0.75rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem;" oninput="filterFactures()">
                    </div>
                    <!-- Filtres par statut -->
                    <div style="margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="filter-btn-factures active" data-status="all" onclick="filterFacturesByStatus('all')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--accent-primary); color: white; cursor: pointer; font-size: 0.9rem;">Tous</button>
                        <button class="filter-btn-factures" data-status="payee" onclick="filterFacturesByStatus('payee')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">Payé</button>
                        <button class="filter-btn-factures" data-status="envoyee" onclick="filterFacturesByStatus('envoyee')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">Envoyé</button>
                        <button class="filter-btn-factures" data-status="brouillon" onclick="filterFacturesByStatus('brouillon')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">Non envoyé</button>
                        <button class="filter-btn-factures" data-status="en_attente" onclick="filterFacturesByStatus('en_attente')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">En attente</button>
                        <button class="filter-btn-factures" data-status="en_cours" onclick="filterFacturesByStatus('en_cours')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">En cours</button>
                        <button class="filter-btn-factures" data-status="en_retard" onclick="filterFacturesByStatus('en_retard')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem;">En retard</button>
                    </div>
                    
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                        <span><span id="facturesCount">0</span> facture(s) trouvée(s)</span>
                        <span id="facturesFilteredCount" style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;"></span>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                            <button type="button" onclick="openHistoriquePaiementsModal()" style="padding: 0.5rem 1rem; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; font-size: 0.9rem; display: inline-flex; align-items: center; gap: 0.5rem;">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                                Historique des paiements
                            </button>
                            <button type="button" id="btnSupprimerSelection" onclick="supprimerFacturesSelection()" disabled style="padding: 0.5rem 1rem; background: #ef4444; color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; font-size: 0.9rem;" title="Supprimer les factures sélectionnées">
                                Supprimer la sélection (<span id="facturesSelectedCount">0</span>)
                            </button>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                                    <th style="padding: 0.75rem; text-align: center; width: 40px;">
                                        <input type="checkbox" id="facturesSelectAll" onchange="toggleFacturesSelectAll(this)" title="Tout sélectionner">
                                    </th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Numéro</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Date</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Client</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Type</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">Montant HT</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">TVA</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">Total TTC</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Statut</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="facturesListTableBody">
                                <!-- Les factures seront ajoutées ici dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="facturesListError" style="display: none; text-align: center; padding: 2rem; color: #ef4444;">
                    Erreur lors du chargement des factures
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeFacturesListModal()">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Modal Modifier facture -->
    <div class="modal-overlay" id="modifierFactureModalOverlay" onclick="closeModifierFactureModal()">
        <div class="modal" id="modifierFactureModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Modifier la facture</h2>
                <button class="modal-close" onclick="closeModifierFactureModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="modifierFactureForm" onsubmit="return submitModifierFacture(event)">
                    <input type="hidden" id="modifierFactureId" name="facture_id">
                    <input type="hidden" id="modifierFactureType" name="type">
                    <div id="modifierFactureConsommation" style="display: none;">
                        <p style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.9rem;">Régénération : modifiez les dates et les compteurs de consommation. Le PDF sera régénéré avec le même numéro.</p>
                        <div style="margin-bottom: 1rem;">
                            <label for="modifierFactureDateDebut" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Date début période</label>
                            <input type="date" id="modifierFactureDateDebut" name="date_debut_periode" style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);" onchange="refreshModifierFactureCompteurs()">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label for="modifierFactureDateFin" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Date fin période</label>
                            <input type="date" id="modifierFactureDateFin" name="date_fin_periode" style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);" onchange="refreshModifierFactureCompteurs()">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <button type="button" onclick="refreshModifierFactureCompteurs()" style="padding: 0.5rem 1rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.9rem;">Récupérer les compteurs</button>
                        </div>
                        <div id="modifierFactureMachinesContainer" style="margin-top: 1rem;"></div>
                    </div>
                    <div id="modifierFactureSimple" style="display: none;">
                        <div style="margin-bottom: 1rem;">
                            <label for="modifierFactureStatut" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Statut</label>
                            <select id="modifierFactureStatut" name="statut" style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                                <option value="brouillon">Brouillon</option>
                                <option value="en_attente">En attente</option>
                                <option value="envoyee">Envoyée</option>
                                <option value="en_cours">En cours</option>
                                <option value="en_retard">En retard</option>
                                <option value="payee">Payée</option>
                                <option value="annulee">Annulée</option>
                            </select>
                        </div>
                    </div>
                    <!-- Modifier Achat : produits (description, quantité, prix) -->
                    <div id="modifierFactureAchat" style="display: none;">
                        <p style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.9rem;">Modifiez les produits, quantités et montants. Le PDF sera régénéré.</p>
                        <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                            <strong>Produits</strong>
                            <button type="button" onclick="addModifierAchatProduit()" style="padding: 0.5rem 1rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.9rem;">+ Ajouter un produit</button>
                        </div>
                        <div id="modifierFactureAchatProduits"></div>
                    </div>
                    <!-- Modifier Service : description, montant -->
                    <div id="modifierFactureService" style="display: none;">
                        <p style="margin-bottom: 1rem; color: var(--text-secondary); font-size: 0.9rem;">Modifiez le nom du service et le montant. Le PDF sera régénéré.</p>
                        <div style="margin-bottom: 1rem;">
                            <label for="modifierFactureServiceDescription" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Nom du service</label>
                            <input type="text" id="modifierFactureServiceDescription" placeholder="Ex: Maintenance, Réparation..." style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                        </div>
                        <div style="margin-bottom: 1rem;">
                            <label for="modifierFactureServiceMontant" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Montant HT (€)</label>
                            <input type="number" id="modifierFactureServiceMontant" step="0.01" min="0" placeholder="0.00" style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                        </div>
                    </div>
                    <div style="margin-bottom: 1rem;">
                        <label for="modifierFactureDate" style="display: block; font-weight: 600; margin-bottom: 0.5rem;">Date de facture</label>
                        <input type="date" id="modifierFactureDate" name="date_facture" required style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                    </div>
                    <div id="modifierFactureError" style="display: none; padding: 0.75rem; background: rgba(239,68,68,0.1); border-radius: var(--radius-md); color: #dc2626; margin-bottom: 1rem;"></div>
                    <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-secondary" onclick="closeModifierFactureModal()">Annuler</button>
                        <button type="submit" class="btn btn-primary">Enregistrer</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Générer Facture -->
    <div class="modal-overlay" id="factureModalOverlay" onclick="closeFactureModal()">
        <div class="modal" id="factureModal" onclick="event.stopPropagation()">
        <div class="modal-header">
            <h2 class="modal-title">Générer une facture</h2>
            <button class="modal-close" onclick="closeFactureModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="factureForm" onsubmit="submitFactureForm(event)">
                <input type="hidden" name="csrf_token" id="factureFormCsrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                <div class="modal-form-row">
                    <div class="modal-form-group client-autocomplete-wrap">
                        <label for="client_search">Client <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="client_search" autocomplete="off" placeholder="Rechercher un client (ex: A, AB...)" aria-describedby="client_search_hint">
                        <input type="hidden" name="factureClient" id="factureClient" value="">
                        <div id="client_suggestions" class="client-suggestions" role="listbox" aria-label="Suggestions clients" style="display: none;"></div>
                        <div id="client_search_hint" class="input-hint">Tapez pour rechercher par nom (préfixe)</div>
                    </div>
                    <div class="modal-form-group">
                        <label for="factureDate">Date de facture <span style="color: #ef4444;">*</span></label>
                        <input type="date" id="factureDate" name="factureDate" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="modal-form-group">
                        <label for="factureType">Type <span style="color: #ef4444;">*</span></label>
                        <select id="factureType" name="factureType" required onchange="onFactureTypeChange()">
                            <option value="Consommation">Consommation</option>
                            <option value="Achat">Achat</option>
                            <option value="Service">Service</option>
                        </select>
                    </div>
                </div>

                <!-- Champs pour Consommation -->
                <div class="modal-form-row" id="factureConsommationFields">
                    <div class="modal-form-group">
                        <label for="factureOffre">Offre <span style="color: #ef4444;">*</span></label>
                        <select id="factureOffre" name="factureOffre" onchange="onFactureOffreChange()">
                            <option value="">Sélectionner une offre</option>
                            <option value="1000">Offre 1000 copies</option>
                            <option value="2000">Offre 2000 copies</option>
                        </select>
                        <div class="input-hint">Offre 2000: nécessite 2 photocopieurs</div>
                    </div>
                    <div class="modal-form-group">
                        <label for="factureDateDebut">Date début période</label>
                        <input type="date" id="factureDateDebut" name="factureDateDebut" onchange="loadConsommationData()">
                    </div>
                    <div class="modal-form-group">
                        <label for="factureDateFin">Date fin période</label>
                        <input type="date" id="factureDateFin" name="factureDateFin" onchange="loadConsommationData()">
                    </div>
                </div>

                <!-- Champs pour Achat -->
                <div id="factureAchatFields" style="display: none;">
                    <div class="facture-lignes-container" style="margin-bottom: 1rem;">
                        <div class="facture-lignes-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; font-size: 1rem; font-weight: 600;">Produits à facturer</h3>
                            <button type="button" class="btn btn-secondary" onclick="addAchatProduit()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                + Ajouter un produit
                            </button>
                        </div>
                        <div id="factureAchatProduits">
                            <!-- Les produits seront ajoutés ici dynamiquement -->
                        </div>
                    </div>
                </div>

                <!-- Champs pour Service -->
                <div id="factureServiceFields" style="display: none;">
                    <div class="modal-form-row">
                        <div class="modal-form-group" style="flex: 1;">
                            <label for="factureServiceDescription">Description du service <span style="color: #ef4444;">*</span></label>
                            <input type="text" id="factureServiceDescription" name="serviceDescription" placeholder="Ex: Maintenance, Réparation, Installation..." onchange="calculateFactureTotalService()">
                        </div>
                        <div class="modal-form-group" style="flex: 0 0 200px;">
                            <label for="factureServiceMontant">Montant HT (€) <span style="color: #ef4444;">*</span></label>
                            <input type="number" id="factureServiceMontant" name="serviceMontant" step="0.01" min="0" placeholder="0.00" onchange="calculateFactureTotalService()">
                        </div>
                    </div>
                </div>

                <!-- Zone de notifications client -->
                <div id="factureClientNotifications" style="display: none; margin-bottom: 1rem;">
                    <!-- Les notifications seront ajoutées ici dynamiquement -->
                </div>

                <div id="factureConsommationInfo" style="display: none; margin-bottom: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <div id="factureConsommationContent"></div>
                </div>

                <div class="facture-lignes-container" id="factureLignesContainer" style="display: none;">
                    <div class="facture-lignes-header">
                        <h3 style="margin: 0; font-size: 1rem; font-weight: 600;">Lignes de facture (calcul automatique)</h3>
                    </div>
                    <div id="factureLignes"></div>
                </div>

                <div class="facture-totaux">
                    <div class="facture-totaux-row">
                        <span>Total HT :</span>
                        <span><input type="number" id="factureMontantHT" name="montant_ht" step="0.01" value="0.00" readonly style="border: none; background: transparent; text-align: right; font-weight: 600; width: 120px;"> €</span>
                    </div>
                    <div class="facture-totaux-row">
                        <span>TVA (20%) :</span>
                        <span><input type="number" id="factureTVA" name="tva" step="0.01" value="0.00" readonly style="border: none; background: transparent; text-align: right; font-weight: 600; width: 120px; -moz-appearance: textfield;"> €</span>
                    </div>
                    <div class="facture-totaux-row total">
                        <span>Total TTC :</span>
                        <span><input type="number" id="factureMontantTTC" name="montant_ttc" step="0.01" value="0.00" readonly style="border: none; background: transparent; text-align: right; font-weight: 700; width: 120px; font-size: 1.25rem;"> €</span>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeFactureModal()">Annuler</button>
            <button type="submit" form="factureForm" class="btn btn-primary" id="btnGenererFacture">Générer la facture</button>
        </div>
        </div>
    </div>

    <!-- Modal Historique Paiements -->
    <div class="modal-overlay" id="historiquePaiementsModalOverlay" onclick="closeHistoriquePaiementsModal()">
        <div class="modal" id="historiquePaiementsModal" onclick="event.stopPropagation()" style="max-width: 1400px;">
            <div class="modal-header">
                <h2 class="modal-title">Historique des paiements</h2>
                <button class="modal-close" onclick="closeHistoriquePaiementsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="historiquePaiementsLoading" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    Chargement de l'historique...
                </div>
                <div id="historiquePaiementsContainer" style="display: none;">
                    <!-- Barre de recherche -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="position: relative;">
                            <input 
                                type="text" 
                                id="historiquePaiementsSearchInput" 
                                placeholder="Rechercher par facture, client, référence, mode de paiement..." 
                                style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem; color: var(--text-primary); background-color: var(--bg-secondary); transition: all 0.2s;"
                                oninput="filterHistoriquePaiements()"
                            />
                            <svg 
                                width="18" 
                                height="18" 
                                viewBox="0 0 24 24" 
                                fill="none" 
                                stroke="currentColor" 
                                stroke-width="2"
                                style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); pointer-events: none;"
                            >
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.35-4.35"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Filtres -->
                    <div style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="filter-btn active" data-filter="all" onclick="filterHistoriquePaiementsByStatus('all')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--accent-primary); color: white; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Tous
                        </button>
                        <button class="filter-btn" data-filter="recu" onclick="filterHistoriquePaiementsByStatus('recu')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Reçu
                        </button>
                        <button class="filter-btn" data-filter="en_cours" onclick="filterHistoriquePaiementsByStatus('en_cours')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            En cours
                        </button>
                        <button class="filter-btn" data-filter="refuse" onclick="filterHistoriquePaiementsByStatus('refuse')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Refusé
                        </button>
                        <button class="filter-btn" data-filter="annule" onclick="filterHistoriquePaiementsByStatus('annule')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Annulé
                        </button>
                    </div>
                    
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                        <span><span id="historiquePaiementsCount">0</span> paiement(s) trouvé(s)</span>
                        <span id="historiquePaiementsFilteredCount" style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;"></span>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Date</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Facture</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Client</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">Montant</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Mode</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Référence</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Statut</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="historiquePaiementsTableBody">
                                <!-- Les paiements seront ajoutés ici dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="historiquePaiementsError" style="display: none; text-align: center; padding: 2rem; color: #ef4444;">
                    Erreur lors du chargement de l'historique
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeHistoriquePaiementsModal()">Fermer</button>
            </div>
        </div>
    </div>

    <!-- Modal Facture Mail -->
    <div class="modal-overlay" id="factureMailModalOverlay" onclick="closeFactureMailModal()">
        <div class="modal facture-mail-modal" id="factureMailModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="modal-title">Envoyer la facture par email</h2>
                    <p class="modal-subtitle">Un PDF sera joint automatiquement</p>
                </div>
                <button class="modal-close" onclick="closeFactureMailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Card principale -->
                <div class="facture-mail-card">
                    <form id="factureMailForm" onsubmit="submitFactureMailForm(event)">
                        <input type="hidden" name="csrf_token" id="factureMailFormCsrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="modal-form-group facture-search-wrap">
                            <label for="facture_search">Facture <span class="required">*</span></label>
                            <input type="text" id="facture_search" autocomplete="off" placeholder="Rechercher une facture (nom, numéro, email, date)…" aria-describedby="facture_search_hint">
                            <input type="hidden" name="facture_id" id="facture_id" value="">
                            <div id="facture_search_hint" class="input-hint">Cliquez sur le champ pour afficher les résultats. Recherche par nom, prénom, email, numéro ou date.</div>
                            <!-- Badge de statut -->
                            <div id="factureMailStatusBadge" class="status-badge" style="display: none;">
                                <span class="status-badge-icon"></span>
                                <span class="status-badge-text"></span>
                            </div>
                            <!-- Grand panneau de recherche facture -->
                            <div id="factureSearchPanel" class="facture-search-panel" style="display: none;">
                                <div class="facture-search-filters">
                                    <input type="text" id="factureSearchFilterQ" placeholder="Recherche globale…" class="facture-search-filter-input">
                                    <input type="text" id="factureSearchFilterNom" placeholder="Nom / Raison sociale" class="facture-search-filter-input">
                                    <input type="text" id="factureSearchFilterPrenom" placeholder="Prénom" class="facture-search-filter-input">
                                    <input type="text" id="factureSearchFilterNumero" placeholder="N° facture" class="facture-search-filter-input">
                                    <input type="text" id="factureSearchFilterEmail" placeholder="Email" class="facture-search-filter-input">
                                    <input type="date" id="factureSearchFilterDate" class="facture-search-filter-input">
                                    <select id="factureSearchFilterStatut" class="facture-search-filter-select">
                                        <option value="">Tous les statuts</option>
                                        <option value="brouillon">Brouillon</option>
                                        <option value="en_attente">En attente</option>
                                        <option value="envoyee">Envoyée</option>
                                        <option value="en_cours">En cours</option>
                                        <option value="en_retard">En retard</option>
                                        <option value="payee">Payée</option>
                                        <option value="annulee">Annulée</option>
                                    </select>
                                    <button type="button" class="btn btn-secondary" onclick="factureSearchApplyFilters('facture')">Filtrer</button>
                                </div>
                                <div id="facture_search_loading" class="facture-search-loading" style="display: none;">Chargement…</div>
                                <div id="facture_search_results" class="facture-search-results"></div>
                                <div id="facture_search_empty" class="facture-search-empty" style="display: none;">Aucune facture trouvée</div>
                            </div>
                        </div>

                        <div class="modal-form-group">
                            <label for="factureMailEmail">Email du destinataire <span class="required">*</span></label>
                            <input type="email" id="factureMailEmail" name="email" required placeholder="client@example.com">
                            <div class="input-hint">L'email sera pré-rempli avec l'email du client si disponible</div>
                        </div>

                        <div class="modal-form-group">
                            <label for="factureMailSujet">Sujet de l'email</label>
                            <input type="text" id="factureMailSujet" name="sujet" placeholder="Facture - [Numéro de facture]">
                            <div class="input-hint">Le sujet sera pré-rempli avec un texte par défaut</div>
                        </div>

                        <div class="modal-form-group">
                            <label for="factureMailMessage">Message (optionnel)</label>
                            <textarea id="factureMailMessage" name="message" rows="5" placeholder="Message personnalisé à inclure dans l'email..."></textarea>
                            <div class="input-hint">Le message sera ajouté avant la pièce jointe de la facture</div>
                        </div>
                    </form>
                </div>

                <!-- Zone de résultat (succès/erreur) -->
                <div id="factureMailResult" class="facture-mail-result" style="display: none;">
                    <div class="result-content">
                        <div class="result-icon"></div>
                        <div class="result-message"></div>
                        <div class="result-details"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeFactureMailModal()">Annuler</button>
                <button type="button" class="btn btn-secondary" id="btnRenvoyerFactureMail" onclick="renvoyerFactureMail()" style="display: none;" disabled>Renvoyer</button>
                <button type="submit" form="factureMailForm" class="btn btn-primary" id="btnEnvoyerFactureMail">
                    <span class="btn-text">Envoyer la facture</span>
                    <span class="btn-loader" style="display: none;">
                        <svg class="spinner" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" opacity="0.25"></circle>
                            <path d="M12 2C6.477 2 2 6.477 2 12" stroke="currentColor" stroke-width="4" stroke-linecap="round"></path>
                        </svg>
                        Envoi en cours...
                    </span>
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Génération Facture Clients -->
    <div class="modal-overlay" id="generationFactureClientsModalOverlay" onclick="closeGenerationFactureClientsModal()">
        <div class="modal" id="generationFactureClientsModal" onclick="event.stopPropagation()" style="max-width: 1200px;">
            <div class="modal-header">
                <h2 class="modal-title">Génération de factures pour clients</h2>
                <button class="modal-close" onclick="closeGenerationFactureClientsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="generationFactureClientsForm" onsubmit="submitGenerationFactureClientsForm(event)">
                    <input type="hidden" name="csrf_token" id="generationFactureClientsFormCsrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="modal-form-group" style="padding: 1rem; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: var(--radius-md); margin-bottom: 1.5rem;">
                        <div style="font-weight: 600; color: #92400E; margin-bottom: 0.5rem;">&#8505; Génération automatique</div>
                        <div style="font-size: 0.875rem; color: #92400E; line-height: 1.4;">
                            Une facture de consommation sera créée pour chaque client ayant des imprimantes et des relevés récents. Les clients sans imprimante ou sans relevé depuis plus d'un mois sont exclus.
                        </div>
                    </div>

                    <div style="margin-bottom: 1.25rem;">
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">1. Paramètres de la facture</div>
                        <div class="modal-form-row">
                            <div class="modal-form-group">
                                <label for="genFactureDate">Date de facture <span style="color: #ef4444;">*</span></label>
                                <input type="date" id="genFactureDate" name="date_facture" required value="<?= date('Y-m-d') ?>">
                                <div class="input-hint">Date qui apparaîtra sur les factures</div>
                            </div>
                            <div class="modal-form-group">
                                <label for="genFactureOffre">Offre <span style="color: #ef4444;">*</span></label>
                                <select id="genFactureOffre" name="offre" required>
                                    <option value="1000">Offre 1000 (1 imprimante)</option>
                                    <option value="2000">Offre 2000 (2 imprimantes)</option>
                                </select>
                                <div class="input-hint">Appliquée à tous les clients</div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <div style="font-size: 0.8rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.75rem;">2. Période des relevés (compteurs)</div>
                        <div class="modal-form-row">
                            <div class="modal-form-group">
                                <label for="genFactureDateDebut">Du <span style="color: #ef4444;">*</span></label>
                                <input type="date" id="genFactureDateDebut" name="date_debut" required>
                                <div class="input-hint">Début de la période de consommation</div>
                            </div>
                            <div class="modal-form-group">
                                <label for="genFactureDateFin">Au <span style="color: #ef4444;">*</span></label>
                                <input type="date" id="genFactureDateFin" name="date_fin" required>
                                <div class="input-hint">Fin de la période de consommation</div>
                            </div>
                        </div>
                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem;">Si un relevé n'existe pas à la date exacte, le dernier disponible sera utilisé.</div>
                    </div>

                    <div id="genFactureNotifications" style="display: none; margin-bottom: 1rem;"></div>
                </form>
                
                <!-- Zone de progression -->
                <div id="genFactureProgressContainer" style="display: none; margin-top: 2rem; padding: 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: var(--radius-lg); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                    <!-- Percentage Display -->
                    <div class="progress-percentage-display" id="genFactureProgressPercentDisplay">
                        <span id="genFactureProgressPercent">0</span>%
                    </div>
                    
                    <!-- Progress Status Text -->
                    <div class="progress-status-text" id="genFactureProgressStatus">
                        Génération en cours...
                    </div>
                    
                    <!-- Progress Bar Container -->
                    <div class="progress-container-wrapper">
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" id="genFactureProgressBar">
                                <div class="progress-bar-shimmer"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistiques en temps réel -->
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="genFactureStatsClients">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Clients traités</div>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="genFactureStatsGenerees">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Factures générées</div>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="genFactureStatsExclus">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Clients exclus</div>
                        </div>
                    </div>
                    
                    <!-- Liste des résultats en temps réel -->
                    <div id="genFactureProgressLog" style="margin-top: 1.5rem; max-height: 200px; overflow-y: auto; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); padding: 1rem; display: none;">
                        <div style="font-weight: 600; color: #1a202c; margin-bottom: 0.75rem; font-size: 0.9rem;">Détails de la génération:</div>
                        <div id="genFactureProgressLogContent" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeGenerationFactureClientsModal()" id="btnCancelGeneration">Annuler</button>
                <button type="submit" form="generationFactureClientsForm" class="btn btn-primary" id="btnGenererFacturesClients">Générer les factures</button>
            </div>
        </div>
    </div>

    <!-- Modal Envoi en masse -->
    <div class="modal-overlay" id="envoiMasseModalOverlay" onclick="closeEnvoiMasseModal()">
        <div class="modal" id="envoiMasseModal" onclick="event.stopPropagation()" style="max-width: 1200px;">
            <div class="modal-header">
                <h2 class="modal-title">Envoi en masse des factures</h2>
                <button class="modal-close" onclick="closeEnvoiMasseModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="envoiMasseListLoading" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    Chargement des factures...
                </div>
                <div id="envoiMasseListContainer" style="display: none;">
                    <!-- Barre de recherche -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="position: relative;">
                            <input 
                                type="text" 
                                id="envoiMasseSearchInput" 
                                placeholder="Rechercher par numéro, client, date..." 
                                style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem; color: var(--text-primary); background-color: var(--bg-secondary); transition: all 0.2s;"
                                oninput="filterEnvoiMasseFactures()"
                            />
                            <svg 
                                width="18" 
                                height="18" 
                                viewBox="0 0 24 24" 
                                fill="none" 
                                stroke="currentColor" 
                                stroke-width="2"
                                style="position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: var(--text-secondary); pointer-events: none;"
                            >
                                <circle cx="11" cy="11" r="8"></circle>
                                <path d="m21 21-4.35-4.35"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Actions de sélection -->
                    <div style="margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                        <button type="button" class="btn btn-secondary" onclick="selectAllEnvoiMasse()" style="padding: 0.5rem 1rem; font-size: 0.9rem;">
                            Tout sélectionner
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="deselectAllEnvoiMasse()" style="padding: 0.5rem 1rem; font-size: 0.9rem;">
                            Tout désélectionner
                        </button>
                        <span style="margin-left: auto; font-weight: 600; color: var(--text-primary);">
                            <span id="envoiMasseSelectedCount">0</span> facture(s) sélectionnée(s)
                        </span>
                    </div>
                    
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                        <span><span id="envoiMasseCount">0</span> facture(s) disponible(s)</span>
                        <span id="envoiMasseFilteredCount" style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;"></span>
                    </div>
                    
                    <div style="overflow-x: auto; max-height: 400px; overflow-y: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead style="position: sticky; top: 0; background: var(--bg-primary); z-index: 10;">
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                                    <th style="padding: 0.75rem; text-align: center; width: 50px;">
                                        <input type="checkbox" id="envoiMasseSelectAll" onchange="toggleSelectAllEnvoiMasse(this)">
                                    </th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Numéro</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Date</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Client</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">Montant TTC</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Email</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Statut</th>
                                </tr>
                            </thead>
                            <tbody id="envoiMasseTableBody">
                                <!-- Les factures seront ajoutées ici dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="envoiMasseListError" style="display: none; text-align: center; padding: 2rem; color: #ef4444;">
                    Erreur lors du chargement des factures
                </div>
                
                <!-- Zone de progression -->
                <div id="envoiMasseProgressContainer" style="display: none; margin-top: 2rem; padding: 2rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: var(--radius-lg); box-shadow: 0 10px 30px rgba(0,0,0,0.2);">
                    <!-- Percentage Display -->
                    <div class="progress-percentage-display" id="envoiMasseProgressPercentDisplay">
                        <span id="envoiMasseProgressPercent">0</span>%
                    </div>
                    
                    <!-- Progress Status Text -->
                    <div class="progress-status-text" id="envoiMasseProgressStatus">
                        Envoi en cours...
                    </div>
                    
                    <!-- Progress Bar Container -->
                    <div class="progress-container-wrapper">
                        <div class="progress-bar-container">
                            <div class="progress-bar-fill" id="envoiMasseProgressBar">
                                <div class="progress-bar-shimmer"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Statistiques en temps réel -->
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="envoiMasseStatsTotal">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Total</div>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="envoiMasseStatsSuccess">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Envoyées</div>
                        </div>
                        <div style="text-align: center; padding: 1rem; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); border: 1px solid rgba(255,255,255,0.3);">
                            <div style="font-size: 2rem; font-weight: 700; color: #1a202c; margin-bottom: 0.25rem;">
                                <span id="envoiMasseStatsFailed">0</span>
                            </div>
                            <div style="font-size: 0.85rem; color: rgba(26,32,44,0.9); font-weight: 500;">Échecs</div>
                        </div>
                    </div>
                    
                    <!-- Liste des résultats -->
                    <div id="envoiMasseProgressLog" style="margin-top: 1.5rem; max-height: 300px; overflow-y: auto; background: rgba(255,255,255,0.8); border-radius: var(--radius-md); padding: 1rem; display: none;">
                        <div style="font-weight: 600; color: #1a202c; margin-bottom: 0.75rem; font-size: 0.9rem;">Détails de l'envoi:</div>
                        <div id="envoiMasseProgressLogContent" style="display: flex; flex-direction: column; gap: 0.5rem;"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEnvoiMasseModal()" id="btnCancelEnvoiMasse">Fermer</button>
                <button type="button" class="btn btn-primary" id="btnEnvoyerMasse" onclick="submitEnvoiMasse()" disabled>
                    Envoyer les factures sélectionnées
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Programmer envois -->
    <div class="modal-overlay" id="programmerEnvoisModalOverlay" onclick="closeProgrammerEnvoisModal()">
        <div class="modal" id="programmerEnvoisModal" onclick="event.stopPropagation()" style="max-width: 900px;">
            <div class="modal-header">
                <h2 class="modal-title">Programmer des envois</h2>
                <button class="modal-close" onclick="closeProgrammerEnvoisModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="programmerEnvoisForm" onsubmit="submitProgrammerEnvoisForm(event)">
                    <input type="hidden" name="csrf_token" id="programmerEnvoisFormCsrf" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="modal-form-group">
                        <label for="progTypeEnvoi">Type d'envoi <span style="color: #ef4444;">*</span></label>
                        <select id="progTypeEnvoi" name="type_envoi" required onchange="onProgTypeEnvoiChange()">
                            <option value="une_facture">Une facture précise</option>
                            <option value="plusieurs_factures">Plusieurs factures</option>
                            <option value="toutes_selectionnees">Toutes les factures sélectionnées</option>
                        </select>
                    </div>
                    <div class="modal-form-group facture-search-wrap" id="progFactureGroup">
                        <label for="progFactureSearch" id="progFactureGroupLabel">Facture <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="progFactureSearch" autocomplete="off" placeholder="Rechercher une facture (nom, numéro, email, date)…">
                        <input type="hidden" name="facture_id" id="progFactureId" value="">
                        <div class="input-hint" id="progFactureSearchHint">Cliquez pour afficher les résultats. Sélectionnez une ou plusieurs factures.</div>
                        <!-- Grand panneau de recherche facture - Programmer -->
                        <div id="progFactureSearchPanel" class="facture-search-panel" style="display: none;">
                            <div class="facture-search-filters">
                                <input type="text" id="progFactureSearchFilterQ" placeholder="Recherche globale…" class="facture-search-filter-input">
                                <input type="text" id="progFactureSearchFilterNom" placeholder="Nom / Raison sociale" class="facture-search-filter-input">
                                <input type="text" id="progFactureSearchFilterPrenom" placeholder="Prénom" class="facture-search-filter-input">
                                <input type="text" id="progFactureSearchFilterNumero" placeholder="N° facture" class="facture-search-filter-input">
                                <input type="text" id="progFactureSearchFilterEmail" placeholder="Email" class="facture-search-filter-input">
                                <input type="date" id="progFactureSearchFilterDate" class="facture-search-filter-input">
                                <select id="progFactureSearchFilterStatut" class="facture-search-filter-select">
                                    <option value="">Tous les statuts</option>
                                    <option value="brouillon">Brouillon</option>
                                    <option value="en_attente">En attente</option>
                                    <option value="envoyee">Envoyée</option>
                                    <option value="en_cours">En cours</option>
                                    <option value="en_retard">En retard</option>
                                    <option value="payee">Payée</option>
                                    <option value="annulee">Annulée</option>
                                </select>
                                <button type="button" class="btn btn-secondary" onclick="factureSearchApplyFilters('prog')">Filtrer</button>
                            </div>
                            <div class="facture-search-actions">
                                <button type="button" class="btn btn-secondary btn-sm" onclick="progFactureSelectAll()">Tout sélectionner</button>
                                <button type="button" class="btn btn-secondary btn-sm" onclick="progFactureDeselectAll()">Tout désélectionner</button>
                                <button type="button" class="btn btn-primary btn-sm" onclick="progFactureValidateSelection()">Valider la sélection</button>
                            </div>
                            <div id="progFactureSearchLoading" class="facture-search-loading" style="display: none;">Chargement…</div>
                            <div id="progFactureSearchResults" class="facture-search-results"></div>
                            <div id="progFactureSearchEmpty" class="facture-search-empty" style="display: none;">Aucune facture trouvée</div>
                        </div>
                    </div>
                    <div class="modal-form-group" id="progFacturesMultiGroup" style="display: none;">
                        <label>Factures à envoyer</label>
                        <div class="input-hint">Recherchez une facture ci-dessus (champ Facture) puis cliquez sur une suggestion pour l'ajouter</div>
                        <div id="progFacturesMultiList" style="max-height: 150px; overflow-y: auto; border: 2px solid var(--border-color); border-radius: var(--radius-md); padding: 0.5rem; min-height: 2.5rem;"><em style="color: var(--text-secondary);">Aucune facture sélectionnée</em></div>
                    </div>
                    <div class="modal-form-group">
                        <label><input type="checkbox" id="progUseClientEmail" name="use_client_email" checked onchange="onProgEmailOptionChange()"> Utiliser l'email du client</label>
                    </div>
                    <div class="modal-form-group" id="progEmailManualGroup" style="display: none;">
                        <label for="progEmailDestination">Email destinataire <span style="color: #ef4444;">*</span></label>
                        <input type="email" id="progEmailDestination" name="email_destination" placeholder="client@example.com">
                    </div>
                    <div class="modal-form-group">
                        <label><input type="checkbox" id="progAllClients" name="all_clients" onchange="onProgEmailOptionChange()"> Tous les clients concernés</label>
                    </div>
                    <div class="modal-form-row">
                        <div class="modal-form-group">
                            <label for="progDateEnvoi">Date d'envoi <span style="color: #ef4444;">*</span></label>
                            <input type="date" id="progDateEnvoi" name="date_envoi" required>
                        </div>
                        <div class="modal-form-group">
                            <label for="progHeureEnvoi">Heure d'envoi <span style="color: #ef4444;">*</span></label>
                            <input type="time" id="progHeureEnvoi" name="heure_envoi" required value="09:00">
                        </div>
                    </div>
                    <div class="modal-form-group">
                        <label for="progSujet">Objet email</label>
                        <input type="text" id="progSujet" name="sujet" placeholder="Facture - CC Computer">
                    </div>
                    <div class="modal-form-group">
                        <label for="progMessage">Message email</label>
                        <textarea id="progMessage" name="message" rows="4" placeholder="Message personnalisé à inclure..."></textarea>
                    </div>
                </form>
                <hr style="margin: 1.5rem 0; border: none; border-top: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                    <h3 style="margin: 0; font-size: 1rem;">Programmations existantes</h3>
                    <button type="button" class="btn btn-secondary" id="btnExecuterEnvoisProgrammes" onclick="executerEnvoisProgrammes()" style="display: inline-flex; align-items: center; gap: 0.5rem;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline>
                        </svg>
                        Exécuter les envois programmés
                    </button>
                </div>
                <div id="programmerEnvoisListLoading" style="text-align: center; padding: 1rem; color: var(--text-secondary);">Chargement…</div>
                <div id="programmerEnvoisListContainer" style="display: none;">
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
                            <thead>
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                                    <th style="padding: 0.5rem; text-align: left;">ID</th>
                                    <th style="padding: 0.5rem; text-align: left;">Facture(s)</th>
                                    <th style="padding: 0.5rem; text-align: left;">Destinataire</th>
                                    <th style="padding: 0.5rem; text-align: left;">Date/heure</th>
                                    <th style="padding: 0.5rem; text-align: center;">Statut</th>
                                    <th style="padding: 0.5rem; text-align: center;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="programmerEnvoisTableBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeProgrammerEnvoisModal()">Fermer</button>
                <button type="submit" form="programmerEnvoisForm" class="btn btn-primary" id="btnProgrammerEnvois">Créer la programmation</button>
            </div>
        </div>
    </div>

    <!-- Modal Payer -->
    <div class="modal-overlay" id="payerModalOverlay" onclick="closePayerModal()">
        <div class="modal" id="payerModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Enregistrer un paiement</h2>
                <button class="modal-close" onclick="closePayerModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="payerForm" onsubmit="submitPayerForm(event)">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token'] ?? '') ?>">
                    <div class="modal-form-group">
                        <label for="payerFactureSearch">Facture <span style="color: #ef4444;">*</span></label>
                        <div style="position: relative;">
                            <input type="text" id="payerFactureSearch" autocomplete="off" placeholder="Rechercher par nom, prénom, numéro de facture ou date..." style="width: 100%; padding: 0.75rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem;">
                            <input type="hidden" id="payerFacture" name="facture_id" value="">
                            <div id="payerFactureSuggestions" class="payer-suggestions" style="display: none; position: absolute; top: 100%; left: 0; right: 0; margin-top: 0.25rem; max-height: 280px; overflow-y: auto; background: var(--bg-primary); border: 2px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-lg); z-index: 1000;"></div>
                        </div>
                        <div id="payerFactureSelected" style="display: none; margin-top: 0.5rem; padding: 0.75rem; background: var(--bg-secondary); border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <span id="payerFactureSelectedLabel"></span>
                                <button type="button" onclick="clearPayerFactureSelection()" style="padding: 0.25rem 0.5rem; background: #ef4444; color: white; border: none; border-radius: var(--radius-sm); cursor: pointer; font-size: 0.8rem;">Changer</button>
                            </div>
                        </div>
                        <div class="input-hint" id="payerFactureHint">Tapez pour rechercher par nom, prénom, numéro ou date</div>
                    </div>

                    <div class="modal-form-row">
                        <div class="modal-form-group">
                            <label for="payerMontant">Montant (€) <span style="color: #ef4444;">*</span></label>
                            <input type="number" id="payerMontant" name="montant" step="0.01" min="0" required placeholder="0.00">
                        </div>
                        <div class="modal-form-group">
                            <label for="payerDate">Date de paiement <span style="color: #ef4444;">*</span></label>
                            <input type="date" id="payerDate" name="date_paiement" required value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <div class="modal-form-group">
                        <label for="payerMode">Mode de paiement <span style="color: #ef4444;">*</span></label>
                        <select id="payerMode" name="mode_paiement" required>
                            <option value="">Sélectionner un mode de paiement</option>
                            <option value="cb">Carte bancaire</option>
                            <option value="cheque">Chèque</option>
                            <option value="virement">Virement</option>
                            <option value="especes">Espèce</option>
                            <option value="autre">Autre paiement</option>
                        </select>
                        <div class="input-hint">Espèce et Carte bancaire : statut "Payé" automatique. Autres modes : statut "En cours"</div>
                    </div>

                    <div class="modal-form-group">
                        <label for="payerReference">Référence du paiement</label>
                        <input type="text" id="payerReference" name="reference" readonly style="background-color: var(--bg-secondary); cursor: not-allowed;" placeholder="Générée automatiquement">
                        <div class="input-hint">La référence sera générée automatiquement au format P + année + mois + jour + numéro unique (ex: P20251229001)</div>
                    </div>

                    <div class="modal-form-group">
                        <label for="payerJustificatif">Justificatif</label>
                        <input type="file" id="payerJustificatif" name="justificatif" accept=".pdf,.jpg,.jpeg,.png,.gif">
                        <div class="input-hint">Fichier PDF ou image (max 5MB)</div>
                    </div>

                    <div class="modal-form-group">
                        <label for="payerCommentaire">Commentaire</label>
                        <textarea id="payerCommentaire" name="commentaire" rows="3" placeholder="Notes supplémentaires sur ce paiement..."></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePayerModal()">Annuler</button>
                <button type="submit" form="payerForm" class="btn btn-primary" id="btnEnregistrerPaiement">Enregistrer le paiement</button>
        </div>
        </div>
    </div>

    <script src="/assets/js/paiements.js" charset="UTF-8"></script>
</body>
</html>

