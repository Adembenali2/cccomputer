<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle Photocopieur
 * 
 * Représente un photocopieur dans l'application
 * 
 * @package App\Models
 */
class Photocopieur
{
    public int $id;
    public ?int $idClient;
    public string $macNorm;
    public ?string $macAddress;
    public ?string $serialNumber;
    public ?string $model;
    public ?string $nom;
    public ?string $status;
    public ?string $ipAddress;
    
    /**
     * Crée une instance Photocopieur depuis un tableau de données
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $photocopieur = new self();
        
        $photocopieur->id = (int)($data['id'] ?? 0);
        $photocopieur->idClient = isset($data['id_client']) ? (int)$data['id_client'] : null;
        $photocopieur->macNorm = $data['mac_norm'] ?? '';
        $photocopieur->macAddress = $data['MacAddress'] ?? $data['mac_address'] ?? null;
        $photocopieur->serialNumber = $data['SerialNumber'] ?? $data['serial_number'] ?? null;
        $photocopieur->model = $data['Model'] ?? $data['model'] ?? null;
        $photocopieur->nom = $data['Nom'] ?? $data['nom'] ?? null;
        $photocopieur->status = $data['Status'] ?? $data['status'] ?? null;
        $photocopieur->ipAddress = $data['IpAddress'] ?? $data['ip_address'] ?? null;
        
        return $photocopieur;
    }
}

