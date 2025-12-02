<?php
declare(strict_types=1);

/**
 * Classe centralisée pour la validation des données
 * Remplace les validations dupliquées dans plusieurs fichiers
 */
class Validator
{
    /**
     * Valide et normalise un email
     * 
     * @param string $email Email à valider
     * @return string Email validé et normalisé (minuscules)
     * @throws InvalidArgumentException Si l'email est invalide
     */
    public static function email(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            throw new InvalidArgumentException('Email vide');
        }
        
        $email = filter_var($email, FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new InvalidArgumentException('Email invalide');
        }
        
        // Normaliser (minuscules, supprimer points avant @gmail.com)
        $parts = explode('@', $email);
        if (strtolower($parts[1]) === 'gmail.com') {
            $parts[0] = str_replace('.', '', $parts[0]);
        }
        
        return strtolower(implode('@', $parts));
    }
    
    /**
     * Valide un numéro de téléphone français
     * 
     * @param string|null $phone Numéro de téléphone
     * @return bool true si valide, false sinon
     */
    public static function phone(?string $phone): bool
    {
        if ($phone === null || $phone === '') {
            return false;
        }
        
        // Nettoyer le numéro (supprimer espaces, points, tirets)
        $cleaned = preg_replace('/[\s\.\-]/', '', $phone);
        
        // Format français : 10 chiffres, peut commencer par 0 ou +33
        if (preg_match('/^(?:\+33|0)[1-9]\d{8}$/', $cleaned)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Valide un SIRET (14 chiffres)
     * 
     * @param string $siret Numéro SIRET
     * @return bool true si valide, false sinon
     */
    public static function siret(string $siret): bool
    {
        $siret = preg_replace('/\s/', '', $siret);
        return preg_match('/^\d{14}$/', $siret) === 1;
    }
    
    /**
     * Valide un IBAN avec vérification du checksum
     * 
     * @param string $iban Numéro IBAN
     * @return bool true si valide, false sinon
     */
    public static function iban(string $iban): bool
    {
        $iban = str_replace(' ', '', strtoupper(trim($iban)));
        
        // Format de base : 2 lettres + 2 chiffres + 4-30 caractères alphanumériques
        if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{4,30}$/', $iban)) {
            return false;
        }
        
        // Vérifier le checksum modulo 97
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($rearranged) as $char) {
            $numeric .= is_numeric($char) ? $char : (string)(ord($char) - 55);
        }
        
        // Utiliser bcmod si disponible, sinon calcul manuel
        if (function_exists('bcmod')) {
            return bcmod($numeric, '97') === '1';
        }
        
        // Fallback : calcul modulo 97 manuel
        $remainder = 0;
        for ($i = 0; $i < strlen($numeric); $i++) {
            $remainder = ($remainder * 10 + (int)$numeric[$i]) % 97;
        }
        
        return $remainder === 1;
    }
    
    /**
     * Valide un code postal français (5 chiffres)
     * 
     * @param string $postalCode Code postal
     * @return bool true si valide, false sinon
     */
    public static function postalCode(string $postalCode): bool
    {
        $postalCode = trim($postalCode);
        return preg_match('/^\d{5}$/', $postalCode) === 1;
    }
    
    /**
     * Valide une chaîne de caractères
     * 
     * @param string $value Valeur à valider
     * @param string $name Nom du champ (pour les messages d'erreur)
     * @param int $minLength Longueur minimale
     * @param int $maxLength Longueur maximale
     * @return string Valeur validée
     * @throws InvalidArgumentException Si la validation échoue
     */
    public static function string(
        string $value,
        string $name,
        int $minLength = 1,
        int $maxLength = 1000
    ): string {
        $value = trim($value);
        
        if (strlen($value) < $minLength) {
            throw new InvalidArgumentException(
                "{$name} trop court (min {$minLength} caractères)"
            );
        }
        
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException(
                "{$name} trop long (max {$maxLength} caractères)"
            );
        }
        
        return $value;
    }
    
    /**
     * Valide un ID numérique
     * 
     * @param mixed $id ID à valider
     * @param string $name Nom du champ (pour les messages d'erreur)
     * @return int ID validé
     * @throws InvalidArgumentException Si l'ID est invalide
     */
    public static function id($id, string $name = 'ID'): int
    {
        $id = (int)$id;
        if ($id <= 0) {
            throw new InvalidArgumentException("{$name} invalide");
        }
        return $id;
    }
}

