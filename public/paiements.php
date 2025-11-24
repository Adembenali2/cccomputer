<?php
// /public/paiements.php
// Page de gestion des paiements et factures

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function h(?string $s): string {
    return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8');
}

// Données factices pour le développement
// TODO: Remplacer par des requêtes à la base de données

// Génération de données factices pour les clients
$clientsData = [];
$clientNames = [
    'Entreprise ABC', 'Société XYZ', 'Comptabilité DEF', 'Bureau GHI', 
    'Services JKL', 'Solutions MNO', 'Expertise PQR', 'Consulting STU',
    'Groupe VWX', 'Partners YZ'
];

for ($i = 1; $i <= 10; $i++) {
    $monthlyConsumption = [];
    $totalYear = 0;
    
    // Générer consommation mensuelle pour les 12 derniers mois
    for ($m = 11; $m >= 0; $m--) {
        $month = date('Y-m', strtotime("-$m months"));
        $consumption = rand(500, 5000); // Consommation en pages
        $amount = $consumption * 0.05; // 0.05€ par page
        $monthlyConsumption[] = [
            'month' => $month,
            'consumption' => $consumption,
            'amount' => round($amount, 2)
        ];
        $totalYear += $amount;
    }
    
    $clientsData[] = [
        'id' => $i,
        'name' => $clientNames[$i - 1] ?? "Client $i",
        'numero_client' => 'C' . str_pad($i, 5, '0', STR_PAD_LEFT),
        'monthly_consumption' => $monthlyConsumption,
        'total_year' => round($totalYear, 2),
        'pending_amount' => round(rand(100, 2000), 2),
        'status' => rand(0, 1) ? 'paid' : 'pending'
    ];
}

// Générer données pour le diagramme (consommation globale)
$chartData = [
    'daily' => [],
    'monthly' => [],
    'yearly' => []
];

// Données quotidiennes (30 derniers jours)
for ($d = 29; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    $chartData['daily'][] = [
        'date' => $date,
        'consumption' => rand(10000, 50000),
        'amount' => round(rand(10000, 50000) * 0.05, 2)
    ];
}

// Données mensuelles (12 derniers mois)
for ($m = 11; $m >= 0; $m--) {
    $month = date('Y-m', strtotime("-$m months"));
    $chartData['monthly'][] = [
        'month' => $month,
        'consumption' => rand(200000, 800000),
        'amount' => round(rand(200000, 800000) * 0.05, 2)
    ];
}

// Données annuelles (5 dernières années)
for ($y = 4; $y >= 0; $y--) {
    $year = date('Y', strtotime("-$y years"));
    $chartData['yearly'][] = [
        'year' => $year,
        'consumption' => rand(2000000, 8000000),
        'amount' => round(rand(2000000, 8000000) * 0.05, 2)
    ];
}

// Historique des paiements factices
$paymentHistory = [];
$paymentTypes = ['Virement', 'Chèque', 'Carte bancaire', 'Espèces'];
$statuses = ['completed', 'pending', 'failed'];

for ($i = 0; $i < 20; $i++) {
    $client = $clientsData[rand(0, count($clientsData) - 1)];
    $paymentHistory[] = [
        'id' => $i + 1,
        'client_id' => $client['id'],
        'client_name' => $client['name'],
        'amount' => round(rand(100, 2000), 2),
        'date' => date('Y-m-d', strtotime('-' . rand(0, 90) . ' days')),
        'type' => $paymentTypes[rand(0, count($paymentTypes) - 1)],
        'status' => $statuses[rand(0, count($statuses) - 1)],
        'reference' => 'PAY-' . date('Y') . '-' . str_pad($i + 1, 5, '0', STR_PAD_LEFT)
    ];
}

// Trier par date décroissante
usort($paymentHistory, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

$CSRF = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = $CSRF;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiements - CCComputer</title>
    <link rel="stylesheet" href="/assets/css/paiements.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body class="page-paiements">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="paiements-wrapper">
        <div class="paiements-header">
            <h2 class="page-title">Gestion des Paiements</h2>
            <p class="page-subtitle">Consommation et facturation des clients</p>
        </div>

        <!-- Diagramme de consommation -->
        <div class="chart-section">
            <div class="chart-header">
                <h3>Diagramme de Consommation</h3>
                <div class="chart-controls">
                    <button class="chart-btn active" data-period="daily">Jour</button>
                    <button class="chart-btn" data-period="monthly">Mois</button>
                    <button class="chart-btn" data-period="yearly">Année</button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="consumptionChart"></canvas>
            </div>
        </div>

        <!-- Liste des clients avec consommation -->
        <div class="clients-section">
            <div class="section-header">
                <h3>Consommation Mensuelle par Client</h3>
                <div class="search-box">
                    <input type="text" id="clientSearch" placeholder="🔍 Rechercher un client..." />
                </div>
            </div>
            
            <div class="clients-grid" id="clientsGrid">
                <?php foreach ($clientsData as $client): ?>
                    <div class="client-card" data-client-name="<?= h(strtolower($client['name'])) ?>" data-client-num="<?= h(strtolower($client['numero_client'])) ?>">
                        <div class="client-card-header">
                            <div>
                                <h4 class="client-name"><?= h($client['name']) ?></h4>
                                <span class="client-number"><?= h($client['numero_client']) ?></span>
                            </div>
                            <span class="status-badge <?= $client['status'] === 'paid' ? 'status-paid' : 'status-pending' ?>">
                                <?= $client['status'] === 'paid' ? '✓ Payé' : '⏳ En attente' ?>
                            </span>
                        </div>
                        
                        <div class="client-stats">
                            <div class="stat-item">
                                <span class="stat-label">Total annuel</span>
                                <span class="stat-value"><?= number_format($client['total_year'], 2, ',', ' ') ?> €</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">En attente</span>
                                <span class="stat-value pending"><?= number_format($client['pending_amount'], 2, ',', ' ') ?> €</span>
                            </div>
                        </div>

                        <div class="monthly-breakdown">
                            <div class="breakdown-header">
                                <span>Derniers 3 mois</span>
                            </div>
                            <div class="breakdown-list">
                                <?php 
                                $last3Months = array_slice($client['monthly_consumption'], -3);
                                foreach ($last3Months as $month): 
                                ?>
                                    <div class="breakdown-item">
                                        <span class="month-name"><?= h(date('M Y', strtotime($month['month'] . '-01'))) ?></span>
                                        <span class="month-stats">
                                            <span class="month-pages"><?= number_format($month['consumption'], 0, ',', ' ') ?> pages</span>
                                            <span class="month-amount"><?= number_format($month['amount'], 2, ',', ' ') ?> €</span>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button class="btn-view-details" data-client-id="<?= $client['id'] ?>">
                            Voir les détails
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Zone de paiement -->
        <div class="payment-section">
            <div class="section-header">
                <h3>Effectuer un Paiement</h3>
            </div>
            
            <div class="payment-form-container">
                <form id="paymentForm" class="payment-form">
                    <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                    
                    <div class="form-group">
                        <label for="paymentClient">Client *</label>
                        <select id="paymentClient" name="client_id" required>
                            <option value="">-- Sélectionner un client --</option>
                            <?php foreach ($clientsData as $client): ?>
                                <option value="<?= $client['id'] ?>" data-pending="<?= $client['pending_amount'] ?>">
                                    <?= h($client['name']) ?> (<?= h($client['numero_client']) ?>) - 
                                    <?= number_format($client['pending_amount'], 2, ',', ' ') ?> € en attente
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="paymentAmount">Montant (€) *</label>
                            <input type="number" id="paymentAmount" name="amount" step="0.01" min="0.01" required 
                                   placeholder="0.00" />
                            <small class="form-hint">Montant dû: <span id="pendingAmount">0.00</span> €</small>
                        </div>

                        <div class="form-group">
                            <label for="paymentType">Type de paiement *</label>
                            <select id="paymentType" name="payment_type" required>
                                <option value="">-- Sélectionner --</option>
                                <option value="virement">Virement</option>
                                <option value="cheque">Chèque</option>
                                <option value="carte">Carte bancaire</option>
                                <option value="especes">Espèces</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="paymentDate">Date de paiement *</label>
                        <input type="date" id="paymentDate" name="payment_date" required 
                               value="<?= date('Y-m-d') ?>" />
                    </div>

                    <div class="form-group">
                        <label for="paymentReference">Référence</label>
                        <input type="text" id="paymentReference" name="reference" 
                               placeholder="Référence du paiement (optionnel)" />
                    </div>

                    <div class="form-group">
                        <label for="paymentNotes">Notes</label>
                        <textarea id="paymentNotes" name="notes" rows="3" 
                                  placeholder="Notes supplémentaires (optionnel)"></textarea>
                    </div>

                    <div id="paymentError" class="error-message" style="display: none;"></div>
                    <div id="paymentSuccess" class="success-message" style="display: none;"></div>

                    <div class="form-actions">
                        <button type="submit" class="btn-primary">Enregistrer le paiement</button>
                        <button type="reset" class="btn-secondary">Réinitialiser</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Historique des paiements -->
        <div class="history-section">
            <div class="section-header">
                <h3>Historique des Paiements</h3>
                <div class="history-filters">
                    <select id="historyClientFilter">
                        <option value="">Tous les clients</option>
                        <?php foreach ($clientsData as $client): ?>
                            <option value="<?= $client['id'] ?>"><?= h($client['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="historyStatusFilter">
                        <option value="">Tous les statuts</option>
                        <option value="completed">Complété</option>
                        <option value="pending">En attente</option>
                        <option value="failed">Échoué</option>
                    </select>
                </div>
            </div>

            <div class="history-table-container">
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Client</th>
                            <th>Montant</th>
                            <th>Type</th>
                            <th>Référence</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr data-client-id="<?= $payment['client_id'] ?>" 
                                data-status="<?= $payment['status'] ?>">
                                <td><?= h(date('d/m/Y', strtotime($payment['date']))) ?></td>
                                <td><?= h($payment['client_name']) ?></td>
                                <td class="amount-cell"><?= number_format($payment['amount'], 2, ',', ' ') ?> €</td>
                                <td><?= h($payment['type']) ?></td>
                                <td><?= h($payment['reference']) ?></td>
                                <td>
                                    <span class="status-badge status-<?= $payment['status'] ?>">
                                        <?php
                                        switch($payment['status']) {
                                            case 'completed': echo '✓ Complété'; break;
                                            case 'pending': echo '⏳ En attente'; break;
                                            case 'failed': echo '✗ Échoué'; break;
                                        }
                                        ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Données pour le diagramme
        const chartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        
        // Initialisation du diagramme
        let consumptionChart;
        const ctx = document.getElementById('consumptionChart');
        
        function initChart(period = 'monthly') {
            const data = chartData[period];
            let labels, consumptionData, amountData;
            
            if (period === 'daily') {
                labels = data.map(d => new Date(d.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }));
                consumptionData = data.map(d => d.consumption);
                amountData = data.map(d => d.amount);
            } else if (period === 'monthly') {
                labels = data.map(d => {
                    const date = new Date(d.month + '-01');
                    return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                });
                consumptionData = data.map(d => d.consumption);
                amountData = data.map(d => d.amount);
            } else {
                labels = data.map(d => d.year);
                consumptionData = data.map(d => d.consumption);
                amountData = data.map(d => d.amount);
            }
            
            if (consumptionChart) {
                consumptionChart.destroy();
            }
            
            consumptionChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Consommation (pages)',
                            data: consumptionData,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            yAxisID: 'y',
                            tension: 0.4
                        },
                        {
                            label: 'Montant (€)',
                            data: amountData,
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            yAxisID: 'y1',
                            tension: 0.4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false,
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    if (context.datasetIndex === 0) {
                                        return 'Consommation: ' + context.parsed.y.toLocaleString('fr-FR') + ' pages';
                                    } else {
                                        return 'Montant: ' + context.parsed.y.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' });
                                    }
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            display: true,
                            position: 'left',
                            title: {
                                display: true,
                                text: 'Consommation (pages)'
                            }
                        },
                        y1: {
                            type: 'linear',
                            display: true,
                            position: 'right',
                            title: {
                                display: true,
                                text: 'Montant (€)'
                            },
                            grid: {
                                drawOnChartArea: false,
                            },
                        }
                    }
                }
            });
        }
        
        // Gestion des boutons de période
        document.querySelectorAll('.chart-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.chart-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                initChart(this.dataset.period);
            });
        });
        
        // Initialiser avec les données mensuelles
        initChart('monthly');
        
        // Recherche de clients
        document.getElementById('clientSearch').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            document.querySelectorAll('.client-card').forEach(card => {
                const name = card.dataset.clientName || '';
                const num = card.dataset.clientNum || '';
                if (name.includes(searchTerm) || num.includes(searchTerm)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Mise à jour du montant dû lors de la sélection d'un client
        document.getElementById('paymentClient').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const pendingAmount = selectedOption.dataset.pending || '0.00';
            document.getElementById('pendingAmount').textContent = parseFloat(pendingAmount).toLocaleString('fr-FR', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
            document.getElementById('paymentAmount').value = pendingAmount;
        });
        
        // Filtres de l'historique
        function filterHistory() {
            const clientFilter = document.getElementById('historyClientFilter').value;
            const statusFilter = document.getElementById('historyStatusFilter').value;
            
            document.querySelectorAll('#historyTableBody tr').forEach(row => {
                const clientId = row.dataset.clientId || '';
                const status = row.dataset.status || '';
                
                const showClient = !clientFilter || clientId === clientFilter;
                const showStatus = !statusFilter || status === statusFilter;
                
                row.style.display = (showClient && showStatus) ? '' : 'none';
            });
        }
        
        document.getElementById('historyClientFilter').addEventListener('change', filterHistory);
        document.getElementById('historyStatusFilter').addEventListener('change', filterHistory);
        
        // Gestion du formulaire de paiement
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const errorDiv = document.getElementById('paymentError');
            const successDiv = document.getElementById('paymentSuccess');
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            
            // Validation
            const amount = parseFloat(document.getElementById('paymentAmount').value);
            const pending = parseFloat(document.getElementById('pendingAmount').textContent.replace(/\s/g, '').replace(',', '.'));
            
            if (amount > pending) {
                errorDiv.textContent = 'Le montant ne peut pas dépasser le montant dû (' + pending.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }) + ')';
                errorDiv.style.display = 'block';
                return;
            }
            
            // TODO: Envoyer les données au serveur
            // Pour l'instant, on simule juste un succès
            successDiv.textContent = 'Paiement enregistré avec succès !';
            successDiv.style.display = 'block';
            
            setTimeout(() => {
                this.reset();
                successDiv.style.display = 'none';
                document.getElementById('pendingAmount').textContent = '0.00';
            }, 3000);
        });
    </script>
</body>
</html>

