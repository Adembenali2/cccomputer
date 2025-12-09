<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiements & Facturation - CC Computer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <link rel="stylesheet" href="/assets/css/paiements.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="page-paiements">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="paiements-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Paiements & Facturation</h2>
            <div class="paiements-header-controls">
                <select id="clientFilter">
                    <option value="">Tous les clients</option>
                    <option value="1">Client A</option>
                    <option value="2">Client B</option>
                    <option value="3">Client C</option>
                </select>
                <select id="periodFilter">
                    <option value="month">Ce mois-ci</option>
                    <option value="year">Cette année</option>
                    <option value="custom">Personnalisée</option>
                </select>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="paiements-header">
            <div class="paiements-kpi-row">
                <div class="paiements-kpi-card kpi-dettes">
                    <div class="paiements-kpi-title">Dettes</div>
                    <div class="paiements-kpi-amount">12 430,50 €</div>
                    <div class="paiements-kpi-subtitle">Montant total restant dû</div>
                </div>
                <div class="paiements-kpi-card kpi-paye">
                    <div class="paiements-kpi-title">Payé</div>
                    <div class="paiements-kpi-amount">45 230,00 €</div>
                    <div class="paiements-kpi-subtitle">Total encaissé sur la période</div>
                </div>
                <div class="paiements-kpi-card kpi-a-payer">
                    <div class="paiements-kpi-title">À payer</div>
                    <div class="paiements-kpi-amount">8 750,25 €</div>
                    <div class="paiements-kpi-subtitle">Factures échues ou à échéance</div>
                </div>
            </div>
        </div>

        <!-- Barre d'onglets -->
        <div class="paiements-tabs-container">
            <div class="paiements-tabs">
                <button class="paiements-tab active" data-tab="consommation">Consommation</button>
                <button class="paiements-tab" data-tab="factures">Factures</button>
                <button class="paiements-tab" data-tab="generation">Génération de facture</button>
                <button class="paiements-tab" data-tab="historique">Historique paiements</button>
                <button class="paiements-tab" data-tab="paiement">Effectuer un paiement</button>
            </div>
        </div>

        <!-- Zone de contenu -->
        <div class="paiements-content-area">
            <!-- Onglet 1: Consommation -->
            <div id="consommation" class="paiements-tab-content active">
                <div class="paiements-filters-bar">
                    <div class="paiements-filter-group paiements-client-search-group">
                        <label>Rechercher un client</label>
                        <input type="text" id="clientSearchInput" placeholder="Rechercher un client..." autocomplete="off">
                        <div id="clientSuggestions" class="client-suggestions"></div>
                    </div>
                    <div class="paiements-filter-group">
                        <label>Mois</label>
                        <select id="monthSelect">
                            <option value="01">Janvier</option>
                            <option value="02">Février</option>
                            <option value="03">Mars</option>
                            <option value="04">Avril</option>
                            <option value="05">Mai</option>
                            <option value="06">Juin</option>
                            <option value="07">Juillet</option>
                            <option value="08">Août</option>
                            <option value="09">Septembre</option>
                            <option value="10">Octobre</option>
                            <option value="11">Novembre</option>
                            <option value="12">Décembre</option>
                        </select>
                    </div>
                    <div class="paiements-filter-group">
                        <label>Année</label>
                        <select id="yearSelect">
                            <option value="2023">2023</option>
                            <option value="2024">2024</option>
                            <option value="2025" selected>2025</option>
                        </select>
                    </div>
                    <button class="btn-secondary" id="exportCsvBtn">Exporter en CSV</button>
                </div>

                <div class="paiements-chart-container">
                    <div class="paiements-chart-title">
                        <span id="chartTitle">Consommation par mois</span>
                        <span id="chartSubtitle" class="paiements-chart-subtitle"></span>
                    </div>
                    <div class="paiements-chart-wrapper">
                        <canvas id="consumptionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Onglet 2: Factures -->
            <div id="factures" class="paiements-tab-content">
                <div class="paiements-filters-bar">
                    <div class="paiements-filter-group">
                        <label>Client</label>
                        <select>
                            <option>Tous</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="paiements-filter-group">
                        <label>Du</label>
                        <input type="date" value="2025-01-01">
                    </div>
                    <div class="paiements-filter-group">
                        <label>Au</label>
                        <input type="date" value="2025-12-31">
                    </div>
                    <div class="paiements-filter-group">
                        <label>Statut</label>
                        <select>
                            <option>Tous</option>
                            <option>Payées</option>
                            <option>Non payées</option>
                            <option>En retard</option>
                        </select>
                    </div>
                    <button class="btn-primary">Filtrer</button>
                </div>

                <div class="paiements-table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>N° facture</th>
                                <th>Client</th>
                                <th>Période</th>
                                <th>Date de facture</th>
                                <th>Montant</th>
                                <th>Statut</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>FAC-2025-001</td>
                                <td>Client A</td>
                                <td>01/11/2025 - 30/11/2025</td>
                                <td>05/12/2025</td>
                                <td>1 250,00 €</td>
                                <td><span class="badge badge-success">Payée</span></td>
                                <td>
                                    <div class="paiements-action-buttons">
                                        <button class="btn-icon" onclick="downloadInvoice('FAC-2025-001')">📥 PDF</button>
                                        <button class="btn-icon" onclick="openSendMailModal('FAC-2025-001', 'Client A')">✉️ Mail</button>
                                        <button class="btn-icon" onclick="viewInvoiceDetails('FAC-2025-001')">👁️</button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>FAC-2025-002</td>
                                <td>Client B</td>
                                <td>01/11/2025 - 30/11/2025</td>
                                <td>05/12/2025</td>
                                <td>2 450,50 €</td>
                                <td><span class="badge badge-warning">Non payée</span></td>
                                <td>
                                    <div class="paiements-action-buttons">
                                        <button class="btn-icon" onclick="downloadInvoice('FAC-2025-002')">📥 PDF</button>
                                        <button class="btn-icon" onclick="openSendMailModal('FAC-2025-002', 'Client B')">✉️ Mail</button>
                                        <button class="btn-icon" onclick="viewInvoiceDetails('FAC-2025-002')">👁️</button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>FAC-2025-003</td>
                                <td>Client C</td>
                                <td>01/10/2025 - 31/10/2025</td>
                                <td>05/11/2025</td>
                                <td>890,75 €</td>
                                <td><span class="badge badge-danger">En retard</span></td>
                                <td>
                                    <div class="paiements-action-buttons">
                                        <button class="btn-icon" onclick="downloadInvoice('FAC-2025-003')">📥 PDF</button>
                                        <button class="btn-icon" onclick="openSendMailModal('FAC-2025-003', 'Client C')">✉️ Mail</button>
                                        <button class="btn-icon" onclick="viewInvoiceDetails('FAC-2025-003')">👁️</button>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td>FAC-2025-004</td>
                                <td>Client A</td>
                                <td>01/10/2025 - 31/10/2025</td>
                                <td>05/11/2025</td>
                                <td>1 680,00 €</td>
                                <td><span class="badge badge-success">Payée</span></td>
                                <td>
                                    <div class="paiements-action-buttons">
                                        <button class="btn-icon" onclick="downloadInvoice('FAC-2025-004')">📥 PDF</button>
                                        <button class="btn-icon" onclick="openSendMailModal('FAC-2025-004', 'Client A')">✉️ Mail</button>
                                        <button class="btn-icon" onclick="viewInvoiceDetails('FAC-2025-004')">👁️</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet 3: Génération de facture -->
            <div id="generation" class="paiements-tab-content">
                <div class="paiements-cards-grid">
                    <!-- Card Facture manuelle -->
                    <div class="paiements-card">
                        <div class="paiements-card-title">Facture manuelle</div>
                        <div class="paiements-card-description">
                            Créer une facture manuellement (lignes saisies à la main)
                        </div>
                        <button class="btn-primary" onclick="openManualInvoiceModal()">
                            Créer une facture manuelle
                        </button>
                    </div>

                    <!-- Card Facture automatique -->
                    <div class="paiements-card">
                        <div class="paiements-card-title">Facture automatique</div>
                        <div class="paiements-card-description">
                            Pré-générer des factures à partir de la consommation (compteurs)
                        </div>
                        <div class="paiements-form-grid paiements-card-actions">
                            <div class="paiements-form-group">
                                <label>Période</label>
                                <select id="autoPeriod">
                                    <option>Mois courant</option>
                                    <option>Mois précédent</option>
                                </select>
                            </div>
                            <div class="paiements-form-group">
                                <label>Client</label>
                                <select id="autoClient">
                                    <option>Tous les clients</option>
                                    <option>Client A</option>
                                    <option>Client B</option>
                                    <option>Client C</option>
                                </select>
                            </div>
                        </div>
                        <div class="paiements-card-actions">
                            <button class="btn-secondary" onclick="calculatePrefacturation()">
                                Calculer la pré-facturation
                            </button>
                            <button class="btn-primary" onclick="generateInvoices()">
                                Générer les factures
                            </button>
                        </div>
                        <div id="prefacturationTable" class="paiements-hidden">
                            <div class="paiements-table-container">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Client</th>
                                            <th>Pages N&B</th>
                                            <th>Pages couleur</th>
                                            <th>Montant estimé</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>Client A</td>
                                            <td>1 250</td>
                                            <td>350</td>
                                            <td>1 450,00 €</td>
                                        </tr>
                                        <tr>
                                            <td>Client B</td>
                                            <td>2 100</td>
                                            <td>580</td>
                                            <td>2 680,50 €</td>
                                        </tr>
                                        <tr>
                                            <td>Client C</td>
                                            <td>890</td>
                                            <td>120</td>
                                            <td>950,75 €</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Onglet 4: Historique paiements -->
            <div id="historique" class="paiements-tab-content">
                <div class="paiements-filters-bar">
                    <div class="paiements-filter-group">
                        <label>Client</label>
                        <select>
                            <option>Tous</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="paiements-filter-group">
                        <label>Du</label>
                        <input type="date" value="2025-01-01">
                    </div>
                    <div class="paiements-filter-group">
                        <label>Au</label>
                        <input type="date" value="2025-12-31">
                    </div>
                    <div class="paiements-filter-group">
                        <label>Mode de paiement</label>
                        <select>
                            <option>Tous</option>
                            <option>Espèces</option>
                            <option>Chèque</option>
                            <option>Virement</option>
                            <option>Carte</option>
                        </select>
                    </div>
                    <button class="btn-primary">Filtrer</button>
                </div>

                <div class="paiements-table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Client</th>
                                <th>Mode de paiement</th>
                                <th>Montant</th>
                                <th>Factures associées</th>
                                <th>Commentaire</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="clickable" onclick="viewPaymentDetails(1)">
                                <td>15/12/2025</td>
                                <td>Client A</td>
                                <td>Virement</td>
                                <td>1 250,00 €</td>
                                <td>FAC-2025-001</td>
                                <td>Paiement mensuel</td>
                            </tr>
                            <tr class="clickable" onclick="viewPaymentDetails(2)">
                                <td>10/12/2025</td>
                                <td>Client B</td>
                                <td>Chèque</td>
                                <td>2 450,50 €</td>
                                <td>FAC-2025-002</td>
                                <td>Chèque n°123456</td>
                            </tr>
                            <tr class="clickable" onclick="viewPaymentDetails(3)">
                                <td>05/12/2025</td>
                                <td>Client C</td>
                                <td>Espèces</td>
                                <td>890,75 €</td>
                                <td>FAC-2025-003</td>
                                <td>Règlement comptant</td>
                            </tr>
                            <tr class="clickable" onclick="viewPaymentDetails(4)">
                                <td>01/12/2025</td>
                                <td>Client A</td>
                                <td>Carte</td>
                                <td>1 680,00 €</td>
                                <td>FAC-2025-004</td>
                                <td>Paiement en ligne</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Onglet 5: Effectuer un paiement -->
            <div id="paiement" class="paiements-tab-content">
                <div class="paiements-two-columns">
                    <!-- Colonne gauche: Formulaire -->
                    <div class="paiements-card">
                        <div class="paiements-card-title">Enregistrer un paiement</div>
                        <form id="paymentForm" class="paiements-form-grid">
                            <div class="paiements-form-group">
                                <label>Client</label>
                                <select id="paymentClient" required>
                                    <option value="">Sélectionner un client</option>
                                    <option value="1">Client A</option>
                                    <option value="2">Client B</option>
                                    <option value="3">Client C</option>
                                </select>
                            </div>
                            <div class="paiements-form-group">
                                <label>Date du paiement</label>
                                <input type="date" id="paymentDate" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="paiements-form-group">
                                <label>Mode de paiement</label>
                                <select id="paymentMode" required>
                                    <option value="">Sélectionner</option>
                                    <option value="especes">Espèces</option>
                                    <option value="cheque">Chèque</option>
                                    <option value="virement">Virement</option>
                                    <option value="carte">Carte</option>
                                </select>
                            </div>
                            <div class="paiements-form-group">
                                <label>Montant reçu</label>
                                <input type="number" id="paymentAmount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                            <div class="paiements-form-group">
                                <label>Référence (chèque/virement)</label>
                                <input type="text" id="paymentRef" placeholder="N° de chèque ou référence virement">
                            </div>
                            <div class="paiements-form-group full-width">
                                <label>Commentaire</label>
                                <textarea id="paymentComment" placeholder="Commentaire optionnel"></textarea>
                            </div>
                            <div class="paiements-form-group full-width">
                                <button type="submit" class="btn-primary btn-full-width">
                                    Enregistrer le paiement
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Colonne droite: Factures du client -->
                    <div class="paiements-card">
                        <div class="paiements-card-title">Factures à payer</div>
                        <div id="clientInvoices">
                            <p class="paiements-hint">
                                Sélectionnez un client pour voir ses factures
                            </p>
                        </div>
                        <div class="paiements-card-actions">
                            <button class="btn-secondary btn-full-width" onclick="distributeAmount()">
                                Répartir automatiquement le montant sur les factures
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modale Envoi Mail -->
    <div id="sendMailModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Envoyer la facture par mail</h2>
                <button class="modal-close" onclick="closeSendMailModal()">&times;</button>
            </div>
            <form id="sendMailForm">
                <div class="paiements-form-group">
                    <label>Client</label>
                    <input type="text" id="mailClient" readonly>
                </div>
                <div class="paiements-form-group">
                    <label>N° de facture</label>
                    <input type="text" id="mailInvoice" readonly>
                </div>
                <div class="paiements-form-group">
                    <label>Email du client</label>
                    <input type="email" id="mailEmail" required placeholder="email@exemple.com">
                </div>
                <div class="paiements-form-group">
                    <label>Sujet</label>
                    <input type="text" id="mailSubject" required placeholder="Facture FAC-2025-001">
                </div>
                <div class="paiements-form-group">
                    <label>Message</label>
                    <textarea id="mailMessage" required placeholder="Votre message..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeSendMailModal()">Annuler</button>
                    <button type="submit" class="btn-primary">Envoyer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modale Facture Manuelle -->
    <div id="manualInvoiceModal" class="modal-overlay">
        <div class="modal modal-large">
            <div class="modal-header">
                <h2 class="modal-title">Créer une facture manuelle</h2>
                <button class="modal-close" onclick="closeManualInvoiceModal()">&times;</button>
            </div>
            <form id="manualInvoiceForm">
                <div class="paiements-form-grid">
                    <div class="paiements-form-group">
                        <label>Client</label>
                        <select id="invoiceClient" required>
                            <option value="">Sélectionner</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="paiements-form-group">
                        <label>Date de facture</label>
                        <input type="date" id="invoiceDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="paiements-form-group">
                        <label>Période du</label>
                        <input type="date" id="invoicePeriodFrom" required>
                    </div>
                    <div class="paiements-form-group">
                        <label>Période au</label>
                        <input type="date" id="invoicePeriodTo" required>
                    </div>
                </div>

                <div class="paiements-invoice-lines">
                    <div class="paiements-invoice-lines-header">
                        <h3 class="paiements-invoice-lines-title">Lignes de facture</h3>
                        <button type="button" class="btn-secondary" onclick="addInvoiceLine()">+ Ajouter une ligne</button>
                    </div>
                    <div id="invoiceLinesContainer">
                        <!-- Lignes seront ajoutées dynamiquement -->
                    </div>
                </div>

                <div class="paiements-invoice-totals">
                    <div class="paiements-total-line">
                        <span>Total HT:</span>
                        <span id="totalHT">0,00 €</span>
                    </div>
                    <div class="paiements-total-line">
                        <span>TVA:</span>
                        <span id="totalTVA">0,00 €</span>
                    </div>
                    <div class="paiements-total-line final">
                        <span>Total TTC:</span>
                        <span id="totalTTC">0,00 €</span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn-secondary" onclick="closeManualInvoiceModal()">Annuler</button>
                    <button type="submit" class="btn-primary">Enregistrer la facture</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modale Détails Paiement -->
    <div id="paymentDetailsModal" class="modal-overlay">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Détails du paiement</h2>
                <button class="modal-close" onclick="closePaymentDetailsModal()">&times;</button>
            </div>
            <div id="paymentDetailsContent">
                <!-- Contenu dynamique -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closePaymentDetailsModal()">Fermer</button>
            </div>
        </div>
    </div>

    <script>
        // Données fictives de clients
        const clientsData = [
            { raison_sociale: 'DUPONT SARL', nom: 'Dupont', prenom: 'Jean', id: 1 },
            { raison_sociale: 'Durand Services', nom: 'Durand', prenom: 'Marie', id: 2 },
            { raison_sociale: 'Martin & Fils', nom: 'Martin', prenom: 'Pierre', id: 3 },
            { raison_sociale: 'Bernard Informatique', nom: 'Bernard', prenom: 'Sophie', id: 4 },
            { raison_sociale: 'Dubois Consulting', nom: 'Dubois', prenom: 'Thomas', id: 5 },
            { raison_sociale: 'Moreau Solutions', nom: 'Moreau', prenom: 'Claire', id: 6 },
            { raison_sociale: 'Laurent Entreprise', nom: 'Laurent', prenom: 'David', id: 7 },
            { raison_sociale: 'Simon Services', nom: 'Simon', prenom: 'Julie', id: 8 },
            { raison_sociale: 'Michel & Associés', nom: 'Michel', prenom: 'Nicolas', id: 9 },
            { raison_sociale: 'Garcia Industries', nom: 'Garcia', prenom: 'Laura', id: 10 }
        ];

        // Gestion des onglets
        document.querySelectorAll('.paiements-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Retirer active de tous les onglets
                document.querySelectorAll('.paiements-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.paiements-tab-content').forEach(c => c.classList.remove('active'));
                
                // Ajouter active à l'onglet cliqué
                this.classList.add('active');
                document.getElementById(targetTab).classList.add('active');
            });
        });

        // Recherche client automatique avec suggestions
        (function() {
            const searchInput = document.getElementById('clientSearchInput');
            const suggestionsContainer = document.getElementById('clientSuggestions');
            let selectedClientId = null;

            if (!searchInput || !suggestionsContainer) return;

            function formatClientName(client) {
                return `${client.raison_sociale} (${client.prenom} ${client.nom})`;
            }

            function filterClients(query) {
                if (!query || query.trim().length === 0) {
                    return [];
                }
                const lowerQuery = query.toLowerCase();
                return clientsData.filter(client => {
                    return client.raison_sociale.toLowerCase().includes(lowerQuery) ||
                           client.nom.toLowerCase().includes(lowerQuery) ||
                           client.prenom.toLowerCase().includes(lowerQuery);
                });
            }

            function displaySuggestions(clients) {
                suggestionsContainer.innerHTML = '';
                
                if (clients.length === 0) {
                    suggestionsContainer.classList.remove('show');
                    return;
                }

                clients.forEach((client, index) => {
                    const item = document.createElement('div');
                    item.className = 'client-suggestion-item';
                    item.setAttribute('data-client-id', client.id);
                    item.innerHTML = `
                        <div class="client-suggestion-name">${client.raison_sociale}</div>
                        <div class="client-suggestion-details">${client.prenom} ${client.nom}</div>
                    `;
                    
                    item.addEventListener('click', function() {
                        searchInput.value = formatClientName(client);
                        selectedClientId = client.id;
                        selectedClient = client.raison_sociale;
                        suggestionsContainer.classList.remove('show');
                        console.log('Client sélectionné:', client);
                        // Mettre à jour la courbe avec le client sélectionné
                        updateConsumptionChart();
                    });

                    item.addEventListener('mouseenter', function() {
                        suggestionsContainer.querySelectorAll('.client-suggestion-item').forEach(i => i.classList.remove('active'));
                        this.classList.add('active');
                    });

                    suggestionsContainer.appendChild(item);
                });

                suggestionsContainer.classList.add('show');
            }

            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                if (query.length === 0) {
                    suggestionsContainer.classList.remove('show');
                    selectedClientId = null;
                    selectedClient = null;
                    // Réinitialiser le graphique
                    updateConsumptionChart();
                    return;
                }
                const filtered = filterClients(query);
                displaySuggestions(filtered);
            });

            // Fermer les suggestions en cliquant ailleurs
            document.addEventListener('click', function(e) {
                if (!searchInput.contains(e.target) && !suggestionsContainer.contains(e.target)) {
                    suggestionsContainer.classList.remove('show');
                }
            });

            // Navigation clavier dans les suggestions
            let selectedIndex = -1;
            searchInput.addEventListener('keydown', function(e) {
                const items = suggestionsContainer.querySelectorAll('.client-suggestion-item');
                
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    items.forEach((item, idx) => {
                        item.classList.toggle('active', idx === selectedIndex);
                    });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, -1);
                    items.forEach((item, idx) => {
                        item.classList.toggle('active', idx === selectedIndex);
                    });
                } else if (e.key === 'Enter' && selectedIndex >= 0 && items[selectedIndex]) {
                    e.preventDefault();
                    items[selectedIndex].click();
                } else if (e.key === 'Escape') {
                    suggestionsContainer.classList.remove('show');
                }
            });
        })();

        // Sélecteurs de période (Mois et Année) - Filtrage dynamique
        (function() {
            const monthSelect = document.getElementById('monthSelect');
            const yearSelect = document.getElementById('yearSelect');

            // Définir le mois actuel par défaut
            if (monthSelect) {
                const monthValue = String(new Date().getMonth() + 1).padStart(2, '0');
                monthSelect.value = monthValue;
                selectedMonth = monthValue;
            }

            if (monthSelect) {
                monthSelect.addEventListener('change', function() {
                    selectedMonth = this.value;
                    console.log('Mois sélectionné:', this.value, this.options[this.selectedIndex].text);
                    // Mettre à jour la courbe
                    updateConsumptionChart();
                });
            }

            if (yearSelect) {
                yearSelect.addEventListener('change', function() {
                    selectedYear = this.value;
                    console.log('Année sélectionnée:', this.value);
                    // Mettre à jour la courbe
                    updateConsumptionChart();
                });
            }
            
            // Initialiser l'année par défaut
            if (yearSelect && yearSelect.value) {
                selectedYear = yearSelect.value;
            }
        })();

        // Export CSV dynamique avec nom incluant client + date
        (function() {
            const exportBtn = document.getElementById('exportCsvBtn');
            if (!exportBtn) return;

            function getCurrentData() {
                const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                                   'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
                
                let data = [];

                // Si un client, mois et année sont sélectionnés, exporter les données de ce mois
                if (selectedClient && selectedMonth && selectedYear) {
                    const periodKey = `${selectedYear}-${selectedMonth}`;
                    const clientData = FAKE_CONSO[selectedClient];
                    if (clientData && clientData[periodKey]) {
                        const monthData = clientData[periodKey];
                        data.push({
                            mois: monthNames[parseInt(selectedMonth) - 1],
                            nb_noir: monthData.noir,
                            nb_couleur: monthData.couleur
                        });
                    }
                } else {
                    // Sinon, exporter toutes les données disponibles (par défaut)
                    const year = selectedYear || '2025';
                    for (let month = 1; month <= 12; month++) {
                        const monthStr = String(month).padStart(2, '0');
                        let totalNB = 0, totalColor = 0, count = 0;
                        for (let cid in dataClientMensuelle) {
                            if (dataClientMensuelle[cid][year] && dataClientMensuelle[cid][year][monthStr]) {
                                totalNB += dataClientMensuelle[cid][year][monthStr].nb;
                                totalColor += dataClientMensuelle[cid][year][monthStr].couleur;
                                count++;
                            }
                        }
                        data.push({
                            mois: monthNames[month - 1],
                            nb_noir: count > 0 ? Math.round(totalNB / count) : 0,
                            nb_couleur: count > 0 ? Math.round(totalColor / count) : 0
                        });
                    }
                }

                return data;
            }

            function convertToCSV(data) {
                // En-tête
                const headers = ['Mois', 'Pages Noir & Blanc', 'Pages Couleur'];
                const csvRows = [headers.join(';')];

                // Données
                data.forEach(row => {
                    const values = [row.mois, row.nb_noir, row.nb_couleur];
                    csvRows.push(values.join(';'));
                });

                return csvRows.join('\n');
            }

            function downloadCSV(csvContent, filename) {
                const blob = new Blob(['\ufeff' + csvContent], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                
                link.setAttribute('href', url);
                link.setAttribute('download', filename);
                link.style.visibility = 'hidden';
                
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            }

            function generateFilename() {
                let filename = 'consommation';
                
                if (selectedClient && selectedMonth && selectedYear) {
                    const clientName = selectedClient.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
                    const monthNames = ['janvier', 'fevrier', 'mars', 'avril', 'mai', 'juin', 
                                       'juillet', 'aout', 'septembre', 'octobre', 'novembre', 'decembre'];
                    const monthName = monthNames[parseInt(selectedMonth) - 1];
                    filename += `_${clientName}_${monthName}_${selectedYear}`;
                } else if (selectedClient) {
                    const clientName = selectedClient.replace(/[^a-zA-Z0-9]/g, '_').toLowerCase();
                    filename += `_${clientName}`;
                } else {
                    filename += '_tous_clients';
                }
                
                // Ajouter la date d'export
                const now = new Date();
                const dateStr = now.toISOString().split('T')[0].replace(/-/g, '');
                filename += `_${dateStr}`;
                
                return filename + '.csv';
            }

            exportBtn.addEventListener('click', function() {
                const data = getCurrentData();
                const csvContent = convertToCSV(data);
                const filename = generateFilename();
                
                downloadCSV(csvContent, filename);
            });
        })();

        // ====== DONNÉES FICTIVES DE CONSOMMATION PAR CLIENT ======
        const dataClientMensuelle = {
            "1": { // DUPONT SARL
                "2023": {
                    "01": { nb: 1200, couleur: 300 }, "02": { nb: 1350, couleur: 350 },
                    "03": { nb: 1100, couleur: 280 }, "04": { nb: 1450, couleur: 400 },
                    "05": { nb: 1600, couleur: 450 }, "06": { nb: 1500, couleur: 420 },
                    "07": { nb: 1700, couleur: 500 }, "08": { nb: 1650, couleur: 480 },
                    "09": { nb: 1800, couleur: 520 }, "10": { nb: 1750, couleur: 510 },
                    "11": { nb: 1900, couleur: 550 }, "12": { nb: 2000, couleur: 600 }
                },
                "2024": {
                    "01": { nb: 2100, couleur: 650 }, "02": { nb: 2200, couleur: 680 },
                    "03": { nb: 2050, couleur: 620 }, "04": { nb: 2300, couleur: 720 },
                    "05": { nb: 2400, couleur: 750 }, "06": { nb: 2350, couleur: 730 },
                    "07": { nb: 2500, couleur: 800 }, "08": { nb: 2450, couleur: 780 },
                    "09": { nb: 2600, couleur: 820 }, "10": { nb: 2550, couleur: 810 },
                    "11": { nb: 2700, couleur: 850 }, "12": { nb: 2800, couleur: 900 }
                },
                "2025": {
                    "01": { nb: 2900, couleur: 950 }, "02": { nb: 3000, couleur: 980 },
                    "03": { nb: 2850, couleur: 920 }, "04": { nb: 3100, couleur: 1020 },
                    "05": { nb: 3200, couleur: 1050 }, "06": { nb: 3150, couleur: 1030 },
                    "07": { nb: 3300, couleur: 1100 }, "08": { nb: 3250, couleur: 1080 },
                    "09": { nb: 3400, couleur: 1120 }, "10": { nb: 3350, couleur: 1110 },
                    "11": { nb: 3500, couleur: 1150 }, "12": { nb: 3600, couleur: 1200 }
                }
            },
            "2": { // Durand Services
                "2023": {
                    "01": { nb: 800, couleur: 200 }, "02": { nb: 850, couleur: 220 },
                    "03": { nb: 750, couleur: 180 }, "04": { nb: 900, couleur: 250 },
                    "05": { nb: 950, couleur: 280 }, "06": { nb: 920, couleur: 260 },
                    "07": { nb: 1000, couleur: 300 }, "08": { nb: 980, couleur: 290 },
                    "09": { nb: 1050, couleur: 320 }, "10": { nb: 1020, couleur: 310 },
                    "11": { nb: 1100, couleur: 350 }, "12": { nb: 1150, couleur: 380 }
                },
                "2024": {
                    "01": { nb: 1200, couleur: 400 }, "02": { nb: 1250, couleur: 420 },
                    "03": { nb: 1180, couleur: 390 }, "04": { nb: 1300, couleur: 450 },
                    "05": { nb: 1350, couleur: 480 }, "06": { nb: 1320, couleur: 460 },
                    "07": { nb: 1400, couleur: 500 }, "08": { nb: 1380, couleur: 490 },
                    "09": { nb: 1450, couleur: 520 }, "10": { nb: 1420, couleur: 510 },
                    "11": { nb: 1500, couleur: 550 }, "12": { nb: 1550, couleur: 580 }
                },
                "2025": {
                    "01": { nb: 1600, couleur: 600 }, "02": { nb: 1650, couleur: 620 },
                    "03": { nb: 1580, couleur: 590 }, "04": { nb: 1700, couleur: 650 },
                    "05": { nb: 1750, couleur: 680 }, "06": { nb: 1720, couleur: 660 },
                    "07": { nb: 1800, couleur: 700 }, "08": { nb: 1780, couleur: 690 },
                    "09": { nb: 1850, couleur: 720 }, "10": { nb: 1820, couleur: 710 },
                    "11": { nb: 1900, couleur: 750 }, "12": { nb: 1950, couleur: 780 }
                }
            },
            "3": { // Martin & Fils
                "2023": {
                    "01": { nb: 1500, couleur: 400 }, "02": { nb: 1600, couleur: 420 },
                    "03": { nb: 1450, couleur: 380 }, "04": { nb: 1700, couleur: 450 },
                    "05": { nb: 1800, couleur: 480 }, "06": { nb: 1750, couleur: 460 },
                    "07": { nb: 1900, couleur: 500 }, "08": { nb: 1850, couleur: 490 },
                    "09": { nb: 2000, couleur: 520 }, "10": { nb: 1950, couleur: 510 },
                    "11": { nb: 2100, couleur: 550 }, "12": { nb: 2200, couleur: 580 }
                },
                "2024": {
                    "01": { nb: 2300, couleur: 600 }, "02": { nb: 2400, couleur: 620 },
                    "03": { nb: 2250, couleur: 590 }, "04": { nb: 2500, couleur: 650 },
                    "05": { nb: 2600, couleur: 680 }, "06": { nb: 2550, couleur: 660 },
                    "07": { nb: 2700, couleur: 700 }, "08": { nb: 2650, couleur: 690 },
                    "09": { nb: 2800, couleur: 720 }, "10": { nb: 2750, couleur: 710 },
                    "11": { nb: 2900, couleur: 750 }, "12": { nb: 3000, couleur: 780 }
                },
                "2025": {
                    "01": { nb: 3100, couleur: 800 }, "02": { nb: 3200, couleur: 820 },
                    "03": { nb: 3050, couleur: 790 }, "04": { nb: 3300, couleur: 850 },
                    "05": { nb: 3400, couleur: 880 }, "06": { nb: 3350, couleur: 860 },
                    "07": { nb: 3500, couleur: 900 }, "08": { nb: 3450, couleur: 890 },
                    "09": { nb: 3600, couleur: 920 }, "10": { nb: 3550, couleur: 910 },
                    "11": { nb: 3700, couleur: 950 }, "12": { nb: 3800, couleur: 980 }
                }
            }
        };

        // Générer des données pour les autres clients (4-10) avec des variations
        for (let clientId = 4; clientId <= 10; clientId++) {
            dataClientMensuelle[clientId.toString()] = {};
            for (let year of ['2023', '2024', '2025']) {
                dataClientMensuelle[clientId.toString()][year] = {};
                const baseNb = 500 + (clientId * 100);
                const baseColor = 150 + (clientId * 20);
                for (let month = 1; month <= 12; month++) {
                    const monthStr = String(month).padStart(2, '0');
                    const variation = Math.floor(Math.random() * 200) - 100;
                    dataClientMensuelle[clientId.toString()][year][monthStr] = {
                        nb: Math.max(100, baseNb + variation + (month * 50)),
                        couleur: Math.max(50, baseColor + Math.floor(variation / 3) + (month * 10))
                    };
                }
            }
        }

        // ====== GRAPHIQUE MODERNE AVEC CHART.JS ======
        let consumptionChart = null;
        let selectedClient = null;
        let selectedMonth = null;
        let selectedYear = null;

        // Restructurer les données au format demandé (client -> année-mois)
        const FAKE_CONSO = {};
        for (let clientId in dataClientMensuelle) {
            const client = clientsData.find(c => String(c.id) === clientId);
            if (!client) continue;
            const clientName = client.raison_sociale;
            FAKE_CONSO[clientName] = {};
            
            for (let year in dataClientMensuelle[clientId]) {
                for (let month in dataClientMensuelle[clientId][year]) {
                    const key = `${year}-${month}`;
                    const data = dataClientMensuelle[clientId][year][month];
                    FAKE_CONSO[clientName][key] = {
                        noir: data.nb,
                        couleur: data.couleur
                    };
                }
            }
        }

        function initChart() {
            const canvas = document.getElementById('consumptionChart');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            
            // Détruire le graphique existant s'il existe
            if (consumptionChart) {
                consumptionChart.destroy();
            }

            consumptionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Noir & Blanc', 'Couleur'],
                    datasets: []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            padding: 12,
                            titleFont: {
                                family: 'Inter, system-ui, sans-serif',
                                size: 14,
                                weight: '600'
                            },
                            bodyFont: {
                                family: 'Inter, system-ui, sans-serif',
                                size: 13
                            },
                            borderColor: 'rgba(255, 255, 255, 0.1)',
                            borderWidth: 1,
                            cornerRadius: 8,
                            displayColors: true,
                            callbacks: {
                                label: function(context) {
                                    return context.parsed.y.toLocaleString('fr-FR') + ' pages';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                display: false
                            },
                            ticks: {
                                font: {
                                    family: 'Inter, system-ui, sans-serif',
                                    size: 13,
                                    weight: '500'
                                },
                                color: '#475569'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: true,
                                color: 'rgba(226, 232, 240, 0.5)',
                                drawBorder: false
                            },
                            ticks: {
                                font: {
                                    family: 'Inter, system-ui, sans-serif',
                                    size: 12
                                },
                                color: '#64748b',
                                padding: 10,
                                callback: function(value) {
                                    return value.toLocaleString('fr-FR');
                                }
                            }
                        }
                    },
                    elements: {
                        bar: {
                            borderRadius: 8,
                            borderSkipped: false
                        }
                    }
                }
            });

            updateConsumptionChart();
        }

        function updateConsumptionChart() {
            const chartWrapper = document.querySelector('.paiements-chart-wrapper');
            const canvas = document.getElementById('consumptionChart');
            const chartTitle = document.getElementById('chartTitle');
            const chartSubtitle = document.getElementById('chartSubtitle');
            
            // Vérifier si les 3 conditions sont réunies
            if (!selectedClient || !selectedMonth || !selectedYear) {
                // Afficher le message d'état
                if (canvas) {
                    canvas.style.display = 'none';
                }
                if (chartWrapper) {
                    let messageDiv = chartWrapper.querySelector('.chart-message');
                    if (!messageDiv) {
                        messageDiv = document.createElement('div');
                        messageDiv.className = 'chart-message';
                        chartWrapper.appendChild(messageDiv);
                    }
                    messageDiv.innerHTML = `
                        <div style="text-align: center; padding: 3rem 2rem; color: #64748b;">
                            <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin: 0 auto 1rem; opacity: 0.5;">
                                <path d="M9 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0zM19 17a2 2 0 1 1-4 0 2 2 0 0 1 4 0z"/>
                                <path d="M13 16.5V6a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1v10.5a1 1 0 0 0 1 1h1m8-1a1 1 0 0 1-1 1H9m4-1v-8a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v8m-6 1h6"/>
                            </svg>
                            <p style="font-size: 1.1rem; font-weight: 500; margin-bottom: 0.5rem; color: #475569;">Veuillez sélectionner un client et une période</p>
                            <p style="font-size: 0.9rem; color: #94a3b8;">Sélectionnez un client via la recherche, puis choisissez un mois et une année pour afficher la consommation.</p>
                        </div>
                    `;
                }
                if (chartTitle) {
                    chartTitle.textContent = 'Consommation par mois';
                }
                if (chartSubtitle) {
                    chartSubtitle.textContent = '';
                    chartSubtitle.style.display = 'none';
                }
                return;
            }

            // Les 3 conditions sont réunies → afficher le graphique
            if (chartWrapper) {
                const messageDiv = chartWrapper.querySelector('.chart-message');
                if (messageDiv) {
                    messageDiv.remove();
                }
            }
            if (canvas) {
                canvas.style.display = 'block';
            }

            if (!consumptionChart) {
                initChart();
                return;
            }

            // Récupérer les données pour le client, mois et année sélectionnés
            const periodKey = `${selectedYear}-${selectedMonth}`;
            const clientData = FAKE_CONSO[selectedClient];
            
            if (!clientData || !clientData[periodKey]) {
                // Pas de données pour cette période
                consumptionChart.data.labels = ['Noir & Blanc', 'Couleur'];
                consumptionChart.data.datasets = [{
                    label: 'Consommation',
                    data: [0, 0],
                    backgroundColor: ['#1e293b', '#3b82f6']
                }];
            } else {
                const data = clientData[periodKey];
                consumptionChart.data.labels = ['Noir & Blanc', 'Couleur'];
                consumptionChart.data.datasets = [{
                    label: 'Consommation',
                    data: [data.noir, data.couleur],
                    backgroundColor: ['#1e293b', '#3b82f6']
                }];
            }

            // Mettre à jour le titre
            const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                               'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
            const monthName = monthNames[parseInt(selectedMonth) - 1];
            
            if (chartTitle) {
                chartTitle.textContent = `Consommation - ${selectedClient}`;
            }
            if (chartSubtitle) {
                chartSubtitle.textContent = `${monthName} ${selectedYear}`;
                chartSubtitle.style.display = 'inline';
            }

            consumptionChart.update('active');
        }

        // Initialiser le graphique au chargement (mais ne pas l'afficher tant que les conditions ne sont pas réunies)
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                initChart();
                updateConsumptionChart();
            });
        } else {
            initChart();
            updateConsumptionChart();
        }

        // Fonctions modales
        function openSendMailModal(invoiceNum, clientName) {
            document.getElementById('mailClient').value = clientName;
            document.getElementById('mailInvoice').value = invoiceNum;
            document.getElementById('mailSubject').value = `Facture ${invoiceNum}`;
            document.getElementById('sendMailModal').classList.add('open');
        }

        function closeSendMailModal() {
            document.getElementById('sendMailModal').classList.remove('open');
            document.getElementById('sendMailForm').reset();
        }

        document.getElementById('sendMailForm').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Mail envoyé (fictif)');
            closeSendMailModal();
        });

        function downloadInvoice(invoiceNum) {
            alert(`Téléchargement de ${invoiceNum} (fictif)`);
        }

        function viewInvoiceDetails(invoiceNum) {
            alert(`Détails de ${invoiceNum} (fictif)`);
        }

        // Modale facture manuelle
        let lineCounter = 0;
        function addInvoiceLine() {
            lineCounter++;
            const container = document.getElementById('invoiceLinesContainer');
            const lineDiv = document.createElement('div');
            lineDiv.className = 'paiements-invoice-line';
            lineDiv.id = `line-${lineCounter}`;
            lineDiv.innerHTML = `
                <input type="text" placeholder="Désignation" class="line-designation" required>
                <input type="number" placeholder="Quantité" class="line-quantity" min="0" step="1" required>
                <input type="number" placeholder="Prix unitaire HT" class="line-price" min="0" step="0.01" required>
                <select class="line-tva">
                    <option value="0">0%</option>
                    <option value="5.5">5.5%</option>
                    <option value="10">10%</option>
                    <option value="20" selected>20%</option>
                </select>
                <span class="line-total">0,00 €</span>
                <button type="button" class="btn-icon" onclick="removeInvoiceLine(${lineCounter})">✕</button>
            `;
            container.appendChild(lineDiv);
            
            // Ajouter les event listeners pour le calcul
            lineDiv.querySelectorAll('input, select').forEach(input => {
                input.addEventListener('input', calculateInvoiceTotals);
            });
        }

        function removeInvoiceLine(id) {
            document.getElementById(`line-${id}`).remove();
            calculateInvoiceTotals();
        }

        function calculateInvoiceTotals() {
            const lines = document.querySelectorAll('.paiements-invoice-line');
            let totalHT = 0;
            let totalTVA = 0;

            lines.forEach(line => {
                const quantity = parseFloat(line.querySelector('.line-quantity').value) || 0;
                const price = parseFloat(line.querySelector('.line-price').value) || 0;
                const tvaRate = parseFloat(line.querySelector('.line-tva').value) || 0;
                
                const lineHT = quantity * price;
                const lineTVA = lineHT * (tvaRate / 100);
                
                totalHT += lineHT;
                totalTVA += lineTVA;
                
                line.querySelector('.line-total').textContent = (lineHT + lineTVA).toFixed(2).replace('.', ',') + ' €';
            });

            document.getElementById('totalHT').textContent = totalHT.toFixed(2).replace('.', ',') + ' €';
            document.getElementById('totalTVA').textContent = totalTVA.toFixed(2).replace('.', ',') + ' €';
            document.getElementById('totalTTC').textContent = (totalHT + totalTVA).toFixed(2).replace('.', ',') + ' €';
        }

        function openManualInvoiceModal() {
            document.getElementById('manualInvoiceModal').classList.add('open');
            // Ajouter une ligne par défaut
            if (document.getElementById('invoiceLinesContainer').children.length === 0) {
                addInvoiceLine();
            }
        }

        function closeManualInvoiceModal() {
            document.getElementById('manualInvoiceModal').classList.remove('open');
            document.getElementById('manualInvoiceForm').reset();
            document.getElementById('invoiceLinesContainer').innerHTML = '';
            lineCounter = 0;
        }

        document.getElementById('manualInvoiceForm').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Facture enregistrée (fictif)');
            closeManualInvoiceModal();
        });

        // Facture automatique
        function calculatePrefacturation() {
            const table = document.getElementById('prefacturationTable');
            table.classList.remove('paiements-hidden');
        }

        function generateInvoices() {
            alert('Factures générées (fictif)');
        }

        // Historique paiements
        function viewPaymentDetails(id) {
            const details = {
                1: { date: '15/12/2025', client: 'Client A', mode: 'Virement', amount: '1 250,00 €', invoices: 'FAC-2025-001', comment: 'Paiement mensuel' },
                2: { date: '10/12/2025', client: 'Client B', mode: 'Chèque', amount: '2 450,50 €', invoices: 'FAC-2025-002', comment: 'Chèque n°123456' },
                3: { date: '05/12/2025', client: 'Client C', mode: 'Espèces', amount: '890,75 €', invoices: 'FAC-2025-003', comment: 'Règlement comptant' },
                4: { date: '01/12/2025', client: 'Client A', mode: 'Carte', amount: '1 680,00 €', invoices: 'FAC-2025-004', comment: 'Paiement en ligne' }
            };

            const payment = details[id];
            if (payment) {
                document.getElementById('paymentDetailsContent').innerHTML = `
                    <div class="paiements-form-grid">
                        <div class="paiements-form-group">
                            <label>Date</label>
                            <input type="text" value="${payment.date}" readonly>
                        </div>
                        <div class="paiements-form-group">
                            <label>Client</label>
                            <input type="text" value="${payment.client}" readonly>
                        </div>
                        <div class="paiements-form-group">
                            <label>Mode de paiement</label>
                            <input type="text" value="${payment.mode}" readonly>
                        </div>
                        <div class="paiements-form-group">
                            <label>Montant</label>
                            <input type="text" value="${payment.amount}" readonly>
                        </div>
                        <div class="paiements-form-group">
                            <label>Factures associées</label>
                            <input type="text" value="${payment.invoices}" readonly>
                        </div>
                        <div class="paiements-form-group full-width">
                            <label>Commentaire</label>
                            <textarea readonly>${payment.comment}</textarea>
                        </div>
                    </div>
                `;
                document.getElementById('paymentDetailsModal').classList.add('open');
            }
        }

        function closePaymentDetailsModal() {
            document.getElementById('paymentDetailsModal').classList.remove('open');
        }

        // Formulaire de paiement
        document.getElementById('paymentClient').addEventListener('change', function() {
            const clientId = this.value;
            const invoicesContainer = document.getElementById('clientInvoices');
            
            if (clientId) {
                // Données fictives de factures
                const invoices = {
                    '1': [
                        { num: 'FAC-2025-005', date: '01/12/2025', amount: '1 200,00 €', remaining: '1 200,00 €' },
                        { num: 'FAC-2025-006', date: '15/12/2025', amount: '850,50 €', remaining: '850,50 €' }
                    ],
                    '2': [
                        { num: 'FAC-2025-007', date: '05/12/2025', amount: '2 100,00 €', remaining: '2 100,00 €' }
                    ],
                    '3': [
                        { num: 'FAC-2025-008', date: '10/12/2025', amount: '750,25 €', remaining: '750,25 €' },
                        { num: 'FAC-2025-009', date: '20/12/2025', amount: '1 050,00 €', remaining: '1 050,00 €' }
                    ]
                };

                const clientInvoices = invoices[clientId] || [];
                if (clientInvoices.length > 0) {
                    let html = '<div class="paiements-table-container"><table><thead><tr><th></th><th>N° facture</th><th>Date</th><th>Montant restant</th></tr></thead><tbody>';
                    clientInvoices.forEach(inv => {
                        html += `
                            <tr>
                                <td><input type="checkbox" class="invoice-checkbox" data-amount="${inv.remaining.replace(/[^\d,]/g, '').replace(',', '.')}"></td>
                                <td>${inv.num}</td>
                                <td>${inv.date}</td>
                                <td>${inv.remaining}</td>
                            </tr>
                        `;
                    });
                    html += '</tbody></table></div>';
                    invoicesContainer.innerHTML = html;
                } else {
                    invoicesContainer.innerHTML = '<p class="paiements-hint">Aucune facture en attente</p>';
                }
            } else {
                invoicesContainer.innerHTML = '<p class="paiements-hint">Sélectionnez un client pour voir ses factures</p>';
            }
        });

        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Paiement enregistré (fictif)');
            this.reset();
            document.getElementById('clientInvoices').innerHTML = '<p class="paiements-hint">Sélectionnez un client pour voir ses factures</p>';
        });

        function distributeAmount() {
            const amount = parseFloat(document.getElementById('paymentAmount').value) || 0;
            if (amount > 0) {
                alert(`Répartition automatique de ${amount.toFixed(2)} € sur les factures sélectionnées (fictif)`);
            } else {
                alert('Veuillez saisir un montant');
            }
        }

        // Fermer les modales en cliquant en dehors
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('open');
                }
            });
        });

        // Fermer les modales avec Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.open').forEach(overlay => {
                    overlay.classList.remove('open');
                });
            }
        });
    </script>
</body>
</html>
