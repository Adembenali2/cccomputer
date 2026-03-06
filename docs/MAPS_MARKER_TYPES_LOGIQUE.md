# Logique des types de marqueurs – Maps

## Résumé

Une **source de vérité unique** pour les types de marqueurs et leurs couleurs a été mise en place. La logique est cohérente sur toute la page Maps.

---

## Logique appliquée

| Type     | Condition                    | Couleur | Label              |
|----------|------------------------------|---------|--------------------|
| `both`   | SAV et livraison             | Rouge   | SAV + Livraison    |
| `sav`    | SAV uniquement               | Jaune   | SAV en cours       |
| `delivery`| Livraison uniquement        | Bleu    | Livraison en cours |
| `normal` | Ni SAV ni livraison          | Vert    | Client normal      |

**Ordre de priorité :** both > sav > delivery > normal

---

## Propriétés client utilisées

- **`hasSav`** : booléen – client avec au moins un SAV actif (non résolu, non annulé)
- **`hasLivraison`** : booléen – client avec au moins une livraison active (non livrée, non annulée)

Origine en base :
- `has_livraison` : `COUNT(*)` des livraisons avec `statut NOT IN ('livree', 'annulee')`
- `has_sav` : `COUNT(*)` des SAV avec `statut NOT IN ('resolu', 'annule')`

---

## Source de vérité unique

### 1. `MARKER_TYPES` (maps.php)

```javascript
const MARKER_TYPES = {
    normal: { color: '#16a34a', label: 'Client normal' },
    delivery: { color: '#3b82f6', label: 'Livraison en cours' },
    sav: { color: '#eab308', label: 'SAV en cours' },
    both: { color: '#ef4444', label: 'SAV + Livraison' }
};
```

### 2. `computeMarkerType(client)` (maps.php)

Calcule le type à partir de `hasSav` et `hasLivraison` :

```javascript
function computeMarkerType(client) {
    if (!client) return 'normal';
    const hasSav = !!client.hasSav;
    const hasLivraison = !!client.hasLivraison;
    if (hasSav && hasLivraison) return 'both';
    if (hasSav) return 'sav';
    if (hasLivraison) return 'delivery';
    return 'normal';
}
```

### 3. `getMarkerColor(markerType)` (maps.php)

Retourne la couleur depuis `MARKER_TYPES`. Gère `livraison` comme alias de `delivery` pour compatibilité.

---

## Où la logique est utilisée

| Contexte                    | Utilisation                                      |
|----------------------------|--------------------------------------------------|
| Chargement initial         | `addClientToMap` → `computeMarkerType`           |
| Recherche                  | Résultats avec point coloré via `computeMarkerType` |
| Ajout à la tournée         | `addClientToRoute` → `addClientToMap`            |
| Après géocodage            | `updatedClient` conserve `hasSav`/`hasLivraison` |
| Marqueurs sur la carte     | `createMarkerIcon(computeMarkerType(client))`     |
| Popups                     | Couleur et label depuis `MARKER_TYPES`           |
| Clients sélectionnés       | Bordure colorée via `--chip-accent`              |
| Filtres                    | `hasSav`, `hasLivraison` (clés `sav`, `livraison`)|
| Légende                    | Générée depuis `MARKER_TYPES`                    |

---

## Fichiers modifiés

| Fichier                    | Modifications |
|----------------------------|---------------|
| `public/maps.php`         | `MARKER_TYPES`, `computeMarkerType`, `getMarkerColor`, légende dynamique, `addClientToMap`, `renderSelectedClients`, popups, résultats recherche, chips |
| `assets/css/maps.css`      | `.client-result-dot`, `.client-result-text`, `.selected-client-chip` avec `--chip-accent` |
| `API/maps_get_all_clients.php` | `markerType` : `livraison` → `delivery` |
| `API/maps_search_clients.php`  | `markerType` : `livraison` → `delivery` |

---

## Diff synthétique

### maps.php
- Ajout de `MARKER_TYPES` et `computeMarkerType`
- `getMarkerColor` basé sur `MARKER_TYPES`, alias `livraison` → `delivery`
- Légende générée dynamiquement depuis `MARKER_TYPES`
- `addClientToMap` : utilisation de `computeMarkerType`
- Popups : couleur et label depuis `MARKER_TYPES`
- Résultats recherche : point coloré par client
- Chips sélectionnés : `--chip-accent` selon le type

### maps.css
- `.client-result-dot` et `.client-result-text` pour les résultats
- `.selected-client-chip::before` utilise `var(--chip-accent)`

### APIs
- `markerType` : `livraison` remplacé par `delivery`
