# Tournée du jour – Maps

## Objectif

Au chargement de la page Maps, la tournée du jour est chargée automatiquement à partir des livraisons et SAV programmés aujourd'hui.

---

## Logique métier

### Livraison du jour
- **Table :** `livraisons`
- **Date :** `date_prevue = CURDATE()`
- **Statuts retenus :** tout sauf `livree`, `annulee`

### SAV du jour
- **Table :** `sav`
- **Date :** `COALESCE(date_intervention_prevue, date_ouverture) = CURDATE()`
- **Statuts retenus :** tout sauf `resolu`, `annule`

### Date
- Utilisation de la date serveur (`date('Y-m-d')`) pour éviter les écarts de fuseau.

---

## Fichiers modifiés / créés

| Fichier | Rôle |
|---------|------|
| `API/maps_get_today_route.php` | **Nouveau** – API qui retourne les IDs clients de la tournée du jour |
| `public/maps.php` | `loadTodayRoute()`, chaînage après `loadAllClients()` |
| `assets/js/maps-enhancements.js` | Priorité tournée du jour vs localStorage, date de sauvegarde |

---

## Flux d'exécution

1. `loadAllClients()` charge les clients et les marqueurs.
2. `loadTodayRoute()` appelle l’API et récupère les IDs du jour.
3. Si la tournée du jour contient des clients :
   - Remplacement de `selectedClients` par ces clients
   - Ajout sur la carte via `addClientToMap`
   - Affichage dans la liste des clients sélectionnés
   - `window.mapsTodayRouteLoaded = true`
4. Si la tournée du jour est vide :
   - Message : « Aucune livraison ou aucun SAV programmé aujourd'hui. »
   - `window.mapsTodayRouteLoaded = false`
5. Après 2,5 s, `maps-enhancements.js` :
   - Ne restaure pas depuis localStorage si `mapsTodayRouteLoaded === true`
   - Restaure depuis localStorage si `mapsTodayRouteLoaded === false` et que la date sauvegardée est aujourd’hui
   - Restaure toujours le point de départ depuis localStorage

---

## Priorité tournée du jour / localStorage

| Situation | Comportement |
|-----------|--------------|
| Tournée du jour avec clients | Utilisation de la tournée du jour, pas de restauration localStorage |
| Tournée du jour vide | Restauration depuis localStorage uniquement si la date sauvegardée est aujourd’hui |
| localStorage d’un autre jour | Ignoré pour les clients sélectionnés |
| Point de départ | Toujours restauré depuis localStorage s’il existe |

---

## API `maps_get_today_route.php`

**Réponse :**
```json
{
  "ok": true,
  "date": "2025-03-06",
  "clientIds": [1, 2, 3],
  "count": 3
}
```

**Règles :**
- Authentification requise (session)
- Date serveur : `date('Y-m-d')`
- Gestion de `date_intervention_prevue` si la colonne existe

---

## Cas limites

- **Aucun client programmé aujourd’hui :** message affiché, page inchangée.
- **Client de la tournée absent du cache :** non ajouté (client sans adresse complète).
- **Erreur API :** log en console, pas de blocage.
- **Changement de jour :** à chaque chargement, la tournée du jour est recalculée.

---

## Vérifications

- [x] Livraison aujourd’hui → client ajouté automatiquement
- [x] SAV aujourd’hui → client ajouté automatiquement
- [x] Livraison + SAV aujourd’hui → client en rouge (both)
- [x] Aucun client aujourd’hui → message affiché, page stable
- [x] Filtres inchangés
- [x] Clustering inchangé
- [x] Recherche inchangée
- [x] Calcul d’itinéraire inchangé
- [x] Point de départ conservé (localStorage)
