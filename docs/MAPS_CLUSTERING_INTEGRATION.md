# Intégration du clustering des marqueurs – Maps

## Résumé

Le clustering des marqueurs a été intégré via **Leaflet.markercluster v1.5.3**. Les marqueurs clients sont regroupés en clusters quand ils sont proches, et s’affichent individuellement au zoom.

---

## Fichiers modifiés

| Fichier | Modifications |
|---------|---------------|
| `public/maps.php` | Dépendances CSS/JS, variable `markerClusterGroup`, initialisation, `addClientToMap`, `applyMarkerFilters` |
| `assets/js/maps-enhancements.js` | `FilterManager.applyFilters` utilise `markerClusterGroup` si disponible |

---

## Détail des modifications

### 1. Dépendances (maps.php, lignes 49-57)

```html
<!-- Leaflet.markercluster : clustering des marqueurs -->
<link rel="stylesheet"
      href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css"
      crossorigin=""/>
<link rel="stylesheet"
      href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css"
      crossorigin=""/>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"
        crossorigin=""></script>
```

Chargement après Leaflet, avant le script inline de la page.

---

### 2. Variable globale (maps.php, ligne 351)

```javascript
let markerClusterGroup;
```

---

### 3. Initialisation (maps.php, lignes 625-627)

```javascript
// Groupe de clustering des marqueurs clients (améliore lisibilité et performances)
markerClusterGroup = L.markerClusterGroup();
markerClusterGroup.addTo(map);
```

Après le tile layer, avant `createMarkerIcon`.

---

### 4. addClientToMap (maps.php, ligne 824)

**Avant :**
```javascript
}).addTo(map);
```

**Après :**
```javascript
}).addTo(markerClusterGroup);
```

Le reste de la fonction est inchangé : `clientMarkers[client.id] = marker`, `marker.bindPopup(popupContent)`, `marker.options.clientId`, `applyMarkerFilters()`.

---

### 5. applyMarkerFilters (maps.php, lignes 1788-1820)

**Changements :**
- Garde : `if (!map || !markerClusterGroup || !clientMarkers) return;`
- `map.hasLayer(marker)` → `markerClusterGroup.hasLayer(marker)`
- `map.addLayer(marker)` → `markerClusterGroup.addLayer(marker)`
- `map.removeLayer(marker)` → `markerClusterGroup.removeLayer(marker)`

La logique de filtrage (Tous, SAV, Livraison, Normal) reste identique.

---

### 6. FilterManager (maps-enhancements.js)

`FilterManager.applyFilters` utilise désormais `markerClusterGroup` s’il est défini, sinon `map` (fallback pour compatibilité future).

---

## Vérifications

| Fonctionnalité | Statut |
|----------------|--------|
| Chargement des clients | `loadAllClients` inchangé |
| Marqueurs en cluster | `addClientToMap` ajoute au cluster |
| Zoom éclate les clusters | Comportement par défaut du plugin |
| Filtres (Tous, SAV, Livraison, Normal) | `applyMarkerFilters` adapté |
| Popups | `marker.bindPopup` inchangé |
| Ajout à la tournée | `addClientToRoute` → `addClientToMap` inchangé |
| Calcul d’itinéraire | `map`, `L.tileLayer`, `routeLayer` inchangés |
| Recherche | `clientMarkers`, `clientsCache` inchangés |
| `clientMarkers` source de vérité | Conservé |
| `startMarker` | Toujours sur `map` directement |
| `routeLayer` | Toujours sur `map` directement |

---

## Points de vigilance

1. **CDN** : Leaflet.markercluster est chargé depuis unpkg. En cas de problème réseau, prévoir une version locale ou un autre CDN.

2. **Filtres** : Un marqueur masqué par filtre est retiré du cluster group. Il ne reste plus visible dans un cluster.

3. **Styles** : Les styles par défaut du plugin (MarkerCluster.Default.css) peuvent être ajustés dans `maps.css` si besoin.

4. **Compatibilité** : Leaflet 1.9.4, Leaflet.markercluster 1.5.3.

---

## Diff synthétique

```
maps.php:
+ Lignes 49-57 : CSS + JS Leaflet.markercluster
+ Ligne 351 : let markerClusterGroup;
+ Lignes 625-627 : markerClusterGroup = L.markerClusterGroup(); markerClusterGroup.addTo(map);
  Ligne 824 : .addTo(map) → .addTo(markerClusterGroup)
  Ligne 1789 : if (!map || !markerClusterGroup || !clientMarkers) return;
  Lignes 1812-1817 : map.hasLayer/addLayer/removeLayer → markerClusterGroup.hasLayer/addLayer/removeLayer

maps-enhancements.js:
  FilterManager.applyFilters : utilise markerClusterGroup si défini, sinon map
```
