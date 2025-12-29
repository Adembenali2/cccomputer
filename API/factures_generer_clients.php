<?php
/**
 * API pour générer automatiquement des factures de consommation pour tous les clients d'une période
 * Applique les mêmes règles que la génération manuelle :
 * - Numéros de facture automatiques
 * - Ajustement des dates si pas de compteur à la date exacte
 * - Exclusion des clients sans imprimantes
 * - Exclusion des clients sans relevé depuis 1 mois
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

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
    
    if (!$data || empty($data['date_debut']) || empty($data['date_fin']) || empty($data['date_facture'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes: date_debut, date_fin et date_facture sont requis'], 400);
    }
    
    $dateDebut = trim($data['date_debut']);
    $dateFin = trim($data['date_fin']);
    $dateFacture = trim($data['date_facture']);
    $offre = !empty($data['offre']) ? (int)$data['offre'] : 1000; // Par défaut offre 1000
    
    // Validation des dates
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || 
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin) || 
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFacture)) {
        jsonResponse(['ok' => false, 'error' => 'Format de date invalide (attendu: YYYY-MM-DD)'], 400);
    }
    
    if (!in_array($offre, [1000, 2000], true)) {
        jsonResponse(['ok' => false, 'error' => 'Offre invalide (doit être 1000 ou 2000)'], 400);
    }
    
    // Charger le service de calcul
    if (!class_exists('App\Services\InvoiceCalculationService')) {
        require_once __DIR__ . '/../src/Services/InvoiceCalculationService.php';
    }
    $calculationService = new \App\Services\InvoiceCalculationService();
    
    // Charger la fonction de génération de numéro de facture
    require_once __DIR__ . '/factures_generer.php';
    
    // Récupérer tous les clients (la colonne `actif` n'existe pas dans certains schémas)
    // Le filtrage des clients réellement facturables est géré plus bas (imprimantes, relevés, etc.)
    $stmt = $pdo->prepare("SELECT id, raison_sociale, adresse, code_postal, ville, siret, email FROM clients ORDER BY raison_sociale");
    $stmt->execute();
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $facturesGenerees = [];
    $clientsExclus = [];
    
    // Pour chaque client, vérifier et générer la facture
    foreach ($clients as $client) {
        $clientId = (int)$client['id'];
        $clientNom = $client['raison_sociale'];
        
        try {
            // 1. Vérifier si le client a des imprimantes attribuées
            $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM photocopieurs_clients WHERE id_client = :client_id");
            $stmt->execute([':client_id' => $clientId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $nbPhotocopieurs = (int)($result['nb'] ?? 0);
            
            if ($nbPhotocopieurs === 0) {
                $clientsExclus[] = [
                    'client_id' => $clientId,
                    'client_nom' => $clientNom,
                    'raison' => 'Aucune imprimante attribuée'
                ];
                continue;
            }
            
            // 2. Vérifier si le client a reçu un relevé dans le dernier mois
            $dateLimite = date('Y-m-d', strtotime('-1 month'));
            // Attention : avec PDO MySQL en mode prepared natif, on ne peut pas réutiliser le même
            // paramètre nommé plusieurs fois dans la requête, sinon on obtient HY093.
            // On utilise donc 2 paramètres différents avec la même valeur.
            $stmt = $pdo->prepare("
                SELECT MAX(Timestamp) as dernier_releve
                FROM (
                    SELECT Timestamp
                    FROM compteur_relevee cr
                    INNER JOIN photocopieurs_clients pc ON cr.mac_norm = pc.mac_norm
                    WHERE pc.id_client = :client_id1
                      AND cr.mac_norm IS NOT NULL
                      AND cr.mac_norm != ''
                    UNION ALL
                    SELECT Timestamp
                    FROM compteur_relevee_ancien cra
                    INNER JOIN photocopieurs_clients pc2 ON cra.mac_norm = pc2.mac_norm
                    WHERE pc2.id_client = :client_id2
                      AND cra.mac_norm IS NOT NULL
                      AND cra.mac_norm != ''
                ) AS combined
            ");
            $stmt->execute([':client_id1' => $clientId, ':client_id2' => $clientId]);
            $resultReleve = $stmt->fetch(PDO::FETCH_ASSOC);
            $dernierReleve = $resultReleve['dernier_releve'] ?? null;
            
            if (!$dernierReleve || $dernierReleve < $dateLimite) {
                $dateReleveFormatted = $dernierReleve ? date('d/m/Y', strtotime($dernierReleve)) : 'Jamais';
                $clientsExclus[] = [
                    'client_id' => $clientId,
                    'client_nom' => $clientNom,
                    'raison' => "Aucun relevé reçu depuis plus d'un mois (dernier relevé: {$dateReleveFormatted})"
                ];
                continue;
            }
            
            // 3. Récupérer les données de consommation (réutiliser la logique de factures_get_consommation.php)
            // Récupérer les photocopieurs du client
            $stmt = $pdo->prepare("
                SELECT 
                    pc.id,
                    pc.SerialNumber,
                    pc.MacAddress,
                    pc.mac_norm
                FROM photocopieurs_clients pc
                WHERE pc.id_client = :client_id
                ORDER BY pc.id
                LIMIT 2
            ");
            $stmt->execute([':client_id' => $clientId]);
            $photocopieursRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($photocopieursRaw)) {
                $clientsExclus[] = [
                    'client_id' => $clientId,
                    'client_nom' => $clientNom,
                    'raison' => 'Aucun photocopieur trouvé'
                ];
                continue;
            }
            
            // Récupérer les infos des photocopieurs
            $photocopieurs = [];
            foreach ($photocopieursRaw as $pc) {
                $macNorm = $pc['mac_norm'];
                
                // Récupérer les infos les plus récentes
                $stmtInfo1 = $pdo->prepare("
                    SELECT Nom, Model, Timestamp
                    FROM compteur_relevee
                    WHERE mac_norm = :mac_norm
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp DESC
                    LIMIT 1
                ");
                $stmtInfo1->execute([':mac_norm' => $macNorm]);
                $info1 = $stmtInfo1->fetch(PDO::FETCH_ASSOC);
                
                $stmtInfo2 = $pdo->prepare("
                    SELECT Nom, Model, Timestamp
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = :mac_norm
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp DESC
                    LIMIT 1
                ");
                $stmtInfo2->execute([':mac_norm' => $macNorm]);
                $info2 = $stmtInfo2->fetch(PDO::FETCH_ASSOC);
                
                $info = null;
                if ($info1 && $info2) {
                    $ts1 = strtotime($info1['Timestamp']);
                    $ts2 = strtotime($info2['Timestamp']);
                    $info = ($ts1 >= $ts2) ? $info1 : $info2;
                } elseif ($info1) {
                    $info = $info1;
                } elseif ($info2) {
                    $info = $info2;
                }
                
                $photocopieurs[] = [
                    'id' => $pc['id'],
                    'SerialNumber' => $pc['SerialNumber'],
                    'MacAddress' => $pc['MacAddress'],
                    'mac_norm' => $macNorm,
                    'nom' => !empty($info['Nom']) ? $info['Nom'] : ('Imprimante ' . $pc['id']),
                    'modele' => !empty($info['Model']) ? $info['Model'] : 'Inconnu'
                ];
            }
            
            // Récupérer les relevés pour chaque photocopieur (même logique que factures_get_consommation.php)
            $machines = [];
            $datesAjustees = [];
            
            foreach ($photocopieurs as $pc) {
                $macNorm = $pc['mac_norm'] ?? '';
                if (empty($macNorm) || strlen(trim($macNorm)) === 0) {
                    continue;
                }
                $macNorm = trim($macNorm);
                
                // Récupérer le PREMIER relevé du jour de début ou le dernier avant
                $stmtStart1 = $pdo->prepare("
                    SELECT 
                        COALESCE(TotalBW, 0) as TotalBW,
                        COALESCE(TotalColor, 0) as TotalColor,
                        Timestamp
                    FROM compteur_relevee
                    WHERE mac_norm = :mac_norm
                      AND DATE(Timestamp) = :date_debut
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp ASC
                    LIMIT 1
                ");
                $stmtStart1->execute([':mac_norm' => $macNorm, ':date_debut' => $dateDebut]);
                $startReleve1 = $stmtStart1->fetch(PDO::FETCH_ASSOC);
                
                $stmtStart2 = $pdo->prepare("
                    SELECT 
                        COALESCE(TotalBW, 0) as TotalBW,
                        COALESCE(TotalColor, 0) as TotalColor,
                        Timestamp
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = :mac_norm
                      AND DATE(Timestamp) = :date_debut
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp ASC
                    LIMIT 1
                ");
                $stmtStart2->execute([':mac_norm' => $macNorm, ':date_debut' => $dateDebut]);
                $startReleve2 = $stmtStart2->fetch(PDO::FETCH_ASSOC);
                
                $startReleve = null;
                $startDateAjustee = false;
                if ($startReleve1 && $startReleve2) {
                    $ts1 = strtotime($startReleve1['Timestamp']);
                    $ts2 = strtotime($startReleve2['Timestamp']);
                    $startReleve = ($ts1 <= $ts2) ? $startReleve1 : $startReleve2;
                } elseif ($startReleve1) {
                    $startReleve = $startReleve1;
                } elseif ($startReleve2) {
                    $startReleve = $startReleve2;
                } else {
                    // Chercher le dernier relevé avant cette date
                    $stmtStartFallback1 = $pdo->prepare("
                        SELECT 
                            COALESCE(TotalBW, 0) as TotalBW,
                            COALESCE(TotalColor, 0) as TotalColor,
                            Timestamp
                        FROM compteur_relevee
                        WHERE mac_norm = :mac_norm
                          AND DATE(Timestamp) < :date_debut
                          AND mac_norm IS NOT NULL
                          AND mac_norm != ''
                        ORDER BY Timestamp DESC
                        LIMIT 1
                    ");
                    $stmtStartFallback1->execute([':mac_norm' => $macNorm, ':date_debut' => $dateDebut]);
                    $startFallback1 = $stmtStartFallback1->fetch(PDO::FETCH_ASSOC);
                    
                    $stmtStartFallback2 = $pdo->prepare("
                        SELECT 
                            COALESCE(TotalBW, 0) as TotalBW,
                            COALESCE(TotalColor, 0) as TotalColor,
                            Timestamp
                        FROM compteur_relevee_ancien
                        WHERE mac_norm = :mac_norm
                          AND DATE(Timestamp) < :date_debut
                          AND mac_norm IS NOT NULL
                          AND mac_norm != ''
                        ORDER BY Timestamp DESC
                        LIMIT 1
                    ");
                    $stmtStartFallback2->execute([':mac_norm' => $macNorm, ':date_debut' => $dateDebut]);
                    $startFallback2 = $stmtStartFallback2->fetch(PDO::FETCH_ASSOC);
                    
                    if ($startFallback1 && $startFallback2) {
                        $ts1 = strtotime($startFallback1['Timestamp']);
                        $ts2 = strtotime($startFallback2['Timestamp']);
                        $startReleve = ($ts1 >= $ts2) ? $startFallback1 : $startFallback2;
                    } elseif ($startFallback1) {
                        $startReleve = $startFallback1;
                    } elseif ($startFallback2) {
                        $startReleve = $startFallback2;
                    }
                    
                    if ($startReleve) {
                        $startDateAjustee = true;
                        $datesAjustees[] = [
                            'type' => 'debut',
                            'date_demandee' => date('d/m/Y', strtotime($dateDebut)),
                            'date_utilisee' => date('d/m/Y', strtotime($startReleve['Timestamp'])),
                            'machine' => $pc['nom']
                        ];
                    }
                }
                
                // Récupérer le DERNIER relevé du jour de fin ou le dernier avant
                $stmtEnd1 = $pdo->prepare("
                    SELECT 
                        COALESCE(TotalBW, 0) as TotalBW,
                        COALESCE(TotalColor, 0) as TotalColor,
                        Timestamp
                    FROM compteur_relevee
                    WHERE mac_norm = :mac_norm
                      AND DATE(Timestamp) = :date_fin
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp DESC
                    LIMIT 1
                ");
                $stmtEnd1->execute([':mac_norm' => $macNorm, ':date_fin' => $dateFin]);
                $endReleve1 = $stmtEnd1->fetch(PDO::FETCH_ASSOC);
                
                $stmtEnd2 = $pdo->prepare("
                    SELECT 
                        COALESCE(TotalBW, 0) as TotalBW,
                        COALESCE(TotalColor, 0) as TotalColor,
                        Timestamp
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = :mac_norm
                      AND DATE(Timestamp) = :date_fin
                      AND mac_norm IS NOT NULL
                      AND mac_norm != ''
                    ORDER BY Timestamp DESC
                    LIMIT 1
                ");
                $stmtEnd2->execute([':mac_norm' => $macNorm, ':date_fin' => $dateFin]);
                $endReleve2 = $stmtEnd2->fetch(PDO::FETCH_ASSOC);
                
                $endReleve = null;
                $endDateAjustee = false;
                if ($endReleve1 && $endReleve2) {
                    $ts1 = strtotime($endReleve1['Timestamp']);
                    $ts2 = strtotime($endReleve2['Timestamp']);
                    $endReleve = ($ts1 >= $ts2) ? $endReleve1 : $endReleve2;
                } elseif ($endReleve1) {
                    $endReleve = $endReleve1;
                } elseif ($endReleve2) {
                    $endReleve = $endReleve2;
                } else {
                    // Chercher le dernier relevé avant cette date
                    $stmtEndFallback1 = $pdo->prepare("
                        SELECT 
                            COALESCE(TotalBW, 0) as TotalBW,
                            COALESCE(TotalColor, 0) as TotalColor,
                            Timestamp
                        FROM compteur_relevee
                        WHERE mac_norm = :mac_norm
                          AND DATE(Timestamp) < :date_fin
                          AND mac_norm IS NOT NULL
                          AND mac_norm != ''
                        ORDER BY Timestamp DESC
                        LIMIT 1
                    ");
                    $stmtEndFallback1->execute([':mac_norm' => $macNorm, ':date_fin' => $dateFin]);
                    $endFallback1 = $stmtEndFallback1->fetch(PDO::FETCH_ASSOC);
                    
                    $stmtEndFallback2 = $pdo->prepare("
                        SELECT 
                            COALESCE(TotalBW, 0) as TotalBW,
                            COALESCE(TotalColor, 0) as TotalColor,
                            Timestamp
                        FROM compteur_relevee_ancien
                        WHERE mac_norm = :mac_norm
                          AND DATE(Timestamp) < :date_fin
                          AND mac_norm IS NOT NULL
                          AND mac_norm != ''
                        ORDER BY Timestamp DESC
                        LIMIT 1
                    ");
                    $stmtEndFallback2->execute([':mac_norm' => $macNorm, ':date_fin' => $dateFin]);
                    $endFallback2 = $stmtEndFallback2->fetch(PDO::FETCH_ASSOC);
                    
                    if ($endFallback1 && $endFallback2) {
                        $ts1 = strtotime($endFallback1['Timestamp']);
                        $ts2 = strtotime($endFallback2['Timestamp']);
                        $endReleve = ($ts1 >= $ts2) ? $endFallback1 : $endFallback2;
                    } elseif ($endFallback1) {
                        $endReleve = $endFallback1;
                    } elseif ($endFallback2) {
                        $endReleve = $endFallback2;
                    }
                    
                    if ($endReleve) {
                        $endDateAjustee = true;
                        $datesAjustees[] = [
                            'type' => 'fin',
                            'date_demandee' => date('d/m/Y', strtotime($dateFin)),
                            'date_utilisee' => date('d/m/Y', strtotime($endReleve['Timestamp'])),
                            'machine' => $pc['nom']
                        ];
                    }
                }
                
                // Calculer les consommations
                $compteurDebutNB = 0;
                $compteurDebutCouleur = 0;
                $compteurFinNB = 0;
                $compteurFinCouleur = 0;
                $consoNB = 0;
                $consoColor = 0;
                
                if ($startReleve) {
                    $compteurDebutNB = (int)$startReleve['TotalBW'];
                    $compteurDebutCouleur = (int)$startReleve['TotalColor'];
                }
                
                if ($endReleve) {
                    $compteurFinNB = (int)$endReleve['TotalBW'];
                    $compteurFinCouleur = (int)$endReleve['TotalColor'];
                }
                
                if ($startReleve && $endReleve) {
                    $consoNB = max(0, $compteurFinNB - $compteurDebutNB);
                    $consoColor = max(0, $compteurFinCouleur - $compteurDebutCouleur);
                }
                
                $machines[] = [
                    'id' => $pc['id'],
                    'nom' => $pc['nom'],
                    'modele' => $pc['modele'],
                    'mac_norm' => $macNorm,
                    'compteur_debut_nb' => $compteurDebutNB,
                    'compteur_debut_couleur' => $compteurDebutCouleur,
                    'compteur_fin_nb' => $compteurFinNB,
                    'compteur_fin_couleur' => $compteurFinCouleur,
                    'conso_nb' => $consoNB,
                    'conso_couleur' => $consoColor,
                    'date_debut_releve' => $startReleve ? $startReleve['Timestamp'] : null,
                    'date_fin_releve' => $endReleve ? $endReleve['Timestamp'] : null
                ];
            }
            
            if (empty($machines)) {
                $clientsExclus[] = [
                    'client_id' => $clientId,
                    'client_nom' => $clientNom,
                    'raison' => 'Impossible de récupérer les données de consommation'
                ];
                continue;
            }
            
            // 4. Préparer les données pour la génération de facture
            $machinesData = [];
            $nbImprimantes = count($machines);
            
            foreach ($machines as $index => $machine) {
                $machineKey = $index === 0 ? 'machine1' : 'machine2';
                $machinesData[$machineKey] = [
                    'conso_nb' => (float)($machine['conso_nb'] ?? 0),
                    'conso_couleur' => (float)($machine['conso_couleur'] ?? 0),
                    'nom' => $machine['nom'] ?? 'Imprimante ' . ($index + 1),
                    'compteur_debut_nb' => (int)($machine['compteur_debut_nb'] ?? 0),
                    'compteur_debut_couleur' => (int)($machine['compteur_debut_couleur'] ?? 0),
                    'compteur_fin_nb' => (int)($machine['compteur_fin_nb'] ?? 0),
                    'compteur_fin_couleur' => (int)($machine['compteur_fin_couleur'] ?? 0),
                    'date_debut_releve' => $machine['date_debut_releve'] ?? null,
                    'date_fin_releve' => $machine['date_fin_releve'] ?? null
                ];
            }
            
            // 5. Générer les lignes de facture
            $lignes = $calculationService::generateAllInvoiceLines(
                $offre,
                $nbImprimantes,
                $machinesData
            );
            
            // Calculer les totaux
            $totals = $calculationService::calculateInvoiceTotals($lignes);
            $montantHT = $totals['montant_ht'];
            $tva = $totals['tva'];
            $montantTTC = $totals['montant_ttc'];
            
            // 6. Générer le numéro de facture
            $numeroFacture = generateFactureNumber($pdo, 'Consommation');
            
            // 7. Créer la facture en base
            $pdo->beginTransaction();
            
            try {
                // Insertion Facture
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
                    ':type' => 'Consommation',
                    ':montant_ht' => $montantHT,
                    ':tva' => $tva,
                    ':montant_ttc' => $montantTTC,
                    ':created_by' => $userId
                ]);
                $factureId = $pdo->lastInsertId();
                
                // Insertion Lignes
                $validLigneTypes = ['N&B', 'Couleur', 'Service', 'Produit'];
                $stmtLigne = $pdo->prepare("
                    INSERT INTO facture_lignes (
                        id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre
                    ) VALUES (
                        :id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre
                    )
                ");
                
                foreach ($lignes as $i => $l) {
                    $ligneType = $l['type'] ?? 'Service';
                    if (!in_array($ligneType, $validLigneTypes, true)) {
                        if (stripos($ligneType, 'couleur') !== false || stripos($ligneType, 'color') !== false) {
                            $ligneType = 'Couleur';
                        } elseif (stripos($ligneType, 'nb') !== false || stripos($ligneType, 'noir') !== false) {
                            $ligneType = 'N&B';
                        } else {
                            $ligneType = 'Service';
                        }
                    }
                    
                    $stmtLigne->execute([
                        ':id_facture' => $factureId,
                        ':description' => $l['description'] ?? '',
                        ':type' => $ligneType,
                        ':quantite' => (float)($l['quantite'] ?? 1.0),
                        ':prix_unitaire_ht' => (float)($l['prix_unitaire'] ?? 0.0),
                        ':total_ht' => (float)($l['total_ht'] ?? 0.0),
                        ':ordre' => $i
                    ]);
                }
                
                // Génération PDF
                $clientData = [
                    'id' => $clientId,
                    'raison_sociale' => $client['raison_sociale'],
                    'adresse' => $client['adresse'] ?? '',
                    'code_postal' => $client['code_postal'] ?? '',
                    'ville' => $client['ville'] ?? '',
                    'siret' => $client['siret'] ?? '',
                    'email' => $client['email'] ?? ''
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
                
                // Vérifier que le fichier existe
                $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
                $projectDir = dirname(__DIR__);
                $baseDir = is_dir($docRoot) ? $docRoot : $projectDir;
                $baseUploadDir = $baseDir . '/uploads';
                $facturesDir = $baseUploadDir . '/factures';
                $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
                $actualFilePath = $facturesDir . '/' . $relativePath;
                
                if (!file_exists($actualFilePath)) {
                    throw new RuntimeException('Le fichier PDF n\'a pas pu être créé: ' . $actualFilePath);
                }
                
                // Mise à jour chemin PDF (statut reste 'brouillon' jusqu'à l'envoi par email)
                $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?")
                    ->execute([$pdfWebPath, $factureId]);
                
                $pdo->commit();
                
                $facturesGenerees[] = [
                    'client_id' => $clientId,
                    'client_nom' => $clientNom,
                    'facture_id' => $factureId,
                    'numero' => $numeroFacture,
                    'montant_ttc' => $montantTTC,
                    'dates_ajustees' => $datesAjustees
                ];
                
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            error_log("Erreur génération facture pour client #{$clientId} ({$clientNom}): " . $e->getMessage());
            $clientsExclus[] = [
                'client_id' => $clientId,
                'client_nom' => $clientNom,
                'raison' => 'Erreur: ' . $e->getMessage()
            ];
        }
    }
    
    // Préparer la réponse
    $message = count($facturesGenerees) . " facture(s) générée(s) avec succès";
    if (count($clientsExclus) > 0) {
        $message .= ". " . count($clientsExclus) . " client(s) exclu(s)";
    }
    
    jsonResponse([
        'ok' => true,
        'message' => $message,
        'factures_generees' => $facturesGenerees,
        'clients_exclus' => $clientsExclus,
        'total_clients' => count($clients),
        'total_generees' => count($facturesGenerees),
        'total_exclus' => count($clientsExclus)
    ]);
    
} catch (PDOException $e) {
    error_log('factures_generer_clients.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('factures_generer_clients.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}
?>
