// ============================================
// PATCH 3 : JS - Nouvelles fonctions (VERSION B - Fichier externe)
// À inclure via : <script src="/assets/js/maps-enhancements.js"></script>
// ============================================

// ============================================
// HELPERS MANQUANTS
// ============================================

/**
 * Échappe le HTML pour éviter XSS
 */
function escapeHtml(str) {
    if (str == null) return '';
    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

/**
 * Valide des coordonnées géographiques
 */
function isValidCoordinate(lat, lng) {
    if (lat == null || lng == null || isNaN(lat) || isNaN(lng)) {
        return false;
    }
    return lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
}

/**
 * Normalise un point en [lat, lng] ou null
 * Accepte [lat, lng] ou {lat, lng} ou objet Leaflet LatLng
 */
function normalizeLatLng(point) {
    if (!point) return null;
    
    // Si c'est déjà un array [lat, lng]
    if (Array.isArray(point) && point.length >= 2) {
        const lat = parseFloat(point[0]);
        const lng = parseFloat(point[1]);
        if (isValidCoordinate(lat, lng)) {
            return [lat, lng];
        }
        return null;
    }
    
    // Si c'est un objet {lat, lng}
    if (typeof point === 'object' && point !== null) {
        let lat, lng;
        
        // Objet Leaflet LatLng
        if (typeof point.lat === 'number' && typeof point.lng === 'number') {
            lat = point.lat;
            lng = point.lng;
        } else if (typeof point[0] === 'number' && typeof point[1] === 'number') {
            lat = point[0];
            lng = point[1];
        } else {
            return null;
        }
        
        if (isValidCoordinate(lat, lng)) {
            return [lat, lng];
        }
    }
    
    return null;
}

/**
 * Attend qu'une condition soit vraie avant d'exécuter un callback
 */
function waitFor(predicate, callback, tries = 100, delay = 100) {
    if (predicate()) {
        callback();
        return;
    }
    
    let attempts = 0;
    const interval = setInterval(() => {
        attempts++;
        if (predicate()) {
            clearInterval(interval);
            callback();
        } else if (attempts >= tries) {
            clearInterval(interval);
            // Ne pas afficher d'avertissement si les fonctions ne sont pas critiques
            // console.warn('waitFor: timeout, condition never met');
        }
    }, delay);
}

// ============================================
// SYSTÈME DE TOASTS
// ============================================

const ToastManager = {
    container: null,
    
    init() {
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.className = 'toast-container';
            this.container.setAttribute('aria-live', 'polite');
            this.container.setAttribute('aria-atomic', 'true');
            const mapsPage = document.getElementById('maps-page');
            if (mapsPage) {
                mapsPage.appendChild(this.container);
            }
        }
    },
    
    show(message, type = 'info', duration = 3000) {
        this.init();
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        
        const icons = {
            success: '✅',
            error: '❌',
            info: 'ℹ️',
            warning: '⚠️'
        };
        
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${escapeHtml(message)}</span>
            <button type="button" class="toast-close" aria-label="Fermer">×</button>
        `;
        
        const closeBtn = toast.querySelector('.toast-close');
        const close = () => {
            toast.style.animation = 'slideInRight 0.3s reverse';
            setTimeout(() => toast.remove(), 300);
        };
        
        closeBtn.addEventListener('click', close);
        
        if (duration > 0) {
            setTimeout(close, duration);
        }
        
        this.container.appendChild(toast);
    }
};

// ============================================
// LOCALSTORAGE PERSISTENCE
// ============================================

const StorageManager = {
    KEY_SELECTED_CLIENTS: 'maps_selected_clients',
    KEY_START_POINT: 'maps_start_point',
    
    /**
     * Sauvegarde les clients sélectionnés (format minimal stable)
     */
    saveSelectedClients(clients) {
        try {
            if (!Array.isArray(clients)) return;
            // Format minimal : uniquement id et priority
            const minimal = clients.map(x => ({
                id: parseInt(x.id) || 0,
                priority: parseInt(x.priority) || 1
            })).filter(x => x.id > 0);
            localStorage.setItem(this.KEY_SELECTED_CLIENTS, JSON.stringify(minimal));
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadSelectedClients() {
        try {
            const data = localStorage.getItem(this.KEY_SELECTED_CLIENTS);
            if (!data) return null;
            const parsed = JSON.parse(data);
            return Array.isArray(parsed) ? parsed : null;
        } catch (e) {
            console.warn('localStorage load failed:', e);
            return null;
        }
    },
    
    /**
     * Sauvegarde le point de départ (normalisé en [lat, lng])
     */
    saveStartPoint(point) {
        try {
            const normalized = normalizeLatLng(point);
            if (normalized) {
                localStorage.setItem(this.KEY_START_POINT, JSON.stringify(normalized));
            } else {
                localStorage.removeItem(this.KEY_START_POINT);
            }
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadStartPoint() {
        try {
            const data = localStorage.getItem(this.KEY_START_POINT);
            if (!data) return null;
            const parsed = JSON.parse(data);
            return normalizeLatLng(parsed);
        } catch (e) {
            console.warn('localStorage load failed:', e);
            return null;
        }
    },
    
    clear() {
        try {
            localStorage.removeItem(this.KEY_SELECTED_CLIENTS);
            localStorage.removeItem(this.KEY_START_POINT);
        } catch (e) {
            console.warn('localStorage clear failed:', e);
        }
    }
};

// ============================================
// FILTRES MARKERS
// ============================================

const FilterManager = {
    activeFilters: new Set(['all', 'sav', 'livraison', 'normal']),
    
    init() {
        const toggles = document.querySelectorAll('#maps-page .map-filter-toggle input[type="checkbox"]');
        toggles.forEach(toggle => {
            toggle.addEventListener('change', () => {
                const filter = toggle.dataset.filter;
                if (toggle.checked) {
                    this.activeFilters.add(filter);
                } else {
                    this.activeFilters.delete(filter);
                }
                this.applyFilters();
            });
        });
    },
    
    applyFilters() {
        // Vérifications robustes
        if (typeof map === 'undefined' || !map || typeof clientMarkers === 'undefined' || !clientMarkers) {
            return;
        }
        
        Object.values(clientMarkers).forEach(marker => {
            const clientId = marker.options?.clientId;
            if (!clientId) return;
            
            if (typeof clientsCache === 'undefined' || !clientsCache) return;
            const client = clientsCache.get(clientId);
            if (!client) return;
            
            let visible = false;
            
            if (this.activeFilters.has('all')) {
                visible = true;
            } else {
                const hasSav = client.hasSav || false;
                const hasLivraison = client.hasLivraison || false;
                
                if (this.activeFilters.has('sav') && hasSav) visible = true;
                if (this.activeFilters.has('livraison') && hasLivraison) visible = true;
                if (this.activeFilters.has('normal') && !hasSav && !hasLivraison) visible = true;
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
};

// ============================================
// RECHERCHE DANS ZONE VISIBLE
// ============================================

function searchInVisibleBounds() {
    // Vérifications robustes
    if (typeof map === 'undefined' || !map || typeof map.getBounds !== 'function') {
        ToastManager.show('Carte non disponible', 'error');
        return;
    }
    
    if (typeof clientsCache === 'undefined' || !clientsCache || typeof clientMarkers === 'undefined' || !clientMarkers) {
        ToastManager.show('Données clients non disponibles', 'error');
        return;
    }
    
    try {
        const bounds = map.getBounds();
        const visibleClients = Array.from(clientsCache.values()).filter(client => {
            if (!isValidCoordinate(client.lat, client.lng)) return false;
            return bounds.contains([client.lat, client.lng]);
        });
        
        ToastManager.show(`${visibleClients.length} client(s) dans la zone visible`, 'info');
        
        // Limiter à 20 popups max pour éviter le flood
        let opened = 0;
        const maxPopups = 20;
        
        Object.values(clientMarkers).forEach(marker => {
            if (opened >= maxPopups) return;
            
            const clientId = marker.options?.clientId;
            if (!clientId) return;
            
            const client = clientsCache.get(clientId);
            if (client && isValidCoordinate(client.lat, client.lng) && bounds.contains([client.lat, client.lng])) {
                // Simple highlight : bounce léger (pas de popup pour éviter flood)
                marker.setZIndexOffset(1000);
                opened++;
            }
        });
        
        if (opened > 0) {
            // Reset z-index après 2 secondes
            setTimeout(() => {
                Object.values(clientMarkers).forEach(marker => {
                    marker.setZIndexOffset(0);
                });
            }, 2000);
        }
    } catch (e) {
        console.error('searchInVisibleBounds error:', e);
        ToastManager.show('Erreur lors de la recherche', 'error');
    }
}

// ============================================
// EXPORT ITINÉRAIRE
// ============================================

function exportRoute(format = 'csv') {
    // Vérifications robustes
    if (typeof lastOrderedStops === 'undefined' || !Array.isArray(lastOrderedStops) || lastOrderedStops.length === 0) {
        ToastManager.show('Aucun itinéraire à exporter', 'warning');
        return;
    }
    
    if (format === 'csv') {
        try {
            const headers = ['Ordre', 'Nom', 'Code', 'Adresse', 'Latitude', 'Longitude'];
            
            // Normaliser startPoint
            const normalizedStart = normalizeLatLng(typeof startPoint !== 'undefined' ? startPoint : null);
            const startRow = normalizedStart 
                ? ['Départ', 'Point de départ', '', `${normalizedStart[0]},${normalizedStart[1]}`, normalizedStart[0], normalizedStart[1]]
                : ['Départ', 'Point de départ', '', '', '', ''];
            
            const rows = [
                startRow,
                ...lastOrderedStops.map((client, idx) => [
                    idx + 1,
                    client.name || '',
                    client.code || '',
                    client.address || '',
                    client.lat || '',
                    client.lng || ''
                ])
            ];
            
            const csv = [
                headers.join(','),
                ...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
            ].join('\n');
            
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `itineraire_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            
            ToastManager.show('Itinéraire exporté en CSV', 'success');
        } catch (e) {
            console.error('exportRoute error:', e);
            ToastManager.show('Erreur lors de l\'export', 'error');
        }
    }
}

// ============================================
// SECTIONS REPLIABLES
// ============================================

function initCollapsibleSections() {
    const sections = document.querySelectorAll('#maps-page .section-title[data-section]');
    sections.forEach(title => {
        title.addEventListener('click', () => {
            const content = title.nextElementSibling;
            if (content && content.classList.contains('section-content')) {
                title.classList.toggle('collapsed');
                content.classList.toggle('collapsed');
            }
        });
    });
}

// ============================================
// BARRE DE PROGRESSION GÉOCODAGE
// ============================================

function updateGeocodeProgress(current, total) {
    let progressBar = document.getElementById('geocodeProgressBar');
    if (!progressBar) {
        const container = document.getElementById('notFoundClientsSection');
        if (container && container.parentNode) {
            progressBar = document.createElement('div');
            progressBar.id = 'geocodeProgressBar';
            progressBar.className = 'progress-bar';
            progressBar.innerHTML = '<div class="progress-bar-fill" style="width: 0%"></div>';
            container.parentNode.insertBefore(progressBar, container);
        }
    }
    
    if (progressBar) {
        const fill = progressBar.querySelector('.progress-bar-fill');
        if (fill) {
            const percent = total > 0 ? (current / total) * 100 : 0;
            fill.style.width = percent + '%';
        }
    }
}

// ============================================
// INITIALISATION AU CHARGEMENT
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    // Restaurer depuis localStorage (après chargement clients)
    setTimeout(() => {
        try {
            const savedClients = StorageManager.loadSelectedClients();
            if (savedClients && savedClients.length > 0 && typeof clientsCache !== 'undefined' && clientsCache && typeof selectedClients !== 'undefined' && Array.isArray(selectedClients)) {
                // Éviter les doublons : vérifier si le client n'est pas déjà dans selectedClients
                const existingIds = new Set(selectedClients.map(s => s.id));
                let restored = 0;
                
                savedClients.forEach(saved => {
                    if (existingIds.has(saved.id)) return; // Déjà présent, skip
                    
                    const client = clientsCache.get(saved.id);
                    if (client) {
                        selectedClients.push({ id: client.id, priority: saved.priority || 1 });
                        existingIds.add(saved.id);
                        restored++;
                    }
                });
                
                if (restored > 0 && typeof renderSelectedClients === 'function') {
                    renderSelectedClients();
                    ToastManager.show(`${restored} client(s) restauré(s) depuis la sauvegarde`, 'info', 3000);
                }
            }
            
            const savedStart = StorageManager.loadStartPoint();
            if (savedStart && savedStart.length === 2 && typeof setStartPoint === 'function') {
                setStartPoint(savedStart, 'Point restauré');
            }
        } catch (e) {
            console.error('Restore from localStorage error:', e);
        }
    }, 2000);
    
    // Initialiser sections repliables
    initCollapsibleSections();
    
    // Initialiser filtres (après chargement map)
    setTimeout(() => {
        if (typeof FilterManager !== 'undefined') {
            FilterManager.init();
        }
    }, 1000);
    
    // Bouton recherche zone visible
    const toolbarRight = document.querySelector('#maps-page .map-toolbar-right');
    if (toolbarRight) {
        const btnSearchBounds = document.createElement('button');
        btnSearchBounds.type = 'button';
        btnSearchBounds.className = 'secondary';
        btnSearchBounds.textContent = '🔍 Zone visible';
        btnSearchBounds.addEventListener('click', searchInVisibleBounds);
        toolbarRight.appendChild(btnSearchBounds);
    }
    
    // Bouton export
    const routeExtra = document.querySelector('#maps-page .route-extra');
    if (routeExtra) {
        const btnExport = document.createElement('button');
        btnExport.type = 'button';
        btnExport.className = 'secondary';
        btnExport.textContent = '📥 Exporter CSV';
        btnExport.addEventListener('click', () => exportRoute('csv'));
        routeExtra.appendChild(btnExport);
    }
    
    // Bouton effacer recherche
    const clearBtn = document.getElementById('clientSearchClear');
    const clientSearchInput = document.getElementById('clientSearch');
    const clientResultsEl = document.getElementById('clientResults');
    
    if (clearBtn && clientSearchInput && clientResultsEl) {
        clearBtn.addEventListener('click', () => {
            clientSearchInput.value = '';
            clientResultsEl.innerHTML = '';
            clientResultsEl.style.display = 'none';
            clearBtn.classList.remove('visible');
            clientSearchInput.focus();
        });
        
        clientSearchInput.addEventListener('input', () => {
            clearBtn.classList.toggle('visible', clientSearchInput.value.length > 0);
        });
    }
});

// ============================================
// WRAPPERS DES FONCTIONS EXISTANTES
// ============================================

// Utiliser waitFor au lieu de setTimeout pour plus de robustesse
// Augmenter le nombre de tentatives pour laisser plus de temps au chargement
waitFor(
    () => typeof addClientToRoute !== 'undefined' && typeof setStartPoint !== 'undefined' && typeof renderSelectedClients !== 'undefined',
    () => {
        // Wrapper pour addClientToRoute
        if (typeof addClientToRoute !== 'undefined') {
            const originalAddClientToRoute = addClientToRoute;
            window.addClientToRoute = async function(client) {
                try {
                    const result = await originalAddClientToRoute.call(this, client);
                    if (result !== false && typeof StorageManager !== 'undefined' && typeof selectedClients !== 'undefined' && Array.isArray(selectedClients)) {
                        StorageManager.saveSelectedClients(selectedClients);
                        const countEl = document.getElementById('selectedClientsCount');
                        if (countEl) {
                            countEl.textContent = selectedClients.length;
                        }
                        if (client && client.name) {
                            ToastManager.show(`Client "${client.name}" ajouté`, 'success', 2000);
                        }
                    }
                    return result;
                } catch (e) {
                    console.error('addClientToRoute wrapper error:', e);
                    return false;
                }
            };
        }
        
        // Wrapper pour setStartPoint
        if (typeof setStartPoint !== 'undefined') {
            const originalSetStartPoint = setStartPoint;
            window.setStartPoint = function(latlng, label) {
                try {
                    originalSetStartPoint.call(this, latlng, label);
                    if (typeof StorageManager !== 'undefined' && typeof startPoint !== 'undefined') {
                        // Normaliser avant sauvegarde
                        StorageManager.saveStartPoint(startPoint);
                        ToastManager.show('Point de départ défini', 'success', 2000);
                    }
                } catch (e) {
                    console.error('setStartPoint wrapper error:', e);
                }
            };
        }
        
        // Wrapper pour renderSelectedClients
        if (typeof renderSelectedClients !== 'undefined') {
            const originalRenderSelectedClients = renderSelectedClients;
            window.renderSelectedClients = function() {
                try {
                    originalRenderSelectedClients.call(this);
                    if (typeof selectedClients !== 'undefined' && Array.isArray(selectedClients)) {
                        const countEl = document.getElementById('selectedClientsCount');
                        if (countEl) {
                            countEl.textContent = selectedClients.length;
                        }
                        if (typeof StorageManager !== 'undefined') {
                            StorageManager.saveSelectedClients(selectedClients);
                        }
                    }
                } catch (e) {
                    console.error('renderSelectedClients wrapper error:', e);
                }
            };
        }
    },
    100, // 100 tentatives
    150 // 150ms entre chaque tentative (max 15 secondes)
);

