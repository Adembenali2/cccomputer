<?php
/**
 * Script de diagnostic pour l'import SFTP
 * Affiche l'état réel de l'import et pourquoi les fichiers ne sont pas traités
 * 
 * Usage: Ouvrir dans le navigateur /import/diagnostic_import_sftp.php
 */

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Diagnostic Import SFTP</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #f5f5f5; }
        .section { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h2 { color: #333; border-bottom: 2px solid #4CAF50; padding-bottom: 10px; }
        .ok { color: #4CAF50; }
        .error { color: #f44336; }
        .warning { color: #ff9800; }
        .info { color: #2196F3; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 4px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #4CAF50; color: white; }
        .badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 0.9em; }
        .badge-ok { background: #4CAF50; color: white; }
        .badge-error { background: #f44336; color: white; }
        .badge-warning { background: #ff9800; color: white; }
    </style>
</head>
<body>
    <h1>🔍 Diagnostic Import SFTP</h1>
    
    <?php
    // ====== SECTION 1: Derniers imports ======
    echo '<div class="section">';
    echo '<h2>1. Derniers imports SFTP (DB)</h2>';
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            ran_at,
            imported,
            skipped,
            ok,
            msg
        FROM import_run
        WHERE msg LIKE '%\"source\":\"SFTP\"%'
        ORDER BY ran_at DESC
        LIMIT 10
    ");
    $stmt->execute();
    $imports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($imports)) {
        echo '<p class="error">❌ Aucun import SFTP trouvé dans la base de données</p>';
    } else {
        echo '<table>';
        echo '<tr><th>ID</th><th>Date</th><th>Importé</th><th>Ignoré</th><th>OK</th><th>Détails</th></tr>';
        
        foreach ($imports as $imp) {
            $decoded = json_decode($imp['msg'], true);
            $inserted = $decoded['inserted'] ?? $imp['imported'];
            $updated = $decoded['updated'] ?? 0;
            $processedFiles = $decoded['processed_files'] ?? 0;
            $filesError = $decoded['files_error'] ?? 0;
            
            $age = time() - strtotime($imp['ran_at']);
            $ageMin = round($age / 60, 1);
            $isRecent = $age < 600; // 10 minutes
            
            $okBadge = $imp['ok'] ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-error">KO</span>';
            $recentBadge = $isRecent ? '<span class="badge badge-ok">Récent</span>' : '<span class="badge badge-warning">Ancien (' . $ageMin . ' min)</span>';
            
            echo '<tr>';
            echo '<td>' . $imp['id'] . '</td>';
            echo '<td>' . $imp['ran_at'] . ' ' . $recentBadge . '</td>';
            echo '<td>' . $inserted . ' inséré(s), ' . $updated . ' mis à jour</td>';
            echo '<td>' . $imp['skipped'] . '</td>';
            echo '<td>' . $okBadge . '</td>';
            echo '<td>';
            echo 'Fichiers traités: ' . $processedFiles . '<br>';
            echo 'Fichiers en erreur: ' . $filesError . '<br>';
            if (isset($decoded['processed_details']) && is_array($decoded['processed_details'])) {
                echo '<details><summary>Détails fichiers (' . count($decoded['processed_details']) . ')</summary>';
                echo '<pre>' . json_encode($decoded['processed_details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . '</pre>';
                echo '</details>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    
    // ====== SECTION 2: Test connexion SFTP ======
    echo '<div class="section">';
    echo '<h2>2. Test Connexion SFTP</h2>';
    
    $sftpHost = getenv('SFTP_HOST') ?: '';
    $sftpUser = getenv('SFTP_USER') ?: '';
    $sftpPass = getenv('SFTP_PASS') ?: '';
    $sftpPort = (int)(getenv('SFTP_PORT') ?: 22);
    
    if (empty($sftpHost) || empty($sftpUser) || empty($sftpPass)) {
        echo '<p class="error">❌ Variables d\'environnement SFTP manquantes</p>';
        echo '<ul>';
        echo '<li>SFTP_HOST: ' . ($sftpHost ? '✓' : '✗') . '</li>';
        echo '<li>SFTP_USER: ' . ($sftpUser ? '✓' : '✗') . '</li>';
        echo '<li>SFTP_PASS: ' . ($sftpPass ? '✓' : '✗') . '</li>';
        echo '</ul>';
    } else {
        echo '<p class="ok">✅ Variables d\'environnement présentes</p>';
        
        try {
            require_once dirname(__DIR__) . '/vendor/autoload.php';
            $sftp = new \phpseclib3\Net\SFTP($sftpHost, $sftpPort, 15);
            
            if ($sftp->login($sftpUser, $sftpPass)) {
                echo '<p class="ok">✅ Connexion SFTP réussie</p>';
                
                // Lister les fichiers dans /
                $files = $sftp->nlist('/');
                if ($files === false) {
                    echo '<p class="error">❌ Impossible de lister les fichiers dans /</p>';
                } else {
                    $csvFiles = array_filter($files, function($f) {
                        return preg_match('/^COPIEUR_MAC-[A-F0-9]{12}_\d{8}_\d{6}\.csv$/i', $f);
                    });
                    
                    echo '<p class="info">📂 Fichiers dans / (racine):</p>';
                    echo '<ul>';
                    echo '<li>Total: ' . count($files) . ' entrées</li>';
                    echo '<li>Fichiers CSV matchés: ' . count($csvFiles) . '</li>';
                    echo '</ul>';
                    
                    if (count($csvFiles) > 0) {
                        echo '<p class="warning">⚠️ ' . count($csvFiles) . ' fichier(s) CSV trouvé(s) dans / (devraient être traités)</p>';
                        echo '<details><summary>Liste des fichiers CSV</summary><ul>';
                        foreach (array_slice($csvFiles, 0, 20) as $f) {
                            echo '<li>' . htmlspecialchars($f) . '</li>';
                        }
                        echo '</ul></details>';
                    } else {
                        echo '<p class="ok">✅ Aucun fichier CSV à traiter dans /</p>';
                    }
                    
                    // Vérifier /processed
                    $processedFiles = $sftp->nlist('/processed');
                    if ($processedFiles === false) {
                        echo '<p class="warning">⚠️ Répertoire /processed introuvable ou inaccessible</p>';
                    } else {
                        $processedCount = count(array_filter($processedFiles, function($f) {
                            return $f !== '.' && $f !== '..' && preg_match('/\.csv$/i', $f);
                        }));
                        echo '<p class="info">📦 Fichiers dans /processed: ' . $processedCount . '</p>';
                    }
                    
                    // Vérifier /errors
                    $errorFiles = $sftp->nlist('/errors');
                    if ($errorFiles === false) {
                        echo '<p class="warning">⚠️ Répertoire /errors introuvable ou inaccessible</p>';
                    } else {
                        $errorCount = count(array_filter($errorFiles, function($f) {
                            return $f !== '.' && $f !== '..' && preg_match('/\.csv$/i', $f);
                        }));
                        echo '<p class="info">❌ Fichiers dans /errors: ' . $errorCount . '</p>';
                    }
                }
            } else {
                echo '<p class="error">❌ Échec de l\'authentification SFTP</p>';
            }
        } catch (Throwable $e) {
            echo '<p class="error">❌ Erreur lors de la connexion SFTP: ' . htmlspecialchars($e->getMessage()) . '</p>';
            echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
        }
    }
    echo '</div>';
    
    // ====== SECTION 3: Vérification du badge ======
    echo '<div class="section">';
    echo '<h2>3. État du Badge Dashboard</h2>';
    
    $lastImport = $imports[0] ?? null;
    if ($lastImport) {
        $decoded = json_decode($lastImport['msg'], true);
        $inserted = $decoded['inserted'] ?? $lastImport['imported'];
        $age = time() - strtotime($lastImport['ran_at']);
        $ageMin = round($age / 60, 1);
        
        echo '<p><strong>Dernier import:</strong> ' . $lastImport['ran_at'] . '</p>';
        echo '<p><strong>Âge:</strong> ' . $ageMin . ' minutes</p>';
        echo '<p><strong>Résultat affiché:</strong> ' . $inserted . ' inséré(s)</p>';
        
        if ($ageMin > 10) {
            echo '<p class="warning">⚠️ Le badge affiche un résultat ancien (' . $ageMin . ' minutes). L\'import ne s\'exécute probablement pas automatiquement.</p>';
            echo '<p class="info">💡 Solution: Configurer le cron (voir docs/GUIDE_IMPORT_AUTOMATIQUE.md)</p>';
        } else {
            echo '<p class="ok">✅ Le résultat est récent (' . $ageMin . ' minutes)</p>';
        }
        
        if ($lastImport['ok'] && $inserted > 0) {
            $processedFiles = $decoded['processed_files'] ?? 0;
            if ($processedFiles === 0) {
                echo '<p class="error">❌ PROBLÈME: Import OK mais processed_files = 0. Les fichiers n\'ont probablement pas été traités.</p>';
            } else {
                echo '<p class="ok">✅ ' . $processedFiles . ' fichier(s) traité(s)</p>';
            }
        }
    } else {
        echo '<p class="error">❌ Aucun import trouvé</p>';
    }
    echo '</div>';
    
    // ====== SECTION 4: Test manuel ======
    echo '<div class="section">';
    echo '<h2>4. Test Manuel</h2>';
    echo '<p>Pour forcer un import immédiat, exécute cette commande dans la console du navigateur (F12) :</p>';
    echo '<pre>fetch(\'/import/run_import_if_due.php?limit=20&force=1\', {method:\'POST\', credentials:\'same-origin\'}).then(r => r.json()).then(console.log);</pre>';
    echo '</div>';
    ?>
    
</body>
</html>

