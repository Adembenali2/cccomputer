<?php
/**
 * API pour enregistrer un nouveau paiement
 *
 * Accepte :
 * - FormData (multipart/form-data) : csrf_token dans le body
 * - JSON (Content-Type: application/json) : csrf_token dans le body OU header X-CSRF-Token
 *
 * La session doit contenir csrf_token (via ensureCsrfToken sur la page paiements).
 * Sinon : 403 Token CSRF invalide.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';
require_once __DIR__ . '/../includes/Validator.php';
require_once __DIR__ . '/../includes/ErrorHandler.php';
ErrorHandler::register();

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Support FormData (multipart) et JSON (application/json)
// Pour JSON : csrf_token dans le body OU header X-CSRF-Token obligatoire
$inputData = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);
    $inputData = is_array($decoded) ? $decoded : [];
}

$csrfToken = (string)($inputData['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfSession = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfSession === '' || $csrfToken === '' || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

try {
    $hasFactureId = isset($inputData['facture_id']) && trim((string) ($inputData['facture_id'] ?? '')) !== '';
    $hasClientId = isset($inputData['id_client']) && trim((string) ($inputData['id_client'] ?? '')) !== '';
    if (!$hasFactureId && !$hasClientId) {
        apiFail('Champ requis manquant : facture_id ou id_client', 400);
    }
    if ($hasFactureId) {
        $factureId = Validator::int($inputData['facture_id'], 1);
    } else {
        Validator::int($inputData['id_client'], 1);
        apiFail('Facture non sélectionnée', 400);
    }

    $montant = Validator::float($inputData['montant'] ?? 0, 0.0);
    if ($montant <= 0) {
        throw new InvalidArgumentException('Le montant doit être supérieur à 0');
    }

    // [Fonctionnalité A] Mode normalisé
    $rawMode = trim((string) ($inputData['mode_paiement'] ?? ''));
    if ($rawMode === 'cb') {
        $rawMode = 'carte';
    } elseif ($rawMode === 'autre') {
        $rawMode = 'prelevement';
    }
    $modePaiement = Validator::enum($rawMode, ['virement', 'cheque', 'especes', 'prelevement', 'carte']);

    $datePaiement = trim((string) ($inputData['date_paiement'] ?? date('Y-m-d')));
    $datePaiementDt = DateTime::createFromFormat('Y-m-d', $datePaiement);
    if (!$datePaiementDt || $datePaiementDt->format('Y-m-d') !== $datePaiement) {
        throw new InvalidArgumentException('Date de paiement invalide');
    }

    $pdo = getPdo();
    $userId = currentUserId();

    $reference = !empty($inputData['reference']) ? trim((string) $inputData['reference']) : null;
    $commentaire = !empty($inputData['commentaire']) ? trim((string) $inputData['commentaire']) : null;

    // Générer automatiquement la référence si elle n'est pas fournie
    if (empty($reference)) {
        // Extraire année, mois, jour de la date de paiement
        $dateParts = explode('-', $datePaiement);
        $annee = $dateParts[0];
        $mois = $dateParts[1];
        $jour = $dateParts[2];
        
        // Trouver le dernier numéro pour cette date
        $stmt = $pdo->prepare("
            SELECT reference 
            FROM paiements 
            WHERE reference LIKE :pattern 
            ORDER BY reference DESC 
            LIMIT 1
        ");
        $pattern = 'P' . $annee . $mois . $jour . '%';
        $stmt->execute([':pattern' => $pattern]);
        $dernierRef = $stmt->fetchColumn();
        
        // Déterminer le prochain numéro
        $numero = 1;
        if ($dernierRef) {
            // Extraire le numéro de la dernière référence (les 3 derniers chiffres)
            $dernierNumero = (int)substr($dernierRef, -3);
            $numero = $dernierNumero + 1;
        }
        
        // Formater le numéro sur 3 chiffres
        $numeroFormate = str_pad($numero, 3, '0', STR_PAD_LEFT);
        
        // Générer la référence : P + année + mois + jour + numéro
        $reference = 'P' . $annee . $mois . $jour . $numeroFormate;
        
        // Vérifier l'unicité (sécurité supplémentaire)
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM paiements WHERE reference = :reference");
        $stmt->execute([':reference' => $reference]);
        $exists = $stmt->fetchColumn() > 0;
        
        if ($exists) {
            // Si la référence existe déjà (cas très rare), incrémenter
            $numero++;
            $numeroFormate = str_pad($numero, 3, '0', STR_PAD_LEFT);
            $reference = 'P' . $annee . $mois . $jour . $numeroFormate;
        }
    }
    
    // Vérifier que la facture existe et récupérer les infos
    $stmt = $pdo->prepare("
        SELECT f.id, f.id_client, f.numero, f.montant_ttc, f.statut, COALESCE(f.montant_paye, 0) AS montant_paye
        FROM factures f
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    $clientId = (int)$facture['id_client'];
    
    // Gérer l'upload du justificatif
    $justificatifPath = null;
    if (!empty($_FILES['justificatif']) && $_FILES['justificatif']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['justificatif'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        $allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        
        // Vérifier la taille
        if ($file['size'] > $maxSize) {
            jsonResponse(['ok' => false, 'error' => 'Le fichier est trop volumineux (max 5MB)'], 400);
        }
        
        // Vérifier le type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            jsonResponse(['ok' => false, 'error' => 'Type de fichier non autorisé'], 400);
        }
        
        // Créer le dossier de stockage si nécessaire
        $uploadDir = __DIR__ . '/../uploads/paiements/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Générer un nom de fichier unique
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = 'paiement_' . $factureId . '_' . time() . '_' . uniqid() . '.' . $extension;
        $filePath = $uploadDir . $fileName;
        
        // Déplacer le fichier
        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'upload du fichier'], 500);
        }
        
        $justificatifPath = '/uploads/paiements/' . $fileName;
    }
    
    // [Fonctionnalité A] Validation solde restant dû
    $factureTtc = (float)($facture['montant_ttc'] ?? 0);
    $hasFactureIdCol = columnExists($pdo, 'paiements', 'facture_id');
    $hasIdFactureCol = columnExists($pdo, 'paiements', 'id_facture');
    $whereFacture = [];
    if ($hasIdFactureCol) { $whereFacture[] = "id_facture = :id"; }
    if ($hasFactureIdCol) { $whereFacture[] = "facture_id = :id"; }
    $whereFactureSql = !empty($whereFacture) ? ('(' . implode(' OR ', $whereFacture) . ')') : '1=0';
    $stmtPaid = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE {$whereFactureSql}");
    $stmtPaid->execute([':id' => $factureId]);
    $dejaPaye = (float)$stmtPaid->fetchColumn();
    $restant = max(0.0, $factureTtc - $dejaPaye);
    if ($montant > $restant + 0.0001) {
        jsonResponse(['ok' => false, 'error' => 'Le montant ne peut pas dépasser le solde restant dû (' . number_format($restant, 2, ',', ' ') . ' €)'], 400);
    }

    // Tous les modes de paiement : en attente de validation
    $statutPaiement = 'en_cours';
    
    // Démarrer la transaction
    $pdo->beginTransaction();
    
    try {
        // [Fonctionnalité A] INSERT compatible anciens/nouveaux schémas paiements
        $hasFactureId = $hasFactureIdCol;
        $hasIdFacture = $hasIdFactureCol;
        $hasIdClient = columnExists($pdo, 'paiements', 'id_client');
        $hasCommentaire = columnExists($pdo, 'paiements', 'commentaire');
        $hasStatut = columnExists($pdo, 'paiements', 'statut');

        $cols = [];
        $vals = [];
        $paramsInsert = [
            ':montant' => $montant,
            ':date_paiement' => $datePaiement,
            ':mode_paiement' => $modePaiement,
            ':reference' => $reference,
            ':created_by' => $userId,
        ];

        if ($hasFactureId) { $cols[] = 'facture_id'; $vals[] = ':facture_id'; $paramsInsert[':facture_id'] = $factureId; }
        if ($hasIdFacture) { $cols[] = 'id_facture'; $vals[] = ':id_facture'; $paramsInsert[':id_facture'] = $factureId; }
        if ($hasIdClient) { $cols[] = 'id_client'; $vals[] = ':id_client'; $paramsInsert[':id_client'] = $clientId; }
        $cols[] = 'montant'; $vals[] = ':montant';
        $cols[] = 'date_paiement'; $vals[] = ':date_paiement';
        $cols[] = 'mode_paiement'; $vals[] = ':mode_paiement';
        $cols[] = 'reference'; $vals[] = ':reference';
        if ($hasCommentaire) { $cols[] = 'commentaire'; $vals[] = ':commentaire'; $paramsInsert[':commentaire'] = $commentaire; }
        if ($hasStatut) { $cols[] = 'statut'; $vals[] = ':statut'; $paramsInsert[':statut'] = $statutPaiement; }
        $cols[] = 'created_by'; $vals[] = ':created_by';

        $sqlInsert = "INSERT INTO paiements (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
        $stmt = $pdo->prepare($sqlInsert);
        $stmt->execute($paramsInsert);
        
        $paiementId = $pdo->lastInsertId();
        
        // Générer le reçu de paiement en PDF
        $recuPath = null;
        try {
            require_once __DIR__ . '/paiements_generer_recu.php';
            $recuPath = generateRecuPDF($pdo, $paiementId);
        } catch (Throwable $e) {
            error_log('paiements_enregistrer.php - Erreur génération reçu: ' . $e->getMessage());
            // On continue même si la génération du reçu échoue
        }
        
        // Mettre à jour le reçu généré et le justificatif si présent
        // Priorité : justificatif uploadé > reçu généré
        $finalRecuPath = $justificatifPath ?: $recuPath;
        
        if ($finalRecuPath) {
            $stmt = $pdo->prepare("
                UPDATE paiements 
                SET recu_path = :recu_path,
                    recu_genere = :recu_genere
                WHERE id = :id
            ");
            $stmt->execute([
                ':recu_path' => $finalRecuPath,
                ':recu_genere' => ($recuPath && !$justificatifPath) ? 1 : 0,
                ':id' => $paiementId
            ]);
        }
        
        // [Fonctionnalité A] Recalcul montant_paye + statut facture auto
        $stmtTotal = $pdo->prepare("SELECT COALESCE(SUM(montant), 0) FROM paiements WHERE {$whereFactureSql}");
        $stmtTotal->execute([':id' => $factureId]);
        $totalPaye = (float)$stmtTotal->fetchColumn();

        if (columnExists($pdo, 'factures', 'montant_paye')) {
            $pdo->prepare("UPDATE factures SET montant_paye = :mp, updated_at = NOW() WHERE id = :id")
                ->execute([':mp' => $totalPaye, ':id' => $factureId]);
        }

        $nouveauStatut = (string)($facture['statut'] ?? 'en_attente');
        if ($totalPaye >= $factureTtc - 0.0001) {
            $nouveauStatut = 'payee';
        } elseif ($totalPaye > 0.0001 && $totalPaye < $factureTtc - 0.0001) {
            $nouveauStatut = 'partielle';
        }
        if ($nouveauStatut !== (string)$facture['statut']) {
            try {
                $pdo->prepare("UPDATE factures SET statut = :s, updated_at = NOW() WHERE id = :id")
                    ->execute([':s' => $nouveauStatut, ':id' => $factureId]);
            } catch (Throwable $e) {
                // Fallback si ENUM ne contient pas 'partielle'
                if ($nouveauStatut === 'partielle') {
                    $pdo->prepare("UPDATE factures SET statut = 'en_cours', updated_at = NOW() WHERE id = :id")
                        ->execute([':id' => $factureId]);
                    $nouveauStatut = 'en_cours';
                } else {
                    throw $e;
                }
            }
        }
        
        $pdo->commit();

        $numeroFacture = (string)($facture['numero'] ?? $factureId);
        $clientNom = 'Client #' . $clientId;
        try {
            $clientStmt = $pdo->prepare("SELECT raison_sociale FROM clients WHERE id = ? LIMIT 1");
            $clientStmt->execute([$clientId]);
            $clientNom = (string)($clientStmt->fetchColumn() ?: $clientNom);
        } catch (Throwable $e) {
            // ignore
        }
        logAction(
            $pdo,
            'paiement',
            'paiement_recu',
            'Paiement recu — ' . $clientNom,
            'Montant: ' . number_format($montant, 2, '.', '') . ' EUR | Mode: ' . $modePaiement . ' | Facture: ' . $numeroFacture,
            (int)$paiementId,
            'paiements.php'
        );

        // Envoi email "reçu, en attente de validation" pour tous les modes de paiement
        require_once __DIR__ . '/../includes/parametres.php';
        $autoSend = getAutoSendEmailsEnabled($pdo);
        if ($autoSend && !$justificatifPath) {
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                $appConfig = require __DIR__ . '/../config/app.php';
                $receiptService = new \App\Services\PaymentReceiptEmailService($pdo, $appConfig);
                $result = $receiptService->sendPendingValidationEmail((int)$paiementId);
                if (isset($result['success']) && !$result['success']) {
                    error_log('[paiements_enregistrer] Envoi email échoué: ' . ($result['message'] ?? ''));
                }
            } catch (Throwable $e) {
                error_log('[paiements_enregistrer] Erreur envoi email: ' . $e->getMessage());
            }
        }
        
        jsonResponse([
            'ok' => true,
            'message' => 'Paiement enregistré avec succès',
            'paiement_id' => $paiementId,
            'facture_id' => $factureId,
            'nouveau_statut' => $nouveauStatut,
            'reference' => $reference,
            'recu_path' => $finalRecuPath ?? null,
            'recu_genere' => $recuPath ? true : false,
            'montant_paye' => $totalPaye
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        
        // Supprimer le fichier uploadé en cas d'erreur
        if ($justificatifPath && file_exists(__DIR__ . '/..' . $justificatifPath)) {
            @unlink(__DIR__ . '/..' . $justificatifPath);
        }
        
        throw $e;
    }
    
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    ErrorHandler::apiError($e);
}

