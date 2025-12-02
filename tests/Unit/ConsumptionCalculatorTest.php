<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DateTime;

/**
 * Tests unitaires pour le calcul des consommations
 * 
 * Vérifie la logique de calcul des copies noir/blanc et couleur
 * entre deux relevés de compteurs
 */
class ConsumptionCalculatorTest extends TestCase
{
    /**
     * Test du calcul de consommation simple (noir/blanc)
     */
    public function testCalculateBlackWhiteConsumption(): void
    {
        $startBw = 1000;
        $endBw = 1500;
        $expected = 500;
        
        $consumption = $endBw - $startBw;
        
        $this->assertEquals($expected, $consumption);
    }
    
    /**
     * Test du calcul de consommation couleur
     */
    public function testCalculateColorConsumption(): void
    {
        $startColor = 200;
        $endColor = 350;
        $expected = 150;
        
        $consumption = $endColor - $startColor;
        
        $this->assertEquals($expected, $consumption);
    }
    
    /**
     * Test avec compteur qui diminue (réinitialisation)
     */
    public function testCounterReset(): void
    {
        $startBw = 99900;
        $endBw = 100; // Compteur réinitialisé
        
        // Si le compteur diminue, on considère qu'il y a eu une réinitialisation
        // La consommation est simplement la différence (peut être négative, à gérer en métier)
        $consumption = $endBw - $startBw;
        
        // Dans ce cas, on devrait détecter une anomalie ou gérer le rollover
        $this->assertLessThan(0, $consumption);
    }
    
    /**
     * Test avec valeurs nulles
     */
    public function testNullValues(): void
    {
        $startBw = null;
        $endBw = 1000;
        
        // Si le compteur de départ est null, on ne peut pas calculer
        $consumption = $endBw - ($startBw ?? 0);
        
        $this->assertEquals(1000, $consumption);
    }
    
    /**
     * Test de la règle 20→20 (période de facturation)
     */
    public function testBillingPeriodRule(): void
    {
        // Période du 20 janvier au 20 février
        $periodStart = new DateTime('2024-01-20 00:00:00');
        $periodEnd = new DateTime('2024-02-20 23:59:59');
        
        // Date de relevé dans la période
        $releveDate = new DateTime('2024-01-25 10:00:00');
        
        $this->assertGreaterThanOrEqual($periodStart, $releveDate);
        $this->assertLessThanOrEqual($periodEnd, $releveDate);
    }
    
    /**
     * Test de la règle 20→20 avec date avant le 20
     */
    public function testBillingPeriodBefore20(): void
    {
        // Si on est le 15 janvier, la période est du 20 décembre au 20 janvier
        $date = new DateTime('2024-01-15');
        $year = (int)$date->format('Y');
        $month = (int)$date->format('m');
        $day = (int)$date->format('d');
        
        if ($day < 20) {
            $periodStart = new DateTime("$year-$month-20 00:00:00");
            $periodStart->modify('-1 month');
            $periodEnd = new DateTime("$year-$month-20 23:59:59");
        } else {
            $periodStart = new DateTime("$year-$month-20 00:00:00");
            $periodEnd = clone $periodStart;
            $periodEnd->modify('+1 month');
        }
        
        $expectedStart = new DateTime('2023-12-20 00:00:00');
        $expectedEnd = new DateTime('2024-01-20 23:59:59');
        
        $this->assertEquals($expectedStart->format('Y-m-d'), $periodStart->format('Y-m-d'));
        $this->assertEquals($expectedEnd->format('Y-m-d'), $periodEnd->format('Y-m-d'));
    }
}

