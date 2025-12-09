<?php
declare(strict_types=1);

// ✅ Affiche toutes les erreurs PHP et PDO dans Railway
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * upload_compteur.php (version avec logs détaillés et gestion d'erreurs)
 * - Connexion SFTP avec timeout
 * - Import CSV compteur_relevee
 * - Log dans import_run
 * - Gestion complète des erreurs et timeouts
 */

// ---------- Timeout global du script ----------
// Maximum 50 secondes pour éviter les blocages (laisser 10s de marge avant le timeout du parent)
set_time_limit(50);
$scriptStartTime = time();
$SCRIPT_TIMEOUT = 50;

// ---------- 0) Normaliser les variables d'env pour db.php ----------
(function (): void {
    $needs = !getenv('MYSQLHOST') || !getenv('MYSQLDATABASE') || !getenv('MYSQLUSER');
    if (!$needs) return;

    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL') ?: '';
    if (!$url) return;

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) return;

    putenv("MYSQLHOST={$p['host']}");
    putenv("MYSQLPORT=" . ($p['port'] ?? '3306'));
    putenv("MYSQLUSER=" . urldecode($p['user']));
    putenv("MYSQLPASSWORD=" . (isset($p['pass']) ? urldecode($p['pass']) : ''));
    putenv("MYSQLDATABASE=" . ltrim($p['path'], '/'));
})();

// ---------- 1) Charger $pdo depuis includes/db.php ----------
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../includes/db.php',
];
$ok = false;
$pdo = null;
foreach ($paths as $p) {
    if (is_file($p)) {
        require_once $p;
        $ok = true;
        break;
    }
}
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    $errorMsg = "Impossible de charger includes/db.php et obtenir \$pdo";
    echo "❌ Erreur: $errorMsg\n";
    exit(1);
}

echo "✅ Connexion à la base établie.\n";

// ---------- 2) Connexion SFTP avec timeout et gestion d'erreurs ----------
require __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SFTP;

// Fonction pour vérifier le timeout
function checkTimeout(int $startTime, int $maxSeconds): void {
    if ((time() - $startTime) > $maxSeconds) {
        throw new RuntimeException("TIMEOUT: Le script a dépassé la limite de {$maxSeconds} secondes");
    }
}

// Fonction pour logger les erreurs dans la base
function logErrorToDB(?PDO $pdo, string $errorMsg): void {
    if (!$pdo) return;
    try {
        $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), 0, 0, 0, :msg)
        ")->execute([
            ':msg' => json_encode([
                'source' => 'SFTP',
                'error' => $errorMsg,
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]);
    } catch (Throwable $e) {
        // Si on ne peut pas logger, on ignore (pour éviter les boucles)
    }
}

$sftp = null;

try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    
    // Utiliser uniquement les variables d'environnement pour la sécurité
    $sftp_host = getenv('SFTP_HOST') ?: '';
    $sftp_user = getenv('SFTP_USER') ?: '';
    $sftp_pass = getenv('SFTP_PASS') ?: '';
    $sftp_port = (int)(getenv('SFTP_PORT') ?: 22);
    $sftp_timeout = (int)(getenv('SFTP_TIMEOUT') ?: 15); // Timeout de connexion SFTP

    if (empty($sftp_host) || empty($sftp_user) || empty($sftp_pass)) {
        $errorMsg = "Variables d'environnement SFTP manquantes (SFTP_HOST, SFTP_USER, SFTP_PASS)";
        echo "❌ Erreur: $errorMsg\n";
        logErrorToDB($pdo ?? null, $errorMsg);
        exit(1);
    }

    echo "🔌 Tentative de connexion SFTP à $sftp_host:$sftp_port (timeout: {$sftp_timeout}s)...\n";
    
    // Créer la connexion SFTP avec timeout explicite
    $sftp = new SFTP($sftp_host, $sftp_port, $sftp_timeout);
    
    // Tentative de login avec gestion d'erreur
    $loginSuccess = false;
    try {
        $loginSuccess = $sftp->login($sftp_user, $sftp_pass);
    } catch (Throwable $e) {
        $errorMsg = "Erreur lors de la connexion SFTP: " . $e->getMessage();
        echo "❌ $errorMsg\n";
        logErrorToDB($pdo ?? null, $errorMsg);
        exit(1);
    }
    
    if (!$loginSuccess) {
        $errorMsg = "Échec de l'authentification SFTP (vérifiez SFTP_USER et SFTP_PASS)";
        echo "❌ Erreur: $errorMsg\n";
        logErrorToDB($pdo ?? null, $errorMsg);
        exit(1);
    }

    echo "✅ Connexion SFTP établie.\n";
    
} catch (Throwable $e) {
    $errorMsg = "Erreur fatale lors de la connexion SFTP: " . $e->getMessage();
    echo "❌ $errorMsg\n";
    logErrorToDB($pdo ?? null, $errorMsg);
    exit(1);
}

// ---------- Création dossiers SFTP avec gestion d'erreurs ----------
try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    @$sftp->mkdir('/processed');
    @$sftp->mkdir('/errors');
} catch (Throwable $e) {
    echo "⚠️ Avertissement: Impossible de créer les dossiers SFTP: " . $e->getMessage() . "\n";
    // On continue quand même, les dossiers peuvent déjà exister
}

function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/' . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.' . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// ---------- 3) Utilitaires ----------
function parse_csv_kv(string $filepath): array {
    $data = [];
    if (($h = fopen($filepath, 'r')) !== false) {
        while (($row = fgetcsv($h, 2000, ',')) !== false) {
            if (isset($row[0], $row[1])) {
                $data[trim($row[0])] = trim((string)$row[1]);
            }
        }
        fclose($h);
    }
    return $data;
}

$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "🚀 Traitement des fichiers CSV...\n";

// ---------- 4) Requêtes PDO ----------
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
$st_compteur   = $pdo->prepare($sql_compteur);

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_run (
            id INT NOT NULL AUTO_INCREMENT,
            ran_at DATETIME NOT NULL,
            imported INT NOT NULL,
            skipped INT NOT NULL,
            ok TINYINT(1) NOT NULL,
            msg TEXT,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    echo "⚠️ [IMPORT_RUN] Erreur CREATE TABLE: " . $e->getMessage() . "\n";
}

// ---------- 4.5) Limite de fichiers ----------
// Maximum 20 fichiers CSV par exécution (configurable via SFTP_BATCH_LIMIT)
$MAX_FILES = (int)(getenv('SFTP_BATCH_LIMIT') ?: 20);
if ($MAX_FILES <= 0) $MAX_FILES = 20;
if ($MAX_FILES > 20) $MAX_FILES = 20; // Limite absolue de 20 fichiers

$files_processed = 0;
$compteurs_inserted = 0;
$files_error = 0;
$files_list = []; // Liste des fichiers traités pour le log

// ---------- 5) Parcours fichiers avec timeout et gestion d'erreurs ----------
try {
    checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
    
    echo "📂 Liste des fichiers sur le serveur SFTP...\n";
    $files = $sftp->nlist('/');
    
    if ($files === false) {
        $errorMsg = "Impossible de lister les fichiers du dossier racine SFTP";
        echo "❌ Erreur: $errorMsg\n";
        logErrorToDB($pdo ?? null, $errorMsg);
        exit(1);
    }
    
    echo "✅ " . count($files) . " entrées trouvées dans le dossier racine\n";
    
} catch (Throwable $e) {
    $errorMsg = "Erreur lors de la liste des fichiers SFTP: " . $e->getMessage();
    echo "❌ $errorMsg\n";
    logErrorToDB($pdo ?? null, $errorMsg);
    exit(1);
}

if (is_array($files) && count($files) > 0) {
    // Filtrer et trier les fichiers CSV valides
    $csvFiles = [];
    foreach ($files as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) continue;
        $csvFiles[] = $entry;
    }
    
    // Trier par nom (pour traiter dans l'ordre chronologique si possible)
    sort($csvFiles);
    
    // Limiter à MAX_FILES
    if (count($csvFiles) > $MAX_FILES) {
        $csvFiles = array_slice($csvFiles, 0, $MAX_FILES);
        echo "ℹ️ Limitation à $MAX_FILES fichiers CSV (limite maximale)\n";
    }
    
    $found = false;
    foreach ($csvFiles as $entry) {
        try {
            checkTimeout($scriptStartTime, $SCRIPT_TIMEOUT);
            
            $found = true;
            $files_processed++;
            $remote = '/' . $entry;
            
            echo "📥 Téléchargement de $entry...\n";
            $tmp = tempnam(sys_get_temp_dir(), 'csv_');
            
            // Tentative de téléchargement avec gestion d'erreur
            $downloadSuccess = false;
            try {
                $downloadSuccess = $sftp->get($remote, $tmp);
            } catch (Throwable $e) {
                echo "❌ Exception lors du téléchargement de $entry: " . $e->getMessage() . "\n";
                $downloadSuccess = false;
            }
            
            if (!$downloadSuccess) {
                echo "❌ Erreur téléchargement $entry\n";
                try {
                    sftp_safe_move($sftp, $remote, '/errors');
                } catch (Throwable $e) {
                    echo "⚠️ Impossible de déplacer $entry vers /errors: " . $e->getMessage() . "\n";
                }
                @unlink($tmp);
                $files_error++;
                continue;
            }
            
            echo "✅ Téléchargement réussi: $entry\n";
            
        } catch (RuntimeException $e) {
            // Timeout - arrêter le traitement
            echo "⏱️ TIMEOUT: Arrêt du traitement des fichiers\n";
            break;
        } catch (Throwable $e) {
            echo "❌ Erreur lors du traitement de $entry: " . $e->getMessage() . "\n";
            $files_error++;
            continue;
        }

        $row = parse_csv_kv($tmp);
        @unlink($tmp);

        $values = [];
        foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;

        if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
            echo "⚠️ Données manquantes (MacAddress/Timestamp) pour $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        try {
            $pdo->beginTransaction();
            $binds = [];
            foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
            $st_compteur->execute($binds);

            if ($st_compteur->rowCount() === 1) {
                $compteurs_inserted++;
                echo "✅ Compteur INSÉRÉ pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            } else {
                echo "ℹ️ Déjà présent: compteur NON réinséré pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            }

            $pdo->commit();

            // Ajouter à la liste des fichiers traités (même si le déplacement échoue)
            $files_list[] = $entry;
            
            try {
                [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
                if (!$okMove) {
                    echo "⚠️ Impossible de déplacer $entry vers /processed\n";
                } else {
                    echo "📦 Archivé: $entry → /processed\n";
                }
            } catch (Throwable $e) {
                echo "⚠️ Erreur lors du déplacement de $entry: " . $e->getMessage() . "\n";
                // On continue quand même, le fichier est déjà traité
            }

        } catch (RuntimeException $e) {
            // Timeout - arrêter le traitement
            if (isset($pdo) && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackErr) {
                    // Ignorer les erreurs de rollback
                }
            }
            echo "⏱️ TIMEOUT: Arrêt du traitement (fichier: $entry)\n";
            break;
        } catch (Throwable $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                } catch (Throwable $rollbackErr) {
                    // Ignorer les erreurs de rollback
                }
            }
            echo "❌ [ERREUR PDO] " . $e->getMessage() . "\n";
            try {
                sftp_safe_move($sftp, $remote, '/errors');
            } catch (Throwable $moveErr) {
                echo "⚠️ Impossible de déplacer $entry vers /errors: " . $moveErr->getMessage() . "\n";
            }
            $files_error++;
        }
    }

    if (!$found) {
        echo "⚠️ Aucun fichier CSV trouvé sur le SFTP.\n";
    }
}

// ---------- 6) Journal du run ----------
try {
    // Créer un message JSON structuré pour différencier les sources
    $summaryData = [
        'source' => 'SFTP',
        'files_processed' => $files_processed,
        'files_error' => $files_error,
        'files_success' => max(0, $files_processed - $files_error),
        'compteurs_inserted' => $compteurs_inserted,
        'max_files_limit' => $MAX_FILES,
        'files' => array_slice($files_list, 0, 20) // Limiter à 20 pour éviter un JSON trop gros
    ];
    
    $summary = json_encode($summaryData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    $stmt->execute([
        ':imported' => $compteurs_inserted, // Nombre de compteurs réellement insérés
        ':skipped'  => $files_error,
        ':ok'       => ($files_error === 0 && $files_processed > 0 ? 1 : ($files_processed === 0 ? 1 : 0)),
        ':msg'      => $summary,
    ]);
    echo "📝 [IMPORT_RUN] Ligne insérée: $files_processed fichiers traités, $compteurs_inserted compteurs insérés\n";
} catch (Throwable $e) {
    echo "❌ [IMPORT_RUN] Erreur INSERT: " . $e->getMessage() . "\n";
}

// ---------- Gestion d'erreur finale ----------
// S'assurer qu'une erreur est toujours loggée en cas d'échec
if ($files_error > 0 && $compteurs_inserted === 0 && $files_processed > 0) {
    try {
        $errorSummary = json_encode([
            'source' => 'SFTP',
            'error' => "Tous les fichiers ont échoué ($files_error erreur(s) sur $files_processed fichier(s))",
            'files_processed' => $files_processed,
            'files_error' => $files_error,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), 0, :skipped, 0, :msg)
        ")->execute([
            ':skipped' => $files_error,
            ':msg' => $errorSummary
        ]);
    } catch (Throwable $e) {
        // Ignorer les erreurs de log final
    }
}

echo "-----------------------------\n";
$duration = time() - $scriptStartTime;
echo "✅ Traitement terminé en {$duration} seconde(s).\n";
