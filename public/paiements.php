<?php
// /public/paiements.php
// Page de gestion des paiements avec graphique de consommation de papier
// NOUVELLE VERSION : Graphique en ligne, filtres automatiques, design modernisé

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
            <h1 class="page-title">Paiements - Consommation de papier</h1>
            <p class="page-sub">Visualisation de la consommation cumulée depuis le premier relevé</p>
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
        
        <!-- Graphique en ligne -->
        <section class="paiements-chart">
            <div class="chart-header">
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
            <div class="chart-container">
                <canvas id="consumptionChart"></canvas>
            </div>
        </section>
        
        <!-- Statistiques -->
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
            loadData();
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
            const photocopieurSelect = document.getElementById('filter-photocopieur');
            const dateStartInput = document.getElementById('filter-date-start');
            const dateEndInput = document.getElementById('filter-date-end');
            
            // Mise à jour automatique lors des changements
            periodSelect.addEventListener('change', () => {
                updateDefaultDates();
                loadData();
            });
            
            photocopieurSelect.addEventListener('change', loadData);
            dateStartInput.addEventListener('change', loadData);
            dateEndInput.addEventListener('change', loadData);
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
        
        // Charger les données et mettre à jour le graphique
        async function loadData() {
            // Éviter les requêtes multiples simultanées
            if (isLoading) return;
            
            const period = document.getElementById('filter-period').value;
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
            chartContainer.style.opacity = '0.6';
            chartContainer.style.pointerEvents = 'none';
            
            try {
                const params = new URLSearchParams({
                    period: period,
                    date_start: dateStart,
                    date_end: dateEnd
                });
                
                if (mac) {
                    params.append('mac', mac);
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
                chartContainer.style.opacity = '1';
                chartContainer.style.pointerEvents = 'auto';
            }
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
            const ctx = document.getElementById('consumptionChart').getContext('2d');
            
            // Formater les labels selon la période
            const period = document.getElementById('filter-period').value;
            const formattedLabels = data.labels.map(label => {
                if (period === 'day') {
                    return new Date(label).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
                } else if (period === 'month') {
                    const [year, month] = label.split('-');
                    return new Date(year, month - 1).toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                } else {
                    return label;
                }
            });
            
            // Détruire le graphique existant s'il existe
            if (consumptionChart) {
                consumptionChart.destroy();
            }
            
            // Créer le nouveau graphique en ligne (line chart)
            consumptionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: formattedLabels,
                    datasets: [
                        {
                            label: 'Noir et blanc',
                            data: data.bw,
                            borderColor: 'rgb(0, 0, 0)',
                            backgroundColor: 'rgba(0, 0, 0, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4, // Courbe lissée
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: 'rgb(0, 0, 0)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2
                        },
                        {
                            label: 'Couleur',
                            data: data.color,
                            borderColor: 'rgb(255, 99, 132)',
                            backgroundColor: 'rgba(255, 99, 132, 0.1)',
                            borderWidth: 2,
                            fill: true,
                            tension: 0.4, // Courbe lissée
                            pointRadius: 3,
                            pointHoverRadius: 5,
                            pointBackgroundColor: 'rgb(255, 99, 132)',
                            pointBorderColor: '#fff',
                            pointBorderWidth: 2
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
            const totalBw = !empty(data.bw) ? Math.max(...data.bw) : 0;
            const totalColor = !empty(data.color) ? Math.max(...data.color) : 0;
            const totalPages = totalBw + totalColor;
            
            document.getElementById('stat-total-bw').textContent = formatNumber(totalBw);
            document.getElementById('stat-total-color').textContent = formatNumber(totalColor);
            document.getElementById('stat-total-pages').textContent = formatNumber(totalPages);
        }
        
        // Formater un nombre avec séparateurs
        function formatNumber(num) {
            return new Intl.NumberFormat('fr-FR').format(num);
        }
    </script>
</body>
</html>

