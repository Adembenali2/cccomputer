<?php
// /public/api/attribuer_photocopieur.php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Méthode non autorisée";
    exit;
}

$idClient = filter_input(INPUT_POST, 'id_client', FILTER_VALIDATE_INT);
$macInput = isset($_POST['mac_address']) ? trim($_POST['mac_address']) : '';

if (!$idClient || $macInput === '') {
    echo "Paramètres manquants";
    exit;
}

// Normalisation MAC : on enlève tout sauf [0-9A-F] et on met en majuscules
$macNorm = strtoupper(preg_replace('/[^0-9A-F]/i', '', $macInput));

if (strlen($macNorm) !== 12) {
    echo "Adresse MAC invalide";
    exit;
}

try {
    $pdo->beginTransaction();

    // On essaie de récupérer les infos (SerialNumber / MacAddress) depuis v_compteur_last
    $sql = "SELECT SerialNumber, MacAddress 
            FROM v_compteur_last
            WHERE mac_norm = :mac_norm
            ORDER BY Timestamp DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':mac_norm' => $macNorm]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $serial = $row['SerialNumber'] ?? null;
    // Si on ne trouve pas, on utilise la MAC saisie comme MacAddress stockée
    $macDisplay = $row['MacAddress'] ?? $macInput;

    // Insertion dans photocopieurs_clients
    $sqlInsert = "INSERT INTO photocopieurs_clients (id_client, SerialNumber, MacAddress)
                  VALUES (:id_client, :serial, :mac_address)";
    $stmtInsert = $pdo->prepare($sqlInsert);
    $stmtInsert->execute([
        ':id_client'   => $idClient,
        ':serial'      => $serial,
        ':mac_address' => $macDisplay,
    ]);

    $pdo->commit();

    // Redirection vers la page détail
    $redirectUrl = '/dephotocopieurs_details.php?mac=' . urlencode($macNorm);
    header('Location: ' . $redirectUrl);
    exit;

} catch (PDOException $e) {
    $pdo->rollBack();
    error_log('Erreur SQL (attribuer_photocopieur) : ' . $e->getMessage());

    // Gestion du cas doublon (UNIQUE mac_norm ou Serial)
    if ((int)$e->getCode() === 23000) {
        echo "Cette photocopieuse est déjà attribuée.";
    } else {
        echo "Erreur lors de l\'attribution.";
    }
    exit;
}
