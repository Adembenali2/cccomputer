# GUIDE D'UTILISATION - PATCH_3_JS_NEW_FUNCTIONS.js

## 📋 CORRECTIONS APPLIQUÉES

✅ **escapeHtml()** - Fonction helper ajoutée  
✅ **isValidCoordinate()** - Fonction helper ajoutée  
✅ **normalizeLatLng()** - Normalise [lat,lng] ou {lat,lng} → [lat,lng]  
✅ **Vérifications robustes** - Tous les `if (!map)` remplacés par `typeof map !== 'undefined' && map`  
✅ **waitFor()** - Remplace setTimeout(500) fragile  
✅ **localStorage format stable** - Format minimal {id, priority} uniquement  
✅ **Évite doublons** - Vérification lors du restore  
✅ **startPoint normalisé** - Toujours sauvegardé en [lat, lng]  
✅ **exportRoute sécurisé** - Vérifie lastOrderedStops, normalise startPoint  
✅ **searchInVisibleBounds limité** - Max 20 markers, highlight léger (pas de flood popups)

---

## 🔧 DEUX VERSIONS DISPONIBLES

### **VERSION A : Inline dans maps.php** (Recommandé)

**Fichier :** `PATCH_3_JS_NEW_FUNCTIONS.js` (lignes 1-387)

**Où coller dans maps.php :**
```php
<!-- Ligne ~191 : AVANT le gros script existant -->
<script>
// ============================================
// PATCH 3 : JS - Nouvelles fonctions
// ============================================

<script>
// ... (copier tout le contenu de PATCH_3_JS_NEW_FUNCTIONS.js depuis la ligne 6 jusqu'à la ligne 386)
</script>

<!-- Ensuite, le gros script existant continue... -->
<script>
// ==================
// Configuration
// ==================
const CONFIG = { ... }
// ... reste du script existant
</script>
```

**Ordre dans maps.php :**
1. HTML (lignes 1-189)
2. **PATCH_3_JS_NEW_FUNCTIONS.js** (ligne 191) ← **ICI**
3. Script existant (ligne 191+)

---

### **VERSION B : Fichier .js externe**

**Créer un nouveau fichier :** `assets/js/maps-enhancements.js`

**Contenu :** Copier le contenu de `PATCH_3_JS_NEW_FUNCTIONS.js` mais **RETIRER** :
- La ligne 6 : `<script>`
- La ligne 387 : `</script>`
- Les commentaires "VERSION A" et "VERSION B"

**Dans maps.php (ligne ~35, après maps.css) :**
```php
<!-- CSS spécifique à la page carte -->
<link rel="stylesheet" href="/assets/css/maps.css">

<!-- Améliorations JS -->
<script src="/assets/js/maps-enhancements.js"></script>

<!-- Leaflet (OpenStreetMap) -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" ...>
```

**Ordre dans maps.php :**
1. HTML (lignes 1-189)
2. **maps-enhancements.js** (ligne ~35, dans `<head>`) ← **ICI**
3. Script existant (ligne 191+)

---

## ⚠️ IMPORTANT

### Version A (Inline) :
- ✅ Plus simple (tout dans un seul fichier)
- ✅ Pas de problème de chargement asynchrone
- ⚠️ Fichier maps.php plus long

### Version B (Externe) :
- ✅ Séparation des concerns
- ✅ Cache navigateur possible
- ⚠️ Doit être chargé AVANT le script existant (dans `<head>`)

---

## 🧪 VÉRIFICATIONS POST-APPLICATION

1. **Console navigateur (F12) :** Aucune erreur
2. **Toasts :** Apparaissent lors des actions
3. **localStorage :** Données sauvegardées (DevTools > Application > Local Storage)
4. **Filtres :** Fonctionnent (toggles SAV/Livraison/Normal)
5. **Export CSV :** Télécharge un fichier valide
6. **Recherche zone visible :** Affiche le nombre sans flood popups
7. **Restore :** L'itinéraire est restauré au rechargement (sans doublons)

---

## 🐛 DÉPANNAGE

**Erreur : "escapeHtml is not defined"**
→ Vérifier que les helpers sont bien définis (début du script)

**Erreur : "map is not defined"**
→ Normal, le script attend que map soit créé (waitFor gère ça)

**localStorage ne sauvegarde pas**
→ Vérifier que le format est correct (console.log StorageManager.saveSelectedClients)

**Doublons lors du restore**
→ Vérifier que la logique d'évitement de doublons fonctionne (ligne ~270)

---

**Version corrigée prête à utiliser ! 🎉**

