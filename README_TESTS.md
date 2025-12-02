# Tests Automatiques - CCComputer

## Installation

Les dépendances de test sont installées via Composer :

```bash
composer install
```

Cela installera PHPUnit dans `vendor/bin/phpunit`.

## Structure des tests

```
tests/
├── Unit/           # Tests unitaires (logique pure)
│   ├── ConsumptionCalculatorTest.php
│   ├── DebtCalculatorTest.php
│   └── ValidatorTest.php
└── Api/            # Tests API (endpoints)
    ├── PaiementsApiTest.php
    └── ClientsApiTest.php
```

## Exécution des tests

### Tous les tests

```bash
./vendor/bin/phpunit
```

### Tests unitaires uniquement

```bash
./vendor/bin/phpunit tests/Unit
```

### Tests API uniquement

```bash
./vendor/bin/phpunit tests/Api
```

### Un test spécifique

```bash
./vendor/bin/phpunit tests/Unit/ConsumptionCalculatorTest.php
```

### Avec couverture de code

```bash
./vendor/bin/phpunit --coverage-html coverage/
```

## Types de tests

### Tests unitaires

Les tests unitaires vérifient la logique métier isolée :

- **ConsumptionCalculatorTest** : Calcul des consommations (noir/blanc, couleur)
- **DebtCalculatorTest** : Calcul des dettes clients
- **ValidatorTest** : Validation des données (email, téléphone, SIRET)

### Tests API

Les tests API vérifient les endpoints :

- **PaiementsApiTest** : Endpoints de paiements/dettes
- **ClientsApiTest** : Endpoints de recherche de clients

**Note** : Les tests API nécessitent une base de données de test configurée. Pour l'instant, ce sont des placeholders qui peuvent être complétés avec un client HTTP mock.

## Configuration

La configuration PHPUnit se trouve dans `phpunit.xml` à la racine du projet.

## Environnement de test

Les tests s'exécutent dans un environnement isolé défini par la variable `APP_ENV=testing` dans `phpunit.xml`.

## Prochaines étapes

1. Compléter les tests API avec un client HTTP réel ou mock
2. Ajouter des tests d'intégration pour les repositories
3. Ajouter des tests pour les services
4. Configurer une base de données de test dédiée

