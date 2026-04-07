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
