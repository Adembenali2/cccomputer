<?php
// /public/maps.php
// Page de planification de trajets clients (version sans base de données, Google Maps)

require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']); // adapte si tu veux ouvrir à d'autres rôles
require_once __DIR__ . '/../includes/db.php'; // prêt pour plus tard si tu branches la BDD

function h(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
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

    <!-- Google Maps JS API (mettre ta vraie clé) -->
    <script
        src="https://maps.googleapis.com/maps/api/js?key=YOUR_GOOGLE_MAPS_API_KEY&language=fr&region=FR"
        defer
    ></script>
</head>
<body class="page-maps">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Carte & planification de tournée</h1>
        <p class="page-sub">
            Visualisez vos clients sur une carte et préparez un itinéraire (départ de chez vous + plusieurs clients).
            Cette version est <strong>démo</strong> : les clients sont codés en dur et il n’y a pas encore de connexion à la base.
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
                    Vous pouvez gérer des centaines / milliers de clients grâce à la recherche.
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

                <p id="routeMessage" class="maps-message hint">
                    L’itinéraire utilise le service de routage Google (Directions API).
                    L’ordre est optimisé selon la <strong>proximité</strong> et le niveau <strong>d’urgence</strong>.
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
                    <strong>Carte clients</strong> – Démo sans base de données
                </div>
                <div class="map-toolbar-right">
                    <span class="badge" id="badgeClients">Clients : 0</span>
                    <span class="badge" id="badgeStart">Départ : non défini</span>
                </div>
            </div>
            <div id="map" aria-label="Carte des clients"></div>
        </section>
    </section>
</main>

<script>
// ==================
// Configuration démo
// ==================

// Clients codés en dur pour la démonstration (à remplacer plus tard par la base de données)
const demoClients = [
    {
        id: 1,
        name: "Client Alpha",
        code: "CL-001",
        address: "10 Rue de Paris, Lyon",
        lat: 45.764043,
        lng: 4.835659,
        basePriority: 1
    },
    {
        id: 2,
        name: "Client Bravo",
        code: "CL-002",
        address: "25 Avenue de la République, Villeurbanne",
        lat: 45.7700,
        lng: 4.8800,
        basePriority: 2
    },
    {
        id: 3,
        name: "Client Charlie",
        code: "CL-003",
        address: "5 Rue Victor Hugo, Vénissieux",
        lat: 45.6970,
        lng: 4.8850,
        basePriority: 1
    },
    {
        id: 4,
        name: "Client Delta",
        code: "CL-004",
        address: "50 Rue Garibaldi, Lyon",
        lat: 45.7510,
        lng: 4.8500,
        basePriority: 3
    },
    {
        id: 5,
        name: "Client Echo",
        code: "CL-005",
        address: "12 Rue du Lac, Décines",
        lat: 45.7680,
        lng: 4.9600,
        basePriority: 1
    },
    {
        id: 6,
        name: "Client Foxtrot",
        code: "CL-006",
        address: "2 Rue Nationale, Oullins",
        lat: 45.7160,
        lng: 4.8060,
        basePriority: 2
    }
];

// ==================
// Variables globales
// ==================

let map;
let directionsService;
let directionsRenderer;
const clientMarkers = {};

const clientSearchInput = document.getElementById('clientSearch');
const clientResultsEl = document.getElementById('clientResults');
const selectedClientsContainer = document.getElementById('selectedClients');

let selectedClients = [];     // [{id, priority}]
let startPoint = null;        // {lat, lng}
let startMarker = null;
let pickStartFromMap = false;
let lastOrderedStops = [];    // clients dans l'ordre optimisé
let lastRouteLegs = [];       // legs renvoyés par Google Directions

const startInfoEl = document.getElementById('startInfo');
const badgeStartEl = document.getElementById('badgeStart');
const routeMessageEl = document.getElementById('routeMessage');
const btnShowTurns = document.getElementById('btnShowTurns');
const routeStepsEl = document.getElementById('routeSteps');
const routeTurnsEl = document.getElementById('routeTurns');

// ==================
// Initialisation Google Maps
// ==================

function initMap() {
    // Centre initial approx. France
    map = new google.maps.Map(document.getElementById('map'), {
        center: { lat: 46.5, lng: 2.0 },
        zoom: 6,
        mapTypeId: 'roadmap',
        tilt: 45 // léger effet "3D" quand tu zoomes
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        map: map,
        suppressMarkers: false,
        polylineOptions: {
            strokeColor: '#3b82f6',
            strokeOpacity: 0.9,
            strokeWeight: 6
        }
    });

    // Placer les marqueurs clients
    const bounds = new google.maps.LatLngBounds();

    demoClients.forEach(client => {
        const pos = { lat: client.lat, lng: client.lng };
        const m = new google.maps.Marker({
            position: pos,
            map: map,
            title: client.name
        });
        const info = new google.maps.InfoWindow({
            content: `<strong>${client.name}</strong><br>${client.address}<br><small>Code : ${client.code}</small>`
        });
        m.addListener('click', () => info.open(map, m));

        clientMarkers[client.id] = m;
        bounds.extend(pos);
    });

    if (!bounds.isEmpty()) {
        map.fitBounds(bounds);
    }

    document.getElementById('badgeClients').textContent = "Clients : " + demoClients.length;

    // Events UI après que la map soit prête
    initUIEvents();
}

window.initMap = initMap;
window.addEventListener('load', initMap);

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
        const client = demoClients.find(c => c.id === sel.id);
        if (!client) return;

        const chip = document.createElement('div');
        chip.className = 'selected-client-chip';

        const text = document.createElement('div');
        text.className = 'selected-client-main';
        text.innerHTML =
            `<strong>${idx + 1}. ${client.name}</strong>` +
            `<span>${client.address} — ${client.code}</span>`;

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
        });

        labelUrg.appendChild(select);

        // Bouton supprimer
        const btnRemove = document.createElement('button');
        btnRemove.type = 'button';
        btnRemove.className = 'chip-remove';
        btnRemove.textContent = '✕';
        btnRemove.addEventListener('click', () => {
            selectedClients = selectedClients.filter(s => s.id !== sel.id);
            renderSelectedClients();
        });

        controls.appendChild(labelUrg);
        controls.appendChild(btnRemove);

        chip.appendChild(text);
        chip.appendChild(controls);

        chip.addEventListener('click', (e) => {
            if (e.target === select || e.target === btnRemove) return;
            // centrer la carte sur le client
            map.setZoom(13);
            map.panTo({ lat: client.lat, lng: client.lng });
            if (clientMarkers[client.id]) {
                google.maps.event.trigger(clientMarkers[client.id], 'click');
            }
        });

        selectedClientsContainer.appendChild(chip);
    });
}

function addClientToRoute(client) {
    if (!client) return;

    if (selectedClients.find(s => s.id === client.id)) {
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        clientResultsEl.style.display = 'none';
        return;
    }

    selectedClients.push({
        id: client.id,
        priority: client.basePriority || 1
    });

    clientSearchInput.value = '';
    clientResultsEl.innerHTML = '';
    clientResultsEl.style.display = 'none';
    renderSelectedClients();

    map.setZoom(13);
    map.panTo({ lat: client.lat, lng: client.lng });
    if (clientMarkers[client.id]) {
        google.maps.event.trigger(clientMarkers[client.id], 'click');
    }
}

function searchClients(query) {
    query = query.trim().toLowerCase();
    if (!query) return [];

    return demoClients.filter(c => {
        const haystack = (c.name + ' ' + c.code + ' ' + c.address).toLowerCase();
        return haystack.includes(query);
    }).slice(0, 10);
}

// ==========================
// Gestion du point de départ
// ==========================

function setStartPoint(latlng, label) {
    startPoint = { lat: latlng.lat, lng: latlng.lng };

    if (startMarker) {
        startMarker.setMap(null);
    }

    startMarker = new google.maps.Marker({
        position: startPoint,
        map: map,
        draggable: true,
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 7,
            fillColor: '#16a34a',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeWeight: 2
        },
        title: 'Point de départ'
    });

    startMarker.addListener('dragend', (e) => {
        const pos = e.latLng;
        startPoint = { lat: pos.lat(), lng: pos.lng() };
        startInfoEl.textContent = `Départ : ${startPoint.lat.toFixed(5)}, ${startPoint.lng.toFixed(5)} (marqueur déplacé)`;
        badgeStartEl.textContent = 'Départ : défini';
    });

    startInfoEl.textContent = `Départ : ${startPoint.lat.toFixed(5)}, ${startPoint.lng.toFixed(5)}${label ? ' – ' + label : ''}`;
    badgeStartEl.textContent = 'Départ : défini';

    map.setZoom(13);
    map.panTo(startPoint);
}

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

// Distance haversine (km) pour l'heuristique proximité + urgence
function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371; // km
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
            const client = demoClients.find(c => c.id === sel.id);
            if (!client) return null;
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
    let current = { lat: startLatLng.lat, lng: startLatLng.lng };

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

        li.textContent = `Étape ${index + 1} : ${fromLabel} → ${toLabel} (${leg.distance.text}, ${leg.duration.text})`;
        ul.appendChild(li);
    });

    routeStepsEl.appendChild(ul);
}

// Détails tour par tour (comme Google Maps, en français)
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
            text.innerHTML = step.instructions + ` (${step.distance.text})`;

            li.appendChild(labelIndex);
            li.appendChild(text);
            list.appendChild(li);

            stepIndex++;
        });

        block.appendChild(list);
        routeTurnsEl.appendChild(block);
    });
}

// =====================
// Événements UI & route
// =====================

function initUIEvents() {
    // Recherche clients
    clientSearchInput.addEventListener('input', () => {
        const q = clientSearchInput.value;
        clientResultsEl.innerHTML = '';

        if (!q.trim()) {
            clientResultsEl.style.display = 'none';
            return;
        }

        const results = searchClients(q);
        clientResultsEl.style.display = 'block';

        if (!results.length) {
            const item = document.createElement('div');
            item.className = 'client-result-item empty';
            item.textContent = 'Aucun client trouvé.';
            clientResultsEl.appendChild(item);
            return;
        }

        results.forEach(client => {
            const item = document.createElement('div');
            item.className = 'client-result-item';
            item.innerHTML =
                `<strong>${client.name}</strong>` +
                `<span>${client.address} — ${client.code}</span>`;
            item.addEventListener('click', () => addClientToRoute(client));
            clientResultsEl.appendChild(item);
        });
    });

    document.addEventListener('click', (e) => {
        if (!clientResultsEl.contains(e.target) && e.target !== clientSearchInput) {
            clientResultsEl.style.display = 'none';
        }
    });

    // Bouton géolocalisation
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
                setStartPoint({ lat, lng }, "Ma position");
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

    // Bouton choisir départ sur la carte
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
            startMarker.setMap(null);
            startMarker = null;
        }
        startPoint = null;
        startInfoEl.textContent = 'Aucun point de départ défini.';
        badgeStartEl.textContent = 'Départ : non défini';
    });

    // Clic carte pour définir départ
    map.addListener('click', (e) => {
        if (!pickStartFromMap) return;
        const latlng = { lat: e.latLng.lat(), lng: e.latLng.lng() };
        setStartPoint(latlng, "Point choisi sur la carte");
        routeMessageEl.textContent = "Point de départ défini depuis la carte.";
        routeMessageEl.className = 'maps-message success';
        pickStartFromMap = false;
    });

    // Bouton voir / cacher détails
    btnShowTurns.addEventListener('click', () => {
        if (!lastRouteLegs.length) return;
        const isHidden = routeTurnsEl.style.display === 'none' || routeTurnsEl.style.display === '';
        routeTurnsEl.style.display = isHidden ? 'block' : 'none';
        btnShowTurns.textContent = isHidden
            ? '👁️ Masquer l’itinéraire détaillé'
            : '👁️ Voir l’itinéraire détaillé';
    });

    // Bouton calculer itinéraire
    document.getElementById('btnRoute').addEventListener('click', calculateRoute);
}

// Calcul de l’itinéraire avec Google Directions
function calculateRoute() {
    routeStepsEl.innerHTML = '';
    routeTurnsEl.innerHTML = '';
    routeTurnsEl.style.display = 'none';
    btnShowTurns.disabled = true;
    btnShowTurns.textContent = '👁️ Voir l’itinéraire détaillé';

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

    const orderedStops = computeOrderedStops(startPoint, clientsForRouting);
    lastOrderedStops = orderedStops.slice();
    lastRouteLegs = [];

    const origin = new google.maps.LatLng(startPoint.lat, startPoint.lng);
    const destinationStop = orderedStops[orderedStops.length - 1];
    const destination = new google.maps.LatLng(destinationStop.lat, destinationStop.lng);

    const waypoints = orderedStops.slice(0, -1).map(c => ({
        location: new google.maps.LatLng(c.lat, c.lng),
        stopover: true
    }));

    const request = {
        origin: origin,
        destination: destination,
        waypoints: waypoints,
        travelMode: google.maps.TravelMode.DRIVING,
        optimizeWaypoints: false // on respecte notre ordre (proximité + urgence)
    };

    routeMessageEl.textContent = "Calcul de l’itinéraire en cours…";
    routeMessageEl.className = 'maps-message hint';

    directionsService.route(request, (result, status) => {
        if (status !== google.maps.DirectionsStatus.OK || !result.routes.length) {
            console.error(result);
            routeMessageEl.textContent = "Erreur lors du calcul de l’itinéraire : " + status;
            routeMessageEl.className = 'maps-message alert';
            return;
        }

        directionsRenderer.setDirections(result);
        const route = result.routes[0];

        // stats globales
        let totalDistance = 0;
        let totalDuration = 0;

        (route.legs || []).forEach(leg => {
            totalDistance += leg.distance.value; // mètres
            totalDuration += leg.duration.value; // secondes
        });

        document.getElementById('statDistance').textContent = formatDistance(totalDistance);
        document.getElementById('statDuration').textContent = formatDuration(totalDuration);
        document.getElementById('statStops').textContent = orderedStops.length + ' client(s)';
        document.getElementById('statInfo').textContent = 'Temps de trajet estimé (Google).';

        lastRouteLegs = route.legs || [];

        // Résumé + tour par tour
        renderRouteSummary(lastRouteLegs);
        renderTurnByTurn(lastRouteLegs);

        btnShowTurns.disabled = false;

        routeMessageEl.textContent = "Itinéraire calculé avec succès (Google Maps, détails disponibles).";
        routeMessageEl.className = 'maps-message success';
    });
}
</script>
</body>
</html>
