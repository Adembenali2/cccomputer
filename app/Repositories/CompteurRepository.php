<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;
use DateTime;
use App\Models\Releve;

/**
 * Repository pour l'accès aux données des relevés de compteurs
 * 
 * @package App\Repositories
 */
class CompteurRepository
{
    private PDO $pdo;
    
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }
    
    /**
     * Récupère tous les relevés pour une MAC normalisée
     * 
     * @param string $macNorm
     * @return Releve[]
     */
    public function findByMacNorm(string $macNorm): array
    {
        $sql = "
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee' as source
            FROM compteur_relevee
            WHERE mac_norm = :mac
            UNION ALL
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee_ancien' as source
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac
            ORDER BY Timestamp DESC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':mac' => $macNorm]);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Releve::fromArray($row, $row['source']);
        }
        
        return $results;
    }
    
    /**
     * Récupère les relevés pour une MAC normalisée dans une période
     * 
     * @param string $macNorm
     * @param DateTime $start
     * @param DateTime $end
     * @return Releve[]
     */
    public function findByMacNormAndPeriod(string $macNorm, DateTime $start, DateTime $end): array
    {
        $sql = "
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee' as source
            FROM compteur_relevee
            WHERE mac_norm = :mac
              AND Timestamp >= :start
              AND Timestamp <= :end
            UNION ALL
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee_ancien' as source
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac
              AND Timestamp >= :start
              AND Timestamp <= :end
            ORDER BY Timestamp ASC
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':mac' => $macNorm,
            ':start' => $start->format('Y-m-d H:i:s'),
            ':end' => $end->format('Y-m-d H:i:s'),
        ]);
        
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = Releve::fromArray($row, $row['source']);
        }
        
        return $results;
    }
    
    /**
     * Trouve le compteur de départ pour une période (idéalement du 20)
     * 
     * @param string $macNorm
     * @param DateTime $periodStart
     * @return Releve|null
     */
    public function findPeriodStartCounter(string $macNorm, DateTime $periodStart): ?Releve
    {
        $periodDate = $periodStart->format('Y-m-d');
        
        // D'abord, chercher le compteur exactement du 20
        $sql = "
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee' as source
            FROM compteur_relevee
            WHERE mac_norm = :mac
              AND DATE(Timestamp) = :period_date
            UNION ALL
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee_ancien' as source
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac
              AND DATE(Timestamp) = :period_date
            ORDER BY Timestamp ASC
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':mac' => $macNorm,
            ':period_date' => $periodDate,
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return Releve::fromArray($row, $row['source']);
        }
        
        // Si pas trouvé, chercher le premier après le 20
        $periodStartStr = $periodStart->format('Y-m-d H:i:s');
        $sql = "
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee' as source
            FROM compteur_relevee
            WHERE mac_norm = :mac
              AND Timestamp > :period_start
            UNION ALL
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee_ancien' as source
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac
              AND Timestamp > :period_start
            ORDER BY Timestamp ASC
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':mac' => $macNorm,
            ':period_start' => $periodStartStr,
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Releve::fromArray($row, $row['source']) : null;
    }
    
    /**
     * Trouve le compteur de fin pour une période
     * 
     * @param string $macNorm
     * @param DateTime $periodEnd
     * @return Releve|null
     */
    public function findPeriodEndCounter(string $macNorm, DateTime $periodEnd): ?Releve
    {
        $periodEndStr = $periodEnd->format('Y-m-d H:i:s');
        
        $sql = "
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee' as source
            FROM compteur_relevee
            WHERE mac_norm = :mac
              AND Timestamp <= :period_end
            UNION ALL
            SELECT 
                id, mac_norm, MacAddress, SerialNumber, Model, Nom, Status, IpAddress,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp,
                'compteur_relevee_ancien' as source
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac
              AND Timestamp <= :period_end
            ORDER BY Timestamp DESC
            LIMIT 1
        ";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':mac' => $macNorm,
            ':period_end' => $periodEndStr,
        ]);
        
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Releve::fromArray($row, $row['source']) : null;
    }
}

