<?php
/**
 * API pour vérifier le nombre de photocopieurs d'un client
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);

if (!$clientId) {
    jsonResponse(['ok' => false, 'error' => 'ID client manquant ou invalide'], 400);
}

try {
    $pdo = getPdoOrFail();
    
    // Compter les photocopieurs du client
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM photocopieurs_clients WHERE id_client = :client_id");
    $stmt->execute([':client_id' => $clientId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $nbPhotocopieurs = (int)($result['nb'] ?? 0);
    
    // Récupérer aussi les détails des photocopieurs
    $stmt = $pdo->prepare("
        SELECT 
            pc.id,
            pc.SerialNumber,
            pc.MacAddress,
            pc.mac_norm
        FROM photocopieurs_clients pc
        WHERE pc.id_client = :client_id
        ORDER BY pc.id
    ");
    $stmt->execute([':client_id' => $clientId]);
    $photocopieursRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les infos les plus récentes pour chaque photocopieur
    $photocopieurs = [];
    $dernierReleveDate = null;
    $dernierReleveJours = null;
    
    foreach ($photocopieursRaw as $pc) {
        $macNorm = $pc['mac_norm'];
        
        // Récupérer les infos les plus récentes du photocopieur (recherche dans les deux tables)
        $stmtInfo = $pdo->prepare("
            SELECT Nom, Model, Timestamp,
                   TIMESTAMPDIFF(DAY, Timestamp, NOW()) AS jours_ecoules
            FROM (
                SELECT Nom, Model, Timestamp
                FROM compteur_relevee
                WHERE mac_norm = :mac_norm
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
                UNION ALL
                SELECT Nom, Model, Timestamp
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac_norm
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
            ) AS combined
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtInfo->execute([':mac_norm' => $macNorm]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
        
        $timestamp = $info['Timestamp'] ?? null;
        $joursEcoules = null;
        
        if ($timestamp) {
            // Calculer le nombre de jours depuis le dernier relevé
            $stmtJours = $pdo->prepare("
                SELECT TIMESTAMPDIFF(DAY, :timestamp, NOW()) AS jours
            ");
            $stmtJours->execute([':timestamp' => $timestamp]);
            $resultJours = $stmtJours->fetch(PDO::FETCH_ASSOC);
            $joursEcoules = (int)($resultJours['jours'] ?? 0);
            
            // Garder la date la plus récente parmi tous les photocopieurs
            if ($dernierReleveDate === null || strtotime($timestamp) > strtotime($dernierReleveDate)) {
                $dernierReleveDate = $timestamp;
                $dernierReleveJours = $joursEcoules;
            }
        }
        
        $photocopieurs[] = [
            'id' => $pc['id'],
            'SerialNumber' => $pc['SerialNumber'],
            'MacAddress' => $pc['MacAddress'],
            'mac_norm' => $macNorm,
            'nom' => !empty($info['Nom']) ? $info['Nom'] : 'Inconnu',
            'modele' => !empty($info['Model']) ? $info['Model'] : 'Inconnu',
            'dernier_releve' => $timestamp,
            'jours_ecoules' => $joursEcoules
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'nb_photocopieurs' => $nbPhotocopieurs,
        'photocopieurs' => $photocopieurs,
        'dernier_releve_date' => $dernierReleveDate,
        'dernier_releve_jours' => $dernierReleveJours
    ]);
    
} catch (PDOException $e) {
    error_log('Erreur PDO factures_check_photocopieurs: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données lors de la vérification'], 500);
} catch (Exception $e) {
    error_log('Erreur factures_check_photocopieurs: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors de la vérification des photocopieurs'], 500);
} catch (Throwable $e) {
    error_log('Erreur fatale factures_check_photocopieurs: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur fatale lors de la vérification'], 500);
}
?>
