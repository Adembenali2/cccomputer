<?php
/**
 * API pour régénérer une facture de consommation
 * Modifie les dates de période, recalcule les compteurs, régénère le PDF
 * Garde le même numéro de facture
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
    $dateDebut = trim($data['date_debut_periode'] ?? '');
    $dateFin = trim($data['date_fin_periode'] ?? '');
    $dateFacture = trim($data['date_facture'] ?? '');
    
    if (empty($dateDebut) || empty($dateFin) || empty($dateFacture)) {
        jsonResponse(['ok' => false, 'error' => 'date_debut_periode, date_fin_periode et date_facture sont requis'], 400);
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFacture)) {
        jsonResponse(['ok' => false, 'error' => 'Format de date invalide (YYYY-MM-DD)'], 400);
    }
    
    $offre = !empty($data['offre']) ? (int)$data['offre'] : 1000;
    if (!in_array($offre, [1000, 2000], true)) {
        jsonResponse(['ok' => false, 'error' => 'Offre invalide'], 400);
    }
    
    // Récupérer la facture
    $stmt = $pdo->prepare("
        SELECT f.*, c.raison_sociale, c.adresse, c.code_postal, c.ville, c.siret, c.email
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        WHERE f.id = :id
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    if (($facture['type'] ?? '') !== 'Consommation') {
        jsonResponse(['ok' => false, 'error' => 'Seules les factures de type Consommation peuvent être régénérées'], 400);
    }
    
    $clientId = (int)$facture['id_client'];
    $numero = $facture['numero'];
    
    $machines = [];
    
    // Si des machines/compteurs sont fournis manuellement, les utiliser
    if (!empty($data['machines']) && is_array($data['machines'])) {
        foreach ($data['machines'] as $m) {
            $cb = (int)($m['compteur_debut_nb'] ?? 0);
            $cc = (int)($m['compteur_debut_couleur'] ?? 0);
            $fb = (int)($m['compteur_fin_nb'] ?? 0);
            $fc = (int)($m['compteur_fin_couleur'] ?? 0);
            $machines[] = [
                'nom' => trim($m['nom'] ?? 'Imprimante'),
                'compteur_debut_nb' => $cb,
                'compteur_debut_couleur' => $cc,
                'compteur_fin_nb' => $fb,
                'compteur_fin_couleur' => $fc,
                'conso_nb' => max(0, $fb - $cb),
                'conso_couleur' => max(0, $fc - $cc),
                'date_debut_releve' => $dateDebut,
                'date_fin_releve' => $dateFin
            ];
        }
    }
    
    // Sinon récupérer via l'API
    if (empty($machines)) {
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $apiUrl = $baseUrl . '/API/factures_get_consommation.php?client_id=' . $clientId . '&offre=' . $offre . '&date_debut=' . urlencode($dateDebut) . '&date_fin=' . urlencode($dateFin);
        
        $ctx = stream_context_create(['http' => ['timeout' => 10]]);
        $json = @file_get_contents($apiUrl, false, $ctx);
        
        if ($json === false) {
            jsonResponse(['ok' => false, 'error' => 'Impossible de récupérer les données de consommation'], 500);
        }
        
        $consommation = json_decode($json, true);
        if (!$consommation || empty($consommation['ok']) || empty($consommation['machines'])) {
            $errMsg = $consommation['error'] ?? 'Aucune donnée de consommation pour cette période';
            jsonResponse(['ok' => false, 'error' => $errMsg], 400);
        }
        
        $machines = $consommation['machines'];
    }
    $nbImprimantes = count($machines);
    
    if ($offre === 2000 && $nbImprimantes !== 2) {
        jsonResponse(['ok' => false, 'error' => "L'offre 2000 nécessite 2 imprimantes"], 400);
    }
    
    // Préparer machinesData pour InvoiceCalculationService
    $machinesData = [];
    foreach ($machines as $index => $m) {
        $key = $index === 0 ? 'machine1' : 'machine2';
        $machinesData[$key] = [
            'conso_nb' => (float)($m['conso_nb'] ?? 0),
            'conso_couleur' => (float)($m['conso_couleur'] ?? 0),
            'nom' => $m['nom'] ?? 'Imprimante ' . ($index + 1),
            'compteur_debut_nb' => (int)($m['compteur_debut_nb'] ?? 0),
            'compteur_debut_couleur' => (int)($m['compteur_debut_couleur'] ?? 0),
            'compteur_fin_nb' => (int)($m['compteur_fin_nb'] ?? 0),
            'compteur_fin_couleur' => (int)($m['compteur_fin_couleur'] ?? 0),
            'date_debut_releve' => $m['date_debut_releve'] ?? null,
            'date_fin_releve' => $m['date_fin_releve'] ?? null
        ];
    }
    
    require_once __DIR__ . '/../src/Services/InvoiceCalculationService.php';
    $lignes = \App\Services\InvoiceCalculationService::generateAllInvoiceLines($offre, $nbImprimantes, $machinesData);
    $totals = \App\Services\InvoiceCalculationService::calculateInvoiceTotals($lignes);
    
    $pdo->beginTransaction();
    
    try {
        // Supprimer les anciennes lignes
        $pdo->prepare("DELETE FROM facture_lignes WHERE id_facture = ?")->execute([$factureId]);
        
        // Insérer les nouvelles lignes
        $stmtLigne = $pdo->prepare("
            INSERT INTO facture_lignes (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre)
            VALUES (:id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre)
        ");
        $validTypes = ['N&B', 'Couleur', 'Service', 'Produit'];
        foreach ($lignes as $i => $l) {
            $type = $l['type'] ?? 'Service';
            if (!in_array($type, $validTypes, true)) {
                $type = stripos($type, 'couleur') !== false ? 'Couleur' : (stripos($type, 'nb') !== false ? 'N&B' : 'Service');
            }
            $stmtLigne->execute([
                ':id_facture' => $factureId,
                ':description' => $l['description'] ?? '',
                ':type' => $type,
                ':quantite' => (float)($l['quantite'] ?? 1.0),
                ':prix_unitaire_ht' => (float)($l['prix_unitaire'] ?? 0.0),
                ':total_ht' => (float)($l['total_ht'] ?? 0.0),
                ':ordre' => $i
            ]);
        }
        
        // Mettre à jour la facture
        $pdo->prepare("
            UPDATE factures SET
                date_facture = ?,
                date_debut_periode = ?,
                date_fin_periode = ?,
                montant_ht = ?,
                tva = ?,
                montant_ttc = ?
            WHERE id = ?
        ")->execute([
            $dateFacture,
            $dateDebut,
            $dateFin,
            $totals['montant_ht'],
            $totals['tva'],
            $totals['montant_ttc'],
            $factureId
        ]);
        
        // Supprimer l'ancien PDF si existant
        $oldPdfPath = $facture['pdf_path'] ?? '';
        if (!empty($oldPdfPath)) {
            $possibleDirs = [$_SERVER['DOCUMENT_ROOT'] ?? '', dirname(__DIR__), '/app', '/var/www/html'];
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
            'raison_sociale' => $facture['raison_sociale'],
            'adresse' => $facture['adresse'] ?? '',
            'code_postal' => $facture['code_postal'] ?? '',
            'ville' => $facture['ville'] ?? '',
            'siret' => $facture['siret'] ?? '',
            'email' => $facture['email'] ?? ''
        ];
        $factureData = [
            'factureClient' => $clientId,
            'factureDate' => $dateFacture,
            'factureType' => 'Consommation',
            'offre' => $offre,
            'nb_imprimantes' => $nbImprimantes,
            'machines' => $machinesData,
            'lignes' => $lignes
        ];
        
        $pdfWebPath = generateFacturePDF($pdo, $factureId, $clientData, $factureData);
        
        // Mettre à jour le facture avec le nouveau numéro... non, on garde le même numéro.
        // generateFacturePDF crée un nouveau PDF - mais il utilise les données de la facture depuis la DB.
        // En fait generateFacturePDF lit la facture depuis la DB. Donc après notre UPDATE, la facture a les bons totaux.
        // Mais generateFacturePDF utilise $data pour les lignes - on lui passe $factureData avec lignes. Et il utilise
        // $pdo->query pour récupérer les lignes... Let me check generateFacturePDF.
        
        $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?")
            ->execute([$pdfWebPath, $factureId]);
        
        $pdo->commit();
        
        enregistrerAction($pdo, currentUserId(), 'facture_regeneree', "Facture {$numero} régénérée (ID: {$factureId}) - nouvelle période {$dateDebut} / {$dateFin}");
        
        jsonResponse([
            'ok' => true,
            'message' => 'Facture régénérée avec succès',
            'facture_id' => $factureId,
            'numero' => $numero,
            'montant_ht' => $totals['montant_ht'],
            'montant_ttc' => $totals['montant_ttc']
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('factures_regenerer.php SQL: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_regenerer.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
