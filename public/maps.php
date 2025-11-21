<?php
// /public/maps.php
// Page de planification de trajets clients (version sans base de données)

require_once __DIR__ . '/../includes/auth_role.php';
authorize_roles(['Admin', 'Dirigeant']); // adapte si tu veux ouvrir à d'autres rôles
require_once __DIR__ . '/../includes/db.php'; // pas encore utilisé ici, mais prêt pour plus tard

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

    <!-- Leaflet (carte) -->
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
                    Vous pouvez gérer des dizaines ou centaines de clients sans liste infinie.
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
                <button type="button" id="btnRoute" class="primary">🚐 Calculer l’itinéraire</button>
                <p id="routeMessage" class="maps-message hint">
                    L’itinéraire utilise le service de routage public OSRM (OpenStreetMap).
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

                <div id="routeSteps" class="route-steps">
                    <!-- Résumé des étapes rempli en JS -->
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
// On ajoute un champ "basePriority" (1 normal, 2 urgent, 3 très urgent)
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

// ============
// Carte Leaflet
// ============

let map = L.map('map');

// Fond de carte
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
}).addTo(map);

// Fit initial sur les clients
let clientsLatLng = demoClients.map(c => [c.lat, c.lng]);
if (clientsLatLng.length) {
    let bounds = L.latLngBounds(clientsLatLng);
    map.fitBounds(bounds, { padding: [40, 40] });
} else {
    map.setView([46.5, 2.0], 6); // centre France
}

// Marqueurs clients
const clientMarkers = {};
demoClients.forEach(client => {
    const m = L.marker([client.lat, client.lng]).addTo(map);
    m.bindPopup(
        `<strong>${client.name}</strong><br>` +
        `${client.address}<br>` +
        `<small>Code : ${client.code}</small>`
    );
    clientMarkers[client.id] = m;
});

// Mise à jour badge clients
document.getElementById('badgeClients').textContent = "Clients : " + demoClients.length;

// =========================
// Recherche & sélection de clients
// =========================

const clientSearchInput = document.getElementById('clientSearch');
const clientResultsEl = document.getElementById('clientResults');
const selectedClientsContainer = document.getElementById('selectedClients');

// selectedClients = [{id, priority}]
let selectedClients = [];

// Rendu des clients sélectionnés
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
            map.setView([client.lat, client.lng], 13);
            if (clientMarkers[client.id]) {
                clientMarkers[client.id].openPopup();
            }
        });

        selectedClientsContainer.appendChild(chip);
    });
}

// Ajout d'un client à la tournée
function addClientToRoute(client) {
    if (!client) return;

    if (selectedClients.find(s => s.id === client.id)) {
        // déjà présent
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        return;
    }

    selectedClients.push({
        id: client.id,
        priority: client.basePriority || 1
    });

    clientSearchInput.value = '';
    clientResultsEl.innerHTML = '';
    renderSelectedClients();

    // focus visuel sur le client
    map.setView([client.lat, client.lng], 13);
    if (clientMarkers[client.id]) {
        clientMarkers[client.id].openPopup();
    }
}

// Recherche dans la liste de clients
function searchClients(query) {
    query = query.trim().toLowerCase();
    if (!query) return [];

    return demoClients.filter(c => {
        const haystack = (c.name + ' ' + c.code + ' ' + c.address).toLowerCase();
        return haystack.includes(query);
    }).slice(0, 10); // limite à 10 résultats pour rester lisible
}

clientSearchInput.addEventListener('input', () => {
    const q = clientSearchInput.value;
    clientResultsEl.innerHTML = '';

    if (!q.trim()) {
        clientResultsEl.style.display = 'none';
        return;
    }

    const results = searchClients(q);
    if (!results.length) {
        clientResultsEl.style.display = 'block';
        const item = document.createElement('div');
        item.className = 'client-result-item empty';
        item.textContent = 'Aucun client trouvé.';
        clientResultsEl.appendChild(item);
        return;
    }

    clientResultsEl.style.display = 'block';

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

// Fermer la liste de résultats si clic ailleurs
document.addEventListener('click', (e) => {
    if (!clientResultsEl.contains(e.target) && e.target !== clientSearchInput) {
        clientResultsEl.style.display = 'none';
    }
});

// ================================
// Gestion du point de départ
// ================================

let startPoint = null;
let startMarker = null;
let pickStartFromMap = false;
const startInfoEl = document.getElementById('startInfo');
const badgeStartEl = document.getElementById('badgeStart');
const routeMessageEl = document.getElementById('routeMessage');
let routeLayer = null;
let lastOrderedStops = []; // pour résumer les étapes

function setStartPoint(latlng, label) {
    startPoint = latlng;

    if (startMarker) {
        map.removeLayer(startMarker);
    }

    startMarker = L.marker(latlng, { draggable: true }).addTo(map);
    startMarker.bindPopup(`<strong>Départ</strong><br>${label || ''}`).openPopup();

    startMarker.on('dragend', (e) => {
        const pos = e.target.getLatLng();
        startPoint = [pos.lat, pos.lng];
        startInfoEl.textContent = `Départ : ${pos.lat.toFixed(5)}, ${pos.lng.toFixed(5)} (marqueur déplacé)`;
        badgeStartEl.textContent = 'Départ : défini';
    });

    startInfoEl.textContent = `Départ : ${latlng[0].toFixed(5)}, ${latlng[1].toFixed(5)}${label ? ' – ' + label : ''}`;
    badgeStartEl.textContent = 'Départ : défini';
}

// Bouton : utiliser la géolocalisation
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
            map.setView([lat, lng], 13);
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

// Bouton : choisir le départ sur la carte
document.getElementById('btnClickStart').addEventListener('click', () => {
    pickStartFromMap = !pickStartFromMap;
    routeMessageEl.textContent = pickStartFromMap
        ? "Cliquez sur la carte pour définir le point de départ."
        : "Mode sélection de départ désactivé.";
    routeMessageEl.className = 'maps-message hint';
});

// Bouton : effacer le départ
document.getElementById('btnClearStart').addEventListener('click', () => {
    if (startMarker) {
        map.removeLayer(startMarker);
        startMarker = null;
    }
    startPoint = null;
    startInfoEl.textContent = 'Aucun point de départ défini.';
    badgeStartEl.textContent = 'Départ : non défini';
});

// Clic sur la carte pour définir le départ (si mode actif)
map.on('click', (e) => {
    if (!pickStartFromMap) return;
    const latlng = [e.latlng.lat, e.latlng.lng];
    setStartPoint(latlng, "Point choisi sur la carte");
    routeMessageEl.textContent = "Point de départ défini depuis la carte.";
    routeMessageEl.className = 'maps-message success';
    pickStartFromMap = false;
});

// =====================================
// Calcul d'itinéraire avec OSRM (demo)
// =====================================

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

// Récupère les clients sélectionnés avec leur priorité
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

// Calcule l'ordre de visite en fonction de la distance + priorité (heuristique simple)
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

            // Plus l'urgence est forte, plus on réduit "le coût" de la distance
            // (3 = très urgent -> weight plus faible)
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

document.getElementById('btnRoute').addEventListener('click', () => {
    const routeStepsEl = document.getElementById('routeSteps');
    routeStepsEl.innerHTML = '';

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

    // Calcul de l'ordre optimisé (proximité + urgence)
    const orderedStops = computeOrderedStops(startPoint, clientsForRouting);
    lastOrderedStops = orderedStops.slice();

    // Construction de la chaîne de coordonnées OSRM : lon,lat;lon,lat;...
    const waypoints = [
        { lat: startPoint[0], lng: startPoint[1], label: 'Départ' },
        ...orderedStops.map(c => ({ lat: c.lat, lng: c.lng, label: c.name, id: c.id }))
    ];

    const coords = waypoints
        .map(p => `${p.lng.toFixed(6)},${p.lat.toFixed(6)}`)
        .join(';');

    const url = `https://router.project-osrm.org/route/v1/driving/${coords}?overview=full&geometries=geojson&steps=true`;

    routeMessageEl.textContent = "Calcul de l’itinéraire en cours…";
    routeMessageEl.className = 'maps-message hint';

    fetch(url)
        .then(res => res.json())
        .then(data => {
            if (!data.routes || !data.routes.length) {
                throw new Error('Aucun itinéraire trouvé.');
            }

            const route = data.routes[0];

            // Nettoyer l’ancien tracé
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

            // Ajuster le zoom sur l’itinéraire
            const coords = route.geometry.coordinates.map(c => [c[1], c[0]]);
            const bounds = L.latLngBounds(coords);
            map.fitBounds(bounds, { padding: [40, 40] });

            // Statistiques globales
            const distance = route.distance; // en mètres
            const duration = route.duration; // en secondes

            document.getElementById('statDistance').textContent = formatDistance(distance);
            document.getElementById('statDuration').textContent = formatDuration(duration);
            document.getElementById('statStops').textContent = orderedStops.length + ' client(s)';
            document.getElementById('statInfo').textContent = 'Conduite continue approximative';

            // Résumé des étapes (comme un mini Google Maps)
            if (route.legs && route.legs.length) {
                const ul = document.createElement('ul');

                route.legs.forEach((leg, index) => {
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

            routeMessageEl.textContent = "Itinéraire calculé avec succès (optimisé selon distance + urgence).";
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
