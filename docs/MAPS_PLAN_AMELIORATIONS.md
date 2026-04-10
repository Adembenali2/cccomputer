# Plan d'amélioration – Page Maps

Document de référence pour l'évolution fonctionnelle. Aucune modification de code.

---

## 1. Analyse rapide de l'existant

### 1.1 Fonctionnement actuel

La page Maps permet de planifier des tournées clients :

1. **Point de départ** : géolocalisation, adresse saisie, ou clic sur la carte
2. **Sélection des clients** : recherche en temps réel (nom, code, adresse) → ajout à la tournée
3. **Calcul d'itinéraire** : OSRM (gratuit) avec option d'optimisation (proximité + urgence)
4. **Affichage** : marqueurs colorés (vert/bleu/jaune/rouge selon SAV/livraison), filtres, légende

**Flux technique :**
- Chargement : `loadAllClients()` → `/API/maps_get_all_clients.php` → géocodage en arrière-plan si besoin
- Recherche : `searchClients()` → `/API/maps_search_clients.php` (debounce 400 ms)
- Itinéraire : fetch direct vers `router.project-osrm.org`
- Persistance : localStorage (clients sélectionnés, point départ) via maps-enhancements.js

### 1.2 Fonctionnalités principales déjà présentes

| Fonctionnalité | Implémentation |
|----------------|-----------------|
| Carte Leaflet + OSM | `map`, `L.tileLayer`, `L.marker` |
| Marqueurs par type | `createMarkerIcon`, `getMarkerColor`, `markerType` |
| Filtres (Tous, Normal, Livraison, SAV) | `applyMarkerFilters`, `activeFilters` |
| Recherche clients | `searchClients`, `maps_search_clients.php` |
| Point départ | `setStartPoint`, géoloc, géocodage |
| Optimisation ordre | `computeOrderedStops` (haversine + priorité) |
| Itinéraire OSRM | Handler `#btnRoute`, `renderRouteSummary`, `renderTurnByTurn` |
| Export CSV | `exportRoute` (maps-enhancements) |
| Zone visible | `searchInVisibleBounds` (toast + highlight) |
| Google Maps | `openInGoogleMaps` (URL avec waypoints) |
| Clients non géocodés | `addClientToNotFoundList`, `notFoundClientsSection` |
| Persistance session | `StorageManager` (localStorage) |

### 1.3 Limites actuelles

| Catégorie | Limite |
|-----------|--------|
| **UX** | Pas de clustering → carte illisible avec beaucoup de clients |
| **UX** | Popups basiques (nom, adresse, code) |
| **UX** | Pas de fiche client enrichie au clic |
| **Filtres** | Uniquement par type (normal/livraison/SAV), pas de ville/code postal |
| **Recherche** | Pas de tri des résultats, pas de filtre zone visible |
| **Tournée** | Pas de sauvegarde nommée, pas de rechargement d'une tournée |
| **Export** | CSV uniquement, pas de GPX/PDF |
| **Données** | Clients non géocodés listés mais pas d'outil de correction |
| **Performance** | Tous les clients chargés d'un coup (jusqu'à 5000) |
| **Code** | ~1200 lignes JS inline dans maps.php |

---

## 2. Liste des améliorations possibles

### 2.1 Améliorations carte

| Amélioration | Description |
|--------------|-------------|
| **Clustering des marqueurs** | Regrouper les marqueurs proches en clusters avec compteur |
| **Meilleure lisibilité** | Réduction du bruit visuel, zoom adaptatif |
| **Amélioration des popups** | Lien vers fiche client, bouton "Ajouter à la tournée", infos SAV/livraison détaillées |

### 2.2 Améliorations filtres et recherche

| Amélioration | Description |
|--------------|-------------|
| **Filtres avancés** | Ville, code postal, rayon (distance du départ), type client |
| **Recherche zone visible** | Lister les clients dans la zone visible (améliorer `searchInVisibleBounds`) |
| **Tri des résultats** | Par distance, par nom, par urgence |

### 2.3 Améliorations tournée / itinéraire

| Amélioration | Description |
|--------------|-------------|
| **Optimisation ordre** | Améliorer `computeOrderedStops` (TSP, contraintes) |
| **Gestion priorités** | Priorité SAV/livraison plus visible dans l'interface |
| **Sauvegarde tournée** | Nommer et sauvegarder une tournée (backend ou localStorage) |
| **Rechargement tournée** | Charger une tournée sauvegardée |
| **Export GPX** | Format GPS pour navigateurs |
| **Export PDF** | Itinéraire imprimable avec carte |
| **Google Maps** | Déjà présent (améliorer si besoin) |

### 2.4 Améliorations UX

| Amélioration | Description |
|--------------|-------------|
| **Fiche client enrichie** | Panneau ou modal au clic sur marqueur |
| **Visualisation clients sélectionnés** | Marqueurs distincts, numérotation sur la carte |
| **Panneau latéral** | Sections repliables, organisation plus claire |
| **Statistiques rapides** | Clients affichés, distance totale, zone visible |

### 2.5 Améliorations données

| Amélioration | Description |
|--------------|-------------|
| **Clients non géocodés** | Liste + actions (corriger, ignorer) |
| **Correction adresse** | Champ éditable pour corriger une adresse et re-géocoder |
| **Diagnostic adresse** | Indiquer pourquoi une adresse n'a pas été trouvée |

---

## 3. Classement par priorité

### Facile

| Amélioration | Valeur utilisateur | Difficulté | Fichiers | Fonctions |
|--------------|-------------------|-------------|----------|-----------|
| **Amélioration popups** | Moyenne | Faible | maps.php | `addClientToMap` (popupContent) |
| **Tri résultats recherche** | Moyenne | Faible | maps.php | Handler `clientSearchInput` input |
| **Statistiques rapides** | Moyenne | Faible | maps.php, maps.css | Zone toolbar, `updateClientsBadge` |
| **Panneau sections repliables** | Faible | Faible | maps.php, maps.css | HTML `data-section`, JS toggle |
| **Filtres ville / code postal** | Haute | Moyenne-faible | maps.php, maps_search_clients.php | `applyMarkerFilters`, API |

### Moyenne

| Amélioration | Valeur utilisateur | Difficulté | Fichiers | Fonctions |
|--------------|-------------------|-------------|----------|-----------|
| **Clustering** | Haute | Moyenne | maps.php, maps.css | `addClientToMap`, `L.markerClusterGroup` |
| **Recherche zone visible** | Haute | Moyenne | maps-enhancements.js | `searchInVisibleBounds` (liste + actions) |
| **Fiche client enrichie** | Haute | Moyenne | maps.php, maps.css | Nouveau panneau/modal, clic marqueur |
| **Export GPX** | Moyenne | Moyenne | maps-enhancements.js | `exportRoute` (nouveau format) |
| **Sauvegarde tournée** | Haute | Moyenne | maps.php, maps-enhancements, API | `StorageManager`, `selectedClients` |
| **Rechargement tournée** | Haute | Moyenne | maps.php, API | `loadAllClients`, `renderSelectedClients` |
| **Correction adresse** | Moyenne | Moyenne | maps.php, maps_geocode_client.php | `addClientToNotFoundList`, `notFoundClientsSection` |
| **Marqueurs clients sélectionnés** | Moyenne | Moyenne | maps.php | `addClientToMap`, `createMarkerIcon` |

### Avancée

| Amélioration | Valeur utilisateur | Difficulté | Fichiers | Fonctions |
|--------------|-------------------|-------------|----------|-----------|
| **Optimisation TSP** | Haute | Élevée | maps.php | `computeOrderedStops` | 
| **Export PDF** | Moyenne | Élevée | Nouveau | Génération PDF côté serveur |
| **Filtres par rayon** | Haute | Moyenne-élevée | maps.php, API | `applyMarkerFilters`, `haversine` |
| **Chargement progressif** | Moyenne | Élevée | maps.php, API | `loadAllClients`, pagination |

---

## 4. Plan d'évolution recommandé

Phase 1 – Lisibilité et impact rapide  
1. **Clustering** | Impact immédiat sur la lisibilité  
2. **Amélioration popups** | Lien fiche client, bouton "Ajouter à la tournée"  
3. **Statistiques rapides** | Clients affichés, distance zone visible  

Phase 2 – Filtres et recherche  
4. **Filtres avancés** | Ville, code postal (via API existante)  
5. **Recherche zone visible** | Lister + sélectionner les clients de la zone  
6. **Tri résultats** | Par distance, nom, urgence  

Phase 3 – Tournée et export  
7. **Sauvegarde tournée** | Nom + localStorage ou backend  
8. **Rechargement tournée** | Charger une tournée sauvegardée  
9. **Export GPX** | Format GPS  

Phase 4 – Fiche client et données  
10. **Fiche client enrichie** | Panneau au clic sur marqueur  
11. **Correction adresse** | Édition + re-géocodage pour clients non trouvés  
12. **Marqueurs visuels sélectionnés** | Numérotation, style distinct  

Phase 5 – Avancé (optionnel)  
13. **Optimisation TSP** | Algorithme plus performant  
14. **Export PDF** | Génération PDF  

---

## 5. Vérification technique

### 5.1 Clustering

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `addClientToMap` : ajouter les marqueurs à un `L.markerClusterGroup` au lieu de `map` directement |
| **Dépendance** | Leaflet.markercluster (CDN : `https://unpkg.com/leaflet.markercluster@1.5.3/dist/...`) |
| **Risques** | Compatibilité avec `applyMarkerFilters` (le cluster group doit respecter les filtres) |
| **Intégration** | Créer `markerClusterGroup` avant `loadAllClients`, passer `markerClusterGroup` au lieu de `map` dans `addClientToMap` |

### 5.2 Filtres avancés (ville, code postal)

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `applyMarkerFilters`, `maps_search_clients.php` (déjà filtre par type) |
| **Données** | `clientsCache` contient déjà `ville`, `code_postal` |
| **Risques** | Faible |
| **Intégration** | Ajouter inputs dans `#mapFilters` ou panneau, étendre la logique dans `applyMarkerFilters` |

### 5.3 Recherche zone visible

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `searchInVisibleBounds` (maps-enhancements.js L.290) |
| **Fonction actuelle** | Toast + highlight z-index |
| **Risques** | Faible |
| **Intégration** | Afficher une liste des clients dans la zone, avec bouton "Ajouter à la tournée" pour chacun |

### 5.4 Fiche client enrichie

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `addClientToMap` (popup) ou nouvel événement `click` sur marqueur |
| **Données** | `clientsCache` ou appel API fiche client |
| **Risques** | Moyen (nouveau panneau/modal, gestion des états) |
| **Intégration** | Ouvrir un panneau latéral ou modal au clic, avec lien vers `/public/client_fiche.php?id=X` |

### 5.5 Sauvegarde / rechargement tournée

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `StorageManager` (localStorage) ou nouvel API backend |
| **Données** | `selectedClients`, `startPoint`, `lastOrderedStops` |
| **Risques** | Moyen (format de persistance, nommage) |
| **Intégration** | Étendre `StorageManager` ou créer `maps_save_tour.php` / `maps_load_tour.php` |

### 5.6 Export GPX

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `exportRoute` (maps-enhancements.js L.347) |
| **Format** | XML GPX (waypoints + track) |
| **Risques** | Faible |
| **Intégration** | Ajouter `if (format === 'gpx')` avec génération du XML côté client |

### 5.7 Correction adresse

| Élément | Détail |
|---------|--------|
| **Où intervenir** | `addClientToNotFoundList`, `notFoundClientsSection` |
| **API** | `maps_geocode_client.php` (accepte déjà `address` en paramètre) |
| **Risques** | Faible |
| **Intégration** | Ajouter un champ input dans chaque `not-found-client-item`, bouton "Re-géocoder" |

---

## 6. Synthèse

| Priorité | Améliorations | Effort estimé |
|----------|---------------|---------------|
| **Phase 1** | Clustering, popups, stats | 1–2 jours |
| **Phase 2** | Filtres avancés, recherche zone, tri | 1–2 jours |
| **Phase 3** | Sauvegarde, rechargement, GPX | 2–3 jours |
| **Phase 4** | Fiche client, correction adresse, marqueurs sélectionnés | 2–3 jours |
| **Phase 5** | TSP, PDF | 3+ jours |

**Recommandation** : Démarrer par le clustering (impact immédiat) puis les améliorations de popups et de filtres pour maximiser la valeur sans modifier l’architecture existante.
