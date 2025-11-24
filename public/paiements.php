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
    $totalYearNB = 0;
    $totalYearColor = 0;
    $totalYearAmount = 0;
    
    // Générer consommation mensuelle pour les 12 derniers mois
    for ($m = 11; $m >= 0; $m--) {
        $month = date('Y-m', strtotime("-$m months"));
        
        // Consommation Noir et Blanc (généralement plus élevée)
        $consumptionNB = rand(1000, 8000);
        $amountNB = $consumptionNB * 0.03; // 0.03€ par page NB
        
        // Consommation Couleur (généralement plus faible mais plus chère)
        $consumptionColor = rand(100, 2000);
        $amountColor = $consumptionColor * 0.15; // 0.15€ par page couleur
        
        $totalMonth = $consumptionNB + $consumptionColor;
        $totalAmount = $amountNB + $amountColor;
        
        $monthlyConsumption[] = [
            'month' => $month,
            'nb' => [
                'pages' => $consumptionNB,
                'amount' => round($amountNB, 2)
            ],
            'color' => [
                'pages' => $consumptionColor,
                'amount' => round($amountColor, 2)
            ],
            'total' => [
                'pages' => $totalMonth,
                'amount' => round($totalAmount, 2)
            ]
        ];
        
        $totalYearNB += $amountNB;
        $totalYearColor += $amountColor;
        $totalYearAmount += $totalAmount;
    }
    
    // Générer les factures (période du 20 au 20)
    // La facture est générée le 20 de chaque mois pour la période du 20 du mois précédent au 20 du mois actuel
    $invoices = [];
    for ($m = 11; $m >= 0; $m--) {
        $invoiceMonth = date('Y-m', strtotime("-$m months"));
        // Date de facturation : le 20 du mois
        $invoiceDate = date('Y-m-20', strtotime($invoiceMonth . '-01'));
        // Période : du 20 du mois précédent au 20 du mois actuel
        $periodStart = date('Y-m-20', strtotime($invoiceMonth . '-01 -1 month'));
        $periodEnd = date('Y-m-20', strtotime($invoiceMonth . '-01'));
        // Date d'échéance : le 20 du mois suivant
        $dueDate = date('Y-m-20', strtotime($invoiceMonth . '-01 +1 month'));
        
        // Trouver la consommation pour cette période (du 20 au 20)
        $invoiceConsumption = $monthlyConsumption[11 - $m] ?? null;
        
        if ($invoiceConsumption) {
            $invoiceNumber = 'FAC-' . date('Ymd', strtotime($invoiceDate)) . '-' . str_pad($i, 5, '0', STR_PAD_LEFT);
            
            $invoices[] = [
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'nb_pages' => $invoiceConsumption['nb']['pages'],
                'nb_amount' => $invoiceConsumption['nb']['amount'],
                'color_pages' => $invoiceConsumption['color']['pages'],
                'color_amount' => $invoiceConsumption['color']['amount'],
                'total_pages' => $invoiceConsumption['total']['pages'],
                'total_amount' => $invoiceConsumption['total']['amount'],
                'status' => (strtotime($dueDate) < time()) ? 'overdue' : (rand(0, 1) ? 'paid' : 'pending')
            ];
        }
    }
    
    $clientsData[] = [
        'id' => $i,
        'name' => $clientNames[$i - 1] ?? "Client $i",
        'numero_client' => 'C' . str_pad($i, 5, '0', STR_PAD_LEFT),
        'monthly_consumption' => $monthlyConsumption,
        'total_year' => [
            'nb' => round($totalYearNB, 2),
            'color' => round($totalYearColor, 2),
            'total' => round($totalYearAmount, 2)
        ],
        'pending_amount' => round(rand(100, 2000), 2),
        'status' => rand(0, 1) ? 'paid' : 'pending',
        'invoices' => $invoices
    ];
}

// Générer données pour le diagramme (consommation globale avec distinction NB/Couleur)
$chartData = [
    'daily' => [],
    'monthly' => [],
    'yearly' => []
];

// Données quotidiennes (30 derniers jours)
for ($d = 29; $d >= 0; $d--) {
    $date = date('Y-m-d', strtotime("-$d days"));
    $nbPages = rand(50000, 200000);
    $colorPages = rand(5000, 30000);
    $nbAmount = $nbPages * 0.03;
    $colorAmount = $colorPages * 0.15;
    $chartData['daily'][] = [
        'date' => $date,
        'nb_pages' => $nbPages,
        'color_pages' => $colorPages,
        'total_pages' => $nbPages + $colorPages,
        'amount' => round($nbAmount + $colorAmount, 2)
    ];
}

// Données mensuelles (12 derniers mois)
for ($m = 11; $m >= 0; $m--) {
    $month = date('Y-m', strtotime("-$m months"));
    $nbPages = rand(200000, 800000);
    $colorPages = rand(20000, 150000);
    $nbAmount = $nbPages * 0.03;
    $colorAmount = $colorPages * 0.15;
    $chartData['monthly'][] = [
        'month' => $month,
        'nb_pages' => $nbPages,
        'color_pages' => $colorPages,
        'total_pages' => $nbPages + $colorPages,
        'amount' => round($nbAmount + $colorAmount, 2)
    ];
}

// Données annuelles (5 dernières années)
for ($y = 4; $y >= 0; $y--) {
    $year = date('Y', strtotime("-$y years"));
    $nbPages = rand(2000000, 8000000);
    $colorPages = rand(200000, 1500000);
    $nbAmount = $nbPages * 0.03;
    $colorAmount = $colorPages * 0.15;
    $chartData['yearly'][] = [
        'year' => $year,
        'nb_pages' => $nbPages,
        'color_pages' => $colorPages,
        'total_pages' => $nbPages + $colorPages,
        'amount' => round($nbAmount + $colorAmount, 2)
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

        <!-- Section Export Tous les Clients (après le diagramme) -->
        <div class="export-section">
            <div class="section-header">
                <h3>Export des Consommations - Tous les Clients</h3>
            </div>
            
            <div class="export-filters">
                <div class="filter-group">
                    <label for="exportPeriod">Période *</label>
                    <select id="exportPeriod" required>
                        <option value="all_months">Tous les mois disponibles</option>
                        <option value="specific_month">Mois spécifique</option>
                        <option value="specific_year">Toute une année</option>
                        <option value="from_first">Depuis le premier compteur reçu</option>
                    </select>
                </div>
                
                <div class="filter-group" id="monthFilterGroup" style="display: none;">
                    <label for="exportMonth">Mois</label>
                    <input type="month" id="exportMonth" />
                </div>
                
                <div class="filter-group" id="yearFilterGroup" style="display: none;">
                    <label for="exportYear">Année</label>
                    <select id="exportYear">
                        <?php
                        $currentYear = (int)date('Y');
                        for ($y = $currentYear; $y >= $currentYear - 5; $y--) {
                            echo "<option value=\"$y\">$y</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>
            
            <div class="export-buttons">
                <button class="btn-export" id="exportAllClients">
                    📊 Exporter tous les clients en Excel
                </button>
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
                                <span class="stat-value"><?= number_format($client['total_year']['total'], 2, ',', ' ') ?> €</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-label">En attente</span>
                                <span class="stat-value pending"><?= number_format($client['pending_amount'], 2, ',', ' ') ?> €</span>
                            </div>
                        </div>

                        <div class="consumption-summary">
                            <div class="summary-item">
                                <span class="summary-label">NB</span>
                                <span class="summary-value"><?= number_format($client['total_year']['nb'], 2, ',', ' ') ?> €</span>
                            </div>
                            <div class="summary-item">
                                <span class="summary-label">Couleur</span>
                                <span class="summary-value"><?= number_format($client['total_year']['color'], 2, ',', ' ') ?> €</span>
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
                                            <span class="month-pages">
                                                <?= number_format($month['total']['pages'], 0, ',', ' ') ?> pages
                                                <small>(<?= number_format($month['nb']['pages'], 0, ',', ' ') ?> NB / <?= number_format($month['color']['pages'], 0, ',', ' ') ?> C)</small>
                                            </span>
                                            <span class="month-amount"><?= number_format($month['total']['amount'], 2, ',', ' ') ?> €</span>
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
                <form id="paymentForm" class="payment-form" enctype="multipart/form-data">
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
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="virement">Virement</option>
                            </select>
                        </div>
                    </div>

                    <!-- Champ IBAN (visible uniquement pour virement) -->
                    <div class="form-group" id="ibanGroup" style="display: none;">
                        <label for="paymentIban">IBAN du client *</label>
                        <input type="text" id="paymentIban" name="iban" 
                               placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX" 
                               pattern="[A-Z]{2}[0-9]{2}[A-Z0-9]{4}[0-9]{7}([A-Z0-9]?){0,16}" />
                        <small class="form-hint">Format: FR76 XXXX XXXX XXXX XXXX XXXX XXX</small>
                    </div>

                    <!-- Champ justificatif (visible pour chèque et virement) -->
                    <div class="form-group" id="justificatifGroup" style="display: none;">
                        <label for="paymentJustificatif">Justificatif de paiement *</label>
                        <input type="file" id="paymentJustificatif" name="justificatif" 
                               accept=".pdf,.jpg,.jpeg,.png" />
                        <small class="form-hint">Formats acceptés: PDF, JPG, PNG (max 10 Mo)</small>
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

    <!-- Modal Détails Client -->
    <div class="modal-overlay" id="clientDetailsModal" aria-hidden="true">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalClientName">Détails du Client</h3>
                <button class="modal-close" id="closeModal" aria-label="Fermer">&times;</button>
            </div>
            <div class="modal-body" id="modalBody">
                <!-- Contenu chargé dynamiquement -->
            </div>
            
            <!-- Filtres d'export pour le client sélectionné -->
            <div class="modal-export-section" id="modalExportSection" style="display: none;">
                <div class="modal-export-header">
                    <h4>Exporter les consommations de ce client</h4>
                </div>
                <div class="export-filters">
                    <div class="filter-group">
                        <label for="modalExportPeriod">Période *</label>
                        <select id="modalExportPeriod" required>
                            <option value="all_months">Tous les mois disponibles</option>
                            <option value="specific_month">Mois spécifique</option>
                            <option value="specific_year">Toute une année</option>
                            <option value="from_first">Depuis le premier compteur reçu</option>
                        </select>
                    </div>
                    
                    <div class="filter-group" id="modalMonthFilterGroup" style="display: none;">
                        <label for="modalExportMonth">Mois</label>
                        <input type="month" id="modalExportMonth" />
                    </div>
                    
                    <div class="filter-group" id="modalYearFilterGroup" style="display: none;">
                        <label for="modalExportYear">Année</label>
                        <select id="modalExportYear">
                            <?php
                            $currentYear = (int)date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 5; $y--) {
                                echo "<option value=\"$y\">$y</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer">
                <button class="btn-export" id="exportThisClient">📊 Exporter en Excel</button>
                <button class="btn-secondary" id="closeModalBtn">Fermer</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <script>
        // Données pour le diagramme
        const chartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        
        // Initialisation du diagramme
        let consumptionChart;
        const ctx = document.getElementById('consumptionChart');
        
        function initChart(period = 'monthly') {
            const data = chartData[period];
            let labels, nbPagesData, colorPagesData, amountData;
            
            if (period === 'daily') {
                labels = data.map(d => new Date(d.date).toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' }));
                nbPagesData = data.map(d => d.nb_pages);
                colorPagesData = data.map(d => d.color_pages);
                amountData = data.map(d => d.amount);
            } else if (period === 'monthly') {
                labels = data.map(d => {
                    const date = new Date(d.month + '-01');
                    return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                });
                nbPagesData = data.map(d => d.nb_pages);
                colorPagesData = data.map(d => d.color_pages);
                amountData = data.map(d => d.amount);
            } else {
                labels = data.map(d => d.year);
                nbPagesData = data.map(d => d.nb_pages);
                colorPagesData = data.map(d => d.color_pages);
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
                            label: 'Noir et Blanc (pages)',
                            data: nbPagesData,
                            borderColor: 'rgb(59, 130, 246)',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            yAxisID: 'y',
                            tension: 0.4
                        },
                        {
                            label: 'Couleur (pages)',
                            data: colorPagesData,
                            borderColor: 'rgb(236, 72, 153)',
                            backgroundColor: 'rgba(236, 72, 153, 0.1)',
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
                                        return 'NB: ' + context.parsed.y.toLocaleString('fr-FR') + ' pages';
                                    } else if (context.datasetIndex === 1) {
                                        return 'Couleur: ' + context.parsed.y.toLocaleString('fr-FR') + ' pages';
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
        
        // Gestion de l'affichage conditionnel des champs selon le type de paiement
        document.getElementById('paymentType').addEventListener('change', function() {
            const paymentType = this.value;
            const ibanGroup = document.getElementById('ibanGroup');
            const justificatifGroup = document.getElementById('justificatifGroup');
            const ibanInput = document.getElementById('paymentIban');
            const justificatifInput = document.getElementById('paymentJustificatif');
            
            // Réinitialiser
            ibanGroup.style.display = 'none';
            justificatifGroup.style.display = 'none';
            ibanInput.removeAttribute('required');
            justificatifInput.removeAttribute('required');
            
            if (paymentType === 'virement') {
                ibanGroup.style.display = 'block';
                justificatifGroup.style.display = 'block';
                ibanInput.setAttribute('required', 'required');
                justificatifInput.setAttribute('required', 'required');
            } else if (paymentType === 'cheque') {
                justificatifGroup.style.display = 'block';
                justificatifInput.setAttribute('required', 'required');
            }
            // Espèces : aucun champ supplémentaire requis
        });

        // Mise à jour du montant dû quand on sélectionne un client
        document.getElementById('paymentClient').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const pending = selectedOption ? parseFloat(selectedOption.dataset.pending || 0) : 0;
            document.getElementById('pendingAmount').textContent = pending.toLocaleString('fr-FR', { 
                minimumFractionDigits: 2, 
                maximumFractionDigits: 2 
            });
        });

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
            const paymentType = document.getElementById('paymentType').value;
            const iban = document.getElementById('paymentIban').value.trim();
            const justificatif = document.getElementById('paymentJustificatif').files[0];
            
            if (amount > pending) {
                errorDiv.textContent = 'Le montant ne peut pas dépasser le montant dû (' + pending.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }) + ')';
                errorDiv.style.display = 'block';
                return;
            }
            
            // Validation spécifique selon le type
            if (paymentType === 'virement') {
                if (!iban) {
                    errorDiv.textContent = 'L\'IBAN est obligatoire pour un virement';
                    errorDiv.style.display = 'block';
                    return;
                }
                if (!justificatif) {
                    errorDiv.textContent = 'Le justificatif est obligatoire pour un virement';
                    errorDiv.style.display = 'block';
                    return;
                }
            } else if (paymentType === 'cheque') {
                if (!justificatif) {
                    errorDiv.textContent = 'Le justificatif est obligatoire pour un chèque';
                    errorDiv.style.display = 'block';
                    return;
                }
            }
            
            // Préparer les données pour l'envoi
            const formData = new FormData(this);
            
            // Envoyer les données au serveur
            fetch('/API/payment_process.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    successDiv.textContent = 'Paiement enregistré avec succès !';
                    successDiv.style.display = 'block';
                    
                    setTimeout(() => {
                        this.reset();
                        successDiv.style.display = 'none';
                        document.getElementById('pendingAmount').textContent = '0.00';
                        document.getElementById('ibanGroup').style.display = 'none';
                        document.getElementById('justificatifGroup').style.display = 'none';
                        // Recharger la page pour mettre à jour les données
                        location.reload();
                    }, 2000);
                } else {
                    errorDiv.textContent = data.error || 'Erreur lors de l\'enregistrement du paiement';
                    errorDiv.style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                errorDiv.textContent = 'Erreur de communication avec le serveur';
                errorDiv.style.display = 'block';
            });
        });

        // Gestion de la modal "Voir les détails"
        const clientsDataJS = <?= json_encode($clientsData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        let selectedClientId = null;

        document.querySelectorAll('.btn-view-details').forEach(btn => {
            btn.addEventListener('click', function() {
                const clientId = parseInt(this.dataset.clientId);
                selectedClientId = clientId;
                showClientDetails(clientId);
            });
        });

        function showClientDetails(clientId) {
            const client = clientsDataJS.find(c => c.id === clientId);
            if (!client) return;

            const modal = document.getElementById('clientDetailsModal');
            const modalBody = document.getElementById('modalBody');
            const modalTitle = document.getElementById('modalClientName');

            modalTitle.textContent = `Détails - ${client.name}`;
            modal.setAttribute('aria-hidden', 'false');
            modal.style.display = 'flex';

            // Générer le contenu détaillé
            let html = `
                <div class="client-detail-header">
                    <div class="detail-info">
                        <p><strong>Numéro client:</strong> ${client.numero_client}</p>
                        <p><strong>Statut:</strong> <span class="status-badge ${client.status === 'paid' ? 'status-paid' : 'status-pending'}">${client.status === 'paid' ? '✓ Payé' : '⏳ En attente'}</span></p>
                        <p><strong>Montant en attente:</strong> <span class="pending">${client.pending_amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span></p>
                    </div>
                </div>

                <div class="detail-summary">
                    <h4>Résumé Annuel</h4>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <span class="summary-card-label">Noir et Blanc</span>
                            <span class="summary-card-value">${client.total_year.nb.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span>
                        </div>
                        <div class="summary-card">
                            <span class="summary-card-label">Couleur</span>
                            <span class="summary-card-value">${client.total_year.color.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span>
                        </div>
                        <div class="summary-card total">
                            <span class="summary-card-label">Total</span>
                            <span class="summary-card-value">${client.total_year.total.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span>
                        </div>
                    </div>
                </div>

                <div class="detail-table-section">
                    <h4>Consommation Mensuelle Détaillée</h4>
                    <div class="table-container">
                        <table class="detail-table">
                            <thead>
                                <tr>
                                    <th>Mois</th>
                                    <th>NB - Pages</th>
                                    <th>NB - Montant</th>
                                    <th>Couleur - Pages</th>
                                    <th>Couleur - Montant</th>
                                    <th>Total Pages</th>
                                    <th>Total Montant</th>
                                </tr>
                            </thead>
                            <tbody>
            `;

            client.monthly_consumption.forEach(month => {
                const monthName = new Date(month.month + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                html += `
                    <tr>
                        <td>${monthName}</td>
                        <td>${month.nb.pages.toLocaleString('fr-FR')}</td>
                        <td>${month.nb.amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</td>
                        <td>${month.color.pages.toLocaleString('fr-FR')}</td>
                        <td>${month.color.amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</td>
                        <td><strong>${month.total.pages.toLocaleString('fr-FR')}</strong></td>
                        <td><strong>${month.total.amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</strong></td>
                    </tr>
                `;
            });

            html += `
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            // Section Factures
            html += `
                <div class="detail-invoices-section">
                    <h4>Factures</h4>
                    <div class="invoices-list">
            `;
            
            if (client.invoices && client.invoices.length > 0) {
                client.invoices.forEach(invoice => {
                    const invoiceDate = new Date(invoice.invoice_date);
                    const dueDate = new Date(invoice.due_date);
                    const periodStart = new Date(invoice.period_start);
                    const periodEnd = new Date(invoice.period_end);
                    
                    let statusClass = 'invoice-status-';
                    let statusText = '';
                    if (invoice.status === 'paid') {
                        statusClass += 'paid';
                        statusText = '✓ Payée';
                    } else if (invoice.status === 'overdue') {
                        statusClass += 'overdue';
                        statusText = '⚠ En retard';
                    } else {
                        statusClass += 'pending';
                        statusText = '⏳ En attente';
                    }
                    
                    html += `
                        <div class="invoice-item">
                            <div class="invoice-header">
                                <div class="invoice-info">
                                    <span class="invoice-number"><strong>${invoice.invoice_number}</strong></span>
                                    <span class="invoice-date">Date: ${invoiceDate.toLocaleDateString('fr-FR')}</span>
                                    <span class="invoice-period">Période: ${periodStart.toLocaleDateString('fr-FR')} - ${periodEnd.toLocaleDateString('fr-FR')}</span>
                                </div>
                                <div class="invoice-actions">
                                    <span class="invoice-status ${statusClass}">${statusText}</span>
                                    <button class="btn-download-invoice" data-invoice-id="${invoice.invoice_number}" data-client-id="${client.id}">
                                        📥 Télécharger
                                    </button>
                                </div>
                            </div>
                            <div class="invoice-details">
                                <div class="invoice-detail-row">
                                    <span>NB: ${invoice.nb_pages.toLocaleString('fr-FR')} pages</span>
                                    <span class="invoice-amount">${invoice.nb_amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span>
                                </div>
                                <div class="invoice-detail-row">
                                    <span>Couleur: ${invoice.color_pages.toLocaleString('fr-FR')} pages</span>
                                    <span class="invoice-amount">${invoice.color_amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</span>
                                </div>
                                <div class="invoice-detail-row total">
                                    <span><strong>Total: ${invoice.total_pages.toLocaleString('fr-FR')} pages</strong></span>
                                    <span class="invoice-amount"><strong>${invoice.total_amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}</strong></span>
                                </div>
                                <div class="invoice-due-date">
                                    <span>Échéance: ${dueDate.toLocaleDateString('fr-FR')}</span>
                                </div>
                            </div>
                        </div>
                    `;
                });
            } else {
                html += `<p class="no-invoices">Aucune facture disponible</p>`;
            }
            
            html += `
                    </div>
                </div>
            `;

            modalBody.innerHTML = html;
            // Afficher la section d'export avec filtres
            document.getElementById('modalExportSection').style.display = 'block';
            
            // Attacher les événements de téléchargement
            document.querySelectorAll('.btn-download-invoice').forEach(btn => {
                btn.addEventListener('click', function() {
                    const invoiceNumber = this.dataset.invoiceId;
                    const clientId = parseInt(this.dataset.clientId);
                    downloadInvoice(clientId, invoiceNumber);
                });
            });
        }
        
        // Fonction pour télécharger une facture en PDF
        function downloadInvoice(clientId, invoiceNumber) {
            const client = clientsDataJS.find(c => c.id === clientId);
            if (!client) {
                alert('Client introuvable');
                return;
            }
            
            const invoice = client.invoices.find(inv => inv.invoice_number === invoiceNumber);
            if (!invoice) {
                alert('Facture introuvable');
                return;
            }
            
            // Télécharger le PDF depuis l'API
            const url = `/API/generate_invoice_pdf.php?client_id=${clientId}&invoice_number=${encodeURIComponent(invoiceNumber)}`;
            window.open(url, '_blank');
        }

        // Fermeture de la modal
        document.getElementById('closeModal').addEventListener('click', closeModal);
        document.getElementById('closeModalBtn').addEventListener('click', closeModal);
        document.getElementById('clientDetailsModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        function closeModal() {
            const modal = document.getElementById('clientDetailsModal');
            modal.setAttribute('aria-hidden', 'true');
            modal.style.display = 'none';
            selectedClientId = null;
            document.getElementById('modalExportSection').style.display = 'none';
            // Réinitialiser les filtres
            document.getElementById('modalExportPeriod').value = 'all_months';
            document.getElementById('modalMonthFilterGroup').style.display = 'none';
            document.getElementById('modalYearFilterGroup').style.display = 'none';
        }

        // Gestion des filtres d'export (tous les clients)
        document.getElementById('exportPeriod').addEventListener('change', function() {
            const period = this.value;
            const monthGroup = document.getElementById('monthFilterGroup');
            const yearGroup = document.getElementById('yearFilterGroup');
            
            monthGroup.style.display = (period === 'specific_month') ? 'block' : 'none';
            yearGroup.style.display = (period === 'specific_year') ? 'block' : 'none';
        });

        // Gestion des filtres d'export (client sélectionné dans la modal)
        document.getElementById('modalExportPeriod').addEventListener('change', function() {
            const period = this.value;
            const monthGroup = document.getElementById('modalMonthFilterGroup');
            const yearGroup = document.getElementById('modalYearFilterGroup');
            
            monthGroup.style.display = (period === 'specific_month') ? 'block' : 'none';
            yearGroup.style.display = (period === 'specific_year') ? 'block' : 'none';
        });

        // Fonction pour filtrer les données selon la période
        function filterConsumptionData(consumptionArray, period, monthValue, yearValue) {
            if (period === 'all_months') {
                return consumptionArray;
            } else if (period === 'specific_month') {
                if (!monthValue) return [];
                const [year, month] = monthValue.split('-');
                return consumptionArray.filter(m => {
                    const [mYear, mMonth] = m.month.split('-');
                    return mYear === year && mMonth === month;
                });
            } else if (period === 'specific_year') {
                if (!yearValue) return [];
                return consumptionArray.filter(m => {
                    const [mYear] = m.month.split('-');
                    return mYear === yearValue;
                });
            } else if (period === 'from_first') {
                // Retourner tous les mois (depuis le premier compteur = tous les mois disponibles)
                return consumptionArray;
            }
            return consumptionArray;
        }

        // Fonction d'export Excel
        function exportToExcel(data, filename) {
            if (data.length === 0) {
                alert('Aucune donnée à exporter pour les critères sélectionnés.');
                return;
            }
            
            const wb = XLSX.utils.book_new();
            
            // Feuille de données
            const ws = XLSX.utils.json_to_sheet(data);
            XLSX.utils.book_append_sheet(wb, ws, 'Consommations');
            
            // Télécharger
            XLSX.writeFile(wb, filename);
        }

        // Export tous les clients (bouton en haut après le diagramme)
        document.getElementById('exportAllClients').addEventListener('click', function() {
            const period = document.getElementById('exportPeriod').value;
            const monthValue = document.getElementById('exportMonth').value;
            const yearValue = document.getElementById('exportYear').value;
            
            // Validation
            if (period === 'specific_month' && !monthValue) {
                alert('Veuillez sélectionner un mois');
                return;
            }
            if (period === 'specific_year' && !yearValue) {
                alert('Veuillez sélectionner une année');
                return;
            }
            
            const exportData = [];
            
            // Export tous les clients
            clientsDataJS.forEach(client => {
                const filteredMonths = filterConsumptionData(client.monthly_consumption, period, monthValue, yearValue);
                filteredMonths.forEach(month => {
                    exportData.push({
                        'Client': client.name,
                        'Numéro Client': client.numero_client,
                        'Mois': new Date(month.month + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
                        'NB - Pages': month.nb.pages,
                        'NB - Montant (€)': month.nb.amount,
                        'Couleur - Pages': month.color.pages,
                        'Couleur - Montant (€)': month.color.amount,
                        'Total Pages': month.total.pages,
                        'Total Montant (€)': month.total.amount
                    });
                });
            });
            
            // Générer le nom de fichier selon la période
            let filename = '';
            if (period === 'specific_month') {
                const monthName = new Date(monthValue + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                filename = `Consommations_Tous_Clients_${monthName.replace(/\s+/g, '_')}.xlsx`;
            } else if (period === 'specific_year') {
                filename = `Consommations_Tous_Clients_Annee_${yearValue}.xlsx`;
            } else if (period === 'from_first') {
                filename = `Consommations_Tous_Clients_Depuis_Premier_Compteur_${new Date().toISOString().split('T')[0]}.xlsx`;
            } else {
                filename = `Consommations_Tous_Clients_Tous_Mois_${new Date().toISOString().split('T')[0]}.xlsx`;
            }
            
            exportToExcel(exportData, filename);
        });

        // Export client sélectionné depuis la modal
        document.getElementById('exportThisClient').addEventListener('click', function() {
            if (!selectedClientId) {
                alert('Aucun client sélectionné');
                return;
            }
            
            const period = document.getElementById('modalExportPeriod').value;
            const monthValue = document.getElementById('modalExportMonth').value;
            const yearValue = document.getElementById('modalExportYear').value;
            
            // Validation
            if (period === 'specific_month' && !monthValue) {
                alert('Veuillez sélectionner un mois');
                return;
            }
            if (period === 'specific_year' && !yearValue) {
                alert('Veuillez sélectionner une année');
                return;
            }
            
            const client = clientsDataJS.find(c => c.id === selectedClientId);
            if (!client) {
                alert('Client introuvable');
                return;
            }
            
            const filteredMonths = filterConsumptionData(client.monthly_consumption, period, monthValue, yearValue);
            const exportData = filteredMonths.map(month => ({
                'Mois': new Date(month.month + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' }),
                'NB - Pages': month.nb.pages,
                'NB - Montant (€)': month.nb.amount,
                'Couleur - Pages': month.color.pages,
                'Couleur - Montant (€)': month.color.amount,
                'Total Pages': month.total.pages,
                'Total Montant (€)': month.total.amount
            }));
            
            // Générer le nom de fichier selon la période
            const clientName = client.name.replace(/\s+/g, '_');
            let filename = '';
            if (period === 'specific_month') {
                const monthName = new Date(monthValue + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                filename = `Consommations_${clientName}_${monthName.replace(/\s+/g, '_')}.xlsx`;
            } else if (period === 'specific_year') {
                filename = `Consommations_${clientName}_Annee_${yearValue}.xlsx`;
            } else if (period === 'from_first') {
                filename = `Consommations_${clientName}_Depuis_Premier_Compteur_${new Date().toISOString().split('T')[0]}.xlsx`;
            } else {
                filename = `Consommations_${clientName}_Tous_Mois_${new Date().toISOString().split('T')[0]}.xlsx`;
            }
            
            exportToExcel(exportData, filename);
        });
    </script>
</body>
</html>

