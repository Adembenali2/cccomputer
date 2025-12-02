# Architecture MVC Légère - CCComputer

## Vue d'ensemble

Ce document décrit la nouvelle architecture MVC légère mise en place progressivement dans le projet CCComputer. Cette architecture est introduite **sans casser** les fonctionnalités existantes.

## Structure

### Dossier `app/`

```
app/
├── Models/          # Modèles de données (entités)
├── Repositories/    # Accès aux données (couche d'abstraction BDD)
└── Services/        # Logique métier (calculs, règles)
```

### Models (`app/Models/`)

Les modèles représentent les entités métier de l'application :

- **Client.php** : Représente un client
- **Photocopieur.php** : Représente un photocopieur
- **Releve.php** : Représente un relevé de compteur

Chaque modèle :
- Contient les propriétés de l'entité
- Fournit des méthodes `fromArray()` et `toArray()` pour la conversion
- Peut contenir des méthodes de logique métier simples

### Repositories (`app/Repositories/`)

Les repositories encapsulent l'accès aux données :

- **ClientRepository.php** : Accès aux données des clients
- **CompteurRepository.php** : Accès aux données des relevés

Chaque repository :
- Reçoit une instance PDO dans le constructeur
- Expose des méthodes de recherche/lecture (`findById()`, `findByMacNorm()`, etc.)
- Retourne des objets Model, pas des tableaux bruts

### Services (`app/Services/`)

Les services contiennent la logique métier complexe :

- **ConsumptionService.php** : Calcul des consommations
- **DebtService.php** : Calcul des dettes

Chaque service :
- Utilise les repositories pour accéder aux données
- Contient la logique de calcul et les règles métier
- Peut utiliser d'autres services

## Utilisation dans les fichiers existants

### Exemple : Utilisation dans `public/photocopieurs_details.php`

**Avant** (code direct) :
```php
$sql = "SELECT * FROM compteur_relevee WHERE mac_norm = :mac";
$stmt = $pdo->prepare($sql);
$stmt->execute([':mac' => $macNorm]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
```

**Après** (avec repository) :
```php
require_once __DIR__ . '/../app/Repositories/CompteurRepository.php';
use App\Repositories\CompteurRepository;

$repository = new CompteurRepository($pdo);
$releves = $repository->findByMacNorm($macNorm);
// $releves est un tableau d'objets Releve
```

### Exemple : Utilisation dans `API/paiements_dettes.php`

**Avant** (logique inline) :
```php
// Calcul direct dans le fichier API
$consumption = $endCounter - $startCounter;
$debt = 20.0 + ($consumption * 0.05);
```

**Après** (avec services) :
```php
require_once __DIR__ . '/../app/Services/ConsumptionService.php';
require_once __DIR__ . '/../app/Services/DebtService.php';
use App\Services\ConsumptionService;
use App\Services\DebtService;

$consumptionService = new ConsumptionService($compteurRepository);
$debtService = new DebtService($consumptionService);

$debt = $debtService->calculateDebtForPeriod($client, $macNorm, $periodStart, $periodEnd);
```

## Migration progressive

### Phase 1 : Structure de base ✅

- [x] Création du dossier `app/`
- [x] Création des dossiers `Models/`, `Repositories/`, `Services/`
- [x] Création des modèles de base (Client, Photocopieur, Releve)
- [x] Création des repositories de base
- [x] Création des services de base

### Phase 2 : Extraction de la logique (en cours)

- [ ] Migration de `public/photocopieurs_details.php` pour utiliser `CompteurRepository`
- [ ] Migration de `API/paiements_dettes.php` pour utiliser `DebtService` et `ConsumptionService`
- [ ] Migration des autres fichiers critiques

### Phase 3 : Tests et documentation

- [ ] Ajout de tests unitaires pour les services
- [ ] Ajout de tests d'intégration pour les repositories
- [ ] Documentation complète de l'API

## Compatibilité

**IMPORTANT** : Cette architecture est introduite **sans casser** l'existant :

- ✅ Les fichiers `public/*.php` continuent d'exister et fonctionnent
- ✅ Les fichiers `API/*.php` continuent d'exister et fonctionnent
- ✅ Les routes/URLs ne changent pas
- ✅ La structure JSON des API ne change pas
- ✅ Le comportement visible pour l'utilisateur ne change pas

## Avantages

1. **Séparation des responsabilités** : La logique métier est isolée des couches de présentation
2. **Testabilité** : Les services et repositories peuvent être testés indépendamment
3. **Maintenabilité** : Le code est mieux organisé et plus facile à maintenir
4. **Réutilisabilité** : Les services peuvent être réutilisés dans plusieurs endroits
5. **Évolutivité** : Facilite l'ajout de nouvelles fonctionnalités

## Prochaines étapes

1. Continuer à extraire la logique métier des fichiers existants
2. Ajouter des tests pour chaque service et repository
3. Documenter chaque service et repository
4. Préparer une migration complète vers une architecture MVC plus complète (si nécessaire)

