<?php
// /public/paiements.php
// Page de gestion des paiements avec graphique de consommation de papier

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
            <p class="page-sub">Visualisation de la consommation de papier (noir et blanc / couleur)</p>
        </header>
        
        <!-- Filtres -->
        <section class="paiements-filters">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filter-period">Période</label>
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
                
                <div class="filter-group filter-actions">
                    <button id="btn-apply-filters" class="btn btn-primary">Appliquer les filtres</button>
                    <button id="btn-reset-filters" class="btn btn-secondary">Réinitialiser</button>
                </div>
            </div>
        </section>
        
        <!-- Graphique -->
        <section class="paiements-chart">
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
                    </div>
                </div>
                
                <div class="stat-card stat-color">
                    <div class="stat-icon">🎨</div>
                    <div class="stat-content">
                        <div class="stat-label">Couleur</div>
                        <div class="stat-value" id="stat-total-color">0</div>
                    </div>
                </div>
                
                <div class="stat-card stat-total">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-label">Total</div>
                        <div class="stat-value" id="stat-total-pages">0</div>
                    </div>
                </div>
            </div>
        </section>
    </main>
    
    <script>
        // Variables globales
        let consumptionChart = null;
        let photocopieursList = [];
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            initializeFilters();
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
            
            // Écouteurs d'événements
            document.getElementById('btn-apply-filters').addEventListener('click', loadData);
            document.getElementById('btn-reset-filters').addEventListener('click', resetFilters);
        }
        
        // Réinitialiser les filtres
        function resetFilters() {
            document.getElementById('filter-period').value = 'month';
            document.getElementById('filter-photocopieur').value = '';
            
            const today = new Date();
            const endDate = today.toISOString().split('T')[0];
            const startDate = new Date(today);
            startDate.setMonth(startDate.getMonth() - 12);
            const startDateStr = startDate.toISOString().split('T')[0];
            
            document.getElementById('filter-date-start').value = startDateStr;
            document.getElementById('filter-date-end').value = endDate;
            
            loadData();
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
            const period = document.getElementById('filter-period').value;
            const mac = document.getElementById('filter-photocopieur').value;
            const dateStart = document.getElementById('filter-date-start').value;
            const dateEnd = document.getElementById('filter-date-end').value;
            
            // Validation des dates
            if (!dateStart || !dateEnd) {
                alert('Veuillez sélectionner une date de début et une date de fin');
                return;
            }
            
            if (new Date(dateStart) > new Date(dateEnd)) {
                alert('La date de début doit être antérieure à la date de fin');
                return;
            }
            
            // Afficher un indicateur de chargement
            const chartContainer = document.querySelector('.chart-container');
            chartContainer.style.opacity = '0.5';
            
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
                    alert('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            } catch (error) {
                console.error('Erreur chargement données:', error);
                alert('Erreur lors du chargement des données');
            } finally {
                chartContainer.style.opacity = '1';
            }
        }
        
        // Mettre à jour le graphique
        function updateChart(data) {
            const ctx = document.getElementById('consumptionChart').getContext('2d');
            
            // Formater les labels selon la période
            const period = document.getElementById('filter-period').value;
            const formattedLabels = data.labels.map(label => {
                if (period === 'day') {
                    return new Date(label).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
                } else if (period === 'month') {
                    const [year, month] = label.split('-');
                    return new Date(year, month - 1).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                } else {
                    return label;
                }
            });
            
            // Détruire le graphique existant s'il existe
            if (consumptionChart) {
                consumptionChart.destroy();
            }
            
            // Créer le nouveau graphique
            consumptionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: formattedLabels,
                    datasets: [
                        {
                            label: 'Noir et blanc',
                            data: data.bw,
                            backgroundColor: 'rgba(0, 0, 0, 0.7)',
                            borderColor: 'rgba(0, 0, 0, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Couleur',
                            data: data.color,
                            backgroundColor: 'rgba(255, 99, 132, 0.7)',
                            borderColor: 'rgba(255, 99, 132, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top'
                        },
                        title: {
                            display: true,
                            text: 'Consommation de papier par période'
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    },
                    scales: {
                        x: {
                            stacked: false,
                            title: {
                                display: true,
                                text: 'Période'
                            }
                        },
                        y: {
                            stacked: false,
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Nombre de pages'
                            },
                            ticks: {
                                stepSize: 100
                            }
                        }
                    },
                    interaction: {
                        mode: 'nearest',
                        axis: 'x',
                        intersect: false
                    }
                }
            });
        }
        
        // Mettre à jour les statistiques
        function updateStats(data) {
            document.getElementById('stat-total-bw').textContent = formatNumber(data.total_bw);
            document.getElementById('stat-total-color').textContent = formatNumber(data.total_color);
            document.getElementById('stat-total-pages').textContent = formatNumber(data.total_bw + data.total_color);
        }
        
        // Formater un nombre avec séparateurs
        function formatNumber(num) {
            return new Intl.NumberFormat('fr-FR').format(num);
        }
    </script>
</body>
</html>

