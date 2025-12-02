<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use InvalidArgumentException;

require_once __DIR__ . '/../../includes/Validator.php';

/**
 * Tests unitaires pour la classe Validator
 */
class ValidatorTest extends TestCase
{
    /**
     * Test de validation d'email valide
     */
    public function testValidEmail(): void
    {
        $email = 'test@example.com';
        $result = Validator::email($email);
        
        $this->assertEquals('test@example.com', $result);
    }
    
    /**
     * Test de validation d'email invalide
     */
    public function testInvalidEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email invalide');
        
        Validator::email('invalid-email');
    }
    
    /**
     * Test de validation d'email vide
     */
    public function testEmptyEmail(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Email vide');
        
        Validator::email('');
    }
    
    /**
     * Test de normalisation email Gmail
     */
    public function testGmailNormalization(): void
    {
        $email = 'test.email@gmail.com';
        $result = Validator::email($email);
        
        // Les points avant @gmail.com doivent être supprimés
        $this->assertEquals('testemail@gmail.com', $result);
    }
    
    /**
     * Test de validation téléphone français
     */
    public function testValidPhone(): void
    {
        $phone1 = '0612345678';
        $phone2 = '06 12 34 56 78';
        $phone3 = '+33612345678';
        
        $this->assertTrue(Validator::phone($phone1));
        $this->assertTrue(Validator::phone($phone2));
        $this->assertTrue(Validator::phone($phone3));
    }
    
    /**
     * Test de validation téléphone invalide
     */
    public function testInvalidPhone(): void
    {
        $this->assertFalse(Validator::phone('123'));
        $this->assertFalse(Validator::phone(null));
        $this->assertFalse(Validator::phone(''));
    }
    
    /**
     * Test de validation SIRET
     */
    public function testValidSiret(): void
    {
        // SIRET valide (14 chiffres)
        $siret = '12345678901234';
        
        $this->assertTrue(Validator::siret($siret));
    }
    
    /**
     * Test de validation SIRET invalide
     */
    public function testInvalidSiret(): void
    {
        $this->assertFalse(Validator::siret('123'));
        $this->assertFalse(Validator::siret('123456789012345')); // Trop long
    }
}

