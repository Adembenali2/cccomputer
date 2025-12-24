<?php
/**
 * API pour générer des factures pour plusieurs clients
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
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['clients']) || !is_array($data['clients']) || empty($data['date_facture']) || empty($data['type'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
    }
    
    $clients = $data['clients'];
    $dateFacture = trim($data['date_facture']);
    $type = trim($data['type']);
    $dateDebut = !empty($data['date_debut']) ? trim($data['date_debut']) : null;
    $dateFin = !empty($data['date_fin']) ? trim($data['date_fin']) : null;
    $envoyerEmail = !empty($data['envoyer_email']) ? true : false;
    
    // Validation
    if (count($clients) === 0) {
        jsonResponse(['ok' => false, 'error' => 'Aucun client sélectionné'], 400);
    }
    
    $typesValides = ['Consommation', 'Achat', 'Service'];
    if (!in_array($type, $typesValides, true)) {
        jsonResponse(['ok' => false, 'error' => 'Type de facture invalide'], 400);
    }
    
    // Validation de la date
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFacture)) {
        jsonResponse(['ok' => false, 'error' => 'Date de facture invalide'], 400);
    }
    
    // Fonction pour générer un numéro de facture
    require_once __DIR__ . '/factures_generer.php';
    
    $facturesGenerees = 0;
    $facturesErreurs = [];
    
    // Pour chaque client, générer une facture
    foreach ($clients as $clientId) {
        $clientId = (int)$clientId;
        
        try {
            // Récupérer le client
            $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $clientId]);
            $client = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$client) {
                $facturesErreurs[] = "Client #{$clientId} introuvable";
                continue;
            }
            
            // Générer le numéro de facture
            $numeroFacture = generateFactureNumber($pdo, $type);
            
            // Pour l'instant, créer une facture vide (à compléter selon vos besoins)
            // Vous pouvez adapter cette partie pour calculer les montants selon la consommation
            $montantHT = 0.00;
            $tva = 0.00;
            $montantTTC = 0.00;
            
            $pdo->beginTransaction();
            
            try {
                // Insérer la facture
                $stmt = $pdo->prepare("
                    INSERT INTO factures (
                        id_client, numero, date_facture, date_debut_periode, date_fin_periode,
                        type, montant_ht, tva, montant_ttc, statut, created_by
                    ) VALUES (
                        :id_client, :numero, :date_facture, :date_debut, :date_fin,
                        :type, :montant_ht, :tva, :montant_ttc, 'brouillon', :created_by
                    )
                ");
                
                $stmt->execute([
                    ':id_client' => $clientId,
                    ':numero' => $numeroFacture,
                    ':date_facture' => $dateFacture,
                    ':date_debut' => $dateDebut,
                    ':date_fin' => $dateFin,
                    ':type' => $type,
                    ':montant_ht' => $montantHT,
                    ':tva' => $tva,
                    ':montant_ttc' => $montantTTC,
                    ':created_by' => $userId
                ]);
                
                $factureId = $pdo->lastInsertId();
                
                // Note: Ici vous pouvez ajouter des lignes de facture par défaut
                // ou laisser l'utilisateur les ajouter manuellement après
                
                $pdo->commit();
                $facturesGenerees++;
                
                // Si l'envoi par email est demandé
                if ($envoyerEmail && !empty($client['email'])) {
                    try {
                        // Note: Pour envoyer par email, il faudrait d'abord générer le PDF
                        // Pour l'instant, on ne fait que créer la facture
                        // Vous pouvez ajouter la génération PDF et l'envoi email ici si nécessaire
                    } catch (Exception $e) {
                        error_log("Erreur envoi email pour facture #{$factureId}: " . $e->getMessage());
                    }
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $facturesErreurs[] = "Client {$client['raison_sociale']}: " . $e->getMessage();
            }
            
        } catch (Exception $e) {
            $facturesErreurs[] = "Client #{$clientId}: " . $e->getMessage();
        }
    }
    
    $message = "{$facturesGenerees} facture(s) générée(s) avec succès";
    if (count($facturesErreurs) > 0) {
        $message .= ". " . count($facturesErreurs) . " erreur(s)";
    }
    
    jsonResponse([
        'ok' => true,
        'message' => $message,
        'factures_generees' => $facturesGenerees,
        'erreurs' => $facturesErreurs
    ]);
    
} catch (PDOException $e) {
    error_log('factures_generer_clients.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_generer_clients.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

