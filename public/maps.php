<?php
// /public/maps.php
// Page de planification de trajets clients (version 100% gratuite : OpenStreetMap + OSRM)

require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/db.php';

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Helper pour requêtes sécurisées
function safeFetchAll(PDO $pdo, string $sql, array $params = [], string $context = 'query'): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (PDOException $e) {
        error_log("Erreur SQL ({$context}) : " . $e->getMessage());
        return [];
    }
}

// Récupérer le nombre total de clients pour l'affichage
$totalClients = (int)($pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn() ?? 0);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Carte & planification de tournée</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- CSS globaux -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- CSS spécifique à la page carte -->
    <link rel="stylesheet" href="/assets/css/maps.css">

    <!-- Leaflet (OpenStreetMap) -->
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
</head>
<body class="page-maps">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Carte & planification de tournée</h1>
        <p class="page-sub">
            Visualisez vos clients sur une carte et préparez un itinéraire (départ de chez vous + plusieurs clients).<br>
            Version <strong>100% gratuite</strong> basée sur <strong>OpenStreetMap</strong> + <strong>OSRM</strong> (pas de clé API, pas de CB).
        </p>
    </header>

    <section class="maps-layout">
        <!-- PANNEAU GAUCHE : PARAMÈTRES / CLIENTS -->
        <aside class="maps-panel" aria-label="Panneau de planification de tournée">
            <h2>Planifier un trajet</h2>
            <small>1. Définissez un point de départ, 2. Sélectionnez les clients, 3. Calculez l’itinéraire.</small>

            <!-- 1. Point de départ -->
            <div>
                <div class="section-title">1. Point de départ</div>
                <div class="btn-group">
                    <button type="button" id="btnGeo" class="primary">📍 Ma position</button>
                    <button type="button" id="btnClickStart">🖱️ Choisir sur la carte</button>
                    <button type="button" id="btnClearStart">❌ Effacer</button>
                </div>
                <div id="startInfo" class="hint">
                    Aucun point de départ défini.
                </div>
            </div>

            <!-- 2. Clients à visiter -->
            <div>
                <div class="section-title">2. Clients à visiter</div>
                <p class="hint">
                    Recherchez un client (nom, code, adresse) puis ajoutez-le à la tournée.
                    La recherche se fait en temps réel dans la base de données (minimum 2 caractères).
                </p>

                <div class="client-search">
                    <input type="search"
                           id="clientSearch"
                           class="client-search-input"
                           placeholder="Rechercher un client (nom, code, adresse)…"
                           autocomplete="off">
                    <div id="clientResults"
                         class="client-results"
                         aria-label="Résultats de recherche de clients">
                        <!-- Rempli dynamiquement -->
                    </div>
                </div>

                <div class="selected-clients" id="selectedClients">
                    <p class="hint">Aucun client sélectionné pour le moment.</p>
                </div>
            </div>

            <!-- 3. Calcul itinéraire -->
            <div>
                <div class="section-title">3. Calculer l’itinéraire</div>
                <div class="btn-group">
                    <button type="button" id="btnRoute" class="primary">🚐 Calculer l’itinéraire</button>
                    <button type="button" id="btnShowTurns" class="secondary" disabled>
                        👁️ Voir l’itinéraire détaillé
                    </button>
                </div>

                <div class="route-options">
                    <label class="route-option">
                        <input type="checkbox" id="optimizeOrder" checked>
                        Optimiser l’ordre (proximité + urgence)
                    </label>
                </div>

                <div class="route-extra">
                    <button type="button" id="btnGoogle" class="secondary" disabled>
                        📱 Ouvrir l’itinéraire dans Google Maps
                    </button>
                </div>

                <p id="routeMessage" class="maps-message hint">
                    L'itinéraire utilise le service de routage public <strong>OSRM</strong> (OpenStreetMap).
                    L'ordre peut être <strong>optimisé</strong> automatiquement ou ajusté manuellement (↑ / ↓).
                    Les adresses sont géocodées automatiquement via <strong>Nominatim</strong>.
                </p>

                <div class="maps-stats" aria-live="polite">
                    <div class="maps-stat">
                        <span class="maps-stat-label">Distance totale</span>
                        <span class="maps-stat-value" id="statDistance">—</span>
                    </div>
                    <div class="maps-stat">
                        <span class="maps-stat-label">Durée estimée</span>
                        <span class="maps-stat-value" id="statDuration">—</span>
                    </div>
                    <div class="maps-stat">
                        <span class="maps-stat-label">Clients visités</span>
                        <span class="maps-stat-value" id="statStops">—</span>
                    </div>
                    <div class="maps-stat">
                        <span class="maps-stat-label">Temps de trajet</span>
                        <span class="maps-stat-value" id="statInfo">—</span>
                    </div>
                </div>

                <!-- Résumé par grandes étapes (Départ -> Client 1, etc.) -->
                <div id="routeSteps" class="route-steps">
                    <!-- Résumé des étapes rempli en JS -->
                </div>

                <!-- Détails “tourner à gauche / à droite” -->
                <div id="routeTurns" class="route-turns" style="display:none;">
                    <!-- Instructions détaillées remplies en JS -->
                </div>
            </div>
        </aside>

        <!-- PANNEAU DROIT : CARTE -->
        <section class="map-wrapper">
            <div class="map-toolbar">
                <div class="map-toolbar-left">
                    <strong>Carte clients</strong> – <?= h((string)$totalClients) ?> client(s) enregistré(s)
                </div>
                <div class="map-toolbar-right">
                    <span class="badge" id="badgeClients">Clients chargés : 0</span>
                    <span class="badge" id="badgeStart">Départ : non défini</span>
                </div>
            </div>
            <div id="map" aria-label="Carte des clients"></div>
        </section>
    </section>
</main>

<script>
// ==================
// Configuration
// ==================

// Cache des clients chargés (avec coordonnées géocodées)
const clientsCache = new Map(); // id -> {id, name, code, address, lat, lng, basePriority}

// ==================
// Variables globales
// ==================

let map;
const clientMarkers = {};

const clientSearchInput = document.getElementById('clientSearch');
const clientResultsEl = document.getElementById('clientResults');
const selectedClientsContainer = document.getElementById('selectedClients');

let selectedClients = [];     // [{id, priority}]
let startPoint = null;        // [lat, lng]
let startMarker = null;
let pickStartFromMap = false;
let routeLayer = null;
let lastOrderedStops = [];    // clients dans l'ordre utilisé pour l'itinéraire
let lastRouteLegs = [];       // legs OSRM

const startInfoEl = document.getElementById('startInfo');
const badgeStartEl = document.getElementById('badgeStart');
const routeMessageEl = document.getElementById('routeMessage');
const btnShowTurns = document.getElementById('btnShowTurns');
const routeStepsEl = document.getElementById('routeSteps');
const routeTurnsEl = document.getElementById('routeTurns');
const btnGoogle = document.getElementById('btnGoogle');
const optimizeOrderCheckbox = document.getElementById('optimizeOrder');

// ==================
// Initialisation Leaflet
// ==================

map = L.map('map');

// Fond de carte OpenStreetMap
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
}).addTo(map);

// === Icônes de priorité ===
function getPriorityColor(priority) {
    if (priority >= 3) return '#ef4444'; // rouge
    if (priority === 2) return '#f97316'; // orange
    return '#16a34a'; // vert
}

function createPriorityIcon(priority) {
    const color = getPriorityColor(priority);
    return L.divIcon({
        className: 'priority-marker',
        html: `<div class="priority-dot" style="background:${color};"></div>`,
        iconSize: [18, 18],
        iconAnchor: [9, 9]
    });
}

// Initialiser la carte sur la France
map.setView([46.5, 2.0], 6);

// Fonction pour géocoder une adresse
async function geocodeAddress(address) {
    if (!address || address.trim() === '') {
        return null;
    }
    
    try {
        const response = await fetch(`/API/maps_geocode.php?address=${encodeURIComponent(address)}`);
        const data = await response.json();
        
        if (data.ok && data.lat && data.lng) {
            return { lat: data.lat, lng: data.lng, display_name: data.display_name || address };
        }
        return null;
    } catch (err) {
        console.error('Erreur géocodage:', err);
        return null;
    }
}

// Fonction pour charger un client avec géocodage
// Utilise l'adresse exacte de la base de données (ou adresse de livraison si différente)
async function loadClientWithGeocode(client) {
    if (clientsCache.has(client.id)) {
        return clientsCache.get(client.id);
    }
    
    // Utiliser address_geocode si disponible (adresse de livraison), sinon address (adresse principale)
    const addressToGeocode = client.address_geocode || client.address;
    
    const coords = await geocodeAddress(addressToGeocode);
    if (!coords) {
        console.warn('Impossible de géocoder:', addressToGeocode);
        return null;
    }
    
    const clientWithCoords = {
        ...client,
        lat: coords.lat,
        lng: coords.lng,
        // Conserver l'adresse originale de la BDD pour l'affichage
        displayAddress: client.address
    };
    
    clientsCache.set(client.id, clientWithCoords);
    return clientWithCoords;
}

// Fonction pour ajouter un client sur la carte
// autoFit: si true, ajuste la vue pour inclure tous les clients, sinon ne fait rien
function addClientToMap(client, autoFit = true) {
    if (!client.lat || !client.lng) {
        console.warn('Client sans coordonnées:', client);
        return false; // Retourner false si pas de coordonnées
    }
    
    // Si le marqueur existe déjà, juste le mettre à jour
    if (clientMarkers[client.id]) {
        // Mettre à jour la position et l'icône si nécessaire
        const marker = clientMarkers[client.id];
        marker.setLatLng([client.lat, client.lng]);
        marker.setIcon(createPriorityIcon(client.basePriority || 1));
        return true;
    }
    
    // Créer un nouveau marqueur
    const marker = L.marker([client.lat, client.lng], {
        icon: createPriorityIcon(client.basePriority || 1)
    }).addTo(map);
    
    // Afficher l'adresse exacte de la base de données
    const displayAddress = client.displayAddress || client.address || 
        `${escapeHtml(client.adresse || '')} ${escapeHtml(client.code_postal || '')} ${escapeHtml(client.ville || '')}`.trim();
    
    const popupContent = `
        <strong>${escapeHtml(client.name)}</strong><br>
        ${displayAddress}<br>
        <small>Code : ${escapeHtml(client.code)}</small>
        ${client.telephone ? `<br><small>Tel: ${escapeHtml(client.telephone)}</small>` : ''}
        ${client.adresse_livraison && !client.livraison_identique ? `<br><small style="color:#666;">Livraison: ${escapeHtml(client.adresse_livraison)}</small>` : ''}
    `;
    
    marker.bindPopup(popupContent);
    clientMarkers[client.id] = marker;
    
    // Ajuster la vue pour inclure tous les clients seulement si autoFit est true
    if (autoFit) {
        const allCoords = Array.from(clientsCache.values())
            .filter(c => c.lat && c.lng)
            .map(c => [c.lat, c.lng]);
        if (allCoords.length > 1) {
            const bounds = L.latLngBounds(allCoords);
            map.fitBounds(bounds, { padding: [40, 40] });
        }
    }
    
    updateClientsBadge();
    return true;
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function updateClientsBadge() {
    const count = Object.keys(clientMarkers).length;
    document.getElementById('badgeClients').textContent = `Clients chargés : ${count}`;
}

updateClientsBadge();

// =========================
// UI : recherche & sélection
// =========================

function renderSelectedClients() {
    selectedClientsContainer.innerHTML = '';

    if (!selectedClients.length) {
        const p = document.createElement('p');
        p.className = 'hint';
        p.textContent = 'Aucun client sélectionné pour le moment.';
        selectedClientsContainer.appendChild(p);
        return;
    }

    selectedClients.forEach((sel, idx) => {
        const client = clientsCache.get(sel.id);
        if (!client) return;

        const chip = document.createElement('div');
        chip.className = 'selected-client-chip';

        // Afficher l'adresse exacte de la base de données
        const displayAddress = client.displayAddress || client.address || 
            `${client.adresse || ''} ${client.code_postal || ''} ${client.ville || ''}`.trim();
        
        const text = document.createElement('div');
        text.className = 'selected-client-main';
        text.innerHTML =
            `<strong>${idx + 1}. ${escapeHtml(client.name)}</strong>` +
            `<span>${escapeHtml(displayAddress)} — ${escapeHtml(client.code)}</span>`;

        const controls = document.createElement('div');
        controls.className = 'selected-client-controls';

        // Sélecteur d'urgence
        const labelUrg = document.createElement('label');
        labelUrg.textContent = 'Urgence : ';

        const select = document.createElement('select');
        select.innerHTML = `
            <option value="1">Normale</option>
            <option value="2">Urgente</option>
            <option value="3">Très urgente</option>
        `;
        select.value = String(sel.priority || client.basePriority || 1);

        select.addEventListener('change', () => {
            sel.priority = parseInt(select.value, 10) || 1;
            const marker = clientMarkers[client.id];
            if (marker) {
                marker.setIcon(createPriorityIcon(sel.priority));
            }
        });

        labelUrg.appendChild(select);

        // Bouton déplacer vers le haut
        const btnUp = document.createElement('button');
        btnUp.type = 'button';
        btnUp.textContent = '↑';
        btnUp.className = 'chip-move';
        btnUp.addEventListener('click', (e) => {
            e.stopPropagation();
            const index = selectedClients.findIndex(s => s.id === sel.id);
            if (index > 0) {
                const tmp = selectedClients[index - 1];
                selectedClients[index - 1] = selectedClients[index];
                selectedClients[index] = tmp;
                renderSelectedClients();
            }
        });

        // Bouton déplacer vers le bas
        const btnDown = document.createElement('button');
        btnDown.type = 'button';
        btnDown.textContent = '↓';
        btnDown.className = 'chip-move';
        btnDown.addEventListener('click', (e) => {
            e.stopPropagation();
            const index = selectedClients.findIndex(s => s.id === sel.id);
            if (index < selectedClients.length - 1) {
                const tmp = selectedClients[index + 1];
                selectedClients[index + 1] = selectedClients[index];
                selectedClients[index] = tmp;
                renderSelectedClients();
            }
        });

        // Bouton supprimer
        const btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.className = 'chip-remove';
        btnRemove.textContent = '✕';
        btnRemove.addEventListener('click', (e) => {
            e.stopPropagation();
            selectedClients = selectedClients.filter(s => s.id !== sel.id);
            renderSelectedClients();
        });

        controls.appendChild(labelUrg);
        controls.appendChild(btnUp);
        controls.appendChild(btnDown);
        controls.appendChild(btnRemove);

        chip.appendChild(text);
        chip.appendChild(controls);

        chip.addEventListener('click', (e) => {
            if (e.target === select || e.target === btnRemove || e.target === btnUp || e.target === btnDown) return;
            map.setView([client.lat, client.lng], 13);
            if (clientMarkers[client.id]) clientMarkers[client.id].openPopup();
        });

        selectedClientsContainer.appendChild(chip);
    });
}

async function addClientToRoute(client) {
    if (!client) return;

    if (selectedClients.find(s => s.id === client.id)) {
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        clientResultsEl.style.display = 'none';
        return;
    }

    // S'assurer que le client a des coordonnées
    if (!client.lat || !client.lng) {
        routeMessageEl.textContent = "Géocodage de l'adresse en cours…";
        routeMessageEl.className = 'maps-message hint';
        
        const clientWithCoords = await loadClientWithGeocode(client);
        if (!clientWithCoords || !clientWithCoords.lat || !clientWithCoords.lng) {
            routeMessageEl.textContent = "Impossible de géocoder l'adresse de ce client. Veuillez vérifier l'adresse.";
            routeMessageEl.className = 'maps-message alert';
            return;
        }
        client = clientWithCoords;
    }

    selectedClients.push({
        id: client.id,
        priority: client.basePriority || 1
    });

    clientSearchInput.value = '';
    clientResultsEl.innerHTML = '';
    clientResultsEl.style.display = 'none';
    
    // Ajouter le client sur la carte AVANT de rendre la liste (pour qu'il soit visible immédiatement)
    const added = addClientToMap(client, false); // false = ne pas ajuster la vue automatiquement
    
    // Centrer la carte sur le client sélectionné et ouvrir le popup
    if (client.lat && client.lng) {
        map.setView([client.lat, client.lng], 15); // Zoom plus proche pour voir le client
        // Attendre un peu pour que le marqueur soit créé
        setTimeout(() => {
            if (clientMarkers[client.id]) {
                clientMarkers[client.id].openPopup();
            }
        }, 100);
    }
    
    // Mettre à jour la liste des clients sélectionnés
    renderSelectedClients();
    
    // Message de confirmation
    if (added) {
        routeMessageEl.textContent = `Client "${client.name}" ajouté à la tournée et affiché sur la carte.`;
        routeMessageEl.className = 'maps-message success';
    }
}

// Recherche de clients via API
let searchTimeout = null;
async function searchClients(query) {
    query = query.trim();
    if (!query || query.length < 2) return [];
    
    try {
        const response = await fetch(`/API/maps_search_clients.php?q=${encodeURIComponent(query)}&limit=20`);
        const data = await response.json();
        
        if (data.ok && Array.isArray(data.clients)) {
            return data.clients;
        }
        return [];
    } catch (err) {
        console.error('Erreur recherche clients:', err);
        return [];
    }
}

clientSearchInput.addEventListener('input', () => {
    const q = clientSearchInput.value;
    clientResultsEl.innerHTML = '';
    
    clearTimeout(searchTimeout);
    
    if (!q.trim() || q.length < 2) {
        clientResultsEl.style.display = 'none';
        return;
    }
    
    // Afficher un indicateur de chargement
    const loadingItem = document.createElement('div');
    loadingItem.className = 'client-result-item loading';
    loadingItem.textContent = 'Recherche en cours…';
    clientResultsEl.appendChild(loadingItem);
    clientResultsEl.style.display = 'block';
    
    // Debounce de 300ms
    searchTimeout = setTimeout(async () => {
        const results = await searchClients(q);
        clientResultsEl.innerHTML = '';
        
        if (!results.length) {
            const item = document.createElement('div');
            item.className = 'client-result-item empty';
            item.textContent = 'Aucun client trouvé.';
            clientResultsEl.appendChild(item);
            return;
        }
        
        results.forEach(client => {
            // Afficher l'adresse exacte de la base de données
            const displayAddress = client.address || 
                `${client.adresse || ''} ${client.code_postal || ''} ${client.ville || ''}`.trim();
            
            const item = document.createElement('div');
            item.className = 'client-result-item';
            item.innerHTML =
                `<strong>${escapeHtml(client.name)}</strong>` +
                `<span>${escapeHtml(displayAddress)} — ${escapeHtml(client.code)}</span>`;
            item.addEventListener('click', async () => {
                // Ajouter le client à la route (qui va le géocoder et l'afficher sur la carte)
                await addClientToRoute(client);
            });
            clientResultsEl.appendChild(item);
        });
    }, 300);
});

document.addEventListener('click', (e) => {
    if (!clientResultsEl.contains(e.target) && e.target !== clientSearchInput) {
        clientResultsEl.style.display = 'none';
    }
});

// ==========================
// Gestion du point de départ
// ==========================

function setStartPoint(latlng, label) {
    startPoint = [latlng[0], latlng[1]];

    if (startMarker) {
        map.removeLayer(startMarker);
    }

    startMarker = L.marker(startPoint, { draggable: true }).addTo(map);
    startMarker.bindPopup(`<strong>Départ</strong><br>${label || ''}`).openPopup();

    startMarker.on('dragend', (e) => {
        const pos = e.target.getLatLng();
        startPoint = [pos.lat, pos.lng];
        startInfoEl.textContent = `Départ : ${pos.lat.toFixed(5)}, ${pos.lng.toFixed(5)} (marqueur déplacé)`;
        badgeStartEl.textContent = 'Départ : défini';
    });

    startInfoEl.textContent = `Départ : ${startPoint[0].toFixed(5)}, ${startPoint[1].toFixed(5)}${label ? ' – ' + label : ''}`;
    badgeStartEl.textContent = 'Départ : défini';

    map.setView(startPoint, 13);
}

// Géolocalisation
document.getElementById('btnGeo').addEventListener('click', () => {
    routeMessageEl.textContent = "Demande de géolocalisation en cours…";
    routeMessageEl.className = 'maps-message hint';

    if (!navigator.geolocation) {
        routeMessageEl.textContent = "Géolocalisation non supportée par ce navigateur.";
        routeMessageEl.className = 'maps-message alert';
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const lat = pos.coords.latitude;
            const lng = pos.coords.longitude;
            setStartPoint([lat, lng], "Ma position");
            routeMessageEl.textContent = "Point de départ défini sur votre position actuelle.";
            routeMessageEl.className = 'maps-message success';
        },
        (err) => {
            routeMessageEl.textContent = "Impossible de récupérer votre position (" + err.message + ").";
            routeMessageEl.className = 'maps-message alert';
        },
        { enableHighAccuracy: true }
    );
});

// Choisir départ sur la carte
document.getElementById('btnClickStart').addEventListener('click', () => {
    pickStartFromMap = !pickStartFromMap;
    routeMessageEl.textContent = pickStartFromMap
        ? "Cliquez sur la carte pour définir le point de départ."
        : "Mode sélection de départ désactivé.";
    routeMessageEl.className = 'maps-message hint';
});

// Effacer départ
document.getElementById('btnClearStart').addEventListener('click', () => {
    if (startMarker) {
        map.removeLayer(startMarker);
        startMarker = null;
    }
    startPoint = null;
    startInfoEl.textContent = 'Aucun point de départ défini.';
    badgeStartEl.textContent = 'Départ : non défini';
});

// Clic sur la carte pour définir le départ
map.on('click', (e) => {
    if (!pickStartFromMap) return;
    const latlng = [e.latlng.lat, e.latlng.lng];
    setStartPoint(latlng, "Point choisi sur la carte");
    routeMessageEl.textContent = "Point de départ défini depuis la carte.";
    routeMessageEl.className = 'maps-message success';
    pickStartFromMap = false;
});

// ==================
// Utilitaires route
// ==================

function formatDistance(meters) {
    if (!meters && meters !== 0) return '—';
    if (meters < 1000) return meters.toFixed(0) + ' m';
    return (meters / 1000).toFixed(1) + ' km';
}

function formatDuration(seconds) {
    if (!seconds && seconds !== 0) return '—';
    const minutes = Math.round(seconds / 60);
    if (minutes < 60) return minutes + ' min';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h + ' h ' + (m > 0 ? m + ' min' : '');
}

// Distance haversine (en km)
function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const toRad = x => x * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a =
        Math.sin(dLat / 2) * Math.sin(dLat / 2) +
        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
        Math.sin(dLon / 2) * Math.sin(dLon / 2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function getSelectedClientsForRouting() {
    return selectedClients
        .map(sel => {
            const client = clientsCache.get(sel.id);
            if (!client || !client.lat || !client.lng) return null;
            return {
                ...client,
                priority: sel.priority || client.basePriority || 1
            };
        })
        .filter(Boolean);
}

// Proximité + urgence
function computeOrderedStops(startLatLng, clients) {
    const remaining = clients.slice();
    const ordered = [];
    let current = { lat: startLatLng[0], lng: startLatLng[1] };

    while (remaining.length) {
        let bestIndex = 0;
        let bestScore = Infinity;

        for (let i = 0; i < remaining.length; i++) {
            const c = remaining[i];
            const distKm = haversine(current.lat, current.lng, c.lat, c.lng);
            const pr = c.priority || 1;

            let weight;
            if (pr >= 3) weight = 0.4;
            else if (pr === 2) weight = 0.7;
            else weight = 1.0;

            const score = distKm * weight;
            if (score < bestScore) {
                bestScore = score;
                bestIndex = i;
            }
        }

        const next = remaining.splice(bestIndex, 1)[0];
        ordered.push(next);
        current = { lat: next.lat, lng: next.lng };
    }

    return ordered;
}

// Résumé des grandes étapes
function renderRouteSummary(legs) {
    routeStepsEl.innerHTML = '';

    if (!legs || !legs.length) return;

    const ul = document.createElement('ul');

    legs.forEach((leg, index) => {
        const li = document.createElement('li');

        const fromLabel = (index === 0)
            ? 'Départ'
            : (lastOrderedStops[index - 1]?.name || 'Étape ' + index);

        const toLabel = lastOrderedStops[index]?.name || 'Arrivée';

        li.textContent = `Étape ${index + 1} : ${fromLabel} → ${toLabel} (${formatDistance(leg.distance)}, ${formatDuration(leg.duration)})`;
        ul.appendChild(li);
    });

    routeStepsEl.appendChild(ul);
}

// Construction d'une phrase FR pour chaque step OSRM
function buildInstruction(step) {
    const man = step.maneuver || {};
    const type = man.type || '';
    const mod = man.modifier || '';
    const name = step.name || '';
    const dist = formatDistance(step.distance || 0);

    const dirMap = {
        'left': 'à gauche',
        'right': 'à droite',
        'slight left': 'légèrement à gauche',
        'slight_right': 'légèrement à droite',
        'sharp_left': 'franchement à gauche',
        'sharp_right': 'franchement à droite',
        'uturn': 'en faisant demi-tour',
        'straight': 'tout droit'
    };
    const dir = dirMap[mod] || '';

    let txt;

    if (type === 'depart') {
        if (name) {
            txt = `Démarrer sur ${name}.`;
        } else {
            txt = 'Démarrer depuis votre position.';
        }
    } else if (type === 'arrive') {
        txt = 'Vous êtes arrivé à destination.';
    } else if (type === 'roundabout') {
        const exit = man.exit ? `, prendre la sortie ${man.exit}` : '';
        if (name) {
            txt = `Au rond-point${exit}, suivre ${name}.`;
        } else {
            txt = `Au rond-point${exit}, continuer sur la voie principale.`;
        }
    } else if (type === 'turn' || type === 'continue') {
        if (dir && name) {
            txt = `Tourner ${dir} sur ${name} (${dist}).`;
        } else if (dir) {
            txt = `Tourner ${dir} (${dist}).`;
        } else if (name) {
            txt = `Suivre ${name} (${dist}).`;
        } else {
            txt = `Continuer tout droit (${dist}).`;
        }
    } else if (type === 'merge') {
        txt = name ? `S’insérer sur ${name} (${dist}).` : `S’insérer sur la voie (${dist}).`;
    } else if (type === 'on ramp') {
        txt = name ? `Prendre la bretelle vers ${name} (${dist}).` : `Prendre la bretelle (${dist}).`;
    } else if (type === 'off ramp') {
        txt = name ? `Prendre la sortie vers ${name} (${dist}).` : `Prendre la sortie (${dist}).`;
    } else {
        txt = name ? `Continuer sur ${name} (${dist}).` : `Continuer (${dist}).`;
    }

    return txt;
}

// Détails tour par tour
function renderTurnByTurn(legs) {
    routeTurnsEl.innerHTML = '';

    if (!legs || !legs.length) {
        const p = document.createElement('p');
        p.className = 'hint';
        p.textContent = "Aucun détail d’itinéraire disponible.";
        routeTurnsEl.appendChild(p);
        return;
    }

    let stepIndex = 1;

    legs.forEach((leg, legIndex) => {
        const block = document.createElement('div');
        block.className = 'route-turns-leg';

        const title = document.createElement('div');
        title.className = 'route-turns-leg-title';

        const fromLabel = (legIndex === 0)
            ? 'Départ'
            : (lastOrderedStops[legIndex - 1]?.name || 'Étape ' + legIndex);

        const toLabel = lastOrderedStops[legIndex]?.name || 'Arrivée';

        title.textContent = `Trajet ${legIndex + 1} : ${fromLabel} → ${toLabel}`;
        block.appendChild(title);

        const list = document.createElement('ul');
        list.className = 'route-turns-list';

        (leg.steps || []).forEach(step => {
            const li = document.createElement('li');
            li.className = 'route-turns-step';

            const labelIndex = document.createElement('span');
            labelIndex.className = 'route-turns-step-index';
            labelIndex.textContent = stepIndex;

            const text = document.createElement('div');
            text.className = 'route-turns-step-text';
            text.textContent = buildInstruction(step);

            li.appendChild(labelIndex);
            li.appendChild(text);
            list.appendChild(li);

            stepIndex++;
        });

        block.appendChild(list);
        routeTurnsEl.appendChild(block);
    });
}

// Voir / cacher les détails
btnShowTurns.addEventListener('click', () => {
    if (!lastRouteLegs.length) return;
    const isHidden = routeTurnsEl.style.display === 'none' || routeTurnsEl.style.display === '';
    routeTurnsEl.style.display = isHidden ? 'block' : 'none';
    btnShowTurns.textContent = isHidden
        ? '👁️ Masquer l’itinéraire détaillé'
        : '👁️ Voir l’itinéraire détaillé';
});

// Ouvrir l’itinéraire dans Google Maps (site web, pas d’API)
function openInGoogleMaps() {
    if (!startPoint || !lastOrderedStops.length) return;

    const parts = [];
    parts.push(`${startPoint[0]},${startPoint[1]}`);
    lastOrderedStops.forEach(c => {
        parts.push(`${c.lat},${c.lng}`);
    });

    const url = 'https://www.google.com/maps/dir/' + parts.join('/');
    window.open(url, '_blank');
}

btnGoogle.addEventListener('click', openInGoogleMaps);

// =====================
// Calcul de l’itinéraire (OSRM)
// =====================

document.getElementById('btnRoute').addEventListener('click', () => {
    routeStepsEl.innerHTML = '';
    routeTurnsEl.innerHTML = '';
    routeTurnsEl.style.display = 'none';
    btnShowTurns.disabled = true;
    btnShowTurns.textContent = '👁️ Voir l’itinéraire détaillé';
    btnGoogle.disabled = true;

    if (!startPoint) {
        routeMessageEl.textContent = "Définissez d'abord un point de départ (ma position ou clic sur la carte).";
        routeMessageEl.className = 'maps-message alert';
        return;
    }

    const clientsForRouting = getSelectedClientsForRouting();

    if (!clientsForRouting.length) {
        routeMessageEl.textContent = "Sélectionnez au moins un client à visiter.";
        routeMessageEl.className = 'maps-message alert';
        return;
    }

    if (clientsForRouting.length > 20) {
        routeMessageEl.textContent = "Pour la démo, limitez-vous à 20 clients maximum par tournée.";
        routeMessageEl.className = 'maps-message alert';
        return;
    }

    let orderedStops;

    if (optimizeOrderCheckbox.checked) {
        orderedStops = computeOrderedStops(startPoint, clientsForRouting);
        // on met à jour l’ordre dans la liste visuelle
        selectedClients = orderedStops.map(c => ({
            id: c.id,
            priority: c.priority
        }));
        renderSelectedClients();
    } else {
        orderedStops = clientsForRouting.slice(); // respecter l'ordre manuel
    }

    lastOrderedStops = orderedStops.slice();
    lastRouteLegs = [];

    const waypoints = [
        { lat: startPoint[0], lng: startPoint[1] },
        ...orderedStops.map(c => ({ lat: c.lat, lng: c.lng }))
    ];

    const coordStr = waypoints
        .map(p => `${p.lng.toFixed(6)},${p.lat.toFixed(6)}`)
        .join(';');

    const url = `https://router.project-osrm.org/route/v1/driving/${coordStr}?overview=full&geometries=geojson&steps=true`;

    routeMessageEl.textContent = "Calcul de l’itinéraire en cours…";
    routeMessageEl.className = 'maps-message hint';

    fetch(url)
        .then(r => r.json())
        .then(data => {
            if (!data.routes || !data.routes.length) {
                throw new Error('Aucun itinéraire trouvé.');
            }

            const route = data.routes[0];

            // Nettoyer ancien tracé
            if (routeLayer) {
                map.removeLayer(routeLayer);
            }

            routeLayer = L.geoJSON(route.geometry, {
                style: {
                    color: '#3b82f6',
                    weight: 5,
                    opacity: 0.85
                }
            }).addTo(map);

            const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);
            const bounds = L.latLngBounds(coords);
            map.fitBounds(bounds, { padding: [40, 40] });

            const distance = route.distance; // m
            const duration = route.duration; // s

            document.getElementById('statDistance').textContent = formatDistance(distance);
            document.getElementById('statDuration').textContent = formatDuration(duration);
            document.getElementById('statStops').textContent = orderedStops.length + ' client(s)';
            document.getElementById('statInfo').textContent = 'Temps de trajet estimé (OSRM).';

            lastRouteLegs = route.legs || [];

            renderRouteSummary(lastRouteLegs);
            renderTurnByTurn(lastRouteLegs);

            btnShowTurns.disabled = false;
            btnGoogle.disabled = false;

            routeMessageEl.textContent = "Itinéraire calculé avec succès (optimisé + détails).";
            routeMessageEl.className = 'maps-message success';
        })
        .catch(err => {
            console.error(err);
            routeMessageEl.textContent = "Erreur lors du calcul de l’itinéraire : " + err.message;
            routeMessageEl.className = 'maps-message alert';
        });
});
</script>
</body>
</html>
