<?php
/**
 * API pour récupérer les consommations d'un client pour une période donnée
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$offre = filter_input(INPUT_GET, 'offre', FILTER_VALIDATE_INT);
$dateDebut = filter_input(INPUT_GET, 'date_debut', FILTER_SANITIZE_STRING);
$dateFin = filter_input(INPUT_GET, 'date_fin', FILTER_SANITIZE_STRING);

if (!$clientId || !$offre || !$dateDebut || !$dateFin) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres manquants'], 400);
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
    
    // Récupérer les photocopieurs du client
    $stmt = $pdo->prepare("
        SELECT 
            pc.id,
            pc.SerialNumber,
            pc.MacAddress,
            pc.mac_norm,
            COALESCE(latest.Nom, CONCAT('Imprimante ', pc.id)) as nom,
            COALESCE(latest.Model, 'Inconnu') as modele
        FROM photocopieurs_clients pc
        LEFT JOIN (
            SELECT 
                mac_norm,
                Nom,
                Model,
                ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) latest ON latest.mac_norm = pc.mac_norm AND latest.rn = 1
        WHERE pc.id_client = :client_id
        ORDER BY pc.id
        LIMIT 2
    ");
    $stmt->execute([':client_id' => $clientId]);
    $photocopieurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
} catch (Exception $e) {
    error_log('Erreur factures_get_consommation: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors du calcul des consommations: ' . $e->getMessage()], 500);
}
?>
