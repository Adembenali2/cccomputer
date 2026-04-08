# Audit final des erreurs – Page Maps

## 1. Liste complète des corrections

### Erreurs JS / Code mort

| # | Fichier | Bloc | Gravité | Risque | Correction |
|---|---------|------|---------|--------|------------|
| 1 | maps.php L.625-631 | `createPriorityIcon()` | Faible | Aucun | Supprimer (jamais appelée) |
| 2 | maps-enhancements.js L.396-406 | `initCollapsibleSections()` | Faible | Aucun | Supprimer l'appel (aucun `[data-section]` en HTML) |
| 3 | maps-enhancements.js L.313-325 | `updateGeocodeProgress()` | Faible | Aucun | Supprimer (jamais appelée) |

### Listeners doublés

| # | Fichier | Élément | Gravité | Risque | Correction |
|---|---------|---------|---------|--------|------------|
| 4 | maps.php L.1855-1869 + maps-enhancements L.393-407 | `#clientSearchClear` click + `#clientSearch` input | Moyenne | Double exécution au click/input | Supprimer le bloc dans maps-enhancements (maps.php garde la logique) |
| 5 | maps.php L.1797-1852 + maps-enhancements L.371-375 | `#mapFilters` checkboxes | Critique | Désynchronisation activeFilters vs FilterManager.activeFilters | Supprimer FilterManager.init() (garder uniquement inline) |

### Conflits maps.php / maps-enhancements.js

| # | Fichier | Problème | Gravité | Risque | Correction |
|---|---------|----------|---------|--------|------------|
| 6 | Les deux | `escapeHtml` défini 2 fois | Faible | Écrasement silencieux | Garder maps-enhancements (chargé en 1er), supprimer maps.php |
| 7 | Les deux | `isValidCoordinate` défini 2 fois | Faible | maps.php utilise CONFIG, maps-enhancements constantes en dur | Garder maps.php (plus précis), maps-enhancements utilise celui de maps.php après chargement. Ne pas modifier pour éviter régression. |

### Variables globales dangereuses

| # | Fichier | Variable | Gravité | Risque | Correction |
|---|---------|----------|---------|--------|------------|
| 8 | maps.php L.344-367 | Références DOM au chargement | Moyenne | Null si script avant DOM | Encapsuler dans DOMContentLoaded ou vérifier null avant usage |
| 9 | maps.php L.863 | `document.getElementById('badgeClients')` | Faible | Null si ID absent | Ajouter vérification null |

### Fonctions dupliquées

| # | Fichier | Fonction | Gravité | Correction |
|---|---------|----------|---------|------------|
| 10 | maps.php L.855-859 | `escapeHtml` | Faible | Supprimer, utiliser celle de maps-enhancements (déjà chargé avant) |

### Incohérences DOM / robustesse

| # | Fichier | Problème | Gravité | Correction |
|---|---------|----------|---------|------------|
| 11 | maps.php | `routeMessageEl`, `startInfoEl` etc. utilisés sans vérification | Moyenne | Ajouter garde-fous dans les fonctions qui les utilisent |
| 12 | maps.php L.863 | `updateClientsBadge` appelle getElementById sans null check | Faible | Ajouter `if (el) el.textContent = ...` |

### Retours de fonctions

| # | Fichier | Fonction | Gravité | Correction |
|---|---------|----------|---------|------------|
| 13 | maps.php L.989 | `addClientToRoute` ne retourne pas explicitement | Faible | Retourner `true` en cas de succès pour cohérence avec le wrapper |

### Problèmes de maintenabilité

| # | Fichier | Problème | Gravité | Correction |
|---|---------|----------|---------|------------|
| 14 | maps-enhancements | FilterManager reste défini mais init() supprimé | Faible | Garder FilterManager.applyFilters() pour usage futur, ne plus appeler init() |

---

## 2. Plan de correction (ordre d'exécution)

**Phase 1 – Sûr, sans impact fonctionnel**
1. Supprimer `createPriorityIcon` (maps.php)
2. Supprimer `updateGeocodeProgress` (maps-enhancements)
3. Supprimer l'appel à `initCollapsibleSections` (maps-enhancements)

**Phase 2 – Résolution des conflits**
4. Supprimer le bloc clientSearchClear/clientSearch dans maps-enhancements (éviter doublons)
5. Supprimer l'appel à `FilterManager.init()` dans maps-enhancements (garder uniquement les filtres inline)

**Phase 3 – Nettoyage et robustesse**
6. Supprimer `escapeHtml` de maps.php (utiliser celle de maps-enhancements)
7. Sécuriser `updateClientsBadge` avec null check
8. Ajouter `return true` à la fin de `addClientToRoute` en cas de succès

**Phase 4 – Vérifications optionnelles**
9. Ajouter des garde-fous sur les éléments DOM critiques dans les fonctions sensibles
