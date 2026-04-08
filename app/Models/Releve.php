<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle Releve
 * 
 * Représente un relevé de compteur
 * 
 * @package App\Models
 */
class Releve
{
    public int $id;
    public string $macNorm;
    public ?string $macAddress;
    public ?string $serialNumber;
    public ?string $model;
    public ?string $nom;
    public ?string $status;
    public ?string $ipAddress;
    public int $totalBw;
    public int $totalColor;
    public \DateTime $timestamp;
    public string $source; // 'compteur_relevee' ou 'compteur_relevee_ancien'
    
    /**
     * Crée une instance Releve depuis un tableau de données
     * 
     * @param array $data
     * @param string $source
     * @return self
     */
    public static function fromArray(array $data, string $source = 'compteur_relevee'): self
    {
        $releve = new self();
        
        $releve->id = (int)($data['id'] ?? 0);
        $releve->macNorm = $data['mac_norm'] ?? '';
        $releve->macAddress = $data['MacAddress'] ?? null;
        $releve->serialNumber = $data['SerialNumber'] ?? null;
        $releve->model = $data['Model'] ?? null;
        $releve->nom = $data['Nom'] ?? null;
        $releve->status = $data['Status'] ?? null;
        $releve->ipAddress = $data['IpAddress'] ?? null;
        $releve->totalBw = (int)($data['TotalBW'] ?? 0);
        $releve->totalColor = (int)($data['TotalColor'] ?? 0);
        $releve->timestamp = new \DateTime($data['Timestamp'] ?? 'now');
        $releve->source = $source;
        
        return $releve;
    }
    
    /**
     * Calcule la consommation noir/blanc entre deux relevés
     * 
     * @param self $start
     * @param self $end
     * @return int
     */
    public static function calculateBwConsumption(self $start, self $end): int
    {
        return max(0, $end->totalBw - $start->totalBw);
    }
    
    /**
     * Calcule la consommation couleur entre deux relevés
     * 
     * @param self $start
     * @param self $end
     * @return int
     */
    public static function calculateColorConsumption(self $start, self $end): int
    {
        return max(0, $end->totalColor - $start->totalColor);
    }
}

