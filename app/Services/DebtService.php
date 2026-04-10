<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Client;
use App\Services\ConsumptionService;
use DateTime;

/**
 * Service pour le calcul des dettes
 * 
 * @package App\Services
 */
class DebtService
{
    private ConsumptionService $consumptionService;
    
    // Configuration des tarifs selon les règles du projet
    // N&B : 0.05€ par copie si > 1000 copies/mois, sinon 0€
    // Couleur : 0.09€ par copie
    private const PRICING = [
        'bw_base' => 0.05,      // Prix par copie N&B si > seuil
        'bw_threshold' => 1000,  // Seuil pour N&B
        'color_base' => 0.09,    // Prix par copie couleur
    ];
    
    public function __construct(ConsumptionService $consumptionService)
    {
        $this->consumptionService = $consumptionService;
    }
    
    /**
     * Calcule la dette pour une consommation donnée selon les règles de tarification
     * 
     * Règles :
     * - N&B : 0.05€ par copie si > 1000 copies/mois, sinon 0€
     * - Couleur : 0.09€ par copie
     * 
     * @param int $bwConsumption Consommation N&B
     * @param int $colorConsumption Consommation couleur
     * @return array ['debt' => float, 'bw_amount' => float, 'color_amount' => float, ...]
     */
    public function calculateDebt(int $bwConsumption, int $colorConsumption): array
    {
        $bwAmount = 0;
        if ($bwConsumption > self::PRICING['bw_threshold']) {
            $bwAmount = $bwConsumption * self::PRICING['bw_base'];
        }
        
        $colorAmount = $colorConsumption * self::PRICING['color_base'];
        
        $totalDebt = $bwAmount + $colorAmount;
        
        return [
            'debt' => round($totalDebt, 2),
            'bw_consumption' => $bwConsumption,
            'color_consumption' => $colorConsumption,
            'bw_amount' => round($bwAmount, 2),
            'color_amount' => round($colorAmount, 2),
            'bw_threshold' => self::PRICING['bw_threshold'],
            'bw_price' => self::PRICING['bw_base'],
            'color_price' => self::PRICING['color_base'],
        ];
    }
    
    /**
     * Calcule la dette pour un client sur une période
     * 
     * @param Client $client
     * @param string $macNorm
     * @param DateTime $periodStart
     * @param DateTime $periodEnd
     * @return array|null ['debt' => float, 'bw_consumption' => int, 'color_consumption' => int, ...]
     */
    public function calculateDebtForPeriod(Client $client, string $macNorm, DateTime $periodStart, DateTime $periodEnd): ?array
    {
        $consumption = $this->consumptionService->calculateConsumptionForPeriod($macNorm, $periodStart, $periodEnd);
        
        if (!$consumption) {
            return null;
        }
        
        $bwConsumption = $consumption['bw'];
        $colorConsumption = $consumption['color'];
        
        $debtData = $this->calculateDebt($bwConsumption, $colorConsumption);
        
        return array_merge($debtData, [
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'start_counter' => $consumption['start_counter'] ?? null,
            'end_counter' => $consumption['end_counter'] ?? null,
        ]);
    }
}

