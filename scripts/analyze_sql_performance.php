<?php
/**
 * Script d'analyse des performances SQL
 * 
 * Identifie les requêtes potentiellement lourdes et les problèmes de performance
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db_connection.php';

configureErrorReporting(true);

echo "=== ANALYSE DES PERFORMANCES SQL ===\n\n";

$pdo = getPdo();
$issues = [];
$recommendations = [];

// ====================================================================
// ANALYSE 1 : Vérifier les index sur les colonnes fréquemment utilisées
// ====================================================================
echo "ANALYSE 1 : Vérification des index\n";

$tablesToCheck = [
    'clients' => ['numero_client', 'raison_sociale', 'id'],
    'sav' => ['id_client', 'statut', 'priorite', 'date_ouverture'],
    'livraisons' => ['id_client', 'statut', 'date_livraison'],
    'compteur_relevee' => ['mac_norm', 'Timestamp'],
    'historique' => ['id_utilisateur', 'date_action', 'type_action'],
    'photocopieurs_clients' => ['id_client', 'mac_norm'],
];

foreach ($tablesToCheck as $table => $columns) {
    try {
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table`");
        $stmt->execute();
        $indexes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $indexedColumns = [];
        foreach ($indexes as $index) {
            $indexedColumns[] = $index['Column_name'];
        }
        
        $missingIndexes = [];
        foreach ($columns as $column) {
            if (!in_array($column, $indexedColumns, true)) {
                $missingIndexes[] = $column;
            }
        }
        
        if (count($missingIndexes) > 0) {
            $issues[] = [
                'type' => 'missing_index',
                'table' => $table,
                'columns' => $missingIndexes,
                'severity' => 'medium'
            ];
            echo "  ⚠ Table `$table` : index manquants sur " . implode(', ', $missingIndexes) . "\n";
        } else {
            echo "  ✓ Table `$table` : tous les index nécessaires sont présents\n";
        }
    } catch (PDOException $e) {
        echo "  ✗ Erreur lors de l'analyse de `$table` : " . $e->getMessage() . "\n";
    }
}

// ====================================================================
// ANALYSE 2 : Identifier les requêtes avec IN() dynamiques
// ====================================================================
echo "\nANALYSE 2 : Requêtes avec IN() dynamiques\n";

$filesWithInClause = [
    'public/historique.php' => [
        'clients WHERE id IN',
        'sav WHERE id IN',
        'livraisons WHERE id IN',
        'utilisateurs WHERE id IN'
    ]
];

foreach ($filesWithInClause as $file => $patterns) {
    $filePath = __DIR__ . '/../' . $file;
    if (!file_exists($filePath)) {
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    foreach ($patterns as $pattern) {
        if (strpos($content, $pattern) !== false) {
            // Vérifier si c'est bien sécurisé avec des placeholders
            if (strpos($content, '$placeholders') !== false || strpos($content, 'implode') !== false) {
                echo "  ✓ $file : IN() dynamique semble sécurisé (utilise placeholders)\n";
            } else {
                $issues[] = [
                    'type' => 'unsafe_in_clause',
                    'file' => $file,
                    'pattern' => $pattern,
                    'severity' => 'high'
                ];
                echo "  ⚠ $file : IN() dynamique potentiellement non sécurisé ($pattern)\n";
            }
        }
    }
}

// ====================================================================
// ANALYSE 3 : Identifier les requêtes complexes (CTE, sous-requêtes)
// ====================================================================
echo "\nANALYSE 3 : Requêtes complexes\n";

$complexQueries = [
    'public/clients.php' => [
        'description' => 'Requête avec CTE pour unifier compteur_relevee et compteur_relevee_ancien',
        'complexity' => 'high',
        'recommendation' => 'Vérifier les performances avec EXPLAIN, considérer matérialiser les vues'
    ]
];

foreach ($complexQueries as $file => $info) {
    $filePath = __DIR__ . '/../' . $file;
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (strpos($content, 'WITH ') !== false || strpos($content, 'CTE') !== false) {
            echo "  ⚠ $file : " . $info['description'] . "\n";
            echo "     Recommandation : " . $info['recommendation'] . "\n";
            
            $issues[] = [
                'type' => 'complex_query',
                'file' => $file,
                'description' => $info['description'],
                'complexity' => $info['complexity'],
                'recommendation' => $info['recommendation'],
                'severity' => 'low'
            ];
        }
    }
}

// ====================================================================
// ANALYSE 4 : Vérifier les requêtes sans LIMIT
// ====================================================================
echo "\nANALYSE 4 : Requêtes sans LIMIT\n";

$filesToCheck = [
    'public/clients.php',
    'public/sav.php',
    'public/dashboard.php'
];

foreach ($filesToCheck as $file) {
    $filePath = __DIR__ . '/../' . $file;
    if (!file_exists($filePath)) {
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    // Chercher les SELECT sans LIMIT (mais ignorer les COUNT(*))
    preg_match_all('/SELECT\s+(?!COUNT\(\*\))[^;]+FROM[^;]+(?!LIMIT)/i', $content, $matches);
    
    if (!empty($matches[0])) {
        // Filtrer les faux positifs (requêtes avec LIMIT sur plusieurs lignes)
        $hasLimit = preg_match('/LIMIT\s+\d+/i', $content);
        
        if (!$hasLimit) {
            echo "  ⚠ $file : Requêtes SELECT sans LIMIT détectées\n";
            $issues[] = [
                'type' => 'missing_limit',
                'file' => $file,
                'severity' => 'medium'
            ];
        }
    }
}

// ====================================================================
// ANALYSE 5 : Vérifier l'utilisation du cache
// ====================================================================
echo "\nANALYSE 5 : Utilisation du cache\n";

$filesWithCache = [
    'public/dashboard.php' => 'CacheHelper utilisé pour la liste des clients'
];

foreach ($filesWithCache as $file => $description) {
    $filePath = __DIR__ . '/../' . $file;
    if (file_exists($filePath)) {
        $content = file_get_contents($filePath);
        if (strpos($content, 'CacheHelper') !== false) {
            echo "  ✓ $file : $description\n";
        }
    }
}

// ====================================================================
// ANALYSE 6 : Identifier les requêtes avec ORDER BY sur colonnes non indexées
// ====================================================================
echo "\nANALYSE 6 : ORDER BY sur colonnes potentiellement non indexées\n";

$orderByPatterns = [
    'ORDER BY raison_sociale',
    'ORDER BY date_ouverture',
    'ORDER BY date_action',
    'ORDER BY Timestamp'
];

// Note: Cette analyse est basique, une analyse plus approfondie nécessiterait EXPLAIN
echo "  ℹ Pour une analyse approfondie, exécuter EXPLAIN sur les requêtes critiques\n";

// ====================================================================
// RÉSUMÉ ET RECOMMANDATIONS
// ====================================================================
echo "\n=== RÉSUMÉ ===\n";

$highSeverity = array_filter($issues, fn($i) => $i['severity'] === 'high');
$mediumSeverity = array_filter($issues, fn($i) => $i['severity'] === 'medium');
$lowSeverity = array_filter($issues, fn($i) => $i['severity'] === 'low');

echo "Problèmes identifiés :\n";
echo "  - Haute priorité : " . count($highSeverity) . "\n";
echo "  - Priorité moyenne : " . count($mediumSeverity) . "\n";
echo "  - Priorité basse : " . count($lowSeverity) . "\n\n";

if (count($highSeverity) > 0) {
    echo "⚠ PROBLÈMES HAUTE PRIORITÉ :\n";
    foreach ($highSeverity as $issue) {
        echo "  - " . $issue['type'] . " dans " . ($issue['file'] ?? $issue['table'] ?? 'N/A') . "\n";
    }
    echo "\n";
}

// Recommandations générales
echo "RECOMMANDATIONS GÉNÉRALES :\n";
echo "  1. Exécuter EXPLAIN sur les requêtes complexes pour identifier les goulots d'étranglement\n";
echo "  2. Ajouter des index sur les colonnes utilisées dans WHERE et ORDER BY\n";
echo "  3. Utiliser le cache pour les requêtes fréquentes (déjà implémenté pour dashboard)\n";
echo "  4. Monitorer les requêtes lentes avec le slow query log MySQL\n";
echo "  5. Considérer la pagination pour les grandes listes\n";

echo "\n✅ Analyse terminée\n";

