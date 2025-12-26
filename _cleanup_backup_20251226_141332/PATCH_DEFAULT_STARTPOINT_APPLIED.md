# PATCH APPLIQUÉ : Point de départ par défaut

## ✅ MODIFICATIONS APPLIQUÉES

### 1. Constantes et fonction ajoutées (lignes 222-267)

**Position :** Après `CONFIG`, avant `clientsCache`

**Contenu ajouté :**
```javascript
// ==================
// Point de départ par défaut
// ==================

const DEFAULT_START_ADDRESS = "7 Rue Fraizier, 93210 Saint-Denis";
// Coordonnées hardcodées pour éviter le géocodage (approximatives, centre de Saint-Denis)
const DEFAULT_START_COORDS = [48.9358, 2.3536];

/**
 * Initialise le point de départ par défaut si aucun n'est défini
 * Priorité : localStorage > code existant > default hardcodé
 */
function initDefaultStartPoint() {
    // 1. Vérifier si startPoint est déjà défini (par code existant ou localStorage restore)
    if (startPoint && Array.isArray(startPoint) && startPoint.length === 2) {
        return; // Déjà défini, ne rien faire
    }
    
    // 2. Vérifier localStorage (si StorageManager existe, il a déjà été restauré)
    if (typeof StorageManager !== 'undefined') {
        const savedStart = StorageManager.loadStartPoint();
        if (savedStart && Array.isArray(savedStart) && savedStart.length === 2) {
            return; // Déjà restauré par maps-enhancements.js
        }
    } else {
        // Fallback : vérifier localStorage directement
        try {
            const saved = localStorage.getItem('maps_start_point');
            if (saved) {
                const parsed = JSON.parse(saved);
                if (Array.isArray(parsed) && parsed.length === 2 && 
                    isValidCoordinate(parsed[0], parsed[1])) {
                    return; // Existe dans localStorage
                }
            }
        } catch (e) {
            // Ignorer erreur localStorage
        }
    }
    
    // 3. Appliquer le default hardcodé (sans géocodage)
    if (typeof map !== 'undefined' && map && typeof setStartPoint === 'function') {
        setStartPoint(DEFAULT_START_COORDS, DEFAULT_START_ADDRESS);
    }
}
```

---

### 2. Appel d'initialisation (lignes 1645-1648)

**Position :** Après `loadAllClients()`, avant `</script>`

**Contenu ajouté :**
```javascript
// Initialiser le point de départ par défaut après un court délai
// (pour laisser le temps à maps-enhancements.js de restaurer depuis localStorage)
setTimeout(() => {
    initDefaultStartPoint();
}, 2500); // 2.5s : après le restore de maps-enhancements.js (2s) + marge
```

---

### 3. Input adresse ajouté (lignes 79-84)

**Position :** Dans la section "Point de départ", après `#startInfo`

**Contenu ajouté :**
```html
<input type="text" 
       id="startAddressInput" 
       class="client-search-input" 
       placeholder="Adresse de départ (ex: 7 Rue Fraizier, 93210 Saint-Denis)"
       value=""
       style="margin-top: 0.5rem; width: 100%;">
```

---

### 4. Mise à jour input dans setStartPoint (lignes 1197-1201)

**Position :** Dans la fonction `setStartPoint()`, après `badgeStartEl.textContent`

**Contenu ajouté :**
```javascript
// Mettre à jour l'input adresse si présent
const startAddressInput = document.getElementById('startAddressInput');
if (startAddressInput && label) {
    startAddressInput.value = label;
}
```

---

### 5. Effacement input + géocodage depuis input (lignes 1251-1275)

**Position :** Après le listener `btnClearStart`, avant `map.on('click')`

**Contenu ajouté :**
```javascript
// Effacer l'input adresse
const startAddressInput = document.getElementById('startAddressInput');
if (startAddressInput) {
    startAddressInput.value = '';
}

// Géocoder l'adresse si l'utilisateur tape dans l'input et valide (Enter)
const startAddressInput = document.getElementById('startAddressInput');
if (startAddressInput) {
    startAddressInput.addEventListener('keydown', async (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const address = startAddressInput.value.trim();
            if (!address) return;
            
            routeMessageEl.textContent = "Géocodage de l'adresse en cours…";
            routeMessageEl.className = 'maps-message hint';
            
            const coords = await geocodeAddress(address);
            if (coords) {
                setStartPoint([coords.lat, coords.lng], address);
                routeMessageEl.textContent = "Point de départ défini.";
                routeMessageEl.className = 'maps-message success';
            } else {
                routeMessageEl.textContent = "Impossible de géocoder cette adresse.";
                routeMessageEl.className = 'maps-message alert';
            }
        }
    });
}
```

---

## 📋 RÉSUMÉ

### Coordonnées utilisées
- **Adresse :** "7 Rue Fraizier, 93210 Saint-Denis"
- **Coordonnées hardcodées :** `[48.9358, 2.3536]`
- **Source :** Centre approximatif de Saint-Denis (pas de géocodage nécessaire)

### Clé localStorage
- **Utilisée :** `maps_start_point` (gérée par `StorageManager` dans `maps-enhancements.js`)
- **Format :** `[lat, lng]` (array normalisé)

### Priorité d'initialisation
1. ✅ **localStorage** (`maps_start_point`) - restauré par `maps-enhancements.js` (2s)
2. ✅ **startPoint déjà défini** - par code existant
3. ✅ **Default hardcodé** - appliqué après 2.5s si rien d'autre

---

## ✅ VÉRIFICATIONS

- [ ] **Chargement rapide** : Pas de géocodage au chargement, marker apparaît immédiatement
- [ ] **Marker par défaut** : Apparaît sur Saint-Denis (48.9358, 2.3536)
- [ ] **Input rempli** : L'input affiche "7 Rue Fraizier, 93210 Saint-Denis"
- [ ] **localStorage prioritaire** : Si une valeur existe, elle est utilisée (pas le default)
- [ ] **Géocodage manuel** : Taper une adresse + Enter → géocode et définit le départ
- [ ] **Effacement** : Bouton "Effacer" vide aussi l'input

---

## 🎯 COMPORTEMENT ATTENDU

1. **Premier chargement** (pas de localStorage) :
   - Après 2.5s → Marker apparaît sur Saint-Denis
   - Input affiche "7 Rue Fraizier, 93210 Saint-Denis"
   - Pas de géocodage (performance optimale)

2. **Chargement suivant** (localStorage existe) :
   - Après 2s → `maps-enhancements.js` restaure depuis localStorage
   - `initDefaultStartPoint()` détecte que `startPoint` est déjà défini → ne fait rien
   - Le point sauvegardé est utilisé (pas le default)

3. **Utilisateur choisit autre départ** :
   - Sauvegardé dans localStorage (via `StorageManager`)
   - Input mis à jour avec la nouvelle adresse
   - Le choix est conservé au prochain chargement

---

**Patch appliqué avec succès ! 🎉**

