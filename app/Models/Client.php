<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Modèle Client
 * 
 * Représente un client dans l'application
 * 
 * @package App\Models
 */
class Client
{
    public int $id;
    public string $numeroClient;
    public string $raisonSociale;
    public string $adresse;
    public string $codePostal;
    public string $ville;
    public ?string $adresseLivraison;
    public bool $livraisonIdentique;
    public string $siret;
    public ?string $numeroTva;
    public string $depotMode;
    public ?string $nomDirigeant;
    public ?string $prenomDirigeant;
    public string $telephone1;
    public ?string $telephone2;
    public string $email;
    public ?string $parrain;
    public string $offre; // 'packbronze' ou 'packargent'
    public \DateTime $dateCreation;
    public \DateTime $dateDajout;
    
    /**
     * Crée une instance Client depuis un tableau de données
     * 
     * @param array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $client = new self();
        
        $client->id = (int)($data['id'] ?? 0);
        $client->numeroClient = $data['numero_client'] ?? '';
        $client->raisonSociale = $data['raison_sociale'] ?? '';
        $client->adresse = $data['adresse'] ?? '';
        $client->codePostal = $data['code_postal'] ?? '';
        $client->ville = $data['ville'] ?? '';
        $client->adresseLivraison = $data['adresse_livraison'] ?? null;
        $client->livraisonIdentique = (bool)($data['livraison_identique'] ?? false);
        $client->siret = $data['siret'] ?? '';
        $client->numeroTva = $data['numero_tva'] ?? null;
        $client->depotMode = $data['depot_mode'] ?? 'espece';
        $client->nomDirigeant = $data['nom_dirigeant'] ?? null;
        $client->prenomDirigeant = $data['prenom_dirigeant'] ?? null;
        $client->telephone1 = $data['telephone1'] ?? '';
        $client->telephone2 = $data['telephone2'] ?? null;
        $client->email = $data['email'] ?? '';
        $client->parrain = $data['parrain'] ?? null;
        $client->offre = $data['offre'] ?? 'packbronze';
        
        $client->dateCreation = new \DateTime($data['date_creation'] ?? 'now');
        $client->dateDajout = new \DateTime($data['date_dajout'] ?? 'now');
        
        return $client;
    }
    
    /**
     * Convertit le client en tableau
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'numero_client' => $this->numeroClient,
            'raison_sociale' => $this->raisonSociale,
            'adresse' => $this->adresse,
            'code_postal' => $this->codePostal,
            'ville' => $this->ville,
            'adresse_livraison' => $this->adresseLivraison,
            'livraison_identique' => $this->livraisonIdentique,
            'siret' => $this->siret,
            'numero_tva' => $this->numeroTva,
            'depot_mode' => $this->depotMode,
            'nom_dirigeant' => $this->nomDirigeant,
            'prenom_dirigeant' => $this->prenomDirigeant,
            'telephone1' => $this->telephone1,
            'telephone2' => $this->telephone2,
            'email' => $this->email,
            'parrain' => $this->parrain,
            'offre' => $this->offre,
            'date_creation' => $this->dateCreation->format('Y-m-d H:i:s'),
            'date_dajout' => $this->dateDajout->format('Y-m-d H:i:s'),
        ];
    }
}

