<?php
// /public/api/get_client_photocopieur.php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

$idClient = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$idClient) {
    echo json_encode(['ok' => false, 'error' => 'id_client_invalid']);
    exit;
}

try {
    // Utiliser une requête directe au lieu de la vue pour éviter les problèmes
    // On joint photocopieurs_clients avec les derniers relevés de compteur
    $sql = "
        WITH v_compteur_last AS (
            SELECT 
                r.*,
                ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
            FROM compteur_relevee r
            WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
        ),
        v_last AS (
            SELECT * FROM v_compteur_last WHERE rn = 1
        )
        SELECT 
            pc.mac_norm,
            pc.SerialNumber,
            pc.MacAddress,
            v.Model,
            v.Nom,
            v.`Timestamp` AS last_ts
        FROM photocopieurs_clients pc
        LEFT JOIN v_last v ON v.mac_norm = pc.mac_norm
        WHERE pc.id_client = :id_client
        ORDER BY v.`Timestamp` DESC, pc.id DESC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_client' => $idClient]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Redirection vers la page de détails du photocopieur
        $redirectUrl = '/public/photocopieurs_details.php?mac=' . urlencode($row['mac_norm']);

        echo json_encode([
            'ok'       => true,
            'assigned' => true,
            'data'     => $row,
            'redirect_url' => $redirectUrl,
        ]);
    } else {
        echo json_encode([
            'ok'       => true,
            'assigned' => false,
        ]);
    }

} catch (PDOException $e) {
    error_log('Erreur SQL (get_client_photocopieur) : ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'sql_error']);
    exit;
}
