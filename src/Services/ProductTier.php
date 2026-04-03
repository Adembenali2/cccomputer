<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Packaging futur (standard / pro / premium) : lit product_tier et modules dans parametres_app.
 * Les garde-fous métier appellent canUseFeature() avant d'exécuter une fonctionnalité « premium ».
 */
final class ProductTier
{
    public const TIER_STANDARD = 'standard';
    public const TIER_PRO = 'pro';
    public const TIER_PREMIUM = 'premium';

    public static function currentTier(PDO $pdo): string
    {
        require_once __DIR__ . '/../../includes/parametres.php';
        $t = strtolower(trim(getParametreBrut($pdo, 'product_tier', self::TIER_PRO)));
        if (!in_array($t, [self::TIER_STANDARD, self::TIER_PRO, self::TIER_PREMIUM], true)) {
            return self::TIER_PRO;
        }
        return $t;
    }

    public static function isPremium(PDO $pdo): bool
    {
        return self::currentTier($pdo) === self::TIER_PREMIUM;
    }

    /**
     * @param string $moduleCle ex. module_relances_auto, module_dashboard_business
     */
    public static function isModuleEnabled(PDO $pdo, string $moduleCle): bool
    {
        require_once __DIR__ . '/../../includes/parametres.php';
        return getParametre($pdo, $moduleCle);
    }

    /**
     * Combine tier + flag module (pour désactiver un module en offre standard sans changer le code).
     */
    public static function canUseFeature(PDO $pdo, string $moduleCle): bool
    {
        if (!self::isModuleEnabled($pdo, $moduleCle)) {
            return false;
        }
        $tier = self::currentTier($pdo);
        if ($tier === self::TIER_STANDARD) {
            return !in_array($moduleCle, [
                'module_relances_auto',
                'module_factures_recurrentes',
                'module_dashboard_business',
                'module_opportunites',
            ], true);
        }
        return true;
    }
}
