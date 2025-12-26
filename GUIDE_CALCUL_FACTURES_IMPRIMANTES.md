# Guide : Calcul de Factures pour Imprimantes

**Version :** 1.0  
**Date :** 2025-01-XX

---

## 📋 Vue d'ensemble

Le système de génération de factures a été amélioré pour supporter le calcul automatique des factures d'imprimantes avec deux offres :
- **Offre 1000 copies** : Forfait 100€ HT + dépassement NB + couleur
- **Offre 2000 copies** : Forfait 100€ HT + dépassement NB + couleur

Le calcul est effectué **par imprimante** (le seuil s'applique à chaque machine, pas globalement).

---

## 🎯 Règles métier

### Offre "1000 copies" (par imprimante)
- **Forfait mensuel HT** : 100 €
- **Inclus** : 1000 copies noir & blanc (NB)
- **Dépassement NB** : Si `consoNB > 1000` → `excessNB = consoNB - 1000` → coût = `excessNB × 0.05 € HT`
- **Couleur** : `consoCouleur × 0.09 € HT`
- **Total HT imprimante** = `100 + (excessNB × 0.05) + (couleur × 0.09)`

**Exemple :**
- `consoNB = 1500`, `consoCouleur = 0`
- `total = 100 + (500 × 0.05) = 125 € HT`

### Offre "2000 copies" (par imprimante)
- **Forfait mensuel HT** : 100 €
- **Inclus** : 2000 copies NB
- **Dépassement NB** : Si `consoNB > 2000` → `excessNB = consoNB - 2000` → coût = `excessNB × 0.05 € HT`
- **Couleur** : `consoCouleur × 0.09 € HT`
- **Total HT imprimante** = `100 + (excessNB × 0.05) + (couleur × 0.09)`

### Cas client avec 2 imprimantes
- Chaque imprimante est facturée **séparément** avec le même modèle
- Le seuil (1000/2000) s'applique **par imprimante**, pas globalement
- Total facture = somme des totaux de chaque imprimante

**Exemple :**
- Imprimante A : `consoNB = 2500`, `consoCouleur = 50` (offre 2000)
  - Total A = `100 + (500 × 0.05) + (50 × 0.09) = 129.5 € HT`
- Imprimante B : `consoNB = 1800`, `consoCouleur = 0` (offre 2000)
  - Total B = `100 + (0 × 0.05) + (0 × 0.09) = 100 € HT`
- **Total facture** = `129.5 + 100 = 229.5 € HT`

---

## 🔧 Utilisation API

### Format JSON pour génération automatique

**Endpoint :** `POST /API/factures_generer.php`

**Nouveau format (imprimantes) :**
```json
{
  "factureClient": 123,
  "factureDate": "2025-01-15",
  "factureType": "Consommation",
  "offre": 1000,
  "nb_imprimantes": 2,
  "machines": {
    "machine1": {
      "conso_nb": 1500,
      "conso_couleur": 0,
      "nom": "Imprimante A"
    },
    "machine2": {
      "conso_nb": 800,
      "conso_couleur": 100,
      "nom": "Imprimante B"
    }
  }
}
```

**Ancien format (lignes manuelles) - toujours supporté :**
```json
{
  "factureClient": 123,
  "factureDate": "2025-01-15",
  "factureType": "Consommation",
  "lignes": [
    {
      "description": "Service",
      "type": "Service",
      "quantite": 1,
      "prix_unitaire": 100.00,
      "total_ht": 100.00
    }
  ]
}
```

### Paramètres

**Nouveau format :**
- `offre` (int, requis) : `1000` ou `2000`
- `nb_imprimantes` (int, requis) : `1` ou `2`
- `machines` (object, requis) :
  - `machine1` (object, requis) :
    - `conso_nb` (float, requis) : Consommation NB
    - `conso_couleur` (float, requis) : Consommation couleur
    - `nom` (string, optionnel) : Nom de l'imprimante (défaut: "Imprimante A")
  - `machine2` (object, requis si `nb_imprimantes = 2`) :
    - Même structure que `machine1` (défaut nom: "Imprimante B")

---

## 📊 Lignes de facture générées

Pour chaque imprimante, le système génère automatiquement :

1. **Forfait mensuel**
   - Description : `"Forfait mensuel (Offre {offre} copies) - {nom_imprimante}"`
   - Type : `Service`
   - Quantité : `1`
   - Prix unitaire : `100.00 € HT`
   - Total HT : `100.00 €`

2. **Dépassement NB** (si `excessNB > 0`)
   - Description : `"Dépassement NB ({excessNB} copies x 0.05€) - {nom_imprimante}"`
   - Type : `Consommation`
   - Quantité : `excessNB`
   - Prix unitaire : `0.05 € HT`
   - Total HT : `excessNB × 0.05`

3. **Copies couleur** (si `consoCouleur > 0`)
   - Description : `"Copies couleur ({consoCouleur} copies x 0.09€) - {nom_imprimante}"`
   - Type : `Consommation`
   - Quantité : `consoCouleur`
   - Prix unitaire : `0.09 € HT`
   - Total HT : `consoCouleur × 0.09`

---

## 🧮 Service de calcul

**Classe :** `App\Services\InvoiceCalculationService`

### Méthodes principales

#### `calculateMachineInvoice(int $offre, float $consoNB, float $consoCouleur): array`

Calcule le coût d'une imprimante.

**Retourne :**
```php
[
    'forfait_ht' => 100.0,
    'seuil_nb' => 1000, // ou 2000
    'excess_nb' => 500.0,
    'excess_nb_ht' => 25.0,
    'couleur_ht' => 9.0,
    'total_ht_machine' => 134.0,
    'conso_nb' => 1500.0,
    'conso_couleur' => 100.0
]
```

#### `generateInvoiceLinesForMachine(array $calculation, string $machineName, int $offre): array`

Génère les lignes de facture pour une imprimante.

#### `generateAllInvoiceLines(int $offre, int $nbImprimantes, array $machines): array`

Génère toutes les lignes de facture pour 1 ou 2 imprimantes.

#### `calculateInvoiceTotals(array $lignes): array`

Calcule les totaux (HT, TVA, TTC) à partir des lignes.

---

## ✅ Tests

**Fichier :** `tests/test_invoice_calculation.php`

**Exécution :**
```bash
php tests/test_invoice_calculation.php
```

**4 cas de test :**
1. ✅ Offre 1000, 1 imprimante, NB=1500, couleur=0 → 125€ HT
2. ✅ Offre 1000, 1 imprimante, NB=800, couleur=100 → 109€ HT
3. ✅ Offre 2000, 2 imprimantes, A: NB=2500 couleur=50, B: NB=1800 couleur=0 → 229.5€ HT
4. ✅ Offre 2000, 2 imprimantes, A: NB=2000 couleur=100, B: NB=2001 couleur=1 → 209.14€ HT

---

## 📄 PDF

Le PDF généré affiche :
- Un tableau avec toutes les lignes de facture (forfait, dépassement NB, couleur par imprimante)
- Les totaux HT, TVA (20%), TTC

**Format des lignes dans le PDF :**
- Description complète avec nom de l'imprimante
- Type (Service/Consommation)
- Quantité
- Prix unitaire HT
- Total HT

---

## 🔒 Validations

- ✅ Valeurs négatives interdites (lève `InvalidArgumentException`)
- ✅ Offre doit être 1000 ou 2000
- ✅ Nombre d'imprimantes doit être 1 ou 2
- ✅ Cast en float pour PHP 8.3 (évite erreurs `number_format` avec strings)
- ✅ Compatible avec l'ancien format (lignes manuelles)

---

## 📁 Fichiers modifiés/créés

1. **`src/Services/InvoiceCalculationService.php`** (nouveau)
   - Service de calcul réutilisable
   - Méthodes statiques pour calculs et génération de lignes

2. **`API/factures_generer.php`** (modifié)
   - Détection automatique du format (nouveau/ancien)
   - Intégration du service de calcul
   - Correction `number_format` avec cast float

3. **`tests/test_invoice_calculation.php`** (nouveau)
   - Tests unitaires pour valider les calculs

---

## 🔄 Compatibilité

- ✅ **Ancien format supporté** : Les factures avec lignes manuelles continuent de fonctionner
- ✅ **Aucune régression** : Envoi email, logs, PDF, DB inchangés
- ✅ **PHP 8.3** : Compatible avec strict typing

---

## 📝 Exemple complet

**Requête :**
```json
{
  "factureClient": 42,
  "factureDate": "2025-01-15",
  "factureType": "Consommation",
  "offre": 2000,
  "nb_imprimantes": 2,
  "machines": {
    "machine1": {
      "conso_nb": 2500,
      "conso_couleur": 50,
      "nom": "HP LaserJet Pro"
    },
    "machine2": {
      "conso_nb": 1800,
      "conso_couleur": 0,
      "nom": "Canon PIXMA"
    }
  }
}
```

**Lignes générées :**
1. Forfait mensuel (Offre 2000 copies) - HP LaserJet Pro : 100.00€ HT
2. Dépassement NB (500 copies x 0.05€) - HP LaserJet Pro : 25.00€ HT
3. Copies couleur (50 copies x 0.09€) - HP LaserJet Pro : 4.50€ HT
4. Forfait mensuel (Offre 2000 copies) - Canon PIXMA : 100.00€ HT

**Totaux :**
- Total HT : 229.50€
- TVA (20%) : 45.90€
- Total TTC : 275.40€

---

**Version :** 1.0  
**Statut :** ✅ Implémenté et testé

