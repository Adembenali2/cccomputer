<?php
// API pour récupérer les statistiques d'un client (SAV, livraisons, factures)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($clientId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID client invalide'], 400);
}

try {
    $stats = [
        'sav' => ['total' => 0, 'ouvert' => 0, 'en_cours' => 0, 'resolu' => 0, 'annule' => 0],
        'livraisons' => ['total' => 0, 'planifiee' => 0, 'en_cours' => 0, 'livree' => 0, 'annulee' => 0],
        'factures' => ['total' => 0, 'en_attente' => 0, 'envoyee' => 0, 'payee' => 0]
    ];
    
    // Statistiques SAV
    try {
        $checkTable = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'sav'
        ");
        $checkTable->execute();
        $savTableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
        
        if ($savTableExists) {
            $savSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN statut = 'ouvert' THEN 1 ELSE 0 END) as ouvert,
                    SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                    SUM(CASE WHEN statut = 'resolu' THEN 1 ELSE 0 END) as resolu,
                    SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annule
                FROM sav
                WHERE id_client = :client_id
            ";
            $savStmt = $pdo->prepare($savSql);
            $savStmt->execute([':client_id' => $clientId]);
            $savData = $savStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($savData) {
                $stats['sav'] = [
                    'total' => (int)$savData['total'],
                    'ouvert' => (int)$savData['ouvert'],
                    'en_cours' => (int)$savData['en_cours'],
                    'resolu' => (int)$savData['resolu'],
                    'annule' => (int)$savData['annule']
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('dashboard_get_client_stats.php - Erreur SAV: ' . $e->getMessage());
    }
    
    // Statistiques Livraisons
    try {
        $checkTable = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'livraisons'
        ");
        $checkTable->execute();
        $livTableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
        
        if ($livTableExists) {
            $livSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN statut = 'planifiee' THEN 1 ELSE 0 END) as planifiee,
                    SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                    SUM(CASE WHEN statut = 'livree' THEN 1 ELSE 0 END) as livree,
                    SUM(CASE WHEN statut = 'annulee' THEN 1 ELSE 0 END) as annulee
                FROM livraisons
                WHERE id_client = :client_id
            ";
            $livStmt = $pdo->prepare($livSql);
            $livStmt->execute([':client_id' => $clientId]);
            $livData = $livStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($livData) {
                $stats['livraisons'] = [
                    'total' => (int)$livData['total'],
                    'planifiee' => (int)$livData['planifiee'],
                    'en_cours' => (int)$livData['en_cours'],
                    'livree' => (int)$livData['livree'],
                    'annulee' => (int)$livData['annulee']
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('dashboard_get_client_stats.php - Erreur Livraisons: ' . $e->getMessage());
    }
    
    // Statistiques Factures
    try {
        $checkTable = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'factures'
        ");
        $checkTable->execute();
        $factTableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
        
        if ($factTableExists) {
            $factSql = "
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN statut = 'en_attente' THEN 1 ELSE 0 END) as en_attente,
                    SUM(CASE WHEN statut = 'envoyee' THEN 1 ELSE 0 END) as envoyee,
                    SUM(CASE WHEN statut = 'payee' THEN 1 ELSE 0 END) as payee
                FROM factures
                WHERE id_client = :client_id
            ";
            $factStmt = $pdo->prepare($factSql);
            $factStmt->execute([':client_id' => $clientId]);
            $factData = $factStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($factData) {
                $stats['factures'] = [
                    'total' => (int)$factData['total'],
                    'en_attente' => (int)$factData['en_attente'],
                    'envoyee' => (int)$factData['envoyee'],
                    'payee' => (int)$factData['payee']
                ];
            }
        }
    } catch (PDOException $e) {
        error_log('dashboard_get_client_stats.php - Erreur Factures: ' . $e->getMessage());
    }
    
    jsonResponse(['ok' => true, 'stats' => $stats]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_client_stats.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_client_stats.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

