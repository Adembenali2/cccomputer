<?php
/**
 * API pour récupérer la liste de toutes les factures
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    $pdo = getPdo();
    
    // Récupérer toutes les factures avec les informations du client
    $sql = "
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.type,
            f.montant_ht,
            f.tva,
            f.montant_ttc,
            f.statut,
            f.pdf_path,
            f.created_at,
            c.id as client_id,
            c.raison_sociale as client_nom,
            c.numero_client as client_code,
            c.nom_dirigeant as client_nom_dirigeant,
            c.prenom_dirigeant as client_prenom_dirigeant,
            c.email as client_email,
            f.email_envoye,
            f.date_envoi_email
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        ORDER BY f.date_facture DESC, f.created_at DESC
    ";
    
    $stmt = $pdo->query($sql);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les données pour le frontend
    $formatted = [];
    foreach ($factures as $facture) {
        $formatted[] = [
            'id' => (int)$facture['id'],
            'numero' => $facture['numero'],
            'date_facture' => $facture['date_facture'],
            'date_facture_formatted' => date('d/m/Y', strtotime($facture['date_facture'])),
            'type' => $facture['type'],
            'montant_ht' => (float)$facture['montant_ht'],
            'tva' => (float)$facture['tva'],
            'montant_ttc' => (float)$facture['montant_ttc'],
            'statut' => $facture['statut'],
            'pdf_path' => $facture['pdf_path'],
            'client_id' => (int)$facture['client_id'],
            'client_nom' => $facture['client_nom'] ?? 'Client inconnu',
            'client_code' => $facture['client_code'] ?? '',
            'client_nom_dirigeant' => $facture['client_nom_dirigeant'] ?? '',
            'client_prenom_dirigeant' => $facture['client_prenom_dirigeant'] ?? '',
            'client_email' => $facture['client_email'] ?? '',
            'email_envoye' => (bool)($facture['email_envoye'] ?? false),
            'date_envoi_email' => $facture['date_envoi_email'] ?? null,
            'created_at' => $facture['created_at']
        ];
    }
    
    // Ajouter les informations de diagnostic si demandé
    $includeDiagnostic = isset($_GET['diagnostic']) && $_GET['diagnostic'] === '1';
    
    $response = [
        'ok' => true,
        'factures' => $formatted,
        'total' => count($formatted)
    ];
    
    if ($includeDiagnostic) {
        // Informations système pour le diagnostic
        $systemInfo = [
            'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'Non défini',
            '__DIR__' => __DIR__,
            'dirname(__DIR__)' => dirname(__DIR__),
            '/app exists' => is_dir('/app'),
            '/var/www/html exists' => is_dir('/var/www/html'),
            'PHP version' => PHP_VERSION,
            'Server software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Non défini'
        ];
        
        // Tester les chemins pour chaque facture
        $diagnosticResults = [];
        foreach ($formatted as $facture) {
            if (empty($facture['pdf_path'])) continue;
            
            $pdfWebPath = $facture['pdf_path'];
            $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
            
            $result = [
                'facture_id' => $facture['id'],
                'numero' => $facture['numero'],
                'pdf_path_db' => $pdfWebPath,
                'paths_tested' => [],
                'file_found' => false,
                'actual_path' => null
            ];
            
            // Tester plusieurs chemins possibles
            $possibleBaseDirs = [];
            $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
            if ($docRoot !== '' && is_dir($docRoot)) {
                $possibleBaseDirs[] = $docRoot;
            }
            $projectDir = dirname(__DIR__);
            if (is_dir($projectDir)) {
                $possibleBaseDirs[] = $projectDir;
            }
            if (is_dir('/app')) {
                $possibleBaseDirs[] = '/app';
            }
            if (is_dir('/var/www/html')) {
                $possibleBaseDirs[] = '/var/www/html';
            }
            
            foreach ($possibleBaseDirs as $baseDir) {
                $testPath = $baseDir . '/uploads/factures/' . $relativePath;
                $exists = file_exists($testPath);
                $isFile = is_file($testPath);
                $readable = is_readable($testPath);
                $size = $exists ? filesize($testPath) : 0;
                
                $result['paths_tested'][] = [
                    'base_dir' => $baseDir,
                    'full_path' => $testPath,
                    'exists' => $exists,
                    'is_file' => $isFile,
                    'readable' => $readable,
                    'size' => $size
                ];
                
                // Si le fichier n'existe pas, vérifier ce qui existe dans le répertoire
                if (!$exists) {
                    $dirPath = dirname($testPath);
                    if (is_dir($dirPath)) {
                        $filesInDir = @scandir($dirPath);
                        if ($filesInDir) {
                            $filesList = array_filter($filesInDir, function($f) {
                                return $f !== '.' && $f !== '..';
                            });
                            $result['paths_tested'][count($result['paths_tested']) - 1]['files_in_directory'] = array_values($filesList);
                        }
                    }
                }
                
                if ($exists && $isFile) {
                    $result['file_found'] = true;
                    $result['actual_path'] = $testPath;
                    break;
                }
            }
            
            $diagnosticResults[] = $result;
        }
        
        $response['diagnostic'] = [
            'system_info' => $systemInfo,
            'factures' => $diagnosticResults
        ];
    }
    
    jsonResponse($response);
    
} catch (PDOException $e) {
    error_log('factures_liste.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_liste.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

