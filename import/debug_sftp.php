<?php
/**
 * Script de diagnostic SFTP
 * Permet de tester isolément chaque composant de l'import SFTP
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');

echo "=== DIAGNOSTIC SFTP ===\n\n";

// 1. Vérifier les chemins
echo "1. Vérification des chemins...\n";
$projectRoot = dirname(__DIR__);
echo "   Project root: $projectRoot\n";
echo "   Existe: " . (is_dir($projectRoot) ? '✓' : '✗') . "\n\n";

$paths = [
    'includes/db.php' => $projectRoot . '/includes/db.php',
    'vendor/autoload.php' => $projectRoot . '/vendor/autoload.php',
    'API/scripts/upload_compteur.php' => $projectRoot . '/API/scripts/upload_compteur.php',
];

foreach ($paths as $name => $path) {
    echo "   $name: $path\n";
    echo "   Existe: " . (is_file($path) ? '✓' : '✗') . "\n";
}
echo "\n";

// 2. Vérifier les variables d'environnement
echo "2. Variables d'environnement...\n";
$envVars = ['SFTP_HOST', 'SFTP_USER', 'SFTP_PASS', 'SFTP_PORT', 'SFTP_TIMEOUT'];
foreach ($envVars as $var) {
    $val = getenv($var);
    if ($var === 'SFTP_PASS') {
        echo "   $var: " . ($val ? '✓ (' . strlen($val) . ' chars)' : '✗') . "\n";
    } else {
        echo "   $var: " . ($val ? "✓ ($val)" : '✗') . "\n";
    }
}
echo "\n";

// 3. Tester la connexion à la base de données
echo "3. Test de connexion à la base de données...\n";
try {
    require_once $projectRoot . '/includes/db.php';
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "   ✓ Connexion PDO établie\n";
        $stmt = $pdo->query("SELECT 1");
        echo "   ✓ Requête de test réussie\n";
    } else {
        echo "   ✗ \$pdo non défini ou invalide\n";
    }
} catch (Throwable $e) {
    echo "   ✗ Erreur: " . $e->getMessage() . "\n";
    echo "   Fichier: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo "\n";

// 4. Tester le chargement de phpseclib
echo "4. Test du chargement de phpseclib...\n";
$sftpClassAvailable = false;
try {
    require_once $projectRoot . '/vendor/autoload.php';
    if (class_exists('phpseclib3\Net\SFTP')) {
        echo "   ✓ Classe SFTP disponible\n";
        $sftpClassAvailable = true;
    } else {
        echo "   ✗ Classe SFTP introuvable\n";
    }
} catch (Throwable $e) {
    echo "   ✗ Erreur: " . $e->getMessage() . "\n";
    echo "   Fichier: " . $e->getFile() . ":" . $e->getLine() . "\n";
}
echo "\n";

// 5. Tester la connexion SFTP
echo "5. Test de connexion SFTP...\n";
$sftp_host = getenv('SFTP_HOST') ?: '';
$sftp_user = getenv('SFTP_USER') ?: '';
$sftp_pass = getenv('SFTP_PASS') ?: '';
$sftp_port = (int)(getenv('SFTP_PORT') ?: 22);
$sftp_timeout = (int)(getenv('SFTP_TIMEOUT') ?: 15);

if (empty($sftp_host) || empty($sftp_user) || empty($sftp_pass)) {
    echo "   ✗ Variables SFTP manquantes\n";
} elseif (!$sftpClassAvailable) {
    echo "   ✗ Classe SFTP non disponible (voir étape 4)\n";
} else {
    try {
        echo "   Tentative de connexion à $sftp_host:$sftp_port (timeout: {$sftp_timeout}s)...\n";
        $start = microtime(true);
        $sftp = new \phpseclib3\Net\SFTP($sftp_host, $sftp_port, $sftp_timeout);
        $connectTime = round((microtime(true) - $start) * 1000, 2);
        echo "   ✓ Instance SFTP créée ({$connectTime}ms)\n";
        
        echo "   Tentative de login...\n";
        $start = microtime(true);
        $loginSuccess = $sftp->login($sftp_user, $sftp_pass);
        $loginTime = round((microtime(true) - $start) * 1000, 2);
        
        if ($loginSuccess) {
            echo "   ✓ Login réussi ({$loginTime}ms)\n";
            
            // Tester la liste des fichiers
            echo "   Test de liste des fichiers...\n";
            $start = microtime(true);
            $files = $sftp->nlist('/');
            $listTime = round((microtime(true) - $start) * 1000, 2);
            
            if ($files !== false) {
                echo "   ✓ Liste des fichiers réussie ({$listTime}ms)\n";
                echo "   Nombre de fichiers: " . count($files) . "\n";
                
                // Filtrer les fichiers CSV
                $csvFiles = array_filter($files, function($f) {
                    return preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $f);
                });
                echo "   Fichiers CSV trouvés: " . count($csvFiles) . "\n";
                if (count($csvFiles) > 0) {
                    echo "   Exemples:\n";
                    foreach (array_slice($csvFiles, 0, 5) as $file) {
                        echo "     - $file\n";
                    }
                }
            } else {
                echo "   ✗ Échec de la liste des fichiers\n";
            }
        } else {
            echo "   ✗ Échec du login\n";
        }
    } catch (Throwable $e) {
        echo "   ✗ Erreur: " . $e->getMessage() . "\n";
        echo "   Exception: " . get_class($e) . "\n";
        echo "   Fichier: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo "   Trace:\n" . $e->getTraceAsString() . "\n";
    }
}
echo "\n";

// 6. Tester l'exécution du script
echo "6. Test d'exécution du script upload_compteur.php...\n";
$scriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
if (is_file($scriptPath)) {
    echo "   ✓ Script trouvé: $scriptPath\n";
    echo "   Taille: " . filesize($scriptPath) . " bytes\n";
    echo "   Lisible: " . (is_readable($scriptPath) ? '✓' : '✗') . "\n";
} else {
    echo "   ✗ Script introuvable: $scriptPath\n";
}
echo "\n";

echo "=== FIN DU DIAGNOSTIC ===\n";

