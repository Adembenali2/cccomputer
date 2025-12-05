<?php

declare(strict_types=1);

namespace App\Services;

use DateTime;
use PDO;
use App\Models\Client;
use App\Repositories\ClientRepository;
use App\Repositories\CompteurRepository;
use App\Services\ConsumptionService;

/**
 * Service pour la gestion de la facturation et des consommations
 * 
 * Ce service gère :
 * - Le calcul des consommations pour les graphiques
 * - La génération des données pour les tableaux de consommation
 * - La génération des données pour les factures de consommation
 * 
 * @package App\Services
 */
class BillingService
{
    private PDO $pdo;
    private ClientRepository $clientRepository;
    private CompteurRepository $compteurRepository;
    private ConsumptionService $consumptionService;
    
    public function __construct(
        PDO $pdo,
        ClientRepository $clientRepository,
        CompteurRepository $compteurRepository,
        ConsumptionService $consumptionService
    ) {
        $this->pdo = $pdo;
        $this->clientRepository = $clientRepository;
        $this->compteurRepository = $compteurRepository;
        $this->consumptionService = $consumptionService;
    }
    
    /**
     * Récupère les données de consommation pour un graphique
     * 
     * @param int|null $clientId ID du client (null pour tous les clients)
     * @param string $granularity 'year' ou 'month'
     * @param array $periodParams ['year' => int, 'month' => int?]
     * @return array ['labels' => string[], 'nbData' => int[], 'colorData' => int[], 'totalData' => int[]]
     */
    public function getConsumptionChartData(?int $clientId, string $granularity, array $periodParams): array
    {
        // Charger les helpers de paiements pour utiliser calculatePeriodConsumption
        if (!function_exists('calculatePeriodConsumption')) {
            require_once __DIR__ . '/../../API/includes/paiements_helpers.php';
        }
        
        // Récupérer les MAC des photocopieurs du client (ou tous si clientId est null)
        $macs = $this->getClientMacs($clientId);
        
        if (empty($macs)) {
            // Aucun photocopieur, retourner des données vides
            return $this->getEmptyChartData($granularity, $periodParams);
        }
        
        // Calculer les périodes selon la granularité
        $periods = $this->calculatePeriods($granularity, $periodParams);
        
        // Pour chaque période, calculer la consommation totale de toutes les MAC
        $labels = [];
        $nbData = [];
        $colorData = [];
        
        foreach ($periods as $period) {
            $periodStart = $period['start'];
            $periodEnd = $period['end'];
            
            // Label selon la granularité
            if ($granularity === 'year') {
                $labels[] = $periodStart->format('M Y');
            } else {
                // Pour le mois, on peut afficher les jours ou les périodes 20→20
                // Ici, on affiche les périodes 20→20
                $labels[] = $periodStart->format('d/m');
            }
            
            // Calculer la consommation totale pour cette période (toutes les MAC)
            $totalBw = 0;
            $totalColor = 0;
            
            foreach ($macs as $macNorm) {
                $consumption = calculatePeriodConsumption($this->pdo, $macNorm, $periodStart, $periodEnd);
                $totalBw += $consumption['bw'] ?? 0;
                $totalColor += $consumption['color'] ?? 0;
            }
            
            $nbData[] = $totalBw;
            $colorData[] = $totalColor;
        }
        
        // Calculer les totaux
        $totalData = array_map(function($nb, $color) {
            return $nb + $color;
        }, $nbData, $colorData);
        
        return [
            'labels' => $labels,
            'nbData' => $nbData,
            'colorData' => $colorData,
            'totalData' => $totalData
        ];
    }
    
    /**
     * Récupère les données pour le tableau de consommation
     * 
     * @param int|null $clientId ID du client (null pour tous les clients)
     * @param int $months Nombre de mois à afficher (par défaut 3)
     * @return array Liste des imprimantes avec leurs consommations
     */
    public function getConsumptionTableData(?int $clientId, int $months = 3): array
    {
        // Charger les helpers de paiements
        if (!function_exists('calculatePeriodConsumption')) {
            require_once __DIR__ . '/../../API/includes/paiements_helpers.php';
        }
        
        // Récupérer les photocopieurs du client
        $photocopieurs = $this->getClientPhotocopieurs($clientId);
        
        if (empty($photocopieurs)) {
            return [];
        }
        
        // Calculer les périodes des N derniers mois (20→20)
        $now = new DateTime();
        $currentDay = (int)$now->format('d');
        $currentMonth = (int)$now->format('m');
        $currentYear = (int)$now->format('Y');
        
        // Si on est avant le 20, le mois en cours n'est pas encore terminé
        $startMonth = $currentDay < 20 ? $currentMonth - 1 : $currentMonth;
        
        $periods = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $startMonth - $i;
            $year = $currentYear;
            
            // Gérer le passage d'année
            if ($month < 1) {
                $month += 12;
                $year--;
            }
            
            // Période 20 du mois → 20 du mois suivant
            $periodStart = new DateTime("$year-$month-20 00:00:00");
            $periodEnd = clone $periodStart;
            $periodEnd->modify('+1 month');
            
            $periods[] = [
                'start' => $periodStart,
                'end' => $periodEnd,
                'key' => "$year-" . str_pad((string)$month, 2, '0', STR_PAD_LEFT),
                'label' => $this->formatPeriodLabel($periodStart, $periodEnd)
            ];
        }
        
        // Pour chaque photocopieur, calculer les consommations
        $result = [];
        foreach ($photocopieurs as $photocopieur) {
            $macNorm = $photocopieur['mac_norm'];
            if (empty($macNorm)) {
                continue;
            }
            
            $consommations = [];
            foreach ($periods as $period) {
                $consumption = calculatePeriodConsumption(
                    $this->pdo,
                    $macNorm,
                    $period['start'],
                    $period['end']
                );
                
                // Ne garder que les périodes avec consommation > 0
                if (($consumption['bw'] ?? 0) > 0 || ($consumption['color'] ?? 0) > 0) {
                    $consommations[] = [
                        'mois' => $period['key'],
                        'periode' => $period['label'],
                        'pagesNB' => $consumption['bw'] ?? 0,
                        'pagesCouleur' => $consumption['color'] ?? 0,
                        'totalPages' => ($consumption['bw'] ?? 0) + ($consumption['color'] ?? 0)
                    ];
                }
            }
            
            // Ne garder que les photocopieurs avec au moins une consommation
            if (!empty($consommations)) {
                $result[] = [
                    'id' => $photocopieur['id'] ?? null,
                    'nom' => $photocopieur['nom'] ?? 'Inconnu',
                    'modele' => $photocopieur['model'] ?? 'Inconnu',
                    'macAddress' => $photocopieur['mac_address'] ?? '',
                    'consommations' => $consommations
                ];
            }
        }
        
        return $result;
    }
    
    /**
     * Récupère les données pour une facture de consommation
     * 
     * @param int $clientId ID du client
     * @param DateTime $periodStart Date de début de période (20 du mois)
     * @param DateTime $periodEnd Date de fin de période (20 du mois suivant)
     * @return array Données de facture
     */
    public function getConsumptionInvoiceData(int $clientId, DateTime $periodStart, DateTime $periodEnd): array
    {
        // Charger les helpers de paiements
        if (!function_exists('calculatePeriodConsumption')) {
            require_once __DIR__ . '/../../API/includes/paiements_helpers.php';
        }
        
        // Récupérer le client
        $client = $this->clientRepository->findById($clientId);
        if (!$client) {
            throw new \RuntimeException("Client introuvable: $clientId");
        }
        
        // Récupérer les photocopieurs du client
        $photocopieurs = $this->getClientPhotocopieurs($clientId);
        
        if (empty($photocopieurs)) {
            return [
                'client' => $client->toArray(),
                'period' => [
                    'start' => $periodStart->format('Y-m-d'),
                    'end' => $periodEnd->format('Y-m-d'),
                    'label' => $this->formatPeriodLabel($periodStart, $periodEnd)
                ],
                'lignes' => [],
                'total' => [
                    'nb' => 0,
                    'color' => 0,
                    'total' => 0
                ]
            ];
        }
        
        // Calculer la consommation pour chaque photocopieur
        $lignes = [];
        $totalNb = 0;
        $totalColor = 0;
        
        foreach ($photocopieurs as $photocopieur) {
            $macNorm = $photocopieur['mac_norm'];
            if (empty($macNorm)) {
                continue;
            }
            
            $consumption = calculatePeriodConsumption(
                $this->pdo,
                $macNorm,
                $periodStart,
                $periodEnd
            );
            
            $nb = $consumption['bw'] ?? 0;
            $color = $consumption['color'] ?? 0;
            
            if ($nb > 0 || $color > 0) {
                $lignes[] = [
                    'photocopieur' => [
                        'nom' => $photocopieur['nom'] ?? 'Inconnu',
                        'modele' => $photocopieur['model'] ?? 'Inconnu',
                        'mac' => $photocopieur['mac_address'] ?? ''
                    ],
                    'nb' => $nb,
                    'color' => $color,
                    'total' => $nb + $color
                ];
                
                $totalNb += $nb;
                $totalColor += $color;
            }
        }
        
        return [
            'client' => $client->toArray(),
            'period' => [
                'start' => $periodStart->format('Y-m-d'),
                'end' => $periodEnd->format('Y-m-d'),
                'label' => $this->formatPeriodLabel($periodStart, $periodEnd)
            ],
            'lignes' => $lignes,
            'total' => [
                'nb' => $totalNb,
                'color' => $totalColor,
                'total' => $totalNb + $totalColor
            ]
        ];
    }
    
    /**
     * Récupère les MAC normalisées des photocopieurs d'un client
     * 
     * @param int|null $clientId ID du client (null pour tous les clients)
     * @return string[] Liste des MAC normalisées
     */
    private function getClientMacs(?int $clientId): array
    {
        if ($clientId === null) {
            // Tous les clients
            $sql = "
                SELECT DISTINCT mac_norm
                FROM photocopieurs_clients
                WHERE mac_norm IS NOT NULL AND mac_norm != ''
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            // Client spécifique
            $sql = "
                SELECT DISTINCT mac_norm
                FROM photocopieurs_clients
                WHERE id_client = :client_id
                  AND mac_norm IS NOT NULL AND mac_norm != ''
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':client_id' => $clientId]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    
    /**
     * Récupère les photocopieurs d'un client avec leurs informations
     * 
     * @param int|null $clientId ID du client (null pour tous les clients)
     * @return array Liste des photocopieurs
     */
    private function getClientPhotocopieurs(?int $clientId): array
    {
        if ($clientId === null) {
            $sql = "
                SELECT 
                    pc.id,
                    pc.mac_norm,
                    pc.MacAddress as mac_address,
                    pc.SerialNumber as serial_number,
                    COALESCE(r.Model, 'Inconnu') as model,
                    COALESCE(r.Nom, 'Inconnu') as nom
                FROM photocopieurs_clients pc
                LEFT JOIN (
                    SELECT mac_norm, Model, Nom,
                           ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
                    FROM compteur_relevee
                ) r ON r.mac_norm = pc.mac_norm AND r.rn = 1
                WHERE pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
                ORDER BY pc.id
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
        } else {
            $sql = "
                SELECT 
                    pc.id,
                    pc.mac_norm,
                    pc.MacAddress as mac_address,
                    pc.SerialNumber as serial_number,
                    COALESCE(r.Model, 'Inconnu') as model,
                    COALESCE(r.Nom, 'Inconnu') as nom
                FROM photocopieurs_clients pc
                LEFT JOIN (
                    SELECT mac_norm, Model, Nom,
                           ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
                    FROM compteur_relevee
                ) r ON r.mac_norm = pc.mac_norm AND r.rn = 1
                WHERE pc.id_client = :client_id
                  AND pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
                ORDER BY pc.id
            ";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':client_id' => $clientId]);
        }
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Calcule les périodes selon la granularité
     * 
     * @param string $granularity 'year' ou 'month'
     * @param array $periodParams ['year' => int, 'month' => int?]
     * @return array Liste des périodes [['start' => DateTime, 'end' => DateTime], ...]
     */
    private function calculatePeriods(string $granularity, array $periodParams): array
    {
        $periods = [];
        
        if ($granularity === 'year') {
            $year = $periodParams['year'] ?? (int)date('Y');
            
            // 12 périodes de facturation (20→20) pour l'année
            for ($month = 1; $month <= 12; $month++) {
                $periodStart = new DateTime("$year-$month-20 00:00:00");
                $periodEnd = clone $periodStart;
                $periodEnd->modify('+1 month');
                
                $periods[] = [
                    'start' => $periodStart,
                    'end' => $periodEnd
                ];
            }
        } else {
            // granularity === 'month'
            $year = $periodParams['year'] ?? (int)date('Y');
            $month = $periodParams['month'] ?? (int)date('m');
            
            // Pour un mois, on peut afficher les périodes 20→20 des 12 derniers mois
            // ou les jours du mois. Ici, on affiche les périodes 20→20 des 12 derniers mois
            $periodStart = new DateTime("$year-$month-20 00:00:00");
            
            for ($i = 0; $i < 12; $i++) {
                $start = clone $periodStart;
                $start->modify("-$i months");
                $end = clone $start;
                $end->modify('+1 month');
                
                $periods[] = [
                    'start' => $start,
                    'end' => $end
                ];
            }
            
            // Inverser pour avoir les plus anciennes en premier
            $periods = array_reverse($periods);
        }
        
        return $periods;
    }
    
    /**
     * Retourne des données de graphique vides
     * 
     * @param string $granularity
     * @param array $periodParams
     * @return array
     */
    private function getEmptyChartData(string $granularity, array $periodParams): array
    {
        $periods = $this->calculatePeriods($granularity, $periodParams);
        $labels = [];
        
        foreach ($periods as $period) {
            if ($granularity === 'year') {
                $labels[] = $period['start']->format('M Y');
            } else {
                $labels[] = $period['start']->format('d/m');
            }
        }
        
        $count = count($labels);
        
        return [
            'labels' => $labels,
            'nbData' => array_fill(0, $count, 0),
            'colorData' => array_fill(0, $count, 0),
            'totalData' => array_fill(0, $count, 0)
        ];
    }
    
    /**
     * Formate un label de période
     * 
     * @param DateTime $start
     * @param DateTime $end
     * @return string
     */
    private function formatPeriodLabel(DateTime $start, DateTime $end): string
    {
        $startDay = $start->format('d');
        $startMonth = $start->format('m');
        $endDay = $end->format('d');
        $endMonth = $end->format('m');
        
        return "$startDay/$startMonth → $endDay/$endMonth";
    }
}

