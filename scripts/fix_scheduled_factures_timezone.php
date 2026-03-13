#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Script de correction des programmations mal stockées (fuseau horaire)
 *
 * Corrige les programmations en_attente dont la date a été stockée en heure Paris
 * au lieu d'UTC (créées avant la correction du fuseau).
 *
 * Heuristique : si date_envoi_programmee > UTC_NOW (cron ne l'a pas prise)
 * ET date_envoi_programmee interprétée comme Paris est <= Paris_NOW (heure passée côté utilisateur),
 * alors on suppose que la valeur a été stockée en Paris sans conversion.
 * On convertit Paris → UTC et on met à jour.
 *
 * Usage:
 *   php scripts/fix_scheduled_factures_timezone.php           # Exécute les corrections
 *   php scripts/fix_scheduled_factures_timezone.php --dry-run # Affiche sans modifier
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

$baseDir = dirname(__DIR__);
chdir($baseDir);

if (file_exists($baseDir . '/.env')) {
    $lines = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
            $k = trim($m[1]);
            $v = trim($m[2], " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

require_once $baseDir . '/includes/helpers.php';

$appTz = new DateTimeZone('Europe/Paris');
$utcTz = new DateTimeZone('UTC');

date_default_timezone_set('UTC');
$utcNow = new DateTime('now', $utcTz);
$utcNowStr = $utcNow->format('Y-m-d H:i:s');

$parisNow = new DateTime('now', $appTz);

echo "=== Correction fuseau programmations factures ===\n";
echo "Mode: " . ($dryRun ? "DRY-RUN (aucune modification)" : "EXÉCUTION") . "\n";
echo "UTC now: {$utcNowStr}\n";
echo "Paris now: " . $parisNow->format('Y-m-d H:i:s') . "\n\n";

try {
    $pdo = getPdo();
    $pdo->exec("SET time_zone = '+00:00'");

    $stmt = $pdo->query("
        SELECT id, date_envoi_programmee, statut, created_at
        FROM factures_envois_programmes
        WHERE statut = 'en_attente'
        ORDER BY date_envoi_programmee ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fixed = 0;
    $skipped = 0;

    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $stored = $r['date_envoi_programmee'];

        if (strcmp($stored, $utcNowStr) <= 0) {
            echo "  #{$id}: date={$stored} → déjà éligible (cron devrait l'avoir prise), ignoré\n";
            $skipped++;
            continue;
        }

        $dtStoredAsParis = DateTime::createFromFormat('Y-m-d H:i:s', $stored, $appTz);
        if (!$dtStoredAsParis) {
            echo "  #{$id}: date={$stored} → format invalide, ignoré\n";
            $skipped++;
            continue;
        }

        if ($dtStoredAsParis > $parisNow) {
            echo "  #{$id}: date={$stored} (interprété Paris) → heure future, ignoré\n";
            $skipped++;
            continue;
        }

        $dtStoredAsParis->setTimezone($utcTz);
        $correctedUtc = $dtStoredAsParis->format('Y-m-d H:i:s');

        if ($correctedUtc === $stored) {
            echo "  #{$id}: date={$stored} → déjà en UTC correct, ignoré\n";
            $skipped++;
            continue;
        }

        echo "  #{$id}: {$stored} (Paris stocké par erreur) → {$correctedUtc} (UTC)\n";

        if (!$dryRun) {
            $upd = $pdo->prepare("UPDATE factures_envois_programmes SET date_envoi_programmee = :date WHERE id = :id");
            $upd->execute([':date' => $correctedUtc, ':id' => $id]);
            $fixed++;
        } else {
            $fixed++;
        }
    }

    echo "\n=== Résumé ===\n";
    echo "Programmations en_attente analysées: " . count($rows) . "\n";
    echo "Corrigées: {$fixed}\n";
    echo "Ignorées: {$skipped}\n";
    if ($dryRun && $fixed > 0) {
        echo "\nRelancez sans --dry-run pour appliquer les corrections.\n";
    }

} catch (Throwable $e) {
    echo "ERREUR: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
