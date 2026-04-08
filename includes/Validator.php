<?php
declare(strict_types=1);

/**
 * Classe centralisée pour la validation des données
 * Remplace les validations dupliquées dans plusieurs fichiers
 */
class Validator
{
    /**
     * Vérifie que chaque clé existe dans $data et n'est pas vide (null, '', chaîne blanche).
     */
    public static function requireFields(array $fields, array $data): void
    {
        if (!function_exists('apiFail')) {
            require_once __DIR__ . '/api_helpers.php';
        }
        foreach ($fields as $key) {
            if (!array_key_exists($key, $data)) {
                apiFail('Champ requis manquant : ' . $key, 400);
            }
            $v = $data[$key];
            if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
                apiFail('Champ requis manquant : ' . $key, 400);
            }
        }
    }

    /**
     * Valide et retourne un entier ; $min / $max inclus si fournis.
     *
     * @param mixed $value
     */
    public static function int($value, ?int $min = null, ?int $max = null): int
    {
        if (is_bool($value)) {
            throw new InvalidArgumentException('Entier invalide');
        }
        if (is_int($value)) {
            $int = $value;
        } else {
            $filtered = filter_var($value, FILTER_VALIDATE_INT);
            if ($filtered === false) {
                throw new InvalidArgumentException('Entier invalide');
            }
            $int = $filtered;
        }
        if ($min !== null && $int < $min) {
            throw new InvalidArgumentException('Entier hors borne minimale');
        }
        if ($max !== null && $int > $max) {
            throw new InvalidArgumentException('Entier hors borne maximale');
        }
        return $int;
    }

    /**
     * Valide et retourne un flottant >= $min.
     *
     * @param mixed $value
     */
    public static function float($value, float $min = 0.0): float
    {
        if (is_bool($value)) {
            throw new InvalidArgumentException('Nombre décimal invalide');
        }
        $f = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($f === false) {
            throw new InvalidArgumentException('Nombre décimal invalide');
        }
        if ($f < $min) {
            throw new InvalidArgumentException('Nombre décimal hors borne minimale');
        }
        return (float) $f;
    }

    /**
     * Trim, strip_tags, htmlspecialchars ; exception si trop long.
     *
     * @param mixed $value
     */
    public static function string($value, int $maxLen = 255): string
    {
        $raw = trim((string) $value);
        $noTags = strip_tags($raw);
        if (strlen($noTags) > $maxLen) {
            throw new InvalidArgumentException('Chaîne trop longue');
        }
        return htmlspecialchars($noTags, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Vérifie que $value est l'une des valeurs autorisées (comparaison stricte).
     *
     * @param mixed $value
     */
    public static function enum($value, array $allowed): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Valeur non autorisée');
        }
        return (string) $value;
    }

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

