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
            width: 100%;
        }
        
        .chart-container canvas {
            max-height: 100% !important;
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

        /* ====== Filtres Paiements ====== */
        .filter-btn.active {
            background: var(--accent-primary) !important;
            color: white !important;
            border-color: var(--accent-primary) !important;
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
        .modal-form-group textarea,
        .modal-form-group input[type="file"] {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            transition: all 0.2s;
        }
        
        .modal-form-group input[type="file"] {
            padding: 0.5rem;
            cursor: pointer;
        }
        
        .modal-form-group input[type="file"]:hover {
            border-color: var(--accent-primary);
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
        /* ====== Facture Mail Modal Styles ====== */
        .facture-mail-modal {
            max-width: 600px;
        }

        .modal-subtitle {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0.5rem 0 0;
            font-weight: 400;
        }

        .facture-mail-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: var(--radius-md);
            font-size: 0.875rem;
            font-weight: 500;
            margin-top: 0.5rem;
        }

        .status-badge.status-none {
            background: #f3f4f6;
            color: #6b7280;
        }

        .status-badge.status-none .status-badge-icon::before {
            content: "○";
            color: #9ca3af;
        }

        .status-badge.status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-badge.status-pending .status-badge-icon::before {
            content: "⏳";
        }

        .status-badge.status-sent {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.status-sent .status-badge-icon::before {
            content: "✓";
            color: #10b981;
        }

        .status-badge.status-failed {
            background: #fee2e2;
            color: #991b1b;
        }

        .status-badge.status-failed .status-badge-icon::before {
            content: "✗";
            color: #ef4444;
        }

        .status-badge-icon {
            font-size: 1rem;
        }

        .facture-mail-result {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: var(--radius-md);
            animation: slideDown 0.3s ease-out;
        }

        .facture-mail-result.success {
            background: #d1fae5;
            border: 1px solid #6ee7b7;
            color: #065f46;
        }

        .facture-mail-result.error {
            background: #fee2e2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .result-content {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .result-icon {
            font-size: 1.5rem;
            font-weight: bold;
        }

        .result-message {
            font-weight: 600;
            font-size: 1rem;
        }

        .result-details {
            font-size: 0.875rem;
            opacity: 0.8;
            font-family: monospace;
            word-break: break-all;
            cursor: pointer;
            padding: 0.5rem;
            background: rgba(0, 0, 0, 0.05);
            border-radius: var(--radius-sm);
        }

        .result-details:hover {
            background: rgba(0, 0, 0, 0.1);
        }

        .btn-loader {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .spinner {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .required {
            color: #ef4444;
            font-weight: 700;
        }

        /* Toast notifications */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 10000;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-width: 400px;
        }

        .toast {
            padding: 1rem 1.25rem;
            border-radius: var(--radius-md);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInRight 0.3s ease-out;
            font-weight: 500;
        }

        .toast.success {
            background: #10b981;
            color: white;
        }

        .toast.error {
            background: #ef4444;
            color: white;
        }

        .toast-icon {
            font-size: 1.25rem;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes slideOutRight {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }

        .toast.fade-out {
            animation: slideOutRight 0.3s ease-out forwards;
        }

        /* Responsive */
        @media (max-width: 640px) {
            .facture-mail-modal {
                max-width: 95%;
                margin: 1rem;
            }

            .facture-mail-card {
                padding: 1rem;
            }

            .toast-container {
                right: 10px;
                left: 10px;
                max-width: none;
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
                <canvas id="statsChart" style="display: none; width: 100% !important; height: 100% !important;"></canvas>
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

    <!-- Modal Paiements -->
    <div class="modal-overlay" id="paiementsModalOverlay" onclick="closePaiementsModal()">
        <div class="modal" id="paiementsModal" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="modal-title">Gestion des paiements</h2>
                <button class="modal-close" onclick="closePaiementsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="paiementsListLoading" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                    Chargement des factures...
                </div>
                <div id="paiementsListContainer" style="display: none;">
                    <!-- Barre de recherche -->
                    <div style="margin-bottom: 1.5rem;">
                        <div style="position: relative;">
                            <input 
                                type="text" 
                                id="paiementsSearchInput" 
                                placeholder="Rechercher par numéro de facture, client ou date..." 
                                style="width: 100%; padding: 0.75rem 1rem 0.75rem 2.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); font-size: 0.95rem; color: var(--text-primary); background-color: var(--bg-secondary); transition: all 0.2s;"
                                oninput="filterPaiements()"
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
                    
                    <!-- Filtres par statut -->
                    <div style="margin-bottom: 1.5rem; display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="filter-btn active" data-status="all" onclick="filterPaiementsByStatus('all')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--accent-primary); color: white; cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Tous
                        </button>
                        <button class="filter-btn" data-status="payee" onclick="filterPaiementsByStatus('payee')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            Payé
                        </button>
                        <button class="filter-btn" data-status="envoyee" onclick="filterPaiementsByStatus('envoyee')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            En attente
                        </button>
                        <button class="filter-btn" data-status="brouillon" onclick="filterPaiementsByStatus('brouillon')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            En cours
                        </button>
                        <button class="filter-btn" data-status="en_retard" onclick="filterPaiementsByStatus('en_retard')" style="padding: 0.5rem 1rem; border: 2px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-secondary); color: var(--text-primary); cursor: pointer; font-size: 0.9rem; transition: all 0.2s;">
                            En retard
                        </button>
                    </div>
                    
                    <div style="margin-bottom: 1rem; font-weight: 600; color: var(--text-primary); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                        <span><span id="paiementsCount">0</span> facture(s) trouvée(s)</span>
                        <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <span id="paiementsFilteredCount" style="font-size: 0.9rem; color: var(--text-secondary); font-weight: normal;"></span>
                            <button onclick="openHistoriquePaiementsModal()" style="padding: 0.5rem 1rem; background: linear-gradient(135deg, #10b981, #059669); color: white; border: none; border-radius: var(--radius-md); font-weight: 600; cursor: pointer; font-size: 0.9rem; transition: all 0.2s; display: inline-flex; align-items: center; gap: 0.5rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                                </svg>
                                Historique des paiements
                            </button>
                        </div>
                    </div>
                    <div style="overflow-x: auto;">
                        <table style="width: 100%; border-collapse: collapse;">
                            <thead>
                                <tr style="background: var(--bg-secondary); border-bottom: 2px solid var(--border-color);">
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Numéro</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Date</th>
                                    <th style="padding: 0.75rem; text-align: left; font-weight: 600; color: var(--text-primary);">Client</th>
                                    <th style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">Montant TTC</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Statut</th>
                                    <th style="padding: 0.75rem; text-align: center; font-weight: 600; color: var(--text-primary);">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="paiementsListTableBody">
                                <!-- Les factures seront ajoutées ici dynamiquement -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div id="paiementsListError" style="display: none; text-align: center; padding: 2rem; color: #ef4444;">
                    Erreur lors du chargement des factures
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closePaiementsModal()">Fermer</button>
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
                        <select id="factureClient" name="factureClient" required onchange="onFactureClientChange()">
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
                        <label for="factureOffre">Offre <span style="color: #ef4444;">*</span></label>
                        <select id="factureOffre" name="factureOffre" required onchange="onFactureOffreChange()">
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
                        <div class="modal-form-group">
                            <label for="factureMailFacture">Facture <span class="required">*</span></label>
                            <select id="factureMailFacture" name="facture_id" required>
                                <option value="">Chargement des factures...</option>
                            </select>
                            <div class="input-hint">Sélectionnez la facture à envoyer</div>
                            <!-- Badge de statut -->
                            <div id="factureMailStatusBadge" class="status-badge" style="display: none;">
                                <span class="status-badge-icon"></span>
                                <span class="status-badge-text"></span>
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
                    <div class="modal-form-row">
                        <div class="modal-form-group">
                            <label for="genFactureDate">Date de facture <span style="color: #ef4444;">*</span></label>
                            <input type="date" id="genFactureDate" name="date_facture" required value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="modal-form-group">
                            <label for="genFactureType">Type <span style="color: #ef4444;">*</span></label>
                            <select id="genFactureType" name="type" required>
                                <option value="Consommation">Consommation</option>
                                <option value="Achat">Achat</option>
                                <option value="Service">Service</option>
                            </select>
                        </div>
                    </div>

                    <div class="modal-form-row">
                        <div class="modal-form-group">
                            <label for="genFactureDateDebut">Date début période</label>
                            <input type="date" id="genFactureDateDebut" name="date_debut">
                        </div>
                        <div class="modal-form-group">
                            <label for="genFactureDateFin">Date fin période</label>
                            <input type="date" id="genFactureDateFin" name="date_fin">
                        </div>
                    </div>

                    <div class="modal-form-group">
                        <label for="genFactureClients">Sélectionner les clients <span style="color: #ef4444;">*</span></label>
                        <div style="border: 2px solid var(--border-color); border-radius: var(--radius-md); padding: 1rem; max-height: 300px; overflow-y: auto; background: var(--bg-secondary);">
                            <div id="genFactureClientsList" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                <div style="text-align: center; padding: 1rem; color: var(--text-secondary);">
                                    Chargement des clients...
                                </div>
                            </div>
                        </div>
                        <div class="input-hint">Cochez les clients pour lesquels vous souhaitez générer une facture</div>
                    </div>

                    <div class="modal-form-group">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input type="checkbox" id="genFactureEnvoyerEmail" name="envoyer_email" style="width: auto; cursor: pointer;">
                            <span>Envoyer les factures par email automatiquement</span>
                        </label>
                        <div class="input-hint">Si coché, les factures seront envoyées par email aux clients après génération</div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeGenerationFactureClientsModal()">Annuler</button>
                <button type="submit" form="generationFactureClientsForm" class="btn btn-primary" id="btnGenererFacturesClients">Générer les factures</button>
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
                    <div class="modal-form-group">
                        <label for="payerFacture">Facture <span style="color: #ef4444;">*</span></label>
                        <select id="payerFacture" name="facture_id" required>
                            <option value="">Chargement des factures...</option>
                        </select>
                        <div class="input-hint">Sélectionnez la facture à payer</div>
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
                        <input type="text" id="payerReference" name="reference" placeholder="Ex: VIR-2025-001, CHQ-001, etc.">
                        <div class="input-hint">Numéro de chèque, référence de virement, etc.</div>
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
            } else if (section === 'paiements') {
                openPaiementsModal();
            } else if (section === 'payer') {
                openPayerModal();
            } else if (section === 'facture-mail') {
                openFactureMailModal();
            } else if (section === 'generation-facture-clients') {
                openGenerationFactureClientsModal();
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
                }
                document.getElementById('factureLignesContainer').style.display = 'none';
                document.getElementById('factureConsommationInfo').style.display = 'none';
                window.factureMachineData = null;
                
                // Réinitialiser les totaux
                calculateFactureTotal();
                
                // Réinitialiser le champ offre
                document.getElementById('factureOffre').value = '';
                
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
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000', {
                    credentials: 'include'
                });
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
         * Gère le changement de client
         */
        async function onFactureClientChange() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            
            if (clientId && offre) {
                await checkClientPhotocopieurs(clientId, offre);
                await loadConsommationData();
            }
        }

        /**
         * Gère le changement d'offre
         */
        async function onFactureOffreChange() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            
            if (clientId && offre) {
                await checkClientPhotocopieurs(clientId, offre);
                await loadConsommationData();
            }
        }

        /**
         * Vérifie le nombre de photocopieurs pour l'offre 2000
         */
        async function checkClientPhotocopieurs(clientId, offre) {
            if (offre !== '2000') {
                return; // Pas de vérification nécessaire pour l'offre 1000
            }
            
            try {
                const response = await fetch(`/API/factures_check_photocopieurs.php?client_id=${clientId}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (data.nb_photocopieurs !== 2) {
                        alert(`L'offre 2000 nécessite exactement 2 photocopieurs. Ce client a ${data.nb_photocopieurs} photocopieur(s).`);
                        document.getElementById('factureOffre').value = '';
                        return false;
                    }
                } else {
                    alert('Erreur lors de la vérification des photocopieurs: ' + (data.error || 'Erreur inconnue'));
                    return false;
                }
            } catch (error) {
                console.error('Erreur lors de la vérification:', error);
                alert('Erreur lors de la vérification des photocopieurs');
                return false;
            }
            
            return true;
        }

        /**
         * Charge les données de consommation et calcule automatiquement les lignes
         */
        async function loadConsommationData() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            const dateDebut = document.getElementById('factureDateDebut').value;
            const dateFin = document.getElementById('factureDateFin').value;
            
            if (!clientId || !offre) {
                document.getElementById('factureConsommationInfo').style.display = 'none';
                document.getElementById('factureLignesContainer').style.display = 'none';
                return;
            }
            
            if (!dateDebut || !dateFin) {
                document.getElementById('factureConsommationInfo').style.display = 'block';
                document.getElementById('factureConsommationContent').innerHTML = 
                    '<p style="color: var(--text-secondary);">Veuillez sélectionner les dates de début et fin de période</p>';
                document.getElementById('factureLignesContainer').style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`/API/factures_get_consommation.php?client_id=${clientId}&offre=${offre}&date_debut=${dateDebut}&date_fin=${dateFin}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok) {
                    // Afficher les informations de consommation
                    let infoHtml = '<h4 style="margin: 0 0 0.5rem; font-size: 1rem;">Consommations détectées:</h4>';
                    data.machines.forEach((machine, index) => {
                        infoHtml += `<div style="margin-bottom: 0.5rem; padding: 0.5rem; background: var(--bg-primary); border-radius: var(--radius-sm);">
                            <strong>${machine.nom}:</strong> ${machine.conso_nb} copies N&B, ${machine.conso_couleur} copies couleur
                        </div>`;
                    });
                    document.getElementById('factureConsommationContent').innerHTML = infoHtml;
                    document.getElementById('factureConsommationInfo').style.display = 'block';
                    
                    // Générer les lignes de facture automatiquement
                    generateFactureLinesFromConsommation(data);
                } else {
                    document.getElementById('factureConsommationInfo').style.display = 'block';
                    document.getElementById('factureConsommationContent').innerHTML = 
                        '<p style="color: #ef4444;">Erreur: ' + (data.error || 'Impossible de charger les consommations') + '</p>';
                    document.getElementById('factureLignesContainer').style.display = 'none';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des consommations:', error);
                document.getElementById('factureConsommationInfo').style.display = 'block';
                document.getElementById('factureConsommationContent').innerHTML = 
                    '<p style="color: #ef4444;">Erreur lors du chargement des consommations</p>';
                document.getElementById('factureLignesContainer').style.display = 'none';
            }
        }

        /**
         * Génère les lignes de facture depuis les données de consommation
         */
        function generateFactureLinesFromConsommation(data) {
            const container = document.getElementById('factureLignes');
            container.innerHTML = '';
            
            // Préparer les données pour l'API
            const machines = {};
            data.machines.forEach((machine, index) => {
                machines[`machine${index + 1}`] = {
                    conso_nb: machine.conso_nb,
                    conso_couleur: machine.conso_couleur,
                    nom: machine.nom
                };
            });
            
            // Stocker les données pour la soumission
            window.factureMachineData = {
                offre: parseInt(data.offre),
                nb_imprimantes: data.machines.length,
                machines: machines
            };
            
            // Afficher un message indiquant que le calcul sera fait côté serveur
            const infoDiv = document.createElement('div');
            infoDiv.style.padding = '1rem';
            infoDiv.style.background = 'var(--bg-secondary)';
            infoDiv.style.borderRadius = 'var(--radius-md)';
            infoDiv.style.marginBottom = '1rem';
            infoDiv.innerHTML = `
                <p style="margin: 0; color: var(--text-primary);">
                    <strong>${data.machines.length} imprimante(s)</strong> détectée(s). 
                    Les lignes de facture seront calculées automatiquement selon l'offre ${data.offre}.
                </p>
            `;
            container.appendChild(infoDiv);
            
            document.getElementById('factureLignesContainer').style.display = 'block';
            calculateFactureTotal();
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
            
            // Si on a des données de machine (nouveau format), utiliser celui-ci
            if (window.factureMachineData) {
                data.offre = window.factureMachineData.offre;
                data.nb_imprimantes = window.factureMachineData.nb_imprimantes;
                data.machines = window.factureMachineData.machines;
            } else {
                // Ancien format: validation des lignes manuelles
                if (!data.lignes || data.lignes.length === 0) {
                    alert('Veuillez ajouter au moins une ligne de facture ou sélectionner une offre');
                    return;
                }
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
                    body: JSON.stringify(data),
                    credentials: 'include'
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
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
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
                    
                    // Bouton Action PDF
                    let actionButtons = '<span style="color: var(--text-muted); font-size: 0.85rem;">N/A</span>';
                    if (facture.pdf_path) {
                        actionButtons = `
                            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                                <button onclick="viewFacturePDFById(${facture.id}, '${facture.numero}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                    </svg>
                                    PDF
                                </button>
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
         * Ouvre le PDF d'une facture par son ID (avec régénération si nécessaire)
         */
        function viewFacturePDFById(factureId, factureNumero) {
            // Utiliser le script PHP qui gère la régénération si nécessaire
            const pdfUrl = `/public/view_facture.php?id=${factureId}`;
            window.open(pdfUrl, '_blank');
        }

        /**
         * Ouvre le PDF d'une facture par son ID (avec régénération si nécessaire)
         */
        function viewFacturePDFById(factureId, factureNumero) {
            // Utiliser le script PHP qui gère la régénération si nécessaire
            const pdfUrl = `/public/view_facture.php?id=${factureId}`;
            window.open(pdfUrl, '_blank');
        }

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

        // ============================================
        // GESTION DES PAIEMENTS
        // ============================================
        
        let allPaiements = [];
        let filteredPaiements = [];
        let currentPaiementStatusFilter = 'all';

        /**
         * Ouvre le modal des paiements
         */
        function openPaiementsModal() {
            const modal = document.getElementById('paiementsModal');
            const overlay = document.getElementById('paiementsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal paiements introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Charger les factures
            loadPaiementsList();
        }

        /**
         * Ferme le modal des paiements
         */
        function closePaiementsModal() {
            const modal = document.getElementById('paiementsModal');
            const overlay = document.getElementById('paiementsModalOverlay');
            
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        /**
         * Charge la liste des factures pour les paiements
         */
        async function loadPaiementsList() {
            const loadingDiv = document.getElementById('paiementsListLoading');
            const container = document.getElementById('paiementsListContainer');
            const errorDiv = document.getElementById('paiementsListError');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/API/factures_liste.php');
                const result = await response.json();
                
                if (result.ok && result.factures) {
                    allPaiements = result.factures;
                    filteredPaiements = [...allPaiements];
                    displayPaiements(allPaiements);
                    
                    // Mettre à jour le compteur
                    document.getElementById('paiementsCount').textContent = allPaiements.length;
                    
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = result.error || 'Erreur lors du chargement des factures';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des paiements:', error);
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Erreur lors du chargement des factures';
            }
        }

        /**
         * Affiche les factures dans le tableau des paiements
         */
        function displayPaiements(factures) {
            const tableBody = document.getElementById('paiementsListTableBody');
            const filteredCountSpan = document.getElementById('paiementsFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture trouvée
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                filteredCountSpan.textContent = `${factures.length} affichée(s)`;
                
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Couleurs et labels pour les statuts
                    const statutColors = {
                        'brouillon': '#6b7280',
                        'envoyee': '#3b82f6',
                        'payee': '#10b981',
                        'en_retard': '#ef4444',
                        'annulee': '#9ca3af'
                    };
                    const statutLabels = {
                        'brouillon': 'En cours',
                        'envoyee': 'En attente',
                        'payee': 'Payé',
                        'en_retard': 'En retard',
                        'annulee': 'Annulée'
                    };
                    const currentStatutColor = statutColors[facture.statut] || '#6b7280';
                    const currentStatutLabel = statutLabels[facture.statut] || facture.statut;
                    
                    // Badge de statut en lecture seule (pas de modification possible)
                    const statutBadge = `
                        <span style="display: inline-block; padding: 0.5rem 1rem; border-radius: var(--radius-md); background: ${currentStatutColor}20; color: ${currentStatutColor}; font-size: 0.9rem; font-weight: 600; border: 1px solid ${currentStatutColor}40;">
                            ${currentStatutLabel}
                        </span>
                    `;
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; color: var(--text-primary); font-weight: 600;">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">
                            ${facture.client_nom || 'Client inconnu'}
                            ${facture.client_code ? ` (${facture.client_code})` : ''}
                        </td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary); font-weight: 600;">
                            ${facture.montant_ttc.toFixed(2).replace('.', ',')} €
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${statutBadge}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${facture.pdf_path ? `
                                <button onclick="viewFacturePDFById(${facture.id}, '${facture.numero}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    PDF
                                </button>
                            ` : '<span style="color: var(--text-muted); font-size: 0.85rem;">N/A</span>'}
                        </td>
                    `;
                    
                    tableBody.appendChild(row);
                });
            }
        }

        /**
         * Filtre les paiements selon le terme de recherche
         */
        function filterPaiements() {
            const searchInput = document.getElementById('paiementsSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            let filtered = allPaiements;
            
            // Filtrer par statut
            if (currentPaiementStatusFilter !== 'all') {
                filtered = filtered.filter(f => f.statut === currentPaiementStatusFilter);
            }
            
            // Filtrer par recherche textuelle
            if (searchTerm) {
                filtered = filtered.filter(facture => {
                    if (facture.numero && facture.numero.toLowerCase().includes(searchTerm)) return true;
                    if (facture.date_facture_formatted && facture.date_facture_formatted.includes(searchTerm)) return true;
                    if (facture.client_nom && facture.client_nom.toLowerCase().includes(searchTerm)) return true;
                    if (facture.client_code && facture.client_code.toLowerCase().includes(searchTerm)) return true;
                    return false;
                });
            }
            
            filteredPaiements = filtered;
            displayPaiements(filtered);
        }

        /**
         * Filtre les paiements par statut
         */
        function filterPaiementsByStatus(status) {
            currentPaiementStatusFilter = status;
            
            // Mettre à jour les boutons de filtre
            document.querySelectorAll('.filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.status === status) {
                    btn.classList.add('active');
                    btn.style.background = 'var(--accent-primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--accent-primary)';
                } else {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.color = 'var(--text-primary)';
                    btn.style.borderColor = 'var(--border-color)';
                }
            });
            
            filterPaiements();
        }

        /**
         * Met à jour le statut de paiement d'une facture
         */
        async function updatePaiementStatut(factureId, newStatut, factureNumero, selectElement) {
            const statutLabels = {
                'brouillon': 'En cours',
                'envoyee': 'En attente',
                'payee': 'Payé',
                'en_retard': 'En retard',
                'annulee': 'Annulée'
            };
            const newStatutLabel = statutLabels[newStatut] || newStatut;
            
            // Sauvegarder l'ancienne valeur au cas où l'utilisateur annule
            const oldValue = selectElement ? selectElement.getAttribute('data-old-value') || selectElement.value : null;
            
            if (!confirm(`Voulez-vous vraiment changer le statut de la facture ${factureNumero} en "${newStatutLabel}" ?`)) {
                // Remettre l'ancienne valeur si l'utilisateur annule
                if (selectElement && oldValue) {
                    selectElement.value = oldValue;
                }
                return;
            }
            
            // Sauvegarder la valeur actuelle
            if (selectElement) {
                selectElement.setAttribute('data-old-value', newStatut);
                selectElement.disabled = true;
                selectElement.style.opacity = '0.6';
                selectElement.style.cursor = 'wait';
            }
            
            try {
                const response = await fetch('/API/factures_update_statut.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        facture_id: factureId,
                        statut: newStatut
                    }),
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    // Afficher un message de succès
                    showMessage('Statut mis à jour avec succès', 'success');
                    // Recharger la liste
                    loadPaiementsList();
                } else {
                    showMessage('Erreur : ' + (result.error || 'Impossible de mettre à jour le statut'), 'error');
                    // Remettre l'ancienne valeur en cas d'erreur
                    if (selectElement && oldValue) {
                        selectElement.value = oldValue;
                    }
                    // Réactiver le select
                    if (selectElement) {
                        selectElement.disabled = false;
                        selectElement.style.opacity = '1';
                        selectElement.style.cursor = 'pointer';
                    }
                }
            } catch (error) {
                console.error('Erreur lors de la mise à jour du statut:', error);
                showMessage('Erreur lors de la mise à jour du statut', 'error');
                // Remettre l'ancienne valeur en cas d'erreur
                if (selectElement && oldValue) {
                    selectElement.value = oldValue;
                }
                // Réactiver le select
                if (selectElement) {
                    selectElement.disabled = false;
                    selectElement.style.opacity = '1';
                    selectElement.style.cursor = 'pointer';
                }
            }
        }
        
        /**
         * Affiche un message à l'utilisateur
         */
        function showMessage(message, type = 'success') {
            const messageContainer = document.getElementById('messageContainer');
            if (!messageContainer) return;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            
            const icon = type === 'success' ? '✓' : type === 'error' ? '✕' : '⚠';
            messageDiv.innerHTML = `
                <span class="message-icon">${icon}</span>
                <span>${message}</span>
            `;
            
            messageContainer.innerHTML = '';
            messageContainer.appendChild(messageDiv);
            
            // Masquer le message après 5 secondes
            setTimeout(() => {
                messageDiv.style.opacity = '0';
                messageDiv.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    messageContainer.innerHTML = '';
                }, 300);
            }, 5000);
        }

        // ============================================
        // GESTION DE L'HISTORIQUE DES PAIEMENTS
        // ============================================
        
        let allHistoriquePaiements = [];
        let filteredHistoriquePaiements = [];
        let currentHistoriqueStatusFilter = 'all';
        
        /**
         * Ouvre le modal d'historique des paiements
         */
        function openHistoriquePaiementsModal() {
            const modal = document.getElementById('historiquePaiementsModal');
            const overlay = document.getElementById('historiquePaiementsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal historique paiements introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser la recherche
            const searchInput = document.getElementById('historiquePaiementsSearchInput');
            if (searchInput) {
                searchInput.value = '';
            }
            
            // Charger l'historique
            loadHistoriquePaiements();
        }

        /**
         * Ferme le modal d'historique des paiements
         */
        function closeHistoriquePaiementsModal() {
            const modal = document.getElementById('historiquePaiementsModal');
            const overlay = document.getElementById('historiquePaiementsModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        /**
         * Charge l'historique des paiements
         */
        async function loadHistoriquePaiements() {
            const loadingDiv = document.getElementById('historiquePaiementsLoading');
            const container = document.getElementById('historiquePaiementsContainer');
            const errorDiv = document.getElementById('historiquePaiementsError');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/API/paiements_historique.php', {
                    credentials: 'include'
                });
                const result = await response.json();
                
                if (result.ok && result.paiements) {
                    allHistoriquePaiements = result.paiements;
                    filteredHistoriquePaiements = [...allHistoriquePaiements];
                    displayHistoriquePaiements(allHistoriquePaiements);
                    
                    // Mettre à jour le compteur
                    document.getElementById('historiquePaiementsCount').textContent = allHistoriquePaiements.length;
                    
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = result.error || 'Erreur lors du chargement de l\'historique';
                }
            } catch (error) {
                console.error('Erreur lors du chargement de l\'historique:', error);
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Erreur lors du chargement de l\'historique';
            }
        }

        /**
         * Affiche les paiements dans le tableau
         */
        function displayHistoriquePaiements(paiements) {
            const tableBody = document.getElementById('historiquePaiementsTableBody');
            const filteredCountSpan = document.getElementById('historiquePaiementsFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (paiements.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucun paiement trouvé
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                filteredCountSpan.textContent = `${paiements.length} affiché(s)`;
                
                paiements.forEach(paiement => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Badge de statut
                    const statutBadge = `
                        <span style="display: inline-block; padding: 0.4rem 0.75rem; border-radius: var(--radius-md); background: ${paiement.statut_color}20; color: ${paiement.statut_color}; font-size: 0.85rem; font-weight: 600; border: 1px solid ${paiement.statut_color}40;">
                            ${paiement.statut_label}
                        </span>
                    `;
                    
                    // Actions (justificatif si disponible)
                    let actions = '<span style="color: var(--text-muted); font-size: 0.85rem;">-</span>';
                    if (paiement.recu_path) {
                        actions = `
                            <button onclick="viewJustificatif('${paiement.recu_path}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: inline-block; vertical-align: middle; margin-right: 0.25rem;">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                    <line x1="16" y1="13" x2="8" y2="13"></line>
                                    <line x1="16" y1="17" x2="8" y2="17"></line>
                                </svg>
                                Justificatif
                            </button>
                        `;
                    }
                    
                    // Facture info
                    const factureInfo = paiement.facture_numero 
                        ? `${paiement.facture_numero}${paiement.facture_date_formatted ? ' (' + paiement.facture_date_formatted + ')' : ''}`
                        : '<span style="color: var(--text-muted); font-size: 0.85rem;">Sans facture</span>';
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; color: var(--text-primary);">${paiement.date_paiement_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${factureInfo}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">
                            ${paiement.client_nom || 'Client inconnu'}
                            ${paiement.client_code ? ` (${paiement.client_code})` : ''}
                        </td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary); font-weight: 600;">
                            ${paiement.montant.toFixed(2).replace('.', ',')} €
                        </td>
                        <td style="padding: 0.75rem; text-align: center; color: var(--text-primary);">
                            ${paiement.mode_paiement_label}
                        </td>
                        <td style="padding: 0.75rem; color: var(--text-secondary); font-size: 0.9rem;">
                            ${paiement.reference || '-'}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${statutBadge}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${actions}
                        </td>
                    `;
                    
                    tableBody.appendChild(row);
                });
                
                // Afficher le nombre de résultats filtrés si différent du total
                if (paiements.length !== allHistoriquePaiements.length) {
                    filteredCountSpan.textContent = `(${paiements.length} sur ${allHistoriquePaiements.length})`;
                } else {
                    filteredCountSpan.textContent = '';
                }
            }
        }

        /**
         * Filtre l'historique selon le terme de recherche
         */
        function filterHistoriquePaiements() {
            const searchInput = document.getElementById('historiquePaiementsSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            let filtered = allHistoriquePaiements;
            
            // Filtrer par statut
            if (currentHistoriqueStatusFilter !== 'all') {
                filtered = filtered.filter(p => p.statut === currentHistoriqueStatusFilter);
            }
            
            // Filtrer par recherche textuelle
            if (searchTerm) {
                filtered = filtered.filter(paiement => {
                    if (paiement.facture_numero && paiement.facture_numero.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.date_paiement_formatted && paiement.date_paiement_formatted.includes(searchTerm)) return true;
                    if (paiement.client_nom && paiement.client_nom.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.client_code && paiement.client_code.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.reference && paiement.reference.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.mode_paiement_label && paiement.mode_paiement_label.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.commentaire && paiement.commentaire.toLowerCase().includes(searchTerm)) return true;
                    return false;
                });
            }
            
            filteredHistoriquePaiements = filtered;
            displayHistoriquePaiements(filtered);
        }

        /**
         * Filtre l'historique par statut
         */
        function filterHistoriquePaiementsByStatus(status) {
            currentHistoriqueStatusFilter = status;
            
            // Mettre à jour les boutons de filtre
            document.querySelectorAll('#historiquePaiementsModal .filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === status) {
                    btn.classList.add('active');
                    btn.style.background = 'var(--accent-primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--accent-primary)';
                } else {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.color = 'var(--text-primary)';
                    btn.style.borderColor = 'var(--border-color)';
                }
            });
            
            filterHistoriquePaiements();
        }

        /**
         * Ouvre le justificatif dans un nouvel onglet
         */
        function viewJustificatif(recuPath) {
            if (recuPath) {
                window.open(recuPath, '_blank');
            }
        }

        // ============================================
        // GESTION DU MODAL FACTURE MAIL
        // ============================================
        
        /**
         * Ouvre le modal d'envoi de facture par email
         */
        function openFactureMailModal() {
            const modal = document.getElementById('factureMailModal');
            const overlay = document.getElementById('factureMailModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal facture mail introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire et l'état
            const form = document.getElementById('factureMailForm');
            if (form) {
                form.reset();
            }
            
            factureMailState.isSubmitting = false;
            factureMailState.selectedFacture = null;
            hideFactureMailStatus();
            hideFactureMailResult();
            setFactureMailLoadingState(false);
            
            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
            if (btnRenvoyer) {
                btnRenvoyer.style.display = 'none';
            }
            
            // Charger les factures
            loadFacturesForMail();
        }

        /**
         * Ferme le modal d'envoi de facture par email
         */
        function closeFactureMailModal() {
            const modal = document.getElementById('factureMailModal');
            const overlay = document.getElementById('factureMailModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                const form = document.getElementById('factureMailForm');
                if (form) {
                    form.reset();
                }
            }
        }

        // État global pour l'envoi d'email
        let factureMailState = {
            isSubmitting: false,
            selectedFacture: null
        };

        /**
         * Charge les factures pour l'envoi par email
         */
        async function loadFacturesForMail() {
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    const factureSelect = document.getElementById('factureMailFacture');
                    factureSelect.innerHTML = '<option value="">Sélectionner une facture</option>';
                    
                    // Filtrer les factures qui ont un PDF
                    const facturesAvecPDF = data.factures.filter(f => f.pdf_path);
                    
                    facturesAvecPDF.forEach(facture => {
                        const option = document.createElement('option');
                        option.value = facture.id;
                        option.textContent = `${facture.numero} - ${facture.client_nom} - ${facture.montant_ttc.toFixed(2)} € TTC`;
                        option.setAttribute('data-email', facture.client_email || '');
                        option.setAttribute('data-numero', facture.numero);
                        option.setAttribute('data-email-envoye', facture.email_envoye || '0');
                        option.setAttribute('data-date-envoi', facture.date_envoi_email || '');
                        factureSelect.appendChild(option);
                    });
                    
                    // Écouter le changement pour pré-remplir l'email, le sujet et afficher le statut
                    factureSelect.addEventListener('change', function() {
                        const selectedOption = this.options[this.selectedIndex];
                        if (selectedOption && selectedOption.value) {
                            const email = selectedOption.getAttribute('data-email') || '';
                            const numero = selectedOption.getAttribute('data-numero') || '';
                            const emailEnvoye = selectedOption.getAttribute('data-email-envoye') || '0';
                            const dateEnvoi = selectedOption.getAttribute('data-date-envoi') || '';
                            
                            factureMailState.selectedFacture = {
                                id: selectedOption.value,
                                numero: numero,
                                emailEnvoye: parseInt(emailEnvoye),
                                dateEnvoi: dateEnvoi
                            };
                            
                            const emailInput = document.getElementById('factureMailEmail');
                            if (emailInput) {
                                emailInput.value = email;
                            }
                            
                            const sujetInput = document.getElementById('factureMailSujet');
                            if (sujetInput) {
                                sujetInput.value = `Facture ${numero} - CC Computer`;
                            }
                            
                            // Afficher le badge de statut
                            updateFactureMailStatus(emailEnvoye, dateEnvoi);
                            
                            // Gérer le bouton Renvoyer
                            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
                            if (btnRenvoyer) {
                                if (emailEnvoye === '1') {
                                    btnRenvoyer.style.display = 'inline-block';
                                    btnRenvoyer.disabled = false;
                                } else {
                                    btnRenvoyer.style.display = 'none';
                                    btnRenvoyer.disabled = true;
                                }
                            }
                        } else {
                            factureMailState.selectedFacture = null;
                            hideFactureMailStatus();
                            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
                            if (btnRenvoyer) {
                                btnRenvoyer.style.display = 'none';
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des factures:', error);
                showToast('Erreur lors du chargement des factures', 'error');
            }
        }

        /**
         * Met à jour le badge de statut de la facture
         */
        function updateFactureMailStatus(emailEnvoye, dateEnvoi) {
            const badge = document.getElementById('factureMailStatusBadge');
            if (!badge) return;
            
            badge.style.display = 'inline-flex';
            const badgeText = badge.querySelector('.status-badge-text');
            const badgeIcon = badge.querySelector('.status-badge-icon');
            
            // Retirer toutes les classes de statut
            badge.classList.remove('status-none', 'status-pending', 'status-sent', 'status-failed');
            
            if (emailEnvoye === '0') {
                badge.classList.add('status-none');
                badgeText.textContent = 'Non envoyée';
            } else if (emailEnvoye === '2') {
                badge.classList.add('status-pending');
                badgeText.textContent = 'En cours d\'envoi';
            } else if (emailEnvoye === '1') {
                badge.classList.add('status-sent');
                if (dateEnvoi) {
                    const date = new Date(dateEnvoi);
                    badgeText.textContent = `Envoyée le ${date.toLocaleDateString('fr-FR')} à ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
                } else {
                    badgeText.textContent = 'Envoyée';
                }
            } else {
                badge.classList.add('status-failed');
                badgeText.textContent = 'Échec';
            }
        }

        /**
         * Cache le badge de statut
         */
        function hideFactureMailStatus() {
            const badge = document.getElementById('factureMailStatusBadge');
            if (badge) {
                badge.style.display = 'none';
            }
        }

        /**
         * Soumet le formulaire d'envoi de facture par email
         */
        async function submitFactureMailForm(e) {
            e.preventDefault();
            
            // Protection contre double clic
            if (factureMailState.isSubmitting) {
                return;
            }
            
            const form = document.getElementById('factureMailForm');
            const formData = new FormData(form);
            
            // Validation
            const factureId = formData.get('facture_id');
            const email = formData.get('email');
            
            if (!factureId) {
                showToast('Veuillez sélectionner une facture', 'error');
                return;
            }
            
            if (!email || !email.includes('@')) {
                showToast('Veuillez saisir une adresse email valide', 'error');
                return;
            }
            
            // Mettre à jour l'état
            factureMailState.isSubmitting = true;
            setFactureMailLoadingState(true);
            
            try {
                const data = {
                    facture_id: parseInt(factureId)
                };
                
                const response = await fetch('/API/factures_envoyer_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data),
                    credentials: 'include'
                });
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    // Succès
                    showFactureMailSuccess(result);
                    showToast('Email envoyé avec succès !', 'success');
                    
                    // Recharger les factures pour mettre à jour le statut
                    setTimeout(() => {
                        loadFacturesForMail();
                    }, 1000);
                } else {
                    // Erreur
                    const errorMsg = result.error || 'Erreur inconnue';
                    showFactureMailError(errorMsg);
                    showToast('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de l\'envoi:', error);
                showFactureMailError('Erreur de connexion : ' + error.message);
                showToast('Erreur lors de l\'envoi de l\'email', 'error');
            } finally {
                factureMailState.isSubmitting = false;
                setFactureMailLoadingState(false);
            }
        }

        /**
         * Renvoie une facture déjà envoyée
         */
        async function renvoyerFactureMail() {
            if (factureMailState.isSubmitting || !factureMailState.selectedFacture) {
                return;
            }
            
            // Utiliser la même fonction mais avec force=true (géré côté backend)
            await submitFactureMailForm(new Event('submit'));
        }

        /**
         * Met à jour l'état de chargement de l'UI
         */
        function setFactureMailLoadingState(loading) {
            const btnSubmit = document.getElementById('btnEnvoyerFactureMail');
            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
            const btnText = btnSubmit.querySelector('.btn-text');
            const btnLoader = btnSubmit.querySelector('.btn-loader');
            const form = document.getElementById('factureMailForm');
            
            if (loading) {
                btnSubmit.disabled = true;
                if (btnRenvoyer) btnRenvoyer.disabled = true;
                if (btnText) btnText.style.display = 'none';
                if (btnLoader) btnLoader.style.display = 'inline-flex';
                if (form) {
                    const inputs = form.querySelectorAll('input, select, textarea, button');
                    inputs.forEach(input => {
                        if (input !== btnSubmit && input !== btnRenvoyer) {
                            input.disabled = true;
                        }
                    });
                }
            } else {
                btnSubmit.disabled = false;
                if (btnRenvoyer) btnRenvoyer.disabled = factureMailState.selectedFacture?.emailEnvoye !== 1;
                if (btnText) btnText.style.display = 'inline';
                if (btnLoader) btnLoader.style.display = 'none';
                if (form) {
                    const inputs = form.querySelectorAll('input, select, textarea');
                    inputs.forEach(input => {
                        input.disabled = false;
                    });
                }
            }
        }

        /**
         * Affiche le résultat de succès
         */
        function showFactureMailSuccess(result) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result success';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = '✓';
            if (message) message.textContent = 'Email envoyé avec succès !';
            
            let detailsText = '';
            if (result.message_id) {
                detailsText += `Message-ID: ${result.message_id}`;
            }
            if (result.log_id) {
                if (detailsText) detailsText += '\n';
                detailsText += `Log ID: ${result.log_id}`;
            }
            if (result.email) {
                if (detailsText) detailsText += '\n';
                detailsText += `Destinataire: ${result.email}`;
            }
            
            if (details) {
                details.textContent = detailsText || 'Aucun détail disponible';
                details.onclick = () => {
                    navigator.clipboard.writeText(detailsText).then(() => {
                        showToast('Détails copiés !', 'success');
                    });
                };
            }
        }

        /**
         * Affiche le résultat d'erreur
         */
        function showFactureMailError(errorMsg) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result error';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = '✗';
            if (message) message.textContent = 'Erreur lors de l\'envoi';
            if (details) {
                details.textContent = errorMsg;
                details.onclick = null;
            }
        }

        /**
         * Cache le résultat
         */
        function hideFactureMailResult() {
            const resultDiv = document.getElementById('factureMailResult');
            if (resultDiv) {
                resultDiv.style.display = 'none';
            }
        }

        /**
         * Affiche un toast
         */
        function showToast(message, type = 'success') {
            // Créer le conteneur s'il n'existe pas
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            
            // Créer le toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = document.createElement('span');
            icon.className = 'toast-icon';
            icon.textContent = type === 'success' ? '✓' : '✗';
            
            const text = document.createElement('span');
            text.textContent = message;
            
            toast.appendChild(icon);
            toast.appendChild(text);
            container.appendChild(toast);
            
            // Supprimer après 5 secondes
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => {
                    toast.remove();
                    if (container.children.length === 0) {
                        container.remove();
                    }
                }, 300);
            }, 5000);
        }

        // ============================================
        // GESTION DU MODAL GÉNÉRATION FACTURE CLIENTS
        // ============================================
        
        /**
         * Ouvre le modal de génération de factures pour clients
         */
        function openGenerationFactureClientsModal() {
            const modal = document.getElementById('generationFactureClientsModal');
            const overlay = document.getElementById('generationFactureClientsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal génération facture clients introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire
            const form = document.getElementById('generationFactureClientsForm');
            if (form) {
                form.reset();
                // Réinitialiser la date à aujourd'hui
                const dateInput = document.getElementById('genFactureDate');
                if (dateInput) {
                    dateInput.value = new Date().toISOString().split('T')[0];
                }
            }
            
            // Charger la liste des clients
            loadClientsForGenerationFacture();
        }

        /**
         * Ferme le modal de génération de factures pour clients
         */
        function closeGenerationFactureClientsModal() {
            const modal = document.getElementById('generationFactureClientsModal');
            const overlay = document.getElementById('generationFactureClientsModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                const form = document.getElementById('generationFactureClientsForm');
                if (form) {
                    form.reset();
                }
            }
        }

        /**
         * Charge la liste des clients pour la génération de factures
         */
        async function loadClientsForGenerationFacture() {
            try {
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    const clientsList = document.getElementById('genFactureClientsList');
                    clientsList.innerHTML = '';
                    
                    if (data.clients.length === 0) {
                        clientsList.innerHTML = '<div style="text-align: center; padding: 1rem; color: var(--text-secondary);">Aucun client disponible</div>';
                        return;
                    }
                    
                    data.clients.forEach(client => {
                        const clientDiv = document.createElement('div');
                        clientDiv.style.display = 'flex';
                        clientDiv.style.alignItems = 'center';
                        clientDiv.style.gap = '0.75rem';
                        clientDiv.style.padding = '0.5rem';
                        clientDiv.style.borderRadius = 'var(--radius-md)';
                        clientDiv.style.transition = 'background 0.2s';
                        clientDiv.onmouseenter = function() { this.style.background = 'var(--bg-primary)'; };
                        clientDiv.onmouseleave = function() { this.style.background = ''; };
                        
                        clientDiv.innerHTML = `
                            <input type="checkbox" id="client_${client.id}" name="clients[]" value="${client.id}" style="width: auto; cursor: pointer;">
                            <label for="client_${client.id}" style="flex: 1; cursor: pointer; margin: 0; font-weight: normal;">
                                <strong>${client.name}</strong>
                                ${client.code ? ` <span style="color: var(--text-secondary);">(${client.code})</span>` : ''}
                            </label>
                        `;
                        
                        clientsList.appendChild(clientDiv);
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des clients:', error);
                const clientsList = document.getElementById('genFactureClientsList');
                clientsList.innerHTML = '<div style="text-align: center; padding: 1rem; color: #ef4444;">Erreur lors du chargement des clients</div>';
            }
        }

        /**
         * Soumet le formulaire de génération de factures pour clients
         */
        async function submitGenerationFactureClientsForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('generationFactureClientsForm');
            const formData = new FormData(form);
            
            // Récupérer les clients sélectionnés
            const clientsSelected = formData.getAll('clients[]');
            
            if (clientsSelected.length === 0) {
                alert('Veuillez sélectionner au moins un client');
                return;
            }
            
            const btnSubmit = document.getElementById('btnGenererFacturesClients');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Génération en cours...';
            
            try {
                const data = {
                    date_facture: formData.get('date_facture'),
                    type: formData.get('type'),
                    date_debut: formData.get('date_debut') || null,
                    date_fin: formData.get('date_fin') || null,
                    clients: clientsSelected.map(id => parseInt(id)),
                    envoyer_email: formData.get('envoyer_email') === 'on'
                };
                
                const response = await fetch('/API/factures_generer_clients.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(data),
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        showMessage('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status), 'error');
                    } catch {
                        showMessage('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200), 'error');
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    showMessage(`${result.factures_generees || clientsSelected.length} facture(s) générée(s) avec succès !`, 'success');
                    closeGenerationFactureClientsModal();
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    showMessage('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la génération:', error);
                showMessage('Erreur lors de la génération des factures: ' + error.message, 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Générer les factures';
            }
        }

        // ============================================
        // GESTION DU MODAL PAYER
        // ============================================
        
        /**
         * Ouvre le modal de paiement
         */
        function openPayerModal() {
            const modal = document.getElementById('payerModal');
            const overlay = document.getElementById('payerModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal payer introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire
            const form = document.getElementById('payerForm');
            if (form) {
                form.reset();
                // Réinitialiser la date à aujourd'hui
                const dateInput = document.getElementById('payerDate');
                if (dateInput) {
                    dateInput.value = new Date().toISOString().split('T')[0];
                }
            }
            
            // Charger les factures non payées
            loadFacturesForPaiement();
        }

        /**
         * Ferme le modal de paiement
         */
        function closePayerModal() {
            const modal = document.getElementById('payerModal');
            const overlay = document.getElementById('payerModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                const form = document.getElementById('payerForm');
                if (form) {
                    form.reset();
                }
            }
        }

        /**
         * Charge les factures pour le select de paiement
         */
        async function loadFacturesForPaiement() {
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    const factureSelect = document.getElementById('payerFacture');
                    factureSelect.innerHTML = '<option value="">Sélectionner une facture</option>';
                    
                    // Filtrer les factures non payées et non annulées
                    const facturesDisponibles = data.factures.filter(f => 
                        f.statut !== 'payee' && f.statut !== 'annulee'
                    );
                    
                    facturesDisponibles.forEach(facture => {
                        const option = document.createElement('option');
                        option.value = facture.id;
                        option.textContent = `${facture.numero} - ${facture.client_nom} - ${facture.montant_ttc.toFixed(2)} € TTC`;
                        option.setAttribute('data-montant', facture.montant_ttc);
                        option.setAttribute('data-client-id', facture.client_id);
                        factureSelect.appendChild(option);
                    });
                    
                    // Écouter le changement pour pré-remplir le montant
                    factureSelect.addEventListener('change', function() {
                        const selectedOption = this.options[this.selectedIndex];
                        if (selectedOption && selectedOption.value) {
                            const montant = parseFloat(selectedOption.getAttribute('data-montant')) || 0;
                            const montantInput = document.getElementById('payerMontant');
                            if (montantInput) {
                                montantInput.value = montant.toFixed(2);
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des factures:', error);
            }
        }

        /**
         * Soumet le formulaire de paiement
         */
        async function submitPayerForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('payerForm');
            const formData = new FormData(form);
            
            // Validation
            const factureId = formData.get('facture_id');
            const montant = parseFloat(formData.get('montant')) || 0;
            const modePaiement = formData.get('mode_paiement');
            
            if (!factureId) {
                alert('Veuillez sélectionner une facture');
                return;
            }
            
            if (montant <= 0) {
                alert('Le montant doit être supérieur à 0');
                return;
            }
            
            const btnSubmit = document.getElementById('btnEnregistrerPaiement');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Enregistrement en cours...';
            
            try {
                const response = await fetch('/API/paiements_enregistrer.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        showMessage('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status), 'error');
                    } catch {
                        showMessage('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200), 'error');
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    showMessage('Paiement enregistré avec succès !', 'success');
                    closePayerModal();
                    // Recharger la liste des paiements si le modal est ouvert
                    if (document.getElementById('paiementsModalOverlay')?.classList.contains('active')) {
                loadPaiementsList();
                    }
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    showMessage('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la requête:', error);
                showMessage('Erreur lors de l\'enregistrement du paiement: ' + error.message, 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Enregistrer le paiement';
            }
        }

        // Exposer les fonctions globalement pour les onclick
        window.openSection = openSection;
        window.openFactureModal = openFactureModal;
        window.closeFactureModal = closeFactureModal;
        window.openFacturesListModal = openFacturesListModal;
        window.closeFacturesListModal = closeFacturesListModal;
        window.viewFacturePDF = viewFacturePDF;
        window.viewFacturePDFById = viewFacturePDFById;
        window.closePDFViewer = closePDFViewer;
        window.openPDFInNewTab = openPDFInNewTab;
        window.filterFactures = filterFactures;
        window.addFactureLigne = addFactureLigne;
        window.removeFactureLigne = removeFactureLigne;
        window.submitFactureForm = submitFactureForm;
        window.openPaiementsModal = openPaiementsModal;
        window.closePaiementsModal = closePaiementsModal;
        window.filterPaiements = filterPaiements;
        window.filterPaiementsByStatus = filterPaiementsByStatus;
        window.updatePaiementStatut = updatePaiementStatut;
        window.openPayerModal = openPayerModal;
        window.closePayerModal = closePayerModal;
        window.submitPayerForm = submitPayerForm;
        window.openHistoriquePaiementsModal = openHistoriquePaiementsModal;
        window.closeHistoriquePaiementsModal = closeHistoriquePaiementsModal;
        window.filterHistoriquePaiements = filterHistoriquePaiements;
        window.filterHistoriquePaiementsByStatus = filterHistoriquePaiementsByStatus;
        window.viewJustificatif = viewJustificatif;
        window.openFactureMailModal = openFactureMailModal;
        window.closeFactureMailModal = closeFactureMailModal;
        window.submitFactureMailForm = submitFactureMailForm;
        window.openGenerationFactureClientsModal = openGenerationFactureClientsModal;
        window.closeGenerationFactureClientsModal = closeGenerationFactureClientsModal;
        window.submitGenerationFactureClientsForm = submitGenerationFactureClientsForm;

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
                    const paiementsModalOverlay = document.getElementById('paiementsModalOverlay');
                    const pdfViewerModalOverlay = document.getElementById('pdfViewerModalOverlay');
                    const payerModalOverlay = document.getElementById('payerModalOverlay');
                    const historiquePaiementsModalOverlay = document.getElementById('historiquePaiementsModalOverlay');
                    const factureMailModalOverlay = document.getElementById('factureMailModalOverlay');
                    const generationFactureClientsModalOverlay = document.getElementById('generationFactureClientsModalOverlay');
                    
                    if (pdfViewerModalOverlay && pdfViewerModalOverlay.classList.contains('active')) {
                        closePDFViewer();
                    } else if (generationFactureClientsModalOverlay && generationFactureClientsModalOverlay.classList.contains('active')) {
                        closeGenerationFactureClientsModal();
                    } else if (factureMailModalOverlay && factureMailModalOverlay.classList.contains('active')) {
                        closeFactureMailModal();
                    } else if (historiquePaiementsModalOverlay && historiquePaiementsModalOverlay.classList.contains('active')) {
                        closeHistoriquePaiementsModal();
                    } else if (payerModalOverlay && payerModalOverlay.classList.contains('active')) {
                        closePayerModal();
                    } else if (paiementsModalOverlay && paiementsModalOverlay.classList.contains('active')) {
                        closePaiementsModal();
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
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000', {
                    credentials: 'include'
                });
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
                const response = await fetch(`/API/paiements_get_stats.php?${params.toString()}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.data) {
                    currentData = data.data;
                    // Vérifier qu'on a des données
                    if (data.data.labels && data.data.labels.length > 0) {
                        updateChart(data.data);
                        loadingDiv.style.display = 'none';
                        canvas.style.display = 'block';
                    } else {
                        loadingDiv.textContent = 'Aucune donnée disponible pour les filtres sélectionnés';
                        canvas.style.display = 'none';
                    }
                } else {
                    const errorMsg = data.error || 'Erreur lors du chargement des données';
                    loadingDiv.textContent = errorMsg;
                    canvas.style.display = 'none';
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

