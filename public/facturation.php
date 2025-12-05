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
                    <div class="kpi-value" id="kpiTotalFacturer">1 245,30 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Montant non payé</div>
                    <div class="kpi-value" id="kpiMontantNonPaye">820,00 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Montant payé</div>
                    <div class="kpi-value" id="kpiMontantPaye">425,30 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Consommation pages</div>
                    <div class="kpi-value" id="kpiConsoPages">N&B : 10 200 | Couleur : 2 100</div>
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
                                <div class="facture-num" id="factureNum">Facture #2025-001</div>
                                <div class="facture-status">
                                    <span class="badge badge-draft">Brouillon</span>
                                </div>
                                <div class="facture-amount">Montant TTC : <strong id="factureMontantTTC">845,20 €</strong></div>
                                <div class="facture-amount">Montant collecté : <strong id="factureMontantCollecte">425,30 €</strong></div>
                                <div class="facture-period" id="facturePeriod">Période : 20/01/2025 - 07/02/2025</div>
                            </div>
                            <div class="facture-actions">
                                <button type="button" class="btn-secondary" id="btnOuvrirFacture">Ouvrir la facture</button>
                                <button type="button" class="btn-secondary" id="btnGenererPDF" onclick="alert('Générer PDF')">Générer PDF</button>
                                <button type="button" class="btn-primary" id="btnEnvoyerClient" onclick="alert('Envoyer au client')">Envoyer au client</button>
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
                            <div class="paiement-item">
                                <div class="paiement-date">15/01/2025</div>
                                <div class="paiement-amount">250,00 €</div>
                                <div class="paiement-user">Admin CCComputer</div>
                                <div class="paiement-mode">Virement</div>
                            </div>
                            <div class="paiement-item">
                                <div class="paiement-date">10/01/2025</div>
                                <div class="paiement-amount">175,30 €</div>
                                <div class="paiement-user">Utilisateur X</div>
                                <div class="paiement-mode">Carte bancaire</div>
                            </div>
                            <div class="paiement-item">
                                <div class="paiement-date">05/01/2025</div>
                                <div class="paiement-amount">100,00 €</div>
                                <div class="paiement-user">Admin CCComputer</div>
                                <div class="paiement-mode">Espèces</div>
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
                    <button type="button" class="btn-export-small" onclick="alert('Exporter en Excel')">
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
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Imprimante</th>
                                    <th>N° série / MAC</th>
                                    <th>Pages N&B</th>
                                    <th>Pages couleur</th>
                                    <th>Total pages</th>
                                    <th>Montant N&B</th>
                                    <th>Montant couleur</th>
                                    <th>Total €</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>
                                        <div>HP LaserJet Pro</div>
                                        <small>Modèle M404dn</small>
                                    </td>
                                    <td>SN-12345678</td>
                                    <td>8 450</td>
                                    <td>0</td>
                                    <td>8 450</td>
                                    <td>422,50 €</td>
                                    <td>0,00 €</td>
                                    <td><strong>422,50 €</strong></td>
                                </tr>
                                <tr class="row-alert">
                                    <td>
                                        <div>Canon imageRUNNER</div>
                                        <small>Modèle ADV C5235i</small>
                                    </td>
                                    <td>MAC-AB:CD:EF:12</td>
                                    <td>1 750</td>
                                    <td>2 100</td>
                                    <td>3 850</td>
                                    <td>87,50 €</td>
                                    <td>315,00 €</td>
                                    <td><strong>402,50 €</strong></td>
                                </tr>
                                <tr>
                                    <td>
                                        <div>Xerox VersaLink</div>
                                        <small>Modèle C405</small>
                                    </td>
                                    <td>SN-87654321</td>
                                    <td>0</td>
                                    <td>1 110</td>
                                    <td>1 110</td>
                                    <td>0,00 €</td>
                                    <td>166,50 €</td>
                                    <td><strong>166,50 €</strong></td>
                                </tr>
                            </tbody>
                        </table>
                        <div class="table-alert-note">
                            <span class="badge badge-warning">⚠️ Toner faible</span> indique un niveau de toner inférieur à 20%
                        </div>
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
                                        <tr class="facture-row" data-facture-id="1">
                                            <td>#2025-001</td>
                                            <td>15/01/2025</td>
                                            <td>01/01 - 31/01</td>
                                            <td>Consommation</td>
                                            <td>845,20 €</td>
                                            <td><span class="badge badge-draft">Brouillon</span></td>
                                        </tr>
                                        <tr class="facture-row" data-facture-id="2">
                                            <td>#2024-125</td>
                                            <td>10/12/2024</td>
                                            <td>01/12 - 31/12</td>
                                            <td>Consommation</td>
                                            <td>1 120,50 €</td>
                                            <td><span class="badge badge-sent">Envoyée</span></td>
                                        </tr>
                                        <tr class="facture-row" data-facture-id="3">
                                            <td>#2024-124</td>
                                            <td>05/12/2024</td>
                                            <td>—</td>
                                            <td>Achat</td>
                                            <td>450,00 €</td>
                                            <td><span class="badge badge-paid">Payée</span></td>
                                        </tr>
                                        <tr class="facture-row" data-facture-id="4">
                                            <td>#2024-123</td>
                                            <td>20/11/2024</td>
                                            <td>01/11 - 30/11</td>
                                            <td>Consommation</td>
                                            <td>980,30 €</td>
                                            <td><span class="badge badge-overdue">En retard</span></td>
                                        </tr>
                                        <tr class="facture-row" data-facture-id="5">
                                            <td>#2024-122</td>
                                            <td>15/11/2024</td>
                                            <td>—</td>
                                            <td>Service</td>
                                            <td>320,00 €</td>
                                            <td><span class="badge badge-paid">Payée</span></td>
                                        </tr>
                                    </tbody>
                                </table>
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
                        <div class="paiement-summary">
                            <div class="summary-row">
                                <span>Numéro facture :</span>
                                <strong>#2025-001</strong>
                            </div>
                            <div class="summary-row">
                                <span>Montant TTC :</span>
                                <strong>845,20 €</strong>
                            </div>
                            <div class="summary-row">
                                <span>Total payé :</span>
                                <strong class="text-success">425,30 €</strong>
                            </div>
                            <div class="summary-row">
                                <span>Solde restant :</span>
                                <strong class="text-warning">419,90 €</strong>
                            </div>
                            <div class="summary-row">
                                <span>Statut paiement :</span>
                                <span class="badge badge-partial">Partiellement payé</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3>Historique des paiements</h3>
                    </div>
                    <div class="card-body">
                        <div class="paiements-timeline">
                            <div class="timeline-item">
                                <div class="timeline-date">15/01/2025</div>
                                <div class="timeline-content">
                                    <div class="timeline-amount">250,00 €</div>
                                    <div class="timeline-mode">Virement - Réf: VIR-2025-001</div>
                                    <div class="timeline-comment">Paiement partiel</div>
                                </div>
                            </div>
                            <div class="timeline-item">
                                <div class="timeline-date">10/01/2025</div>
                                <div class="timeline-content">
                                    <div class="timeline-amount">175,30 €</div>
                                    <div class="timeline-mode">Carte bancaire - Réf: CB-2025-045</div>
                                </div>
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
// Mock Data
// ==================
const mockData = {
    clients: [
        { id: 1, name: 'Entreprise ABC', contrats: [1, 2] },
        { id: 2, name: 'Société XYZ', contrats: [1, 3] },
        { id: 3, name: 'Compagnie DEF', contrats: [2] },
        { id: 4, name: 'Groupe GHI', contrats: [1, 2, 3] }
    ],
    contrats: [
        { id: 1, name: 'Contrat Copie N&B – Mensuel' },
        { id: 2, name: 'Contrat Couleur – Pro' },
        { id: 3, name: 'Contrat Mixte – Annuel' }
    ],
    factures: [
        {
            id: 1,
            numero: '#2025-001',
            date: '2025-01-15',
            periode: { debut: '2025-01-01', fin: '2025-01-31' },
            type: 'Consommation',
            montantHT: 704.33,
            tva: 140.87,
            montantTTC: 845.20,
            statut: 'brouillon',
            client: { nom: 'Entreprise ABC', adresse: '123 Rue Example', email: 'contact@entreprise-abc.fr' },
            lignes: [
                { description: 'Pages N&B', type: 'N&B', quantite: 8450, prixUnitaire: 0.05, total: 422.50 },
                { description: 'Pages Couleur', type: 'Couleur', quantite: 2100, prixUnitaire: 0.15, total: 315.00 },
                { description: 'Maintenance', type: 'Service', quantite: 1, prixUnitaire: 107.70, total: 107.70 }
            ]
        },
        {
            id: 2,
            numero: '#2024-125',
            date: '2024-12-10',
            periode: { debut: '2024-12-01', fin: '2024-12-31' },
            type: 'Consommation',
            montantHT: 933.75,
            tva: 186.75,
            montantTTC: 1120.50,
            statut: 'envoyee',
            client: { nom: 'Société XYZ', adresse: '456 Avenue Test', email: 'comptabilite@societe-xyz.fr' },
            lignes: [
                { description: 'Pages N&B', type: 'N&B', quantite: 12000, prixUnitaire: 0.05, total: 600.00 },
                { description: 'Pages Couleur', type: 'Couleur', quantite: 3000, prixUnitaire: 0.15, total: 450.00 },
                { description: 'Maintenance', type: 'Service', quantite: 1, prixUnitaire: 70.50, total: 70.50 }
            ]
        },
        {
            id: 3,
            numero: '#2024-124',
            date: '2024-12-05',
            periode: null,
            type: 'Achat',
            montantHT: 375.00,
            tva: 75.00,
            montantTTC: 450.00,
            statut: 'payee',
            client: { nom: 'Compagnie DEF', adresse: '789 Boulevard Demo', email: 'direction@compagnie-def.fr' },
            lignes: [
                { description: 'Imprimante HP LaserJet', type: 'Produit', quantite: 1, prixUnitaire: 375.00, total: 375.00 }
            ]
        },
        {
            id: 4,
            numero: '#2024-123',
            date: '2024-11-20',
            periode: { debut: '2024-11-01', fin: '2024-11-30' },
            type: 'Consommation',
            montantHT: 816.92,
            tva: 163.38,
            montantTTC: 980.30,
            statut: 'en_retard',
            client: { nom: 'Groupe GHI', adresse: '321 Rue Sample', email: 'compta@groupe-ghi.fr' },
            lignes: [
                { description: 'Pages N&B', type: 'N&B', quantite: 10000, prixUnitaire: 0.05, total: 500.00 },
                { description: 'Pages Couleur', type: 'Couleur', quantite: 2000, prixUnitaire: 0.15, total: 300.00 },
                { description: 'Maintenance', type: 'Service', quantite: 1, prixUnitaire: 180.30, total: 180.30 }
            ]
        },
        {
            id: 5,
            numero: '#2024-122',
            date: '2024-11-15',
            periode: null,
            type: 'Service',
            montantHT: 266.67,
            tva: 53.33,
            montantTTC: 320.00,
            statut: 'payee',
            client: { nom: 'Entreprise ABC', adresse: '123 Rue Example', email: 'contact@entreprise-abc.fr' },
            lignes: [
                { description: 'Intervention technique', type: 'Service', quantite: 2, prixUnitaire: 133.33, total: 266.67 }
            ]
        }
    ],
    paiements: [
        { id: 1, factureId: 1, date: '2025-01-15', montant: 250.00, mode: 'Virement', reference: 'VIR-2025-001', commentaire: 'Paiement partiel' },
        { id: 2, factureId: 1, date: '2025-01-10', montant: 175.30, mode: 'Carte bancaire', reference: 'CB-2025-045' },
        { id: 3, factureId: 2, date: '2024-12-20', montant: 1120.50, mode: 'Virement', reference: 'VIR-2024-089' },
        { id: 4, factureId: 3, date: '2024-12-08', montant: 450.00, mode: 'Chèque', reference: 'CHQ-2024-123' },
        { id: 5, factureId: 5, date: '2024-11-20', montant: 320.00, mode: 'Carte bancaire', reference: 'CB-2024-234' }
    ],
    // Mock data pour la consommation (150+ clients avec N&B et Couleur)
    consommation: {
        // Génération de 150+ clients mock
        clients: (() => {
            const clients = [];
            const prefixes = ['ACME', 'Beta', 'CC', 'Delta', 'Echo', 'Fusion', 'Gamma', 'Hyper', 'Innov', 'Jupiter', 'Kappa', 'Lambda', 'Matrix', 'Nova', 'Omega', 'Prime', 'Quantum', 'Rapid', 'Sigma', 'Titan', 'Ultra', 'Vector', 'Wave', 'Xeno', 'Ypsilon', 'Zenith'];
            const suffixes = ['SARL', 'Industries', 'Services', 'Solutions', 'Technologies', 'Group', 'Corp', 'Ltd', 'SA', 'GmbH'];
            const prenoms = ['Jean', 'Marie', 'Pierre', 'Sophie', 'Luc', 'Anne', 'Paul', 'Julie', 'Marc', 'Claire', 'Thomas', 'Laura', 'David', 'Emma', 'Nicolas', 'Sarah'];
            const noms = ['Dupont', 'Martin', 'Bernard', 'Dubois', 'Lefebvre', 'Moreau', 'Laurent', 'Simon', 'Michel', 'Garcia', 'Petit', 'Roux', 'Vincent', 'Fournier', 'Leroy', 'Lambert'];
            
            for (let i = 1; i <= 150; i++) {
                const prefix = prefixes[Math.floor(Math.random() * prefixes.length)];
                const suffix = suffixes[Math.floor(Math.random() * suffixes.length)];
                const num = Math.floor(Math.random() * 999) + 1;
                const prenom = prenoms[Math.floor(Math.random() * prenoms.length)];
                const nom = noms[Math.floor(Math.random() * noms.length)];
                const raisonSociale = `${prefix} ${suffix} ${num}`;
                const reference = `CLI-${String(i).padStart(4, '0')}`;
                
                clients.push({
                    id: String(i),
                    name: raisonSociale,
                    prenom: prenom,
                    nom: nom,
                    raisonSociale: raisonSociale,
                    reference: reference,
                    // Texte de recherche combiné pour faciliter la recherche
                    searchText: `${prenom} ${nom} ${raisonSociale} ${reference}`.toLowerCase()
                });
            }
            return clients;
        })(),
        // Fonction pour générer des données de consommation pour un client
        generateClientData: function(clientId, granularityType, periodParams) {
            const baseNb = 3000 + (parseInt(clientId) * 50);
            const baseColor = 500 + (parseInt(clientId) * 20);
            const variation = 0.15;
            
            let labels, nbData, colorData;
            
            if (granularityType === 'year') {
                // Afficher les 12 mois de l'année sélectionnée
                const year = periodParams.year || 2025;
                const monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
                labels = monthNames.map(m => `${m} ${year}`);
                nbData = labels.map(() => Math.floor(baseNb * (1 + (Math.random() - 0.5) * variation)));
                colorData = labels.map(() => Math.floor(baseColor * (1 + (Math.random() - 0.5) * variation)));
            } else if (granularityType === 'month') {
                // Afficher les jours du mois sélectionné
                const year = periodParams.year || 2025;
                const month = periodParams.month !== undefined ? parseInt(periodParams.month) : new Date().getMonth();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                labels = Array.from({length: daysInMonth}, (_, i) => (i + 1).toString());
                nbData = labels.map(() => Math.floor((baseNb / daysInMonth) * (1 + (Math.random() - 0.5) * variation)));
                colorData = labels.map(() => Math.floor((baseColor / daysInMonth) * (1 + (Math.random() - 0.5) * variation)));
            }
            
            return { labels, nbData, colorData };
        },
        
        // Fonction pour obtenir les données agrégées (tous les clients)
        getAggregatedData: function(granularityType, periodParams) {
            const allClients = this.clients;
            
            // Générer les labels et initialiser les tableaux de données
            let labels;
            if (granularityType === 'year') {
                const year = periodParams.year || 2025;
                const monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
                labels = monthNames.map(m => `${m} ${year}`);
            } else if (granularityType === 'month') {
                const year = periodParams.year || 2025;
                const month = periodParams.month !== undefined ? parseInt(periodParams.month) : new Date().getMonth();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                labels = Array.from({length: daysInMonth}, (_, i) => (i + 1).toString());
            }
            
            const nbData = labels.map(() => 0);
            const colorData = labels.map(() => 0);
            
            // Agréger les données de tous les clients
            allClients.forEach(client => {
                const clientData = this.generateClientData(client.id, granularityType, periodParams);
                clientData.nbData.forEach((val, i) => nbData[i] += val);
                clientData.colorData.forEach((val, i) => colorData[i] += val);
            });
            
            // Calculer le total (N&B + Couleur)
            const totalData = labels.map((_, i) => nbData[i] + colorData[i]);
            
            return { labels, nbData, colorData, totalData };
        },
        
        // Fonction pour obtenir les données d'un ou plusieurs clients
        getClientsData: function(clientIds, granularityType, periodParams) {
            if (!clientIds || clientIds.length === 0) {
                return this.getAggregatedData(granularityType, periodParams);
            }
            
            // Générer les labels et initialiser les tableaux de données
            let labels;
            if (granularityType === 'year') {
                const year = periodParams.year || 2025;
                const monthNames = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
                labels = monthNames.map(m => `${m} ${year}`);
            } else if (granularityType === 'month') {
                const year = periodParams.year || 2025;
                const month = periodParams.month !== undefined ? parseInt(periodParams.month) : new Date().getMonth();
                const daysInMonth = new Date(year, month + 1, 0).getDate();
                labels = Array.from({length: daysInMonth}, (_, i) => (i + 1).toString());
            }
            
            const nbData = labels.map(() => 0);
            const colorData = labels.map(() => 0);
            
            clientIds.forEach(clientId => {
                const clientData = this.generateClientData(clientId, granularityType, periodParams);
                clientData.nbData.forEach((val, i) => nbData[i] += val);
                clientData.colorData.forEach((val, i) => colorData[i] += val);
            });
            
            // Calculer le total (N&B + Couleur)
            const totalData = labels.map((_, i) => nbData[i] + colorData[i]);
            
            return { labels, nbData, colorData, totalData };
        }
    }
};

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
function performClientSearch(query, dropdown) {
    const queryLower = query.toLowerCase();
    
    const filtered = mockData.consommation.clients.filter(client =>
        client.searchText.includes(queryLower)
    ).slice(0, 10); // Limiter à 10 résultats
    
    dropdown.innerHTML = '';
    
    if (filtered.length === 0) {
        dropdown.innerHTML = '<div class="dropdown-item empty-state">Aucun client trouvé</div>';
    } else {
        filtered.forEach(client => {
            const item = document.createElement('div');
            item.className = 'dropdown-item';
            
            // Mettre en évidence les correspondances
            const name = highlightMatch(client.raisonSociale, query);
            const details = `${client.prenom} ${client.nom} • ${client.reference}`;
            
            item.innerHTML = `
                <div class="dropdown-item-main">${name}</div>
                <div class="dropdown-item-sub">${details}</div>
            `;
            
            item.addEventListener('click', () => {
                selectClient(client.id, client.raisonSociale);
                document.getElementById('clientSearchInput').value = '';
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
    updateResumeKPIs();
    updateFactureEnCours();
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
function initConsumptionChart() {
    const ctx = document.getElementById('consumptionChart');
    if (!ctx) return;
    
    const granularityType = document.getElementById('chartGranularity').value || 'month';
    const periodParams = getPeriodParams();
    const isAllClients = selectedClientId === null;
    
    // Obtenir les données
    let chartData;
    if (isAllClients) {
        chartData = mockData.consommation.getAggregatedData(granularityType, periodParams);
    } else {
        chartData = mockData.consommation.getClientsData([selectedClientId], granularityType, periodParams);
    }
    
    // Vérifier si toutes les données sont à zéro (aucun relevé)
    const hasData = chartData.nbData.some(val => val > 0) || chartData.colorData.some(val => val > 0);
    const noDataMessage = document.getElementById('chartNoDataMessage');
    const chartContainer = document.querySelector('.chart-container');
    
    // Afficher le message si aucune donnée, mais toujours afficher le graphique (avec valeurs à zéro)
    if (noDataMessage) {
        noDataMessage.style.display = hasData ? 'none' : 'block';
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
    
    // Initialiser le calcul du montant non payé
    updateResumeKPIs();
    
    // Initialiser la facture en cours
    updateFactureEnCours();
    
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
    });
});

// ==================
// Mise à jour du résumé (mock)
// ==================
function updateResumeKPIs() {
    // Récupérer les valeurs des KPI
    const totalFacturerEl = document.getElementById('kpiTotalFacturer');
    const montantPayeEl = document.getElementById('kpiMontantPaye');
    const montantNonPayeEl = document.getElementById('kpiMontantNonPaye');
    
    if (!totalFacturerEl || !montantPayeEl || !montantNonPayeEl) return;
    
    // Extraire les valeurs numériques (enlever les espaces, €, etc.)
    // Format attendu: "1 245,30 €" -> 1245.30
    const parseFrenchNumber = (text) => {
        return parseFloat(text.replace(/\s/g, '').replace(',', '.').replace(/[^\d.]/g, ''));
    };
    
    const totalFacturer = parseFrenchNumber(totalFacturerEl.textContent);
    const montantPaye = parseFrenchNumber(montantPayeEl.textContent);
    
    // Calculer le montant non payé
    const montantNonPaye = totalFacturer - montantPaye;
    
    // Formater et afficher le montant non payé (format français avec espaces)
    const formatted = montantNonPaye.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
    montantNonPayeEl.textContent = formatted + ' €';
}

// ==================
// Mise à jour de la facture en cours
// ==================
function updateFactureEnCours() {
    const now = new Date();
    const currentDay = now.getDate();
    const currentMonth = now.getMonth();
    const currentYear = now.getFullYear();
    
    // Calculer la date de début : toujours le 20 du mois précédent
    const startDate = new Date(currentYear, currentMonth - 1, 20);
    
    // Date de fin : aujourd'hui
    const endDate = new Date(currentYear, currentMonth, currentDay);
    
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
        periodEl.textContent = `Période : ${formatDate(startDate)} - ${formatDate(endDate)}`;
    }
    
    // Générer le numéro de facture (mock)
    const factureNumEl = document.getElementById('factureNum');
    if (factureNumEl) {
        const monthStr = String(currentMonth + 1).padStart(2, '0');
        factureNumEl.textContent = `Facture #${currentYear}-${monthStr}${String(currentDay).padStart(2, '0')}`;
    }
    
    // Calculer le montant collecté (mock - somme des paiements de la facture en cours)
    const montantCollecteEl = document.getElementById('factureMontantCollecte');
    if (montantCollecteEl) {
        // Mock : montant collecté (peut être calculé depuis les paiements réels plus tard)
        const montantCollecte = 425.30; // Mock value
        const formatted = montantCollecte.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        montantCollecteEl.textContent = formatted + ' €';
    }
    
    // Gérer l'activation/désactivation des boutons selon le jour du mois
    const btnGenererPDF = document.getElementById('btnGenererPDF');
    const btnEnvoyerClient = document.getElementById('btnEnvoyerClient');
    const restrictionMessage = document.getElementById('factureRestrictionMessage');
    
    const isDay20 = currentDay === 20;
    
    if (btnGenererPDF && btnEnvoyerClient && restrictionMessage) {
        if (isDay20) {
            // Activer les boutons
            btnGenererPDF.disabled = false;
            btnEnvoyerClient.disabled = false;
            btnGenererPDF.style.opacity = '1';
            btnEnvoyerClient.style.opacity = '1';
            btnGenererPDF.style.cursor = 'pointer';
            btnEnvoyerClient.style.cursor = 'pointer';
            restrictionMessage.style.display = 'none';
        } else {
            // Désactiver les boutons
            btnGenererPDF.disabled = true;
            btnEnvoyerClient.disabled = true;
            btnGenererPDF.style.opacity = '0.5';
            btnEnvoyerClient.style.opacity = '0.5';
            btnGenererPDF.style.cursor = 'not-allowed';
            btnEnvoyerClient.style.cursor = 'not-allowed';
            restrictionMessage.style.display = 'block';
        }
    }
}

// ==================
// Gestion des factures
// ==================
const factureRows = document.querySelectorAll('.facture-row');
factureRows.forEach(row => {
    row.addEventListener('click', () => {
        const factureId = parseInt(row.dataset.factureId);
        const facture = mockData.factures.find(f => f.id === factureId);
        if (facture) {
            displayFactureDetail(facture);
        }
    });
});

function displayFactureDetail(facture) {
    const detailPanel = document.getElementById('factureDetail');
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
    if (facture.periode) {
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
                    <button type="button" class="btn-secondary" onclick="alert('Modifier')">Modifier</button>
                    <button type="button" class="btn-secondary" onclick="alert('Générer PDF')">Générer PDF</button>
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
}

// ==================
// Gestion des paiements
// ==================
document.getElementById('formAddPayment').addEventListener('submit', (e) => {
    e.preventDefault();
    
    const amount = parseFloat(document.getElementById('paymentAmount').value);
    const date = document.getElementById('paymentDate').value;
    const mode = document.getElementById('paymentMode').value;
    const ref = document.getElementById('paymentRef').value;
    const comment = document.getElementById('paymentComment').value;
    const sendReceipt = document.getElementById('paymentSendReceipt').checked;
    
    // Simuler l'ajout du paiement (mock)
    console.log('Paiement ajouté:', { amount, date, mode, ref, comment, sendReceipt });
    
    // Mettre à jour la liste des paiements (mock)
    const paiementsList = document.querySelector('.paiements-list');
    const newPayment = document.createElement('div');
    newPayment.className = 'paiement-item';
    newPayment.innerHTML = `
        <div class="paiement-date">${new Date(date).toLocaleDateString('fr-FR')}</div>
        <div class="paiement-amount">${amount.toFixed(2)} €</div>
        <div class="paiement-mode">${mode}</div>
    `;
    paiementsList.insertBefore(newPayment, paiementsList.firstChild);
    
    // Réinitialiser le formulaire
    document.getElementById('formAddPayment').reset();
    document.getElementById('paymentDate').value = new Date().toISOString().split('T')[0];
    
    alert('Paiement enregistré (simulation)');
});

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
