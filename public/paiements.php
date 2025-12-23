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
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal {
            background: var(--bg-primary);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-xl);
            max-width: 1200px;
            width: 95%;
            max-height: 95vh;
            overflow: hidden;
            display: none;
            flex-direction: column;
            z-index: 1001;
            transform: scale(0.9);
            transition: transform 0.3s ease, opacity 0.3s ease;
            opacity: 0;
            position: relative;
        }

        /* Afficher le modal quand l'overlay est actif */
        .modal-overlay.active .modal {
            display: flex !important;
            transform: scale(1);
            opacity: 1;
        }
        
        /* Compatibilité avec l'ancien système */
        .modal.active {
            display: flex !important;
            transform: scale(1);
            opacity: 1;
        }

        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            background: var(--bg-primary);
            z-index: 10;
        }

        .modal-title {
            font-size: 1.25rem;
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
            padding: 1rem 1.5rem;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            max-height: calc(95vh - 140px);
        }

        .modal-form-group {
            margin-bottom: 1rem;
        }

        .modal-form-group label {
            display: block;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.35rem;
            font-size: 0.85rem;
        }

        .modal-form-group input,
        .modal-form-group select,
        .modal-form-group textarea {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
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
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.75rem;
        }

        .facture-lignes-container {
            margin-top: 1rem;
        }

        .facture-lignes-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }

        .facture-ligne {
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 0.75rem;
            margin-bottom: 0.75rem;
        }

        .facture-ligne-row {
            display: grid;
            grid-template-columns: 2fr 1fr 0.8fr 1fr 1fr auto;
            gap: 0.75rem;
            align-items: end;
        }

        .facture-ligne-field {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .facture-ligne-field label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }

        .facture-ligne-field input,
        .facture-ligne-field select {
            padding: 0.4rem 0.5rem;
            font-size: 0.9rem;
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
            padding: 0.5rem 1rem;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: all 0.2s;
        }

        .btn-add-ligne:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .facture-totaux {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 2px solid var(--border-color);
        }

        .facture-totaux-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            font-size: 1rem;
        }

        .facture-totaux-row.total {
            font-weight: 700;
            font-size: 1.1rem;
            border-top: 2px solid var(--border-color);
            margin-top: 0.5rem;
            padding-top: 0.75rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            flex-shrink: 0;
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
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="section-card-btn" onclick="openSection('factures')">
                            Voir les factures
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M5 12h14"></path>
                                <path d="M12 5l7 7-7 7"></path>
                            </svg>
                        </button>
                        <button class="section-card-btn" onclick="runDiagnostic()" style="background: #f59e0b; color: white; border-color: #f59e0b;">
                            🔍 Diagnostic PDF
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

    <!-- Modal Envoyer Email -->
    <div class="modal-overlay" id="sendEmailModalOverlay" onclick="closeSendEmailModal()">
        <div class="modal" id="sendEmailModal" onclick="event.stopPropagation()" style="max-width: 500px;">
            <div class="modal-header">
                <h2 class="modal-title" id="sendEmailTitle">Envoyer la facture par email</h2>
                <button class="modal-close" onclick="closeSendEmailModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="sendEmailForm" onsubmit="sendFactureEmail(event)">
                    <input type="hidden" id="sendEmailFactureId" name="facture_id">
                    <div class="modal-form-group">
                        <label for="sendEmailAddress">Adresse email <span style="color: #ef4444;">*</span></label>
                        <input 
                            type="email" 
                            id="sendEmailAddress" 
                            name="email" 
                            required 
                            placeholder="email@exemple.com"
                            style="width: 100%; padding: 0.75rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 1rem;"
                        />
                        <div class="input-hint" style="margin-top: 0.5rem; font-size: 0.85rem; color: var(--text-secondary);">
                            L'email du client sera mis à jour si vous le modifiez
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeSendEmailModal()">Annuler</button>
                <button type="submit" form="sendEmailForm" class="btn btn-primary" id="btnSendEmail">Envoyer</button>
            </div>
        </div>
    </div>

    <!-- Modal Liste Factures -->
    <div class="modal-overlay" id="facturesListModalOverlay" onclick="closeFacturesListModal()">
        <div class="modal" id="facturesListModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Liste des factures</h2>
                <button class="modal-close" onclick="closeFacturesListModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="facturesListLoading" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    Chargement des factures...
                </div>
                <div id="facturesListContainer" style="display: none;">
                    <!-- Barre de recherche -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="position: relative;">
                            <input 
                                type="text" 
                                id="facturesSearchInput" 
                                placeholder="Rechercher par nom, prénom, raison sociale, numéro de facture ou date..." 
                                style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem; color: var(--text-primary); background-color: var(--bg-secondary); transition: all 0.2s;"
                                oninput="filterFactures()"
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
                    
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center;">
                        <span><span id="facturesCount">0</span> facture(s) trouvée(s)</span>
                        <span id="facturesFilteredCount" style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;"></span>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
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

    <!-- Modal Générer Facture -->
    <div class="modal-overlay" id="factureModalOverlay" onclick="closeFactureModal()">
        <div class="modal" id="factureModal" onclick="event.stopPropagation()">
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
                        <h3 style="margin: 0; font-size: 1rem; font-weight: 600;">Lignes de facture</h3>
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
    </div>

    <script>
        // Variables globales pour le graphique
        let statsChart = null;
        let currentData = null;

        // ============================================
        // FONCTIONS GLOBALES - Doivent être définies en premier
        // ============================================
        
        /**
         * Ouvre une section spécifique
         */
        function openSection(section) {
            console.log('openSection appelé avec:', section);
            if (section === 'generer-facture') {
                openFactureModal();
            } else if (section === 'factures') {
                openFacturesListModal();
            } else {
                console.log('Ouverture de la section:', section);
                alert(`Section "${section}" - À implémenter`);
            }
        }

        /**
         * Ouvre le modal de génération de facture
         */
        function openFactureModal() {
            console.log('Ouverture du modal de facture');
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            
            if (!modal) {
                console.error('Modal factureModal introuvable');
                alert('Erreur: Le modal de facture est introuvable. Vérifiez la console pour plus de détails.');
                return;
            }
            if (!overlay) {
                console.error('Overlay factureModalOverlay introuvable');
                alert('Erreur: L\'overlay du modal est introuvable. Vérifiez la console pour plus de détails.');
                return;
            }
            
            try {
                // Réinitialiser le formulaire
                const form = document.getElementById('factureForm');
                if (form) {
                    form.reset();
                    // Réinitialiser la date à aujourd'hui
                    const dateInput = document.getElementById('factureDate');
                    if (dateInput) {
                        dateInput.value = new Date().toISOString().split('T')[0];
                    }
                }
                
                // Réinitialiser les lignes
                const lignesContainer = document.getElementById('factureLignes');
                if (lignesContainer) {
                    lignesContainer.innerHTML = '';
                    addFactureLigne();
                }
                
                // Réinitialiser les totaux
                calculateFactureTotal();
                
                // Afficher le modal (seulement l'overlay a besoin de la classe active)
                overlay.classList.add('active');
                // Le modal s'affichera automatiquement via le CSS .modal-overlay.active .modal
                document.body.style.overflow = 'hidden';
                
                // Charger la liste des clients
                loadClientsForFacture();
                console.log('Modal ouvert avec succès');
            } catch (error) {
                console.error('Erreur lors de l\'ouverture du modal:', error);
                alert('Erreur lors de l\'ouverture du modal: ' + error.message);
            }
        }

        /**
         * Ferme le modal de génération de facture
         */
        function closeFactureModal() {
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                // Le modal se cachera automatiquement
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                const form = document.getElementById('factureForm');
                if (form) {
                    form.reset();
                }
                const lignes = document.getElementById('factureLignes');
                if (lignes) {
                    lignes.innerHTML = '';
                    addFactureLigne();
                }
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
                console.log('Envoi des données:', data);
                const response = await fetch('/API/factures_generer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        alert('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status));
                    } catch {
                        alert('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200));
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    alert('Facture générée avec succès !');
                    if (result.pdf_url) {
                        window.open(result.pdf_url, '_blank');
                    }
                    closeFactureModal();
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    alert('Erreur : ' + errorMsg);
                }
            } catch (error) {
                console.error('Erreur lors de la requête:', error);
                alert('Erreur lors de la génération de la facture: ' + error.message);
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Générer la facture';
            }
        }

        /**
         * Ouvre le modal de liste des factures
         */
        function openFacturesListModal() {
            const modal = document.getElementById('facturesListModal');
            const overlay = document.getElementById('facturesListModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal facturesListModal introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Charger les factures
            loadFacturesList();
        }

        /**
         * Ferme le modal de liste des factures
         */
        function closeFacturesListModal() {
            const modal = document.getElementById('facturesListModal');
            const overlay = document.getElementById('facturesListModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Variable globale pour stocker toutes les factures
        let allFactures = [];

        /**
         * Charge la liste des factures depuis l'API
         */
        async function loadFacturesList() {
            const loadingDiv = document.getElementById('facturesListLoading');
            const container = document.getElementById('facturesListContainer');
            const errorDiv = document.getElementById('facturesListError');
            const tableBody = document.getElementById('facturesListTableBody');
            const countSpan = document.getElementById('facturesCount');
            const searchInput = document.getElementById('facturesSearchInput');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            // Réinitialiser la recherche
            if (searchInput) {
                searchInput.value = '';
            }
            
            try {
                const response = await fetch('/API/factures_liste.php');
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    // Stocker toutes les factures pour le filtrage
                    allFactures = data.factures;
                    
                    // Afficher toutes les factures initialement
                    displayFactures(allFactures);
                    
                    countSpan.textContent = data.total || data.factures.length;
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    errorDiv.textContent = data.error || 'Erreur lors du chargement des factures';
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des factures:', error);
                errorDiv.textContent = 'Erreur: ' + error.message;
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
            }
        }

        /**
         * Affiche les factures dans le tableau
         */
        function displayFactures(factures) {
            const tableBody = document.getElementById('facturesListTableBody');
            const countSpan = document.getElementById('facturesCount');
            const filteredCountSpan = document.getElementById('facturesFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture trouvée
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Badge de statut
                    const statutColors = {
                        'brouillon': '#6b7280',
                        'envoyee': '#3b82f6',
                        'payee': '#10b981',
                        'en_retard': '#ef4444',
                        'annulee': '#9ca3af'
                    };
                    const statutLabels = {
                        'brouillon': 'Brouillon',
                        'envoyee': 'Envoyée',
                        'payee': 'Payée',
                        'en_retard': 'En retard',
                        'annulee': 'Annulée'
                    };
                    const statutColor = statutColors[facture.statut] || '#6b7280';
                    const statutLabel = statutLabels[facture.statut] || facture.statut;
                    
                    // Boutons Actions (PDF et Envoyer)
                    let actionButtons = '<span style="color: var(--text-muted); font-size: 0.85rem;">N/A</span>';
                    if (facture.pdf_path) {
                        const emailSentBadge = facture.email_envoye 
                            ? `<span style="display: inline-block; padding: 0.2rem 0.5rem; background: #10b98120; color: #10b981; border-radius: var(--radius-md); font-size: 0.75rem; margin-left: 0.5rem;">✓ Envoyée</span>`
                            : '';
                        
                        actionButtons = `
                            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                                <button onclick="window.open('${facture.pdf_path}', '_blank')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                    </svg>
                                    PDF
                                </button>
                                <button onclick="openSendEmailModal(${facture.id}, '${facture.numero}', '${(facture.client_email || '').replace(/'/g, "\\'")}')" style="padding: 0.4rem 0.75rem; background: #10b981; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                        <polyline points="22,6 12,13 2,6"></polyline>
                                    </svg>
                                    Envoyer
                                </button>
                                ${emailSentBadge}
                            </div>
                        `;
                    }
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; font-weight: 600; color: var(--text-primary);">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${facture.client_nom}${facture.client_code ? ' (' + facture.client_code + ')' : ''}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.type}</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.montant_ht.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.tva.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">${facture.montant_ttc.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: var(--radius-md); background: ${statutColor}20; color: ${statutColor}; font-size: 0.85rem; font-weight: 600;">${statutLabel}</span>
                        </td>
                                <td style="padding: 0.75rem; text-align: center;">${actionButtons}</td>
                    `;
                    tableBody.appendChild(row);
                });
                
                // Afficher le nombre de résultats filtrés si différent du total
                if (factures.length !== allFactures.length) {
                    filteredCountSpan.textContent = `(${factures.length} sur ${allFactures.length})`;
                } else {
                    filteredCountSpan.textContent = '';
                }
            }
        }

        // Variable globale pour stocker le chemin du PDF actuel
        let currentPDFPath = '';

        /**
         * Ouvre le modal pour voir le PDF d'une facture
         */
        function viewFacturePDF(pdfPath, factureNumero) {
            const modal = document.getElementById('pdfViewerModal');
            const overlay = document.getElementById('pdfViewerModalOverlay');
            const embed = document.getElementById('pdfViewerEmbed');
            const fallback = document.getElementById('pdfViewerFallback');
            const title = document.getElementById('pdfViewerTitle');
            
            if (!modal || !overlay) {
                console.error('Éléments du modal PDF introuvables');
                // Fallback : ouvrir directement dans un nouvel onglet
                window.open(pdfPath, '_blank');
                return;
            }
            
            currentPDFPath = pdfPath;
            title.textContent = `Facture ${factureNumero}`;
            
            // Essayer d'afficher avec embed
            if (embed) {
                embed.src = pdfPath;
                embed.style.display = 'block';
                if (fallback) {
                    fallback.style.display = 'none';
                }
                
                // Vérifier si le PDF se charge (timeout de 2 secondes)
                setTimeout(function() {
                    // Si l'embed n'a pas chargé, afficher le fallback
                    try {
                        if (embed.offsetHeight === 0 || embed.offsetWidth === 0) {
                            if (fallback) {
                                fallback.style.display = 'block';
                                embed.style.display = 'none';
                            }
                        }
                    } catch (e) {
                        // Si erreur, afficher le fallback
                        if (fallback) {
                            fallback.style.display = 'block';
                            embed.style.display = 'none';
                        }
                    }
                }, 2000);
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        /**
         * Ouvre le PDF dans un nouvel onglet
         */
        function openPDFInNewTab() {
            if (currentPDFPath) {
                window.open(currentPDFPath, '_blank');
            }
        }

        /**
         * Ferme le modal PDF
         */
        function closePDFViewer() {
            const modal = document.getElementById('pdfViewerModal');
            const overlay = document.getElementById('pdfViewerModalOverlay');
            const embed = document.getElementById('pdfViewerEmbed');
            
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Vider l'embed pour libérer la mémoire
                if (embed) {
                    embed.src = '';
                }
                currentPDFPath = '';
            }
        }

        /**
         * Ouvre le modal pour envoyer la facture par email
         */
        function openSendEmailModal(factureId, factureNumero, clientEmail) {
            const modal = document.getElementById('sendEmailModal');
            const overlay = document.getElementById('sendEmailModalOverlay');
            const title = document.getElementById('sendEmailTitle');
            const emailInput = document.getElementById('sendEmailAddress');
            const factureIdInput = document.getElementById('sendEmailFactureId');
            
            if (!modal || !overlay) {
                console.error('Modal sendEmailModal introuvable');
                return;
            }
            
            title.textContent = `Envoyer la facture ${factureNumero}`;
            factureIdInput.value = factureId;
            emailInput.value = clientEmail || '';
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Focus sur le champ email
            setTimeout(() => {
                emailInput.focus();
                if (!clientEmail) {
                    emailInput.select();
                }
            }, 100);
        }

        /**
         * Ferme le modal d'envoi d'email
         */
        function closeSendEmailModal() {
            const modal = document.getElementById('sendEmailModal');
            const overlay = document.getElementById('sendEmailModalOverlay');
            
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                document.getElementById('sendEmailForm').reset();
            }
        }

        /**
         * Envoie la facture par email
         */
        async function sendFactureEmail(e) {
            e.preventDefault();
            
            const form = document.getElementById('sendEmailForm');
            const formData = new FormData(form);
            const data = {
                facture_id: formData.get('facture_id'),
                email: formData.get('email')
            };
            
            const btnSend = document.getElementById('btnSendEmail');
            btnSend.disabled = true;
            btnSend.textContent = 'Envoi en cours...';
            
            try {
                console.log('Envoi email - Données:', data);
                const response = await fetch('/API/factures_envoyer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data)
                });
                
                console.log('Envoi email - Status:', response.status);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        alert('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status));
                    } catch {
                        alert('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200));
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Envoi email - Réponse:', result);
                
                if (result.ok) {
                    alert('Facture envoyée avec succès à ' + data.email);
                    closeSendEmailModal();
                    // Recharger la liste des factures pour mettre à jour le statut
                    loadFacturesList();
                } else {
                    const errorMsg = result.error || 'Erreur lors de l\'envoi de l\'email';
                    console.error('Erreur API:', errorMsg);
                    alert('Erreur : ' + errorMsg);
                }
            } catch (error) {
                console.error('Erreur lors de l\'envoi de l\'email:', error);
                alert('Erreur lors de l\'envoi de l\'email: ' + error.message);
            } finally {
                btnSend.disabled = false;
                btnSend.textContent = 'Envoyer';
            }
        }

        /**
         * Filtre les factures selon le terme de recherche
         */
        function filterFactures() {
            const searchInput = document.getElementById('facturesSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            if (!searchTerm) {
                // Afficher toutes les factures si la recherche est vide
                displayFactures(allFactures);
                return;
            }
            
            // Filtrer les factures
            const filtered = allFactures.filter(facture => {
                // Rechercher dans le numéro de facture
                if (facture.numero && facture.numero.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans la date (format français et format ISO)
                if (facture.date_facture_formatted && facture.date_facture_formatted.includes(searchTerm)) {
                    return true;
                }
                if (facture.date_facture && facture.date_facture.includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le nom du client (raison sociale)
                if (facture.client_nom && facture.client_nom.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le code client
                if (facture.client_code && facture.client_code.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le nom du dirigeant
                if (facture.client_nom_dirigeant && facture.client_nom_dirigeant.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le prénom du dirigeant
                if (facture.client_prenom_dirigeant && facture.client_prenom_dirigeant.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le type
                if (facture.type && facture.type.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                return false;
            });
            
            // Afficher les factures filtrées
            displayFactures(filtered);
        }

        // Exposer les fonctions globalement pour les onclick
        window.openSection = openSection;
        window.openFactureModal = openFactureModal;
        window.closeFactureModal = closeFactureModal;
        window.openFacturesListModal = openFacturesListModal;
        window.closeFacturesListModal = closeFacturesListModal;
        window.viewFacturePDF = viewFacturePDF;
        window.closePDFViewer = closePDFViewer;
        window.openPDFInNewTab = openPDFInNewTab;
        window.openSendEmailModal = openSendEmailModal;
        window.closeSendEmailModal = closeSendEmailModal;
        window.sendFactureEmail = sendFactureEmail;
        window.filterFactures = filterFactures;
        window.addFactureLigne = addFactureLigne;
        window.removeFactureLigne = removeFactureLigne;
        window.submitFactureForm = submitFactureForm;
        
        // Fonction de diagnostic pour les fichiers PDF
        async function runDiagnostic() {
            try {
                console.log('Démarrage du diagnostic...');
                // Utiliser l'API factures_liste avec le paramètre diagnostic
                const apiUrl = '/API/factures_liste.php?diagnostic=1';
                console.log('URL de l\'API:', apiUrl);
                const response = await fetch(apiUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                // Vérifier le type de contenu
                const contentType = response.headers.get('content-type');
                console.log('Content-Type:', contentType);
                console.log('Status:', response.status);
                console.log('URL:', response.url);
                
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Réponse non-JSON reçue:', text.substring(0, 500));
                    
                    // Si c'est du HTML, c'est probablement une redirection
                    if (text.trim().startsWith('<!DOCTYPE') || text.trim().startsWith('<html')) {
                        alert('Erreur: L\'API a été redirigée vers une page HTML. Le fichier API/factures_diagnostic.php n\'est peut-être pas accessible sur le serveur.\n\nVérifiez que le fichier existe et est déployé sur Railway.');
                    } else {
                        alert('Erreur: Le serveur a retourné du contenu non-JSON. Vérifiez la console pour plus de détails.');
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse JSON reçue:', result);
                
                if (result.ok && result.diagnostic) {
                    const diag = result.diagnostic;
                    console.log('=== DIAGNOSTIC PDF ===');
                    console.log('Informations système:', diag.system_info);
                    console.log('Factures analysées:', diag.factures);
                    
                    // Afficher dans une alerte formatée
                    let message = '=== DIAGNOSTIC PDF ===\n\n';
                    message += 'DOCUMENT_ROOT: ' + (diag.system_info.DOCUMENT_ROOT || 'Non défini') + '\n';
                    message += '__DIR__: ' + diag.system_info['__DIR__'] + '\n';
                    message += 'dirname(__DIR__): ' + diag.system_info['dirname(__DIR__)'] + '\n';
                    message += '/app existe: ' + (diag.system_info['/app exists'] ? 'Oui' : 'Non') + '\n';
                    message += '/var/www/html existe: ' + (diag.system_info['/var/www/html exists'] ? 'Oui' : 'Non') + '\n\n';
                    
                    if (diag.factures && diag.factures.length > 0) {
                        message += '=== FACTURES ===\n';
                        diag.factures.forEach((facture, index) => {
                            message += `\n${index + 1}. Facture ${facture.numero} (ID: ${facture.facture_id})\n`;
                            message += `   Chemin DB: ${facture.pdf_path_db}\n`;
                            message += `   Fichier trouvé: ${facture.file_found ? 'OUI' : 'NON'}\n`;
                            if (facture.file_found) {
                                message += `   Chemin réel: ${facture.actual_path}\n`;
                            } else {
                                message += `   Chemins testés:\n`;
                                facture.paths_tested.forEach(path => {
                                    message += `     - ${path.full_path} (existe: ${path.exists ? 'Oui' : 'Non'})\n`;
                                    if (path.files_in_directory && path.files_in_directory.length > 0) {
                                        message += `       Fichiers dans le répertoire: ${path.files_in_directory.join(', ')}\n`;
                                    }
                                });
                            }
                        });
                    } else {
                        message += 'Aucune facture avec PDF trouvée dans la base de données.\n';
                    }
                    
                    alert(message);
                    console.log('Diagnostic complet. Voir la console pour plus de détails.');
                } else if (result.ok) {
                    alert('Diagnostic activé mais aucune information de diagnostic retournée. Vérifiez la console.');
                    console.log('Réponse complète:', result);
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    if (result.trace) {
                        console.error('Trace:', result.trace);
                    }
                    alert('Erreur lors du diagnostic: ' + errorMsg);
                }
            } catch (error) {
                console.error('Erreur diagnostic:', error);
                console.error('Stack trace:', error.stack);
                alert('Erreur lors du diagnostic: ' + error.message + '\n\nVérifiez la console pour plus de détails.');
            }
        }
        
        window.runDiagnostic = runDiagnostic;

        document.addEventListener('DOMContentLoaded', function() {
            const messageContainer = document.getElementById('messageContainer');

            // Initialisation de la section statistiques
            initStatsSection();

            // Ne pas ajouter de ligne au chargement, seulement quand le modal s'ouvre
            // addFactureLigne() sera appelé dans openFactureModal()

            // Fermer les modals avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const factureModal = document.getElementById('factureModal');
                    const factureModalOverlay = document.getElementById('factureModalOverlay');
                    const facturesListModal = document.getElementById('facturesListModal');
                    const facturesListModalOverlay = document.getElementById('facturesListModalOverlay');
                    const pdfViewerModalOverlay = document.getElementById('pdfViewerModalOverlay');
                    const sendEmailModalOverlay = document.getElementById('sendEmailModalOverlay');
                    
                    if (sendEmailModalOverlay && sendEmailModalOverlay.classList.contains('active')) {
                        closeSendEmailModal();
                    } else if (pdfViewerModalOverlay && pdfViewerModalOverlay.classList.contains('active')) {
                        closePDFViewer();
                    } else if (factureModalOverlay && factureModalOverlay.classList.contains('active')) {
                        closeFactureModal();
                    } else if (facturesListModalOverlay && facturesListModalOverlay.classList.contains('active')) {
                        closeFacturesListModal();
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
