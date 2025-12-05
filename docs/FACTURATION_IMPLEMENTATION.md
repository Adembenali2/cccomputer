# Implémentation de la Facturation - Connexion à la Base de Données

## 📋 Vue d'ensemble

Cette documentation décrit l'implémentation de la connexion de l'interface de facturation/paiements/consommation à la base de données réelle, remplaçant les données mock par des données réelles provenant des tables `compteur_relevee`, `compteur_relevee_ancien`, `clients` et `photocopieurs_clients`.

## 🏗️ Architecture

### Structure des fichiers créés/modifiés

```
app/
  Services/
    BillingService.php          # Service principal pour la facturation
  Repositories/
    CompteurRepository.php      # (existant) Accès aux relevés
    ClientRepository.php        # (existant) Accès aux clients

API/
  facturation_consumption_chart.php    # Endpoint pour le graphique
  facturation_consumption_table.php    # Endpoint pour le tableau
  facturation_invoice.php              # Endpoint pour la facture
  facturation_search_clients.php        # Endpoint pour la recherche clients
  includes/
    paiements_helpers.php               # (existant) Fonctions de calcul

public/
  facturation.php                       # (modifié) Interface frontend
```

## 🔑 Composants principaux

### 1. BillingService (`app/Services/BillingService.php`)

Service principal qui orchestre les calculs de consommation et la génération des données pour la facturation.

**Méthodes principales :**

- `getConsumptionChartData()` : Récupère les données pour le graphique de consommation
- `getConsumptionTableData()` : Récupère les données pour le tableau de consommation
- `getConsumptionInvoiceData()` : Récupère les données pour une facture de consommation

**Logique de calcul :**

Le service utilise les fonctions helper de `API/includes/paiements_helpers.php` qui implémentent la logique de facturation 20→20 :

- **Premier mois facturé** : `conso = compteur du 20 - premier compteur de la vie de la machine`
- **Mois suivants** : `conso du mois N = compteur du 20 de mois N - compteur du 20 de mois N-1`

Les compteurs sont recherchés dans les deux tables (`compteur_relevee` et `compteur_relevee_ancien`) via UNION ALL.

### 2. Endpoints API

#### `facturation_consumption_chart.php`

**GET** `/API/facturation_consumption_chart.php`

**Paramètres :**
- `client_id` (int, optionnel) : ID du client (null pour tous les clients)
- `granularity` (string) : 'year' ou 'month'
- `year` (int) : Année
- `month` (int, optionnel) : Mois (0-11) si granularity = 'month'

**Réponse :**
```json
{
  "ok": true,
  "data": {
    "labels": ["Jan 2025", "Fév 2025", ...],
    "nbData": [1000, 1200, ...],
    "colorData": [200, 250, ...],
    "totalData": [1200, 1450, ...]
  }
}
```

#### `facturation_consumption_table.php`

**GET** `/API/facturation_consumption_table.php`

**Paramètres :**
- `client_id` (int, optionnel) : ID du client
- `months` (int, optionnel) : Nombre de mois à afficher (défaut: 3)

**Réponse :**
```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "nom": "HP LaserJet Pro",
      "modele": "M404dn",
      "macAddress": "AB:CD:EF:12:34:56",
      "consommations": [
        {
          "mois": "2025-01",
          "periode": "20/01 → 20/02",
          "pagesNB": 8750,
          "pagesCouleur": 0,
          "totalPages": 8750
        }
      ]
    }
  ]
}
```

#### `facturation_invoice.php`

**GET** `/API/facturation_invoice.php`

**Paramètres :**
- `client_id` (int, requis) : ID du client
- `period_start` (string, requis) : Date de début (Y-m-d, 20 du mois)
- `period_end` (string, requis) : Date de fin (Y-m-d, 20 du mois suivant)

**Réponse :**
```json
{
  "ok": true,
  "data": {
    "client": {...},
    "period": {
      "start": "2025-01-20",
      "end": "2025-02-20",
      "label": "20/01 → 20/02"
    },
    "lignes": [
      {
        "photocopieur": {
          "nom": "HP LaserJet Pro",
          "modele": "M404dn",
          "mac": "AB:CD:EF:12:34:56"
        },
        "nb": 8750,
        "color": 0,
        "total": 8750
      }
    ],
    "total": {
      "nb": 8750,
      "color": 0,
      "total": 8750
    }
  }
}
```

#### `facturation_search_clients.php`

**GET** `/API/facturation_search_clients.php`

**Paramètres :**
- `q` (string, requis) : Terme de recherche
- `limit` (int, optionnel) : Nombre de résultats (défaut: 10)

**Réponse :**
```json
{
  "ok": true,
  "data": [
    {
      "id": 1,
      "name": "ACME Industries",
      "raison_sociale": "ACME Industries",
      "numero_client": "CLI-0001",
      "prenom": "Jean",
      "nom": "Dupont"
    }
  ]
}
```

### 3. Modifications Frontend (`public/facturation.php`)

Les fonctions suivantes ont été modifiées pour utiliser les vrais endpoints :

- `performClientSearch()` : Utilise `/API/facturation_search_clients.php`
- `initConsumptionChart()` : Utilise `/API/facturation_consumption_chart.php`
- `updateTableConsommation()` : Utilise `/API/facturation_consumption_table.php`
- `updateFactureEnCours()` : Utilise `/API/facturation_invoice.php`

**Changements principaux :**

- Remplacement des appels aux données mock par des `fetch()` vers les endpoints API
- Gestion des erreurs avec try/catch
- Affichage d'indicateurs de chargement
- Conservation de la logique d'affichage existante

## 🔄 Flux de données

### Graphique de consommation

1. L'utilisateur sélectionne une granularité (année/mois) et une période
2. Le frontend appelle `/API/facturation_consumption_chart.php` avec les paramètres
3. `BillingService::getConsumptionChartData()` :
   - Récupère les MAC des photocopieurs du client (ou tous)
   - Calcule les périodes selon la granularité
   - Pour chaque période, calcule la consommation totale (toutes les MAC)
   - Retourne les données formatées pour Chart.js

### Tableau de consommation

1. L'utilisateur ouvre l'onglet "Consommation"
2. Le frontend appelle `/API/facturation_consumption_table.php`
3. `BillingService::getConsumptionTableData()` :
   - Récupère les photocopieurs du client
   - Calcule les 3 dernières périodes 20→20
   - Pour chaque photocopieur, calcule les consommations pour chaque période
   - Retourne les données formatées pour le tableau

### Facture de consommation

1. L'utilisateur sélectionne un client
2. Le frontend appelle `/API/facturation_invoice.php` avec la période courante
3. `BillingService::getConsumptionInvoiceData()` :
   - Récupère le client et ses photocopieurs
   - Calcule la consommation pour chaque photocopieur sur la période
   - Retourne les données formatées pour la facture

## 📊 Logique de calcul des consommations

### Recherche des compteurs

Les compteurs sont recherchés dans les deux tables (`compteur_relevee` et `compteur_relevee_ancien`) via UNION ALL, unifiés par `mac_norm`.

### Période de facturation (20→20)

- **Début de période** : 20 du mois à 00:00:00
- **Fin de période** : 20 du mois suivant à 00:00:00

### Recherche du compteur de départ

1. Chercher le compteur exactement du 20 (jour entier)
2. Si pas trouvé, chercher le premier compteur après le 20
3. Si pas trouvé, chercher le dernier compteur avant le 20

### Recherche du compteur de fin

1. Chercher le compteur exactement du 20 suivant (jour entier)
2. Si pas trouvé, chercher le dernier compteur avant ou égal au 20 suivant

### Calcul de la consommation

```php
conso_bw = max(0, compteur_fin->TotalBW - compteur_debut->TotalBW)
conso_color = max(0, compteur_fin->TotalColor - compteur_debut->TotalColor)
```

### Premier mois vs mois suivants

- **Premier mois** : Le compteur de départ est le premier compteur de la vie de la machine (dans les deux tables)
- **Mois suivants** : Le compteur de départ est le compteur du 20 du mois précédent

Cette logique est gérée automatiquement par les fonctions `getPeriodStartCounter()` et `getPeriodEndCounter()` dans `API/includes/paiements_helpers.php`.

## 🔗 Relations entre tables

```
clients (id)
  └── photocopieurs_clients (id_client, mac_norm)
        └── compteur_relevee (mac_norm, Timestamp, TotalBW, TotalColor)
        └── compteur_relevee_ancien (mac_norm, Timestamp, TotalBW, TotalColor)
```

Les photocopieurs sont liés aux clients via `photocopieurs_clients.mac_norm`, et les compteurs sont recherchés par `mac_norm` dans les deux tables de relevés.

## ⚠️ Points d'attention

1. **Normalisation MAC** : Les MAC sont normalisées (suppression des `:`, majuscules) via la colonne générée `mac_norm`
2. **Premier compteur** : Pour le premier mois, on cherche le premier compteur de la vie de la machine dans les deux tables
3. **Périodes sans relevé** : Si aucun compteur n'est trouvé pour une période, la consommation est 0
4. **Performance** : Les requêtes utilisent des index sur `mac_norm` et `Timestamp` pour optimiser les performances

## 🚀 Utilisation

### Test des endpoints

```bash
# Graphique de consommation (tous les clients, année 2025)
curl "http://localhost/API/facturation_consumption_chart.php?granularity=year&year=2025"

# Graphique pour un client spécifique
curl "http://localhost/API/facturation_consumption_chart.php?granularity=month&year=2025&month=0&client_id=1"

# Tableau de consommation
curl "http://localhost/API/facturation_consumption_table.php?months=3&client_id=1"

# Facture de consommation
curl "http://localhost/API/facturation_invoice.php?client_id=1&period_start=2025-01-20&period_end=2025-02-20"

# Recherche de clients
curl "http://localhost/API/facturation_search_clients.php?q=ACME&limit=10"
```

### Interface utilisateur

1. Ouvrir `/public/facturation.php`
2. Rechercher un client dans la barre de recherche
3. Sélectionner un client pour voir ses données
4. Utiliser les contrôles de granularité pour changer la période du graphique
5. Consulter l'onglet "Consommation" pour voir le détail par imprimante
6. Consulter l'onglet "Résumé" pour voir la facture en cours

## 📝 Notes de développement

- Les données mock sont toujours présentes dans le code mais ne sont plus utilisées
- Les fonctions async/await sont utilisées pour les appels API
- La gestion d'erreur affiche des messages appropriés à l'utilisateur
- Les indicateurs de chargement améliorent l'expérience utilisateur

## 🔧 Améliorations futures possibles

1. **Cache** : Mettre en cache les résultats des calculs de consommation pour améliorer les performances
2. **Tarification** : Intégrer les tarifs réels (N&B et couleur) dans le calcul des montants de facture
3. **Export PDF** : Générer des factures PDF à partir des données de consommation
4. **Historique** : Stocker les factures générées dans une table dédiée
5. **Notifications** : Notifier les clients lorsque leur facture est prête

