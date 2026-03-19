<?php
/**
 * API pour modifier une facture
 * Permet de modifier : date_facture, statut, type
 * Pour Achat/Service : lignes (produit, quantité, montant, description) + régénération PDF
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id'])) {
        jsonResponse(['ok' => false, 'error' => 'facture_id requis'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    if ($factureId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'facture_id invalide'], 400);
    }
    
    // Vérifier que la facture existe
    $stmt = $pdo->prepare("
        SELECT f.id, f.numero, f.type, f.id_client, f.date_facture, f.statut, f.pdf_path,
               c.raison_sociale, c.adresse, c.code_postal, c.ville, c.siret, c.email
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        WHERE f.id = :id LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    $factureType = $facture['type'] ?? '';
    $lignes = $data['lignes'] ?? null;
    $hasLignes = !empty($lignes) && is_array($lignes) && in_array($factureType, ['Achat', 'Service'], true);
    
    if ($hasLignes) {
        // Modification Achat/Service : lignes + totaux + PDF
        $validLigneTypes = ['N&B', 'Couleur', 'Service', 'Produit'];
        $montantHT = 0;
        foreach ($lignes as $l) {
            $qty = (float)($l['quantite'] ?? 1.0);
            $pu = (float)($l['prix_unitaire'] ?? $l['prix_unitaire_ht'] ?? 0.0);
            $total = (float)($l['total_ht'] ?? ($qty * $pu));
            $montantHT += $total;
        }
        $tva = $montantHT * 0.20;
        $montantTTC = $montantHT + $tva;
        
        $dateFacture = !empty($data['date_facture']) ? date('Y-m-d', strtotime($data['date_facture'])) : ($facture['date_facture'] ?? date('Y-m-d'));
        $statut = $data['statut'] ?? $facture['statut'] ?? 'en_attente';
        $statutsValides = ['brouillon', 'en_attente', 'envoyee', 'en_cours', 'en_retard', 'payee', 'annulee'];
        if (!in_array($statut, $statutsValides, true)) {
            $statut = 'en_attente';
        }
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM facture_lignes WHERE id_facture = ?")->execute([$factureId]);
            
            $stmtLigne = $pdo->prepare("
                INSERT INTO facture_lignes (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre)
                VALUES (:id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre)
            ");
            foreach ($lignes as $i => $l) {
                $desc = trim($l['description'] ?? '');
                $type = $l['type'] ?? ($factureType === 'Service' ? 'Service' : 'Produit');
                if (!in_array($type, $validLigneTypes, true)) {
                    $type = $factureType === 'Service' ? 'Service' : 'Produit';
                }
                $qty = (float)($l['quantite'] ?? 1.0);
                $pu = (float)($l['prix_unitaire'] ?? $l['prix_unitaire_ht'] ?? 0.0);
                $total = (float)($l['total_ht'] ?? ($qty * $pu));
                
                $stmtLigne->execute([
                    ':id_facture' => $factureId,
                    ':description' => $desc,
                    ':type' => $type,
                    ':quantite' => $qty,
                    ':prix_unitaire_ht' => $pu,
                    ':total_ht' => $total,
                    ':ordre' => $i
                ]);
            }
            
            $pdo->prepare("
                UPDATE factures SET date_facture = ?, statut = ?, montant_ht = ?, tva = ?, montant_ttc = ? WHERE id = ?
            ")->execute([$dateFacture, $statut, $montantHT, $tva, $montantTTC, $factureId]);
            
            // Supprimer l'ancien PDF
            $oldPdfPath = $facture['pdf_path'] ?? '';
            if (!empty($oldPdfPath)) {
                $possibleDirs = [rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'), dirname(__DIR__), '/app', '/var/www/html'];
                foreach ($possibleDirs as $base) {
                    if (empty($base) || !is_dir($base)) continue;
                    $fullPath = $base . $oldPdfPath;
                    if (file_exists($fullPath) && is_file($fullPath)) {
                        @unlink($fullPath);
                        break;
                    }
                }
            }
            
            // Régénérer le PDF
            require_once __DIR__ . '/factures_generer.php';
            $clientData = [
                'raison_sociale' => $facture['raison_sociale'] ?? '',
                'adresse' => $facture['adresse'] ?? '',
                'code_postal' => $facture['code_postal'] ?? '',
                'ville' => $facture['ville'] ?? '',
                'siret' => $facture['siret'] ?? '',
                'email' => $facture['email'] ?? ''
            ];
            $pdfWebPath = generateFacturePDF($pdo, $factureId, $clientData, []);
            $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?")->execute([$pdfWebPath, $factureId]);
            
            $pdo->commit();
            enregistrerAction($pdo, currentUserId(), 'facture_modifiee', "Facture {$facture['numero']} modifiée (lignes Achat/Service) (ID: {$factureId})");
            
            jsonResponse([
                'ok' => true,
                'message' => 'Facture modifiée avec succès',
                'facture_id' => $factureId,
                'montant_ht' => $montantHT,
                'montant_ttc' => $montantTTC
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    } else {
        // Modification simple : date, statut, type
        $updates = [];
        $params = [':id' => $factureId];
        
        if (!empty($data['date_facture'])) {
            $date = trim($data['date_facture']);
            $ts = strtotime($date);
            if ($ts !== false) {
                $updates[] = "date_facture = :date_facture";
                $params[':date_facture'] = date('Y-m-d', $ts);
            }
        }
        
        if (isset($data['statut'])) {
            $statutsValides = ['brouillon', 'en_attente', 'envoyee', 'en_cours', 'en_retard', 'payee', 'annulee'];
            if (in_array($data['statut'], $statutsValides, true)) {
                $updates[] = "statut = :statut";
                $params[':statut'] = $data['statut'];
            }
        }
        
        if (isset($data['type'])) {
            $typesValides = ['Consommation', 'Achat', 'Service'];
            if (in_array($data['type'], $typesValides, true)) {
                $updates[] = "type = :type";
                $params[':type'] = $data['type'];
            }
        }
        
        if (empty($updates)) {
            jsonResponse(['ok' => false, 'error' => 'Aucune modification fournie'], 400);
        }
        
        $sql = "UPDATE factures SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        enregistrerAction($pdo, currentUserId(), 'facture_modifiee', "Facture {$facture['numero']} modifiée (ID: {$factureId})");
        
        jsonResponse([
            'ok' => true,
            'message' => 'Facture modifiée avec succès',
            'facture_id' => $factureId
        ]);
    }
    
} catch (PDOException $e) {
    error_log('factures_modifier.php SQL: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_modifier.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
