<?php
/**
 * GET — Alertes tableau de bord (factures en retard, SAV urgents, livraisons du jour, paiements anciens en attente)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

$alerts = [];

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut = 'en_retard'");
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $alerts[] = [
            'type' => 'danger',
            'icon' => '⚠️',
            'message' => $count . ' facture' . ($count > 1 ? 's' : '') . ' en retard',
            'count' => $count,
            'link' => '/public/view_facture.php',
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard_get_alerts factures: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM sav WHERE priorite = 'urgente' AND statut = 'ouvert' AND id_technicien IS NULL"
    );
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => '🔧',
            'message' => $count . ' SAV urgent' . ($count > 1 ? 's' : '') . ' non assigné' . ($count > 1 ? 's' : ''),
            'count' => $count,
            'link' => '/public/sav.php',
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard_get_alerts sav: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM livraisons WHERE DATE(date_prevue) = CURDATE() AND statut IN ('planifiee','en_cours')"
    );
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $alerts[] = [
            'type' => 'info',
            'icon' => '📦',
            'message' => $count . ' livraison' . ($count > 1 ? 's' : '') . ' prévue' . ($count > 1 ? 's' : '') . " aujourd'hui",
            'count' => $count,
            'link' => '/public/livraison.php',
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard_get_alerts livraisons: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM paiements WHERE statut = 'en_cours' AND date_paiement < DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $count = (int) $stmt->fetchColumn();
    if ($count > 0) {
        $alerts[] = [
            'type' => 'warning',
            'icon' => '💰',
            'message' => $count . ' paiement' . ($count > 1 ? 's' : '') . ' en attente depuis plus de 30 jours',
            'count' => $count,
            'link' => '/public/paiements.php',
        ];
    }
} catch (Throwable $e) {
    error_log('dashboard_get_alerts paiements: ' . $e->getMessage());
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(
    [
        'success' => true,
        'alerts' => $alerts,
    ],
    JSON_UNESCAPED_UNICODE
);
