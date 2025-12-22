<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('paiements', []); // Accessible à tous les utilisateurs connectés
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiement - CC Computer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        /* ====== Layout Page Paiement ====== */
        .paiement-page {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1.5rem;
            min-height: calc(100vh - 200px);
        }

        /* ====== Header Section ====== */
        .paiement-header {
            margin-bottom: 2.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .paiement-header-content {
            flex: 1;
        }

        .paiement-header h1 {
            font-size: 2.25rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 0.5rem;
            line-height: 1.2;
        }

        .paiement-header p {
            color: var(--text-secondary);
            margin: 0;
            font-size: 1rem;
        }

        .paiement-header-icon {
            width: 64px;
            height: 64px;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-md);
        }

        .paiement-header-icon svg {
            width: 32px;
            height: 32px;
            fill: white;
        }

        /* ====== Message Container ====== */
        .message-container {
            margin-bottom: 1.5rem;
            animation: slideDown 0.3s ease-out;
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .message {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            animation: fadeIn 0.3s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .message.success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .message.error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .message.warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
        }

        .message-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        /* ====== Main Card ====== */
        .paiement-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-md);
            transition: box-shadow 0.3s ease;
        }

        .paiement-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .paiement-card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0 0 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .paiement-card-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0 0 2rem;
        }

        .paiement-card-divider {
            height: 2px;
            background: linear-gradient(90deg, var(--accent-primary), transparent);
            border: none;
            margin: 0 0 2rem;
        }

        /* ====== Form Layout ====== */
        .paiement-form {
            display: flex;
            flex-direction: column;
            gap: 1.75rem;
        }

        /* ====== Form Groups ====== */
        .paiement-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .paiement-form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .paiement-form-group.full-width {
            grid-column: 1 / -1;
        }

        .paiement-form-group label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .paiement-form-group label .required {
            color: #ef4444;
            font-weight: 700;
        }

        .paiement-form-group input,
        .paiement-form-group select,
        .paiement-form-group textarea {
            padding: 0.875rem 1rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            transition: all 0.2s ease;
            width: 100%;
            font-family: inherit;
        }

        .paiement-form-group input:hover,
        .paiement-form-group select:hover,
        .paiement-form-group textarea:hover {
            border-color: var(--accent-primary);
        }

        .paiement-form-group input:focus,
        .paiement-form-group select:focus,
        .paiement-form-group textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
            background-color: var(--bg-primary);
        }

        .paiement-form-group input:invalid:not(:placeholder-shown),
        .paiement-form-group select:invalid:not(:placeholder-shown) {
            border-color: #ef4444;
        }

        .paiement-form-group textarea {
            min-height: 120px;
            resize: vertical;
            font-family: inherit;
        }

        .paiement-form-group .input-hint {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }

        /* ====== Montant Input Special ====== */
        .montant-wrapper {
            position: relative;
        }

        .montant-wrapper::before {
            content: '€';
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-weight: 600;
            pointer-events: none;
        }

        .montant-wrapper input {
            padding-right: 2.5rem;
        }

        /* ====== Form Actions ====== */
        .paiement-form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border-color);
        }

        .btn {
            padding: 0.875rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            font-family: inherit;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: #fff;
            flex: 1;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            background: var(--text-muted);
            cursor: not-allowed;
            opacity: 0.6;
        }

        .btn-secondary {
            background-color: var(--bg-secondary);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
        }

        .btn-secondary:hover {
            background-color: var(--bg-tertiary);
            border-color: var(--accent-primary);
        }

        .btn-icon {
            width: 18px;
            height: 18px;
        }

        /* ====== Loading State ====== */
        .btn-primary.loading {
            position: relative;
            color: transparent;
        }

        .btn-primary.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        /* ====== Responsive Design ====== */
        @media (max-width: 768px) {
            .paiement-page {
                padding: 1.5rem 1rem;
            }

            .paiement-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .paiement-header h1 {
                font-size: 1.75rem;
            }

            .paiement-header-icon {
                width: 56px;
                height: 56px;
            }

            .paiement-header-icon svg {
                width: 28px;
                height: 28px;
            }

            .paiement-card {
                padding: 1.5rem;
            }

            .paiement-form-row {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }

            .paiement-form-actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .paiement-page {
                padding: 1rem 0.75rem;
            }

            .paiement-card {
                padding: 1.25rem;
            }

            .paiement-form {
                gap: 1.5rem;
            }
        }

        /* ====== Dark Theme Adjustments ====== */
        [data-theme="dark"] .message.success {
            background-color: #064e3b;
            border-color: #059669;
        }

        [data-theme="dark"] .message.error {
            background-color: #7f1d1d;
            border-color: #dc2626;
        }

        [data-theme="dark"] .message.warning {
            background-color: #78350f;
            border-color: #d97706;
        }

        /* ====== Graphique Section ====== */
        .stats-section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
        }

        .stats-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .stats-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .stats-filters {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .stats-filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .stats-filter-group label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .stats-filter-group select {
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            min-width: 150px;
        }

        .stats-filter-group select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .btn-export {
            padding: 0.5rem 1rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s ease;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 1.5rem;
        }

        .chart-loading {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 400px;
            color: var(--text-secondary);
        }

        @media (max-width: 768px) {
            .stats-section {
                padding: 1.5rem;
            }

            .stats-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats-filters {
                width: 100%;
            }

            .stats-filter-group {
                flex: 1;
                min-width: 120px;
            }

            .chart-container {
                height: 300px;
            }
        }

        /* ====== Sections Grid ====== */
        .sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .section-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
        }

        .section-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--accent-primary);
        }

        .section-card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .section-card-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }

        .section-card-icon svg {
            width: 24px;
            height: 24px;
        }

        .section-card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .section-card-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .section-card-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
            line-height: 1.5;
        }

        .section-card-btn {
            padding: 0.75rem 1.25rem;
            background: var(--bg-secondary);
            color: var(--text-primary);
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            width: 100%;
            margin-top: auto;
        }

        .section-card-btn:hover {
            background: var(--accent-primary);
            color: white;
            border-color: var(--accent-primary);
            transform: translateX(4px);
        }

        .section-card-btn svg {
            transition: transform 0.2s ease;
        }

        .section-card-btn:hover svg {
            transform: translateX(2px);
        }

        @media (max-width: 768px) {
            .sections-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }

            .section-card {
                padding: 1.25rem;
            }
        }

        /* ====== Modal Facture ====== */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            display: none;
            flex-direction: column;
            z-index: 1001;
        }

        .modal.active {
            display: flex;
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            background: var(--bg-primary);
            z-index: 10;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--text-secondary);
            padding: 0.5rem;
            line-height: 1;
            transition: color 0.2s;
        }

        .modal-close:hover {
            color: var(--text-primary);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-form-group {
            margin-bottom: 1.5rem;
        }

        .modal-form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
        }

        .modal-form-group input,
        .modal-form-group select,
        .modal-form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            transition: all 0.2s;
        }

        .modal-form-group input:focus,
        .modal-form-group select:focus,
        .modal-form-group textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .modal-form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .facture-lignes-container {
            margin-top: 1.5rem;
        }

        .facture-lignes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .facture-ligne {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .facture-ligne-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
            gap: 1rem;
            align-items: end;
        }

        .facture-ligne-field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .facture-ligne-field label {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .facture-ligne-field input,
        .facture-ligne-field select {
            padding: 0.5rem;
            font-size: 0.95rem;
        }

        .facture-ligne-actions {
            display: flex;
            align-items: center;
        }

        .btn-remove-ligne {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.5rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }

        .btn-remove-ligne:hover {
            background: #dc2626;
        }

        .btn-add-ligne {
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }

        .btn-add-ligne:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .facture-totaux {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 2px solid var(--border-color);
        }

        .facture-totaux-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            font-size: 1.1rem;
        }

        .facture-totaux-row.total {
            font-weight: 700;
            font-size: 1.25rem;
            border-top: 2px solid var(--border-color);
            margin-top: 0.5rem;
            padding-top: 1rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            position: sticky;
            bottom: 0;
            background: var(--bg-primary);
        }

        @media (max-width: 768px) {
            .facture-ligne-row {
                grid-template-columns: 1fr;
                gap: 0.75rem;
            }

            .modal {
                max-width: 100%;
                max-height: 100vh;
                border-radius: 0;
            }
        }
    </style>
</head>
<body>
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
                <div style="display: flex; gap: 1rem; align-items: center;">
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

            <div class="chart-container">
                <div class="chart-loading" id="chartLoading">Chargement des données...</div>
                <canvas id="statsChart" style="display: none;"></canvas>
            </div>
        </div>

        <!-- Sections Grid -->
        <div class="sections-grid">
            <!-- Section Paiements -->
            <div class="section-card" id="sectionPaiements">
                <div class="section-card-header">
                    <div class="section-card-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                            <line x1="1" y1="10" x2="23" y2="10"></line>
                        </svg>
                    </div>
                    <h3 class="section-card-title">Paiements</h3>
                </div>
                <div class="section-card-content">
                    <p class="section-card-description">Consultez et gérez tous les paiements enregistrés</p>
                    <button class="section-card-btn" onclick="openSection('paiements')">
                        Voir les paiements
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Section Factures -->
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
                    <p class="section-card-description">Liste de toutes les factures générées</p>
                    <button class="section-card-btn" onclick="openSection('factures')">
                        Voir les factures
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                    </button>
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
        </div>
    </div>

    <!-- Modal Générer Facture -->
    <div class="modal-overlay" id="factureModalOverlay" onclick="closeFactureModal()"></div>
    <div class="modal" id="factureModal">
        <div class="modal-header">
            <h2 class="modal-title">Générer une facture</h2>
            <button class="modal-close" onclick="closeFactureModal()">&times;</button>
        </div>
        <div class="modal-body">
            <form id="factureForm" onsubmit="submitFactureForm(event)">
                <div class="modal-form-row">
                    <div class="modal-form-group">
                        <label for="factureClient">Client <span style="color: #ef4444;">*</span></label>
                        <select id="factureClient" name="factureClient" required>
                            <option value="">Chargement...</option>
                        </select>
                    </div>
                    <div class="modal-form-group">
                        <label for="factureDate">Date de facture <span style="color: #ef4444;">*</span></label>
                        <input type="date" id="factureDate" name="factureDate" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="modal-form-group">
                        <label for="factureType">Type <span style="color: #ef4444;">*</span></label>
                        <select id="factureType" name="factureType" required>
                            <option value="Consommation">Consommation</option>
                            <option value="Achat">Achat</option>
                            <option value="Service">Service</option>
                        </select>
                    </div>
                </div>

                <div class="modal-form-row">
                    <div class="modal-form-group">
                        <label for="factureDateDebut">Date début période</label>
                        <input type="date" id="factureDateDebut" name="factureDateDebut">
                    </div>
                    <div class="modal-form-group">
                        <label for="factureDateFin">Date fin période</label>
                        <input type="date" id="factureDateFin" name="factureDateFin">
                    </div>
                </div>

                <div class="facture-lignes-container">
                    <div class="facture-lignes-header">
                        <h3 style="margin: 0; font-size: 1.1rem;">Lignes de facture</h3>
                        <button type="button" class="btn-add-ligne" onclick="addFactureLigne()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M12 5v14M5 12h14"></path>
                            </svg>
                            Ajouter une ligne
                        </button>
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

    <script>
        // Variables globales pour le graphique
        let statsChart = null;
        let currentData = null;

        /**
         * Ouvre une section spécifique
         */
        function openSection(section) {
            if (section === 'generer-facture') {
                openFactureModal();
            } else {
                // TODO: Implémenter les autres sections
                console.log('Ouverture de la section:', section);
                alert(`Section "${section}" - À implémenter`);
            }
        }

        /**
         * Ouvre le modal de génération de facture
         */
        function openFactureModal() {
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            if (modal && overlay) {
                modal.classList.add('active');
                overlay.classList.add('active');
                document.body.style.overflow = 'hidden';
                // Charger la liste des clients
                loadClientsForFacture();
            }
        }

        /**
         * Ferme le modal de génération de facture
         */
        function closeFactureModal() {
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            if (modal && overlay) {
                modal.classList.remove('active');
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                document.getElementById('factureForm').reset();
                document.getElementById('factureLignes').innerHTML = '';
                addFactureLigne();
            }
        }

        /**
         * Charge la liste des clients pour le select
         */
        async function loadClientsForFacture() {
            try {
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000');
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    const clientSelect = document.getElementById('factureClient');
                    clientSelect.innerHTML = '<option value="">Sélectionner un client</option>';
                    data.clients.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id;
                        option.textContent = `${client.name} (${client.code})`;
                        clientSelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des clients:', error);
            }
        }

        /**
         * Ajoute une ligne de facture
         */
        function addFactureLigne() {
            const container = document.getElementById('factureLignes');
            const ligneIndex = container.children.length;
            const ligneDiv = document.createElement('div');
            ligneDiv.className = 'facture-ligne';
            ligneDiv.innerHTML = `
                <div class="facture-ligne-row">
                    <div class="facture-ligne-field">
                        <label>Description</label>
                        <input type="text" name="lignes[${ligneIndex}][description]" required placeholder="Description de la ligne">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Type</label>
                        <select name="lignes[${ligneIndex}][type]" required>
                            <option value="N&B">Noir et Blanc</option>
                            <option value="Couleur">Couleur</option>
                            <option value="Service">Service</option>
                            <option value="Produit">Produit</option>
                        </select>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Quantité</label>
                        <input type="number" name="lignes[${ligneIndex}][quantite]" step="0.01" min="0" value="1" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Prix unitaire HT (€)</label>
                        <input type="number" name="lignes[${ligneIndex}][prix_unitaire]" step="0.01" min="0" value="0" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Total HT (€)</label>
                        <input type="number" name="lignes[${ligneIndex}][total_ht]" step="0.01" min="0" value="0" readonly class="ligne-total">
                    </div>
                    <div class="facture-ligne-actions">
                        <button type="button" class="btn-remove-ligne" onclick="removeFactureLigne(this)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6L6 18M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(ligneDiv);
            
            // Ajouter les event listeners pour calculer le total
            const inputs = ligneDiv.querySelectorAll('input[name*="[quantite]"], input[name*="[prix_unitaire]"]');
            inputs.forEach(input => {
                input.addEventListener('input', calculateLigneTotal);
            });
        }

        /**
         * Supprime une ligne de facture
         */
        function removeFactureLigne(btn) {
            if (document.getElementById('factureLignes').children.length > 1) {
                btn.closest('.facture-ligne').remove();
                calculateFactureTotal();
            }
        }

        /**
         * Calcule le total d'une ligne
         */
        function calculateLigneTotal(e) {
            const ligne = e.target.closest('.facture-ligne');
            const quantite = parseFloat(ligne.querySelector('input[name*="[quantite]"]').value) || 0;
            const prixUnitaire = parseFloat(ligne.querySelector('input[name*="[prix_unitaire]"]').value) || 0;
            const total = quantite * prixUnitaire;
            ligne.querySelector('.ligne-total').value = total.toFixed(2);
            calculateFactureTotal();
        }

        /**
         * Calcule le total de la facture
         */
        function calculateFactureTotal() {
            let totalHT = 0;
            document.querySelectorAll('.ligne-total').forEach(input => {
                totalHT += parseFloat(input.value) || 0;
            });
            
            const tauxTVA = 20; // TVA à 20%
            const tva = totalHT * (tauxTVA / 100);
            const totalTTC = totalHT + tva;
            
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
        }

        /**
         * Soumet le formulaire de facture
         */
        async function submitFactureForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('factureForm');
            const formData = new FormData(form);
            const data = {};
            
            // Convertir FormData en objet
            for (let [key, value] of formData.entries()) {
                if (key.startsWith('lignes[')) {
                    const match = key.match(/lignes\[(\d+)\]\[(\w+)\]/);
                    if (match) {
                        const index = parseInt(match[1]);
                        const field = match[2];
                        if (!data.lignes) data.lignes = [];
                        if (!data.lignes[index]) data.lignes[index] = {};
                        data.lignes[index][field] = value;
                    }
                } else {
                    data[key] = value;
                }
            }
            
            // Validation
            if (!data.factureClient) {
                alert('Veuillez sélectionner un client');
                return;
            }
            
            if (!data.lignes || data.lignes.length === 0) {
                alert('Veuillez ajouter au moins une ligne de facture');
                return;
            }
            
            const btnSubmit = document.getElementById('btnGenererFacture');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Génération en cours...';
            
            try {
                const response = await fetch('/API/factures_generer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    alert('Facture générée avec succès !');
                    if (result.pdf_url) {
                        window.open(result.pdf_url, '_blank');
                    }
                    closeFactureModal();
                } else {
                    alert('Erreur : ' + (result.error || 'Erreur inconnue'));
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('Erreur lors de la génération de la facture');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Générer la facture';
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            const messageContainer = document.getElementById('messageContainer');

            // Initialisation de la section statistiques
            initStatsSection();

            // Ajouter la première ligne de facture
            addFactureLigne();

            // Calculer le total initial
            calculateFactureTotal();

            // Fermer le modal avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const modal = document.getElementById('factureModal');
                    if (modal && modal.classList.contains('active')) {
                        closeFactureModal();
                    }
                }
            });

        });

        /**
         * Initialise la section statistiques
         */
        function initStatsSection() {
            // Charger la liste des clients
            loadClients();
            
            // Remplir les années (5 dernières années)
            const yearSelect = document.getElementById('filterAnnee');
            const currentYear = new Date().getFullYear();
            for (let i = currentYear; i >= currentYear - 5; i--) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i;
                yearSelect.appendChild(option);
            }
            
            // Définir l'année en cours par défaut
            yearSelect.value = currentYear;
            
            // Charger les données initiales
            loadStatsData();
            
            // Écouter les changements de filtres
            document.getElementById('filterClient').addEventListener('change', loadStatsData);
            document.getElementById('filterMois').addEventListener('change', loadStatsData);
            document.getElementById('filterAnnee').addEventListener('change', loadStatsData);
            
            // Bouton export Excel
            document.getElementById('btnExportExcel').addEventListener('click', exportToExcel);
        }

        /**
         * Charge la liste des clients
         */
        async function loadClients() {
            try {
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000');
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    const clientSelect = document.getElementById('filterClient');
                    data.clients.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id;
                        option.textContent = client.name;
                        clientSelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des clients:', error);
            }
        }

        /**
         * Charge les données statistiques
         */
        async function loadStatsData() {
            const loadingDiv = document.getElementById('chartLoading');
            const canvas = document.getElementById('statsChart');
            
            loadingDiv.style.display = 'flex';
            canvas.style.display = 'none';
            
            const clientId = document.getElementById('filterClient').value;
            const mois = document.getElementById('filterMois').value;
            const annee = document.getElementById('filterAnnee').value;
            
            const params = new URLSearchParams();
            if (clientId) params.append('client_id', clientId);
            if (mois) params.append('mois', mois);
            if (annee) params.append('annee', annee);
            
            try {
                const response = await fetch(`/API/paiements_get_stats.php?${params.toString()}`);
                const data = await response.json();
                
                if (data.ok && data.data) {
                    currentData = data.data;
                    updateChart(data.data);
                    loadingDiv.style.display = 'none';
                    canvas.style.display = 'block';
                } else {
                    loadingDiv.textContent = 'Aucune donnée disponible';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des statistiques:', error);
                loadingDiv.textContent = 'Erreur lors du chargement des données';
            }
        }

        /**
         * Met à jour le graphique
         */
        function updateChart(data) {
            const ctx = document.getElementById('statsChart').getContext('2d');
            
            // Détruire le graphique existant si présent
            if (statsChart) {
                statsChart.destroy();
            }
            
            // Créer le nouveau graphique
            statsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [
                        {
                            label: 'Noir et Blanc',
                            data: data.noir_blanc,
                            borderColor: '#1f2937',
                            backgroundColor: 'rgba(31, 41, 55, 0.1)',
                            tension: 0.4,
                            fill: false
                        },
                        {
                            label: 'Couleur',
                            data: data.couleur,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            tension: 0.4,
                            fill: false
                        },
                        {
                            label: 'Total Pages',
                            data: data.total_pages,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: false
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        title: {
                            display: true,
                            text: 'Évolution des impressions par mois'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: function(value) {
                                    return value.toLocaleString('fr-FR');
                                }
                            }
                        }
                    }
                }
            });
        }

        /**
         * Exporte les données en Excel
         */
        function exportToExcel() {
            const clientId = document.getElementById('filterClient').value;
            const mois = document.getElementById('filterMois').value;
            const annee = document.getElementById('filterAnnee').value;
            
            const params = new URLSearchParams();
            if (clientId) params.append('client_id', clientId);
            if (mois) params.append('mois', mois);
            if (annee) params.append('annee', annee);
            
            window.location.href = `/API/paiements_export_excel.php?${params.toString()}`;
        }
    </script>
</body>
</html>
