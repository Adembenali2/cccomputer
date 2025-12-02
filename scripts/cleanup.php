<?php
declare(strict_types=1);

/**
 * Script de nettoyage automatique
 * À exécuter via cron quotidiennement
 * 
 * Usage: php scripts/cleanup.php
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

echo "=== Début du nettoyage automatique ===\n";

try {
    $pdo = requirePdoConnection();
    
    // 1. Nettoyer les sessions expirées (plus de 30 jours)
    echo "Nettoyage des sessions expirées...\n";
    $stmt = $pdo->prepare("
        DELETE FROM sessions 
        WHERE last_activity < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $sessionsDeleted = $stmt->rowCount();
    echo "  → {$sessionsDeleted} sessions supprimées\n";
    
    // 2. Nettoyer l'historique ancien (plus de 1 an)
    echo "Nettoyage de l'historique ancien...\n";
    $stmt = $pdo->prepare("
        DELETE FROM historique 
        WHERE date_action < DATE_SUB(NOW(), INTERVAL 1 YEAR)
    ");
    $stmt->execute();
    $historyDeleted = $stmt->rowCount();
    echo "  → {$historyDeleted} entrées d'historique supprimées\n";
    
    // 3. Nettoyer les fichiers temporaires (plus de 1 heure)
    echo "Nettoyage des fichiers temporaires...\n";
    $tempDirs = [
        __DIR__ . '/../cache',
        __DIR__ . '/../tmp',
    ];
    
    $filesDeleted = 0;
    foreach ($tempDirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        
        $files = glob($dir . '/*');
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < (time() - 3600)) {
                @unlink($file);
                $filesDeleted++;
            }
        }
    }
    echo "  → {$filesDeleted} fichiers temporaires supprimés\n";
    
    // 4. Nettoyer les logs de rate limiting anciens (plus de 24h)
    $rateLimitDir = __DIR__ . '/../cache/ratelimit';
    if (is_dir($rateLimitDir)) {
        $files = glob($rateLimitDir . '/*.json');
        $rateLimitFilesDeleted = 0;
        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < (time() - 86400)) {
                @unlink($file);
                $rateLimitFilesDeleted++;
            }
        }
        echo "  → {$rateLimitFilesDeleted} fichiers de rate limiting supprimés\n";
    }
    
    echo "\n=== Nettoyage terminé avec succès ===\n";
    
} catch (Throwable $e) {
    echo "ERREUR lors du nettoyage: " . $e->getMessage() . "\n";
    error_log('cleanup.php error: ' . $e->getMessage());
    exit(1);
}

