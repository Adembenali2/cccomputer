<?php
// /api/stock_add.php

// Désactiver l'affichage des erreurs HTML pour retourner uniquement du JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Fonction pour envoyer une réponse JSON propre
function jsonResponse(array $data, int $statusCode = 200) {
    // Nettoyer tout buffer de sortie avant d'envoyer le JSON
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK);
    exit;
}

// Démarrer le buffer de sortie
ob_start();

// Gestion de la session pour les API (sans redirection HTML)
try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('stock_add.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier l'authentification sans redirection HTML
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Générer un token CSRF si manquant
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Vérifier que la connexion existe
if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Connexion base de données manquante'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Lire et décoder le JSON une seule fois
$raw = file_get_contents('php://input');
$jsonData = json_decode($raw, true);

// Vérification CSRF
$csrfToken = $jsonData['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

// Utiliser les données décodées
$data = $jsonData ?? [];
if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

$type    = $data['type'] ?? '';
$payload = $data['data'] ?? [];

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    switch ($type) {

        /* ===================== PAPIER ===================== */
        case 'papier':
            $marque = trim($payload['marque'] ?? '');
            $modele = trim($payload['modele'] ?? '');
            $poids  = trim($payload['poids']  ?? '');
            $qty    = (int)($payload['qty_delta'] ?? 0);
            $ref    = trim($payload['reference'] ?? '');

            if ($marque === '' || $modele === '' || $poids === '' || $qty === 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, modèle, poids, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer le papier dans paper_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM paper_catalog
                WHERE marque = :marque AND modele = :modele AND poids = :poids
                LIMIT 1
            ");
            $stmt->execute([
                ':marque' => $marque,
                ':modele' => $modele,
                ':poids'  => $poids,
            ]);
            $paperId = $stmt->fetchColumn();

            if (!$paperId) {
                $stmt = $pdo->prepare("
                    INSERT INTO paper_catalog (marque, modele, poids)
                    VALUES (:marque, :modele, :poids)
                ");
                $stmt->execute([
                    ':marque' => $marque,
                    ':modele' => $modele,
                    ':poids'  => $poids,
                ]);
                $paperId = $pdo->lastInsertId();
            }

            // 2) insérer le mouvement
            $stmt = $pdo->prepare("
                INSERT INTO paper_moves (paper_id, qty_delta, reason, reference, user_id)
                VALUES (:paper_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':paper_id'  => $paperId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            break;

        /* ===================== TONER ===================== */
        case 'toner':
            $marque  = trim($payload['marque']  ?? '');
            $modele  = trim($payload['modele']  ?? '');
            $couleur = trim($payload['couleur'] ?? '');
            $qty     = (int)($payload['qty_delta'] ?? 0);
            $ref     = trim($payload['reference'] ?? '');

            if ($marque === '' || $modele === '' || $couleur === '' || $qty === 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, modèle, couleur, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans toner_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM toner_catalog
                WHERE marque = :marque AND modele = :modele AND couleur = :couleur
                LIMIT 1
            ");
            $stmt->execute([
                ':marque'  => $marque,
                ':modele'  => $modele,
                ':couleur' => $couleur,
            ]);
            $tonerId = $stmt->fetchColumn();

            if (!$tonerId) {
                $stmt = $pdo->prepare("
                    INSERT INTO toner_catalog (marque, modele, couleur)
                    VALUES (:marque, :modele, :couleur)
                ");
                $stmt->execute([
                    ':marque'  => $marque,
                    ':modele'  => $modele,
                    ':couleur' => $couleur,
                ]);
                $tonerId = $pdo->lastInsertId();
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO toner_moves (toner_id, qty_delta, reason, reference, user_id)
                VALUES (:toner_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':toner_id'  => $tonerId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            break;

        /* ===================== LCD ===================== */
        case 'lcd':
            $marque     = trim($payload['marque']     ?? '');
            $reference  = trim($payload['reference']  ?? '');
            $etat       = strtoupper(trim($payload['etat'] ?? 'A'));
            $modele     = trim($payload['modele']     ?? '');
            $taille     = (int)($payload['taille']    ?? 0);
            $resolution = trim($payload['resolution'] ?? '');
            $connectique= trim($payload['connectique']?? '');
            $prix       = $payload['prix'] === '' ? null : (float)$payload['prix'];
            $qty        = (int)($payload['qty_delta'] ?? 0);
            $refMove    = trim($payload['reference_move'] ?? '');
            $ref        = $refMove !== '' ? $refMove : $reference;

            if ($marque === '' || $reference === '' || $modele === '' || !$taille || $resolution === '' || $connectique === '' || $qty === 0) {
                throw new RuntimeException('Champs obligatoires manquants (marque, réf., modèle, taille, résolution, connectique, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans lcd_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM lcd_catalog
                WHERE marque = :marque AND reference = :reference
                LIMIT 1
            ");
            $stmt->execute([
                ':marque'    => $marque,
                ':reference' => $reference,
            ]);
            $lcdId = $stmt->fetchColumn();

            if ($lcdId) {
                // mise à jour des métadonnées
                $stmt = $pdo->prepare("
                    UPDATE lcd_catalog
                    SET etat = :etat,
                        modele = :modele,
                        taille = :taille,
                        resolution = :resolution,
                        connectique = :connectique,
                        prix = :prix
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':etat'       => $etat,
                    ':modele'     => $modele,
                    ':taille'     => $taille,
                    ':resolution' => $resolution,
                    ':connectique'=> $connectique,
                    ':prix'       => $prix,
                    ':id'         => $lcdId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO lcd_catalog (marque, reference, etat, modele, taille, resolution, connectique, prix)
                    VALUES (:marque, :reference, :etat, :modele, :taille, :resolution, :connectique, :prix)
                ");
                $stmt->execute([
                    ':marque'     => $marque,
                    ':reference'  => $reference,
                    ':etat'       => $etat,
                    ':modele'     => $modele,
                    ':taille'     => $taille,
                    ':resolution' => $resolution,
                    ':connectique'=> $connectique,
                    ':prix'       => $prix,
                ]);
                $lcdId = $pdo->lastInsertId();
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO lcd_moves (lcd_id, qty_delta, reason, reference, user_id)
                VALUES (:lcd_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':lcd_id'    => $lcdId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            break;

        /* ===================== PC ===================== */
        case 'pc':
            $etat     = strtoupper(trim($payload['etat'] ?? 'A'));
            $reference= trim($payload['reference'] ?? '');
            $marque   = trim($payload['marque']    ?? '');
            $modele   = trim($payload['modele']    ?? '');
            $cpu      = trim($payload['cpu']       ?? '');
            $ram      = trim($payload['ram']       ?? '');
            $stockage = trim($payload['stockage']  ?? '');
            $os       = trim($payload['os']        ?? '');
            $gpu      = trim($payload['gpu']       ?? '');
            $reseau   = trim($payload['reseau']    ?? '');
            $ports    = trim($payload['ports']     ?? '');
            $prix     = $payload['prix'] === '' ? null : (float)$payload['prix'];
            $qty      = (int)($payload['qty_delta'] ?? 0);
            $refMove  = trim($payload['reference_move'] ?? '');
            $ref      = $refMove !== '' ? $refMove : $reference;

            if ($reference === '' || $marque === '' || $modele === '' || $cpu === '' || $ram === '' || $stockage === '' || $os === '' || $qty === 0) {
                throw new RuntimeException('Champs obligatoires manquants (réf., marque, modèle, CPU, RAM, stockage, OS, quantité).');
            }

            $reason = 'achat';
            $userId = $_SESSION['user_id'] ?? null;

            $pdo->beginTransaction();

            // 1) trouver ou créer dans pc_catalog
            $stmt = $pdo->prepare("
                SELECT id FROM pc_catalog
                WHERE reference = :reference
                LIMIT 1
            ");
            $stmt->execute([':reference' => $reference]);
            $pcId = $stmt->fetchColumn();

            if ($pcId) {
                $stmt = $pdo->prepare("
                    UPDATE pc_catalog
                    SET etat = :etat,
                        marque = :marque,
                        modele = :modele,
                        cpu = :cpu,
                        ram = :ram,
                        stockage = :stockage,
                        os = :os,
                        gpu = :gpu,
                        reseau = :reseau,
                        ports = :ports,
                        prix = :prix
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':etat'      => $etat,
                    ':marque'    => $marque,
                    ':modele'    => $modele,
                    ':cpu'       => $cpu,
                    ':ram'       => $ram,
                    ':stockage'  => $stockage,
                    ':os'        => $os,
                    ':gpu'       => $gpu ?: null,
                    ':reseau'    => $reseau ?: null,
                    ':ports'     => $ports ?: null,
                    ':prix'      => $prix,
                    ':id'        => $pcId,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pc_catalog (etat, reference, marque, modele, cpu, ram, stockage, os, gpu, reseau, ports, prix)
                    VALUES (:etat, :reference, :marque, :modele, :cpu, :ram, :stockage, :os, :gpu, :reseau, :ports, :prix)
                ");
                $stmt->execute([
                    ':etat'      => $etat,
                    ':reference' => $reference,
                    ':marque'    => $marque,
                    ':modele'    => $modele,
                    ':cpu'       => $cpu,
                    ':ram'       => $ram,
                    ':stockage'  => $stockage,
                    ':os'        => $os,
                    ':gpu'       => $gpu ?: null,
                    ':reseau'    => $reseau ?: null,
                    ':ports'     => $ports ?: null,
                    ':prix'      => $prix,
                ]);
                $pcId = $pdo->lastInsertId();
            }

            // 2) mouvement
            $stmt = $pdo->prepare("
                INSERT INTO pc_moves (pc_id, qty_delta, reason, reference, user_id)
                VALUES (:pc_id, :qty_delta, :reason, :reference, :user_id)
            ");
            $stmt->execute([
                ':pc_id'     => $pcId,
                ':qty_delta' => $qty,
                ':reason'    => $reason,
                ':reference' => $ref !== '' ? $ref : null,
                ':user_id'   => $userId,
            ]);

            $pdo->commit();
            break;

        default:
            throw new RuntimeException('Type inconnu.');
    }

    jsonResponse(['ok' => true], 200);
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stock_add.php PDO error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stock_add.php error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
}
