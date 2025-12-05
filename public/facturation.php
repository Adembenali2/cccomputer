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

    <!-- Bandeau filtres -->
    <section class="filters-bar">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="filterClient">Client</label>
                <select id="filterClient" class="filter-select">
                    <option value="">Tous les clients</option>
                    <option value="1">Entreprise ABC</option>
                    <option value="2">Société XYZ</option>
                    <option value="3">Compagnie DEF</option>
                    <option value="4">Groupe GHI</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterContrat">Contrat</label>
                <select id="filterContrat" class="filter-select">
                    <option value="">Tous les contrats</option>
                    <option value="1">Contrat Copie N&B – Mensuel</option>
                    <option value="2">Contrat Couleur – Pro</option>
                    <option value="3">Contrat Mixte – Annuel</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="filterDateDebut">Période</label>
                <div class="date-range">
                    <input type="date" id="filterDateDebut" class="filter-date" value="<?= date('Y-m-01') ?>">
                    <span>au</span>
                    <input type="date" id="filterDateFin" class="filter-date" value="<?= date('Y-m-t') ?>">
                </div>
            </div>
            <div class="filter-group filter-quick">
                <label>Raccourcis</label>
                <div class="quick-filters">
                    <button type="button" class="quick-filter-btn" data-period="month">Ce mois-ci</button>
                    <button type="button" class="quick-filter-btn" data-period="last-month">Dernier mois</button>
                </div>
            </div>
            <div class="filter-group filter-apply">
                <button type="button" class="btn-primary" id="btnApplyFilters">Appliquer</button>
            </div>
        </div>
        <div class="filters-summary" id="filtersSummary">
            <span class="summary-item">Conso N&B : <strong>12 430 pages</strong></span>
            <span class="summary-item">Couleur : <strong>3 210 pages</strong></span>
            <span class="summary-item">Montant estimé : <strong>845,20 €</strong></span>
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
                        <label for="chartClient">Client</label>
                        <select id="chartClient" class="filter-select chart-select">
                            <option value="">Tous les clients</option>
                            <option value="1">Client A – ACME SARL</option>
                            <option value="2">Client B – Beta Industries</option>
                            <option value="3">Client C – CC Services</option>
                        </select>
                    </div>
                    <div class="chart-control-group">
                        <label for="chartGranularity">Granularité</label>
                        <select id="chartGranularity" class="filter-select chart-select">
                            <option value="year">Année</option>
                            <option value="month" selected>Mois</option>
                            <option value="day">Jour</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="consumptionChart"></canvas>
                </div>
            </div>
        </div>
    </section>

    <!-- Système d'onglets -->
    <section class="tabs-section">
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
                    <div class="kpi-value">1 245,30 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Factures en attente</div>
                    <div class="kpi-value">3 factures – 820,00 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Montant payé</div>
                    <div class="kpi-value">425,30 €</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Conso pages</div>
                    <div class="kpi-value">N&B : 10 200 | Couleur : 2 100</div>
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
                                <div class="facture-num">Facture #2025-001</div>
                                <div class="facture-status">
                                    <span class="badge badge-draft">Brouillon</span>
                                </div>
                                <div class="facture-amount">Montant TTC : <strong>845,20 €</strong></div>
                                <div class="facture-period">Période : 01/01/2025 - 31/01/2025</div>
                            </div>
                            <div class="facture-actions">
                                <button type="button" class="btn-secondary" onclick="alert('Ouvrir la facture')">Ouvrir la facture</button>
                                <button type="button" class="btn-secondary" onclick="alert('Générer PDF')">Générer PDF</button>
                                <button type="button" class="btn-primary" onclick="alert('Envoyer au client')">Envoyer au client</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="card-header">
                        <h3>Derniers paiements</h3>
                        <button type="button" class="btn-icon" id="btnAddPayment" aria-label="Ajouter un paiement">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"/>
                                <line x1="5" y1="12" x2="19" y2="12"/>
                            </svg>
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="paiements-list">
                            <div class="paiement-item">
                                <div class="paiement-date">15/01/2025</div>
                                <div class="paiement-amount">250,00 €</div>
                                <div class="paiement-mode">Virement</div>
                            </div>
                            <div class="paiement-item">
                                <div class="paiement-date">10/01/2025</div>
                                <div class="paiement-amount">175,30 €</div>
                                <div class="paiement-mode">Carte bancaire</div>
                            </div>
                            <div class="paiement-item">
                                <div class="paiement-date">05/01/2025</div>
                                <div class="paiement-amount">100,00 €</div>
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
    // Mock data pour la consommation
    consommation: {
        clients: [
            { id: '', name: 'Tous les clients' },
            { id: '1', name: 'Client A – ACME SARL' },
            { id: '2', name: 'Client B – Beta Industries' },
            { id: '3', name: 'Client C – CC Services' }
        ],
        byYear: {
            '': {
                labels: ['2022', '2023', '2024', '2025'],
                datasets: [
                    {
                        label: 'Client A – ACME SARL',
                        data: [45000, 52000, 58000, 12000],
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client B – Beta Industries',
                        data: [38000, 42000, 48000, 10000],
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client C – CC Services',
                        data: [32000, 35000, 40000, 8500],
                        backgroundColor: 'rgba(139, 92, 246, 0.7)',
                        borderColor: 'rgb(139, 92, 246)',
                        borderWidth: 2
                    }
                ]
            },
            '1': {
                labels: ['2022', '2023', '2024', '2025'],
                data: [45000, 52000, 58000, 12000],
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 2
            },
            '2': {
                labels: ['2022', '2023', '2024', '2025'],
                data: [38000, 42000, 48000, 10000],
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderColor: 'rgb(16, 185, 129)',
                borderWidth: 2
            },
            '3': {
                labels: ['2022', '2023', '2024', '2025'],
                data: [32000, 35000, 40000, 8500],
                backgroundColor: 'rgba(139, 92, 246, 0.7)',
                borderColor: 'rgb(139, 92, 246)',
                borderWidth: 2
            }
        },
        byMonth: {
            '': {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                datasets: [
                    {
                        label: 'Client A – ACME SARL',
                        data: [4200, 4500, 4800, 5100, 4900, 5200, 5000, 4800, 5100, 5300, 5000, 12000],
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client B – Beta Industries',
                        data: [3500, 3800, 4000, 4200, 4100, 4300, 4200, 4000, 4200, 4400, 4200, 10000],
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client C – CC Services',
                        data: [3000, 3200, 3400, 3600, 3500, 3700, 3600, 3400, 3600, 3800, 3600, 8500],
                        backgroundColor: 'rgba(139, 92, 246, 0.7)',
                        borderColor: 'rgb(139, 92, 246)',
                        borderWidth: 2
                    }
                ]
            },
            '1': {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                data: [4200, 4500, 4800, 5100, 4900, 5200, 5000, 4800, 5100, 5300, 5000, 12000],
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 2
            },
            '2': {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                data: [3500, 3800, 4000, 4200, 4100, 4300, 4200, 4000, 4200, 4400, 4200, 10000],
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderColor: 'rgb(16, 185, 129)',
                borderWidth: 2
            },
            '3': {
                labels: ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'],
                data: [3000, 3200, 3400, 3600, 3500, 3700, 3600, 3400, 3600, 3800, 3600, 8500],
                backgroundColor: 'rgba(139, 92, 246, 0.7)',
                borderColor: 'rgb(139, 92, 246)',
                borderWidth: 2
            }
        },
        byDay: {
            '': {
                labels: Array.from({length: 30}, (_, i) => (i + 1).toString()),
                datasets: [
                    {
                        label: 'Client A – ACME SARL',
                        data: Array.from({length: 30}, () => Math.floor(Math.random() * 200) + 350),
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client B – Beta Industries',
                        data: Array.from({length: 30}, () => Math.floor(Math.random() * 150) + 280),
                        backgroundColor: 'rgba(16, 185, 129, 0.7)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 2
                    },
                    {
                        label: 'Client C – CC Services',
                        data: Array.from({length: 30}, () => Math.floor(Math.random() * 120) + 240),
                        backgroundColor: 'rgba(139, 92, 246, 0.7)',
                        borderColor: 'rgb(139, 92, 246)',
                        borderWidth: 2
                    }
                ]
            },
            '1': {
                labels: Array.from({length: 30}, (_, i) => (i + 1).toString()),
                data: Array.from({length: 30}, () => Math.floor(Math.random() * 200) + 350),
                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 2
            },
            '2': {
                labels: Array.from({length: 30}, (_, i) => (i + 1).toString()),
                data: Array.from({length: 30}, () => Math.floor(Math.random() * 150) + 280),
                backgroundColor: 'rgba(16, 185, 129, 0.7)',
                borderColor: 'rgb(16, 185, 129)',
                borderWidth: 2
            },
            '3': {
                labels: Array.from({length: 30}, (_, i) => (i + 1).toString()),
                data: Array.from({length: 30}, () => Math.floor(Math.random() * 120) + 240),
                backgroundColor: 'rgba(139, 92, 246, 0.7)',
                borderColor: 'rgb(139, 92, 246)',
                borderWidth: 2
            }
        }
    }
};

// ==================
// Graphique de consommation
// ==================
let consumptionChart = null;

function initConsumptionChart() {
    const ctx = document.getElementById('consumptionChart');
    if (!ctx) return;
    
    const clientId = document.getElementById('chartClient').value || '';
    const granularity = document.getElementById('chartGranularity').value || 'month';
    
    const dataKey = `by${granularity.charAt(0).toUpperCase() + granularity.slice(1)}`;
    const chartData = mockData.consommation[dataKey][clientId];
    
    if (!chartData) return;
    
    const config = {
        type: clientId === '' ? 'bar' : 'line',
        data: clientId === '' 
            ? {
                labels: chartData.labels,
                datasets: chartData.datasets
            }
            : {
                labels: chartData.labels,
                datasets: [{
                    label: mockData.consommation.clients.find(c => c.id === clientId)?.name || 'Consommation',
                    data: chartData.data,
                    backgroundColor: chartData.backgroundColor,
                    borderColor: chartData.borderColor,
                    borderWidth: chartData.borderWidth,
                    fill: true,
                    tension: 0.4
                }]
            },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            size: 12
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
                            return context.dataset.label + ': ' + context.parsed.y.toLocaleString('fr-FR') + ' pages';
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
                        }
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    ticks: {
                        font: {
                            family: "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
                            size: 11
                        }
                    },
                    grid: {
                        display: false
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

// Initialisation du graphe au chargement
document.addEventListener('DOMContentLoaded', () => {
    initConsumptionChart();
    
    // Écouter les changements de client et granularité
    document.getElementById('chartClient').addEventListener('change', updateConsumptionChart);
    document.getElementById('chartGranularity').addEventListener('change', updateConsumptionChart);
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
// Gestion des filtres
// ==================
document.getElementById('btnApplyFilters').addEventListener('click', () => {
    const client = document.getElementById('filterClient').value;
    const contrat = document.getElementById('filterContrat').value;
    const dateDebut = document.getElementById('filterDateDebut').value;
    const dateFin = document.getElementById('filterDateFin').value;
    
    // Simuler la mise à jour des données (mock)
    console.log('Filtres appliqués:', { client, contrat, dateDebut, dateFin });
    
    // Mettre à jour le résumé (mock)
    updateFiltersSummary();
});

// Quick filters
document.querySelectorAll('.quick-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const period = btn.dataset.period;
        const today = new Date();
        let dateDebut, dateFin;
        
        if (period === 'month') {
            dateDebut = new Date(today.getFullYear(), today.getMonth(), 1);
            dateFin = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        } else if (period === 'last-month') {
            dateDebut = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            dateFin = new Date(today.getFullYear(), today.getMonth(), 0);
        }
        
        document.getElementById('filterDateDebut').value = dateDebut.toISOString().split('T')[0];
        document.getElementById('filterDateFin').value = dateFin.toISOString().split('T')[0];
    });
});

function updateFiltersSummary() {
    // Mock data pour le résumé
    const summary = document.getElementById('filtersSummary');
    summary.innerHTML = `
        <span class="summary-item">Conso N&B : <strong>12 430 pages</strong></span>
        <span class="summary-item">Couleur : <strong>3 210 pages</strong></span>
        <span class="summary-item">Montant estimé : <strong>845,20 €</strong></span>
    `;
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
// Export Excel (mock)
// ==================
document.getElementById('btnExportExcel').addEventListener('click', () => {
    alert('Export Excel (fonctionnalité à implémenter)');
});

// ==================
// Modal paiement
// ==================
const btnAddPayment = document.getElementById('btnAddPayment');
const modalAddPayment = document.getElementById('modalAddPayment');
const modalClose = document.querySelector('.modal-close');

if (btnAddPayment && modalAddPayment) {
    btnAddPayment.addEventListener('click', () => {
        modalAddPayment.style.display = 'flex';
    });
}

if (modalClose) {
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
</script>
</body>
</html>

