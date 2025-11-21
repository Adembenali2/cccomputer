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
    // On utilise la vue v_photocopieurs_clients_last
    $sql = "SELECT 
                mac_norm,
                SerialNumber,
                MacAddress,
                Model,
                Nom,
                last_ts
            FROM v_photocopieurs_clients_last
            WHERE client_id = :id_client
            ORDER BY last_ts DESC
            LIMIT 1";

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
