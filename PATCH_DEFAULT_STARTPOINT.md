# PATCH : Point de départ par défaut (Saint-Denis)

## Objectif
Ajouter un point de départ par défaut "7 Rue Fraizier, 93210 Saint-Denis" avec coordonnées hardcodées pour éviter le géocodage à chaque chargement.

## Priorité d'initialisation
1. **localStorage** (si existe) - restauré par maps-enhancements.js
2. **startPoint déjà défini** (par code existant)
3. **Default hardcodé** - "7 Rue Fraizier, 93210 Saint-Denis" avec coordonnées

---

## MODIFICATION 1 : Ajouter constante et fonction helper

**Fichier :** `public/maps.php`

**Position :** Après `CONFIG` (ligne ~211), avant les variables globales

**Ajouter :**
```javascript
// ==================
// Configuration
// ==================

// Constantes configurables
const CONFIG = {
    SEARCH_DEBOUNCE_MS: 400,
    GEOCODE_BATCH_SIZE: 3,
    GEOCODE_BATCH_DELAY_MS: 1500,
    FETCH_TIMEOUT_MS: 15000,
    MAX_RETRIES: 3,
    RETRY_DELAY_MS: 1000,
    MAX_CLIENTS_PER_ROUTE: 20,
    COORDINATE_BOUNDS: {
        LAT_MIN: -90,
        LAT_MAX: 90,
        LNG_MIN: -180,
        LNG_MAX: 180
    }
};

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
    // Mais on vérifie quand même au cas où
    if (typeof StorageManager !== 'undefined') {
        const savedStart = StorageManager.loadStartPoint();
        if (savedStart && Array.isArray(savedStart) && savedStart.length === 2) {
            // Déjà restauré par maps-enhancements.js, ne rien faire
            return;
        }
    } else {
        // Fallback : vérifier localStorage directement
        try {
            const saved = localStorage.getItem('maps_start_point');
            if (saved) {
                const parsed = JSON.parse(saved);
                if (Array.isArray(parsed) && parsed.length === 2 && 
                    isValidCoordinate(parsed[0], parsed[1])) {
                    // Existe dans localStorage, sera restauré par maps-enhancements.js
                    return;
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

## MODIFICATION 2 : Appeler l'initialisation au bon moment

**Fichier :** `public/maps.php`

**Position :** Après `loadAllClients()` (ligne ~1581), juste avant `</script>`

**Chercher :**
```javascript
// Charger tous les clients au démarrage (après que toutes les fonctions soient définies)
loadAllClients();
</script>
```

**Remplacer par :**
```javascript
// Charger tous les clients au démarrage (après que toutes les fonctions soient définies)
loadAllClients();

// Initialiser le point de départ par défaut après un court délai
// (pour laisser le temps à maps-enhancements.js de restaurer depuis localStorage)
setTimeout(() => {
    initDefaultStartPoint();
}, 2500); // 2.5s : après le restore de maps-enhancements.js (2s) + marge
</script>
```

---

## MODIFICATION 3 : Ajouter un champ input pour l'adresse (optionnel mais recommandé)

**Fichier :** `public/maps.php`

**Position :** Dans la section "Point de départ" (ligne ~66-76)

**Chercher :**
```html
<div id="startInfo" class="hint">
    Aucun point de départ défini.
</div>
```

**Remplacer par :**
```html
<div id="startInfo" class="hint">
    Aucun point de départ défini.
</div>
<input type="text" 
       id="startAddressInput" 
       class="client-search-input" 
       placeholder="Adresse de départ (ex: 7 Rue Fraizier, 93210 Saint-Denis)"
       value=""
       style="margin-top: 0.5rem; width: 100%;">
```

**Puis ajouter dans le script (après la fonction `setStartPoint`, ligne ~1143) :**

```javascript
// Mettre à jour l'input adresse quand startPoint change
const startAddressInput = document.getElementById('startAddressInput');
if (startAddressInput) {
    // Mettre à jour l'input quand le point est défini
    const originalSetStartPoint = setStartPoint;
    setStartPoint = function(latlng, label) {
        originalSetStartPoint.call(this, latlng, label);
        if (startAddressInput && label) {
            startAddressInput.value = label;
        }
    };
    
    // Géocoder l'adresse si l'utilisateur tape et valide
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

**Note :** Cette modification 3 est optionnelle. Si vous ne voulez pas l'input, ignorez-la.

---

## RÉSUMÉ DES MODIFICATIONS

### Fichier : `public/maps.php`

1. **Ligne ~211** (après CONFIG) : Ajouter constante `DEFAULT_START_ADDRESS` et `DEFAULT_START_COORDS` + fonction `initDefaultStartPoint()`

2. **Ligne ~1581** (après `loadAllClients()`) : Ajouter `setTimeout(() => { initDefaultStartPoint(); }, 2500);`

3. **Ligne ~76** (optionnel) : Ajouter input pour adresse de départ

4. **Ligne ~1143** (après `setStartPoint`) : Ajouter logique pour mettre à jour l'input

---

## COORDONNÉES UTILISÉES

**Adresse :** "7 Rue Fraizier, 93210 Saint-Denis"  
**Coordonnées hardcodées :** `[48.9358, 2.3536]` (centre approximatif de Saint-Denis)

**Note :** Si vous voulez les coordonnées exactes de cette adresse, vous pouvez :
- Les géocoder une fois manuellement et les remplacer
- Ou utiliser le système de cache localStorage (clé `startPoint_default_cached`) pour stocker le résultat du premier géocodage

---

## VÉRIFICATIONS

- [ ] La page charge rapidement (pas de géocodage au chargement)
- [ ] Le marker de départ apparaît automatiquement sur Saint-Denis
- [ ] Si localStorage a une valeur, elle est utilisée (pas le default)
- [ ] Si l'utilisateur choisit un autre départ, son choix est sauvegardé
- [ ] Le champ input (si ajouté) affiche l'adresse par défaut

---

## COMPATIBILITÉ

✅ Compatible avec `maps-enhancements.js` (utilise StorageManager si disponible)  
✅ Ne casse pas les fonctions existantes  
✅ Respecte la priorité : localStorage > code > default  
✅ Pas de géocodage au chargement (performance)

