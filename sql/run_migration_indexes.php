<?php
/**
 * Migration : index de performance (jointures / dashboard)
 *
 * Usage : php sql/run_migration_indexes.php
 *
 * Exécute sql/migrations/add_performance_indexes.sql via PDO (includes/db_connection.php).
 */

require_once __DIR__ . '/../includes/db_connection.php';

try {
    $pdo = DatabaseConnection::getInstance();

    echo "Début de la migration : index de performance...\n";

    $sqlFile = __DIR__ . '/migrations/add_performance_indexes.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("Fichier SQL introuvable: {$sqlFile}");
    }

    $sql = file_get_contents($sqlFile);
    if ($sql === false) {
        throw new RuntimeException("Impossible de lire le fichier SQL: {$sqlFile}");
    }

    // Ignorer les lignes de commentaire SQL (-- ...) pour ne pas fusionner commentaire + CREATE dans un même bloc
    $lines = preg_split('/\R/', $sql);
    $clean = '';
    foreach ($lines as $line) {
        $t = trim($line);
        if ($t === '' || str_starts_with($t, '--')) {
            continue;
        }
        $clean .= $line . "\n";
    }

    $queries = array_filter(
        array_map('trim', explode(';', $clean)),
        static function ($q) {
            return $q !== '';
        }
    );

    $pdo->beginTransaction();

    try {
        foreach ($queries as $query) {
            echo 'Exécution: ' . substr($query, 0, 100) . "...\n";
            $pdo->exec($query);
        }

        $pdo->commit();
        echo "✅ Migration réussie : index créés ou déjà présents (IF NOT EXISTS).\n";
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (PDOException $e) {
    echo '❌ Erreur SQL: ' . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo '❌ Erreur: ' . $e->getMessage() . "\n";
    exit(1);
}
