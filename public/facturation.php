<?php
// /public/facturation.php
// Page de gestion de facturation et paiements

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('facturation', ['Admin', 'Dirigeant', 'Chargé relation clients']); // Accessible aux admins, dirigeants et commerciaux
require_once __DIR__ . '/../includes/helpers.php';

// Générer un token CSRF si manquant
ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Facturation & Paiements - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- CSS globaux -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- CSS spécifique à la page facturation -->
    <link rel="stylesheet" href="/assets/css/facturation.css">
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- SheetJS pour l'export Excel -->
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <!-- CSRF Token pour les requêtes API -->
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
</head>
<body class="page-facturation">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <!-- Header de page -->
    <header class="facturation-header">
        <div>
            <h1 class="page-title">Facturation & Paiements</h1>
            <p class="page-sub">Suivi des consommations, factures, paiements et envois clients.</p>
        </div>
        <button type="button" class="btn-export" id="btnExportExcel" aria-label="Exporter en Excel">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                <polyline points="7 10 12 15 17 10"/>
                <line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            Exporter en Excel
        </button>
    </header>

    <!-- Barre de recherche client -->
    <section class="filters-bar">
        <div class="client-search-section">
            <div class="client-search-wrapper">
                <label for="clientSearchInput" class="client-search-label">Rechercher un client</label>
                <div class="client-search-container">
                    <div class="client-search-input-wrapper">
                        <svg class="client-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"/>
                            <path d="m21 21-4.35-4.35"/>
                        </svg>
                        <input 
                            type="text" 
                            id="clientSearchInput" 
                            class="client-search-input" 
                            placeholder="Rechercher un client (nom, prénom, raison sociale, référence client…)"
                            autocomplete="off"
                        >
                    </div>
                    <div id="clientSearchDropdown" class="client-search-dropdown" style="display:none;"></div>
                </div>
                <div id="selectedClientDisplay" class="selected-client-display" style="display:none;">
                    <span class="selected-client-name"></span>
                    <button type="button" class="btn-remove-client" id="btnRemoveClient" aria-label="Retirer la sélection">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/>
                            <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
    </section>

    <!-- Graphique de consommation -->
    <section class="chart-section">
        <div class="content-card">
            <div class="card-header">
                <div>
                    <h3>Consommation des clients</h3>
                    <p class="card-subtitle">Vue globale de la consommation par période</p>
                </div>
                <div class="chart-controls">
                    <div class="chart-control-group">
                        <label for="chartGranularity">Granularité</label>
                        <select id="chartGranularity" class="filter-select chart-select">
                            <option value="year">Année</option>
                            <option value="month" selected>Mois</option>
                        </select>
                    </div>
                    <!-- Contrôles conditionnels pour la granularité -->
                    <div id="granularityYearControls" class="chart-control-group" style="display:none;">
                        <label for="chartYear">Année</label>
                        <select id="chartYear" class="filter-select chart-select">
                            <option value="2022">2022</option>
                            <option value="2023">2023</option>
                            <option value="2024">2024</option>
                            <option value="2025" selected>2025</option>
                        </select>
                    </div>
                    <div id="granularityMonthControls" class="chart-control-group" style="display:flex;">
                        <div class="chart-control-group">
                            <label for="chartMonthYear">Année</label>
                            <select id="chartMonthYear" class="filter-select chart-select">
                                <option value="2022">2022</option>
                                <option value="2023">2023</option>
                                <option value="2024">2024</option>
                                <option value="2025" selected>2025</option>
                            </select>
                        </div>
                        <div class="chart-control-group">
                            <label for="chartMonth">Mois</label>
                            <select id="chartMonth" class="filter-select chart-select">
                                <option value="0">Janvier</option>
                                <option value="1">Février</option>
                                <option value="2">Mars</option>
                                <option value="3">Avril</option>
                                <option value="4">Mai</option>
                                <option value="5">Juin</option>
                                <option value="6">Juillet</option>
                                <option value="7">Août</option>
                                <option value="8">Septembre</option>
                                <option value="9">Octobre</option>
                                <option value="10">Novembre</option>
                                <option value="11">Décembre</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div id="chartNoDataMessage" class="chart-no-data-message" style="display:none;">
                    <p>Aucun relevé pour cette période.</p>
                </div>
                <div class="chart-container">
                    <canvas id="consumptionChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Système d'onglets -->
    <section class="tabs-section" id="tabsSection" style="display:none;">
        <div class="tabs-nav" role="tablist">
            <button class="tab-btn active" data-tab="resume" role="tab" aria-selected="true" aria-controls="tab-resume">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="20" x2="12" y2="10"/>
                    <line x1="18" y1="20" x2="18" y2="4"/>
                    <line x1="6" y1="20" x2="6" y2="16"/>
                </svg>
                Résumé
            </button>
            <button class="tab-btn" data-tab="consommation" role="tab" aria-selected="false" aria-controls="tab-consommation">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                </svg>
                Consommation
            </button>
            <button class="tab-btn" data-tab="factures" role="tab" aria-selected="false" aria-controls="tab-factures">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                    <line x1="8" y1="4" x2="8" y2="22"/>
                </svg>
                Factures
            </button>
            <button class="tab-btn" data-tab="paiements" role="tab" aria-selected="false" aria-controls="tab-paiements">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                    <line x1="1" y1="10" x2="23" y2="10"/>
                </svg>
                Paiements
            </button>
            <button class="tab-btn" data-tab="emails" role="tab" aria-selected="false" aria-controls="tab-emails">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                    <polyline points="22,6 12,13 2,6"/>
                </svg>
                Emails & Docs
            </button>
        </div>

        <!-- Onglet Résumé -->
        <div class="tab-content active" id="tab-resume" role="tabpanel" aria-labelledby="tab-resume">
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total à facturer</div>
                    <div class="kpi-value" id="kpiTotalFacturer">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Montant non payé</div>
                    <div class="kpi-value" id="kpiMontantNonPaye">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Montant payé</div>
                    <div class="kpi-value" id="kpiMontantPaye">—</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Consommation pages</div>
                    <div class="kpi-value" id="kpiConsoPages">—</div>
                </div>
            </div>

            <div class="content-grid">
                <div class="content-card">
                    <div class="card-header">
                        <h3>Facture en cours</h3>
                    </div>
                    <div class="card-body">
                        <div class="facture-current">
                            <div class="facture-info">
                                <div class="facture-num" id="factureNum">—</div>
                                <div class="facture-status" id="factureStatus">
                                    <span class="badge badge-draft" style="display:none;">BROUILLON</span>
                                </div>
                                <div class="facture-amount">Consommation N&B : <strong id="factureConsoNB">—</strong></div>
                                <div class="facture-amount">Consommation couleur : <strong id="factureConsoCouleur">—</strong></div>
                                <div class="facture-amount">Montant TTC : <strong id="factureMontantTTC">—</strong></div>
                                <div class="facture-period" id="facturePeriod">—</div>
                            </div>
                            <div class="facture-actions">
                                <button type="button" class="btn-secondary" id="btnOuvrirFacture" style="display:none;">Ouvrir la facture</button>
                                <button type="button" class="btn-primary" id="btnGenererFacture" style="display:none;">Générer la facture</button>
                                <div id="factureRestrictionMessage" class="facture-restriction-message" style="display:none; margin-top: 0.5rem; font-size: 0.875rem; color: var(--text-secondary);">
                                    La génération de la facture n'est possible que le 20 de chaque mois.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3>Derniers paiements</h3>
                    </div>
                    <div class="card-body">
                        <div class="paiements-list" id="paiementsList">
                            <div style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                Sélectionnez un client pour voir les paiements
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglet Consommation -->
        <div class="tab-content" id="tab-consommation" role="tabpanel" aria-labelledby="tab-consommation">
            <div class="content-card">
                <div class="card-header">
                    <h3>Détail de la consommation</h3>
                    <button type="button" class="btn-export-small" id="btnExportConsommation" onclick="exportTableConsommation()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Exporter
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-wrapper">
                        <table class="table" id="tableConsommation">
                            <thead>
                                <tr>
                                    <th>Imprimante</th>
                                    <th>MAC address</th>
                                    <th>Pages N&B</th>
                                    <th>Pages couleur</th>
                                    <th>Total pages</th>
                                    <th>Mois (20 → 20)</th>
                                </tr>
                            </thead>
                            <tbody id="tableConsommationBody">
                                <!-- Les lignes seront générées dynamiquement par JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglet Factures -->
        <div class="tab-content" id="tab-factures" role="tabpanel" aria-labelledby="tab-factures">
            <div class="factures-layout">
                <div class="factures-list">
                    <div class="content-card">
                        <div class="card-header">
                            <h3>Liste des factures</h3>
                            <div class="card-actions">
                                <button type="button" class="btn-secondary btn-small" onclick="alert('Nouvelle facture manuelle')">+ Nouvelle facture manuelle</button>
                                <button type="button" class="btn-primary btn-small" onclick="alert('Générer facture de consommation')">Générer facture de consommation</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="factures-table-wrapper">
                                <table class="table table-compact">
                                    <thead>
                                        <tr>
                                            <th>Numéro</th>
                                            <th>Date</th>
                                            <th>Période</th>
                                            <th>Type</th>
                                            <th>Montant TTC</th>
                                            <th>Statut</th>
                                        </tr>
                                    </thead>
                                    <tbody id="facturesListBody">
                                        <tr>
                                            <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                                                Sélectionnez un client pour voir les factures
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div style="text-align: right; margin-top: 1rem;">
                                <button type="button" class="btn-secondary btn-small" id="btnVoirHistoriqueFactures" onclick="ouvrirHistoriqueFactures()">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem;">
                                        <line x1="12" y1="5" x2="12" y2="19"/>
                                        <line x1="5" y1="12" x2="19" y2="12"/>
                                    </svg>
                                    Voir l'historique
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="facture-detail" id="factureDetail">
                    <div class="content-card">
                        <div class="card-header">
                            <h3>Détail de la facture</h3>
                        </div>
                        <div class="card-body">
                            <div class="facture-detail-placeholder">
                                <p>Sélectionnez une facture pour voir le détail</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglet Paiements -->
        <div class="tab-content" id="tab-paiements" role="tabpanel" aria-labelledby="tab-paiements">
            <div class="content-grid">
                <div class="content-card">
                    <div class="card-header">
                        <h3>Résumé de la facture</h3>
                    </div>
                    <div class="card-body">
                        <div class="paiement-summary" id="paiementSummary">
                            <!-- Le résumé sera généré dynamiquement par JavaScript -->
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3>Historique des paiements</h3>
                    </div>
                    <div class="card-body">
                        <div class="paiements-timeline-wrapper">
                            <div class="paiements-timeline" id="paiementsTimeline">
                                <!-- Les paiements seront générés dynamiquement par JavaScript -->
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3>Ajouter un paiement</h3>
                    </div>
                    <div class="card-body">
                        <form class="standard-form" id="formAddPayment">
                            <div class="form-group">
                                <label for="paymentAmount">Montant *</label>
                                <input type="number" id="paymentAmount" step="0.01" min="0" required placeholder="0,00">
                            </div>
                            <div class="form-group">
                                <label for="paymentDate">Date *</label>
                                <input type="date" id="paymentDate" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-group">
                                <label for="paymentMode">Mode de paiement *</label>
                                <select id="paymentMode" required>
                                    <option value="">Sélectionner...</option>
                                    <option value="virement">Virement</option>
                                    <option value="cb">Carte bancaire</option>
                                    <option value="cheque">Chèque</option>
                                    <option value="especes">Espèces</option>
                                    <option value="autre">Autre</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="paymentRef">Référence</label>
                                <input type="text" id="paymentRef" placeholder="Ex: VIR-2025-001">
                            </div>
                            <div class="form-group">
                                <label for="paymentComment">Commentaire</label>
                                <textarea id="paymentComment" rows="3" placeholder="Commentaire optionnel"></textarea>
                            </div>
                            <div class="form-group">
                                <label>
                                    <input type="checkbox" id="paymentSendReceipt">
                                    Générer et envoyer un reçu au client
                                </label>
                            </div>
                            <button type="submit" class="fiche-action-btn">Enregistrer le paiement</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglet Emails & Docs -->
        <div class="tab-content" id="tab-emails" role="tabpanel" aria-labelledby="tab-emails">
            <div class="content-card">
                <div class="card-header">
                    <h3>Documents envoyés</h3>
                    <div class="filter-type">
                        <button type="button" class="filter-type-btn active" data-type="all">Tous</button>
                        <button type="button" class="filter-type-btn" data-type="facture">Factures</button>
                        <button type="button" class="filter-type-btn" data-type="contrat">Contrats</button>
                        <button type="button" class="filter-type-btn" data-type="recu">Reçus</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="emails-list">
                        <div class="email-item" data-type="facture">
                            <div class="email-icon email-icon-facture">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                            </div>
                            <div class="email-content">
                                <div class="email-type">Facture #2025-001</div>
                                <div class="email-date">Envoyé le 15/01/2025 à 14:30</div>
                                <div class="email-recipient">À : contact@entreprise-abc.fr</div>
                            </div>
                            <div class="email-status">
                                <span class="badge badge-sent">Envoyé</span>
                            </div>
                            <div class="email-actions">
                                <button type="button" class="btn-icon-small" onclick="alert('Voir PDF')" aria-label="Voir PDF">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </button>
                                <button type="button" class="btn-icon-small" onclick="alert('Renvoyer')" aria-label="Renvoyer">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="email-item" data-type="facture">
                            <div class="email-icon email-icon-facture">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                    <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                            </div>
                            <div class="email-content">
                                <div class="email-type">Facture #2024-125</div>
                                <div class="email-date">Envoyé le 10/12/2024 à 09:15</div>
                                <div class="email-recipient">À : comptabilite@societe-xyz.fr</div>
                            </div>
                            <div class="email-status">
                                <span class="badge badge-sent">Envoyé</span>
                            </div>
                            <div class="email-actions">
                                <button type="button" class="btn-icon-small" onclick="alert('Voir PDF')" aria-label="Voir PDF">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </button>
                                <button type="button" class="btn-icon-small" onclick="alert('Renvoyer')" aria-label="Renvoyer">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="email-item" data-type="recu">
                            <div class="email-icon email-icon-recu">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                    <polyline points="10 9 9 9 8 9"/>
                                </svg>
                            </div>
                            <div class="email-content">
                                <div class="email-type">Reçu de paiement #2025-001</div>
                                <div class="email-date">Envoyé le 15/01/2025 à 15:00</div>
                                <div class="email-recipient">À : contact@entreprise-abc.fr</div>
                            </div>
                            <div class="email-status">
                                <span class="badge badge-sent">Envoyé</span>
                            </div>
                            <div class="email-actions">
                                <button type="button" class="btn-icon-small" onclick="alert('Voir PDF')" aria-label="Voir PDF">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </button>
                                <button type="button" class="btn-icon-small" onclick="alert('Renvoyer')" aria-label="Renvoyer">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="email-item" data-type="contrat">
                            <div class="email-icon email-icon-contrat">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                    <polyline points="14 2 14 8 20 8"/>
                                    <line x1="16" y1="13" x2="8" y2="13"/>
                                    <line x1="16" y1="17" x2="8" y2="17"/>
                                </svg>
                            </div>
                            <div class="email-content">
                                <div class="email-type">Contrat de maintenance</div>
                                <div class="email-date">Envoyé le 05/12/2024 à 11:20</div>
                                <div class="email-recipient">À : direction@compagnie-def.fr</div>
                            </div>
                            <div class="email-status">
                                <span class="badge badge-error">Erreur</span>
                            </div>
                            <div class="email-actions">
                                <button type="button" class="btn-icon-small" onclick="alert('Voir PDF')" aria-label="Voir PDF">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                        <polyline points="14 2 14 8 20 8"/>
                                    </svg>
                                </button>
                                <button type="button" class="btn-icon-small" onclick="alert('Renvoyer')" aria-label="Renvoyer">
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <polyline points="23 4 23 10 17 10"/>
                                        <polyline points="1 20 1 14 7 14"/>
                                        <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<!-- Modal pour l'export Excel/PDF -->
<div class="modal-overlay" id="modalExport" style="display:none;">
    <div class="modal-content modal-export">
        <div class="modal-header">
            <h3>Exporter les données</h3>
            <button type="button" class="modal-close" id="modalExportClose" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body">
            <form class="standard-form" id="formExport">
                <!-- Choix du client (optionnel) -->
                <div class="form-section">
                    <h4 class="form-section-title">Client <span class="form-section-optional">(optionnel)</span></h4>
                    <div class="form-group">
                        <div class="client-search-container">
                            <div class="client-search-input-wrapper">
                                <svg class="client-search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"/>
                                    <path d="m21 21-4.35-4.35"/>
                                </svg>
                                <input 
                                    type="text" 
                                    id="exportClientSearch" 
                                    class="client-search-input" 
                                    placeholder="Rechercher un client (nom, prénom, raison sociale, référence client…)"
                                    autocomplete="off"
                                >
                            </div>
                            <div id="exportClientSearchDropdown" class="client-search-dropdown" style="display:none;"></div>
                        </div>
                        <div id="exportSelectedClientDisplay" class="selected-client-display" style="display:none;">
                            <span class="selected-client-name"></span>
                            <button type="button" class="btn-remove-client" id="btnRemoveExportClient" aria-label="Retirer la sélection">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <line x1="18" y1="6" x2="6" y2="18"/>
                                    <line x1="6" y1="6" x2="18" y2="18"/>
                                </svg>
                            </button>
                        </div>
                        <small class="form-hint">Si aucun client n'est sélectionné, l'export portera sur tous les clients.</small>
                    </div>
                </div>

                <!-- Choix de la période (obligatoire) -->
                <div class="form-section">
                    <h4 class="form-section-title">Période <span class="required">*</span></h4>
                    <div class="form-group">
                        <div class="button-group" role="group" aria-label="Type de période">
                            <button type="button" class="button-group-item active" data-value="year" id="btnPeriodYear">
                                Année
                            </button>
                            <button type="button" class="button-group-item" data-value="month" id="btnPeriodMonth">
                                Mois (20 → 20)
                            </button>
                        </div>
                        <input type="hidden" name="exportPeriodType" id="exportPeriodType" value="year">
                    
                    <!-- Contrôles pour l'année -->
                    <div id="exportYearControls" class="form-group" style="margin-top: 0.75rem;">
                        <label for="exportYear">Année</label>
                        <select id="exportYear" class="filter-select" required>
                            <option value="">Sélectionner une année</option>
                            <option value="2022">2022</option>
                            <option value="2023">2023</option>
                            <option value="2024">2024</option>
                            <option value="2025" selected>2025</option>
                        </select>
                    </div>
                    
                    <!-- Contrôles pour le mois (20→20) -->
                    <div id="exportMonthControls" class="form-group" style="display:none; margin-top: 0.75rem;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div>
                                <label for="exportMonthYear">Année</label>
                                <select id="exportMonthYear" class="filter-select">
                                    <option value="">Sélectionner une année</option>
                                    <option value="2022">2022</option>
                                    <option value="2023">2023</option>
                                    <option value="2024">2024</option>
                                    <option value="2025" selected>2025</option>
                                </select>
                            </div>
                            <div>
                                <label for="exportMonth">Mois</label>
                                <select id="exportMonth" class="filter-select">
                                    <option value="">Sélectionner un mois</option>
                                    <option value="0">Janvier</option>
                                    <option value="1">Février</option>
                                    <option value="2">Mars</option>
                                    <option value="3">Avril</option>
                                    <option value="4">Mai</option>
                                    <option value="5">Juin</option>
                                    <option value="6">Juillet</option>
                                    <option value="7">Août</option>
                                    <option value="8">Septembre</option>
                                    <option value="9">Octobre</option>
                                    <option value="10">Novembre</option>
                                    <option value="11">Décembre</option>
                                </select>
                            </div>
                        </div>
                        <small class="form-hint" id="exportMonthPeriodHint" style="margin-top: 0.5rem; display: none;"></small>
                    </div>
                    <div id="exportPeriodError" class="form-error" style="display:none;"></div>
                </div>

                <!-- Type de consommation -->
                <div class="form-section">
                    <h4 class="form-section-title">Type de consommation</h4>
                    <div class="form-group">
                        <div class="button-group" role="group" aria-label="Type de consommation">
                            <button type="button" class="button-group-item" data-value="nb" id="btnTypeNB">
                                Noir & Blanc
                            </button>
                            <button type="button" class="button-group-item" data-value="color" id="btnTypeColor">
                                Couleur
                            </button>
                            <button type="button" class="button-group-item active" data-value="both" id="btnTypeBoth">
                                Les deux (Total)
                            </button>
                        </div>
                        <input type="hidden" name="exportConsumptionType" id="exportConsumptionType" value="both">
                    </div>
                </div>

                <!-- Boutons d'action -->
                <div class="form-actions">
                    <button type="button" class="btn-secondary" id="btnCancelExport">Annuler</button>
                    <button type="submit" class="btn-primary" id="btnSubmitExport">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem;">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="7 10 12 15 17 10"/>
                            <line x1="12" y1="15" x2="12" y2="3"/>
                        </svg>
                        Exporter
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal pour ajouter un paiement -->
<div class="modal-overlay" id="modalAddPayment" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Ajouter un paiement</h3>
            <button type="button" class="modal-close" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body">
            <!-- Le formulaire est déjà dans l'onglet Paiements, on peut le réutiliser ou créer un nouveau -->
        </div>
    </div>
</div>

<!-- Modal pour l'historique des factures -->
<div class="modal-overlay" id="modalHistoriqueFactures" style="display:none;">
    <div class="modal-content" style="max-width: 900px;">
        <div class="modal-header">
            <h3>Historique des factures</h3>
            <button type="button" class="modal-close" id="btnCloseHistoriqueFactures" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Numéro</th>
                            <th>Date</th>
                            <th>Période</th>
                            <th>Type</th>
                            <th>Montant TTC</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody id="historiqueFacturesBody">
                        <!-- Les lignes seront générées dynamiquement par JavaScript -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal pour l'aperçu de facture -->
<div class="modal-overlay" id="modalFactureApercu" style="display:none;">
    <div class="modal-content modal-facture">
        <div class="modal-header">
            <h3>Aperçu de la facture</h3>
            <button type="button" class="modal-close" id="btnCloseFactureApercu" aria-label="Fermer">&times;</button>
        </div>
        <div class="modal-body facture-preview-body">
            <div class="facture-preview" id="facturePreview">
                <!-- Badge BROUILLON -->
                <div class="facture-brouillon-badge">
                    <span class="badge badge-draft">BROUILLON</span>
                </div>
                
                <!-- En-tête facture -->
                <div class="facture-header">
                    <div class="facture-entreprise">
                        <h2 class="facture-entreprise-name">CCComputer</h2>
                        <div class="facture-entreprise-details">
                            <div>123 Rue de l'Exemple</div>
                            <div>75000 Paris</div>
                            <div>Tél: 01 23 45 67 89</div>
                            <div>Email: contact@cccomputer.fr</div>
                        </div>
                    </div>
                    <div class="facture-header-right">
                        <div class="facture-title">FACTURE</div>
                        <div class="facture-numero" id="facturePreviewNum">Facture #2025-001</div>
                        <div class="facture-date" id="facturePreviewDate">Date : 07/02/2025</div>
                    </div>
                </div>
                
                <!-- Informations client -->
                <div class="facture-client-section">
                    <div class="facture-section-label">Client :</div>
                    <div class="facture-client-info" id="facturePreviewClient">
                        <div class="facture-client-name">Entreprise ABC</div>
                        <div>123 Rue Example</div>
                        <div>75000 Paris</div>
                        <div>contact@entreprise-abc.fr</div>
                    </div>
                </div>
                
                <!-- Période de consommation -->
                <div class="facture-period-section" id="facturePreviewPeriod">
                    <strong>Période de consommation :</strong> 20/01/2025 – 07/02/2025
                </div>
                
                <!-- Tableau des lignes -->
                <div class="facture-lignes">
                    <table class="facture-table">
                        <thead>
                            <tr>
                                <th class="facture-col-desc">Description</th>
                                <th class="facture-col-qty">Qté</th>
                                <th class="facture-col-pu">Prix unitaire HT</th>
                                <th class="facture-col-total">Total HT</th>
                            </tr>
                        </thead>
                        <tbody id="facturePreviewLignes">
                            <tr>
                                <td>Pages N&B</td>
                                <td class="text-right">8 450</td>
                                <td class="text-right">0,05 €</td>
                                <td class="text-right">422,50 €</td>
                            </tr>
                            <tr>
                                <td>Pages Couleur</td>
                                <td class="text-right">2 100</td>
                                <td class="text-right">0,15 €</td>
                                <td class="text-right">315,00 €</td>
                            </tr>
                            <tr>
                                <td>Maintenance</td>
                                <td class="text-right">1</td>
                                <td class="text-right">107,70 €</td>
                                <td class="text-right">107,70 €</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                
                <!-- Totaux -->
                <div class="facture-totaux">
                    <div class="facture-totaux-row">
                        <div class="facture-totaux-label">Sous-total HT :</div>
                        <div class="facture-totaux-value" id="facturePreviewHT">845,20 €</div>
                    </div>
                    <div class="facture-totaux-row">
                        <div class="facture-totaux-label">TVA (20%) :</div>
                        <div class="facture-totaux-value" id="facturePreviewTVA">169,04 €</div>
                    </div>
                    <div class="facture-totaux-row facture-totaux-total">
                        <div class="facture-totaux-label">Total TTC :</div>
                        <div class="facture-totaux-value" id="facturePreviewTTC">1 014,24 €</div>
                    </div>
                </div>
                
                <!-- Pied de page -->
                <div class="facture-footer">
                    <div class="facture-footer-text">
                        <p><strong>Conditions de paiement :</strong> Paiement à réception de facture, délai de paiement 30 jours.</p>
                        <p><strong>Mentions légales :</strong> CCComputer - SIRET: 123 456 789 00012 - RCS Paris B 123 456 789</p>
                        <p>En cas de retard de paiement, des pénalités de 3 fois le taux d'intérêt légal seront appliquées.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ==================
// REMOVED: Mock Data - Now using real API calls
// All data is loaded from the database via API endpoints
// ==================
// REMOVED: mockData object (previously lines 934-1180)
// The following functions now use real API calls:
// - updateResumeKPIs() -> /API/facturation_summary.php
// - updateFactureEnCours() -> /API/facturation_invoice.php
// - updatePaiementsDisplay() -> /API/facturation_payments_list.php
// - updateFacturesList() -> /API/facturation_factures_list.php
// - displayFactureDetail() -> /API/facturation_facture_detail.php
// - updateTableConsommation() -> /API/facturation_consumption_table.php
// - initConsumptionChart() -> /API/facturation_consumption_chart.php
// ==================

// Variable pour suivre l'état de la facture (sera mis à jour via API)
let factureGeneree = false;

// ==================
// Graphique de consommation
// ==================
let consumptionChart = null;
let selectedClientId = null; // Un seul client sélectionné via la barre de recherche

// Initialisation de la barre de recherche client
function initClientSearch() {
    const searchInput = document.getElementById('clientSearchInput');
    const dropdown = document.getElementById('clientSearchDropdown');
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    const btnRemove = document.getElementById('btnRemoveClient');
    
    if (!searchInput || !dropdown || !selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    let searchTimeout = null;
    
    // Recherche de clients avec debounce
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            dropdown.style.display = 'none';
            // Si le champ est vide et qu'un client était sélectionné, réinitialiser
            if (selectedClientId) {
                clearClientSelection();
            }
            return;
        }
        
        // Debounce de 200ms pour éviter trop de recherches
        searchTimeout = setTimeout(() => {
            performClientSearch(query, dropdown);
        }, 200);
    });
    
    // Fermer le dropdown en cliquant ailleurs
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
    
    // Retirer la sélection du client
    if (btnRemove) {
        btnRemove.addEventListener('click', () => {
            clearClientSelection();
        });
    }
    
    // Navigation clavier dans le dropdown
    searchInput.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.dropdown-item');
        if (items.length === 0) return;
        
        const currentIndex = Array.from(items).findIndex(item => item.classList.contains('highlighted'));
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === nextIndex);
            });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === prevIndex);
            });
        } else if (e.key === 'Enter' && currentIndex >= 0) {
            e.preventDefault();
            items[currentIndex].click();
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
            searchInput.blur();
        }
    });
}

// Effectuer la recherche de clients
async function performClientSearch(query, dropdown) {
    dropdown.innerHTML = '<div class="dropdown-item empty-state">Recherche...</div>';
    dropdown.style.display = 'block';
    
    try {
        const response = await fetch(`/API/facturation_search_clients.php?q=${encodeURIComponent(query)}&limit=10`);
        const result = await response.json();
        
        dropdown.innerHTML = '';
        
        if (!result.ok || !result.data || result.data.length === 0) {
            dropdown.innerHTML = '<div class="dropdown-item empty-state">Aucun client trouvé</div>';
            return;
        }
        
        result.data.forEach(client => {
            const item = document.createElement('div');
            item.className = 'dropdown-item';
            
            // Mettre en évidence les correspondances
            const name = highlightMatch(client.raison_sociale || client.name, query);
            const details = `${client.prenom || ''} ${client.nom || ''} • ${client.reference || client.numero_client || ''}`.trim();
            
            item.innerHTML = `
                <div class="dropdown-item-main">${name}</div>
                <div class="dropdown-item-sub">${details}</div>
            `;
            
            item.addEventListener('click', () => {
                selectClient(client.id, client.raison_sociale || client.name);
                document.getElementById('clientSearchInput').value = '';
                dropdown.style.display = 'none';
            });
            
            item.addEventListener('mouseenter', () => {
                dropdown.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('highlighted'));
                item.classList.add('highlighted');
            });
            
            dropdown.appendChild(item);
        });
    } catch (error) {
        console.error('Erreur recherche clients:', error);
        dropdown.innerHTML = '<div class="dropdown-item empty-state">Erreur de recherche</div>';
    }
}

// Mettre en évidence les correspondances dans le texte
function highlightMatch(text, query) {
    if (!query) return text;
    const regex = new RegExp(`(${query})`, 'gi');
    return text.replace(regex, '<strong>$1</strong>');
}

// Sélectionner un client
function selectClient(clientId, clientName) {
    selectedClientId = clientId;
    
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    if (!selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    selectedName.textContent = clientName;
    selectedDisplay.style.display = 'flex';
    
    // Afficher la section des onglets
    const tabsSection = document.getElementById('tabsSection');
    if (tabsSection) {
        tabsSection.style.display = 'block';
    }
    
    updateConsumptionChart();
    updateResumeKPIs().catch(err => console.error('Erreur updateResumeKPIs:', err));
    updateFactureEnCours().catch(err => console.error('Erreur updateFactureEnCours:', err));
    updatePaiementsDisplay().catch(err => console.error('Erreur updatePaiementsDisplay:', err));
    updateFacturesList().catch(err => console.error('Erreur updateFacturesList:', err));
}

// Réinitialiser la sélection (afficher tous les clients)
function clearClientSelection() {
    selectedClientId = null;
    
    const selectedDisplay = document.getElementById('selectedClientDisplay');
    if (selectedDisplay) {
        selectedDisplay.style.display = 'none';
    }
    
    const searchInput = document.getElementById('clientSearchInput');
    if (searchInput) {
        searchInput.value = '';
    }
    
    // Masquer la section des onglets
    const tabsSection = document.getElementById('tabsSection');
    if (tabsSection) {
        tabsSection.style.display = 'none';
    }
    
    updateConsumptionChart();
}

// Obtenir les paramètres de période depuis les contrôles
function getPeriodParams() {
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const params = {};
    
    if (granularityType === 'year') {
        const yearSelect = document.getElementById('chartYear');
        if (yearSelect) {
            params.year = parseInt(yearSelect.value);
        }
    } else if (granularityType === 'month') {
        const yearSelect = document.getElementById('chartMonthYear');
        const monthSelect = document.getElementById('chartMonth');
        if (yearSelect) params.year = parseInt(yearSelect.value);
        if (monthSelect) params.month = parseInt(monthSelect.value);
    }
    
    return params;
}

// Initialiser le graphe
async function initConsumptionChart() {
    const ctx = document.getElementById('consumptionChart');
    if (!ctx) return;
    
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const periodParams = getPeriodParams();
    const isAllClients = selectedClientId === null;
    
    // Afficher un indicateur de chargement
    const noDataMessage = document.getElementById('chartNoDataMessage');
    const chartContainer = document.querySelector('.chart-container');
    if (noDataMessage) {
        noDataMessage.style.display = 'block';
        noDataMessage.textContent = 'Chargement des données...';
    }
    if (chartContainer) {
        chartContainer.style.display = 'none';
    }
    
    try {
        // Construire l'URL de l'API
        const params = new URLSearchParams({
            granularity: granularityType,
            year: periodParams.year || new Date().getFullYear()
        });
        if (granularityType === 'month' && periodParams.month !== undefined) {
            params.append('month', periodParams.month);
        }
        if (!isAllClients) {
            params.append('client_id', selectedClientId);
        }
        
        const response = await fetch(`/API/facturation_consumption_chart.php?${params.toString()}`);
        
        // Vérifier le statut HTTP avant de parser le JSON
        if (!response.ok) {
            // Si la réponse n'est pas OK, essayer de parser le JSON pour obtenir le message d'erreur
            let errorMessage = `Erreur HTTP ${response.status}`;
            try {
                const errorData = await response.json();
                errorMessage = errorData.error || errorData.message || errorMessage;
            } catch (e) {
                // Si ce n'est pas du JSON, utiliser le texte brut
                const text = await response.text();
                errorMessage = text || errorMessage;
            }
            throw new Error(errorMessage);
        }
        
        const result = await response.json();
        
        if (!result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement des données');
        }
        
        const chartData = result.data;
        
        // Vérifier si toutes les données sont à zéro (aucun relevé)
        const hasData = chartData.nbData.some(val => val > 0) || chartData.colorData.some(val => val > 0);
        
        // Afficher le message si aucune donnée, mais toujours afficher le graphique (avec valeurs à zéro)
        if (noDataMessage) {
            noDataMessage.style.display = hasData ? 'none' : 'block';
            noDataMessage.textContent = 'Aucun relevé pour cette période.';
        }
        // Le graphique s'affiche toujours, même avec des valeurs à zéro
        if (chartContainer) {
            chartContainer.style.display = 'block';
        }
    
    // Créer les 3 datasets pour N&B, Couleur et Total (line chart) - version esthétique améliorée
    const datasets = [
        {
            label: 'Noir & Blanc',
            data: chartData.nbData,
            borderColor: 'rgb(30, 41, 59)', // Gris foncé (slate-800) - cohérent avec le projet
            backgroundColor: 'rgba(30, 41, 59, 0.08)', // Gradient léger sous la courbe
            borderWidth: 2.5,
            fill: true,
            tension: 0.3, // Courbes plus lissées
            pointRadius: 4.5,
            pointHoverRadius: 7,
            pointBackgroundColor: 'rgb(30, 41, 59)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2.5,
            pointHoverBorderWidth: 3,
            pointHoverBackgroundColor: 'rgb(30, 41, 59)'
        },
        {
            label: 'Couleur',
            data: chartData.colorData,
            borderColor: 'rgb(139, 92, 246)', // Violet (violet-500) - cohérent avec le projet
            backgroundColor: 'rgba(139, 92, 246, 0.08)', // Gradient léger sous la courbe
            borderWidth: 2.5,
            fill: true,
            tension: 0.3, // Courbes plus lissées
            pointRadius: 4.5,
            pointHoverRadius: 7,
            pointBackgroundColor: 'rgb(139, 92, 246)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2.5,
            pointHoverBorderWidth: 3,
            pointHoverBackgroundColor: 'rgb(139, 92, 246)'
        },
        {
            label: 'Total',
            data: chartData.totalData,
            borderColor: 'rgb(59, 130, 246)', // Bleu (blue-500) - accent principal du projet
            backgroundColor: 'rgba(59, 130, 246, 0.08)', // Gradient léger sous la courbe
            borderWidth: 2.5,
            fill: true,
            tension: 0.3, // Courbes plus lissées
            pointRadius: 4.5,
            pointHoverRadius: 7,
            pointBackgroundColor: 'rgb(59, 130, 246)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2.5,
            pointHoverBorderWidth: 3,
            pointHoverBackgroundColor: 'rgb(59, 130, 246)',
            borderDash: [6, 4] // Ligne en pointillés pour différencier la courbe Total
        }
    ];
    
    const config = {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1200,
                easing: 'easeInOutQuart'
            },
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            size: 12,
                            weight: '500'
                        },
                        generateLabels: function(chart) {
                            const original = Chart.defaults.plugins.legend.labels.generateLabels;
                            const labels = original.call(this, chart);
                            // Personnaliser les labels avec des carrés colorés
                            labels.forEach((label, index) => {
                                if (index === 0) {
                                    label.text = '◼ Noir & Blanc';
                                } else if (index === 1) {
                                    label.text = '◼ Couleur';
                                } else if (index === 2) {
                                    label.text = '◼ Total';
                                }
                            });
                            return labels;
                        },
                        onClick: function(e, legendItem, legend) {
                            // Permettre de cliquer sur la légende pour masquer/afficher les courbes
                            const index = legendItem.datasetIndex;
                            const chart = legend.chart;
                            const meta = chart.getDatasetMeta(index);
                            meta.hidden = meta.hidden === null ? !chart.data.datasets[index].hidden : null;
                            chart.update();
                        }
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: {
                        size: 14,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 13
                    },
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += context.parsed.y.toLocaleString('fr-FR') + ' pages';
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString('fr-FR');
                        },
                        font: {
                            family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            size: 11
                        },
                        padding: 8
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.04)',
                        drawBorder: false,
                        lineWidth: 1
                    }
                },
                x: {
                    ticks: {
                        font: {
                            family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            size: 11
                        },
                        padding: 8
                    },
                    grid: {
                        display: false,
                        drawBorder: false
                    }
                }
            }
        }
    };
    
        if (consumptionChart) {
            consumptionChart.destroy();
        }
        
        consumptionChart = new Chart(ctx, config);
    } catch (error) {
        console.error('Erreur chargement graphique:', error);
        if (noDataMessage) {
            noDataMessage.style.display = 'block';
            noDataMessage.textContent = 'Erreur lors du chargement des données.';
        }
        if (chartContainer) {
            chartContainer.style.display = 'none';
        }
    }
}

function updateConsumptionChart() {
    initConsumptionChart();
}

// Gérer l'affichage des contrôles conditionnels selon le type de granularité
function updateGranularityControls() {
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const yearControls = document.getElementById('granularityYearControls');
    const monthControls = document.getElementById('granularityMonthControls');
    
    // Masquer tous les contrôles
    if (yearControls) yearControls.style.display = 'none';
    if (monthControls) monthControls.style.display = 'none';
    
    // Afficher les contrôles appropriés
    if (granularityType === 'year' && yearControls) {
        yearControls.style.display = 'flex';
    } else if (granularityType === 'month' && monthControls) {
        monthControls.style.display = 'flex';
    }
}


// Initialiser les valeurs par défaut des contrôles de période
function initDefaultPeriodValues() {
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth(); // 0-11
    
    // Initialiser l'année pour tous les contrôles
    ['chartYear', 'chartMonthYear'].forEach(selectId => {
        const select = document.getElementById(selectId);
        if (select) {
            const option = select.querySelector(`option[value="${currentYear}"]`);
            if (option) option.selected = true;
        }
    });
    
    // Initialiser le mois pour le contrôle mois
    const monthSelect = document.getElementById('chartMonth');
    if (monthSelect) {
        const option = monthSelect.querySelector(`option[value="${currentMonth}"]`);
        if (option) option.selected = true;
    }
}

// Initialisation au chargement
document.addEventListener('DOMContentLoaded', () => {
    initClientSearch();
    
    // Initialiser la recherche de client pour l'export
    initExportClientSearch();
    initButtonGroups();
    updateExportPeriodControls();
    
    // Initialiser les valeurs par défaut
    initDefaultPeriodValues();
    
    // Initialiser les contrôles de granularité
    updateGranularityControls();
    
    initConsumptionChart();
    
    // Initialiser le calcul du montant non payé (sera appelé après sélection d'un client)
    // updateResumeKPIs() sera appelé automatiquement quand un client est sélectionné
    
    // Initialiser la facture en cours (sera appelé après sélection d'un client)
    // updateFactureEnCours() sera appelé automatiquement quand un client est sélectionné
    
    // Initialiser le tableau de consommation
    updateTableConsommation();
    
    // Écouter les changements de granularité
    const granularityTypeSelect = document.getElementById('chartGranularity');
    if (granularityTypeSelect) {
        granularityTypeSelect.addEventListener('change', () => {
            updateGranularityControls();
            updateConsumptionChart();
        });
    }
    
    // Écouter les changements des contrôles de période
    const periodControls = [
        'chartYear', 'chartMonthYear', 'chartMonth'
    ];
    
    periodControls.forEach(controlId => {
        const control = document.getElementById(controlId);
        if (control) {
            control.addEventListener('change', () => {
                updateConsumptionChart();
            });
        }
    });
});

// ==================
// Gestion des onglets
// ==================
const tabButtons = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');

tabButtons.forEach(btn => {
    btn.addEventListener('click', () => {
        const targetTab = btn.dataset.tab;
        
        // Désactiver tous les onglets
        tabButtons.forEach(b => {
            b.classList.remove('active');
            b.setAttribute('aria-selected', 'false');
        });
        tabContents.forEach(c => {
            c.classList.remove('active');
        });
        
        // Activer l'onglet sélectionné
        btn.classList.add('active');
        btn.setAttribute('aria-selected', 'true');
        document.getElementById(`tab-${targetTab}`).classList.add('active');
        
        // Si on passe à l'onglet Paiements, charger les données
        if (targetTab === 'paiements' && selectedClientId) {
            updatePaiementsDisplay().catch(err => console.error('Erreur updatePaiementsDisplay:', err));
        }
    });
});

// ==================
// Mise à jour du tableau de consommation
// ==================
async function updateTableConsommation() {
    const tbody = document.getElementById('tableConsommationBody');
    if (!tbody) return;
    
    // Afficher un indicateur de chargement
    tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 2rem;">Chargement des données...</td></tr>';
    
    try {
        // Construire l'URL de l'API
        const params = new URLSearchParams({
            months: '3'
        });
        if (selectedClientId) {
            params.append('client_id', selectedClientId);
        }
        
        const response = await fetch(`/API/facturation_consumption_table.php?${params.toString()}`);
        
        // Vérifier le statut HTTP avant de parser le JSON
        if (!response.ok) {
            // Si la réponse n'est pas OK, essayer de parser le JSON pour obtenir le message d'erreur
            let errorMessage = `Erreur HTTP ${response.status}`;
            try {
                const errorData = await response.json();
                errorMessage = errorData.error || errorData.message || errorMessage;
            } catch (e) {
                // Si ce n'est pas du JSON, utiliser le texte brut
                const text = await response.text();
                errorMessage = text || errorMessage;
            }
            throw new Error(errorMessage);
        }
        
        const result = await response.json();
        
        if (!result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement des données');
        }
        
        const imprimantes = result.data;
        
        if (imprimantes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 2rem;">Aucune donnée de consommation disponible.</td></tr>';
            return;
        }
    
    // Calculer les 3 derniers mois (périodes 20→20)
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth(); // 0-11
    const currentDay = now.getDate();
    
    // Si on est avant le 20, le mois en cours n'est pas encore terminé
    // On commence donc par le mois précédent
    let moisDebut = currentMonth;
    if (currentDay < 20) {
        moisDebut = currentMonth - 1;
    }
    
    // Générer les 3 derniers mois contractuels
    const derniersMois = [];
    for (let i = 0; i < 3; i++) {
        let mois = moisDebut - i;
        let annee = currentYear;
        
        // Gérer le passage d'année
        if (mois < 0) {
            mois += 12;
            annee--;
        }
        
        // Créer la clé de mois au format 'YYYY-MM'
        const moisKey = `${annee}-${String(mois + 1).padStart(2, '0')}`;
        
        // Calculer la période (20 du mois → 20 du mois suivant)
        const moisSuivant = (mois + 1) % 12;
        const anneeSuivante = mois === 11 ? annee + 1 : annee;
        
        // Noms des mois en français
        const moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        const moisNom = moisNoms[mois];
        
        // Format de la période : "Janvier 2025 (20/01 → 20/02)"
        const periode = `${moisNom} ${annee} (20/${String(mois + 1).padStart(2, '0')} → 20/${String(moisSuivant + 1).padStart(2, '0')})`;
        
        derniersMois.push({
            key: moisKey,
            periode: periode,
            mois: mois,
            annee: annee
        });
    }
    
        // Vider le tbody
        tbody.innerHTML = '';
        
        // Pour chaque imprimante, générer les lignes pour les mois disponibles
        imprimantes.forEach(imprimante => {
            // Les consommations sont déjà filtrées et triées par le backend
            const consommationsFiltrees = imprimante.consommations || [];
            
            // Si aucune consommation, on passe à l'imprimante suivante
            if (consommationsFiltrees.length === 0) return;
            
            // Créer une ligne pour chaque mois de consommation
            consommationsFiltrees.forEach((consommation, index) => {
                const periode = consommation.periode || consommation.mois;
            
            const tr = document.createElement('tr');
            
                // Colonne Imprimante (uniquement sur la première ligne)
                if (index === 0) {
                    const tdImprimante = document.createElement('td');
                    tdImprimante.setAttribute('rowspan', consommationsFiltrees.length);
                    tdImprimante.innerHTML = `
                        <div>${imprimante.nom || 'Inconnu'}</div>
                        <small>Modèle ${imprimante.modele || 'Inconnu'}</small>
                    `;
                    tr.appendChild(tdImprimante);
                }
                
                // Colonne MAC address (uniquement sur la première ligne)
                if (index === 0) {
                    const tdMac = document.createElement('td');
                    tdMac.setAttribute('rowspan', consommationsFiltrees.length);
                    tdMac.textContent = imprimante.macAddress || '';
                    tr.appendChild(tdMac);
                }
                
                // Colonne Pages N&B
                const tdNb = document.createElement('td');
                tdNb.textContent = (consommation.pagesNB || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdNb);
                
                // Colonne Pages couleur
                const tdColor = document.createElement('td');
                tdColor.textContent = (consommation.pagesCouleur || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdColor);
                
                // Colonne Total pages
                const tdTotal = document.createElement('td');
                tdTotal.textContent = (consommation.totalPages || 0).toLocaleString('fr-FR').replace(/,/g, ' ');
                tr.appendChild(tdTotal);
                
                // Colonne Mois (20 → 20)
                const tdMois = document.createElement('td');
                tdMois.textContent = periode;
                tr.appendChild(tdMois);
                
                tbody.appendChild(tr);
            });
        });
    } catch (error) {
        console.error('Erreur chargement tableau consommation:', error);
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 2rem; color: #ef4444;">Erreur lors du chargement des données.</td></tr>';
    }
}

// ==================
// Export Excel du tableau de consommation
// ==================
async function exportTableConsommation() {
    // Récupérer les données depuis le tableau affiché (ou depuis l'API si nécessaire)
    const tbody = document.getElementById('tableConsommationBody');
    if (!tbody || tbody.children.length === 0) {
        alert('Aucune donnée à exporter. Veuillez d\'abord charger les données de consommation.');
        return;
    }
    
    // Si pas de client sélectionné, on ne peut pas exporter
    if (!selectedClientId) {
        alert('Veuillez sélectionner un client pour exporter les données.');
        return;
    }
    
    try {
        // Récupérer les données depuis l'API pour l'export
        const response = await fetch(`/API/facturation_consumption_table.php?months=3&client_id=${selectedClientId}`);
        const result = await response.json();
        
        if (!response.ok || !result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement des données');
        }
        
        const imprimantes = result.data;
        
        if (!imprimantes || imprimantes.length === 0) {
            alert('Aucune donnée à exporter.');
            return;
        }
    
    // Calculer les 3 derniers mois (même logique que updateTableConsommation)
    const now = new Date();
    const currentYear = now.getFullYear();
    const currentMonth = now.getMonth();
    const currentDay = now.getDate();
    
    let moisDebut = currentMonth;
    if (currentDay < 20) {
        moisDebut = currentMonth - 1;
    }
    
    const derniersMois = [];
    for (let i = 0; i < 3; i++) {
        let mois = moisDebut - i;
        let annee = currentYear;
        
        if (mois < 0) {
            mois += 12;
            annee--;
        }
        
        const moisKey = `${annee}-${String(mois + 1).padStart(2, '0')}`;
        const moisSuivant = (mois + 1) % 12;
        const anneeSuivante = mois === 11 ? annee + 1 : annee;
        const moisNoms = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        const moisNom = moisNoms[mois];
        const periode = `${moisNom} ${annee} (20/${String(mois + 1).padStart(2, '0')} → 20/${String(moisSuivant + 1).padStart(2, '0')})`;
        
        derniersMois.push({
            key: moisKey,
            periode: periode,
            mois: mois,
            annee: annee
        });
    }
    
    // Préparer les données pour l'export
    const data = [];
    
    // En-têtes
    data.push(['Imprimante', 'MAC address', 'Pages N&B', 'Pages couleur', 'Total pages', 'Mois (20 → 20)']);
    
    // Données depuis l'API
    imprimantes.forEach(imprimante => {
        if (!imprimante.consommations || imprimante.consommations.length === 0) return;
        
        imprimante.consommations.forEach((consommation, index) => {
            const row = [];
            
            // Imprimante (uniquement sur la première ligne)
            if (index === 0) {
                row.push(`${imprimante.nom || 'Imprimante'} (Modèle ${imprimante.modele || 'N/A'})`);
            } else {
                row.push(''); // Cellule vide pour le rowspan
            }
            
            // MAC address (uniquement sur la première ligne)
            if (index === 0) {
                row.push(imprimante.macAddress || 'N/A');
            } else {
                row.push(''); // Cellule vide pour le rowspan
            }
            
            // Pages N&B
            row.push(consommation.pagesNB || 0);
            
            // Pages couleur
            row.push(consommation.pagesCouleur || 0);
            
            // Total pages
            row.push(consommation.totalPages || 0);
            
            // Mois
            row.push(consommation.periode || consommation.mois || 'N/A');
            
            data.push(row);
        });
    });
    
    // Créer le workbook et la feuille
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Ajuster la largeur des colonnes
    const colWidths = [
        { wch: 30 }, // Imprimante
        { wch: 18 }, // MAC address
        { wch: 12 }, // Pages N&B
        { wch: 14 }, // Pages couleur
        { wch: 12 }, // Total pages
        { wch: 30 }  // Mois
    ];
    ws['!cols'] = colWidths;
    
    // Ajouter la feuille au workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Consommation');
    
    // Générer le nom du fichier avec la date actuelle
    const dateStr = new Date().toISOString().split('T')[0].replace(/-/g, '');
    const filename = `consommation-client-${dateStr}.xlsx`;
    
    // Télécharger le fichier
    XLSX.writeFile(wb, filename);
}

// ==================
// Mise à jour du résumé (KPI)
// ==================
async function updateResumeKPIs() {
    if (!selectedClientId) {
        return;
    }
    
    try {
        const response = await fetch(`/API/facturation_summary.php?client_id=${selectedClientId}`);
        const result = await response.json();
        
        if (!result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement du résumé');
        }
        
        const data = result.data;
        
        // Formater un montant en format français
        const formatCurrency = (amount) => {
            return amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
        };
        
        // Mettre à jour les KPI
        const totalFacturerEl = document.getElementById('kpiTotalFacturer');
        const montantPayeEl = document.getElementById('kpiMontantPaye');
        const montantNonPayeEl = document.getElementById('kpiMontantNonPaye');
        const consoPagesEl = document.getElementById('kpiConsoPages');
        
        if (totalFacturerEl) {
            totalFacturerEl.textContent = formatCurrency(data.total_a_facturer);
        }
        if (montantPayeEl) {
            montantPayeEl.textContent = formatCurrency(data.montant_paye);
        }
        if (montantNonPayeEl) {
            montantNonPayeEl.textContent = formatCurrency(data.montant_non_paye);
        }
        if (consoPagesEl && data.consommation_pages) {
            const conso = data.consommation_pages;
            consoPagesEl.textContent = `N&B : ${conso.nb.toLocaleString('fr-FR')} | Couleur : ${conso.color.toLocaleString('fr-FR')}`;
        }
        
    } catch (error) {
        console.error('Erreur chargement résumé:', error);
    }
}

// ==================
// État de la facture en cours
// ==================
let factureGeneree = false; // État mock : false par défaut (facture non générée)

// ==================
// Mise à jour de la facture en cours
// ==================
async function updateFactureEnCours() {
    if (!selectedClientId) {
        // Pas de client sélectionné, masquer la facture
        return;
    }
    
    const now = new Date();
    const currentDay = now.getDate();
    const currentMonth = now.getMonth();
    const currentYear = now.getFullYear();
    
    // Calculer la période de facturation (20 du mois précédent → 20 du mois courant)
    let periodStartMonth = currentMonth - 1;
    let periodStartYear = currentYear;
    if (periodStartMonth < 0) {
        periodStartMonth = 11;
        periodStartYear--;
    }
    
    const periodStart = new Date(periodStartYear, periodStartMonth, 20);
    const periodEnd = new Date(currentYear, currentMonth, 20);
    
    // Formater les dates en format français (DD/MM/YYYY)
    const formatDate = (date) => {
        const day = String(date.getDate()).padStart(2, '0');
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const year = date.getFullYear();
        return `${day}/${month}/${year}`;
    };
    
    // Mettre à jour la période affichée
    const periodEl = document.getElementById('facturePeriod');
    if (periodEl) {
        periodEl.textContent = `Période : ${formatDate(periodStart)} – ${formatDate(periodEnd)}`;
    }
    
    // Générer le numéro de facture
    const factureNumEl = document.getElementById('factureNum');
    if (factureNumEl) {
        const monthStr = String(currentMonth + 1).padStart(2, '0');
        factureNumEl.textContent = `Facture ${currentYear}-${monthStr} (brouillon)`;
    }
    
    try {
        // Récupérer les données de facture depuis l'API
        const params = new URLSearchParams({
            client_id: selectedClientId,
            period_start: periodStart.toISOString().split('T')[0],
            period_end: periodEnd.toISOString().split('T')[0]
        });
        
        const response = await fetch(`/API/facturation_invoice.php?${params.toString()}`);
        const result = await response.json();
        
        if (!result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement des données');
        }
        
        const invoiceData = result.data;
        
        // Mettre à jour la consommation N&B et couleur
        const consoNBEl = document.getElementById('factureConsoNB');
        const consoCouleurEl = document.getElementById('factureConsoCouleur');
        if (consoNBEl && consoCouleurEl) {
            const total = invoiceData.total || {};
            const consoNB = total.nb || 0;
            const consoCouleur = total.color || 0;
            
            consoNBEl.textContent = `${consoNB.toLocaleString('fr-FR')} pages`;
            consoCouleurEl.textContent = `${consoCouleur.toLocaleString('fr-FR')} pages`;
        }
        
        // Calculer le montant TTC (à adapter selon vos tarifs)
        const montantTTCEl = document.getElementById('factureMontantTTC');
        if (montantTTCEl) {
            // TODO: Adapter selon vos tarifs réels
            // Exemple: 0.05€ par page N&B, 0.15€ par page couleur
            const prixNB = 0.05;
            const prixCouleur = 0.15;
            const total = invoiceData.total || {};
            const montantHT = (total.nb || 0) * prixNB + (total.color || 0) * prixCouleur;
            const tva = montantHT * 0.20; // TVA 20%
            const montantTTC = montantHT + tva;
            
            const formatted = montantTTC.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
            montantTTCEl.textContent = formatted + ' €';
        }
    } catch (error) {
        console.error('Erreur chargement facture:', error);
        // En cas d'erreur, afficher des valeurs par défaut
        const consoNBEl = document.getElementById('factureConsoNB');
        const consoCouleurEl = document.getElementById('factureConsoCouleur');
        if (consoNBEl && consoCouleurEl) {
            consoNBEl.textContent = '0 pages';
            consoCouleurEl.textContent = '0 pages';
        }
    }
    
    // Gérer la visibilité et l'activation des boutons selon l'état
    const btnOuvrirFacture = document.getElementById('btnOuvrirFacture');
    const btnGenererFacture = document.getElementById('btnGenererFacture');
    const restrictionMessage = document.getElementById('factureRestrictionMessage');
    
    const isDay20 = currentDay === 20;
    
    if (factureGeneree) {
        // Facture déjà générée : afficher "Ouvrir la facture", cacher "Générer la facture"
        if (btnOuvrirFacture) {
            btnOuvrirFacture.style.display = 'inline-flex';
        }
        if (btnGenererFacture) {
            btnGenererFacture.style.display = 'none';
        }
        if (restrictionMessage) {
            restrictionMessage.style.display = 'none';
        }
    } else {
        // Facture non générée : afficher "Générer la facture", cacher "Ouvrir la facture"
        if (btnOuvrirFacture) {
            btnOuvrirFacture.style.display = 'none';
        }
        // Facture non générée : afficher "Générer la facture", cacher "Ouvrir la facture"
        if (btnOuvrirFacture) {
            btnOuvrirFacture.style.display = 'none';
        }
        if (btnGenererFacture) {
            btnGenererFacture.style.display = 'inline-flex';
            
            // Gérer l'activation selon le jour du mois
            if (isDay20) {
                // Activer le bouton le 20
                btnGenererFacture.disabled = false;
                btnGenererFacture.style.opacity = '1';
                btnGenererFacture.style.cursor = 'pointer';
                if (restrictionMessage) {
                    restrictionMessage.style.display = 'none';
                }
            } else {
                // Désactiver le bouton si ce n'est pas le 20
                btnGenererFacture.disabled = true;
                btnGenererFacture.style.opacity = '0.5';
                btnGenererFacture.style.cursor = 'not-allowed';
                if (restrictionMessage) {
                    restrictionMessage.style.display = 'block';
                }
            }
        }
    }
}

// ==================
// Générer la facture
// ==================
function genererFacture() {
    const now = new Date();
    const currentDay = now.getDate();
    
    // Vérifier que c'est bien le 20
    if (currentDay !== 20) {
        alert('La génération de la facture n\'est possible que le 20 de chaque mois.');
        return;
    }
    
    // Mettre à jour l'état
    factureGeneree = true;
    
    // Mettre à jour le numéro de facture
    const factureNumEl = document.getElementById('factureNum');
    if (factureNumEl) {
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth();
        const monthStr = String(currentMonth + 1).padStart(2, '0');
        factureNumEl.textContent = `Facture #${currentYear}-${monthStr}`;
    }
    
    // Mettre à jour l'UI
    updateFactureEnCours();
    
    // Message de confirmation (mock)
    alert('Facture générée avec succès !');
}

// ==================
// Gestion des factures
// ==================
// Charger la liste des factures depuis l'API
async function updateFacturesList() {
    if (!selectedClientId) {
        const tbody = document.getElementById('facturesListBody');
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">Sélectionnez un client pour voir les factures</td></tr>';
        }
        return;
    }
    
    const tbody = document.getElementById('facturesListBody');
    if (!tbody) return;
    
    tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem;">Chargement des factures...</td></tr>';
    
    try {
        const response = await fetch(`/API/facturation_factures_list.php?client_id=${selectedClientId}&limit=50`);
        const result = await response.json();
        
        if (!response.ok || !result.ok || !result.data) {
            throw new Error(result.error || `Erreur serveur (${response.status})`);
        }
        
        const factures = result.data;
        
        if (factures.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">Aucune facture trouvée</td></tr>';
            return;
        }
        
        const formatDate = (dateStr) => {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('fr-FR');
        };
        
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
        };
        
        const getStatutBadge = (statut) => {
            const badges = {
                'brouillon': '<span class="badge badge-draft">Brouillon</span>',
                'envoyee': '<span class="badge badge-sent">Envoyée</span>',
                'payee': '<span class="badge badge-paid">Payée</span>',
                'en_retard': '<span class="badge badge-overdue">En retard</span>'
            };
            return badges[statut] || '';
        };
        
        tbody.innerHTML = factures.map(facture => {
            let periode = '—';
            if (facture.periode && facture.periode.debut && facture.periode.fin) {
                periode = `${formatDate(facture.periode.debut)} - ${formatDate(facture.periode.fin)}`;
            }
            
            return `
                <tr class="facture-row" data-facture-id="${facture.id}" style="cursor: pointer;">
                    <td>${facture.numero}</td>
                    <td>${formatDate(facture.date)}</td>
                    <td>${periode}</td>
                    <td>${facture.type}</td>
                    <td>${formatCurrency(facture.montantTTC)}</td>
                    <td>${getStatutBadge(facture.statut)}</td>
                </tr>
            `;
        }).join('');
        
        // Ajouter les event listeners aux nouvelles lignes
        document.querySelectorAll('.facture-row').forEach(row => {
            row.addEventListener('click', () => {
                const factureId = parseInt(row.dataset.factureId);
                displayFactureDetail(factureId);
            });
        });
        
    } catch (error) {
        console.error('Erreur chargement factures:', error);
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 2rem; color: #ef4444;">Erreur lors du chargement des factures: ${error.message}</td></tr>`;
    }
}

// Afficher le détail d'une facture depuis l'API
async function displayFactureDetail(factureId) {
    const detailPanel = document.getElementById('factureDetail');
    if (!detailPanel) return;
    
    detailPanel.innerHTML = '<div class="content-card"><div class="card-body"><p>Chargement...</p></div></div>';
    
    try {
        const response = await fetch(`/API/facturation_facture_detail.php?facture_id=${factureId}`);
        const result = await response.json();
        
        if (!response.ok || !result.ok || !result.data) {
            throw new Error(result.error || `Erreur serveur (${response.status})`);
        }
        
        const facture = result.data;
        
        const formatDate = (dateStr) => {
            if (!dateStr) return '—';
            const d = new Date(dateStr);
            return d.toLocaleDateString('fr-FR');
        };
        
        const formatCurrency = (amount) => {
            return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
        };
        
        const statutBadge = {
            'brouillon': '<span class="badge badge-draft">Brouillon</span>',
            'envoyee': '<span class="badge badge-sent">Envoyée</span>',
            'payee': '<span class="badge badge-paid">Payée</span>',
            'en_retard': '<span class="badge badge-overdue">En retard</span>'
        };
        
        let periodeHtml = '—';
        if (facture.periode && facture.periode.debut && facture.periode.fin) {
            periodeHtml = `${formatDate(facture.periode.debut)} - ${formatDate(facture.periode.fin)}`;
        }
        
        let lignesHtml = facture.lignes.map(ligne => `
            <tr>
                <td>${ligne.description}</td>
                <td>${ligne.type}</td>
                <td>${ligne.quantite}</td>
                <td>${formatCurrency(ligne.prixUnitaire)}</td>
                <td><strong>${formatCurrency(ligne.total)}</strong></td>
            </tr>
        `).join('');
    
    detailPanel.innerHTML = `
        <div class="content-card">
            <div class="card-header">
                <h3>Détail de la facture ${facture.numero}</h3>
            </div>
            <div class="card-body">
                <div class="facture-detail-info">
                    <div class="detail-section">
                        <h4>Informations client</h4>
                        <div class="detail-field">
                            <span class="detail-label">Nom :</span>
                            <span class="detail-value">${facture.client.nom}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Adresse :</span>
                            <span class="detail-value">${facture.client.adresse}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Email :</span>
                            <span class="detail-value">${facture.client.email}</span>
                        </div>
                    </div>
                    <div class="detail-section">
                        <h4>Informations facture</h4>
                        <div class="detail-field">
                            <span class="detail-label">Date :</span>
                            <span class="detail-value">${formatDate(facture.date)}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Période :</span>
                            <span class="detail-value">${periodeHtml}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Type :</span>
                            <span class="detail-value">${facture.type}</span>
                        </div>
                        <div class="detail-field">
                            <span class="detail-label">Statut :</span>
                            <span class="detail-value">${statutBadge[facture.statut] || ''}</span>
                        </div>
                    </div>
                </div>
                <div class="detail-actions">
                    ${facture.pdfGenere 
                        ? `<button type="button" class="btn-secondary" onclick="alert('Voir facture')">Voir facture</button>`
                        : `<button type="button" class="btn-secondary" onclick="alert('Générer PDF')">Générer PDF</button>`
                    }
                    <button type="button" class="btn-primary" onclick="alert('Envoyer au client')">Envoyer au client</button>
                </div>
                <div class="detail-table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Type</th>
                                <th>Quantité</th>
                                <th>Prix unitaire</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${lignesHtml}
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align:right;"><strong>Sous-total HT :</strong></td>
                                <td><strong>${formatCurrency(facture.montantHT)}</strong></td>
                            </tr>
                            <tr>
                                <td colspan="4" style="text-align:right;"><strong>TVA (20%) :</strong></td>
                                <td><strong>${formatCurrency(facture.tva)}</strong></td>
                            </tr>
                            <tr class="total-row">
                                <td colspan="4" style="text-align:right;"><strong>Total TTC :</strong></td>
                                <td><strong>${formatCurrency(facture.montantTTC)}</strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div class="detail-link">
                    <a href="#" onclick="event.preventDefault(); document.querySelector('[data-tab=paiements]').click();">Voir paiements liés</a>
                </div>
            </div>
        </div>
    `;
    } catch (error) {
        console.error('Erreur chargement détail facture:', error);
        detailPanel.innerHTML = `
            <div class="content-card">
                <div class="card-body">
                    <p style="color: #ef4444;">Erreur lors du chargement de la facture: ${error.message}</p>
                </div>
            </div>
        `;
    }
}

// ==================
// Gestion de l'historique des factures
// ==================
function ouvrirHistoriqueFactures() {
    const modal = document.getElementById('modalHistoriqueFactures');
    const tbody = document.getElementById('historiqueFacturesBody');
    
    if (!modal || !tbody) return;
    
    // Générer le tableau avec toutes les factures
    const formatDate = (dateStr) => {
        if (!dateStr) return '—';
        const d = new Date(dateStr);
        return d.toLocaleDateString('fr-FR');
    };
    
    const formatCurrency = (amount) => {
        return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
    };
    
    const statutBadge = {
        'brouillon': '<span class="badge badge-draft">Brouillon</span>',
        'envoyee': '<span class="badge badge-sent">Envoyée</span>',
        'payee': '<span class="badge badge-paid">Payée</span>',
        'en_retard': '<span class="badge badge-overdue">En retard</span>'
    };
    
    let rowsHtml = mockData.factures.map(facture => {
        let periodeHtml = '—';
        if (facture.periode) {
            periodeHtml = `${formatDate(facture.periode.debut)} - ${formatDate(facture.periode.fin)}`;
        }
        
        return `
            <tr class="facture-row" data-facture-id="${facture.id}" style="cursor: pointer;">
                <td>${facture.numero}</td>
                <td>${formatDate(facture.date)}</td>
                <td>${periodeHtml}</td>
                <td>${facture.type}</td>
                <td>${formatCurrency(facture.montantTTC)}</td>
                <td>${statutBadge[facture.statut] || ''}</td>
            </tr>
        `;
    }).join('');
    
    tbody.innerHTML = rowsHtml;
    
    // Ajouter les event listeners pour la sélection de facture
    tbody.querySelectorAll('.facture-row').forEach(row => {
        row.addEventListener('click', () => {
            const factureId = parseInt(row.dataset.factureId);
            const facture = mockData.factures.find(f => f.id === factureId);
            if (facture) {
                displayFactureDetail(facture);
                fermerHistoriqueFactures();
                // Basculer vers l'onglet Factures si nécessaire
                const tabFactures = document.querySelector('[data-tab=factures]');
                if (tabFactures) {
                    tabFactures.click();
                }
            }
        });
    });
    
    modal.style.display = 'flex';
}

function fermerHistoriqueFactures() {
    const modal = document.getElementById('modalHistoriqueFactures');
    if (modal) {
        modal.style.display = 'none';
    }
}

// Gestion de la fermeture du modal
document.getElementById('btnCloseHistoriqueFactures')?.addEventListener('click', fermerHistoriqueFactures);
document.getElementById('modalHistoriqueFactures')?.addEventListener('click', (e) => {
    if (e.target.id === 'modalHistoriqueFactures') {
        fermerHistoriqueFactures();
    }
});

// ==================
// Gestion des paiements
// ==================
document.getElementById('formAddPayment').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    if (!selectedClientId) {
        alert('Veuillez sélectionner un client d\'abord.');
        return;
    }
    
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const date = document.getElementById('paymentDate').value;
    const mode = document.getElementById('paymentMode').value;
    const ref = document.getElementById('paymentRef').value;
    const comment = document.getElementById('paymentComment').value;
    const sendReceipt = document.getElementById('paymentSendReceipt').checked;
    
    // Validation
    if (isNaN(amount) || amount <= 0) {
        alert('Montant invalide');
        return;
    }
    if (!date) {
        alert('Date de paiement requise');
        return;
    }
    if (!mode) {
        alert('Mode de paiement requis');
        return;
    }
    
    // Désactiver le bouton pendant l'envoi
    const submitBtn = e.target.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Enregistrement...';
    
    try {
        // Récupérer le token CSRF
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || 
                         document.querySelector('input[name="csrf_token"]')?.value ||
                         '';
        
        const response = await fetch('/API/facturation_payment_create.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                client_id: selectedClientId,
                montant: amount,
                date_paiement: date,
                mode_paiement: mode,
                reference: ref,
                commentaire: comment,
                send_receipt: sendReceipt,
                csrf_token: csrfToken
            })
        });
        
        const result = await response.json();
        
        if (!result.ok) {
            throw new Error(result.error || 'Erreur lors de la création du paiement');
        }
        
        // Réinitialiser le formulaire
        document.getElementById('formAddPayment').reset();
        document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
        
        // Mettre à jour l'affichage
        await updatePaiementsDisplay();
        await updateResumeKPIs();
        
        alert('Paiement enregistré avec succès !');
        
    } catch (error) {
        console.error('Erreur création paiement:', error);
        alert('Erreur: ' + error.message);
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

// ==================
// Mise à jour de l'affichage des paiements
// ==================
async function updatePaiementsDisplay() {
    if (!selectedClientId) {
        return;
    }
    
    try {
        // Récupérer la liste des paiements
        const response = await fetch(`/API/facturation_payments_list.php?client_id=${selectedClientId}`);
        const result = await response.json();
        
        if (!result.ok || !result.data) {
            throw new Error(result.error || 'Erreur lors du chargement des paiements');
        }
        
        const paiements = result.data;
        
        // Mettre à jour l'historique des paiements
        const timeline = document.getElementById('paiementsTimeline');
        if (timeline) {
            const formatDate = (dateStr) => {
                const d = new Date(dateStr);
                return d.toLocaleDateString('fr-FR');
            };
            
            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
            };
            
            const getStatutBadge = (statut) => {
                const badges = {
                    'en_cours': '<span class="badge badge-warning">EN COURS</span>',
                    'recu': '<span class="badge badge-success">Reçu</span>',
                    'refuse': '<span class="badge badge-danger">Refusé</span>',
                    'annule': '<span class="badge badge-secondary">Annulé</span>'
                };
                return badges[statut] || badges['en_cours'];
            };
            
            let timelineHtml = paiements.map(paiement => {
                let modeText = paiement.mode_paiement;
                if (paiement.reference) {
                    modeText += ` - Réf: ${paiement.reference}`;
                }
                
                return `
                    <div class="timeline-item">
                        <div class="timeline-date">${formatDate(paiement.date_paiement)}</div>
                        <div class="timeline-content">
                            <div class="timeline-amount">${formatCurrency(paiement.montant)}</div>
                            <div class="timeline-mode">${modeText}</div>
                            ${paiement.commentaire ? `<div class="timeline-comment">${paiement.commentaire}</div>` : ''}
                            <div class="timeline-statut">${getStatutBadge(paiement.statut)}</div>
                        </div>
                    </div>
                `;
            }).join('');
            
            if (paiements.length === 0) {
                timelineHtml = '<div class="timeline-item"><div class="timeline-content">Aucun paiement enregistré</div></div>';
            }
            
            timeline.innerHTML = timelineHtml;
        }
        
        // Mettre à jour le résumé de la facture (première facture non payée)
        const summary = document.getElementById('paiementSummary');
        if (summary) {
            // Récupérer les factures non payées depuis l'API summary
            const summaryResponse = await fetch(`/API/facturation_summary.php?client_id=${selectedClientId}`);
            const summaryResult = await summaryResponse.json();
            
            if (summaryResult.ok && summaryResult.data) {
                const summaryData = summaryResult.data;
                const formatCurrency = (amount) => {
                    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
                };
                
                if (summaryData.montant_non_paye > 0) {
                    summary.innerHTML = `
                        <div class="summary-row">
                            <span>Total à facturer :</span>
                            <strong>${formatCurrency(summaryData.total_a_facturer)}</strong>
                        </div>
                        <div class="summary-row">
                            <span>Total payé :</span>
                            <strong class="text-success">${formatCurrency(summaryData.montant_paye)}</strong>
                        </div>
                        <div class="summary-row">
                            <span>Solde restant :</span>
                            <strong class="text-warning">${formatCurrency(summaryData.montant_non_paye)}</strong>
                        </div>
                        <div class="summary-row">
                            <span>Statut paiement :</span>
                            <span class="badge badge-warning">PARTIELLEMENT PAYÉ</span>
                        </div>
                    `;
                } else {
                    summary.innerHTML = `
                        <div class="paiement-summary-empty">
                            <p>Toutes les factures sont payées</p>
                        </div>
                    `;
                }
            } else {
                summary.innerHTML = `
                    <div class="paiement-summary-empty">
                        <p>Aucune facture impayée</p>
                    </div>
                `;
            }
        }
        
        // Mettre à jour la liste des paiements dans l'onglet Résumé
        const paiementsList = document.getElementById('paiementsList');
        if (paiementsList) {
            const formatDate = (dateStr) => {
                const d = new Date(dateStr);
                return d.toLocaleDateString('fr-FR');
            };
            
            const formatCurrency = (amount) => {
                return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(amount);
            };
            
            const getStatutBadge = (statut) => {
                const badges = {
                    'en_cours': '<span class="badge badge-warning">EN COURS</span>',
                    'recu': '<span class="badge badge-success">Reçu</span>',
                    'refuse': '<span class="badge badge-danger">Refusé</span>',
                    'annule': '<span class="badge badge-secondary">Annulé</span>'
                };
                return badges[statut] || badges['en_cours'];
            };
            
            let paiementsHtml = paiements.slice(0, 5).map(paiement => {
                const userName = paiement.created_by_prenom && paiement.created_by_nom 
                    ? `${paiement.created_by_prenom} ${paiement.created_by_nom}`
                    : 'Admin CCComputer';
                
                return `
                    <div class="paiement-item">
                        <div class="paiement-date">${formatDate(paiement.date_paiement)}</div>
                        <div class="paiement-amount">${formatCurrency(paiement.montant)}</div>
                        <div class="paiement-user">${userName}</div>
                        <div class="paiement-mode">${paiement.mode_paiement}</div>
                        <div class="paiement-etat">${getStatutBadge(paiement.statut)}</div>
                    </div>
                `;
            }).join('');
            
            if (paiements.length === 0) {
                paiementsHtml = '<div class="paiement-item"><div style="text-align: center; padding: 1rem;">Aucun paiement enregistré</div></div>';
            }
            
            paiementsList.innerHTML = paiementsHtml;
        }
        
    } catch (error) {
        console.error('Erreur chargement paiements:', error);
        const timeline = document.getElementById('paiementsTimeline');
        if (timeline) {
            timeline.innerHTML = '<div class="timeline-item"><div class="timeline-content" style="color: #ef4444;">Erreur lors du chargement des paiements</div></div>';
        }
    }
}

// Initialiser l'affichage des paiements au chargement (sera appelé après sélection d'un client)
// updatePaiementsDisplay() sera appelé automatiquement quand un client est sélectionné

// ==================
// Gestion des emails/docs
// ==================
document.querySelectorAll('.filter-type-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.filter-type-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        
        const type = btn.dataset.type;
        const emailItems = document.querySelectorAll('.email-item');
        
        emailItems.forEach(item => {
            if (type === 'all' || item.dataset.type === type) {
                item.style.display = 'flex';
            } else {
                item.style.display = 'none';
            }
        });
    });
});

// ==================
// Export Excel/PDF - Modal
// ==================
let exportSelectedClientId = null;

// Ouvrir le modal d'export
document.getElementById('btnExportExcel').addEventListener('click', () => {
    openExportModal();
});

function openExportModal() {
    const modal = document.getElementById('modalExport');
    if (modal) {
        modal.style.display = 'flex';
        // Réinitialiser le formulaire
        document.getElementById('formExport').reset();
        exportSelectedClientId = null;
        document.getElementById('exportSelectedClientDisplay').style.display = 'none';
        
        // Réinitialiser les button groups
        document.querySelectorAll('#btnPeriodYear, #btnPeriodMonth').forEach(btn => btn.classList.remove('active'));
        document.getElementById('btnPeriodYear').classList.add('active');
        document.getElementById('exportPeriodType').value = 'year';
        
        document.querySelectorAll('#btnTypeNB, #btnTypeColor, #btnTypeBoth').forEach(btn => btn.classList.remove('active'));
        document.getElementById('btnTypeBoth').classList.add('active');
        document.getElementById('exportConsumptionType').value = 'both';
        
        updateExportPeriodControls();
        // Réinitialiser les valeurs par défaut
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth();
        document.getElementById('exportYear').value = currentYear;
        document.getElementById('exportMonthYear').value = currentYear;
        document.getElementById('exportMonth').value = currentMonth;
        updateExportMonthPeriodHint();
    }
}

// Fermer le modal d'export
function closeExportModal() {
    const modal = document.getElementById('modalExport');
    if (modal) {
        modal.style.display = 'none';
    }
}

document.getElementById('modalExportClose').addEventListener('click', closeExportModal);
document.getElementById('btnCancelExport').addEventListener('click', closeExportModal);

// Fermer en cliquant sur l'overlay
document.getElementById('modalExport').addEventListener('click', (e) => {
    if (e.target.id === 'modalExport') {
        closeExportModal();
    }
});

// Gérer les button groups
function initButtonGroups() {
    // Button group pour la période
    const periodButtons = document.querySelectorAll('#btnPeriodYear, #btnPeriodMonth');
    periodButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            periodButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const value = btn.dataset.value;
            document.getElementById('exportPeriodType').value = value;
            updateExportPeriodControls();
        });
    });
    
    // Button group pour le type de consommation
    const consumptionButtons = document.querySelectorAll('#btnTypeNB, #btnTypeColor, #btnTypeBoth');
    consumptionButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            consumptionButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const value = btn.dataset.value;
            document.getElementById('exportConsumptionType').value = value;
        });
    });
}

// Gérer les contrôles de période
function updateExportPeriodControls() {
    const periodType = document.getElementById('exportPeriodType').value;
    const yearControls = document.getElementById('exportYearControls');
    const monthControls = document.getElementById('exportMonthControls');
    
    if (periodType === 'year') {
        yearControls.style.display = 'block';
        monthControls.style.display = 'none';
        document.getElementById('exportYear').required = true;
        document.getElementById('exportMonthYear').required = false;
        document.getElementById('exportMonth').required = false;
    } else {
        yearControls.style.display = 'none';
        monthControls.style.display = 'block';
        document.getElementById('exportYear').required = false;
        document.getElementById('exportMonthYear').required = true;
        document.getElementById('exportMonth').required = true;
        updateExportMonthPeriodHint();
    }
}

// Mettre à jour l'indication de période pour le mois (20→20)
function updateExportMonthPeriodHint() {
    const monthSelect = document.getElementById('exportMonth');
    const yearSelect = document.getElementById('exportMonthYear');
    const hint = document.getElementById('exportMonthPeriodHint');
    
    if (!monthSelect || !yearSelect || !hint) return;
    
    const month = parseInt(monthSelect.value);
    const year = parseInt(yearSelect.value);
    
    if (month !== '' && !isNaN(month) && year && !isNaN(year)) {
        const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        const currentMonthName = monthNames[month];
        const nextMonth = (month + 1) % 12;
        const nextMonthName = monthNames[nextMonth];
        const nextYear = month === 11 ? year + 1 : year;
        
        hint.textContent = `Période : du 20 ${currentMonthName} ${year} au 20 ${nextMonthName} ${nextYear}`;
        hint.style.display = 'block';
    } else {
        hint.style.display = 'none';
    }
}

// Les button groups sont initialisés dans initButtonGroups()

// Écouter les changements de mois/année pour mettre à jour l'indication
document.getElementById('exportMonth').addEventListener('change', updateExportMonthPeriodHint);
document.getElementById('exportMonthYear').addEventListener('change', updateExportMonthPeriodHint);

// Initialiser la recherche de client dans le modal
function initExportClientSearch() {
    const searchInput = document.getElementById('exportClientSearch');
    const dropdown = document.getElementById('exportClientSearchDropdown');
    const selectedDisplay = document.getElementById('exportSelectedClientDisplay');
    const btnRemove = document.getElementById('btnRemoveExportClient');
    
    if (!searchInput || !dropdown || !selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    let searchTimeout = null;
    
    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        clearTimeout(searchTimeout);
        
        if (query.length < 1) {
            dropdown.style.display = 'none';
            if (exportSelectedClientId) {
                clearExportClientSelection();
            }
            return;
        }
        
        searchTimeout = setTimeout(() => {
            performExportClientSearch(query, dropdown);
        }, 200);
    });
    
    document.addEventListener('click', (e) => {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
    
    if (btnRemove) {
        btnRemove.addEventListener('click', () => {
            clearExportClientSelection();
        });
    }
    
    searchInput.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.dropdown-item');
        if (items.length === 0) return;
        
        const currentIndex = Array.from(items).findIndex(item => item.classList.contains('highlighted'));
        
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            const nextIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === nextIndex);
            });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            const prevIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
            items.forEach((item, idx) => {
                item.classList.toggle('highlighted', idx === prevIndex);
            });
        } else if (e.key === 'Enter' && currentIndex >= 0) {
            e.preventDefault();
            items[currentIndex].click();
        } else if (e.key === 'Escape') {
            dropdown.style.display = 'none';
            searchInput.blur();
        }
    });
}

function performExportClientSearch(query, dropdown) {
    const queryLower = query.toLowerCase();
    
    const filtered = mockData.consommation.clients.filter(client =>
        client.searchText.includes(queryLower)
    ).slice(0, 10);
    
    dropdown.innerHTML = '';
    
    if (filtered.length === 0) {
        dropdown.innerHTML = '<div class="dropdown-item empty-state">Aucun client trouvé</div>';
    } else {
        filtered.forEach(client => {
            const item = document.createElement('div');
            item.className = 'dropdown-item';
            
            const name = highlightMatch(client.raisonSociale, query);
            const details = `${client.prenom} ${client.nom} • ${client.reference}`;
            
            item.innerHTML = `
                <div class="dropdown-item-main">${name}</div>
                <div class="dropdown-item-sub">${details}</div>
            `;
            
            item.addEventListener('click', () => {
                selectExportClient(client.id, client.raisonSociale);
                document.getElementById('exportClientSearch').value = '';
                dropdown.style.display = 'none';
            });
            
            item.addEventListener('mouseenter', () => {
                dropdown.querySelectorAll('.dropdown-item').forEach(i => i.classList.remove('highlighted'));
                item.classList.add('highlighted');
            });
            
            dropdown.appendChild(item);
        });
    }
    
    dropdown.style.display = 'block';
}

function selectExportClient(clientId, clientName) {
    exportSelectedClientId = clientId;
    
    const selectedDisplay = document.getElementById('exportSelectedClientDisplay');
    if (!selectedDisplay) return;
    
    const selectedName = selectedDisplay.querySelector('.selected-client-name');
    if (!selectedName) return;
    
    selectedName.textContent = clientName;
    selectedDisplay.style.display = 'flex';
}

function clearExportClientSelection() {
    exportSelectedClientId = null;
    
    const selectedDisplay = document.getElementById('exportSelectedClientDisplay');
    if (selectedDisplay) {
        selectedDisplay.style.display = 'none';
    }
    
    const searchInput = document.getElementById('exportClientSearch');
    if (searchInput) {
        searchInput.value = '';
    }
}

// Générer le fichier Excel
function generateExcelFile(exportParams) {
    // Préparer les données pour l'export
    const data = [];
    
    // En-têtes
    const headers = ['Client', 'Période', 'Type', 'Pages N&B', 'Pages Couleur', 'Total Pages', 'Montant N&B (€)', 'Montant Couleur (€)', 'Total (€)'];
    data.push(headers);
    
    // Obtenir les clients à exporter
    const clientsToExport = exportSelectedClientId 
        ? [mockData.consommation.clients.find(c => c.id === exportSelectedClientId)]
        : mockData.consommation.clients;
    
    const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    
    // Générer les données selon la période
    if (exportParams.period.type === 'year') {
        const year = exportParams.period.year;
        const periodLabels = monthNames.map(m => `${m} ${year}`);
        
        clientsToExport.forEach(client => {
            const clientData = mockData.consommation.generateClientData(client.id, 'year', { year: year });
            
            periodLabels.forEach((label, index) => {
                const nbPages = clientData.nbData[index] || 0;
                const colorPages = clientData.colorData[index] || 0;
                const totalPages = nbPages + colorPages;
                const nbAmount = nbPages * 0.05;
                const colorAmount = colorPages * 0.15;
                const totalAmount = nbAmount + colorAmount;
                
                // Filtrer selon le type de consommation
                if (exportParams.consumptionType === 'nb' && nbPages === 0) return;
                if (exportParams.consumptionType === 'color' && colorPages === 0) return;
                
                const row = [
                    client.raisonSociale,
                    label,
                    exportParams.consumptionType === 'nb' ? 'N&B' : exportParams.consumptionType === 'color' ? 'Couleur' : 'Total',
                    exportParams.consumptionType === 'nb' || exportParams.consumptionType === 'both' ? nbPages : '',
                    exportParams.consumptionType === 'color' || exportParams.consumptionType === 'both' ? colorPages : '',
                    exportParams.consumptionType === 'both' ? totalPages : (exportParams.consumptionType === 'nb' ? nbPages : colorPages),
                    exportParams.consumptionType === 'nb' || exportParams.consumptionType === 'both' ? nbAmount.toFixed(2) : '',
                    exportParams.consumptionType === 'color' || exportParams.consumptionType === 'both' ? colorAmount.toFixed(2) : '',
                    totalAmount.toFixed(2)
                ];
                data.push(row);
            });
        });
    } else {
        // Période mois (20→20)
        const year = exportParams.period.year;
        const month = exportParams.period.month;
        const monthName = monthNames[month];
        const nextMonth = (month + 1) % 12;
        const nextMonthName = monthNames[nextMonth];
        const nextYear = month === 11 ? year + 1 : year;
        const periodLabel = `${monthName} ${year} (20 → 20)`;
        
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        clientsToExport.forEach(client => {
            const clientData = mockData.consommation.generateClientData(client.id, 'month', { year: year, month: month });
            
            for (let day = 1; day <= daysInMonth; day++) {
                const index = day - 1;
                const nbPages = clientData.nbData[index] || 0;
                const colorPages = clientData.colorData[index] || 0;
                const totalPages = nbPages + colorPages;
                const nbAmount = nbPages * 0.05;
                const colorAmount = colorPages * 0.15;
                const totalAmount = nbAmount + colorAmount;
                
                // Filtrer selon le type de consommation
                if (exportParams.consumptionType === 'nb' && nbPages === 0) continue;
                if (exportParams.consumptionType === 'color' && colorPages === 0) continue;
                
                const row = [
                    client.raisonSociale,
                    `${periodLabel} - Jour ${day}`,
                    exportParams.consumptionType === 'nb' ? 'N&B' : exportParams.consumptionType === 'color' ? 'Couleur' : 'Total',
                    exportParams.consumptionType === 'nb' || exportParams.consumptionType === 'both' ? nbPages : '',
                    exportParams.consumptionType === 'color' || exportParams.consumptionType === 'both' ? colorPages : '',
                    exportParams.consumptionType === 'both' ? totalPages : (exportParams.consumptionType === 'nb' ? nbPages : colorPages),
                    exportParams.consumptionType === 'nb' || exportParams.consumptionType === 'both' ? nbAmount.toFixed(2) : '',
                    exportParams.consumptionType === 'color' || exportParams.consumptionType === 'both' ? colorAmount.toFixed(2) : '',
                    totalAmount.toFixed(2)
                ];
                data.push(row);
            }
        });
    }
    
    // Créer le workbook
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(data);
    
    // Ajuster la largeur des colonnes
    const colWidths = [
        { wch: 25 }, // Client
        { wch: 20 }, // Période
        { wch: 10 }, // Type
        { wch: 12 }, // Pages N&B
        { wch: 14 }, // Pages Couleur
        { wch: 12 }, // Total Pages
        { wch: 15 }, // Montant N&B
        { wch: 17 }, // Montant Couleur
        { wch: 12 }  // Total
    ];
    ws['!cols'] = colWidths;
    
    // Ajouter la feuille au workbook
    XLSX.utils.book_append_sheet(wb, ws, 'Consommation');
    
    // Générer le nom du fichier
    let filename = 'export-consommation';
    if (exportParams.client) {
        filename += `-${exportParams.client.name.replace(/[^a-z0-9]/gi, '-').toLowerCase()}`;
    }
    if (exportParams.period.type === 'year') {
        filename += `-${exportParams.period.year}`;
    } else {
        filename += `-${monthNames[exportParams.period.month]}-${exportParams.period.year}`;
    }
    filename += '.xlsx';
    
    // Télécharger le fichier
    XLSX.writeFile(wb, filename);
}

// Validation et soumission du formulaire d'export
document.getElementById('formExport').addEventListener('submit', (e) => {
    e.preventDefault();
    
    const periodType = document.getElementById('exportPeriodType').value;
    const consumptionType = document.getElementById('exportConsumptionType').value;
    
    let period = null;
    let periodError = document.getElementById('exportPeriodError');
    
    // Valider la période
    if (periodType === 'year') {
        const year = document.getElementById('exportYear').value;
        if (!year) {
            periodError.textContent = 'Veuillez sélectionner une année.';
            periodError.style.display = 'block';
            return;
        }
        period = {
            type: 'year',
            year: parseInt(year)
        };
    } else {
        const year = document.getElementById('exportMonthYear').value;
        const month = document.getElementById('exportMonth').value;
        if (!year || month === '') {
            periodError.textContent = 'Veuillez sélectionner une année et un mois.';
            periodError.style.display = 'block';
            return;
        }
        const monthNum = parseInt(month);
        const yearNum = parseInt(year);
        const nextMonth = (monthNum + 1) % 12;
        const nextYear = monthNum === 11 ? yearNum + 1 : yearNum;
        
        period = {
            type: 'month',
            year: yearNum,
            month: monthNum,
            startDate: `${yearNum}-${String(monthNum + 1).padStart(2, '0')}-20`,
            endDate: `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-20`
        };
    }
    
    // Masquer l'erreur si tout est valide
    periodError.style.display = 'none';
    
    // Préparer les paramètres d'export
    const exportParams = {
        client: exportSelectedClientId ? {
            id: exportSelectedClientId,
            name: document.querySelector('#exportSelectedClientDisplay .selected-client-name').textContent
        } : null,
        period: period,
        consumptionType: consumptionType
    };
    
    // Générer et télécharger le fichier Excel
    try {
        generateExcelFile(exportParams);
        
        // Afficher une notification de succès
        showExportNotification(exportParams, true);
        
        // Fermer le modal
        closeExportModal();
    } catch (error) {
        console.error('Erreur lors de la génération du fichier Excel:', error);
        alert('Une erreur est survenue lors de la génération du fichier Excel.');
    }
});

// Afficher une notification de succès
function showExportNotification(params, isRealExport = false) {
    // Créer un élément de notification
    const notification = document.createElement('div');
    notification.className = 'export-notification';
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background-color: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-md);
        padding: 1rem 1.5rem;
        box-shadow: var(--shadow-lg);
        z-index: 2000;
        max-width: 400px;
        animation: slideIn 0.3s ease;
    `;
    
    let periodText = '';
    if (params.period.type === 'year') {
        periodText = `Année ${params.period.year}`;
    } else {
        const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        periodText = `${monthNames[params.period.month]} ${params.period.year} (20 → 20)`;
    }
    
    const clientText = params.client ? params.client.name : 'Tous les clients';
    const consumptionText = {
        'nb': 'Noir & Blanc',
        'color': 'Couleur',
        'both': 'Les deux (Total)'
    }[params.consumptionType];
    
    notification.innerHTML = `
        <div style="display: flex; align-items: center; gap: 0.75rem;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <div>
                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.25rem;">${isRealExport ? 'Export Excel généré' : 'Export simulé (mock)'}</div>
                <div style="font-size: 0.85rem; color: var(--text-secondary);">
                    Client: ${clientText}<br>
                    Période: ${periodText}<br>
                    Type: ${consumptionText}
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Retirer la notification après 5 secondes
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 5000);
}

// Les button groups sont initialisés dans le DOMContentLoaded principal

// ==================
// Modal paiement (conservé pour d'autres usages si nécessaire)
// ==================
const modalAddPayment = document.getElementById('modalAddPayment');
const modalClose = document.querySelector('.modal-close');

if (modalClose && modalAddPayment) {
    modalClose.addEventListener('click', () => {
        modalAddPayment.style.display = 'none';
    });
}

if (modalAddPayment) {
    modalAddPayment.addEventListener('click', (e) => {
        if (e.target === modalAddPayment) {
            modalAddPayment.style.display = 'none';
        }
    });
}

// ==================
// Modal aperçu de facture
// ==================
function openFactureApercu() {
    const modal = document.getElementById('modalFactureApercu');
    if (!modal) return;
    
    // Récupérer les données de la facture en cours
    const factureNum = document.getElementById('factureNum')?.textContent || 'Facture #2025-001';
    const facturePeriod = document.getElementById('facturePeriod')?.textContent.replace('Période : ', '') || '20/01/2025 - 07/02/2025';
    const factureMontantTTC = document.getElementById('factureMontantTTC')?.textContent || '845,20 €';
    
    // Extraire le montant TTC pour calculer HT et TVA
    const parseFrenchNumber = (text) => {
        return parseFloat(text.replace(/\s/g, '').replace(',', '.').replace(/[^\d.]/g, ''));
    };
    
    const montantTTC = parseFrenchNumber(factureMontantTTC);
    const montantHT = montantTTC / 1.20; // TVA à 20%
    const montantTVA = montantTTC - montantHT;
    
    // Formater un nombre en format français
    const formatFrenchNumber = (num) => {
        return num.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ') + ' €';
    };
    
    // Date actuelle
    const now = new Date();
    const dateFacture = now.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric' });
    
    // Mettre à jour le contenu de l'aperçu
    const facturePreviewNum = document.getElementById('facturePreviewNum');
    const facturePreviewDate = document.getElementById('facturePreviewDate');
    const facturePreviewPeriod = document.getElementById('facturePreviewPeriod');
    const facturePreviewHT = document.getElementById('facturePreviewHT');
    const facturePreviewTVA = document.getElementById('facturePreviewTVA');
    const facturePreviewTTC = document.getElementById('facturePreviewTTC');
    
    if (facturePreviewNum) facturePreviewNum.textContent = factureNum;
    if (facturePreviewDate) facturePreviewDate.textContent = `Date : ${dateFacture}`;
    if (facturePreviewPeriod) {
        facturePreviewPeriod.innerHTML = `<strong>Période de consommation :</strong> ${facturePeriod}`;
    }
    if (facturePreviewHT) facturePreviewHT.textContent = formatFrenchNumber(montantHT);
    if (facturePreviewTVA) facturePreviewTVA.textContent = formatFrenchNumber(montantTVA);
    if (facturePreviewTTC) facturePreviewTTC.textContent = formatFrenchNumber(montantTTC);
    
    // Ouvrir la modale
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeFactureApercu() {
    const modal = document.getElementById('modalFactureApercu');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Écouter le clic sur le bouton "Ouvrir la facture"
const btnOuvrirFacture = document.getElementById('btnOuvrirFacture');
if (btnOuvrirFacture) {
    btnOuvrirFacture.addEventListener('click', openFactureApercu);
}

// Écouter le clic sur le bouton "Générer la facture"
const btnGenererFacture = document.getElementById('btnGenererFacture');
if (btnGenererFacture) {
    btnGenererFacture.addEventListener('click', genererFacture);
}

// Fermer la modale
const btnCloseFactureApercu = document.getElementById('btnCloseFactureApercu');
if (btnCloseFactureApercu) {
    btnCloseFactureApercu.addEventListener('click', closeFactureApercu);
}

// Fermer en cliquant sur l'overlay
const modalFactureApercu = document.getElementById('modalFactureApercu');
if (modalFactureApercu) {
    modalFactureApercu.addEventListener('click', (e) => {
        if (e.target.id === 'modalFactureApercu') {
            closeFactureApercu();
        }
    });
}
</script>
</body>
</html>
