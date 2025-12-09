<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiements & Facturation - CC Computer</title>
    <style>
        :root {
            --bg-primary: #ffffff;
            --bg-secondary: #f8fafc;
            --bg-tertiary: #f1f5f9;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --border-color: #e2e8f0;
            --accent-primary: #3b82f6;
            --accent-secondary: #1d4ed8;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            line-height: 1.6;
            height: 100vh;
            overflow: hidden;
        }

        /* Container principal */
        .paiements-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* Header fixe */
        .paiements-header {
            background: var(--bg-primary);
            border-bottom: 1px solid var(--border-color);
            padding: 1.5rem 2rem;
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }

        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .page-title {
            font-size: 1.875rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .header-controls {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .header-controls select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        /* KPI Cards */
        .kpi-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-top: 1rem;
            flex-shrink: 0;
        }

        .kpi-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .kpi-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .kpi-amount {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .kpi-subtitle {
            font-size: 0.875rem;
            color: var(--text-muted);
        }

        /* Onglets */
        .tabs-container {
            background: var(--bg-primary);
            border-bottom: 1px solid var(--border-color);
            flex-shrink: 0;
        }

        .tabs {
            display: flex;
            gap: 0;
            padding: 0 2rem;
            overflow-x: auto;
        }

        .tab {
            padding: 1rem 1.5rem;
            background: transparent;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
            position: relative;
        }

        .tab:hover {
            color: var(--text-primary);
            background: var(--bg-tertiary);
        }

        .tab.active {
            color: var(--accent-primary);
            border-bottom-color: var(--accent-primary);
            background: var(--bg-primary);
        }

        /* Zone de contenu */
        .content-area {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            background: var(--bg-secondary);
        }

        .tab-content {
            display: none;
            animation: fadeIn 0.3s ease;
        }

        .tab-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Filtres */
        .filters-bar {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .filter-group input,
        .filter-group select {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .btn {
            padding: 0.5rem 1.25rem;
            border: none;
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--accent-primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-secondary);
        }

        .btn-secondary {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
        }

        .btn-secondary:hover {
            background: var(--border-color);
        }

        /* Graphique */
        .chart-container {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }

        .chart-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-primary);
        }

        .chart-wrapper {
            position: relative;
            height: 300px;
            margin-top: 2rem;
        }

        .chart-legend {
            display: flex;
            gap: 2rem;
            justify-content: center;
            margin-top: 1rem;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .legend-color {
            width: 16px;
            height: 16px;
            border-radius: 4px;
        }

        .legend-color.nb { background: #1e293b; }
        .legend-color.color { background: #3b82f6; }

        /* Tableaux */
        .table-container {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: var(--bg-tertiary);
        }

        th {
            padding: 1rem;
            text-align: left;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--border-color);
        }

        td {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        tbody tr:hover {
            background: var(--bg-tertiary);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge-success {
            background: #d1fae5;
            color: #065f46;
        }

        .badge-warning {
            background: #fef3c7;
            color: #92400e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .btn-icon {
            padding: 0.375rem 0.75rem;
            border: 1px solid var(--border-color);
            background: var(--bg-primary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            font-size: 0.875rem;
            transition: all 0.2s ease;
        }

        .btn-icon:hover {
            background: var(--bg-tertiary);
            border-color: var(--accent-primary);
        }

        /* Cards */
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
        }

        .card-description {
            font-size: 0.9rem;
            color: var(--text-secondary);
            margin-bottom: 1.5rem;
        }

        /* Formulaire */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .form-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-primary);
            color: var(--text-primary);
            font-size: 0.9rem;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        /* Layout 2 colonnes */
        .two-columns {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }

        /* Modale */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal {
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            position: relative;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
        }

        .modal-close:hover {
            background: var(--bg-tertiary);
            color: var(--text-primary);
        }

        .modal-footer {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        /* Lignes de facture */
        .invoice-lines {
            margin: 1.5rem 0;
        }

        .invoice-line {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 0.75rem;
            align-items: end;
            margin-bottom: 1rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }

        .invoice-totals {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-color);
        }

        .total-line {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            font-size: 0.95rem;
        }

        .total-line.final {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-top: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .two-columns {
                grid-template-columns: 1fr;
            }

            .kpi-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .paiements-header {
                padding: 1rem;
            }

            .header-top {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .content-area {
                padding: 1rem;
            }

            .tabs {
                padding: 0 1rem;
            }

            .tab {
                padding: 0.75rem 1rem;
                font-size: 0.85rem;
            }

            .filters-bar {
                flex-direction: column;
            }

            .filter-group {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="paiements-container">
        <!-- Header fixe -->
        <div class="paiements-header">
            <div class="header-top">
                <h1 class="page-title">Paiements & Facturation</h1>
                <div class="header-controls">
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
            <div class="kpi-row">
                <div class="kpi-card">
                    <div class="kpi-title">Dettes</div>
                    <div class="kpi-amount">12 430,50 €</div>
                    <div class="kpi-subtitle">Montant total restant dû</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">Payé</div>
                    <div class="kpi-amount">45 230,00 €</div>
                    <div class="kpi-subtitle">Total encaissé sur la période</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-title">À payer</div>
                    <div class="kpi-amount">8 750,25 €</div>
                    <div class="kpi-subtitle">Factures échues ou à échéance</div>
                </div>
            </div>
        </div>

        <!-- Barre d'onglets -->
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab active" data-tab="consommation">Consommation</button>
                <button class="tab" data-tab="factures">Factures</button>
                <button class="tab" data-tab="generation">Génération de facture</button>
                <button class="tab" data-tab="historique">Historique paiements</button>
                <button class="tab" data-tab="paiement">Effectuer un paiement</button>
            </div>
        </div>

        <!-- Zone de contenu -->
        <div class="content-area">
            <!-- Onglet 1: Consommation -->
            <div id="consommation" class="tab-content active">
                <div class="filters-bar">
                    <div class="filter-group">
                        <label>Rechercher un client</label>
                        <input type="text" placeholder="Rechercher un client...">
                    </div>
                    <div class="filter-group">
                        <label>Période</label>
                        <select>
                            <option>Ce mois-ci</option>
                            <option>Cette année</option>
                            <option>Personnalisée</option>
                        </select>
                    </div>
                    <button class="btn btn-primary">Rechercher</button>
                    <button class="btn btn-secondary">Exporter en Excel</button>
                </div>

                <div class="chart-container">
                    <div class="chart-title">Consommation par mois</div>
                    <div class="chart-wrapper">
                        <canvas id="consumptionChart"></canvas>
                    </div>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <div class="legend-color nb"></div>
                            <span>Noir & Blanc</span>
                        </div>
                        <div class="legend-item">
                            <div class="legend-color color"></div>
                            <span>Couleur</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Onglet 2: Factures -->
            <div id="factures" class="tab-content">
                <div class="filters-bar">
                    <div class="filter-group">
                        <label>Client</label>
                        <select>
                            <option>Tous</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Du</label>
                        <input type="date" value="2025-01-01">
                    </div>
                    <div class="filter-group">
                        <label>Au</label>
                        <input type="date" value="2025-12-31">
                    </div>
                    <div class="filter-group">
                        <label>Statut</label>
                        <select>
                            <option>Tous</option>
                            <option>Payées</option>
                            <option>Non payées</option>
                            <option>En retard</option>
                        </select>
                    </div>
                    <button class="btn btn-primary">Filtrer</button>
                </div>

                <div class="table-container">
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
                                    <div class="action-buttons">
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
                                    <div class="action-buttons">
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
                                    <div class="action-buttons">
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
                                    <div class="action-buttons">
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
            <div id="generation" class="tab-content">
                <div class="cards-grid">
                    <!-- Card Facture manuelle -->
                    <div class="card">
                        <div class="card-title">Facture manuelle</div>
                        <div class="card-description">
                            Créer une facture manuellement (lignes saisies à la main)
                        </div>
                        <button class="btn btn-primary" onclick="openManualInvoiceModal()">
                            Créer une facture manuelle
                        </button>
                    </div>

                    <!-- Card Facture automatique -->
                    <div class="card">
                        <div class="card-title">Facture automatique</div>
                        <div class="card-description">
                            Pré-générer des factures à partir de la consommation (compteurs)
                        </div>
                        <div class="form-grid" style="margin-top: 1.5rem;">
                            <div class="form-group">
                                <label>Période</label>
                                <select id="autoPeriod">
                                    <option>Mois courant</option>
                                    <option>Mois précédent</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Client</label>
                                <select id="autoClient">
                                    <option>Tous les clients</option>
                                    <option>Client A</option>
                                    <option>Client B</option>
                                    <option>Client C</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                            <button class="btn btn-secondary" onclick="calculatePrefacturation()">
                                Calculer la pré-facturation
                            </button>
                            <button class="btn btn-primary" onclick="generateInvoices()">
                                Générer les factures
                            </button>
                        </div>
                        <div id="prefacturationTable" style="display: none; margin-top: 1.5rem;">
                            <div class="table-container">
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
            <div id="historique" class="tab-content">
                <div class="filters-bar">
                    <div class="filter-group">
                        <label>Client</label>
                        <select>
                            <option>Tous</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Du</label>
                        <input type="date" value="2025-01-01">
                    </div>
                    <div class="filter-group">
                        <label>Au</label>
                        <input type="date" value="2025-12-31">
                    </div>
                    <div class="filter-group">
                        <label>Mode de paiement</label>
                        <select>
                            <option>Tous</option>
                            <option>Espèces</option>
                            <option>Chèque</option>
                            <option>Virement</option>
                            <option>Carte</option>
                        </select>
                    </div>
                    <button class="btn btn-primary">Filtrer</button>
                </div>

                <div class="table-container">
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
                            <tr onclick="viewPaymentDetails(1)" style="cursor: pointer;">
                                <td>15/12/2025</td>
                                <td>Client A</td>
                                <td>Virement</td>
                                <td>1 250,00 €</td>
                                <td>FAC-2025-001</td>
                                <td>Paiement mensuel</td>
                            </tr>
                            <tr onclick="viewPaymentDetails(2)" style="cursor: pointer;">
                                <td>10/12/2025</td>
                                <td>Client B</td>
                                <td>Chèque</td>
                                <td>2 450,50 €</td>
                                <td>FAC-2025-002</td>
                                <td>Chèque n°123456</td>
                            </tr>
                            <tr onclick="viewPaymentDetails(3)" style="cursor: pointer;">
                                <td>05/12/2025</td>
                                <td>Client C</td>
                                <td>Espèces</td>
                                <td>890,75 €</td>
                                <td>FAC-2025-003</td>
                                <td>Règlement comptant</td>
                            </tr>
                            <tr onclick="viewPaymentDetails(4)" style="cursor: pointer;">
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
            <div id="paiement" class="tab-content">
                <div class="two-columns">
                    <!-- Colonne gauche: Formulaire -->
                    <div class="card">
                        <div class="card-title">Enregistrer un paiement</div>
                        <form id="paymentForm" class="form-grid" style="margin-top: 1.5rem;">
                            <div class="form-group">
                                <label>Client</label>
                                <select id="paymentClient" required>
                                    <option value="">Sélectionner un client</option>
                                    <option value="1">Client A</option>
                                    <option value="2">Client B</option>
                                    <option value="3">Client C</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Date du paiement</label>
                                <input type="date" id="paymentDate" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <label>Mode de paiement</label>
                                <select id="paymentMode" required>
                                    <option value="">Sélectionner</option>
                                    <option value="especes">Espèces</option>
                                    <option value="cheque">Chèque</option>
                                    <option value="virement">Virement</option>
                                    <option value="carte">Carte</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Montant reçu</label>
                                <input type="number" id="paymentAmount" step="0.01" min="0" required placeholder="0.00">
                            </div>
                            <div class="form-group">
                                <label>Référence (chèque/virement)</label>
                                <input type="text" id="paymentRef" placeholder="N° de chèque ou référence virement">
                            </div>
                            <div class="form-group full-width">
                                <label>Commentaire</label>
                                <textarea id="paymentComment" placeholder="Commentaire optionnel"></textarea>
                            </div>
                            <div class="form-group full-width">
                                <button type="submit" class="btn btn-primary" style="width: 100%;">
                                    Enregistrer le paiement
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Colonne droite: Factures du client -->
                    <div class="card">
                        <div class="card-title">Factures à payer</div>
                        <div id="clientInvoices" style="margin-top: 1.5rem;">
                            <p style="color: var(--text-muted); font-size: 0.9rem;">
                                Sélectionnez un client pour voir ses factures
                            </p>
                        </div>
                        <div style="margin-top: 1rem;">
                            <button class="btn btn-secondary" onclick="distributeAmount()" style="width: 100%;">
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
                <div class="form-group">
                    <label>Client</label>
                    <input type="text" id="mailClient" readonly>
                </div>
                <div class="form-group">
                    <label>N° de facture</label>
                    <input type="text" id="mailInvoice" readonly>
                </div>
                <div class="form-group">
                    <label>Email du client</label>
                    <input type="email" id="mailEmail" required placeholder="email@exemple.com">
                </div>
                <div class="form-group">
                    <label>Sujet</label>
                    <input type="text" id="mailSubject" required placeholder="Facture FAC-2025-001">
                </div>
                <div class="form-group">
                    <label>Message</label>
                    <textarea id="mailMessage" required placeholder="Votre message..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeSendMailModal()">Annuler</button>
                    <button type="submit" class="btn btn-primary">Envoyer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modale Facture Manuelle -->
    <div id="manualInvoiceModal" class="modal-overlay">
        <div class="modal" style="max-width: 900px;">
            <div class="modal-header">
                <h2 class="modal-title">Créer une facture manuelle</h2>
                <button class="modal-close" onclick="closeManualInvoiceModal()">&times;</button>
            </div>
            <form id="manualInvoiceForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Client</label>
                        <select id="invoiceClient" required>
                            <option value="">Sélectionner</option>
                            <option>Client A</option>
                            <option>Client B</option>
                            <option>Client C</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Date de facture</label>
                        <input type="date" id="invoiceDate" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label>Période du</label>
                        <input type="date" id="invoicePeriodFrom" required>
                    </div>
                    <div class="form-group">
                        <label>Période au</label>
                        <input type="date" id="invoicePeriodTo" required>
                    </div>
                </div>

                <div class="invoice-lines">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                        <h3 style="font-size: 1rem; font-weight: 600;">Lignes de facture</h3>
                        <button type="button" class="btn btn-secondary" onclick="addInvoiceLine()">+ Ajouter une ligne</button>
                    </div>
                    <div id="invoiceLinesContainer">
                        <!-- Lignes seront ajoutées dynamiquement -->
                    </div>
                </div>

                <div class="invoice-totals">
                    <div class="total-line">
                        <span>Total HT:</span>
                        <span id="totalHT">0,00 €</span>
                    </div>
                    <div class="total-line">
                        <span>TVA:</span>
                        <span id="totalTVA">0,00 €</span>
                    </div>
                    <div class="total-line final">
                        <span>Total TTC:</span>
                        <span id="totalTTC">0,00 €</span>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeManualInvoiceModal()">Annuler</button>
                    <button type="submit" class="btn btn-primary">Enregistrer la facture</button>
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
                <button type="button" class="btn btn-secondary" onclick="closePaymentDetailsModal()">Fermer</button>
            </div>
        </div>
    </div>

    <script>
        // Gestion des onglets
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const targetTab = this.getAttribute('data-tab');
                
                // Retirer active de tous les onglets
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
                
                // Ajouter active à l'onglet cliqué
                this.classList.add('active');
                document.getElementById(targetTab).classList.add('active');
            });
        });

        // Graphique de consommation (simplifié avec Canvas)
        const canvas = document.getElementById('consumptionChart');
        if (canvas) {
            const ctx = canvas.getContext('2d');
            canvas.width = canvas.parentElement.offsetWidth;
            canvas.height = 300;

            // Données fictives
            const months = ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
            const dataNB = [1200, 1350, 1100, 1450, 1600, 1500, 1700, 1650, 1800, 1750, 1900, 2000];
            const dataColor = [300, 350, 280, 400, 450, 420, 500, 480, 520, 510, 550, 600];

            const maxValue = Math.max(...dataNB, ...dataColor);
            const chartHeight = canvas.height - 60;
            const chartWidth = canvas.width - 80;
            const barWidth = chartWidth / months.length - 10;

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
            lineDiv.className = 'invoice-line';
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
            const lines = document.querySelectorAll('.invoice-line');
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
            document.getElementById('prefacturationTable').style.display = 'block';
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
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date</label>
                            <input type="text" value="${payment.date}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Client</label>
                            <input type="text" value="${payment.client}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Mode de paiement</label>
                            <input type="text" value="${payment.mode}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Montant</label>
                            <input type="text" value="${payment.amount}" readonly>
                        </div>
                        <div class="form-group">
                            <label>Factures associées</label>
                            <input type="text" value="${payment.invoices}" readonly>
                        </div>
                        <div class="form-group full-width">
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
                    let html = '<div class="table-container"><table><thead><tr><th></th><th>N° facture</th><th>Date</th><th>Montant restant</th></tr></thead><tbody>';
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
                    invoicesContainer.innerHTML = '<p style="color: var(--text-muted);">Aucune facture en attente</p>';
                }
            } else {
                invoicesContainer.innerHTML = '<p style="color: var(--text-muted);">Sélectionnez un client pour voir ses factures</p>';
            }
        });

        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            alert('Paiement enregistré (fictif)');
            this.reset();
            document.getElementById('clientInvoices').innerHTML = '<p style="color: var(--text-muted);">Sélectionnez un client pour voir ses factures</p>';
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
    </script>
</body>
</html>

