<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiements & Facturation - CC Computer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <link rel="stylesheet" href="/assets/css/paiements.css" />
</head>
<body class="page-paiements">
    <div class="paiements-wrapper">
        <!-- Header fixe -->
        <div class="paiements-header">
            <div class="paiements-header-top">
                <h1 class="dashboard-title">Paiements & Facturation</h1>
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
            <div class="paiements-kpi-row">
                <div class="paiements-kpi-card">
                    <div class="paiements-kpi-title">Dettes</div>
                    <div class="paiements-kpi-amount">12 430,50 €</div>
                    <div class="paiements-kpi-subtitle">Montant total restant dû</div>
                </div>
                <div class="paiements-kpi-card">
                    <div class="paiements-kpi-title">Payé</div>
                    <div class="paiements-kpi-amount">45 230,00 €</div>
                    <div class="paiements-kpi-subtitle">Total encaissé sur la période</div>
                </div>
                <div class="paiements-kpi-card">
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
                    <div class="paiements-filter-group">
                        <label>Rechercher un client</label>
                        <input type="text" placeholder="Rechercher un client...">
                    </div>
                    <div class="paiements-filter-group">
                        <label>Période</label>
                        <select>
                            <option>Ce mois-ci</option>
                            <option>Cette année</option>
                            <option>Personnalisée</option>
                        </select>
                    </div>
                    <button class="btn-primary">Rechercher</button>
                    <button class="btn-secondary">Exporter en Excel</button>
                </div>

                <div class="paiements-chart-container">
                    <div class="paiements-chart-title">Consommation par mois</div>
                    <div class="paiements-chart-wrapper">
                        <canvas id="consumptionChart"></canvas>
                    </div>
                    <div class="paiements-chart-legend">
                        <div class="paiements-legend-item">
                            <div class="paiements-legend-color nb"></div>
                            <span>Noir & Blanc</span>
                        </div>
                        <div class="paiements-legend-item">
                            <div class="paiements-legend-color color"></div>
                            <span>Couleur</span>
                        </div>
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

        // Graphique de consommation (simplifié avec Canvas)
        const canvas = document.getElementById('consumptionChart');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            const resizeCanvas = () => {
                canvas.width = canvas.parentElement.offsetWidth;
                canvas.height = 300;
                drawChart();
            };
            
            const drawChart = () => {
                // Données fictives
                const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
                const dataNB = [1200, 1350, 1100, 1450, 1600, 1500, 1700, 1650, 1800, 1750, 1900, 2000];
                const dataColor = [300, 350, 280, 400, 450, 420, 500, 480, 520, 510, 550, 600];

                const maxValue = Math.max(...dataNB, ...dataColor);
                const chartHeight = canvas.height - 60;
                const chartWidth = canvas.width - 80;
                const barWidth = chartWidth / months.length - 10;

                // Clear canvas
                ctx.clearRect(0, 0, canvas.width, canvas.height);

                // Dessiner les axes
                ctx.strokeStyle = '#e2e8f0';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(40, 20);
                ctx.lineTo(40, chartHeight + 20);
                ctx.lineTo(canvas.width - 40, chartHeight + 20);
                ctx.stroke();

                // Dessiner les barres
                months.forEach((month, index) => {
                    const x = 50 + index * (barWidth + 10);
                    const nbHeight = (dataNB[index] / maxValue) * chartHeight;
                    const colorHeight = (dataColor[index] / maxValue) * chartHeight;

                    // Barre N&B
                    ctx.fillStyle = '#1e293b';
                    ctx.fillRect(x, chartHeight + 20 - nbHeight, barWidth / 2, nbHeight);

                    // Barre Couleur
                    ctx.fillStyle = '#3b82f6';
                    ctx.fillRect(x + barWidth / 2, chartHeight + 20 - colorHeight, barWidth / 2, colorHeight);

                    // Labels
                    ctx.fillStyle = '#64748b';
                    ctx.font = '12px Inter';
                    ctx.textAlign = 'center';
                    ctx.fillText(month, x + barWidth / 2, chartHeight + 40);
                });
            };
            
            resizeCanvas();
            window.addEventListener('resize', resizeCanvas);
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
