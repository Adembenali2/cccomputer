# Résumé des Modifications Techniques - CCComputer

## Vue d'ensemble

Trois blocs techniques majeurs ont été ajoutés au projet CCComputer de manière **progressive, sécurisée et sans casser l'existant** :

1. **Tests automatiques** (PHPUnit + tests API)
2. **Monitoring / Logs avancés** (Monolog + Sentry optionnel)
3. **Architecture MVC légère** (progressive)

---

## 1. Tests Automatiques (PHPUnit + tests API)

### Fichiers créés

#### Configuration
- **`phpunit.xml`** : Configuration PHPUnit avec deux testsuites (Unit, Api)
- **`README_TESTS.md`** : Documentation complète pour lancer les tests

#### Tests unitaires (`tests/Unit/`)
- **`ConsumptionCalculatorTest.php`** : Tests pour le calcul des consommations
  - Calcul noir/blanc
  - Calcul couleur
  - Gestion des compteurs réinitialisés
  - Règle 20→20 (période de facturation)
  
- **`DebtCalculatorTest.php`** : Tests pour le calcul des dettes
  - Calcul avec pack bronze
  - Calcul avec consommation dans les limites
  - Calcul avec copies couleur
  - Gestion des valeurs nulles/zéro

- **`ValidatorTest.php`** : Tests pour la validation des données
  - Validation d'email (valide, invalide, vide)
  - Normalisation email Gmail
  - Validation téléphone français
  - Validation SIRET

#### Tests API (`tests/Api/`)
- **`PaiementsApiTest.php`** : Tests pour les endpoints de paiements/dettes
  - Structure JSON retournée
  - Gestion des paramètres manquants
  - Gestion des client_id invalides

- **`ClientsApiTest.php`** : Tests pour les endpoints clients
  - Structure JSON de recherche
  - Gestion des queries vides/valides

### Ce que les tests vérifient

**Tests unitaires** :
- ✅ Calcul correct des consommations entre deux relevés
- ✅ Règle des périodes (mois du 20 au 20)
- ✅ Calcul des dettes selon le pack (bronze/argent) et les consommations
- ✅ Validation des données (email, téléphone, SIRET)

**Tests API** :
- ✅ Structure JSON correcte des réponses
- ✅ Gestion des erreurs (paramètres manquants, IDs invalides)
- ⚠️ Placeholders pour tests réels (nécessitent base de test ou mocks)

### Lancement des tests

```bash
# Installer les dépendances
composer install

# Lancer tous les tests
./vendor/bin/phpunit

# Tests unitaires uniquement
./vendor/bin/phpunit tests/Unit

# Tests API uniquement
./vendor/bin/phpunit tests/Api
```

---

## 2. Monitoring / Logs Avancés (Monolog + Sentry)

### Fichiers créés

#### Système de logging
- **`includes/Logger.php`** : Classe `AppLogger` centralisée
  - Utilise Monolog pour un système de logs professionnel
  - Supporte plusieurs niveaux : DEBUG, INFO, WARNING, ERROR, CRITICAL
  - Rotation automatique des logs (30 jours)
  - Fichiers séparés : `logs/app.log` (tous) et `logs/error.log` (erreurs uniquement)
  - Intégration Sentry optionnelle

- **`includes/ErrorHandler.php`** : Gestionnaire d'erreurs global
  - Capture les exceptions non gérées
  - Capture les erreurs PHP (warnings, notices, etc.)
  - Capture les erreurs fatales (shutdown)
  - Logging automatique via Monolog
  - Réponses JSON pour les API, HTML pour les pages

- **`config/sentry.php`** : Configuration Sentry (optionnelle)
  - DSN configurable via variable d'environnement ou fichier
  - Activation uniquement si DSN renseignée

### Points d'intégration principaux

**Déjà intégré** :
- ✅ `includes/db.php` : Logs de connexion DB (debug) et erreurs (critical)
- ✅ `includes/api_helpers.php` : Chargement du logger si disponible
- ✅ `index.php` : Enregistrement du gestionnaire d'erreurs global

**À intégrer progressivement** :
- Remplacement des `error_log()` dans :
  - `API/*.php` (erreurs API)
  - `public/*.php` (erreurs critiques)
  - `includes/*.php` (erreurs système)

### Intégration Sentry

**Configuration** :
1. Option 1 : Variable d'environnement
   ```bash
   export SENTRY_DSN="https://xxxxx@xxxxx.ingest.sentry.io/xxxxx"
   ```

2. Option 2 : Fichier de configuration
   ```php
   // config/sentry.php
   return [
       'dsn' => 'https://xxxxx@xxxxx.ingest.sentry.io/xxxxx',
   ];
   ```

**Activation** :
- Sentry s'active automatiquement si la DSN est renseignée
- Envoie uniquement les erreurs de niveau ERROR et CRITICAL
- Taux d'échantillonnage : 10% des transactions (configurable)

### Utilisation

```php
// Dans n'importe quel fichier PHP
require_once __DIR__ . '/includes/Logger.php';

// Logs simples
AppLogger::info('Client créé', ['client_id' => 123]);
AppLogger::warning('Stock faible', ['product_id' => 456]);
AppLogger::error('Erreur API', ['endpoint' => '/API/test.php']);

// Log d'exception
try {
    // code...
} catch (\Exception $e) {
    AppLogger::exception($e, ['context' => 'additional info']);
}
```

---

## 3. Architecture MVC Légère (Progressive)

### Structure créée

```
app/
├── Models/              # Modèles de données
│   ├── Client.php
│   ├── Photocopieur.php
│   └── Releve.php
├── Repositories/        # Accès aux données
│   ├── ClientRepository.php
│   └── CompteurRepository.php
└── Services/           # Logique métier
    ├── ConsumptionService.php
    └── DebtService.php
```

### Modèles (`app/Models/`)

**Client.php** :
- Représente un client avec toutes ses propriétés
- Méthodes `fromArray()` et `toArray()` pour conversion
- Type-safe avec déclarations de types strictes

**Photocopieur.php** :
- Représente un photocopieur
- Gère MAC normalisée, SerialNumber, Model, etc.

**Releve.php** :
- Représente un relevé de compteur
- Méthodes statiques pour calculer les consommations
- Supporte les deux sources : `compteur_relevee` et `compteur_relevee_ancien`

### Repositories (`app/Repositories/`)

**ClientRepository.php** :
- `findById(int $id)` : Trouve un client par ID
- `findByNumero(string $numero)` : Trouve par numéro client
- `search(string $query, int $limit)` : Recherche de clients
- `findAll(int $limit)` : Liste tous les clients

**CompteurRepository.php** :
- `findByMacNorm(string $macNorm)` : Tous les relevés pour une MAC
- `findByMacNormAndPeriod(...)` : Relevés dans une période
- `findPeriodStartCounter(...)` : Compteur de départ (règle 20→20)
- `findPeriodEndCounter(...)` : Compteur de fin

### Services (`app/Services/`)

**ConsumptionService.php** :
- `calculateConsumptionForPeriod(...)` : Calcul consommation sur période
- `calculateConsumptionBetweenReleves(...)` : Calcul entre deux relevés
- Utilise `CompteurRepository` pour accéder aux données

**DebtService.php** :
- `calculateDebtForPeriod(...)` : Calcul dette pour période
- Gère les tarifs pack bronze/argent
- Utilise `ConsumptionService` pour les calculs de consommation

### Fonctionnalités extraites

**Photocopieurs / Relevés** :
- ✅ Recherche de relevés par MAC normalisée
- ✅ Recherche de relevés par période (20→20)
- ✅ Calcul des consommations (noir/blanc, couleur)
- ✅ Gestion des deux tables (`compteur_relevee` et `compteur_relevee_ancien`)

**Paiements / Dettes** :
- ✅ Calcul des consommations sur période
- ✅ Calcul des dettes selon pack et consommations
- ✅ Gestion des tarifs (pack bronze/argent)

### Utilisation dans les fichiers existants

**Exemple d'utilisation** (à intégrer progressivement) :

```php
// Dans public/photocopieurs_details.php ou API/paiements_dettes.php

require_once __DIR__ . '/../app/Repositories/CompteurRepository.php';
require_once __DIR__ . '/../app/Services/ConsumptionService.php';
require_once __DIR__ . '/../app/Services/DebtService.php';

use App\Repositories\CompteurRepository;
use App\Services\ConsumptionService;
use App\Services\DebtService;

// Utilisation
$repository = new CompteurRepository($pdo);
$consumptionService = new ConsumptionService($repository);
$debtService = new DebtService($consumptionService);

// Calcul de dette
$debt = $debtService->calculateDebtForPeriod($client, $macNorm, $periodStart, $periodEnd);
```

### Documentation

- **`docs/ARCHITECTURE_MVC_LIGHT.md`** : Documentation complète de l'architecture
  - Structure détaillée
  - Exemples d'utilisation
  - Plan de migration progressive
  - Avantages et prochaines étapes

---

## 4. Garanties

### ✅ Style visuel
- **Aucun changement** dans les fichiers CSS, HTML, ou templates
- Le design reste identique

### ✅ Fonctionnalités visibles
- **Aucun changement** dans le comportement utilisateur
- Les mêmes pages, mêmes boutons, mêmes actions
- Aucune nouvelle fonctionnalité visible ajoutée

### ✅ Routes / URLs / Endpoints
- **Aucun changement** dans les routes existantes
- Les fichiers `public/*.php` et `API/*.php` continuent d'exister
- Les paramètres GET/POST attendus ne changent pas
- La structure JSON des API ne change pas

### ✅ Compatibilité
- **100% compatible** avec l'existant
- Les nouveaux fichiers (`app/`, `tests/`, `includes/Logger.php`) sont optionnels
- Le site fonctionne même si ces fichiers ne sont pas utilisés immédiatement

---

## 5. Fichiers modifiés / créés

### Nouveaux fichiers

**Tests** :
- `phpunit.xml`
- `tests/Unit/ConsumptionCalculatorTest.php`
- `tests/Unit/DebtCalculatorTest.php`
- `tests/Unit/ValidatorTest.php`
- `tests/Api/PaiementsApiTest.php`
- `tests/Api/ClientsApiTest.php`
- `README_TESTS.md`

**Logging** :
- `includes/Logger.php`
- `includes/ErrorHandler.php`
- `config/sentry.php`

**Architecture MVC** :
- `app/Models/Client.php`
- `app/Models/Photocopieur.php`
- `app/Models/Releve.php`
- `app/Repositories/ClientRepository.php`
- `app/Repositories/CompteurRepository.php`
- `app/Services/ConsumptionService.php`
- `app/Services/DebtService.php`
- `docs/ARCHITECTURE_MVC_LIGHT.md`

**Configuration** :
- `composer.json` (mis à jour avec Monolog, Sentry, PHPUnit)
- `.gitignore` (mis à jour)

### Fichiers modifiés

- `composer.json` : Ajout des dépendances (Monolog, Sentry, PHPUnit)
- `includes/api_helpers.php` : Chargement du logger si disponible
- `index.php` : Enregistrement du gestionnaire d'erreurs (si disponible)

---

## 6. Prochaines étapes recommandées

### Tests
1. Compléter les tests API avec un client HTTP réel ou mock
2. Ajouter des tests d'intégration pour les repositories
3. Configurer une base de données de test dédiée

### Logging
1. Remplacer progressivement les `error_log()` restants par `AppLogger`
2. Configurer Sentry en production (si souhaité)
3. Monitorer les logs pour identifier les erreurs récurrentes

### Architecture MVC
1. Migrer `public/photocopieurs_details.php` pour utiliser `CompteurRepository`
2. Migrer `API/paiements_dettes.php` pour utiliser `DebtService`
3. Extraire d'autres fonctionnalités progressivement

---

## 7. Installation

### 1. Installer les dépendances

```bash
composer install
```

Cela installera :
- Monolog (logging)
- Sentry (monitoring optionnel)
- PHPUnit (tests)

### 2. Créer le dossier logs

```bash
mkdir -p logs
chmod 755 logs
```

### 3. (Optionnel) Configurer Sentry

Créer `config/sentry.php` ou définir la variable d'environnement `SENTRY_DSN`.

### 4. Lancer les tests

```bash
./vendor/bin/phpunit
```

---

## Conclusion

Les trois blocs techniques ont été ajoutés avec succès :

✅ **Tests automatiques** : Base solide de tests unitaires et API  
✅ **Monitoring / Logs** : Système professionnel avec Monolog + Sentry optionnel  
✅ **Architecture MVC légère** : Structure progressive pour améliorer la maintenabilité  

**Tout est prêt pour une utilisation progressive, sans casser l'existant.**

