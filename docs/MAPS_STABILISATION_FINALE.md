# Stabilisation finale – Page Maps

## 1. addClientToRoute – Retours booléens cohérents

### AVANT
```javascript
async function addClientToRoute(client) {
    if (!client) return;

    if (selectedClients.find(s => s.id === client.id)) {
        // ...
        return;
    }

    if (!isValidCoordinate(client.lat, client.lng)) {
        const clientWithCoords = await loadClientWithGeocode(client);
        if (!clientWithCoords || !isValidCoordinate(...)) {
            // ...
            return;
        }
        client = clientWithCoords;
    }
    // ...
    return added;
}
```

### APRÈS (maps.php L.997-1051)
```javascript
async function addClientToRoute(client) {
    try {
        if (!client) return false;

        if (selectedClients.find(s => s.id === client.id)) {
            if (clientSearchInput) clientSearchInput.value = '';
            if (clientResultsEl) { clientResultsEl.innerHTML = ''; clientResultsEl.style.display = 'none'; }
            return false;
        }

        if (!isValidCoordinate(client.lat, client.lng)) {
            // ...
            const clientWithCoords = await loadClientWithGeocode(client);
            if (!clientWithCoords || !isValidCoordinate(clientWithCoords.lat, clientWithCoords.lng)) {
                // ...
                return false;
            }
            client = clientWithCoords;
        }
        // ... logique d'ajout ...
        return !!added;
    } catch (err) {
        console.error('addClientToRoute error:', err);
        return false;
    }
}
```

**Résumé des retours :**
| Cas | Retour |
|-----|--------|
| `!client` | `false` |
| Client déjà dans la tournée | `false` |
| Géocodage impossible | `false` |
| Exception | `false` |
| Succès | `true` (via `!!added`) |

---

## 2. isValidCoordinate – Une seule source de vérité

### maps.php (L.1074-1084)

**AVANT :**
```javascript
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= CONFIG.COORDINATE_BOUNDS.LAT_MIN && 
           lat <= CONFIG.COORDINATE_BOUNDS.LAT_MAX &&
           lng >= CONFIG.COORDINATE_BOUNDS.LNG_MIN && 
           lng <= CONFIG.COORDINATE_BOUNDS.LNG_MAX;
}
```

**APRÈS :**
```javascript
// Fonction utilitaire pour valider les coordonnées (source de vérité unique, exposée globalement)
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= CONFIG.COORDINATE_BOUNDS.LAT_MIN && 
           lat <= CONFIG.COORDINATE_BOUNDS.LAT_MAX &&
           lng >= CONFIG.COORDINATE_BOUNDS.LNG_MIN && 
           lng <= CONFIG.COORDINATE_BOUNDS.LNG_MAX;
}
window.isValidCoordinate = isValidCoordinate;
```

### maps-enhancements.js (L.20-30)

**AVANT :**
```javascript
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}
```

**APRÈS :**
```javascript
/**
 * Utilise window.isValidCoordinate (défini par maps.php) si disponible, sinon fallback local.
 * Évite la duplication : maps.php est la source de vérité.
 */
function isValidCoordinate(lat, lng) {
    if (typeof window.isValidCoordinate === 'function') {
        return window.isValidCoordinate(lat, lng);
    }
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) return false;
    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}
```

**Stratégie :** maps.php définit et expose `window.isValidCoordinate`. maps-enhancements délègue à cette fonction si elle existe, sinon utilise un fallback. maps.php est chargé après maps-enhancements et écrase la définition globale, donc au runtime c’est toujours la version maps.php qui est utilisée.

---

## 3. Vérification des appelants

| Appelant | Fichier | Utilise le retour ? | Impact |
|----------|---------|---------------------|--------|
| Wrapper maps-enhancements | L.477-489 | Oui : `result !== false` | OK : `false` empêche la sauvegarde, `true` la déclenche |
| `handleClick` (résultats recherche) | maps.php L.1222 | Non : `addClientToRoute(client)` | Aucun |
| Clic sur `client-result-item` | maps.php L.1224 | Non | Aucun |

Le wrapper est le seul à tester le retour. `result !== false` est correct : `true` → sauvegarde, `false` → pas de sauvegarde.

---

## 4. Blocs modifiés (seulement)

### maps.php L.997-1051
- `return` → `return false` (3 cas)
- Bloc `try/catch` avec `return false` en cas d’erreur
- `return added` → `return !!added` pour garantir un booléen

### maps.php L.1074-1084
- Ajout de `window.isValidCoordinate = isValidCoordinate;`

### maps-enhancements.js L.20-30
- `isValidCoordinate` remplacée par une version qui délègue à `window.isValidCoordinate` si elle existe, sinon fallback local

---

## 5. Vérification finale

### addClientToRoute retourne-t-elle toujours true/false ?
- Oui. Tous les chemins retournent : `return false`, `return false`, `return false`, `return !!added` ou `return false` dans le catch.

### Combien de définitions actives de isValidCoordinate au runtime ?
- Une seule. maps-enhancements est chargé en premier, puis maps.php définit `isValidCoordinate` et l’assigne à `window.isValidCoordinate`. maps.php écrase la définition globale, donc au runtime c’est uniquement la version maps.php qui est utilisée.

### Qui est la source de vérité finale ?
- maps.php. La fonction définie dans maps.php (L.1075-1083) utilise `CONFIG.COORDINATE_BOUNDS` et est la seule utilisée au runtime. maps-enhancements ne sert que de fallback si maps.php n’est pas chargé.
