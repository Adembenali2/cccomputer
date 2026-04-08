# Audit technique – Page Maps (développement concret)

Document exploitable pour les prochaines fonctionnalités. Références précises au code.

---

## 1. Arborescence des fichiers

```
cccomputer-1/
├── public/
│   └── maps.php                    # Page principale (HTML + JS inline ~1200 lignes)
├── assets/
│   ├── css/
│   │   ├── main.css                # Styles globaux (optionnel pour maps)
│   │   └── maps.css                # Styles spécifiques maps (~990 lignes)
│   └── js/
│       └── maps-enhancements.js    # Extensions (toasts, localStorage, export) (~440 lignes)
├── source/
│   └── templates/
│       └── header.php              # En-tête + nav (inclus dans maps.php)
├── includes/
│   ├── auth_role.php               # authorize_page('maps', ['Admin', 'Dirigeant'])
│   └── helpers.php                 # getPdo(), h()
└── API/
    ├── maps_get_all_clients.php    # GET : liste tous les clients avec coords
    ├── maps_search_clients.php     # GET ?q= : recherche clients
    ├── maps_geocode.php            # GET ?address= : géocodage adresse
    ├── maps_geocode_client.php     # GET ?client_id=&address= : géocodage + sauvegarde BDD
    └── osrm_route.php              # Proxy OSRM (NON UTILISÉ par maps.php)
```

### Détail par fichier

| Fichier | Rôle | Criticité | Blocs / fonctions importants |
|---------|------|-----------|------------------------------|
| `public/maps.php` | Page complète : auth, HTML, JS inline | **Critique** | `loadAllClients`, `addClientToMap`, `searchClients`, `setStartPoint`, handler `#btnRoute`, filtres |
| `assets/css/maps.css` | Styles layout, panneau, carte, chips, toasts | **Critique** | `.maps-layout`, `#map`, `.selected-client-chip`, `.map-filters` |
| `assets/js/maps-enhancements.js` | Toasts, localStorage, export CSV, zone visible | **Secondaire** | `ToastManager`, `StorageManager`, `FilterManager`, `exportRoute`, `searchInVisibleBounds`, wrappers |
| `source/templates/header.php` | Nav, menu, liens | **Secondaire** | — |
| `includes/auth_role.php` | Vérification accès page | **Critique** | `authorize_page()` |
| `API/maps_get_all_clients.php` | Liste clients + coords + SAV/livraisons | **Critique** | Requête SQL + formatage JSON |
| `API/maps_search_clients.php` | Recherche par nom/code/adresse | **Critique** | LIKE multi-critères |
| `API/maps_geocode.php` | Géocodage Nominatim (point départ) | **Critique** | Cache fichier + curl Nominatim |
| `API/maps_geocode_client.php` | Géocodage client + INSERT client_geocode | **Critique** | Cache + Nominatim + BDD |
| `API/osrm_route.php` | Proxy OSRM | **Optionnel** | **Jamais appelé** – maps.php appelle OSRM directement |

---

## 2. Inventaire des fonctions

### maps.php (script inline)

| Fonction | Rôle | Appelée par | Utilise | DOM | Endpoints | Modifiable |
|----------|------|-------------|---------|-----|-----------|------------|
| `initDefaultStartPoint()` | Point départ par défaut si vide | `setTimeout` L.1388 | `startPoint`, `setStartPoint`, `StorageManager`, `isValidCoordinate` | — | — | Oui |
| `loadAllClients()` | Charge tous les clients au démarrage | L.1378 (appel direct) | `fetchWithTimeout`, `addClientToMap`, `geocodeClientsInBackground`, `isValidCoordinate` | `routeMessageEl` | `/API/maps_get_all_clients.php` | Sensible |
| `addClientToNotFoundList(client)` | Ajoute client non géocodé à la liste | `geocodeClientsInBackground`, `loadClientWithGeocode` | `escapeHtml` | `notFoundClientsSection`, `notFoundClientsContainer` | — | Oui |
| `geocodeClientsInBackground(clients)` | Géocode clients en lot (Nominatim) | `loadAllClients` | `addClientToMap`, `addClientToNotFoundList`, `fetchWithTimeout`, `isValidCoordinate` | `routeMessageEl` | `/API/maps_geocode_client.php` | Sensible |
| `getMarkerColor(markerType)` | Retourne couleur hex selon type | `createMarkerIcon` | — | — | — | Oui |
| `createMarkerIcon(markerType)` | Crée `L.divIcon` Leaflet | `addClientToMap`, `renderSelectedClients` (select change) | `getMarkerColor` | — | — | Oui |
| `createPriorityIcon(priority)` | Alias priorité → markerType | **Jamais appelée** | `createMarkerIcon` | — | — | **Code mort** |
| `geocodeAddress(address)` | Géocode une adresse (point départ) | `startAddressInput` keydown | `fetchWithTimeout`, `isValidCoordinate` | — | `/API/maps_geocode.php` | Oui |
| `loadClientWithGeocode(client)` | Géocode client puis retourne | `addClientToRoute` | `addClientToNotFoundList`, `fetchWithTimeout`, `isValidCoordinate` | — | `/API/maps_geocode_client.php` | Sensible |
| `addClientToMap(client, autoFit)` | Ajoute/met à jour marqueur sur carte | `loadAllClients`, `geocodeClientsInBackground`, `addClientToRoute` | `createMarkerIcon`, `escapeHtml`, `isValidCoordinate`, `applyMarkerFilters`, `updateClientsBadge` | `map`, `clientMarkers` | — | Sensible |
| `escapeHtml(text)` | Échappe HTML (XSS) | `addClientToMap`, `addClientToNotFoundList`, `renderSelectedClients`, search results | — | — | — | Oui (duplique maps-enhancements) |
| `updateClientsBadge()` | Met à jour badge "Clients chargés" | `addClientToMap`, L.867 | — | `#badgeClients` | — | Oui |
| `renderSelectedClients()` | Affiche la liste des clients sélectionnés | `addClientToRoute`, btn ↑/↓, btn ✕, wrappers | `clientsCache`, `escapeHtml`, `createMarkerIcon` | `selectedClientsContainer` | — | Sensible |
| `addClientToRoute(client)` | Ajoute client à la tournée | Clic résultat recherche, wrapper | `loadClientWithGeocode`, `addClientToMap`, `renderSelectedClients`, `isValidCoordinate` | `clientSearchInput`, `clientResultsEl`, `routeMessageEl`, `map` | — | Sensible |
| `fetchWithTimeout(url, options, timeout)` | `fetch` avec timeout | `loadAllClients`, `geocodeClientsInBackground`, `geocodeAddress`, `loadClientWithGeocode`, `searchClients`, `#btnRoute` | — | — | Tous | Oui |
| `isValidCoordinate(lat, lng)` | Valide coordonnées | Partout | `CONFIG.COORDINATE_BOUNDS` | — | — | Oui (duplique maps-enhancements) |
| `searchClients(query)` | Recherche clients en BDD | Handler `clientSearchInput` input | `fetchWithTimeout` | — | `/API/maps_search_clients.php` | Oui |
| `setStartPoint(latlng, label)` | Définit point départ + marqueur | `btnGeo`, `btnClickStart`+map click, `startAddressInput` Enter, `initDefaultStartPoint`, wrapper | — | `startInfoEl`, `badgeStartEl`, `#startAddressInput`, `map` | — | Sensible |
| `formatDistance(meters)` | "X km" ou "X m" | `renderRouteSummary`, `renderTurnByTurn`, `buildInstruction` | — | — | — | Oui |
| `formatDuration(seconds)` | "X min" ou "X h X min" | Idem | — | — | — | Oui |
| `haversine(lat1,lon1,lat2,lon2)` | Distance km | `computeOrderedStops` | — | — | — | Oui |
| `getSelectedClientsForRouting()` | Clients avec coords pour routage | Handler `#btnRoute` | `selectedClients`, `clientsCache`, `isValidCoordinate` | — | — | Oui |
| `computeOrderedStops(start, clients)` | Ordonne par proximité + urgence | Handler `#btnRoute` | `haversine` | — | — | Oui |
| `renderRouteSummary(legs)` | Affiche étapes résumées | Handler `#btnRoute` | `formatDistance`, `formatDuration`, `lastOrderedStops` | `routeStepsEl` | — | Oui |
| `buildInstruction(step)` | Phrase FR pour instruction OSRM | `renderTurnByTurn` | `formatDistance` | — | — | Oui |
| `renderTurnByTurn(legs)` | Affiche instructions détaillées | Handler `#btnRoute`, `btnShowTurns` | `buildInstruction`, `lastOrderedStops` | `routeTurnsEl` | — | Oui |
| `openInGoogleMaps()` | Ouvre URL Google Maps | `btnGoogle` click | `startPoint`, `lastOrderedStops` | — | — | Oui |
| `applyMarkerFilters()` | Affiche/masque marqueurs selon filtres | `addClientToMap`, `DOMContentLoaded` (filtres) | `activeFilters`, `clientMarkers`, `clientsCache` | `map` | — | Sensible |

### maps-enhancements.js

| Fonction | Rôle | Appelée par | Utilise | DOM | Endpoints | Modifiable |
|----------|------|-------------|---------|-----|-----------|------------|
| `escapeHtml(str)` | Échappe HTML | `ToastManager.show`, `exportRoute` | — | — | — | Oui (duplique maps.php) |
| `isValidCoordinate(lat, lng)` | Valide coords | `normalizeLatLng`, `searchInVisibleBounds`, `exportRoute` | — | — | — | Oui (duplique maps.php) |
| `normalizeLatLng(point)` | Normalise en [lat,lng] | `StorageManager`, `exportRoute` | `isValidCoordinate` | — | — | Oui |
| `waitFor(predicate, callback, tries, delay)` | Attend condition | L.413 (wrappers) | — | — | — | Oui |
| `ToastManager.show(msg, type, duration)` | Affiche toast | Wrappers, `searchInVisibleBounds`, `exportRoute` | `escapeHtml` | `#maps-page` (append) | — | Oui |
| `StorageManager.saveSelectedClients()` | Sauvegarde localStorage | Wrappers | — | — | — | Oui |
| `StorageManager.loadSelectedClients()` | Charge localStorage | DOMContentLoaded | — | — | — | Oui |
| `StorageManager.saveStartPoint()` | Sauvegarde point départ | Wrapper `setStartPoint` | `normalizeLatLng` | — | — | Oui |
| `StorageManager.loadStartPoint()` | Charge point départ | DOMContentLoaded, `initDefaultStartPoint` | `normalizeLatLng` | — | — | Oui |
| `FilterManager.init()` | Attache listeners aux filtres | DOMContentLoaded (1s) | — | `#mapFilters input` | — | **Conflit** avec inline |
| `FilterManager.applyFilters()` | Applique filtres | `FilterManager.init` | `clientMarkers`, `clientsCache` | `map` | — | **Conflit** avec inline |
| `searchInVisibleBounds()` | Compte clients zone visible | Bouton "Zone visible" | `map`, `clientsCache`, `clientMarkers`, `isValidCoordinate` | — | — | Oui |
| `exportRoute(format)` | Export CSV itinéraire | Bouton "Exporter CSV" | `lastOrderedStops`, `startPoint`, `normalizeLatLng` | — | — | Oui |
| `initCollapsibleSections()` | Sections repliables | DOMContentLoaded | — | `.section-title[data-section]` | — | **Code mort** (aucun `data-section` en HTML) |
| `updateGeocodeProgress()` | Barre progression géocodage | **Jamais appelée** | — | `#geocodeProgressBar` | — | **Code mort** |

---

## 3. Éléments DOM importants

| ID / Classe | Rôle | Événements | Fonction JS |
|-------------|------|------------|-------------|
| `#maps-page` | Conteneur page | — | `ToastManager` append |
| `#mapsPanel` | Panneau paramètres | — | `togglePanel()` |
| `#panelHeader` | Header panneau | `click` | `togglePanel()` |
| `#togglePanelBtn` | Bouton replier | `click` | `togglePanel()` |
| `#btnGeo` | Ma position | `click` | `navigator.geolocation` → `setStartPoint` |
| `#btnClickStart` | Choisir sur carte | `click` | Toggle `pickStartFromMap` |
| `#btnClearStart` | Effacer départ | `click` | Supprime `startMarker`, reset `startPoint` |
| `#startInfo` | Texte info départ | — | `setStartPoint`, `btnClearStart` |
| `#startAddressInput` | Adresse départ | `keydown` (Enter) | `geocodeAddress` → `setStartPoint` |
| `#clientSearch` | Recherche client | `input` | `searchClients` (debounce) + clear |
| `#clientSearchClear` | Effacer recherche | `click` | Vide input, cache results |
| `#clientResults` | Résultats recherche | — | Rempli par `searchClients` |
| `#selectedClients` | Liste clients sélectionnés | — | `renderSelectedClients` |
| `#selectedClientsCount` | Compteur (caché) | — | Wrappers |

| ID / Classe | Rôle | Événements | Fonction JS |
|-------------|------|------------|-------------|
| `#notFoundClientsSection` | Section clients non trouvés | — | `addClientToNotFoundList` |
| `#notFoundClients` | Liste clients non trouvés | — | `addClientToNotFoundList` |
| `#btnRoute` | Calculer itinéraire | `click` | Fetch OSRM, `renderRouteSummary`, `renderTurnByTurn` |
| `#btnShowTurns` | Voir/masquer détails | `click` | Toggle `#routeTurns` |
| `#optimizeOrder` | Checkbox optimiser | — | `computeOrderedStops` si coché |
| `#btnGoogle` | Ouvrir Google Maps | `click` | `openInGoogleMaps` |
| `#routeMessage` | Message statut | — | Tous les messages |
| `#statDistance`, `#statDuration`, `#statStops`, `#statInfo` | Stats itinéraire | — | Handler `#btnRoute` |
| `#routeSteps` | Résumé étapes | — | `renderRouteSummary` |
| `#routeTurns` | Instructions détaillées | — | `renderTurnByTurn` |
| `#map` | Carte Leaflet | `click` | Départ si `pickStartFromMap` |
| `#mapLegend` | Légende | — | — |
| `#mapFilters` | Filtres | `change` (inputs) | `applyMarkerFilters` (inline) + `FilterManager` |
| `#badgeClients` | Badge nb clients | — | `updateClientsBadge` |
| `#badgeStart` | Badge départ | — | `setStartPoint`, `btnClearStart` |
| `.map-toolbar-right` | Zone droite toolbar | — | maps-enhancements ajoute "Zone visible" |
| `.route-extra` | Zone boutons extra | — | maps-enhancements ajoute "Exporter CSV" |

---

## 4. Zones d’extension

### Zone 1 : Après chargement des clients (L.1378)

```javascript
// maps.php L.1377-1379
loadAllClients();
// ==================
// Gestion du panneau repliable
```

**Pourquoi** : Les clients sont chargés, la carte est prête.  
**Risque** : Faible.  
**Exemple** : Clustering, statistiques sur les clients, pré-calcul de zones.

---

### Zone 2 : Dans `addClientToMap` après création du marqueur (L.728)

```javascript
// maps.php L.726-728
marker.bindPopup(popupContent);
clientMarkers[client.id] = marker;
// Appliquer les filtres après ajout
```

**Pourquoi** : Chaque marqueur est créé ici.  
**Risque** : Moyen.  
**Exemple** : Clustering, custom popup, icônes personnalisées.

---

### Zone 3 : Boutons toolbar (maps-enhancements.js L.373-391)

```javascript
// maps-enhancements.js L.373-391
const toolbarRight = document.querySelector('#maps-page .map-toolbar-right');
if (toolbarRight) {
    const btnSearchBounds = document.createElement('button');
    // ...
});
const routeExtra = document.querySelector('#maps-page .route-extra');
if (routeExtra) {
    const btnExport = document.createElement('button');
    // ...
}
```

**Pourquoi** : Les boutons "Zone visible" et "Exporter CSV" sont ajoutés ici.  
**Risque** : Faible.  
**Exemple** : Nouveau bouton "Centrer sur ma position", "Export GPX".

---

### Zone 4 : Dans `CONFIG` (maps.php L.266-282)

```javascript
const CONFIG = {
    SEARCH_DEBOUNCE_MS: 400,
    GEOCODE_BATCH_SIZE: 3,
    // ...
}
```

**Pourquoi** : Paramètres centralisés.  
**Risque** : Faible.  
**Exemple** : `MAX_CLUSTER_RADIUS`, `CLUSTER_ENABLED`.

---

### Zone 5 : `applyMarkerFilters` (maps.php L.1302-1330)

```javascript
function applyMarkerFilters() {
    // ...
    if (activeFilters.has('all')) {
        visible = true;
    } else {
        // Logique par type
    }
```

**Pourquoi** : Point d’entrée pour toute logique de filtrage.  
**Risque** : Moyen.  
**Exemple** : Filtre par rayon, par code postal, par date.

---

### Zone 6 : Handler `#btnRoute` (maps.php L.1629)

```javascript
document.getElementById('btnRoute').addEventListener('click', () => {
    // ...
    const url = `https://router.project-osrm.org/route/v1/driving/${coordStr}?...`;
    fetchWithTimeout(url, {}, CONFIG.FETCH_TIMEOUT_MS)
```

**Pourquoi** : Calcul d’itinéraire.  
**Risque** : Élevé.  
**Exemple** : Basculer sur `/API/osrm_route.php` pour CORS, ou ajouter un autre mode.

---

### Zone 7 : `searchClients` et affichage résultats (maps.php L.1137-1244)

```javascript
clientSearchInput.addEventListener('input', () => {
    // ...
    searchTimeout = setTimeout(async () => {
        const results = await searchClients(q);
        // ...
        results.forEach(client => {
            const item = document.createElement('div');
            // ...
            item.addEventListener('click', handleClick);
```

**Pourquoi** : Recherche et sélection.  
**Risque** : Moyen.  
**Exemple** : Autocomplétion, recherche par rayon, tri.

---

## 5. Problèmes identifiés

### Fonctions dupliquées

| Fonction | maps.php | maps-enhancements.js | Impact |
|----------|----------|----------------------|--------|
| `escapeHtml` | L.855-859 | L.14-19 | maps.php écrase après chargement. Comportement identique. |
| `isValidCoordinate` | L.1068-1076 | L.25-29 | maps.php utilise `CONFIG.COORDINATE_BOUNDS`, maps-enhancements constantes en dur. maps.php écrase. |

### Variables globales

| Variable | Fichier | Risque |
|----------|---------|--------|
| `map` | maps.php | Partagée |
| `clientMarkers` | maps.php | Partagée |
| `clientsCache` | maps.php | Partagée |
| `selectedClients` | maps.php | Partagée |
| `startPoint` | maps.php | Partagée |
| `lastOrderedStops` | maps.php | Partagée |
| `lastRouteLegs` | maps.php | Partagée |
| `activeFilters` | maps.php | Partagée |
| `clientSearchInput`, `clientResultsEl`, etc. | maps.php | Références DOM au chargement |

### Logique morte

| Élément | Emplacement | Raison |
|---------|-------------|--------|
| `createPriorityIcon` | maps.php L.626-631 | Jamais appelée |
| `initCollapsibleSections` | maps-enhancements L.396-406 | Aucun `.section-title[data-section]` en HTML |
| `updateGeocodeProgress` | maps-enhancements L.313-325 | Jamais appelée |

### Conflits script inline vs maps-enhancements.js

| Conflit | Détail |
|---------|--------|
| **Filtres** | Inline (L.1797) et `FilterManager.init()` (L.371) attachent tous deux des `change` sur `#mapFilters input`. Les deux mettent à jour des états différents (`activeFilters` vs `FilterManager.activeFilters`) et appliquent des filtres. |
| **clientSearchClear** | Inline (L.1855-1869) et maps-enhancements (L.393-407) attachent les mêmes listeners sur le même bouton. |
| **input clientSearch** | Inline attache déjà `input` pour la recherche. maps-enhancements attache un second `input` pour la visibilité du bouton clear. |

### Endpoints non utilisés

| Endpoint | Utilisé par |
|----------|-------------|
| `/API/osrm_route.php` | Aucun fichier. maps.php appelle `router.project-osrm.org` directement. |

### Incohérences de nommage

| Contexte | Problème |
|----------|----------|
| `client_geocode` | Colonne `id_client` dans la table, `client.id` dans le code. Cohérent. |
| `address` vs `address_geocode` | `address` = adresse principale, `address_geocode` = adresse pour géocodage (livraison). |
| `routeMessageEl` vs `routeMessage` | Suffixe `El` pour les éléments DOM. |

### Risques de bugs

| Risque | Emplacement | Description |
|--------|-------------|-------------|
| Double listener filtres | `#mapFilters` | Deux systèmes de filtres peuvent se désynchroniser. |
| `clientSearchInput` null | L.344 | Si le script s’exécute avant le DOM, `getElementById` retourne null. |
| `addClientToRoute` sans return | L.989 | Le wrapper attend un retour pour savoir si `StorageManager.saveSelectedClients` doit être appelé. `addClientToRoute` ne retourne rien explicitement. |

---

## 6. Plan de refactorisation léger

### À extraire

| Élément | Destination | Priorité |
|---------|-------------|----------|
| Script inline maps.php (L.262-1915) | `assets/js/maps.js` | Haute |
| Constantes `CONFIG`, `DEFAULT_*` | En tête de `maps.js` | Moyenne |
| `formatDistance`, `formatDuration`, `haversine` | `assets/js/maps-utils.js` ou module | Basse |

### À renommer

| Actuel | Proposé | Raison |
|--------|----------|--------|
| `createPriorityIcon` | Supprimer (code mort) | Jamais utilisée |
| `FilterManager` vs `applyMarkerFilters` | Un seul système de filtres | Éviter la duplication |

### À centraliser

| Élément | Action |
|---------|--------|
| `escapeHtml` | Une seule définition dans `maps.js` ou `helpers.js` |
| `isValidCoordinate` | Une seule définition, avec `CONFIG.COORDINATE_BOUNDS` |
| Listeners `clientSearchClear` | Un seul endroit (inline ou maps-enhancements) |
| Listeners filtres | Un seul endroit (inline ou `FilterManager`) |

### À laisser tel quel pour l’instant

| Élément | Raison |
|---------|--------|
| Appel direct OSRM | Fonctionne, pas de CORS. |
| Structure HTML | Stable. |
| APIs PHP | Cohérentes. |

---

## 7. Ordre de travail pour une nouvelle fonctionnalité

### 1. Définir le type de fonctionnalité

- **Filtre / affichage** → Zone 5 (`applyMarkerFilters`) ou Zone 2 (`addClientToMap`).
- **Bouton / action** → Zone 3 (maps-enhancements) ou ajout dans le HTML + handler inline.
- **Données** → Zone 1 (`loadAllClients`) ou nouvel endpoint API.
- **Itinéraire** → Zone 6 (handler `#btnRoute`).

### 2. Identifier les points d’injection

| Fichier | Rôle |
|---------|------|
| `maps.php` | HTML (nouveaux boutons/inputs), JS inline (logique principale). |
| `maps-enhancements.js` | Extensions (boutons, toasts, exports). |
| `maps.css` | Styles. |
| `API/*.php` | Nouveau endpoint si besoin. |

### 3. Ordre de modification

1. **API** : Créer ou modifier l’endpoint si nécessaire.
2. **HTML** : Ajouter les éléments dans `maps.php` (boutons, conteneurs, etc.).
3. **CSS** : Ajouter les styles dans `maps.css`.
4. **JS inline** : Logique principale (handlers, appels API, mise à jour DOM).
5. **maps-enhancements.js** : Optionnel (toasts, exports, persistance).

### 4. Points de vigilance

- Ne pas écraser les handlers existants (filtres, `clientSearchClear`).
- Vérifier que `map`, `clientMarkers`, `clientsCache` sont définis avant utilisation.
- Utiliser `fetchWithTimeout` pour les appels API.
- Tester avec et sans `maps-enhancements.js` si possible.

### 5. Exemple : ajout d’un filtre « par code postal »

1. **HTML** : Ajouter une checkbox dans `#mapFilters` avec `data-filter="codepostal"`.
2. **JS** : Étendre `applyMarkerFilters` pour gérer ce filtre (ou un input de code postal).
3. **Pas de nouvel API** : Les données sont déjà dans `clientsCache`.

### 6. Exemple : clustering

1. **HTML** : Ajouter Leaflet.markercluster (CSS + JS).
2. **JS** : Créer un `L.markerClusterGroup` et y ajouter les marqueurs au lieu de `map.addLayer(marker)` dans `addClientToMap`.
3. **Filtres** : Adapter `applyMarkerFilters` pour gérer le cluster group.

---

---

## 8. Corrections appliquées (mars 2025)

Voir `docs/MAPS_CORRECTIONS_LIVRABLE.md` pour le détail des corrections.

- Code mort supprimé : `createPriorityIcon`, `initCollapsibleSections`, `updateGeocodeProgress`
- Conflits résolus : FilterManager.init() désactivé, listeners clientSearchClear dédupliqués
- Robustesse : garde-fous DOM, retour explicite de `addClientToRoute`
- `escapeHtml` : fallback dans maps.php si maps-enhancements absent

---

*Document généré à partir du code réel. Dernière mise à jour : mars 2025.*
