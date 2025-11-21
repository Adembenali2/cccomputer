<?php
// API pour créer un SAV (pour dashboard)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200) {
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
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
} catch (Throwable $e) {
    error_log('dashboard_create_sav.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Lire les données JSON ou POST
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Si pas de JSON, utiliser POST
if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'Données invalides'], 400);
}

// Vérification CSRF
$csrfToken = $data['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

// Validation des données
$idClient = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$reference = trim($data['reference'] ?? '');
$description = trim($data['description'] ?? '');
$idTechnicien = isset($data['id_technicien']) ? (int)$data['id_technicien'] : 0;
$dateOuverture = trim($data['date_ouverture'] ?? '');
$priorite = trim($data['priorite'] ?? 'normale');
$typePanne = trim($data['type_panne'] ?? '');
$commentaire = trim($data['commentaire'] ?? '');

$errors = [];
if ($idClient <= 0) $errors[] = "ID client invalide";
if (empty($reference)) $errors[] = "Référence obligatoire";
if (empty($description)) $errors[] = "Description obligatoire";
if (empty($dateOuverture)) $errors[] = "Date d'ouverture obligatoire";

$allowedPriorites = ['basse', 'normale', 'haute', 'urgente'];
if (!in_array($priorite, $allowedPriorites, true)) {
    $priorite = 'normale';
}

$allowedTypePanne = ['logiciel', 'materiel', 'piece_rechangeable'];
if (!empty($typePanne) && !in_array($typePanne, $allowedTypePanne, true)) {
    $typePanne = null; // Si invalide, on laisse NULL
}

if (!empty($errors)) {
    jsonResponse(['ok' => false, 'error' => implode(', ', $errors)], 400);
}

// Valider la date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOuverture)) {
    jsonResponse(['ok' => false, 'error' => 'Format de date invalide'], 400);
}

try {
    $pdo->beginTransaction();
    
    // Vérifier que la référence n'existe pas déjà
    $checkRef = $pdo->prepare("SELECT id FROM sav WHERE reference = :ref LIMIT 1");
    $checkRef->execute([':ref' => $reference]);
    if ($checkRef->fetch()) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Cette référence SAV existe déjà'], 400);
    }
    
    // Vérifier que le client existe
    $checkClient = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id = :id LIMIT 1");
    $checkClient->execute([':id' => $idClient]);
    $client = $checkClient->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 400);
    }
    
    // Si aucun id_technicien envoyé, on peut laisser NULL (SAV non assigné)
    $technicien = null;
    if ($idTechnicien > 0) {
        $checkTechnicien = $pdo->prepare("SELECT id, nom, prenom, Emploi, statut FROM utilisateurs WHERE id = :id AND Emploi = 'Technicien' AND statut = 'actif' LIMIT 1");
        $checkTechnicien->execute([':id' => $idTechnicien]);
        $technicien = $checkTechnicien->fetch(PDO::FETCH_ASSOC);
        if (!$technicien) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Technicien introuvable ou inactif'], 400);
        }
    }
    
    // Insérer le SAV
    $sql = "
        INSERT INTO sav (
            id_client, id_technicien, reference, description, 
            date_ouverture, statut, priorite, type_panne, commentaire
        ) VALUES (
            :id_client, :id_technicien, :reference, :description,
            :date_ouverture, 'ouvert', :priorite, :type_panne, :commentaire
        )
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_client' => $idClient,
        ':id_technicien' => $idTechnicien > 0 ? $idTechnicien : null,
        ':reference' => $reference,
        ':description' => $description,
        ':date_ouverture' => $dateOuverture,
        ':priorite' => $priorite,
        ':type_panne' => !empty($typePanne) ? $typePanne : null,
        ':commentaire' => empty($commentaire) ? null : $commentaire
    ]);
    
    $savId = (int)$pdo->lastInsertId();
    
    $pdo->commit();
    
    // Enregistrer dans l'historique
    try {
        $technicienNom = $technicien ? ($technicien['prenom'] . ' ' . $technicien['nom']) : 'Non assigné';
        $typePanneLabel = $typePanne ? ('type: ' . $typePanne) : '';
        $details = sprintf(
            'SAV créé: %s pour client %s (ID %d), technicien %s, date ouverture: %s, priorité: %s%s', 
            $reference,
            $client['raison_sociale'],
            $idClient, 
            $technicienNom,
            $dateOuverture,
            $priorite,
            $typePanneLabel ? (', ' . $typePanneLabel) : ''
        );
        enregistrerAction($pdo, $_SESSION['user_id'], 'sav_cree', $details);
    } catch (Throwable $e) {
        error_log('dashboard_create_sav.php log error: ' . $e->getMessage());
    }
    
    jsonResponse([
        'ok' => true, 
        'sav_id' => $savId,
        'message' => 'SAV créé avec succès'
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('dashboard_create_sav.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('dashboard_create_sav.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

