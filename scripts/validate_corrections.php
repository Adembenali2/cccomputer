<?php
/**
 * Script de validation des corrections appliquées lors de l'audit
 * 
 * Ce script vérifie que toutes les corrections critiques fonctionnent correctement
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db_connection.php';

// Configuration d'erreurs pour le script de test
configureErrorReporting(true);

echo "=== VALIDATION DES CORRECTIONS ===\n\n";

$errors = [];
$warnings = [];
$success = [];

// ====================================================================
// TEST 1 : Variable $user_id dans dashboard.php
// ====================================================================
echo "TEST 1 : Variable \$user_id dans dashboard.php\n";
try {
    // Simuler le contexte de dashboard.php
    require_once __DIR__ . '/../includes/auth.php';
    
    // Vérifier que currentUserId() fonctionne
    $user_id = currentUserId() ?? 0;
    if ($user_id > 0) {
        $success[] = "✓ Variable \$user_id correctement initialisée via currentUserId()";
        echo "  ✓ Variable \$user_id = $user_id\n";
    } else {
        $warnings[] = "⚠ Variable \$user_id = 0 (utilisateur non connecté, normal si exécuté en CLI)";
        echo "  ⚠ Variable \$user_id = 0 (normal si exécuté en CLI)\n";
    }
} catch (Throwable $e) {
    $errors[] = "✗ Erreur lors du test de \$user_id : " . $e->getMessage();
    echo "  ✗ Erreur : " . $e->getMessage() . "\n";
}

// ====================================================================
// TEST 2 : Requêtes SQL préparées (GET_LOCK/RELEASE_LOCK)
// Note: Les scripts d'import ont été supprimés
// ====================================================================
echo "\nTEST 2 : Requêtes SQL préparées (GET_LOCK/RELEASE_LOCK)\n";
try {
    $pdo = getPdo();
    $lockName = 'test_validation_lock';
    
    // Tester GET_LOCK avec prepare()
    $stmtLock = $pdo->prepare("SELECT GET_LOCK(:lock_name, 0) as lock_result");
    $stmtLock->execute([':lock_name' => $lockName]);
    $lockResult = $stmtLock->fetch(PDO::FETCH_ASSOC);
    $lockAcquired = (int)($lockResult['lock_result'] ?? 0) === 1;
    
    if ($lockAcquired) {
        $success[] = "✓ GET_LOCK fonctionne avec prepare()";
        echo "  ✓ GET_LOCK fonctionne avec prepare()\n";
        
        // Libérer le verrou
        $stmtRelease = $pdo->prepare("SELECT RELEASE_LOCK(:lock_name)");
        $stmtRelease->execute([':lock_name' => $lockName]);
        $success[] = "✓ RELEASE_LOCK fonctionne avec prepare()";
        echo "  ✓ RELEASE_LOCK fonctionne avec prepare()\n";
    } else {
        $warnings[] = "⚠ Verrou non acquis (peut être normal si déjà verrouillé)";
        echo "  ⚠ Verrou non acquis (peut être normal)\n";
    }
} catch (Throwable $e) {
    $errors[] = "✗ Erreur lors du test des verrous : " . $e->getMessage();
    echo "  ✗ Erreur : " . $e->getMessage() . "\n";
}

// ====================================================================
// TEST 3 : Requête SQL préparée dans api_helpers.php
// ====================================================================
echo "\nTEST 3 : Requête SQL préparée dans api_helpers.php\n";
try {
    $pdo = getPdo();
    
    // Tester la requête SELECT 1 avec prepare()
    $stmt = $pdo->prepare('SELECT 1');
    $stmt->execute();
    $result = $stmt->fetchColumn();
    
    if ($result === '1' || $result === 1) {
        $success[] = "✓ Requête SELECT 1 fonctionne avec prepare()";
        echo "  ✓ Requête SELECT 1 fonctionne avec prepare()\n";
    } else {
        $errors[] = "✗ Résultat inattendu : " . var_export($result, true);
        echo "  ✗ Résultat inattendu : " . var_export($result, true) . "\n";
    }
} catch (Throwable $e) {
    $errors[] = "✗ Erreur lors du test SELECT 1 : " . $e->getMessage();
    echo "  ✗ Erreur : " . $e->getMessage() . "\n";
}

// ====================================================================
// TEST 4 : Connexion PDO via getPdo()
// ====================================================================
echo "\nTEST 4 : Connexion PDO via getPdo()\n";
try {
    $pdo = getPdo();
    
    if ($pdo instanceof PDO) {
        $success[] = "✓ getPdo() retourne une instance PDO valide";
        echo "  ✓ getPdo() retourne une instance PDO valide\n";
        
        // Tester une requête simple
        $stmt = $pdo->prepare("SELECT DATABASE() as db_name");
        $stmt->execute();
        $dbName = $stmt->fetchColumn();
        echo "  ✓ Base de données : " . ($dbName ?: 'N/A') . "\n";
    } else {
        $errors[] = "✗ getPdo() ne retourne pas une instance PDO";
        echo "  ✗ getPdo() ne retourne pas une instance PDO\n";
    }
} catch (Throwable $e) {
    $errors[] = "✗ Erreur lors du test getPdo() : " . $e->getMessage();
    echo "  ✗ Erreur : " . $e->getMessage() . "\n";
}

// ====================================================================
// TEST 5 : Vérifier que les fichiers modifiés existent
// ====================================================================
echo "\nTEST 5 : Vérification des fichiers modifiés\n";
$filesToCheck = [
    'public/dashboard.php',
    'includes/api_helpers.php',
];

foreach ($filesToCheck as $file) {
    $path = __DIR__ . '/../' . $file;
    if (file_exists($path)) {
        $success[] = "✓ Fichier existe : $file";
        echo "  ✓ $file\n";
    } else {
        $errors[] = "✗ Fichier manquant : $file";
        echo "  ✗ Fichier manquant : $file\n";
    }
}

// ====================================================================
// TEST 6 : Vérifier que les corrections sont présentes dans le code
// ====================================================================
echo "\nTEST 6 : Vérification des corrections dans le code\n";

// Vérifier que dashboard.php utilise currentUserId()
$dashboardContent = file_get_contents(__DIR__ . '/../public/dashboard.php');
if (strpos($dashboardContent, 'currentUserId()') !== false) {
    $success[] = "✓ dashboard.php utilise currentUserId()";
    echo "  ✓ dashboard.php utilise currentUserId()\n";
} else {
    $errors[] = "✗ dashboard.php n'utilise pas currentUserId()";
    echo "  ✗ dashboard.php n'utilise pas currentUserId()\n";
}

// Note: run_import_if_due.php et les scripts d'import ont été supprimés

// Vérifier que api_helpers.php utilise prepare() pour SELECT 1
$apiHelpersContent = file_get_contents(__DIR__ . '/../includes/api_helpers.php');
if (strpos($apiHelpersContent, '$stmt = $pdo->prepare(\'SELECT 1\')') !== false) {
    $success[] = "✓ api_helpers.php utilise prepare() pour SELECT 1";
    echo "  ✓ api_helpers.php utilise prepare() pour SELECT 1\n";
} else {
    $errors[] = "✗ api_helpers.php n'utilise pas prepare() pour SELECT 1";
    echo "  ✗ api_helpers.php n'utilise pas prepare() pour SELECT 1\n";
}

// Note: upload_compteur.php et les fonctionnalités SFTP ont été supprimées

// ====================================================================
// RÉSUMÉ
// ====================================================================
echo "\n=== RÉSUMÉ ===\n";
echo "✓ Succès : " . count($success) . "\n";
echo "⚠ Avertissements : " . count($warnings) . "\n";
echo "✗ Erreurs : " . count($errors) . "\n\n";

if (count($errors) > 0) {
    echo "ERREURS DÉTECTÉES :\n";
    foreach ($errors as $error) {
        echo "  $error\n";
    }
    echo "\n";
}

if (count($warnings) > 0) {
    echo "AVERTISSEMENTS :\n";
    foreach ($warnings as $warning) {
        echo "  $warning\n";
    }
    echo "\n";
}

if (count($errors) === 0) {
    echo "✅ TOUS LES TESTS SONT PASSÉS AVEC SUCCÈS !\n";
    exit(0);
} else {
    echo "❌ CERTAINS TESTS ONT ÉCHOUÉ\n";
    exit(1);
}

