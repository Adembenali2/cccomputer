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
                <h1>Paiement</h1>
                <p>Enregistrez un nouveau paiement pour un client</p>
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

        <!-- Main Form Card -->
        <div class="paiement-card">
            <h2 class="paiement-card-title">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                    <line x1="1" y1="10" x2="23" y2="10"></line>
                </svg>
                Informations de facturation
            </h2>
            <p class="paiement-card-subtitle">Remplissez les informations ci-dessous pour enregistrer le paiement</p>
            <hr class="paiement-card-divider" />
            
            <form id="paiementForm" class="paiement-form" novalidate>
                <!-- Row 1: Nom et Email -->
                <div class="paiement-form-row">
                    <div class="paiement-form-group">
                        <label for="nom">
                            Nom
                            <span class="required">*</span>
                        </label>
                        <input 
                            type="text" 
                            id="nom" 
                            name="nom" 
                            required 
                            placeholder="Nom complet du client"
                            autocomplete="name"
                        >
                        <span class="input-hint">Nom complet du client</span>
                    </div>

                    <div class="paiement-form-group">
                        <label for="email">
                            Email
                            <span class="required">*</span>
                        </label>
                        <input 
                            type="email" 
                            id="email" 
                            name="email" 
                            required 
                            placeholder="email@exemple.com"
                            autocomplete="email"
                        >
                        <span class="input-hint">Adresse email du client</span>
                    </div>
                </div>

                <!-- Row 2: Référence et Montant -->
                <div class="paiement-form-row">
                    <div class="paiement-form-group">
                        <label for="reference">Référence client</label>
                        <input 
                            type="text" 
                            id="reference" 
                            name="reference" 
                            placeholder="Référence optionnelle"
                            autocomplete="off"
                        >
                        <span class="input-hint">Numéro de référence client (optionnel)</span>
                    </div>

                    <div class="paiement-form-group">
                        <label for="montant">
                            Montant
                            <span class="required">*</span>
                        </label>
                        <div class="montant-wrapper">
                            <input 
                                type="number" 
                                id="montant" 
                                name="montant" 
                                step="0.01" 
                                min="0" 
                                required
                                placeholder="0.00"
                            >
                        </div>
                        <span class="input-hint">Montant du paiement en euros</span>
                    </div>
                </div>

                <!-- Row 3: Commentaire (Full Width) -->
                <div class="paiement-form-group full-width">
                    <label for="commentaire">Commentaire</label>
                    <textarea 
                        id="commentaire" 
                        name="commentaire" 
                        placeholder="Ajoutez un commentaire optionnel sur ce paiement..."
                        rows="4"
                    ></textarea>
                    <span class="input-hint">Commentaire ou notes supplémentaires (optionnel)</span>
                </div>

                <!-- Form Actions -->
                <div class="paiement-form-actions">
                    <button type="button" class="btn btn-secondary" id="btnReset">
                        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"></path>
                            <path d="M21 3v5h-5"></path>
                            <path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"></path>
                            <path d="M3 21v-5h5"></path>
                        </svg>
                        Réinitialiser
                    </button>
                    <button type="submit" class="btn btn-primary" id="btnPayer">
                        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                        Enregistrer le paiement
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Variables globales pour le graphique
        let statsChart = null;
        let currentData = null;

        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('paiementForm');
            const messageContainer = document.getElementById('messageContainer');
            const btnPayer = document.getElementById('btnPayer');
            const btnReset = document.getElementById('btnReset');

            // Initialisation de la section statistiques
            initStatsSection();

            /**
             * Affiche un message à l'utilisateur
             * @param {string} text - Texte du message
             * @param {string} type - Type de message (success, error, warning)
             */
            function showMessage(text, type = 'success') {
                const iconMap = {
                    success: '<svg class="message-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>',
                    error: '<svg class="message-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>',
                    warning: '<svg class="message-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>'
                };

                messageContainer.innerHTML = `
                    <div class="message ${type}">
                        ${iconMap[type] || ''}
                        <span>${text}</span>
                    </div>
                `;

                // Auto-hide après 5 secondes
                setTimeout(() => {
                    const message = messageContainer.querySelector('.message');
                    if (message) {
                        message.style.opacity = '0';
                        message.style.transform = 'translateY(-10px)';
                        message.style.transition = 'all 0.3s ease-out';
                        setTimeout(() => {
                            messageContainer.innerHTML = '';
                        }, 300);
                    }
                }, 5000);
            }

            /**
             * Valide le formulaire
             * @returns {boolean} - True si valide, false sinon
             */
            function validateForm() {
                const nom = document.getElementById('nom').value.trim();
                const email = document.getElementById('email').value.trim();
                const montant = document.getElementById('montant').value;
                
                // Validation nom
                if (!nom) {
                    showMessage('Le nom est requis.', 'error');
                    document.getElementById('nom').focus();
                    return false;
                }

                if (nom.length < 2) {
                    showMessage('Le nom doit contenir au moins 2 caractères.', 'error');
                    document.getElementById('nom').focus();
                    return false;
                }
                
                // Validation email
                if (!email) {
                    showMessage('L\'email est requis.', 'error');
                    document.getElementById('email').focus();
                    return false;
                }
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showMessage('Veuillez entrer un email valide.', 'error');
                    document.getElementById('email').focus();
                    return false;
                }

                // Validation montant
                if (!montant || parseFloat(montant) <= 0) {
                    showMessage('Le montant doit être supérieur à 0.', 'error');
                    document.getElementById('montant').focus();
                    return false;
                }

                if (parseFloat(montant) > 999999.99) {
                    showMessage('Le montant est trop élevé (maximum 999 999,99 €).', 'error');
                    document.getElementById('montant').focus();
                    return false;
                }
                
                return true;
            }

            /**
             * Formate le montant avec 2 décimales
             */
            function formatMontant() {
                const montantInput = document.getElementById('montant');
                montantInput.addEventListener('blur', function() {
                    const value = parseFloat(this.value);
                    if (!isNaN(value) && value >= 0) {
                        this.value = value.toFixed(2);
                    }
                });
            }

            /**
             * Gère la soumission du formulaire
             */
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                if (!validateForm()) {
                    return;
                }

                // Désactiver le bouton et afficher l'état de chargement
                btnPayer.disabled = true;
                btnPayer.classList.add('loading');
                btnPayer.textContent = '';

                // Simulation d'un traitement (à remplacer par un appel API réel)
                setTimeout(() => {
                    // Récupérer les données du formulaire
                    const formData = {
                        nom: document.getElementById('nom').value.trim(),
                        email: document.getElementById('email').value.trim(),
                        reference: document.getElementById('reference').value.trim(),
                        montant: parseFloat(document.getElementById('montant').value),
                        commentaire: document.getElementById('commentaire').value.trim()
                    };

                    // Afficher un message de succès
                    showMessage(
                        `Paiement de ${formData.montant.toFixed(2)} € enregistré avec succès pour ${formData.nom} !`, 
                        'success'
                    );

                    // Réinitialiser le formulaire
                    form.reset();
                    
                    // Réactiver le bouton
                    btnPayer.disabled = false;
                    btnPayer.classList.remove('loading');
                    btnPayer.innerHTML = `
                        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M5 12h14"></path>
                            <path d="M12 5l7 7-7 7"></path>
                        </svg>
                        Enregistrer le paiement
                    `;

                    // Focus sur le premier champ
                    document.getElementById('nom').focus();
                }, 1500);
            });

            /**
             * Gère la réinitialisation du formulaire
             */
            btnReset.addEventListener('click', function() {
                if (confirm('Êtes-vous sûr de vouloir réinitialiser le formulaire ?')) {
                    form.reset();
                    messageContainer.innerHTML = '';
                    document.getElementById('nom').focus();
                    showMessage('Formulaire réinitialisé.', 'warning');
                }
            });

            // Initialiser le formatage du montant
            formatMontant();

            // Focus automatique sur le premier champ
            document.getElementById('nom').focus();
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
