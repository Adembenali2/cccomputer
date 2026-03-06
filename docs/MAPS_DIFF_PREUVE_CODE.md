# Preuve concrète des modifications – Page Maps

Document basé sur le code final. Références exactes aux lignes.

---

## 1. public/maps.php

### 1.1 Code supprimé : `createPriorityIcon`

**AVANT (supprimé) :**
```javascript
// Fonction pour compatibilité avec l'ancien système de priorité
function createPriorityIcon(priority) {
    if (priority >= 3) return createMarkerIcon('both');
    if (priority === 2) return createMarkerIcon('sav');
    return createMarkerIcon('normal');
}

// Initialiser la carte sur la France par défaut
```

**APRÈS (actuel L.634-636) :**
```javascript
// Initialiser la carte sur la France par défaut
```

---

### 1.2 Gestion de `escapeHtml`

**AVANT (supprimé) :**
```javascript
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateClientsBadge() {
```

**APRÈS (actuel L.858-872) :**
```javascript
// escapeHtml : défini dans maps-enhancements.js ; fallback si absent
if (typeof escapeHtml === 'undefined') {
    window.escapeHtml = function(text) {
        if (text == null) return '';
        const div = document.createElement('div');
        div.textContent = String(text);
        return div.innerHTML;
    };
}

function updateClientsBadge() {
```

---

### 1.3 Garde-fou `updateClientsBadge`

**AVANT :**
```javascript
function updateClientsBadge() {
    const count = Object.keys(clientMarkers).length;
    document.getElementById('badgeClients').textContent = `Clients chargés : ${count}`;
}
```

**APRÈS (actuel L.868-872) :**
```javascript
function updateClientsBadge() {
    const count = Object.keys(clientMarkers).length;
    const el = document.getElementById('badgeClients');
    if (el) el.textContent = `Clients chargés : ${count}`;
}
```

---

### 1.4 Garde-fou `renderSelectedClients`

**AVANT :**
```javascript
function renderSelectedClients() {
    selectedClientsContainer.innerHTML = '';
```

**APRÈS (actuel L.879-882) :**
```javascript
function renderSelectedClients() {
    if (!selectedClientsContainer) return;
    selectedClientsContainer.innerHTML = '';
```

---

### 1.5 Garde-fous et retour de `addClientToRoute`

**AVANT :**
```javascript
    if (selectedClients.find(s => s.id === client.id)) {
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        clientResultsEl.style.display = 'none';
        return;
    }

    if (!isValidCoordinate(client.lat, client.lng)) {
        routeMessageEl.textContent = "Géocodage de l'adresse en cours…";
        routeMessageEl.className = 'maps-message hint';
        // ...
        if (!clientWithCoords || ...) {
            routeMessageEl.textContent = "Impossible de géocoder...";
            return;
        }
    }
    // ...
    clientSearchInput.value = '';
    clientResultsEl.innerHTML = '';
    clientResultsEl.style.display = 'none';
    // ...
    if (added) {
        routeMessageEl.textContent = `Client "${client.name}" ajouté...`;
    }
}
```

**APRÈS (actuel L.999-1049) :**
```javascript
    if (selectedClients.find(s => s.id === client.id)) {
        if (clientSearchInput) clientSearchInput.value = '';
        if (clientResultsEl) { clientResultsEl.innerHTML = ''; clientResultsEl.style.display = 'none'; }
        return;
    }

    if (!isValidCoordinate(client.lat, client.lng)) {
        if (routeMessageEl) { routeMessageEl.textContent = "Géocodage de l'adresse en cours…"; routeMessageEl.className = 'maps-message hint'; }
        // ...
        if (!clientWithCoords || ...) {
            if (routeMessageEl) { routeMessageEl.textContent = "Impossible de géocoder..."; routeMessageEl.className = 'maps-message alert'; }
            return;
        }
    }
    // ...
    if (clientSearchInput) clientSearchInput.value = '';
    if (clientResultsEl) { clientResultsEl.innerHTML = ''; clientResultsEl.style.display = 'none'; }
    // ...
    if (added && routeMessageEl) {
        routeMessageEl.textContent = `Client "${client.name}" ajouté à la tournée et affiché sur la carte.`;
        routeMessageEl.className = 'maps-message success';
    }
    return added;
}
```

---

### 1.6 Garde-fous `loadAllClients` (routeMessageEl)

**APRÈS (actuel L.403-406, 433-436, 445-447, 451-454) :**
```javascript
            if (routeMessageEl) {
                routeMessageEl.textContent = `Chargement de ${totalClients} client(s)…`;
                routeMessageEl.className = 'maps-message hint';
            }
            // ...
            if (routeMessageEl) {
                routeMessageEl.textContent = `${clientsWithCoords} client(s) chargé(s)...`;
                routeMessageEl.className = 'maps-message success';
            }
            // ...
        } else {
            if (routeMessageEl) {
                routeMessageEl.textContent = "Erreur lors du chargement...";
            }
        }
    } catch (err) {
        if (routeMessageEl) {
            routeMessageEl.textContent = "Erreur lors du chargement...";
        }
    }
```

---

### 1.7 Garde-fous `geocodeClientsInBackground`

**APRÈS (actuel L.500-501, 597-600) :**
```javascript
    const updateProgress = () => {
        if (processed < clientsToGeocode.length && routeMessageEl) {
            routeMessageEl.textContent = `Géocodage en cours : ...`;
        }
    };
    // ...
    if (routeMessageEl) {
        routeMessageEl.textContent = `Géocodage terminé : ...`;
        routeMessageEl.className = 'maps-message success';
    }
```

---

### 1.8 Listeners recherche encapsulés

**AVANT :**
```javascript
clientSearchInput.addEventListener('input', () => {
    // ...
    clientResultsEl.innerHTML = '';
    // ...
});

document.addEventListener('click', (e) => {
    if (!clientResultsEl.contains(e.target) && e.target !== clientSearchInput) {
        clientResultsEl.style.display = 'none';
    }
});
```

**APRÈS (actuel L.1143-1264) :**
```javascript
if (clientSearchInput && clientResultsEl) {
clientSearchInput.addEventListener('input', () => {
    // ... (logique identique)
});
}

if (clientResultsEl && clientSearchInput) {
document.addEventListener('click', (e) => {
    if (!clientResultsEl.contains(e.target) && e.target !== clientSearchInput) {
        clientResultsEl.style.display = 'none';
    }
});
}
```

---

### 1.9 clientSearchClear – condition étendue

**AVANT :**
```javascript
const clientSearchClear = document.getElementById('clientSearchClear');
if (clientSearchClear) {
    clientSearchClear.addEventListener('click', () => { ... });
    clientSearchInput.addEventListener('input', () => { ... });
}
```

**APRÈS (actuel L.1865-1878) :**
```javascript
const clientSearchClear = document.getElementById('clientSearchClear');
if (clientSearchClear && clientSearchInput && clientResultsEl) {
    clientSearchClear.addEventListener('click', () => {
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        clientResultsEl.style.display = 'none';
        clientSearchClear.classList.remove('visible');
        clientSearchInput.focus();
    });
    
    clientSearchInput.addEventListener('input', () => {
        clientSearchClear.classList.toggle('visible', clientSearchInput.value.length > 0);
    });
}
```

---

### 1.10 isValidCoordinate (inchangé dans maps.php)

**Code actuel (L.1073-1081) :**
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

---

### 1.11 Filtres (inchangé – géré par maps.php)

**Code actuel (L.1806-1862) :**
```javascript
document.addEventListener('DOMContentLoaded', () => {
    const filterToggles = document.querySelectorAll('#mapFilters input[type="checkbox"]');
    filterToggles.forEach(toggle => {
        toggle.addEventListener('change', () => {
            const filter = toggle.dataset.filter;
            // Logique "Tous" vs filtres spécifiques
            // ...
            activeFilters.add/delete...
            applyMarkerFilters();
        });
    });
});
```

---

## 2. assets/js/maps-enhancements.js

### 2.1 Code supprimé : `initCollapsibleSections` et `updateGeocodeProgress`

**AVANT (supprimé) :**
```javascript
function initCollapsibleSections() {
    const sections = document.querySelectorAll('#maps-page .section-title[data-section]');
    sections.forEach(title => {
        title.addEventListener('click', () => {
            const content = title.nextElementSibling;
            if (content && content.classList.contains('section-content')) {
                title.classList.toggle('collapsed');
                content.classList.toggle('collapsed');
            }
        });
    });
}

function updateGeocodeProgress(current, total) {
    let progressBar = document.getElementById('geocodeProgressBar');
    // ...
}

// ============================================
// INITIALISATION AU CHARGEMENT
// ============================================
```

**APRÈS :** Ces blocs ont été supprimés. Le fichier passe directement à `// INITIALISATION AU CHARGEMENT`.

---

### 2.2 Listeners retirés : FilterManager.init et clientSearchClear

**AVANT (supprimé) :**
```javascript
    // Initialiser sections repliables
    initCollapsibleSections();
    
    // Initialiser filtres (après chargement map)
    setTimeout(() => {
        if (typeof FilterManager !== 'undefined') {
            FilterManager.init();
        }
    }, 1000);
    
    // Bouton recherche zone visible
    // ...
    
    // Bouton effacer recherche
    const clearBtn = document.getElementById('clientSearchClear');
    const clientSearchInput = document.getElementById('clientSearch');
    const clientResultsEl = document.getElementById('clientResults');
    
    if (clearBtn && clientSearchInput && clientResultsEl) {
        clearBtn.addEventListener('click', () => {
            clientSearchInput.value = '';
            clientResultsEl.innerHTML = '';
            clientResultsEl.style.display = 'none';
            clearBtn.classList.remove('visible');
            clientSearchInput.focus();
        });
        
        clientSearchInput.addEventListener('input', () => {
            clearBtn.classList.toggle('visible', clientSearchInput.value.length > 0);
        });
    }
});
```

**APRÈS (actuel L.433-456) :**
```javascript
    // Filtres : gérés par maps.php (évite doublon avec FilterManager)
    
    // Bouton recherche zone visible
    const toolbarRight = document.querySelector('#maps-page .map-toolbar-right');
    // ...
    
    // Bouton export
    // ...
    
    // Bouton effacer recherche : géré par maps.php (évite doublon de listeners)
});
```

---

### 2.3 escapeHtml (inchangé dans maps-enhancements)

**Code actuel (L.13-18) :**
```javascript
function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}
```

---

### 2.4 isValidCoordinate (inchangé dans maps-enhancements)

**Code actuel (L.24-28) :**
```javascript
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}
```

---

### 2.5 FilterManager (inchangé, init() non appelé)

**Code actuel (L.226-282) :** L'objet `FilterManager` est toujours défini avec `init()` et `applyFilters()`, mais **`FilterManager.init()` n'est plus appelé** nulle part.

---

## 3. Vérification de cohérence

### 3.1 Qui gère les filtres au final ?

**maps.php** uniquement.

- `DOMContentLoaded` (L.1807) attache des listeners `change` sur `#mapFilters input[type="checkbox"]`
- `activeFilters` (variable globale) est mise à jour
- `applyMarkerFilters()` est appelée

**maps-enhancements.js** : ne gère plus les filtres. `FilterManager.init()` n’est plus appelé.

---

### 3.2 Qui gère clientSearchClear au final ?

**maps.php** uniquement.

- L.1865-1878 : `clientSearchClear.addEventListener('click', ...)` et `clientSearchInput.addEventListener('input', ...)` pour le toggle

**maps-enhancements.js** : le bloc correspondant a été supprimé.

---

### 3.3 Y a-t-il encore un listener `input` sur `clientSearch` dans maps-enhancements.js ?

**Non.**

- `grep` sur `clientSearch` dans maps-enhancements.js : aucune occurrence
- `grep` sur `addEventListener` : uniquement `closeBtn`, `btnSearchBounds`, `btnExport`, et les listeners internes de `FilterManager.init` (qui n’est plus appelé)

---

### 3.4 Y a-t-il encore une dépendance fragile entre maps.php et maps-enhancements.js ?

**Oui, mais limitée.**

| Dépendance | Sens | Fragilité |
|------------|------|-----------|
| **escapeHtml** | maps.php utilise celle de maps-enhancements (chargé avant) | **Atténuée** : fallback dans maps.php si `escapeHtml` est undefined |
| **isValidCoordinate** | maps.php définit la sienne (avec CONFIG) ; maps-enhancements en définit une aussi | **Duplication** : maps.php est chargé après et écrase. Au runtime, c’est toujours la version maps.php qui est utilisée (y compris par searchInVisibleBounds, normalizeLatLng, etc.) |
| **StorageManager, ToastManager** | maps-enhancements → wrappers utilisent ces objets | **Normale** : maps-enhancements fournit ces services |
| **clientsCache, selectedClients, map, clientMarkers** | maps-enhancements utilise des variables globales de maps.php | **Fragile** : si maps.php change de structure, maps-enhancements peut casser |
| **addClientToRoute, setStartPoint, renderSelectedClients** | maps-enhancements wrappe ces fonctions via `waitFor` | **Normale** : pattern d’extension |

**Conclusion** : La dépendance la plus fragile reste l’usage par maps-enhancements des variables globales de maps.php (`clientsCache`, `selectedClients`, `map`, `clientMarkers`, `startPoint`, `lastOrderedStops`). C’est inhérent à l’architecture actuelle.

---

## 4. Synthèse

| Élément | Gestion finale | Fichier |
|---------|----------------|---------|
| Filtres | maps.php | L.1806-1862 |
| clientSearchClear (click) | maps.php | L.1867-1873 |
| clientSearch (input) pour recherche | maps.php | L.1144-1254 |
| clientSearch (input) pour toggle clear | maps.php | L.1875-1877 |
| escapeHtml | maps-enhancements (définition) + maps.php (fallback) | maps-enhancements L.13-18, maps.php L.858-866 |
| isValidCoordinate | maps.php (utilisée partout) ; maps-enhancements (définition propre pour ToastManager, exportRoute, etc.) | Les deux définissent, maps.php écrase au chargement |
| addClientToRoute retour | `return added` (true/false) | maps.php L.1048 |
