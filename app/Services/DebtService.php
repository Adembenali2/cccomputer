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
    
    // Configuration des tarifs (peut être externalisée dans un fichier de config)
    private const PRICING = [
        'packbronze' => [
            'base_price' => 20.0,
            'included_bw' => 1000,
            'included_color' => 100,
            'price_per_bw' => 0.05,
            'price_per_color' => 0.15,
        ],
        'packargent' => [
            'base_price' => 35.0,
            'included_bw' => 2000,
            'included_color' => 200,
            'price_per_bw' => 0.04,
            'price_per_color' => 0.12,
        ],
    ];
    
    public function __construct(ConsumptionService $consumptionService)
    {
        $this->consumptionService = $consumptionService;
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
        
        $pricing = self::PRICING[$client->offre] ?? self::PRICING['packbronze'];
        
        $bwConsumption = $consumption['bw'];
        $colorConsumption = $consumption['color'];
        
        // Calculer les copies en excès
        $excessBw = max(0, $bwConsumption - $pricing['included_bw']);
        $excessColor = max(0, $colorConsumption - $pricing['included_color']);
        
        // Calculer la dette
        $debt = $pricing['base_price'] 
            + ($excessBw * $pricing['price_per_bw'])
            + ($excessColor * $pricing['price_per_color']);
        
        return [
            'debt' => round($debt, 2),
            'bw_consumption' => $bwConsumption,
            'color_consumption' => $colorConsumption,
            'excess_bw' => $excessBw,
            'excess_color' => $excessColor,
            'base_price' => $pricing['base_price'],
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];
    }
}

