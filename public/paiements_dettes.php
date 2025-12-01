<?php
// /public/paiements_dettes.php
// Page de gestion des dettes mensuelles des clients

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('paiements', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';

$csrfToken = ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dettes clients - Paiements</title>
    
    <!-- CSS globaux -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- CSS spécifique à la page dettes -->
    <link rel="stylesheet" href="/assets/css/paiements_dettes.css">
</head>
<body class="page-paiements-dettes">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>
    
    <main class="page-container">
        <header class="page-header">
            <div class="header-content">
                <div>
                    <h1 class="page-title">Dettes des clients</h1>
                    <p class="page-sub">Calcul automatique des dettes mensuelles selon la consommation</p>
                </div>
                <div class="header-actions">
                    <a href="/public/paiements.php" class="btn-link">
                        ← Retour aux paiements
                    </a>
                </div>
            </div>
        </header>
        
        <!-- Filtres -->
        <section class="dettes-filters">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filter-month">Mois</label>
                    <select id="filter-month" class="filter-select">
                        <?php
                        $currentMonth = (int)date('m');
                        for ($i = 1; $i <= 12; $i++) {
                            $selected = ($i === $currentMonth) ? 'selected' : '';
                            $monthName = date('F', mktime(0, 0, 0, $i, 1));
                            echo "<option value=\"$i\" $selected>" . ucfirst($monthName) . "</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter-year">Année</label>
                    <select id="filter-year" class="filter-select">
                        <?php
                        $currentYear = (int)date('Y');
                        for ($i = $currentYear; $i >= $currentYear - 2; $i--) {
                            $selected = ($i === $currentYear) ? 'selected' : '';
                            echo "<option value=\"$i\" $selected>$i</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
        </section>
        
        <!-- Informations de période -->
        <section class="dettes-period-info" id="period-info">
            <div class="period-card">
                <div class="period-label">Période comptable</div>
                <div class="period-dates" id="period-dates">Chargement...</div>
            </div>
        </section>
        
        <!-- Liste des dettes -->
        <section class="dettes-list" id="dettes-list">
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Chargement des dettes...</p>
            </div>
        </section>
        
        <!-- Résumé global -->
        <section class="dettes-summary" id="dettes-summary" style="display: none;">
            <div class="summary-card">
                <div class="summary-item">
                    <div class="summary-label">Total HT</div>
                    <div class="summary-value" id="total-ht">0,00 €</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Total TTC</div>
                    <div class="summary-value" id="total-ttc">0,00 €</div>
                </div>
                <div class="summary-item">
                    <div class="summary-label">Nombre de clients</div>
                    <div class="summary-value" id="total-clients">0</div>
                </div>
            </div>
        </section>
    </main>
    
    <script>
        // Variables globales
        let currentMonth = <?= (int)date('m') ?>;
        let currentYear = <?= (int)date('Y') ?>;
        
        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            loadDettes();
        });
        
        // Configurer les écouteurs d'événements
        function setupEventListeners() {
            document.getElementById('filter-month').addEventListener('change', function() {
                currentMonth = parseInt(this.value);
                loadDettes();
            });
            
            document.getElementById('filter-year').addEventListener('change', function() {
                currentYear = parseInt(this.value);
                loadDettes();
            });
        }
        
        // Charger les dettes
        async function loadDettes() {
            const loadingEl = document.getElementById('loading');
            const dettesListEl = document.getElementById('dettes-list');
            const summaryEl = document.getElementById('dettes-summary');
            
            loadingEl.style.display = 'block';
            dettesListEl.innerHTML = '';
            summaryEl.style.display = 'none';
            
            try {
                const params = new URLSearchParams({
                    month: currentMonth,
                    year: currentYear
                });
                
                const response = await fetch('/API/paiements_dettes.php?' + params.toString());
                const data = await response.json();
                
                if (data.ok) {
                    displayDettes(data);
                    displaySummary(data);
                    displayPeriodInfo(data.period);
                } else {
                    showError('Erreur: ' + (data.error || 'Erreur inconnue'));
                }
            } catch (error) {
                console.error('Erreur chargement dettes:', error);
                showError('Erreur lors du chargement des dettes');
            } finally {
                loadingEl.style.display = 'none';
            }
        }
        
        // Afficher les dettes
        function displayDettes(data) {
            const dettesListEl = document.getElementById('dettes-list');
            
            if (!data.dettes || data.dettes.length === 0) {
                dettesListEl.innerHTML = '<div class="no-data">Aucune dette trouvée pour cette période.</div>';
                return;
            }
            
            let html = '';
            
            data.dettes.forEach(dette => {
                html += `
                    <div class="dette-card">
                        <div class="dette-header">
                            <div class="dette-client-info">
                                <h3 class="dette-client-name">${escapeHtml(dette.raison_sociale)}</h3>
                                <p class="dette-client-number">Client #${escapeHtml(dette.numero_client)}</p>
                            </div>
                            <div class="dette-totals">
                                <div class="dette-total-item">
                                    <span class="dette-total-label">Total HT</span>
                                    <span class="dette-total-value">${formatMoney(dette.total_ht)} €</span>
                                </div>
                                <div class="dette-total-item dette-total-ttc">
                                    <span class="dette-total-label">Total TTC</span>
                                    <span class="dette-total-value">${formatMoney(dette.total_ttc)} €</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="dette-photocopieurs">
                            ${dette.photocopieurs.map(photo => `
                                <div class="photo-card">
                                    <div class="photo-header">
                                        <div class="photo-info">
                                            <strong>${escapeHtml(photo.model || 'Inconnu')}</strong>
                                            <span class="photo-mac">${escapeHtml(photo.mac_address || photo.mac_norm)}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="photo-details">
                                        <div class="photo-detail-row">
                                            <span class="photo-detail-label">Compteur départ N&B:</span>
                                            <span class="photo-detail-value">${formatNumber(photo.compteur_depart_bw)}</span>
                                        </div>
                                        <div class="photo-detail-row">
                                            <span class="photo-detail-label">Compteur départ Couleur:</span>
                                            <span class="photo-detail-value">${formatNumber(photo.compteur_depart_color)}</span>
                                        </div>
                                        <div class="photo-detail-row">
                                            <span class="photo-detail-label">Compteur fin N&B:</span>
                                            <span class="photo-detail-value">${formatNumber(photo.compteur_fin_bw)}</span>
                                        </div>
                                        <div class="photo-detail-row">
                                            <span class="photo-detail-label">Compteur fin Couleur:</span>
                                            <span class="photo-detail-value">${formatNumber(photo.compteur_fin_color)}</span>
                                        </div>
                                    </div>
                                    
                                    <div class="photo-consumption">
                                        <div class="consumption-item">
                                            <span class="consumption-label">Consommation N&B:</span>
                                            <span class="consumption-value">${formatNumber(photo.consumption_bw)} pages</span>
                                        </div>
                                        <div class="consumption-item">
                                            <span class="consumption-label">Consommation Couleur:</span>
                                            <span class="consumption-value">${formatNumber(photo.consumption_color)} pages</span>
                                        </div>
                                    </div>
                                    
                                    <div class="photo-amounts">
                                        <div class="amount-row">
                                            <span class="amount-label">N&B (${formatMoney(data.tarifs.bw_ttc)} €/page):</span>
                                            <span class="amount-value">${formatMoney(photo.montant_bw_ttc)} € TTC</span>
                                        </div>
                                        <div class="amount-row">
                                            <span class="amount-label">Couleur (${formatMoney(data.tarifs.color_ttc)} €/page):</span>
                                            <span class="amount-value">${formatMoney(photo.montant_color_ttc)} € TTC</span>
                                        </div>
                                        <div class="amount-row amount-total">
                                            <span class="amount-label">Total:</span>
                                            <span class="amount-value">${formatMoney(photo.total_ttc)} € TTC</span>
                                        </div>
                                    </div>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            });
            
            dettesListEl.innerHTML = html;
        }
        
        // Afficher le résumé
        function displaySummary(data) {
            const summaryEl = document.getElementById('dettes-summary');
            let totalHt = 0;
            let totalTtc = 0;
            let totalClients = 0;
            
            if (data.dettes && data.dettes.length > 0) {
                data.dettes.forEach(dette => {
                    totalHt += dette.total_ht;
                    totalTtc += dette.total_ttc;
                    totalClients++;
                });
            }
            
            document.getElementById('total-ht').textContent = formatMoney(totalHt) + ' €';
            document.getElementById('total-ttc').textContent = formatMoney(totalTtc) + ' €';
            document.getElementById('total-clients').textContent = totalClients;
            
            summaryEl.style.display = 'block';
        }
        
        // Afficher les informations de période
        function displayPeriodInfo(period) {
            document.getElementById('period-dates').textContent = period.label || 'Période non définie';
        }
        
        // Afficher une erreur
        function showError(message) {
            const dettesListEl = document.getElementById('dettes-list');
            dettesListEl.innerHTML = `<div class="error-message">${escapeHtml(message)}</div>`;
        }
        
        // Fonctions utilitaires
        function formatMoney(amount) {
            return new Intl.NumberFormat('fr-FR', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }).format(amount);
        }
        
        function formatNumber(num) {
            return new Intl.NumberFormat('fr-FR').format(num);
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

