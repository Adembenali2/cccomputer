# AUDIT D'ARCHITECTURE - maps.php
**Date:** 2024  
**Fichier analysé:** `public/maps.php`  
**Projet:** cccomputer

---

## A) VUE D'ENSEMBLE

### But de la page (3-5 lignes)
Page de planification de tournées clients permettant de :
- Visualiser tous les clients sur une carte interactive (OpenStreetMap via Leaflet)
- Rechercher et sélectionner des clients pour créer un itinéraire
- Définir un point de départ (géolocalisation ou clic sur carte)
- Calculer un itinéraire optimisé entre le départ et les clients sélectionnés (OSRM)
- Afficher les instructions détaillées de navigation (tour par tour)
- Géocoder automatiquement les adresses manquantes via Nominatim

**Technologies:** PHP backend, JavaScript vanilla (inline), Leaflet 1.9.4, OSRM (routage), Nominatim (géocodage)

---

## B) ARBRE DES FICHIERS TOUCHÉS

```
maps.php (1581 lignes)
│
├── PHP Includes (côté serveur)
│   ├── includes/auth_role.php
│   │   └── authorize_page('maps', ['Admin', 'Dirigeant'])
│   │   └── Vérifie session + ACL via user_permissions
│   │
│   ├── includes/helpers.php
│   │   ├── getPdo() → PDO instance (DatabaseConnection singleton)
│   │   └── h() → escapeHtml() pour XSS protection
│   │
│   └── source/templates/header.php
│       └── Header HTML commun (navigation, menu)
│
├── CSS
│   ├── assets/css/main.css (styles globaux)
│   └── assets/css/maps.css (styles spécifiques carte)
│
├── JavaScript (inline dans maps.php, lignes 191-1579)
│   └── ~1388 lignes de JS vanilla
│
├── Librairies externes (CDN)
│   ├── Leaflet 1.9.4 (CSS + JS)
│   │   └── OpenStreetMap tiles
│   └── OSRM (router.project-osrm.org) - API publique
│
└── API Endpoints (appelés en AJAX)
    ├── API/maps_get_all_clients.php
    │   └── GET → {ok: true, clients: [...]}
    │
    ├── API/maps_search_clients.php
    │   └── GET ?q=...&limit=20 → {ok: true, clients: [...]}
    │
    ├── API/maps_geocode.php
    │   └── GET ?address=... → {ok: true, lat: ..., lng: ...}
    │
    └── API/maps_geocode_client.php
        └── GET ?client_id=...&address=... → {ok: true, lat: ..., lng: ...}
```

---

## C) MODULES (Tableau)

| Module | Fichiers | Responsabilités | Problèmes identifiés |
|--------|----------|-----------------|----------------------|
| **Auth/Session** | `includes/auth_role.php`<br>`includes/auth.php` | - Vérifie session utilisateur<br>- Autorise accès selon rôle (Admin/Dirigeant)<br>- Système ACL via `user_permissions` | ✅ Bon : Utilise PDO préparé, redirection 302<br>⚠️ Pas de CSRF token sur les endpoints API |
| **Data/DB** | `maps.php` (lignes 15-23)<br>`API/maps_*.php` | - Compte clients avec adresse complète<br>- Récupère clients avec coordonnées<br>- Recherche clients (LIKE multi-champs)<br>- Stocke géocodage dans `client_geocode` | ✅ Bon : PDO préparé, validation<br>⚠️ Requête COUNT côté serveur (ligne 16) pourrait être optimisée<br>⚠️ Pas de pagination (charge tous les clients) |
| **API/AJAX** | `API/maps_get_all_clients.php`<br>`API/maps_search_clients.php`<br>`API/maps_geocode.php`<br>`API/maps_geocode_client.php` | - Retourne JSON standardisé<br>- Gestion erreurs avec try/catch<br>- Cache géocodage (24h fichiers)<br>- Vérifie session avant traitement | ✅ Bon : Headers JSON, gestion erreurs<br>⚠️ Pas de rate limiting<br>⚠️ Cache fichiers (pas de TTL configurable)<br>⚠️ Pas de validation CSRF sur GET |
| **UI/Sidebar** | `maps.php` (lignes 61-173) | - Panneau gauche : recherche, sélection clients, options route<br>- Badges statistiques (distance, durée, stops)<br>- Liste clients sélectionnés avec contrôles (↑↓, urgence, ✕) | ✅ Bon : Structure HTML sémantique<br>⚠️ Tout le JS est inline (1580 lignes)<br>⚠️ Pas de séparation concerns (HTML/JS/CSS) |
| **UI/Search** | `maps.php` (lignes 949-1107) | - Recherche temps réel (debounce 400ms)<br>- Cache résultats (1 min TTL)<br>- AbortController pour annuler requêtes | ✅ Bon : Debounce, cache, annulation<br>⚠️ Pas de limite max caractères côté client |
| **Carte/Leaflet** | `maps.php` (lignes 477-725) | - Init carte Leaflet (France par défaut)<br>- Marqueurs clients (couleurs selon SAV/livraison)<br>- Popups avec infos client<br>- Géolocalisation navigateur<br>- fitBounds() pour ajuster vue | ✅ Bon : Utilisation correcte Leaflet<br>⚠️ Pas de clustering (peut ralentir avec 1000+ markers)<br>⚠️ Pas de lazy loading markers (charge tout au démarrage) |
| **Routing/OSRM** | `maps.php` (lignes 1447-1575) | - Calcul itinéraire via OSRM public API<br>- Optimisation ordre (proximité + urgence)<br>- Affichage route sur carte (L.geoJSON)<br>- Instructions tour par tour (buildInstruction) | ✅ Bon : Retry logic, timeout<br>⚠️ Dépendance service externe (OSRM public)<br>⚠️ Pas de fallback si OSRM down |
| **Géocodage** | `maps.php` (lignes 366-475)<br>`API/maps_geocode*.php` | - Géocodage batch en arrière-plan (lots de 3)<br>- Retry automatique (3 tentatives)<br>- Respect limite Nominatim (1 req/sec)<br>- Liste clients non trouvés | ✅ Bon : Batch, retry, respect limites<br>⚠️ Pas de queue persistante (perdu si refresh)<br>⚠️ Pas de progression détaillée (juste message) |

---

## D) FLUX D'EXÉCUTION (Diagramme texte)

### 1. Chargement initial de la page

```
[PHP] maps.php
  ├─→ require auth_role.php
  │     └─→ authorize_page('maps', ['Admin', 'Dirigeant'])
  │           └─→ checkPagePermission() → DB query user_permissions
  │
  ├─→ require helpers.php
  │     └─→ getPdo() → DatabaseConnection::getInstance()
  │
  ├─→ [SQL] SELECT COUNT(*) FROM clients WHERE adresse IS NOT NULL...
  │     └─→ $totalClients (affiché dans toolbar)
  │
  └─→ [HTML] Génère structure page (sidebar + carte)

[Browser] Charge page
  ├─→ Charge CSS (main.css + maps.css)
  ├─→ Charge Leaflet (CDN)
  └─→ Exécute <script> inline (ligne 191)

[JS] Initialisation
  ├─→ map = L.map('map') → Init Leaflet
  ├─→ L.tileLayer(...) → Ajoute OpenStreetMap tiles
  ├─→ map.setView([46.5, 2.0], 6) → Vue France
  └─→ loadAllClients() → Appelé en fin de script (ligne 1578)
```

### 2. Chargement des clients

```
[JS] loadAllClients()
  ├─→ fetch('/API/maps_get_all_clients.php')
  │     └─→ [PHP] maps_get_all_clients.php
  │           ├─→ Vérifie session
  │           ├─→ [SQL] SELECT clients + LEFT JOIN client_geocode
  │           │         + sous-requêtes COUNT livraisons/SAV
  │           └─→ Retourne JSON {ok: true, clients: [...]}
  │
  ├─→ Parse JSON → data.clients
  ├─→ Pour chaque client:
  │     ├─→ clientsCache.set(client.id, client)
  │     ├─→ Si client.lat && client.lng:
  │     │     └─→ addClientToMap(client, false)
  │     │           └─→ L.marker([lat, lng]) → Ajoute sur carte
  │     └─→ Sinon si client.needsGeocode:
  │           └─→ clientsToGeocode.push(client)
  │
  ├─→ map.fitBounds(allCoords) → Ajuste vue
  └─→ Si clientsToGeocode.length > 0:
        └─→ geocodeClientsInBackground(clientsToGeocode)
```

### 3. Géocodage en arrière-plan

```
[JS] geocodeClientsInBackground(clients)
  ├─→ Pour chaque lot de 3 clients:
  │     ├─→ Pour chaque client (en parallèle):
  │     │     ├─→ fetch('/API/maps_geocode_client.php?client_id=...&address=...')
  │     │     │     └─→ [PHP] maps_geocode_client.php
  │     │     │           ├─→ Vérifie cache fichier (24h)
  │     │     │           ├─→ Si pas de cache:
  │     │     │           │     └─→ curl Nominatim (1 req/sec max)
  │     │     │           ├─→ INSERT/UPDATE client_geocode
  │     │     │           └─→ Retourne {ok: true, lat: ..., lng: ...}
  │     │     │
  │     │     ├─→ Si succès:
  │     │     │     ├─→ clientsCache.set(client.id, updatedClient)
  │     │     │     └─→ addClientToMap(updatedClient, false)
  │     │     └─→ Si échec:
  │     │           └─→ addClientToNotFoundList(client)
  │     │
  │     └─→ Attendre 1.5s entre lots (respect Nominatim)
  │
  └─→ Affiche message final (X trouvés, Y non trouvés)
```

### 4. Recherche de clients

```
[User] Tape dans input#clientSearch
  └─→ [JS] EventListener 'input' (ligne 1009)
        ├─→ clearTimeout(searchTimeout)
        ├─→ Si query.length < 2: return
        ├─→ Affiche "Recherche en cours..."
        └─→ setTimeout(400ms) → Debounce
              └─→ searchClients(query)
                    ├─→ Vérifie cache (searchCache, TTL 1 min)
                    ├─→ Si pas de cache:
                    │     ├─→ fetch('/API/maps_search_clients.php?q=...&limit=20')
                    │     │     └─→ [PHP] maps_search_clients.php
                    │     │           ├─→ [SQL] SELECT ... WHERE raison_sociale LIKE ? OR ...
                    │     │           └─→ Retourne JSON {ok: true, clients: [...]}
                    │     └─→ Mettre en cache
                    │
                    └─→ Affiche résultats dans #clientResults
                          └─→ [User] Clique sur résultat
                                └─→ addClientToRoute(client)
```

### 5. Ajout client à la tournée

```
[JS] addClientToRoute(client)
  ├─→ Si client déjà sélectionné: return
  ├─→ Si !isValidCoordinate(client.lat, client.lng):
  │     ├─→ loadClientWithGeocode(client)
  │     │     └─→ fetch('/API/maps_geocode_client.php?client_id=...')
  │     │           └─→ Géocode et sauvegarde coordonnées
  │     └─→ Attendre résultat
  │
  ├─→ selectedClients.push({id: client.id, priority: 1})
  ├─→ addClientToMap(client, false)
  ├─→ map.setView([client.lat, client.lng], 15)
  └─→ renderSelectedClients()
        └─→ Affiche chips avec contrôles (↑↓, urgence, ✕)
```

### 6. Calcul d'itinéraire

```
[User] Clique "Calculer l'itinéraire"
  └─→ [JS] EventListener 'btnRoute' (ligne 1451)
        ├─→ Vérifie startPoint existe
        ├─→ Vérifie selectedClients.length > 0
        ├─→ Si optimizeOrder checked:
        │     └─→ computeOrderedStops(startPoint, clients)
        │           └─→ Algorithme glouton (proximité + urgence)
        │
        ├─→ Construit URL OSRM:
        │     └─→ https://router.project-osrm.org/route/v1/driving/{coords}?...
        │
        ├─→ fetchWithTimeout(url, 15s)
        │     └─→ [OSRM API] Retourne {routes: [{geometry, legs, distance, duration}]}
        │
        ├─→ routeLayer = L.geoJSON(route.geometry) → Trace route sur carte
        ├─→ map.fitBounds(route.geometry.coordinates)
        ├─→ Affiche stats (distance, durée, stops)
        ├─→ renderRouteSummary(legs) → Résumé grandes étapes
        └─→ renderTurnByTurn(legs) → Instructions détaillées
```

---

## E) VARIABLES ET ÉTATS IMPORTANTS (Frontend)

### Variables globales (lignes 224-251)

| Variable | Type | Description | Problème |
|----------|------|-------------|----------|
| `map` | `L.Map` | Instance Leaflet | ✅ OK |
| `clientMarkers` | `Object` | `{clientId: L.Marker}` | ⚠️ Pas de nettoyage si client supprimé |
| `clientsCache` | `Map` | `id → {client data}` | ✅ Bon : Map pour performance |
| `searchCache` | `Map` | `query → {results, timestamp}` | ✅ Bon : Cache avec TTL |
| `selectedClients` | `Array` | `[{id, priority}]` | ⚠️ Pas de persistence (perdu si refresh) |
| `startPoint` | `Array\|null` | `[lat, lng]` | ⚠️ Pas de persistence |
| `startMarker` | `L.Marker\|null` | Marqueur départ | ✅ OK |
| `routeLayer` | `L.GeoJSON\|null` | Couche route OSRM | ✅ OK |
| `lastOrderedStops` | `Array` | Clients dans ordre route | ⚠️ Pas de persistence |
| `lastRouteLegs` | `Array` | Legs OSRM | ⚠️ Pas de persistence |
| `notFoundClientsSet` | `Set` | IDs clients non géocodés | ✅ OK |

### Configuration (lignes 197-211)

```javascript
CONFIG = {
    SEARCH_DEBOUNCE_MS: 400,
    GEOCODE_BATCH_SIZE: 3,
    GEOCODE_BATCH_DELAY_MS: 1500,
    FETCH_TIMEOUT_MS: 15000,
    MAX_RETRIES: 3,
    RETRY_DELAY_MS: 1000,
    MAX_CLIENTS_PER_ROUTE: 20,
    COORDINATE_BOUNDS: {...}
}
```
✅ Bon : Centralisé, facile à modifier

---

## F) ZONES À RISQUE ET POINTS D'AMÉLIORATION

### 🔴 SÉCURITÉ

| Problème | Localisation | Impact | Solution |
|----------|--------------|--------|----------|
| **Pas de CSRF token sur API** | `API/maps_*.php` | Attaque CSRF possible | Ajouter token CSRF dans headers ou query params |
| **XSS potentiel** | `maps.php` ligne 687-707 | Injection HTML dans popups | ✅ Déjà protégé avec `escapeHtml()` |
| **SQL Injection** | `maps.php` ligne 16 | Requête COUNT non préparée | ⚠️ Requête statique (pas de paramètres), mais préférer préparée |
| **Validation input limitée** | `API/maps_search_clients.php` | Pas de limite max caractères | Ajouter `maxlength` côté client + validation serveur |
| **Pas de rate limiting** | Tous les endpoints API | DoS possible | Ajouter rate limiting (ex: 100 req/min par IP) |

### 🟡 PERFORMANCE

| Problème | Localisation | Impact | Solution |
|----------|--------------|--------|----------|
| **Charge tous les clients au démarrage** | `loadAllClients()` | Lent si 1000+ clients | Pagination ou lazy loading par viewport |
| **Pas de clustering markers** | `addClientToMap()` | Ralentit avec 500+ markers | Utiliser Leaflet.markercluster |
| **Requête COUNT inutile** | `maps.php` ligne 16 | Requête SQL supplémentaire | Récupérer depuis API (déjà retourné) |
| **Géocodage batch peut être long** | `geocodeClientsInBackground()` | Bloque UI si 100+ clients | Web Worker ou queue avec progression |
| **Pas de cache HTTP** | API endpoints | Requêtes répétées | Ajouter headers Cache-Control |

### 🟠 MAINTENABILITÉ

| Problème | Localisation | Impact | Solution |
|----------|--------------|--------|----------|
| **1580 lignes JS inline** | `maps.php` | Difficile à maintenir | Extraire dans `assets/js/maps.js` |
| **Mélange PHP/HTML/JS** | `maps.php` | Code spaghetti | Séparer en templates + modules JS |
| **Duplication logique géocodage** | `geocodeAddress()` + `loadClientWithGeocode()` | Code dupliqué | Factoriser en fonction unique |
| **Pas de gestion d'erreurs centralisée** | Multiple try/catch | Logs dispersés | Créer ErrorHandler centralisé |
| **Pas de tests** | Aucun | Risque de régression | Ajouter tests unitaires (PHPUnit + Jest) |

---

## G) PLAN D'ACTION (Priorités)

### 🔥 URGENT (Semaine 1)

1. **Séparer JS inline → fichier externe**
   - Créer `assets/js/maps.js`
   - Déplacer tout le `<script>` (lignes 191-1579)
   - Tester que tout fonctionne

2. **Ajouter CSRF protection sur API**
   - Ajouter token dans `maps.php` (déjà `ensureCsrfToken()` disponible)
   - Vérifier token dans chaque endpoint API
   - Passer token via header `X-CSRF-Token`

3. **Optimiser chargement clients**
   - Limiter à 100 clients par défaut
   - Ajouter pagination ou lazy loading par viewport
   - Afficher indicateur de chargement

### ⚡ IMPORTANT (Semaine 2-3)

4. **Ajouter clustering markers**
   - Installer `leaflet.markercluster`
   - Remplacer `addClientToMap()` pour utiliser clustering
   - Tester avec 500+ clients

5. **Améliorer gestion erreurs**
   - Créer `ErrorHandler` centralisé
   - Logger toutes les erreurs (fichier + console)
   - Afficher messages utilisateur clairs

6. **Ajouter rate limiting**
   - Limiter 100 req/min par IP sur endpoints API
   - Retourner 429 si limite dépassée
   - Message utilisateur explicite

### 📋 MOYEN TERME (Mois 1-2)

7. **Refactorer architecture**
   - Séparer en modules JS (MapManager, RouteCalculator, Geocoder)
   - Utiliser classes ES6
   - Importer via modules (import/export)

8. **Ajouter persistence**
   - Sauvegarder `selectedClients` dans localStorage
   - Restaurer au rechargement page
   - Sauvegarder `startPoint` aussi

9. **Améliorer UX géocodage**
   - Web Worker pour géocodage batch
   - Barre de progression détaillée
   - Possibilité d'annuler

10. **Tests et documentation**
    - Tests unitaires (fonctions utilitaires)
    - Tests d'intégration (API endpoints)
    - Documentation JSDoc pour fonctions JS

---

## H) RÉSUMÉ EXÉCUTIF

**Points forts:**
- ✅ Architecture claire (séparation PHP/JS)
- ✅ Utilisation correcte PDO (préparé)
- ✅ Gestion erreurs robuste (try/catch, retry)
- ✅ Respect limites Nominatim (batch, délais)
- ✅ Code fonctionnel et bien commenté

**Points faibles:**
- ⚠️ JS inline (1580 lignes) → difficile à maintenir
- ⚠️ Pas de CSRF protection sur API
- ⚠️ Charge tous les clients (pas de pagination)
- ⚠️ Pas de clustering (ralentit avec beaucoup de markers)

**Recommandation principale:**
**Séparer le JS en fichier externe** (priorité #1) puis **ajouter CSRF protection** (priorité #2). Le reste peut être fait progressivement.

---

**Fin du rapport d'audit**

