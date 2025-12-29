<?php
/**
 * API pour enregistrer un nouveau paiement
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    $userId = currentUserId();
    
    // Récupération des données
    $factureId = !empty($_POST['facture_id']) ? (int)$_POST['facture_id'] : null;
    $montant = !empty($_POST['montant']) ? (float)$_POST['montant'] : 0;
    $datePaiement = !empty($_POST['date_paiement']) ? trim($_POST['date_paiement']) : date('Y-m-d');
    $modePaiement = !empty($_POST['mode_paiement']) ? trim($_POST['mode_paiement']) : '';
    $reference = !empty($_POST['reference']) ? trim($_POST['reference']) : null;
    $commentaire = !empty($_POST['commentaire']) ? trim($_POST['commentaire']) : null;
    
    // Validation
    if (!$factureId) {
        jsonResponse(['ok' => false, 'error' => 'Facture non sélectionnée'], 400);
    }
    
    if ($montant <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Le montant doit être supérieur à 0'], 400);
    }
    
    $modesValides = ['virement', 'cb', 'cheque', 'especes', 'autre'];
    if (!in_array($modePaiement, $modesValides, true)) {
        jsonResponse(['ok' => false, 'error' => 'Mode de paiement invalide'], 400);
    }
    
    // Validation de la date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePaiement)) {
        jsonResponse(['ok' => false, 'error' => 'Date de paiement invalide'], 400);
    }
    
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
        SELECT f.id, f.id_client, f.numero, f.montant_ttc, f.statut
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
    
    // Déterminer le nouveau statut de la facture selon le mode de paiement
    // Espèce ou CB → "payee", autres → "brouillon" (en cours)
    $nouveauStatutFacture = null;
    if (in_array($modePaiement, ['especes', 'cb'], true)) {
        $nouveauStatutFacture = 'payee';
    } else {
        // Virement, chèque, autre → en cours
        $nouveauStatutFacture = 'brouillon';
    }
    
    // Démarrer la transaction
    $pdo->beginTransaction();
    
    try {
        // Insérer le paiement
        $stmt = $pdo->prepare("
            INSERT INTO paiements (
                id_facture, id_client, montant, date_paiement, 
                mode_paiement, reference, commentaire, statut, created_by
            ) VALUES (
                :id_facture, :id_client, :montant, :date_paiement,
                :mode_paiement, :reference, :commentaire, 'recu', :created_by
            )
        ");
        
        $stmt->execute([
            ':id_facture' => $factureId,
            ':id_client' => $clientId,
            ':montant' => $montant,
            ':date_paiement' => $datePaiement,
            ':mode_paiement' => $modePaiement,
            ':reference' => $reference,
            ':commentaire' => $commentaire,
            ':created_by' => $userId
        ]);
        
        $paiementId = $pdo->lastInsertId();
        
        // Mettre à jour le justificatif si présent
        if ($justificatifPath) {
            $stmt = $pdo->prepare("
                UPDATE paiements 
                SET recu_path = :recu_path 
                WHERE id = :id
            ");
            $stmt->execute([
                ':recu_path' => $justificatifPath,
                ':id' => $paiementId
            ]);
        }
        
        // Mettre à jour le statut de la facture
        if ($nouveauStatutFacture) {
            $stmt = $pdo->prepare("
                UPDATE factures 
                SET statut = :statut 
                WHERE id = :id
            ");
            $stmt->execute([
                ':statut' => $nouveauStatutFacture,
                ':id' => $factureId
            ]);
        }
        
        $pdo->commit();
        
        jsonResponse([
            'ok' => true,
            'message' => 'Paiement enregistré avec succès',
            'paiement_id' => $paiementId,
            'facture_id' => $factureId,
            'nouveau_statut' => $nouveauStatutFacture,
            'reference' => $reference
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        
        // Supprimer le fichier uploadé en cas d'erreur
        if ($justificatifPath && file_exists(__DIR__ . '/..' . $justificatifPath)) {
            @unlink(__DIR__ . '/..' . $justificatifPath);
        }
        
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('paiements_enregistrer.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_enregistrer.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

