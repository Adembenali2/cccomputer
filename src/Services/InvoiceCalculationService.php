<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Service de calcul pour les factures d'imprimantes
 * Gère les offres 1000/2000 copies avec calculs de dépassements NB et couleur
 */
class InvoiceCalculationService
{
    // Tarifs fixes
    private const FORFAIT_HT = 100.0;
    private const PRIX_EXCESS_NB_HT = 0.05;
    private const PRIX_COULEUR_HT = 0.09;
    private const TAUX_TVA = 0.20;
    
    /**
     * Calcule le coût d'une imprimante selon l'offre et la consommation
     * 
     * @param int $offre Seuil inclus dans le forfait (1000 ou 2000)
     * @param float $consoNB Consommation noir & blanc
     * @param float $consoCouleur Consommation couleur
     * @return array Détails du calcul :
     *   - forfait_ht: float
     *   - seuil_nb: int
     *   - excess_nb: float (copies en excès)
     *   - excess_nb_ht: float (coût HT de l'excès)
     *   - couleur_ht: float (coût HT couleur)
     *   - total_ht_machine: float
     * @throws \InvalidArgumentException Si les paramètres sont invalides
     */
    public static function calculateMachineInvoice(
        int $offre,
        float $consoNB,
        float $consoCouleur
    ): array {
        // Validation
        if (!in_array($offre, [1000, 2000], true)) {
            throw new \InvalidArgumentException("Offre invalide: {$offre} (doit être 1000 ou 2000)");
        }
        
        if ($consoNB < 0) {
            throw new \InvalidArgumentException("Consommation NB ne peut pas être négative: {$consoNB}");
        }
        
        if ($consoCouleur < 0) {
            throw new \InvalidArgumentException("Consommation couleur ne peut pas être négative: {$consoCouleur}");
        }
        
        // Cast en float pour éviter les problèmes de type
        $consoNB = (float)$consoNB;
        $consoCouleur = (float)$consoCouleur;
        $seuilNB = (int)$offre;
        
        // Forfait fixe
        $forfaitHT = self::FORFAIT_HT;
        
        // Calcul de l'excès NB
        $excessNB = max(0.0, $consoNB - $seuilNB);
        $excessNBHT = $excessNB * self::PRIX_EXCESS_NB_HT;
        
        // Calcul couleur
        $couleurHT = $consoCouleur * self::PRIX_COULEUR_HT;
        
        // Total HT pour cette machine
        $totalHTMachine = $forfaitHT + $excessNBHT + $couleurHT;
        
        return [
            'forfait_ht' => $forfaitHT,
            'seuil_nb' => $seuilNB,
            'excess_nb' => $excessNB,
            'excess_nb_ht' => $excessNBHT,
            'couleur_ht' => $couleurHT,
            'total_ht_machine' => $totalHTMachine,
            'conso_nb' => $consoNB,
            'conso_couleur' => $consoCouleur
        ];
    }
    
    /**
     * Génère les lignes de facture pour une imprimante
     * 
     * @param array $calculation Résultat de calculateMachineInvoice()
     * @param string $machineName Nom/identifiant de l'imprimante (ex: "Imprimante A", "HP-123")
     * @param int $offre Offre choisie (1000 ou 2000)
     * @return array Lignes de facture au format pour facture_lignes
     */
    public static function generateInvoiceLinesForMachine(
        array $calculation,
        string $machineName,
        int $offre
    ): array {
        $lines = [];
        $ordre = 0;
        
        // Ligne 1: Forfait mensuel
        $lines[] = [
            'description' => "Forfait mensuel (Offre {$offre} copies) - {$machineName}",
            'type' => 'Service',
            'quantite' => 1.0,
            'prix_unitaire' => $calculation['forfait_ht'],
            'total_ht' => $calculation['forfait_ht'],
            'ordre' => $ordre++
        ];
        
        // Ligne 2: Dépassement NB (si > 0)
        if ($calculation['excess_nb'] > 0) {
            $lines[] = [
                'description' => sprintf(
                    "Dépassement NB (%d copies x %.2f€) - %s",
                    (int)$calculation['excess_nb'],
                    self::PRIX_EXCESS_NB_HT,
                    $machineName
                ),
                'type' => 'Consommation',
                'quantite' => $calculation['excess_nb'],
                'prix_unitaire' => self::PRIX_EXCESS_NB_HT,
                'total_ht' => $calculation['excess_nb_ht'],
                'ordre' => $ordre++
            ];
        }
        
        // Ligne 3: Couleur (si > 0)
        if ($calculation['conso_couleur'] > 0) {
            $lines[] = [
                'description' => sprintf(
                    "Copies couleur (%d copies x %.2f€) - %s",
                    (int)$calculation['conso_couleur'],
                    self::PRIX_COULEUR_HT,
                    $machineName
                ),
                'type' => 'Consommation',
                'quantite' => $calculation['conso_couleur'],
                'prix_unitaire' => self::PRIX_COULEUR_HT,
                'total_ht' => $calculation['couleur_ht'],
                'ordre' => $ordre++
            ];
        }
        
        return $lines;
    }
    
    /**
     * Calcule les totaux d'une facture à partir des lignes
     * 
     * @param array $lignes Lignes de facture (format facture_lignes)
     * @return array ['montant_ht' => float, 'tva' => float, 'montant_ttc' => float]
     */
    public static function calculateInvoiceTotals(array $lignes): array
    {
        $montantHT = 0.0;
        
        foreach ($lignes as $ligne) {
            $totalHT = (float)($ligne['total_ht'] ?? 0);
            $montantHT += $totalHT;
        }
        
        $tva = $montantHT * self::TAUX_TVA;
        $montantTTC = $montantHT + $tva;
        
        return [
            'montant_ht' => $montantHT,
            'tva' => $tva,
            'montant_ttc' => $montantTTC
        ];
    }
    
    /**
     * Génère toutes les lignes de facture pour un client avec 1 ou 2 imprimantes
     * 
     * @param int $offre Offre choisie (1000 ou 2000)
     * @param int $nbImprimantes Nombre d'imprimantes (1 ou 2)
     * @param array $machines Données des machines :
     *   - machine1: ['conso_nb' => float, 'conso_couleur' => float, 'nom' => string]
     *   - machine2: ['conso_nb' => float, 'conso_couleur' => float, 'nom' => string] (optionnel)
     * @return array Toutes les lignes de facture
     * @throws \InvalidArgumentException Si les données sont invalides
     */
    public static function generateAllInvoiceLines(
        int $offre,
        int $nbImprimantes,
        array $machines
    ): array {
        if (!in_array($nbImprimantes, [1, 2], true)) {
            throw new \InvalidArgumentException("Nombre d'imprimantes invalide: {$nbImprimantes} (doit être 1 ou 2)");
        }
        
        if ($nbImprimantes === 1 && empty($machines['machine1'])) {
            throw new \InvalidArgumentException("Données machine1 requises pour 1 imprimante");
        }
        
        if ($nbImprimantes === 2 && (empty($machines['machine1']) || empty($machines['machine2']))) {
            throw new \InvalidArgumentException("Données machine1 et machine2 requises pour 2 imprimantes");
        }
        
        $allLines = [];
        $ordreGlobal = 0;
        
        // Machine 1
        $machine1 = $machines['machine1'];
        $calc1 = self::calculateMachineInvoice(
            $offre,
            (float)($machine1['conso_nb'] ?? 0),
            (float)($machine1['conso_couleur'] ?? 0)
        );
        $machine1Name = $machine1['nom'] ?? 'Imprimante A';
        $lines1 = self::generateInvoiceLinesForMachine($calc1, $machine1Name, $offre);
        
        foreach ($lines1 as $line) {
            $line['ordre'] = $ordreGlobal++;
            $allLines[] = $line;
        }
        
        // Machine 2 (si présente)
        if ($nbImprimantes === 2) {
            $machine2 = $machines['machine2'];
            $calc2 = self::calculateMachineInvoice(
                $offre,
                (float)($machine2['conso_nb'] ?? 0),
                (float)($machine2['conso_couleur'] ?? 0)
            );
            $machine2Name = $machine2['nom'] ?? 'Imprimante B';
            $lines2 = self::generateInvoiceLinesForMachine($calc2, $machine2Name, $offre);
            
            foreach ($lines2 as $line) {
                $line['ordre'] = $ordreGlobal++;
                $allLines[] = $line;
            }
        }
        
        return $allLines;
    }
}

