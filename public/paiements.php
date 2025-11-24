<?php
// /public/paiements.php
// Page de gestion des paiements et factures - Version refaite complète

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// ============================================
// RÉCUPÉRATION DES DONNÉES DEPUIS LA BASE
// ============================================

// Récupérer tous les clients
$clients = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, numero_client, raison_sociale
        FROM clients
        ORDER BY raison_sociale ASC
    ");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('paiements.php - Erreur récupération clients: ' . $e->getMessage());
    $clients = [];
}

// Récupérer les données de consommation depuis compteur_relevee
// On agrège par jour, mois et année pour tous les clients ou un client spécifique
function getConsumptionData($pdo, $clientId = null, $dateStart = null, $dateEnd = null) {
    $data = [
        'daily' => [],
        'monthly' => [],
        'yearly' => []
    ];
    
    try {
        // Jointure avec photocopieurs_clients pour filtrer par client
        $clientFilter = '';
        $params = [];
        
        if ($clientId) {
            $clientFilter = "AND pc.id_client = :client_id";
            $params[':client_id'] = $clientId;
        }
        
        $dateFilter = '';
        if ($dateStart) {
            $dateFilter .= "AND DATE(cr.Timestamp) >= :date_start ";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd) {
            $dateFilter .= "AND DATE(cr.Timestamp) <= :date_end ";
            $params[':date_end'] = $dateEnd;
        }
        
        // Données quotidiennes (30 derniers jours si pas de filtre de date)
        if (!$dateStart && !$dateEnd) {
            $dateStart = date('Y-m-d', strtotime('-30 days'));
            $dateEnd = date('Y-m-d');
        }
        
        // Requête pour données quotidiennes
        $sqlDaily = "
            SELECT 
                DATE(cr.Timestamp) as date,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY DATE(cr.Timestamp)
            ORDER BY date DESC
            LIMIT 365
        ";
        
        $stmt = $pdo->prepare($sqlDaily);
        $stmt->execute($params);
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($dailyRows as $row) {
            $data['daily'][] = [
                'date' => $row['date'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
        // Requête pour données mensuelles
        $sqlMonthly = "
            SELECT 
                DATE_FORMAT(cr.Timestamp, '%Y-%m') as month,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY DATE_FORMAT(cr.Timestamp, '%Y-%m')
            ORDER BY month DESC
            LIMIT 24
        ";
        
        $stmt = $pdo->prepare($sqlMonthly);
        $stmt->execute($params);
        $monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($monthlyRows as $row) {
            $data['monthly'][] = [
                'month' => $row['month'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
        // Requête pour données annuelles
        $sqlYearly = "
            SELECT 
                YEAR(cr.Timestamp) as year,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY YEAR(cr.Timestamp)
            ORDER BY year DESC
            LIMIT 10
        ";
        
        $stmt = $pdo->prepare($sqlYearly);
        $stmt->execute($params);
        $yearlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($yearlyRows as $row) {
            $data['yearly'][] = [
                'year' => (string)$row['year'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
    } catch (PDOException $e) {
        error_log('paiements.php - Erreur récupération consommation: ' . $e->getMessage());
    }
    
    return $data;
}

// Récupérer les données par défaut (tous les clients, 12 derniers mois)
$defaultDateStart = date('Y-m-d', strtotime('-12 months'));
$defaultDateEnd = date('Y-m-d');
$chartData = getConsumptionData($pdo, null, $defaultDateStart, $defaultDateEnd);

// Si pas de données, générer des données factices pour le développement
if (empty($chartData['daily']) && empty($chartData['monthly']) && empty($chartData['yearly'])) {
    // Génération de données factices pour le développement
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
        $chartData['daily'][] = [
            'date' => $date,
            'nb_pages' => $nbPages,
            'color_pages' => $colorPages,
            'total_pages' => $nbPages + $colorPages,
            'amount' => round($nbPages * 0.03 + $colorPages * 0.15, 2)
        ];
    }
    
    // Données mensuelles (12 derniers mois)
    for ($m = 11; $m >= 0; $m--) {
        $month = date('Y-m', strtotime("-$m months"));
        $nbPages = rand(200000, 800000);
        $colorPages = rand(20000, 150000);
        $chartData['monthly'][] = [
            'month' => $month,
            'nb_pages' => $nbPages,
            'color_pages' => $colorPages,
            'total_pages' => $nbPages + $colorPages,
            'amount' => round($nbPages * 0.03 + $colorPages * 0.15, 2)
        ];
    }
    
    // Données annuelles (5 dernières années)
    for ($y = 4; $y >= 0; $y--) {
        $year = date('Y', strtotime("-$y years"));
        $nbPages = rand(2000000, 8000000);
        $colorPages = rand(200000, 1500000);
        $chartData['yearly'][] = [
            'year' => $year,
            'nb_pages' => $nbPages,
            'color_pages' => $colorPages,
            'total_pages' => $nbPages + $colorPages,
            'amount' => round($nbPages * 0.03 + $colorPages * 0.15, 2)
        ];
    }
}

// ============================================
// CALCUL DES ESTIMATIONS
// ============================================

function calculateEstimations($data) {
    $estimations = [
        'next_months' => [],
        'next_year' => null
    ];
    
    if (empty($data['monthly'])) {
        return $estimations;
    }
    
    // Prendre les 6 derniers mois pour calculer la moyenne et la tendance
    $recentMonths = array_slice($data['monthly'], 0, 6);
    $recentMonths = array_reverse($recentMonths); // Du plus ancien au plus récent
    
    if (count($recentMonths) < 2) {
        return $estimations;
    }
    
    // Calculer la moyenne et la tendance
    $avgNB = 0;
    $avgColor = 0;
    $trendNB = 0;
    $trendColor = 0;
    
    foreach ($recentMonths as $month) {
        $avgNB += $month['nb_pages'];
        $avgColor += $month['color_pages'];
    }
    $avgNB = $avgNB / count($recentMonths);
    $avgColor = $avgColor / count($recentMonths);
    
    // Calcul de la tendance (régression linéaire simple)
    $n = count($recentMonths);
    $sumX = 0;
    $sumYNB = 0;
    $sumYColor = 0;
    $sumXYNB = 0;
    $sumXYColor = 0;
    $sumX2 = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $x = $i;
        $yNB = $recentMonths[$i]['nb_pages'];
        $yColor = $recentMonths[$i]['color_pages'];
        
        $sumX += $x;
        $sumYNB += $yNB;
        $sumYColor += $yColor;
        $sumXYNB += $x * $yNB;
        $sumXYColor += $x * $yColor;
        $sumX2 += $x * $x;
    }
    
    if ($n > 1 && $sumX2 > 0) {
        $trendNB = ($n * $sumXYNB - $sumX * $sumYNB) / ($n * $sumX2 - $sumX * $sumX);
        $trendColor = ($n * $sumXYColor - $sumX * $sumYColor) / ($n * $sumX2 - $sumX * $sumX);
    }
    
    // Générer les prévisions pour les 6 prochains mois
    $lastMonth = $recentMonths[count($recentMonths) - 1];
    $lastMonthDate = new DateTime($lastMonth['month'] . '-01');
    
    for ($i = 1; $i <= 6; $i++) {
        $forecastDate = clone $lastMonthDate;
        $forecastDate->modify("+$i months");
        $monthKey = $forecastDate->format('Y-m');
        
        // Estimation basée sur la moyenne + tendance
        $estimatedNB = max(0, $avgNB + ($trendNB * ($n + $i)));
        $estimatedColor = max(0, $avgColor + ($trendColor * ($n + $i)));
        $estimatedTotal = $estimatedNB + $estimatedColor;
        $estimatedAmount = round($estimatedNB * 0.03 + $estimatedColor * 0.15, 2);
        
        $estimations['next_months'][] = [
            'month' => $monthKey,
            'nb_pages' => (int)$estimatedNB,
            'color_pages' => (int)$estimatedColor,
            'total_pages' => (int)$estimatedTotal,
            'amount' => $estimatedAmount,
            'is_forecast' => true
        ];
    }
    
    // Estimation pour l'année prochaine (moyenne des 12 derniers mois * 12)
    $yearlyData = array_slice($data['yearly'], 0, 1);
    if (!empty($yearlyData)) {
        $currentYear = $yearlyData[0];
        $nextYear = (string)((int)$currentYear['year'] + 1);
        
        $estimations['next_year'] = [
            'year' => $nextYear,
            'nb_pages' => (int)($avgNB * 12),
            'color_pages' => (int)($avgColor * 12),
            'total_pages' => (int)(($avgNB + $avgColor) * 12),
            'amount' => round(($avgNB * 0.03 + $avgColor * 0.15) * 12, 2),
            'is_forecast' => true
        ];
    }
    
    return $estimations;
}

$estimations = calculateEstimations($chartData);

// Récupérer l'historique des paiements
$paymentHistory = [];
try {
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'paiements'
    ");
    $checkTable->execute();
    $tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
    
    if ($tableExists) {
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.client_id,
                c.raison_sociale as client_name,
                c.numero_client,
                p.montant as amount,
                p.type_paiement as type,
                p.date_paiement as date,
                p.reference,
                p.justificatif_upload,
                p.justificatif_pdf,
                p.numero_justificatif,
                'completed' as status
            FROM paiements p
            INNER JOIN clients c ON p.client_id = c.id
            ORDER BY p.date_paiement DESC, p.date_creation DESC
            LIMIT 100
        ");
        $stmt->execute();
        $dbPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($dbPayments as $payment) {
            $typeLabels = [
                'especes' => 'Espèces',
                'cheque' => 'Chèque',
                'virement' => 'Virement'
            ];
            
            $paymentHistory[] = [
                'id' => (int)$payment['id'],
                'client_id' => (int)$payment['client_id'],
                'client_name' => $payment['client_name'],
                'amount' => (float)$payment['amount'],
                'date' => $payment['date'],
                'type' => $typeLabels[$payment['type']] ?? ucfirst($payment['type']),
                'status' => 'completed',
                'reference' => $payment['reference'] ?: 'PAY-' . date('Y') . '-' . str_pad($payment['id'], 5, '0', STR_PAD_LEFT),
                'justificatif_upload' => $payment['justificatif_upload'],
                'justificatif_pdf' => $payment['justificatif_pdf'],
                'numero_justificatif' => $payment['numero_justificatif']
            ];
        }
    }
} catch (PDOException $e) {
    error_log('paiements.php - Erreur récupération historique: ' . $e->getMessage());
    $paymentHistory = [];
}

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
    <style>
        /* Styles additionnels pour la nouvelle version */
        .filters-section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-weight: 500;
            color: var(--text-primary);
            font-size: 0.875rem;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.625rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-size: 0.875rem;
            transition: border-color 0.2s;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        
        .btn-filter {
            padding: 0.625rem 1.25rem;
            background: var(--accent-primary);
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-filter:hover {
            background: var(--accent-secondary);
        }
        
        .btn-reset {
            padding: 0.625rem 1.25rem;
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .btn-reset:hover {
            background: var(--border-color);
        }
        
        .estimations-section {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-sm);
        }
        
        .estimations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .estimation-card {
            padding: 1rem;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
        }
        
        .estimation-card-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }
        
        .estimation-card-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .chart-container {
            position: relative;
            height: 400px;
            margin-top: 1rem;
        }
        
        .forecast-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .forecast-indicator.real {
            background: #3b82f6;
        }
        
        .forecast-indicator.forecast {
            background: #f59e0b;
        }
    </style>
</head>
<body class="page-paiements">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="paiements-wrapper">
        <div class="paiements-header">
            <h2 class="page-title">Gestion des Paiements</h2>
            <p class="page-subtitle">Consommation et facturation des clients</p>
        </div>

        <!-- Section Filtres -->
        <div class="filters-section">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.25rem; font-weight: 600;">Filtres de Consommation</h3>
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filterClient">Client</label>
                    <select id="filterClient">
                        <option value="">Tous les clients</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= $client['id'] ?>">
                                <?= h($client['raison_sociale']) ?> (<?= h($client['numero_client']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filterDateStart">Date de début</label>
                    <input type="date" id="filterDateStart" value="<?= $defaultDateStart ?>" />
                </div>
                
                <div class="filter-group">
                    <label for="filterDateEnd">Date de fin</label>
                    <input type="date" id="filterDateEnd" value="<?= $defaultDateEnd ?>" />
                </div>
            </div>
            
            <div class="filter-actions">
                <button class="btn-filter" id="applyFilters">Appliquer les filtres</button>
                <button class="btn-reset" id="resetFilters">Réinitialiser</button>
            </div>
        </div>

        <!-- Section Estimations -->
        <div class="estimations-section">
            <h3 style="margin: 0 0 1rem 0; font-size: 1.25rem; font-weight: 600;">Prévisions de Consommation</h3>
            <div class="estimations-grid" id="estimationsGrid">
                <!-- Rempli dynamiquement par JavaScript -->
            </div>
        </div>

        <!-- Diagramme de consommation -->
        <div class="chart-section">
            <div class="chart-header">
                <h3>Diagramme de Consommation de Papier</h3>
                <div class="chart-controls">
                    <button class="chart-btn active" data-period="daily">Jour</button>
                    <button class="chart-btn" data-period="monthly">Mois</button>
                    <button class="chart-btn" data-period="yearly">Année</button>
                </div>
            </div>
            <div class="chart-container">
                <canvas id="consumptionChart"></canvas>
            </div>
            <div style="margin-top: 1rem; font-size: 0.875rem; color: var(--text-secondary);">
                <span><span class="forecast-indicator real"></span> Données réelles</span>
                <span style="margin-left: 1rem;"><span class="forecast-indicator forecast"></span> Prévisions</span>
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
                            <?php foreach ($clients as $client): ?>
                                <option value="<?= $client['id'] ?>">
                                    <?= h($client['raison_sociale']) ?> (<?= h($client['numero_client']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="paymentAmount">Montant (€) *</label>
                            <input type="number" id="paymentAmount" name="amount" step="0.01" min="0.01" required 
                                   placeholder="0.00" />
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

                    <div class="form-group" id="ibanGroup" style="display: none;">
                        <label for="paymentIban">IBAN du client *</label>
                        <input type="text" id="paymentIban" name="iban" 
                               placeholder="FR76 XXXX XXXX XXXX XXXX XXXX XXX" />
                    </div>

                    <div class="form-group" id="justificatifGroup" style="display: none;">
                        <label for="paymentJustificatif">Justificatif de paiement *</label>
                        <input type="file" id="paymentJustificatif" name="justificatif" 
                               accept=".pdf,.jpg,.jpeg,.png" />
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
                            <th>Justificatif</th>
                            <th>Statut</th>
                        </tr>
                    </thead>
                    <tbody id="historyTableBody">
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?= !empty($payment['date']) ? h(date('d/m/Y', strtotime($payment['date']))) : '—' ?></td>
                                <td><?= h($payment['client_name']) ?></td>
                                <td class="amount-cell"><?= number_format($payment['amount'], 2, ',', ' ') ?> €</td>
                                <td><?= h($payment['type']) ?></td>
                                <td><?= h($payment['reference']) ?></td>
                                <td>
                                    <?php if (!empty($payment['justificatif_pdf'])): ?>
                                        <a href="<?= h($payment['justificatif_pdf']) ?>" 
                                           target="_blank" 
                                           class="btn-download-receipt">📄 PDF</a>
                                    <?php elseif (!empty($payment['justificatif_upload'])): ?>
                                        <a href="<?= h($payment['justificatif_upload']) ?>" 
                                           target="_blank"
                                           class="btn-download-receipt">📎 Fichier</a>
                                    <?php else: ?>
                                        <span class="no-receipt">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?= $payment['status'] ?>">
                                        ✓ Complété
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
        // ============================================
        // DONNÉES INITIALES
        // ============================================
        const chartData = <?= json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const estimations = <?= json_encode($estimations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const clients = <?= json_encode($clients, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        
        let consumptionChart = null;
        let currentPeriod = 'monthly';
        let currentFilters = {
            clientId: null,
            dateStart: null,
            dateEnd: null
        };
        
        // ============================================
        // INITIALISATION DES ESTIMATIONS
        // ============================================
        function updateEstimationsDisplay() {
            const grid = document.getElementById('estimationsGrid');
            if (!grid) return;
            
            let html = '';
            
            // Afficher les prévisions pour les prochains mois
            if (estimations.next_months && estimations.next_months.length > 0) {
                estimations.next_months.slice(0, 3).forEach(month => {
                    const monthName = new Date(month.month + '-01').toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
                    html += `
                        <div class="estimation-card">
                            <div class="estimation-card-label">${monthName}</div>
                            <div class="estimation-card-value">${month.total_pages.toLocaleString('fr-FR')} pages</div>
                            <div style="font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.5rem;">
                                ${month.amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}
                            </div>
                        </div>
                    `;
                });
            }
            
            // Afficher l'estimation pour l'année prochaine
            if (estimations.next_year) {
                html += `
                    <div class="estimation-card" style="border: 2px solid var(--accent-primary);">
                        <div class="estimation-card-label">Année ${estimations.next_year.year}</div>
                        <div class="estimation-card-value">${estimations.next_year.total_pages.toLocaleString('fr-FR')} pages</div>
                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-top: 0.5rem;">
                            ${estimations.next_year.amount.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' })}
                        </div>
                    </div>
                `;
            }
            
            grid.innerHTML = html || '<p style="color: var(--text-secondary);">Aucune prévision disponible</p>';
        }
        
        // ============================================
        // INITIALISATION DU DIAGRAMME
        // ============================================
        function initChart(period = 'monthly', data = null, forecastData = null) {
            const ctx = document.getElementById('consumptionChart');
            if (!ctx) return;
            
            const chartDataToUse = data || chartData[period] || [];
            let labels = [];
            let nbPagesData = [];
            let colorPagesData = [];
            let amountData = [];
            let isForecastData = [];
            
            // Préparer les données selon la période
            if (period === 'daily') {
                labels = chartDataToUse.map(d => {
                    const date = new Date(d.date);
                    return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
                });
                nbPagesData = chartDataToUse.map(d => d.nb_pages || 0);
                colorPagesData = chartDataToUse.map(d => d.color_pages || 0);
                amountData = chartDataToUse.map(d => d.amount || 0);
                isForecastData = chartDataToUse.map(d => d.is_forecast || false);
            } else if (period === 'monthly') {
                // Combiner les données réelles et les prévisions
                const allData = [...chartDataToUse];
                if (forecastData && forecastData.next_months) {
                    allData.push(...forecastData.next_months);
                }
                
                labels = allData.map(d => {
                    const date = new Date(d.month + '-01');
                    return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                });
                nbPagesData = allData.map(d => d.nb_pages || 0);
                colorPagesData = allData.map(d => d.color_pages || 0);
                amountData = allData.map(d => d.amount || 0);
                isForecastData = allData.map(d => d.is_forecast || false);
            } else {
                labels = chartDataToUse.map(d => d.year);
                nbPagesData = chartDataToUse.map(d => d.nb_pages || 0);
                colorPagesData = chartDataToUse.map(d => d.color_pages || 0);
                amountData = chartDataToUse.map(d => d.amount || 0);
                isForecastData = chartDataToUse.map(d => d.is_forecast || false);
            }
            
            if (consumptionChart) {
                consumptionChart.destroy();
            }
            
            // Trouver l'index où commencent les prévisions
            const forecastStartIndex = isForecastData.indexOf(true);
            const hasForecast = forecastStartIndex !== -1;
            
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
                            tension: 0.4,
                            borderDash: hasForecast ? (() => {
                                const dash = new Array(labels.length).fill(0);
                                for (let i = forecastStartIndex; i < labels.length; i++) {
                                    dash[i] = 5;
                                }
                                return dash;
                            })() : []
                        },
                        {
                            label: 'Couleur (pages)',
                            data: colorPagesData,
                            borderColor: 'rgb(236, 72, 153)',
                            backgroundColor: 'rgba(236, 72, 153, 0.1)',
                            yAxisID: 'y',
                            tension: 0.4,
                            borderDash: hasForecast ? (() => {
                                const dash = new Array(labels.length).fill(0);
                                for (let i = forecastStartIndex; i < labels.length; i++) {
                                    dash[i] = 5;
                                }
                                return dash;
                            })() : []
                        },
                        {
                            label: 'Montant (€)',
                            data: amountData,
                            borderColor: 'rgb(16, 185, 129)',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            yAxisID: 'y1',
                            tension: 0.4,
                            borderDash: hasForecast ? (() => {
                                const dash = new Array(labels.length).fill(0);
                                for (let i = forecastStartIndex; i < labels.length; i++) {
                                    dash[i] = 5;
                                }
                                return dash;
                            })() : []
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
                                    const isForecast = isForecastData[context.dataIndex];
                                    const forecastLabel = isForecast ? ' (Prévision)' : '';
                                    if (context.datasetIndex === 0) {
                                        return 'NB: ' + context.parsed.y.toLocaleString('fr-FR') + ' pages' + forecastLabel;
                                    } else if (context.datasetIndex === 1) {
                                        return 'Couleur: ' + context.parsed.y.toLocaleString('fr-FR') + ' pages' + forecastLabel;
                                    } else {
                                        return 'Montant: ' + context.parsed.y.toLocaleString('fr-FR', { style: 'currency', currency: 'EUR' }) + forecastLabel;
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
        
        // ============================================
        // GESTION DES FILTRES
        // ============================================
        document.getElementById('applyFilters')?.addEventListener('click', async function() {
            const clientId = document.getElementById('filterClient').value;
            const dateStart = document.getElementById('filterDateStart').value;
            const dateEnd = document.getElementById('filterDateEnd').value;
            
            currentFilters = {
                clientId: clientId || null,
                dateStart: dateStart || null,
                dateEnd: dateEnd || null
            };
            
            // Charger les nouvelles données depuis le serveur
            try {
                const params = new URLSearchParams();
                if (clientId) params.append('client_id', clientId);
                if (dateStart) params.append('date_start', dateStart);
                if (dateEnd) params.append('date_end', dateEnd);
                
                const response = await fetch(`/API/get_consumption_data.php?${params.toString()}`);
                const result = await response.json();
                
                if (result.ok && result.data) {
                    // Mettre à jour le diagramme avec les nouvelles données
                    initChart(currentPeriod, result.data, result.estimations);
                } else {
                    alert('Erreur lors du chargement des données filtrées');
                }
            } catch (error) {
                console.error('Erreur:', error);
                alert('Erreur de communication avec le serveur');
            }
        });
        
        document.getElementById('resetFilters')?.addEventListener('click', function() {
            document.getElementById('filterClient').value = '';
            document.getElementById('filterDateStart').value = '<?= $defaultDateStart ?>';
            document.getElementById('filterDateEnd').value = '<?= $defaultDateEnd ?>';
            currentFilters = {
                clientId: null,
                dateStart: null,
                dateEnd: null
            };
            initChart(currentPeriod, chartData, estimations);
        });
        
        // ============================================
        // GESTION DES BOUTONS DE PÉRIODE
        // ============================================
        document.querySelectorAll('.chart-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.querySelectorAll('.chart-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                currentPeriod = this.dataset.period || 'monthly';
                initChart(currentPeriod, chartData[currentPeriod], currentPeriod === 'monthly' ? estimations : null);
            });
        });
        
        // ============================================
        // GESTION DU FORMULAIRE DE PAIEMENT
        // ============================================
        const paymentTypeEl = document.getElementById('paymentType');
        if (paymentTypeEl) {
            paymentTypeEl.addEventListener('change', function() {
                const paymentType = this.value;
                const ibanGroup = document.getElementById('ibanGroup');
                const justificatifGroup = document.getElementById('justificatifGroup');
                const ibanInput = document.getElementById('paymentIban');
                const justificatifInput = document.getElementById('paymentJustificatif');
                
                ibanGroup.style.display = 'none';
                justificatifGroup.style.display = 'none';
                ibanInput?.removeAttribute('required');
                justificatifInput?.removeAttribute('required');
                
                if (paymentType === 'virement') {
                    ibanGroup.style.display = 'block';
                    justificatifGroup.style.display = 'block';
                    ibanInput?.setAttribute('required', 'required');
                    justificatifInput?.setAttribute('required', 'required');
                } else if (paymentType === 'cheque') {
                    justificatifGroup.style.display = 'block';
                    justificatifInput?.setAttribute('required', 'required');
                }
            });
        }
        
        const paymentFormEl = document.getElementById('paymentForm');
        if (paymentFormEl) {
            paymentFormEl.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const errorDiv = document.getElementById('paymentError');
                const successDiv = document.getElementById('paymentSuccess');
                errorDiv.style.display = 'none';
                successDiv.style.display = 'none';
                
                const formData = new FormData(this);
                const submitBtn = this.querySelector('button[type="submit"]');
                const originalBtnText = submitBtn?.textContent || '';
                
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Enregistrement en cours...';
                }
                
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
                            location.reload();
                        }, 2000);
                    } else {
                        errorDiv.textContent = data.error || 'Erreur lors de l\'enregistrement';
                        errorDiv.style.display = 'block';
                    }
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    errorDiv.textContent = 'Erreur de communication avec le serveur';
                    errorDiv.style.display = 'block';
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalBtnText;
                    }
                });
            });
        }
        
        // ============================================
        // INITIALISATION
        // ============================================
        updateEstimationsDisplay();
        initChart('monthly', chartData['monthly'], estimations);
    </script>
</body>
</html>
