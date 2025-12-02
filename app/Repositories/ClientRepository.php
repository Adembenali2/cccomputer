<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use App\Models\Client;

/**
 * Repository pour l'accès aux données des clients
 * 
 * @package App\Repositories
 */
class ClientRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Trouve un client par son ID
     * 
     * @param int $id
     * @return Client|null
     */
    public function findById(int $id): ?Client
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM clients WHERE id = :id
        ");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $data ? Client::fromArray($data) : null;
    }
    
    /**
     * Trouve un client par son numéro client
     * 
     * @param string $numeroClient
     * @return Client|null
     */
    public function findByNumero(string $numeroClient): ?Client
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM clients WHERE numero_client = :numero
        ");
        $stmt->execute([':numero' => $numeroClient]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $data ? Client::fromArray($data) : null;
    }
    
    /**
     * Recherche des clients par terme
     * 
     * @param string $query
     * @param int $limit
     * @return Client[]
     */
    public function search(string $query, int $limit = 20): array
    {
        $searchTerm = '%' . $query . '%';
        
        $stmt = $this->pdo->prepare("
            SELECT * FROM clients
            WHERE raison_sociale LIKE :q
               OR numero_client LIKE :q
               OR nom_dirigeant LIKE :q
               OR prenom_dirigeant LIKE :q
               OR ville LIKE :q
            ORDER BY raison_sociale ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':q', $searchTerm, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Client::fromArray($row);
        }
        
        return $results;
    }
    
    /**
     * Récupère tous les clients (avec limite)
     * 
     * @param int $limit
     * @return Client[]
     */
    public function findAll(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM clients
            ORDER BY raison_sociale ASC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Client::fromArray($row);
        }
        
        return $results;
    }
    
    /**
     * Récupère tous les clients avec leurs photocopieurs associés
     * 
     * @return array ['client' => Client, 'photocopieurs' => array[]]
     */
    public function findAllWithPhotocopieurs(): array
    {
        $sql = "
            SELECT 
                c.*,
                pc.mac_norm,
                pc.MacAddress,
                pc.SerialNumber,
                COALESCE(
                    r1.Model,
                    r2.Model,
                    'Inconnu'
                ) as Model
            FROM clients c
            INNER JOIN photocopieurs_clients pc ON pc.id_client = c.id
            LEFT JOIN (
                SELECT mac_norm, Model, 
                       ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
                FROM compteur_relevee
                WHERE Model IS NOT NULL
            ) r1 ON r1.mac_norm = pc.mac_norm AND r1.rn = 1
            LEFT JOIN (
                SELECT mac_norm, Model,
                       ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
                FROM compteur_relevee_ancien
                WHERE Model IS NOT NULL
            ) r2 ON r2.mac_norm = pc.mac_norm AND r2.rn = 1
            WHERE pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
            ORDER BY c.raison_sociale, pc.mac_norm
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        
        $clientsData = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $clientId = (int)($row['id'] ?? 0);
            
            if (!isset($clientsData[$clientId])) {
                $clientsData[$clientId] = [
                    'client' => Client::fromArray($row),
                    'photocopieurs' => []
                ];
            }
            
            $clientsData[$clientId]['photocopieurs'][] = [
                'mac_norm' => trim($row['mac_norm'] ?? ''),
                'mac_address' => $row['MacAddress'] ?? '',
                'serial' => $row['SerialNumber'] ?? '',
                'model' => $row['Model'] ?? 'Inconnu'
            ];
        }
        
        return array_values($clientsData);
    }
}

