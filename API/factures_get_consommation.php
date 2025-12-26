<?php
/**
 * API pour récupérer les consommations d'un client pour une période donnée
 */

// Désactiver l'affichage des erreurs PHP (on veut du JSON propre)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// S'assurer que le header JSON est bien défini (au cas où auth.php aurait fait un output)
header('Content-Type: application/json; charset=utf-8');

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$offre = filter_input(INPUT_GET, 'offre', FILTER_VALIDATE_INT);
$dateDebut = filter_input(INPUT_GET, 'date_debut', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: filter_input(INPUT_GET, 'date_debut', FILTER_UNSAFE_RAW);
$dateFin = filter_input(INPUT_GET, 'date_fin', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: filter_input(INPUT_GET, 'date_fin', FILTER_UNSAFE_RAW);

// Nettoyer et valider les dates
$dateDebut = trim($dateDebut ?? '');
$dateFin = trim($dateFin ?? '');

if (!$clientId || !$offre || !$dateDebut || !$dateFin) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres manquants: client_id, offre, date_debut et date_fin sont requis'], 400);
}

// Valider le format des dates (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
    jsonResponse(['ok' => false, 'error' => 'Format de date invalide (attendu: YYYY-MM-DD)'], 400);
}

if (!in_array($offre, [1000, 2000], true)) {
    jsonResponse(['ok' => false, 'error' => 'Offre invalide (doit être 1000 ou 2000)'], 400);
}

try {
    $pdo = getPdo();
    
    // Vérifier le nombre de photocopieurs
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM photocopieurs_clients WHERE id_client = :client_id");
    $stmt->execute([':client_id' => $clientId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nbPhotocopieurs = (int)($result['nb'] ?? 0);
    
    if ($offre === 2000 && $nbPhotocopieurs !== 2) {
        jsonResponse(['ok' => false, 'error' => "L'offre 2000 nécessite exactement 2 photocopieurs. Ce client en a {$nbPhotocopieurs}."], 400);
    }
    
    if ($nbPhotocopieurs === 0) {
        jsonResponse(['ok' => false, 'error' => 'Aucun photocopieur trouvé pour ce client'], 400);
    }
    
    // Récupérer les photocopieurs du client avec leurs dernières infos
    $stmt = $pdo->prepare("
        SELECT 
            pc.id,
            pc.SerialNumber,
            pc.MacAddress,
            pc.mac_norm
        FROM photocopieurs_clients pc
        WHERE pc.id_client = :client_id
        ORDER BY pc.id
        LIMIT 2
    ");
    $stmt->execute([':client_id' => $clientId]);
    $photocopieursRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les infos les plus récentes pour chaque photocopieur
    $photocopieurs = [];
    foreach ($photocopieursRaw as $pc) {
        $macNorm = $pc['mac_norm'];
        
        // Récupérer les infos les plus récentes du photocopieur
        $stmtInfo = $pdo->prepare("
            SELECT Nom, Model
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtInfo->execute([':mac_norm' => $macNorm]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);
        
        $photocopieurs[] = [
            'id' => $pc['id'],
            'SerialNumber' => $pc['SerialNumber'],
            'MacAddress' => $pc['MacAddress'],
            'mac_norm' => $macNorm,
            'nom' => !empty($info['Nom']) ? $info['Nom'] : ('Imprimante ' . $pc['id']),
            'modele' => !empty($info['Model']) ? $info['Model'] : 'Inconnu'
        ];
    }
    
    $machines = [];
    
    foreach ($photocopieurs as $pc) {
        $macNorm = $pc['mac_norm'];
        if (!$macNorm) continue;
        
        // Récupérer le premier relevé avant ou au début de la période
        $stmtStart = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) <= :date_debut
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtStart->execute([
            ':mac_norm' => $macNorm,
            ':date_debut' => $dateDebut
        ]);
        $startReleve = $stmtStart->fetch(PDO::FETCH_ASSOC);
        
        // Récupérer le dernier relevé avant ou à la fin de la période
        $stmtEnd = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) <= :date_fin
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtEnd->execute([
            ':mac_norm' => $macNorm,
            ':date_fin' => $dateFin
        ]);
        $endReleve = $stmtEnd->fetch(PDO::FETCH_ASSOC);
        
        if ($startReleve && $endReleve) {
            $consoNB = max(0, (int)$endReleve['TotalBW'] - (int)$startReleve['TotalBW']);
            $consoColor = max(0, (int)$endReleve['TotalColor'] - (int)$startReleve['TotalColor']);
        } else {
            $consoNB = 0;
            $consoColor = 0;
        }
        
        $machines[] = [
            'id' => $pc['id'],
            'nom' => $pc['nom'],
            'modele' => $pc['modele'],
            'mac_norm' => $macNorm,
            'conso_nb' => $consoNB,
            'conso_couleur' => $consoColor
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'offre' => $offre,
        'nb_photocopieurs' => count($machines),
        'machines' => $machines
    ]);
    
} catch (PDOException $e) {
    error_log('Erreur PDO factures_get_consommation: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données lors du calcul des consommations'], 500);
} catch (Exception $e) {
    error_log('Erreur factures_get_consommation: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors du calcul des consommations: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('Erreur fatale factures_get_consommation: ' . $e->getMessage());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur fatale lors du calcul des consommations'], 500);
}
?>
