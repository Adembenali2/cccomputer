<?php
// /public/maps.php
// Page de planification de trajets clients (version 100% gratuite : OpenStreetMap + OSRM)

require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('maps', ['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/helpers.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

// La fonction h() est définie dans includes/helpers.php

// Récupérer le nombre réel de clients depuis la base de données
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM clients WHERE adresse IS NOT NULL AND adresse != '' AND code_postal IS NOT NULL AND code_postal != '' AND ville IS NOT NULL AND ville != ''");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalClients = (int)($result['total'] ?? 0);
} catch (PDOException $e) {
    error_log('maps.php: Error getting client count: ' . $e->getMessage());
    $totalClients = 0;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Carte & planification de tournée</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">

    <!-- CSS globaux -->
    <link rel="stylesheet" href="/assets/css/main.css">
    <!-- CSS spécifique à la page carte -->
    <link rel="stylesheet" href="/assets/css/maps.css">

    <!-- Améliorations JS pour maps.php -->
    <script src="/assets/js/maps-enhancements.js"></script>

    <!-- Leaflet (OpenStreetMap) -->
    <link rel="stylesheet"
          href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY="
          crossorigin=""/>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo="
            crossorigin=""></script>
</head>
<body class="page-maps" id="maps-page">

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
        <aside class="maps-panel" id="mapsPanel" aria-label="Panneau de planification de tournée">
            <div class="maps-panel-header" id="panelHeader">
                <h2>Planifier un trajet</h2>
                <button type="button" id="togglePanelBtn" class="toggle-panel-btn" aria-label="Replier/Déplier le panneau">▼</button>
            </div>
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
                <input type="text" 
                       id="startAddressInput" 
                       class="client-search-input" 
                       placeholder="Adresse de départ (ex: 7 Rue Fraizier, 93210 Saint-Denis)"
                       value=""
                       style="margin-top: 0.5rem; width: 100%;">
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
                           autocomplete="off"
                           aria-label="Rechercher un client">
                    <button type="button" 
                            id="clientSearchClear" 
                            class="client-search-clear" 
                            aria-label="Effacer la recherche"
                            title="Effacer">
                        ×
                    </button>
                    <div id="clientResults"
                         class="client-results"
                         aria-label="Résultats de recherche de clients">
                        <!-- Rempli dynamiquement -->
                    </div>
                </div>

                <div class="selected-clients" id="selectedClients">
                    <p class="hint">Aucun client sélectionné pour le moment.</p>
                    <span id="selectedClientsCount" style="display:none;">0</span>
                </div>
                </div>

                <!-- Clients non trouvés -->
                <div id="notFoundClientsSection" style="display:none;">
                <div class="section-title">Clients non trouvés</div>
                <p class="hint">
                    Les clients suivants n'ont pas pu être géolocalisés. Vérifiez leurs adresses.
                </p>
                <div class="not-found-clients" id="notFoundClients">
                    <!-- Rempli dynamiquement -->
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
            </div>
        </aside>

        <!-- CARTE EN HAUT -->
        <section class="map-wrapper">
            <div class="map-toolbar">
                <div class="map-toolbar-left">
                    <div class="map-toolbar-title">
                        <strong>Carte clients</strong>
                        <span class="map-toolbar-subtitle"><?= h((string)$totalClients) ?> client(s) enregistré(s)</span>
                    </div>
                </div>
                <div class="map-toolbar-right">
                    <span class="badge" id="badgeClients">Clients chargés : 0</span>
                    <span class="badge" id="badgeStart">Départ : non défini</span>
                </div>
            </div>
            <div id="map" aria-label="Carte des clients">
                <!-- Légende des marqueurs -->
                <div class="map-legend" id="mapLegend" role="region" aria-label="Légende des marqueurs">
                    <div class="map-legend-title">Légende</div>
                    <div class="map-legend-item">
                        <div class="map-legend-color" style="background: #16a34a;"></div>
                        <span class="map-legend-label">Client normal</span>
                    </div>
                    <div class="map-legend-item">
                        <div class="map-legend-color" style="background: #3b82f6;"></div>
                        <span class="map-legend-label">Livraison en cours</span>
                    </div>
                    <div class="map-legend-item">
                        <div class="map-legend-color" style="background: #eab308;"></div>
                        <span class="map-legend-label">SAV en cours</span>
                    </div>
                    <div class="map-legend-item">
                        <div class="map-legend-color" style="background: #ef4444;"></div>
                        <span class="map-legend-label">SAV + Livraison</span>
                    </div>
                </div>
                
                <!-- Filtres de marqueurs -->
                <div class="map-filters" id="mapFilters" role="region" aria-label="Filtres de marqueurs">
                    <div class="map-filters-title">Filtres</div>
                    <label class="map-filter-toggle">
                        <input type="checkbox" data-filter="all" checked>
                        <span>Tous</span>
                    </label>
                    <label class="map-filter-toggle">
                        <input type="checkbox" data-filter="normal" checked>
                        <span>Clients normaux</span>
                    </label>
                    <label class="map-filter-toggle">
                        <input type="checkbox" data-filter="livraison" checked>
                        <span>Livraisons</span>
                    </label>
                    <label class="map-filter-toggle">
                        <input type="checkbox" data-filter="sav" checked>
                        <span>SAV</span>
                    </label>
                </div>
            </div>
        </section>
    </section>
</main>

<script>
// ==================
// Configuration
// ==================

// Constantes configurables
const CONFIG = {
    SEARCH_DEBOUNCE_MS: 400,
    GEOCODE_BATCH_SIZE: 3,
    GEOCODE_BATCH_DELAY_MS: 1500,
    FETCH_TIMEOUT_MS: 15000,
    MAX_RETRIES: 3,
    RETRY_DELAY_MS: 1000,
    MAX_CLIENTS_PER_ROUTE: 20,
    COORDINATE_BOUNDS: {
        LAT_MIN: -90,
        LAT_MAX: 90,
        LNG_MIN: -180,
        LNG_MAX: 180
    }
};

// ==================
// Point de départ par défaut
// ==================

const DEFAULT_START_ADDRESS = "7 Rue Fraizier, 93210 Saint-Denis";
// Coordonnées hardcodées pour éviter le géocodage (approximatives, centre de Saint-Denis)
const DEFAULT_START_COORDS = [48.9358, 2.3536];

/**
 * Initialise le point de départ par défaut si aucun n'est défini
 * Priorité : localStorage > code existant > default hardcodé
 */
function initDefaultStartPoint() {
    // 1. Vérifier si startPoint est déjà défini (par code existant ou localStorage restore)
    if (startPoint && Array.isArray(startPoint) && startPoint.length === 2) {
        return; // Déjà défini, ne rien faire
    }
    
    // 2. Vérifier localStorage (si StorageManager existe, il a déjà été restauré)
    // Mais on vérifie quand même au cas où
    if (typeof StorageManager !== 'undefined') {
        const savedStart = StorageManager.loadStartPoint();
        if (savedStart && Array.isArray(savedStart) && savedStart.length === 2) {
            // Déjà restauré par maps-enhancements.js, ne rien faire
            return;
        }
    } else {
        // Fallback : vérifier localStorage directement
        try {
            const saved = localStorage.getItem('maps_start_point');
            if (saved) {
                const parsed = JSON.parse(saved);
                if (Array.isArray(parsed) && parsed.length === 2 && 
                    isValidCoordinate(parsed[0], parsed[1])) {
                    // Existe dans localStorage, sera restauré par maps-enhancements.js
                    return;
                }
            }
        } catch (e) {
            // Ignorer erreur localStorage
        }
    }
    
    // 3. Appliquer le default hardcodé (sans géocodage)
    if (typeof map !== 'undefined' && map && typeof setStartPoint === 'function') {
        setStartPoint(DEFAULT_START_COORDS, DEFAULT_START_ADDRESS);
    }
}

// Cache des clients chargés (avec coordonnées géocodées)
const clientsCache = new Map(); // id -> {id, name, code, address, lat, lng, basePriority}

// Cache des résultats de recherche (évite les requêtes répétées)
const searchCache = new Map(); // query -> {results, timestamp}
const SEARCH_CACHE_TTL = 60000; // 1 minute

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

// Clients non trouvés
const notFoundClientsSection = document.getElementById('notFoundClientsSection');
const notFoundClientsContainer = document.getElementById('notFoundClients');
const notFoundClientsSet = new Set(); // Pour éviter les doublons

// Charger tous les clients depuis la base de données au démarrage
let clientsLoaded = false;
async function loadAllClients() {
    if (clientsLoaded) return;
    
    try {
        const response = await fetchWithTimeout('/API/maps_get_all_clients.php', {}, CONFIG.FETCH_TIMEOUT_MS);
        
        // Vérifier si la réponse est OK
        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Vérifier que la réponse n'est pas vide
        const text = await response.text();
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide du serveur');
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            console.error('Erreur de parsing JSON:', parseError);
            console.error('Réponse reçue:', text.substring(0, 200));
            throw new Error('Réponse JSON invalide du serveur');
        }
        
        if (data.ok && data.clients) {
            const totalClients = data.clients.length;
            let clientsWithCoords = 0;
            let clientsToGeocode = [];
            
            routeMessageEl.textContent = `Chargement de ${totalClients} client(s)…`;
            routeMessageEl.className = 'maps-message hint';
            
            // Traiter tous les clients : ceux avec coordonnées et ceux à géocoder
            for (const client of data.clients) {
                // Stocker dans le cache
                clientsCache.set(client.id, client);
                
                // Si le client a déjà des coordonnées, l'ajouter directement à la carte
                if (client.lat && client.lng) {
                    addClientToMap(client, false);
                    clientsWithCoords++;
                } else if (client.needsGeocode) {
                    // Ajouter à la liste des clients à géocoder en arrière-plan
                    clientsToGeocode.push(client);
                }
            }
            
            // Ajuster la vue pour inclure tous les clients avec coordonnées valides
            const allCoords = Array.from(clientsCache.values())
                .filter(c => isValidCoordinate(c.lat, c.lng))
                .map(c => [c.lat, c.lng]);
            
            if (allCoords.length > 0) {
                const bounds = L.latLngBounds(allCoords);
                map.fitBounds(bounds, { padding: [40, 40] });
            }
            
            routeMessageEl.textContent = `${clientsWithCoords} client(s) chargé(s) et affiché(s) sur la carte.${clientsToGeocode.length > 0 ? ' Géocodage en arrière-plan des autres clients…' : ''}`;
            routeMessageEl.className = 'maps-message success';
            clientsLoaded = true;
            
            // Géocoder les clients sans coordonnées en arrière-plan (par lots pour respecter la limite Nominatim)
            if (clientsToGeocode.length > 0) {
                geocodeClientsInBackground(clientsToGeocode);
            }
        } else {
            routeMessageEl.textContent = "Erreur lors du chargement des clients : " + (data.error || 'Erreur inconnue');
            routeMessageEl.className = 'maps-message alert';
        }
    } catch (err) {
        console.error('Erreur chargement clients:', err);
        routeMessageEl.textContent = "Erreur lors du chargement des clients : " + err.message;
        routeMessageEl.className = 'maps-message alert';
    }
}

// Fonction pour ajouter un client à la liste "Clients non trouvés"
function addClientToNotFoundList(client) {
    if (notFoundClientsSet.has(client.id)) {
        return; // Déjà dans la liste
    }
    
    notFoundClientsSet.add(client.id);
    
    // Afficher la section si elle était cachée
    notFoundClientsSection.style.display = 'block';
    
    // Créer l'élément pour ce client
    const item = document.createElement('div');
    item.className = 'not-found-client-item';
    item.setAttribute('data-client-id', client.id);
    
    // Afficher l'adresse utilisée pour le géocodage
    const addressToShow = client.address_geocode || client.address || 
        `${client.adresse || ''} ${client.code_postal || ''} ${client.ville || ''}`.trim();
    
    item.innerHTML = `
        <div class="not-found-client-info">
            <strong>${escapeHtml(client.name)}</strong>
            <span class="not-found-client-code">Code : ${escapeHtml(client.code)}</span>
        </div>
        <div class="not-found-client-address">
            <small>Adresse : ${escapeHtml(addressToShow)}</small>
        </div>
    `;
    
    notFoundClientsContainer.appendChild(item);
}

// Géocoder les clients en arrière-plan (par lots) avec retry
async function geocodeClientsInBackground(clientsToGeocode) {
    const batchSize = CONFIG.GEOCODE_BATCH_SIZE;
    let processed = 0;
    let found = 0;
    let notFound = 0;
    
    // Mettre à jour le message pour indiquer la progression
    const updateProgress = () => {
        if (processed < clientsToGeocode.length) {
            routeMessageEl.textContent = `Géocodage en cours : ${processed}/${clientsToGeocode.length} client(s) traités (${found} trouvé(s))…`;
        }
    };
    
    for (let i = 0; i < clientsToGeocode.length; i += batchSize) {
        const batch = clientsToGeocode.slice(i, i + batchSize);
        
        // Fonction récursive pour géocoder un client avec retry
        const geocodeClient = async (client, retryCount = 0) => {
            try {
                const response = await fetchWithTimeout(
                    `/API/maps_geocode_client.php?client_id=${client.id}&address=${encodeURIComponent(client.address_geocode)}`
                );
                
                // Si response.ok est false, c'est une erreur réseau/serveur
                if (!response.ok) {
                    if (retryCount < CONFIG.MAX_RETRIES && response.status >= 500) {
                        // Retry pour les erreurs serveur
                        await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
                        return geocodeClient(client, retryCount + 1); // Retry
                    }
                    console.warn('Erreur HTTP lors du géocodage du client', client.id, ':', response.status);
                    addClientToNotFoundList(client);
                    return false;
                }
                
                // Vérifier que la réponse n'est pas vide
                const text = await response.text();
                if (!text || text.trim() === '') {
                    throw new Error('Réponse vide');
                }
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (parseError) {
                    throw new Error('Réponse JSON invalide');
                }
                
                // Vérifier le format de réponse (support success et ok pour compatibilité)
                const isSuccess = (data.success === true || data.ok === true) && data.lat && data.lng;
                
                // Valider les coordonnées
                if (isSuccess && isValidCoordinate(data.lat, data.lng)) {
                    // Mettre à jour le cache
                    const updatedClient = {
                        ...client,
                        lat: data.lat,
                        lng: data.lng,
                        needsGeocode: false
                    };
                    clientsCache.set(client.id, updatedClient);
                    
                    // Ajouter à la carte
                    addClientToMap(updatedClient, false);
                    found++;
                    return true;
                } else if (data.success === false || (data.ok === false && !data.lat)) {
                    // Adresse non trouvée, ajouter à la liste des clients non trouvés
                    addClientToNotFoundList(client);
                    notFound++;
                    return false;
                } else {
                    // Format de réponse inattendu ou coordonnées invalides
                    addClientToNotFoundList(client);
                    notFound++;
                    return false;
                }
            } catch (err) {
                // Retry pour les erreurs réseau/timeout
                if (retryCount < CONFIG.MAX_RETRIES && (err.message.includes('timeout') || err.name === 'TypeError')) {
                    await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
                    return geocodeClient(client, retryCount + 1); // Retry
                }
                
                // Erreur réseau ou autre, ne pas bloquer mais ajouter à la liste non trouvés
                console.warn('Erreur géocodage client', client.id, ':', err.message);
                addClientToNotFoundList(client);
                notFound++;
                return false;
            }
        };
        
        // Géocoder chaque client du lot en parallèle
        const geocodePromises = batch.map(client => geocodeClient(client));
        
        await Promise.all(geocodePromises);
        processed += batch.length;
        updateProgress();
        
        // Attendre entre les lots pour respecter la limite de Nominatim (1 req/sec)
        if (i + batchSize < clientsToGeocode.length) {
            await new Promise(resolve => setTimeout(resolve, CONFIG.GEOCODE_BATCH_DELAY_MS));
        }
    }
    
    routeMessageEl.textContent = `Géocodage terminé : ${found} trouvé(s), ${notFound} non trouvé(s) sur ${processed} client(s) traités.`;
    routeMessageEl.className = 'maps-message success';
    console.log(`Géocodage terminé : ${found} trouvé(s), ${notFound} non trouvé(s) sur ${processed} client(s) traités`);
}

// ==================
// Initialisation Leaflet
// ==================

map = L.map('map');

// Fond de carte OpenStreetMap
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/" target="_blank" rel="noopener">OpenStreetMap</a> contributors'
}).addTo(map);

// === Icônes selon type de marqueur (SAV/livraison) ===
function getMarkerColor(markerType) {
    switch(markerType) {
        case 'both': return '#ef4444'; // Rouge : SAV + livraison
        case 'livraison': return '#3b82f6'; // Bleu : livraison uniquement
        case 'sav': return '#eab308'; // Jaune : SAV uniquement
        default: return '#16a34a'; // Vert : normal
    }
}

function createMarkerIcon(markerType) {
    const color = getMarkerColor(markerType);
    return L.divIcon({
        className: 'priority-marker',
        html: `<div class="priority-dot" style="background:${color};"></div>`,
        iconSize: [18, 18],
        iconAnchor: [9, 9]
    });
}

// Fonction pour compatibilité avec l'ancien système de priorité
function createPriorityIcon(priority) {
    if (priority >= 3) return createMarkerIcon('both');
    if (priority === 2) return createMarkerIcon('sav');
    return createMarkerIcon('normal');
}

// Initialiser la carte sur la France par défaut
// Les clients seront chargés et la vue ajustée automatiquement
map.setView([46.5, 2.0], 6);

// Fonction pour géocoder une adresse
async function geocodeAddress(address, retryCount = 0) {
    if (!address || address.trim() === '') {
        return null;
    }
    
    try {
        const response = await fetchWithTimeout(
            `/API/maps_geocode.php?address=${encodeURIComponent(address)}`
        );
        
        if (!response.ok) {
            if (retryCount < CONFIG.MAX_RETRIES && response.status >= 500) {
                await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
                return geocodeAddress(address, retryCount + 1);
            }
            return null;
        }
        
        const text = await response.text();
        if (!text || text.trim() === '') {
            return null;
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            return null;
        }
        
        if (data.ok && data.lat && data.lng && isValidCoordinate(data.lat, data.lng)) {
            return { lat: data.lat, lng: data.lng, display_name: data.display_name || address };
        }
        return null;
    } catch (err) {
        // Retry pour les erreurs réseau/timeout
        if (retryCount < CONFIG.MAX_RETRIES && (err.message.includes('timeout') || err.name === 'TypeError')) {
            await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
            return geocodeAddress(address, retryCount + 1);
        }
        console.error('Erreur géocodage:', err);
        return null;
    }
}

// Fonction pour charger un client avec géocodage (utilisée uniquement pour la recherche)
// Utilise l'adresse exacte de la base de données (ou adresse de livraison si différente)
async function loadClientWithGeocode(client, retryCount = 0) {
    if (clientsCache.has(client.id)) {
        const cached = clientsCache.get(client.id);
        // Si le client a déjà des coordonnées valides, les retourner
        if (isValidCoordinate(cached.lat, cached.lng)) {
            return cached;
        }
    }
    
    // Utiliser address_geocode si disponible (adresse de livraison), sinon address (adresse principale)
    const addressToGeocode = client.address_geocode || client.address;
    
    // Appeler directement l'API client qui géocode et sauvegarde
    try {
        const response = await fetchWithTimeout(
            `/API/maps_geocode_client.php?client_id=${client.id}&address=${encodeURIComponent(addressToGeocode)}`
        );
        
        // Si response.ok est false, c'est une erreur réseau/serveur
        if (!response.ok) {
            // Retry pour les erreurs serveur
            if (retryCount < CONFIG.MAX_RETRIES && response.status >= 500) {
                await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
                return loadClientWithGeocode(client, retryCount + 1);
            }
            console.warn('Erreur HTTP lors du géocodage du client', client.id, ':', response.status);
            addClientToNotFoundList(client);
            return null;
        }
        
        // Vérifier que la réponse n'est pas vide
        const text = await response.text();
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide');
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            throw new Error('Réponse JSON invalide');
        }
        
        // Vérifier le format de réponse (support success et ok pour compatibilité)
        const isSuccess = (data.success === true || data.ok === true) && data.lat && data.lng;
        
        // Valider les coordonnées
        if (isSuccess && isValidCoordinate(data.lat, data.lng)) {
            const clientWithCoords = {
                ...client,
                lat: data.lat,
                lng: data.lng,
                needsGeocode: false,
                // Conserver l'adresse originale de la BDD pour l'affichage
                displayAddress: client.address
            };
            
            clientsCache.set(client.id, clientWithCoords);
            return clientWithCoords;
        } else if (data.success === false || (data.ok === false && !data.lat)) {
            // Adresse non trouvée, ajouter à la liste des clients non trouvés
            addClientToNotFoundList(client);
            return null;
        } else {
            // Format de réponse inattendu ou coordonnées invalides, traiter comme non trouvé
            addClientToNotFoundList(client);
            return null;
        }
    } catch (err) {
        // Retry pour les erreurs réseau/timeout
        if (retryCount < CONFIG.MAX_RETRIES && (err.message.includes('timeout') || err.name === 'TypeError')) {
            await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
            return loadClientWithGeocode(client, retryCount + 1);
        }
        
        // Erreur réseau ou autre, ne pas bloquer mais ajouter à la liste non trouvés
        console.warn('Erreur géocodage client', client.id, ':', err.message);
        addClientToNotFoundList(client);
        return null;
    }
}

// Fonction pour ajouter un client sur la carte
// autoFit: si true, ajuste la vue pour inclure tous les clients, sinon ne fait rien
function addClientToMap(client, autoFit = true) {
    // Vérifier que les coordonnées existent et sont valides
    if (!isValidCoordinate(client.lat, client.lng)) {
        console.warn('Client sans coordonnées valides:', client);
        return false; // Retourner false si pas de coordonnées
    }
    
    // Déterminer le type de marqueur (utiliser markerType du client ou calculer depuis hasLivraison/hasSav)
    let markerType = client.markerType || 'normal';
    if (!client.markerType) {
        const hasLivraison = client.hasLivraison || false;
        const hasSav = client.hasSav || false;
        if (hasLivraison && hasSav) {
            markerType = 'both';
        } else if (hasLivraison) {
            markerType = 'livraison';
        } else if (hasSav) {
            markerType = 'sav';
        }
    }
    
    // Si le marqueur existe déjà, juste le mettre à jour
    if (clientMarkers[client.id]) {
        // Mettre à jour la position et l'icône si nécessaire
        const marker = clientMarkers[client.id];
        marker.setLatLng([client.lat, client.lng]);
        marker.setIcon(createMarkerIcon(markerType));
        marker.options.clientId = client.id; // S'assurer que clientId est défini
        // Appliquer les filtres après mise à jour
        if (typeof applyMarkerFilters === 'function') {
            applyMarkerFilters();
        }
        return true;
    }
    
    // Créer un nouveau marqueur avec la bonne couleur
    const marker = L.marker([client.lat, client.lng], {
        icon: createMarkerIcon(markerType),
        clientId: client.id // Stocker l'ID pour les filtres
    }).addTo(map);
    
    // Afficher l'adresse exacte de la base de données
    const displayAddress = client.displayAddress || client.address || 
        `${escapeHtml(client.adresse || '')} ${escapeHtml(client.code_postal || '')} ${escapeHtml(client.ville || '')}`.trim();
    
    // Construire le contenu du popup avec les infos SAV/livraisons
    let popupInfo = '';
    if (client.hasLivraison && client.hasSav) {
        popupInfo = '<br><small style="color:#ef4444;">⚠️ SAV + Livraison en cours</small>';
    } else if (client.hasLivraison) {
        popupInfo = '<br><small style="color:#3b82f6;">📦 Livraison en cours</small>';
    } else if (client.hasSav) {
        popupInfo = '<br><small style="color:#eab308;">🔧 SAV en cours</small>';
    }
    
    const popupContent = `
        <strong>${escapeHtml(client.name)}</strong><br>
        ${displayAddress}<br>
        <small>Code : ${escapeHtml(client.code)}</small>
        ${client.telephone ? `<br><small>Tel: ${escapeHtml(client.telephone)}</small>` : ''}
        ${client.adresse_livraison && !client.livraison_identique ? `<br><small style="color:#666;">Livraison: ${escapeHtml(client.adresse_livraison)}</small>` : ''}
        ${popupInfo}
    `;
    
    marker.bindPopup(popupContent);
    clientMarkers[client.id] = marker;
    
    // Appliquer les filtres après ajout
    if (typeof applyMarkerFilters === 'function') {
        applyMarkerFilters();
    }
    
    // Ajuster la vue pour inclure tous les clients seulement si autoFit est true
    if (autoFit) {
        const allCoords = Array.from(clientsCache.values())
            .filter(c => isValidCoordinate(c.lat, c.lng))
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
                // Utiliser le markerType du client plutôt que la priorité pour la couleur
                const markerType = client.markerType || 'normal';
                marker.setIcon(createMarkerIcon(markerType));
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

    // Si pas de coordonnées valides, géocoder l'adresse
    if (!isValidCoordinate(client.lat, client.lng)) {
        routeMessageEl.textContent = "Géocodage de l'adresse en cours…";
        routeMessageEl.className = 'maps-message hint';
        
        const clientWithCoords = await loadClientWithGeocode(client);
        if (!clientWithCoords || !isValidCoordinate(clientWithCoords.lat, clientWithCoords.lng)) {
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
    if (isValidCoordinate(client.lat, client.lng)) {
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

// Fonction utilitaire pour fetch avec timeout
async function fetchWithTimeout(url, options = {}, timeout = CONFIG.FETCH_TIMEOUT_MS) {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    
    try {
        const response = await fetch(url, {
            ...options,
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        return response;
    } catch (err) {
        clearTimeout(timeoutId);
        if (err.name === 'AbortError') {
            throw new Error('Requête timeout après ' + (timeout / 1000) + ' secondes');
        }
        throw err;
    }
}

// Fonction utilitaire pour valider les coordonnées
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= CONFIG.COORDINATE_BOUNDS.LAT_MIN && 
           lat <= CONFIG.COORDINATE_BOUNDS.LAT_MAX &&
           lng >= CONFIG.COORDINATE_BOUNDS.LNG_MIN && 
           lng <= CONFIG.COORDINATE_BOUNDS.LNG_MAX;
}

// Recherche de clients depuis la base de données avec cache
let searchTimeout = null;
let currentSearchAbortController = null;

async function searchClients(query, retryCount = 0) {
    query = query.trim();
    if (!query || query.length < 2) return [];
    
    // Vérifier le cache
    const cacheKey = query.toLowerCase();
    const cached = searchCache.get(cacheKey);
    if (cached && (Date.now() - cached.timestamp) < SEARCH_CACHE_TTL) {
        return cached.results;
    }
    
    try {
        const response = await fetchWithTimeout(
            `/API/maps_search_clients.php?q=${encodeURIComponent(query)}&limit=20`
        );
        
        // Vérifier que la réponse est OK
        if (!response.ok) {
            throw new Error(`Erreur HTTP ${response.status}: ${response.statusText}`);
        }
        
        // Vérifier que la réponse n'est pas vide
        const text = await response.text();
        if (!text || text.trim() === '') {
            throw new Error('Réponse vide du serveur');
        }
        
        let data;
        try {
            data = JSON.parse(text);
        } catch (parseError) {
            throw new Error('Réponse JSON invalide');
        }
        
        if (data.ok && data.clients) {
            // Mettre en cache
            searchCache.set(cacheKey, {
                results: data.clients,
                timestamp: Date.now()
            });
            return data.clients;
        }
        return [];
    } catch (err) {
        console.error('Erreur recherche clients:', err);
        
        // Retry logic
        if (retryCount < CONFIG.MAX_RETRIES && err.message.includes('timeout')) {
            await new Promise(resolve => setTimeout(resolve, CONFIG.RETRY_DELAY_MS * (retryCount + 1)));
            return searchClients(query, retryCount + 1);
        }
        
        return [];
    }
}

clientSearchInput.addEventListener('input', () => {
    const q = clientSearchInput.value;
    clientResultsEl.innerHTML = '';
    
    // Annuler la recherche précédente si elle est en cours
    if (currentSearchAbortController) {
        try {
            currentSearchAbortController.abort();
        } catch (e) {
            // Ignorer les erreurs si le controller est déjà annulé
            console.warn('Erreur lors de l\'annulation de la recherche:', e);
        }
        currentSearchAbortController = null;
    }
    
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
    
    // Debounce avec délai configurable
    searchTimeout = setTimeout(async () => {
        // Créer un nouveau AbortController pour cette recherche
        currentSearchAbortController = new AbortController();
        const abortController = currentSearchAbortController; // Garder une référence locale
        
        try {
            const results = await searchClients(q);
            
            // Vérifier que la recherche n'a pas été annulée
            // Vérifier aussi que currentSearchAbortController n'a pas été réinitialisé
            if (!currentSearchAbortController || currentSearchAbortController !== abortController || currentSearchAbortController.signal.aborted) {
                return;
            }
            
            clientResultsEl.innerHTML = '';
            
            if (!results.length) {
                const item = document.createElement('div');
                item.className = 'client-result-item empty';
                item.textContent = 'Aucun client trouvé.';
                clientResultsEl.appendChild(item);
                return;
            }
            
            results.forEach(client => {
                // Afficher l'adresse exacte
                const displayAddress = client.address || 
                    `${client.adresse || ''} ${client.code_postal || ''} ${client.ville || ''}`.trim();
                
                // Construire les informations supplémentaires
                let extraInfo = [];
                if (client.dirigeant_complet) {
                    extraInfo.push(`Dirigeant: ${escapeHtml(client.dirigeant_complet)}`);
                }
                if (client.telephone) {
                    extraInfo.push(`Tel: ${escapeHtml(client.telephone)}`);
                }
                
                const item = document.createElement('div');
                item.className = 'client-result-item';
                item.setAttribute('role', 'button');
                item.setAttribute('tabindex', '0');
                item.setAttribute('aria-label', `Ajouter ${escapeHtml(client.name)} à la tournée`);
                item.innerHTML =
                    `<strong>${escapeHtml(client.name)}</strong>` +
                    `<span>${escapeHtml(displayAddress)} — ${escapeHtml(client.code)}</span>` +
                    (extraInfo.length > 0 ? `<small>${extraInfo.join(' • ')}</small>` : '');
                
                // Support clavier
                const handleClick = () => {
                    addClientToRoute(client);
                };
                item.addEventListener('click', handleClick);
                item.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        handleClick();
                    }
                });
                
                clientResultsEl.appendChild(item);
            });
        } catch (err) {
            if (err.name !== 'AbortError') {
                console.error('Erreur lors de la recherche:', err);
                // Vérifier que currentSearchAbortController n'a pas été réinitialisé avant d'afficher l'erreur
                if (currentSearchAbortController === abortController) {
                    clientResultsEl.innerHTML = '';
                    const errorItem = document.createElement('div');
                    errorItem.className = 'client-result-item empty';
                    errorItem.textContent = 'Erreur lors de la recherche. Veuillez réessayer.';
                    clientResultsEl.appendChild(errorItem);
                }
            }
        } finally {
            // Ne réinitialiser que si c'est toujours le même controller
            if (currentSearchAbortController === abortController) {
                currentSearchAbortController = null;
            }
        }
    }, CONFIG.SEARCH_DEBOUNCE_MS);
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
    
    // Mettre à jour l'input adresse si présent
    const startAddressInput = document.getElementById('startAddressInput');
    if (startAddressInput && label) {
        startAddressInput.value = label;
    }

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
    
    // Effacer l'input adresse
    const startAddressInput = document.getElementById('startAddressInput');
    if (startAddressInput) {
        startAddressInput.value = '';
    }
});

// Géocoder l'adresse si l'utilisateur tape dans l'input et valide (Enter)
const startAddressInput = document.getElementById('startAddressInput');
if (startAddressInput) {
    startAddressInput.addEventListener('keydown', async (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            const address = startAddressInput.value.trim();
            if (!address) return;
            
            routeMessageEl.textContent = "Géocodage de l'adresse en cours…";
            routeMessageEl.className = 'maps-message hint';
            
            const coords = await geocodeAddress(address);
            if (coords) {
                setStartPoint([coords.lat, coords.lng], address);
                routeMessageEl.textContent = "Point de départ défini.";
                routeMessageEl.className = 'maps-message success';
            } else {
                routeMessageEl.textContent = "Impossible de géocoder cette adresse.";
                routeMessageEl.className = 'maps-message alert';
            }
        }
    });
}

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
            if (!client || !isValidCoordinate(client.lat, client.lng)) return null;
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

    if (clientsForRouting.length > CONFIG.MAX_CLIENTS_PER_ROUTE) {
        routeMessageEl.textContent = `Limitez-vous à ${CONFIG.MAX_CLIENTS_PER_ROUTE} clients maximum par tournée.`;
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

    routeMessageEl.textContent = "Calcul de l'itinéraire en cours…";
    routeMessageEl.className = 'maps-message hint';

    fetchWithTimeout(url, {}, CONFIG.FETCH_TIMEOUT_MS)
        .then(async (r) => {
            if (!r.ok) {
                throw new Error(`Erreur HTTP ${r.status}: ${r.statusText}`);
            }
            
            const text = await r.text();
            if (!text || text.trim() === '') {
                throw new Error('Réponse vide du serveur OSRM');
            }
            
            try {
                return JSON.parse(text);
            } catch (parseError) {
                throw new Error('Réponse JSON invalide du serveur OSRM');
            }
        })
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
            routeMessageEl.textContent = "Erreur lors du calcul de l'itinéraire : " + err.message;
            routeMessageEl.className = 'maps-message alert';
        });
});

// ==================
// Gestion des filtres de marqueurs
// ==================

const activeFilters = new Set(['all', 'sav', 'livraison', 'normal']);

function applyMarkerFilters() {
    if (!map || !clientMarkers) return;
    
    Object.values(clientMarkers).forEach(marker => {
        const clientId = marker.options?.clientId;
        if (!clientId) return;
        
        const client = clientsCache.get(clientId);
        if (!client) return;
        
        let visible = false;
        
        if (activeFilters.has('all')) {
            visible = true;
        } else {
            const hasSav = client.hasSav || false;
            const hasLivraison = client.hasLivraison || false;
            
            if (activeFilters.has('sav') && hasSav) visible = true;
            if (activeFilters.has('livraison') && hasLivraison) visible = true;
            if (activeFilters.has('normal') && !hasSav && !hasLivraison) visible = true;
        }
        
        if (visible) {
            if (!map.hasLayer(marker)) {
                map.addLayer(marker);
            }
        } else {
            if (map.hasLayer(marker)) {
                map.removeLayer(marker);
            }
        }
    });
}

// Initialiser les filtres
document.addEventListener('DOMContentLoaded', () => {
    const filterToggles = document.querySelectorAll('#mapFilters input[type="checkbox"]');
    filterToggles.forEach(toggle => {
        toggle.addEventListener('change', () => {
            const filter = toggle.dataset.filter;
            
            // Si "Tous" est coché, décocher les autres
            if (filter === 'all' && toggle.checked) {
                filterToggles.forEach(t => {
                    if (t !== toggle && t.dataset.filter !== 'all') {
                        t.checked = false;
                        activeFilters.delete(t.dataset.filter);
                    }
                });
                activeFilters.clear();
                activeFilters.add('all');
            } else if (filter === 'all' && !toggle.checked) {
                // Si "Tous" est décoché, cocher tous les autres
                filterToggles.forEach(t => {
                    if (t.dataset.filter !== 'all') {
                        t.checked = true;
                        activeFilters.add(t.dataset.filter);
                    }
                });
                activeFilters.delete('all');
            } else {
                // Gestion des autres filtres
                if (toggle.checked) {
                    activeFilters.add(filter);
                    // Décocher "Tous" si un filtre spécifique est coché
                    const allToggle = document.querySelector('#mapFilters input[data-filter="all"]');
                    if (allToggle) {
                        allToggle.checked = false;
                        activeFilters.delete('all');
                    }
                } else {
                    activeFilters.delete(filter);
                    // Si aucun filtre spécifique n'est coché, cocher "Tous"
                    const hasAnySpecific = Array.from(filterToggles).some(t => 
                        t.dataset.filter !== 'all' && t.checked
                    );
                    if (!hasAnySpecific) {
                        const allToggle = document.querySelector('#mapFilters input[data-filter="all"]');
                        if (allToggle) {
                            allToggle.checked = true;
                            activeFilters.clear();
                            activeFilters.add('all');
                        }
                    }
                }
            }
            
            applyMarkerFilters();
        });
    });
});

// Bouton clear pour la recherche
const clientSearchClear = document.getElementById('clientSearchClear');
if (clientSearchClear) {
    clientSearchClear.addEventListener('click', () => {
        clientSearchInput.value = '';
        clientResultsEl.innerHTML = '';
        clientResultsEl.style.display = 'none';
        clientSearchClear.classList.remove('visible');
        clientSearchInput.focus();
    });
    
    clientSearchInput.addEventListener('input', () => {
        clientSearchClear.classList.toggle('visible', clientSearchInput.value.length > 0);
    });
}

// Charger tous les clients au démarrage (après que toutes les fonctions soient définies)
loadAllClients();

// ==================
// Gestion du panneau repliable (mobile)
// ==================

const mapsPanel = document.getElementById('mapsPanel');
const togglePanelBtn = document.getElementById('togglePanelBtn');
const panelHeader = document.getElementById('panelHeader');

function togglePanel() {
    if (mapsPanel) {
        mapsPanel.classList.toggle('collapsed');
        if (togglePanelBtn) {
            togglePanelBtn.textContent = mapsPanel.classList.contains('collapsed') ? '▲' : '▼';
        }
    }
}

if (togglePanelBtn) {
    togglePanelBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        togglePanel();
    });
}

if (panelHeader) {
    panelHeader.addEventListener('click', (e) => {
        // Ne toggle que si on clique sur le header, pas sur les boutons
        if (e.target === panelHeader || e.target.closest('h2')) {
            togglePanel();
        }
    });
}

// Initialiser le point de départ par défaut après un court délai
// (pour laisser le temps à maps-enhancements.js de restaurer depuis localStorage)
setTimeout(() => {
    initDefaultStartPoint();
    // Appliquer les filtres après chargement des clients
    applyMarkerFilters();
}, 2500); // 2.5s : après le restore de maps-enhancements.js (2s) + marge
</script>
</body>
</html>