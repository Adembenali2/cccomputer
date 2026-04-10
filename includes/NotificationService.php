<?php
/**
 * Notifications globales (hors chatroom).
 */
declare(strict_types=1);

final class NotificationService
{
    private const TYPES = [
        'sav_assigne',
        'livraison_planifiee',
        'facture_impayee',
        'paiement_recu',
        'sav_urgent',
    ];

    private const TYPE_LIENS = ['sav', 'livraison', 'facture', 'paiement'];

    public static function create(
        int $userId,
        string $type,
        string $titre,
        string $message,
        ?int $idLien = null,
        ?string $typeLien = null
    ): void {
        if ($userId <= 0 || !in_array($type, self::TYPES, true)) {
            return;
        }
        if ($typeLien !== null && $typeLien !== '' && !in_array($typeLien, self::TYPE_LIENS, true)) {
            $typeLien = null;
        }
        if ($typeLien === '') {
            $typeLien = null;
        }

        try {
            if (!function_exists('getPdo')) {
                require_once __DIR__ . '/helpers.php';
            }
            $pdo = getPdo();
            $stmt = $pdo->prepare(
                'INSERT INTO notifications (id_user, type, titre, message, id_lien, type_lien, lu)
                 VALUES (:id_user, :type, :titre, :message, :id_lien, :type_lien, 0)'
            );
            $stmt->execute([
                ':id_user' => $userId,
                ':type' => $type,
                ':titre' => mb_substr($titre, 0, 150),
                ':message' => $message === '' ? null : $message,
                ':id_lien' => $idLien,
                ':type_lien' => $typeLien,
            ]);
        } catch (Throwable $e) {
            error_log('NotificationService::create: ' . $e->getMessage());
        }
    }

    public static function getUnread(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }
        try {
            if (!function_exists('getPdo')) {
                require_once __DIR__ . '/helpers.php';
            }
            $pdo = getPdo();
            $stmt = $pdo->prepare(
                'SELECT id, type, titre, message, id_lien, type_lien, lu, date_creation
                 FROM notifications
                 WHERE id_user = :uid AND lu = 0
                 ORDER BY date_creation DESC
                 LIMIT 20'
            );
            $stmt->execute([':uid' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('NotificationService::getUnread: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Transforme une ligne SQL notifications en payload API (type court, url, message affichable).
     *
     * @param array<string, mixed> $row
     * @return array{id:int,message:string,type:string,url:string,created_at:string}
     */
    public static function toApiItem(array $row): array
    {
        $dbType = strtolower((string) ($row['type'] ?? ''));
        $typeLien = isset($row['type_lien']) && $row['type_lien'] !== '' ? (string) $row['type_lien'] : null;
        $idLien = isset($row['id_lien']) ? (int) $row['id_lien'] : 0;
        $titre = trim((string) ($row['titre'] ?? ''));
        $msg = trim((string) ($row['message'] ?? ''));
        $message = $titre !== '' && $msg !== ''
            ? $titre . ' — ' . $msg
            : ($titre !== '' ? $titre : $msg);

        $category = self::mapToCategory($dbType, $typeLien, $titre, $msg);
        $url = self::buildNotificationUrl($category, $idLien > 0 ? $idLien : null, $typeLien);

        return [
            'id' => (int) $row['id'],
            'message' => $message,
            'type' => $category,
            'url' => $url,
            'created_at' => (string) ($row['date_creation'] ?? ''),
        ];
    }

    private static function mapToCategory(string $dbType, ?string $typeLien, string $titre, string $msg): string
    {
        $blob = strtolower($dbType . ' ' . $titre . ' ' . $msg);
        if (str_contains($blob, 'sav')) {
            return 'sav';
        }
        if (str_contains($blob, 'facture')) {
            return 'facture';
        }
        if (str_contains($blob, 'livraison')) {
            return 'livraison';
        }
        if (str_contains($blob, 'stock')) {
            return 'stock';
        }
        if (str_contains($blob, 'paiement')) {
            return 'paiement';
        }

        return match ($dbType) {
            'sav_assigne', 'sav_urgent' => 'sav',
            'livraison_planifiee' => 'livraison',
            'facture_impayee' => 'facture',
            'paiement_recu' => 'paiement',
            default => 'info',
        };
    }

    private static function buildNotificationUrl(string $category, ?int $idLien, ?string $typeLien): string
    {
        $base = match ($category) {
            'sav' => '/public/sav.php',
            'facture' => '/public/historique.php',
            'livraison' => '/public/livraisons.php',
            'stock' => '/public/stock.php',
            'paiement' => '/public/historique.php',
            default => '/public/dashboard.php',
        };

        if ($idLien === null || $idLien <= 0) {
            return $base;
        }

        if ($category === 'sav' || $typeLien === 'sav') {
            return '/public/sav.php?sav_id=' . $idLien;
        }
        if ($category === 'livraison' || $typeLien === 'livraison') {
            return '/public/livraisons.php?livraison_id=' . $idLien;
        }

        return $base;
    }

    public static function markRead(int $notifId, int $userId): void
    {
        if ($notifId <= 0 || $userId <= 0) {
            return;
        }
        try {
            if (!function_exists('getPdo')) {
                require_once __DIR__ . '/helpers.php';
            }
            $pdo = getPdo();
            $stmt = $pdo->prepare(
                'UPDATE notifications SET lu = 1 WHERE id = :id AND id_user = :uid'
            );
            $stmt->execute([':id' => $notifId, ':uid' => $userId]);
        } catch (Throwable $e) {
            error_log('NotificationService::markRead: ' . $e->getMessage());
        }
    }

    public static function markAllRead(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        try {
            if (!function_exists('getPdo')) {
                require_once __DIR__ . '/helpers.php';
            }
            $pdo = getPdo();
            $stmt = $pdo->prepare('UPDATE notifications SET lu = 1 WHERE id_user = :uid AND lu = 0');
            $stmt->execute([':uid' => $userId]);
        } catch (Throwable $e) {
            error_log('NotificationService::markAllRead: ' . $e->getMessage());
        }
    }
}
