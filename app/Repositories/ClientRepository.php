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
}

