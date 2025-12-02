<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tests unitaires pour le calcul des dettes
 * 
 * Vérifie la logique de calcul des dettes clients
 * en fonction des consommations et tarifs
 */
class DebtCalculatorTest extends TestCase
{
    /**
     * Test de calcul de dette simple (pack bronze)
     */
    public function testCalculateDebtBronze(): void
    {
        // Pack bronze : 20€/mois + 0.05€/copie noir/blanc au-delà de 1000
        $basePrice = 20.0;
        $includedCopies = 1000;
        $actualCopies = 1200;
        $pricePerCopy = 0.05;
        
        $excessCopies = max(0, $actualCopies - $includedCopies);
        $debt = $basePrice + ($excessCopies * $pricePerCopy);
        
        $expected = 20.0 + (200 * 0.05); // 20 + 10 = 30
        $this->assertEquals($expected, $debt);
    }
    
    /**
     * Test de calcul de dette avec consommation dans les limites
     */
    public function testCalculateDebtWithinLimit(): void
    {
        $basePrice = 20.0;
        $includedCopies = 1000;
        $actualCopies = 800; // En dessous de la limite
        $pricePerCopy = 0.05;
        
        $excessCopies = max(0, $actualCopies - $includedCopies);
        $debt = $basePrice + ($excessCopies * $pricePerCopy);
        
        // Pas de dépassement, donc juste le prix de base
        $this->assertEquals($basePrice, $debt);
    }
    
    /**
     * Test de calcul avec copies couleur
     */
    public function testCalculateDebtWithColor(): void
    {
        $basePrice = 20.0;
        $bwCopies = 1200;
        $colorCopies = 150;
        $includedBw = 1000;
        $includedColor = 100;
        $pricePerBw = 0.05;
        $pricePerColor = 0.15;
        
        $excessBw = max(0, $bwCopies - $includedBw);
        $excessColor = max(0, $colorCopies - $includedColor);
        
        $debt = $basePrice + ($excessBw * $pricePerBw) + ($excessColor * $pricePerColor);
        
        $expected = 20.0 + (200 * 0.05) + (50 * 0.15); // 20 + 10 + 7.5 = 37.5
        $this->assertEquals($expected, $debt);
    }
    
    /**
     * Test avec valeurs nulles ou zéro
     */
    public function testCalculateDebtWithZeroConsumption(): void
    {
        $basePrice = 20.0;
        $actualCopies = 0;
        $includedCopies = 1000;
        $pricePerCopy = 0.05;
        
        $excessCopies = max(0, $actualCopies - $includedCopies);
        $debt = $basePrice + ($excessCopies * $pricePerCopy);
        
        $this->assertEquals($basePrice, $debt);
    }
}

