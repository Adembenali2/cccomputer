<?php
// /public/paiements.php
// Page de gestion des paiements avec graphique de consommation de papier
// NOUVELLE VERSION : Réorganisation avec statistiques au-dessus, graphique, puis liste des clients avec dettes

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('paiements', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';

// La fonction h() est définie dans includes/helpers.php
// La fonction ensureCsrfToken() est définie dans includes/helpers.php

$csrfToken = ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiements - Consommation de papier</title>
    
    <!-- CSS globaux -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- CSS spécifique à la page paiements -->
    <link rel="stylesheet" href="/assets/css/paiements.css">
    
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="page-paiements">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>
    
    <main class="page-container">
        <header class="page-header">
            <div class="header-content">
                <div>
                    <h1 class="page-title">Paiements - Consommation de papier</h1>
                    <p class="page-sub">Visualisation de la consommation cumulée depuis le premier relevé</p>
                </div>
                <div class="header-actions">
                    <a href="/public/paiements_dettes.php" class="btn-link">
                        💰 Dettes clients
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Filtres modernisés (sans boutons) -->
        <section class="paiements-filters">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filter-period">Période d'agrégation</label>
                    <select id="filter-period" class="filter-select">
                        <option value="day">Par jour</option>
                        <option value="month" selected>Par mois</option>
                        <option value="year">Par année</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter-client">Client</label>
                    <select id="filter-client" class="filter-select">
                        <option value="">Tous les clients</option>
                        <!-- Rempli dynamiquement via JavaScript -->
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter-photocopieur">Photocopieur</label>
                    <select id="filter-photocopieur" class="filter-select">
                        <option value="">Toute la flotte</option>
                        <!-- Rempli dynamiquement via JavaScript -->
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter-date-start">Date début</label>
                    <input type="date" id="filter-date-start" class="filter-input">
                </div>
                
                <div class="filter-group">
                    <label for="filter-date-end">Date fin</label>
                    <input type="date" id="filter-date-end" class="filter-input">
                </div>
            </div>
        </section>
        
        <!-- Statistiques (déplacées au-dessus du graphique) -->
        <section class="paiements-stats">
            <div class="stats-grid">
                <div class="stat-card stat-bw">
                    <div class="stat-icon">📄</div>
                    <div class="stat-content">
                        <div class="stat-label">Noir et blanc</div>
                        <div class="stat-value" id="stat-total-bw">0</div>
                        <div class="stat-unit">pages</div>
                    </div>
                </div>
                
                <div class="stat-card stat-color">
                    <div class="stat-icon">🎨</div>
                    <div class="stat-content">
                        <div class="stat-label">Couleur</div>
                        <div class="stat-value" id="stat-total-color">0</div>
                        <div class="stat-unit">pages</div>
                    </div>
                </div>
                
                <div class="stat-card stat-total">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-label">Total</div>
                        <div class="stat-value" id="stat-total-pages">0</div>
                        <div class="stat-unit">pages</div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Graphique en ligne -->
        <section class="paiements-chart">
            <div class="chart-header">
                <div class="chart-header-left">
                    <h2 class="chart-title">Évolution de la consommation</h2>
                    <div class="chart-legend">
                        <div class="legend-item">
                            <span class="legend-color legend-bw"></span>
                            <span class="legend-label">Noir et blanc</span>
                        </div>
                        <div class="legend-item">
                            <span class="legend-color legend-color"></span>
                            <span class="legend-label">Couleur</span>
                        </div>
                    </div>
                </div>
                <div class="chart-actions">
                    <button id="btn-export-excel" class="btn-export">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                            <polyline points="7 10 12 15 17 10"></polyline>
                            <line x1="12" y1="15" x2="12" y2="3"></line>
                        </svg>
                        Export Excel
                    </button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="consumptionChart"></canvas>
            </div>
        </section>
        
        <!-- Section Périodes de Facturation 20→20 -->
        <section class="paiements-periodes">
            <div class="periodes-header">
                <h2 class="periodes-title">Périodes de Facturation (20→20)</h2>
                <p class="periodes-subtitle">Consommations et dettes par période comptable</p>
                <div class="periodes-filters">
                    <div class="filter-group">
                        <label for="filter-period-month">Mois</label>
                        <select id="filter-period-month" class="filter-select">
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
                    <div class="filter-group">
                        <label for="filter-period-year">Année</label>
                        <input type="number" id="filter-period-year" class="filter-input" min="2020" max="2100" value="<?= date('Y') ?>">
                    </div>
                    <div class="filter-group">
                        <label for="filter-period-client">Client</label>
                        <select id="filter-period-client" class="filter-select">
                            <option value="0">Tous les clients</option>
                            <!-- Rempli dynamiquement -->
                        </select>
                    </div>
                    <div class="filter-group">
                        <button id="btn-load-periodes" class="btn-primary">Charger</button>
                        <button id="btn-export-periodes" class="btn-export">Export Excel</button>
                    </div>
                </div>
            </div>
            
            <div class="periodes-loading" id="periodes-loading" style="display: none;">
                <div class="spinner"></div>
                <p>Chargement des périodes...</p>
            </div>
            
            <div class="periodes-table-container" id="periodes-table-container">
                <table class="periodes-table" id="periodes-table">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th>Période</th>
                            <th>Conso N&B</th>
                            <th>Conso Couleur</th>
                            <th>Montant Total</th>
                            <th>Statut Paiement</th>
                            <th>Montant Restant</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="periodes-table-body">
                        <!-- Rempli dynamiquement -->
                    </tbody>
                </table>
            </div>
        </section>
        
        <!-- Section Clients et Dettes -->
        <section class="paiements-clients">
            <div class="clients-header">
                <h2 class="clients-title">Clients et Dettes</h2>
                <p class="clients-subtitle">Consommation mensuelle, dettes et historique</p>
            </div>
            
            <div class="clients-loading" id="clients-loading" style="display: none;">
                <div class="spinner"></div>
                <p>Chargement des clients...</p>
            </div>
            
            <div class="clients-list" id="clients-list">
                <!-- Rempli dynamiquement via JavaScript -->
            </div>
        </section>
    </main>
    
    <script>
        // Variables globales
        let consumptionChart = null;
        let photocopieursList = [];
        let isLoading = false;
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            initializeFilters();
            setupEventListeners();
            loadPhotocopieurs();
            loadClientsForFilter();
            loadData();
            loadClients();
            loadPeriodes(); // Charger les périodes par défaut
            setupPeriodesEventListeners();
        });
        
        // Initialiser les filtres avec les dates par défaut
        function initializeFilters() {
            const today = new Date();
            const endDate = today.toISOString().split('T')[0];
            
            // Par défaut: 12 derniers mois
            const startDate = new Date(today);
            startDate.setMonth(startDate.getMonth() - 12);
            const startDateStr = startDate.toISOString().split('T')[0];
            
            document.getElementById('filter-date-start').value = startDateStr;
            document.getElementById('filter-date-end').value = endDate;
        }
        
        // Configurer les écouteurs d'événements pour mise à jour automatique
        function setupEventListeners() {
            const periodSelect = document.getElementById('filter-period');
            const clientSelect = document.getElementById('filter-client');
            const photocopieurSelect = document.getElementById('filter-photocopieur');
            const dateStartInput = document.getElementById('filter-date-start');
            const dateEndInput = document.getElementById('filter-date-end');
            const exportBtn = document.getElementById('btn-export-excel');
            
            // Mise à jour automatique lors des changements
            periodSelect.addEventListener('change', () => {
                updateDefaultDates();
                loadData();
            });
            
            clientSelect.addEventListener('change', () => {
                // Mettre à jour la liste des photocopieurs selon le client sélectionné
                updatePhotocopieursForClient();
                loadData();
            });
            
            photocopieurSelect.addEventListener('change', loadData);
            dateStartInput.addEventListener('change', loadData);
            dateEndInput.addEventListener('change', loadData);
            
            // Export Excel
            if (exportBtn) {
                exportBtn.addEventListener('click', exportToExcel);
            }
        }
        
        // Export vers Excel
        function exportToExcel() {
            const period = document.getElementById('filter-period').value;
            const mac = document.getElementById('filter-photocopieur').value;
            const dateStart = document.getElementById('filter-date-start').value;
            const dateEnd = document.getElementById('filter-date-end').value;
            
            if (!dateStart || !dateEnd) {
                showError('Veuillez sélectionner une date de début et une date de fin');
                return;
            }
            
            if (new Date(dateStart) > new Date(dateEnd)) {
                showError('La date de début doit être antérieure à la date de fin');
                return;
            }
            
            // Construire l'URL avec les paramètres
            const params = new URLSearchParams({
                period: period,
                date_start: dateStart,
                date_end: dateEnd
            });
            
            if (mac && mac.trim() !== '') {
                params.append('mac', mac.trim());
            }
            
            // Ouvrir l'URL d'export dans une nouvelle fenêtre pour télécharger le fichier
            window.location.href = '/API/export_paiements_excel.php?' + params.toString();
        }
        
        // Mettre à jour les dates par défaut selon la période
        function updateDefaultDates() {
            const period = document.getElementById('filter-period').value;
            const today = new Date();
            const endDate = today.toISOString().split('T')[0];
            const startDate = new Date(today);
            
            switch (period) {
                case 'day':
                    startDate.setDate(startDate.getDate() - 30);
                    break;
                case 'month':
                    startDate.setMonth(startDate.getMonth() - 12);
                    break;
                case 'year':
                    startDate.setFullYear(startDate.getFullYear() - 5);
                    break;
            }
            
            const startDateStr = startDate.toISOString().split('T')[0];
            document.getElementById('filter-date-start').value = startDateStr;
            document.getElementById('filter-date-end').value = endDate;
        }
        
        // Charger la liste des photocopieurs
        async function loadPhotocopieurs() {
            try {
                const response = await fetch('/API/paiements_data.php?period=month');
                const data = await response.json();
                
                if (data.ok && data.photocopieurs) {
                    photocopieursList = data.photocopieurs;
                    const select = document.getElementById('filter-photocopieur');
                    
                    // Garder l'option "Toute la flotte"
                    const firstOption = select.firstElementChild;
                    select.innerHTML = '';
                    select.appendChild(firstOption);
                    
                    // Ajouter les photocopieurs
                    data.photocopieurs.forEach(p => {
                        const option = document.createElement('option');
                        option.value = p.mac_norm;
                        option.textContent = p.label;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erreur chargement photocopieurs:', error);
            }
        }
        
        // Charger la liste des clients pour le filtre
        async function loadClientsForFilter() {
            try {
                const response = await fetch('/API/paiements_clients.php');
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    // Remplir le filtre principal
                    const select = document.getElementById('filter-client');
                    const firstOption = select.firstElementChild;
                    select.innerHTML = '';
                    select.appendChild(firstOption);
                    
                    // Remplir aussi le filtre de la section périodes
                    const selectPeriodes = document.getElementById('filter-period-client');
                    const firstOptionPeriodes = selectPeriodes ? selectPeriodes.firstElementChild : null;
                    if (selectPeriodes) {
                        selectPeriodes.innerHTML = '';
                        if (firstOptionPeriodes) {
                            selectPeriodes.appendChild(firstOptionPeriodes);
                        }
                    }
                    
                    // Ajouter les clients aux deux filtres
                    data.clients.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id;
                        option.textContent = client.raison_sociale || 'Client #' + (client.numero_client || client.id);
                        select.appendChild(option.cloneNode(true));
                        
                        if (selectPeriodes) {
                            selectPeriodes.appendChild(option);
                        }
                    });
                }
            } catch (error) {
                console.error('Erreur chargement clients:', error);
            }
        }
        
        // Mettre à jour la liste des photocopieurs selon le client sélectionné
        function updatePhotocopieursForClient() {
            const clientId = document.getElementById('filter-client').value;
            const select = document.getElementById('filter-photocopieur');
            const firstOption = select.firstElementChild;
            
            if (!clientId || clientId === '') {
                // Afficher tous les photocopieurs
                select.innerHTML = '';
                select.appendChild(firstOption);
                photocopieursList.forEach(p => {
                    const option = document.createElement('option');
                    option.value = p.mac_norm;
                    option.textContent = p.label;
                    select.appendChild(option);
                });
            } else {
                // Afficher uniquement les photocopieurs du client
                // On aura besoin d'une API pour récupérer les photocopieurs d'un client
                // Pour l'instant, on filtre depuis la liste existante
                select.innerHTML = '';
                select.appendChild(firstOption);
                
                // Filtrer les photocopieurs par client (si l'info est disponible)
                // Sinon, on chargera depuis l'API
                loadPhotocopieursForClient(clientId);
            }
        }
        
        // Charger les photocopieurs d'un client spécifique
        async function loadPhotocopieursForClient(clientId) {
            try {
                const response = await fetch(`/API/clients/get_client_photocopieur.php?client_id=${clientId}`);
                const data = await response.json();
                
                const select = document.getElementById('filter-photocopieur');
                const firstOption = select.firstElementChild;
                select.innerHTML = '';
                select.appendChild(firstOption);
                
                if (data.ok && data.photocopieurs && data.photocopieurs.length > 0) {
                    data.photocopieurs.forEach(p => {
                        const option = document.createElement('option');
                        option.value = p.mac_norm || '';
                        option.textContent = (p.model || 'Inconnu') + ' (' + (p.mac_address || 'N/A') + ')';
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erreur chargement photocopieurs client:', error);
            }
        }
        
        // Charger les données et mettre à jour le graphique
        async function loadData() {
            // Éviter les requêtes multiples simultanées
            if (isLoading) return;
            
            const period = document.getElementById('filter-period').value;
            const clientId = document.getElementById('filter-client').value;
            const mac = document.getElementById('filter-photocopieur').value;
            const dateStart = document.getElementById('filter-date-start').value;
            const dateEnd = document.getElementById('filter-date-end').value;
            
            // Validation des dates
            if (!dateStart || !dateEnd) {
                return;
            }
            
            if (new Date(dateStart) > new Date(dateEnd)) {
                console.warn('La date de début doit être antérieure à la date de fin');
                return;
            }
            
            isLoading = true;
            
            // Afficher un indicateur de chargement
            const chartContainer = document.querySelector('.chart-container');
            if (chartContainer) {
                chartContainer.style.opacity = '0.6';
                chartContainer.style.pointerEvents = 'none';
            }
            
            try {
                const params = new URLSearchParams({
                    period: period,
                    date_start: dateStart,
                    date_end: dateEnd
                });
                
                // Ajouter le filtre client si sélectionné
                if (clientId && clientId.trim() !== '') {
                    params.append('client_id', clientId.trim());
                }
                
                // Ne passer la MAC que si elle est valide (non vide et format correct)
                if (mac && mac.trim() !== '') {
                    params.append('mac', mac.trim());
                }
                
                const response = await fetch('/API/paiements_data.php?' + params.toString());
                const data = await response.json();
                
                if (data.ok) {
                    updateChart(data.data);
                    updateStats(data.data);
                } else {
                    console.error('Erreur API:', data.error);
                    showError('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            } catch (error) {
                console.error('Erreur chargement données:', error);
                showError('Erreur lors du chargement des données');
            } finally {
                isLoading = false;
                if (chartContainer) {
                    chartContainer.style.opacity = '1';
                    chartContainer.style.pointerEvents = 'auto';
                }
            }
        }
        
        // Charger la liste des clients avec consommation et dettes
        async function loadClients() {
            const loadingEl = document.getElementById('clients-loading');
            const clientsListEl = document.getElementById('clients-list');
            
            loadingEl.style.display = 'block';
            clientsListEl.innerHTML = '';
            
            try {
                const response = await fetch('/API/paiements_clients.php');
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    displayClients(data.clients);
                } else {
                    clientsListEl.innerHTML = '<div class="error-message">Erreur: ' + (data.error || 'Erreur inconnue') + '</div>';
                }
            } catch (error) {
                console.error('Erreur chargement clients:', error);
                clientsListEl.innerHTML = '<div class="error-message">Erreur lors du chargement des clients</div>';
            } finally {
                loadingEl.style.display = 'none';
            }
        }
        
        // Afficher les clients
        function displayClients(clients) {
            const clientsListEl = document.getElementById('clients-list');
            
            if (!clients || clients.length === 0) {
                clientsListEl.innerHTML = '<div class="no-data">Aucun client trouvé.</div>';
                return;
            }
            
            let html = '';
            
            clients.forEach(client => {
                const consommationBw = client.consumption_bw || 0;
                const consommationColor = client.consumption_color || 0;
                const dette = client.debt || 0;
                const solde = client.balance || 0;
                
                html += `
                    <div class="client-card">
                        <div class="client-header">
                            <div class="client-info">
                                <h3 class="client-name">${escapeHtml(client.raison_sociale || 'Client sans nom')}</h3>
                                <p class="client-number">Client #${escapeHtml(client.numero_client || 'N/A')}</p>
                            </div>
                            <div class="client-summary">
                                <div class="summary-item">
                                    <span class="summary-label">Consommation N&B</span>
                                    <span class="summary-value">${formatNumber(consommationBw)} pages</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Consommation Couleur</span>
                                    <span class="summary-value">${formatNumber(consommationColor)} pages</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Dette</span>
                                    <span class="summary-value summary-debt">${formatMoney(dette)} €</span>
                                </div>
                                <div class="summary-item">
                                    <span class="summary-label">Solde</span>
                                    <span class="summary-value ${solde >= 0 ? 'summary-positive' : 'summary-negative'}">${formatMoney(solde)} €</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="client-history">
                            <h4 class="history-title">Historique</h4>
                            <div class="history-list" id="history-${client.id}">
                                ${renderHistory(client.history || [])}
                            </div>
                        </div>
                    </div>
                `;
            });
            
            clientsListEl.innerHTML = html;
        }
        
        // Afficher l'historique
        function renderHistory(history) {
            if (!history || history.length === 0) {
                return '<div class="no-history">Aucun historique disponible</div>';
            }
            
            return history.map(period => {
                const factureLink = period.facture_url ? 
                    `<a href="${escapeHtml(period.facture_url)}" target="_blank" class="facture-link">📄 Voir facture</a>` : 
                    '<span class="facture-link disabled">Facture non disponible</span>';
                
                return `
                    <div class="history-item">
                        <div class="history-period">
                            <strong>${escapeHtml(period.period_label || 'Période inconnue')}</strong>
                            ${factureLink}
                        </div>
                        <div class="history-details">
                            <div class="history-detail">
                                <span class="detail-label">N&B:</span>
                                <span class="detail-value">${formatNumber(period.consumption_bw || 0)} pages</span>
                            </div>
                            <div class="history-detail">
                                <span class="detail-label">Couleur:</span>
                                <span class="detail-value">${formatNumber(period.consumption_color || 0)} pages</span>
                            </div>
                            <div class="history-detail">
                                <span class="detail-label">Dette:</span>
                                <span class="detail-value">${formatMoney(period.debt || 0)} €</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }
        
        // Afficher une erreur
        function showError(message) {
            // Créer ou mettre à jour un message d'erreur
            let errorDiv = document.getElementById('error-message');
            if (!errorDiv) {
                errorDiv = document.createElement('div');
                errorDiv.id = 'error-message';
                errorDiv.className = 'error-message';
                document.querySelector('.paiements-chart').insertBefore(errorDiv, document.querySelector('.chart-container'));
            }
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
            
            setTimeout(() => {
                errorDiv.style.display = 'none';
            }, 5000);
        }
        
        // Mettre à jour le graphique (graphique en ligne)
        function updateChart(data) {
            const canvas = document.getElementById('consumptionChart');
            if (!canvas) {
                console.error('Canvas consumptionChart introuvable');
                return;
            }
            const ctx = canvas.getContext('2d');
            
            // S'assurer que les données sont bien définies
            if (!data || !data.labels || !data.bw || !data.color) {
                console.error('Données invalides pour le graphique:', data);
                // Initialiser avec des données vides
                data = {
                    labels: [],
                    bw: [],
                    color: []
                };
            }
            
            // Formater les labels selon la période
            const period = document.getElementById('filter-period').value;
            const formattedLabels = (data.labels || []).map(label => {
                if (period === 'day') {
                    try {
                        return new Date(label + 'T00:00:00').toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
                    } catch (e) {
                        return label;
                    }
                } else if (period === 'month') {
                    try {
                        const [year, month] = label.split('-');
                        return new Date(parseInt(year), parseInt(month) - 1).toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                    } catch (e) {
                        return label;
                    }
                } else {
                    return label;
                }
            });
            
            // Détruire le graphique existant s'il existe
            if (consumptionChart) {
                consumptionChart.destroy();
                consumptionChart = null;
            }
            
            // Vérifier qu'on a au moins un label
            if (formattedLabels.length === 0) {
                console.warn('Aucune donnée à afficher dans le graphique');
            }
            
            // Créer le nouveau graphique en ligne (line chart)
            consumptionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: formattedLabels,
                    datasets: [
                        {
                            label: 'Noir et blanc',
                            data: data.bw || [],
                            borderColor: 'rgb(0, 0, 0)', // Noir
                            backgroundColor: 'rgba(0, 0, 0, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4, // Courbe lissée
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: 'rgb(0, 0, 0)', // Noir
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            spanGaps: true // Permet d'afficher même avec des gaps
                        },
                        {
                            label: 'Couleur',
                            data: data.color || [],
                            borderColor: 'rgb(220, 38, 38)', // Rouge
                            backgroundColor: 'rgba(220, 38, 38, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4, // Courbe lissée
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: 'rgb(220, 38, 38)', // Rouge
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2,
                            spanGaps: true // Permet d'afficher même avec des gaps
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            display: false // On utilise notre légende personnalisée
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
                                    return context.dataset.label + ': ' + formatNumber(context.parsed.y) + ' pages';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            title: {
                                display: true,
                                text: 'Période',
                                font: {
                                    size: 12,
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.05)'
                            }
                        },
                        y: {
                            display: true,
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre de pages',
                                font: {
                                    size: 12,
                                    weight: 'bold'
                                }
                            },
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return formatNumber(value);
                                }
                            }
                        }
                    }
                }
            });
        }
        
        // Mettre à jour les statistiques
        function updateStats(data) {
            // Vérifier si les tableaux existent et ne sont pas vides (JavaScript, pas PHP empty())
            // Utiliser reduce au lieu de Math.max(...array) pour éviter les erreurs avec de grands tableaux
            const totalBw = (Array.isArray(data.bw) && data.bw.length > 0) 
                ? data.bw.reduce((max, val) => Math.max(max, val || 0), 0) 
                : 0;
            const totalColor = (Array.isArray(data.color) && data.color.length > 0) 
                ? data.color.reduce((max, val) => Math.max(max, val || 0), 0) 
                : 0;
            const totalPages = totalBw + totalColor;
            
            const statBwEl = document.getElementById('stat-total-bw');
            const statColorEl = document.getElementById('stat-total-color');
            const statPagesEl = document.getElementById('stat-total-pages');
            
            if (statBwEl) statBwEl.textContent = formatNumber(totalBw);
            if (statColorEl) statColorEl.textContent = formatNumber(totalColor);
            if (statPagesEl) statPagesEl.textContent = formatNumber(totalPages);
        }
        
        // Formater un nombre avec séparateurs
        function formatNumber(num) {
            return new Intl.NumberFormat('fr-FR').format(num);
        }
        
        // Formater un montant en euros
        function formatMoney(amount) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount || 0);
        }
        
        // Échapper le HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // ============================================
        // GESTION DES PÉRIODES DE FACTURATION 20→20
        // ============================================
        
        function setupPeriodesEventListeners() {
            const btnLoad = document.getElementById('btn-load-periodes');
            const btnExport = document.getElementById('btn-export-periodes');
            
            if (btnLoad) {
                btnLoad.addEventListener('click', loadPeriodes);
            }
            
            if (btnExport) {
                btnExport.addEventListener('click', exportPeriodesToExcel);
            }
            
            // Initialiser le mois et l'année avec les valeurs actuelles
            const today = new Date();
            const monthSelect = document.getElementById('filter-period-month');
            const yearInput = document.getElementById('filter-period-year');
            
            if (monthSelect) {
                monthSelect.value = today.getMonth() + 1;
            }
            if (yearInput) {
                yearInput.value = today.getFullYear();
            }
        }
        
        async function loadPeriodes() {
            const month = document.getElementById('filter-period-month').value;
            const year = document.getElementById('filter-period-year').value;
            const clientId = document.getElementById('filter-period-client').value || 0;
            
            const loadingEl = document.getElementById('periodes-loading');
            const tableBody = document.getElementById('periodes-table-body');
            
            loadingEl.style.display = 'block';
            tableBody.innerHTML = '';
            
            try {
                const params = new URLSearchParams({
                    month: month,
                    year: year
                });
                
                if (clientId && clientId !== '0') {
                    params.append('client_id', clientId);
                }
                
                const response = await fetch('/API/paiements_periodes.php?' + params.toString());
                const data = await response.json();
                
                if (data.ok && data.periodes) {
                    displayPeriodes(data.periodes);
                } else {
                    tableBody.innerHTML = '<tr><td colspan="8" class="error-message">Erreur: ' + (data.error || 'Erreur inconnue') + '</td></tr>';
                }
            } catch (error) {
                console.error('Erreur chargement périodes:', error);
                tableBody.innerHTML = '<tr><td colspan="8" class="error-message">Erreur lors du chargement des périodes</td></tr>';
            } finally {
                loadingEl.style.display = 'none';
            }
        }
        
        function displayPeriodes(periodes) {
            const tableBody = document.getElementById('periodes-table-body');
            
            if (!periodes || periodes.length === 0) {
                tableBody.innerHTML = '<tr><td colspan="8" class="no-data">Aucune période trouvée pour cette période.</td></tr>';
                return;
            }
            
            let html = '';
            
            periodes.forEach(periode => {
                const statutClass = getStatutClass(periode.statut_paiement);
                const statutLabel = getStatutLabel(periode.statut_paiement);
                
                html += `
                    <tr>
                        <td>
                            <strong>${escapeHtml(periode.raison_sociale || 'Client sans nom')}</strong><br>
                            <small>${escapeHtml(periode.numero_client || 'N/A')}</small>
                        </td>
                        <td>${escapeHtml(periode.period_label || '')}</td>
                        <td>${formatNumber(periode.consumption_bw || 0)} pages</td>
                        <td>${formatNumber(periode.consumption_color || 0)} pages</td>
                        <td><strong>${formatMoney(periode.debt || 0)} €</strong></td>
                        <td><span class="statut-badge ${statutClass}">${statutLabel}</span></td>
                        <td>${formatMoney(periode.montant_restant || periode.debt || 0)} €</td>
                        <td>
                            <button class="btn-detail" onclick="showPeriodeDetail(${periode.client_id}, '${escapeHtml(periode.period_start)}', '${escapeHtml(periode.period_end)}')">
                                Détail
                            </button>
                            ${periode.facture_url ? 
                                `<a href="${escapeHtml(periode.facture_url)}" target="_blank" class="btn-facture">Facture</a>` : 
                                '<span class="btn-facture disabled">Pas de facture</span>'
                            }
                        </td>
                    </tr>
                `;
            });
            
            tableBody.innerHTML = html;
        }
        
        function getStatutClass(statut) {
            switch(statut) {
                case 'paye': return 'statut-paye';
                case 'partiellement_paye': return 'statut-partiel';
                case 'non_paye': return 'statut-non-paye';
                default: return 'statut-unknown';
            }
        }
        
        function getStatutLabel(statut) {
            switch(statut) {
                case 'paye': return 'Payé';
                case 'partiellement_paye': return 'Partiellement payé';
                case 'non_paye': return 'Non payé';
                default: return 'Inconnu';
            }
        }
        
        function showPeriodeDetail(clientId, periodStart, periodEnd) {
            // TODO: Ouvrir un modal avec le détail de la période (par photocopieur)
            alert('Détail de la période pour le client #' + clientId + '\nPériode: ' + periodStart + ' → ' + periodEnd);
        }
        
        function exportPeriodesToExcel() {
            const month = document.getElementById('filter-period-month').value;
            const year = document.getElementById('filter-period-year').value;
            const clientId = document.getElementById('filter-period-client').value || 0;
            
            const params = new URLSearchParams({
                month: month,
                year: year,
                format: 'excel'
            });
            
            if (clientId && clientId !== '0') {
                params.append('client_id', clientId);
            }
            
            window.location.href = '/API/paiements_periodes.php?' + params.toString();
        }
    </script>
</body>
</html>
