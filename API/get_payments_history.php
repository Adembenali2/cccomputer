<?php
/**
 * API pour récupérer l'historique des paiements
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('get_payments_history.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Vérifier si la table existe
$checkTable = $pdo->prepare("
    SELECT COUNT(*) as cnt 
    FROM INFORMATION_SCHEMA.TABLES 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'paiements'
");
$checkTable->execute();
$tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;

if (!$tableExists) {
    jsonResponse(['ok' => true, 'paiements' => []]);
}

// Récupérer les paiements avec les informations du client
$clientFilter = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

$sql = "
    SELECT 
        p.id,
        p.client_id,
        c.raison_sociale as client_name,
        c.numero_client,
        p.montant,
        p.type_paiement,
        p.date_paiement,
        p.reference,
        p.iban,
        p.notes,
        p.justificatif_upload,
        p.justificatif_pdf,
        p.numero_justificatif,
        p.date_creation
    FROM paiements p
    INNER JOIN clients c ON p.client_id = c.id
";

$params = [];

if ($clientFilter > 0) {
    $sql .= " WHERE p.client_id = :client_id";
    $params[':client_id'] = $clientFilter;
}

$sql .= " ORDER BY p.date_paiement DESC, p.date_creation DESC LIMIT 100";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $paiements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données
    foreach ($paiements as &$paiement) {
        $paiement['id'] = (int)$paiement['id'];
        $paiement['client_id'] = (int)$paiement['client_id'];
        $paiement['montant'] = (float)$paiement['montant'];
        
        // Convertir le type de paiement
        $typeLabels = [
            'especes' => 'Espèces',
            'cheque' => 'Chèque',
            'virement' => 'Virement'
        ];
        $paiement['type_label'] = $typeLabels[$paiement['type_paiement']] ?? ucfirst($paiement['type_paiement']);
    }
    
    jsonResponse(['ok' => true, 'paiements' => $paiements]);
    
} catch (PDOException $e) {
    error_log('get_payments_history.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
}

