# PATCH 4 : JS - Modifications dans le script existant

## Fichier : `public/maps.php`

### Modification 1 : Fonction `loadAllClients()` (ligne ~314)

**Chercher :**
```javascript
routeMessageEl.textContent = `${clientsWithCoords} client(s) chargé(s) et affiché(s) sur la carte.${clientsToGeocode.length > 0 ? ' Géocodage en arrière-plan des autres clients…' : ''}`;
routeMessageEl.className = 'maps-message success';
clientsLoaded = true;
```

**Ajouter après :**
```javascript
// Toast notification
if (typeof ToastManager !== 'undefined') {
    ToastManager.show(`${clientsWithCoords} client(s) chargés`, 'success', 2000);
}
```

---

### Modification 2 : Fonction `geocodeClientsInBackground()` (ligne ~374)

**Chercher :**
```javascript
const updateProgress = () => {
    if (processed < clientsToGeocode.length) {
        routeMessageEl.textContent = `Géocodage en cours : ${processed}/${clientsToGeocode.length} client(s) traités (${found} trouvé(s))…`;
    }
};
```

**Remplacer par :**
```javascript
const updateProgress = () => {
    if (processed < clientsToGeocode.length) {
        routeMessageEl.textContent = `Géocodage en cours : ${processed}/${clientsToGeocode.length} client(s) traités (${found} trouvé(s))…`;
        // Mettre à jour barre de progression
        if (typeof updateGeocodeProgress !== 'undefined') {
            updateGeocodeProgress(processed, clientsToGeocode.length);
        }
    }
};
```

---

### Modification 3 : Fonction `addClientToMap()` (ligne ~682)

**Chercher :**
```javascript
const marker = L.marker([client.lat, client.lng], {
    icon: createMarkerIcon(markerType)
}).addTo(map);
```

**Remplacer par :**
```javascript
const marker = L.marker([client.lat, client.lng], {
    icon: createMarkerIcon(markerType),
    clientId: client.id  // Pour filtres
}).addTo(map);
```

---

### Modification 4 : Fonction `renderSelectedClients()` (ligne ~744)

**Chercher la fin de la fonction (avant le dernier `});` ou `return;`)**

**Ajouter avant la fin :**
```javascript
// Mettre à jour le compteur
const countEl = document.getElementById('selectedClientsCount');
if (countEl) {
    countEl.textContent = selectedClients.length;
}
```

---

### Modification 5 : Fonction `geocodeClientsInBackground()` - Fin (ligne ~472)

**Chercher :**
```javascript
routeMessageEl.textContent = `Géocodage terminé : ${found} trouvé(s), ${notFound} non trouvé(s) sur ${processed} client(s) traités.`;
routeMessageEl.className = 'maps-message success';
```

**Ajouter après :**
```javascript
// Toast notification
if (typeof ToastManager !== 'undefined') {
    ToastManager.show(`Géocodage terminé : ${found} trouvé(s), ${notFound} non trouvé(s)`, found > 0 ? 'success' : 'warning', 3000);
}

// Masquer barre de progression
const progressBar = document.getElementById('geocodeProgressBar');
if (progressBar) {
    setTimeout(() => {
        progressBar.style.opacity = '0';
        setTimeout(() => progressBar.remove(), 300);
    }, 1000);
}
```

---

### Modification 6 : Fonction `addClientToRoute()` (ligne ~911)

**Chercher :**
```javascript
// Message de confirmation
if (added) {
    routeMessageEl.textContent = `Client "${client.name}" ajouté à la tournée et affiché sur la carte.`;
    routeMessageEl.className = 'maps-message success';
}
```

**Remplacer par :**
```javascript
// Message de confirmation
if (added) {
    routeMessageEl.textContent = `Client "${client.name}" ajouté à la tournée et affiché sur la carte.`;
    routeMessageEl.className = 'maps-message success';
    // Toast sera géré par le wrapper dans PATCH_3
}
```

---

## Notes importantes

1. **Ordre d'application :** Appliquer PATCH_3 (nouvelles fonctions) AVANT ces modifications
2. **Vérifications :** Tester que `ToastManager`, `StorageManager`, etc. sont bien définis avant utilisation
3. **Compatibilité :** Ces modifications sont non-bloquantes (utilisent `typeof` checks)

