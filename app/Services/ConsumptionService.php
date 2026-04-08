<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use App\Models\Releve;
use App\Repositories\CompteurRepository;

/**
 * Service pour le calcul des consommations
 * 
 * @package App\Services
 */
class ConsumptionService
{
    private CompteurRepository $compteurRepository;
    
    public function __construct(CompteurRepository $compteurRepository)
    {
        $this->compteurRepository = $compteurRepository;
    }
    
    /**
     * Calcule la consommation pour une période donnée
     * 
     * @param string $macNorm
     * @param DateTime $periodStart
     * @param DateTime $periodEnd
     * @return array ['bw' => int, 'color' => int] ou null si impossible
     */
    public function calculateConsumptionForPeriod(string $macNorm, DateTime $periodStart, DateTime $periodEnd): ?array
    {
        $startCounter = $this->compteurRepository->findPeriodStartCounter($macNorm, $periodStart);
        $endCounter = $this->compteurRepository->findPeriodEndCounter($macNorm, $periodEnd);
        
        if (!$startCounter || !$endCounter) {
            return null;
        }
        
        $bwConsumption = Releve::calculateBwConsumption($startCounter, $endCounter);
        $colorConsumption = Releve::calculateColorConsumption($startCounter, $endCounter);
        
        return [
            'bw' => $bwConsumption,
            'color' => $colorConsumption,
            'start_counter' => $startCounter,
            'end_counter' => $endCounter,
        ];
    }
    
    /**
     * Calcule la consommation entre deux relevés spécifiques
     * 
     * @param Releve $start
     * @param Releve $end
     * @return array ['bw' => int, 'color' => int]
     */
    public function calculateConsumptionBetweenReleves(Releve $start, Releve $end): array
    {
        return [
            'bw' => Releve::calculateBwConsumption($start, $end),
            'color' => Releve::calculateColorConsumption($start, $end),
        ];
    }
}

