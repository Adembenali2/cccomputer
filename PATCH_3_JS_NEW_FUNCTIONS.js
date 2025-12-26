// ============================================
// PATCH 3 : JS - Nouvelles fonctions
// À ajouter AVANT le script existant (ligne 191)
// ============================================

<script>
// Système de toasts
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

// localStorage persistence
const StorageManager = {
    KEY_SELECTED_CLIENTS: 'maps_selected_clients',
    KEY_START_POINT: 'maps_start_point',
    
    saveSelectedClients(clients) {
        try {
            localStorage.setItem(this.KEY_SELECTED_CLIENTS, JSON.stringify(clients));
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadSelectedClients() {
        try {
            const data = localStorage.getItem(this.KEY_SELECTED_CLIENTS);
            return data ? JSON.parse(data) : null;
        } catch (e) {
            console.warn('localStorage load failed:', e);
            return null;
        }
    },
    
    saveStartPoint(point) {
        try {
            localStorage.setItem(this.KEY_START_POINT, JSON.stringify(point));
        } catch (e) {
            console.warn('localStorage save failed:', e);
        }
    },
    
    loadStartPoint() {
        try {
            const data = localStorage.getItem(this.KEY_START_POINT);
            return data ? JSON.parse(data) : null;
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

// Filtres markers
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
        if (!map || !clientMarkers) return;
        
        Object.values(clientMarkers).forEach(marker => {
            const clientId = marker.options?.clientId;
            if (!clientId) return;
            
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

// Recherche dans zone visible
function searchInVisibleBounds() {
    if (!map) return;
    
    const bounds = map.getBounds();
    const visibleClients = Array.from(clientsCache.values()).filter(client => {
        if (!isValidCoordinate(client.lat, client.lng)) return false;
        return bounds.contains([client.lat, client.lng]);
    });
    
    ToastManager.show(`${visibleClients.length} client(s) dans la zone visible`, 'info');
    
    // Highlight les markers visibles
    Object.values(clientMarkers).forEach(marker => {
        const clientId = marker.options?.clientId;
        const client = clientsCache.get(clientId);
        if (client && bounds.contains([client.lat, client.lng])) {
            marker.openPopup();
        }
    });
}

// Export itinéraire
function exportRoute(format = 'csv') {
    if (!lastOrderedStops || lastOrderedStops.length === 0) {
        ToastManager.show('Aucun itinéraire à exporter', 'warning');
        return;
    }
    
    if (format === 'csv') {
        const headers = ['Ordre', 'Nom', 'Code', 'Adresse', 'Latitude', 'Longitude'];
        const rows = [
            ['Départ', 'Point de départ', '', startPoint ? `${startPoint[0]},${startPoint[1]}` : '', startPoint?.[0] || '', startPoint?.[1] || ''],
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
    }
}

// Sections repliables
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

// Barre de progression géocodage
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

// Initialisation au chargement
document.addEventListener('DOMContentLoaded', () => {
    // Restaurer depuis localStorage (après chargement clients)
    setTimeout(() => {
        const savedClients = StorageManager.loadSelectedClients();
        if (savedClients && savedClients.length > 0 && typeof clientsCache !== 'undefined') {
            savedClients.forEach(saved => {
                const client = clientsCache.get(saved.id);
                if (client && typeof selectedClients !== 'undefined') {
                    selectedClients.push({ id: client.id, priority: saved.priority || 1 });
                }
            });
            if (typeof renderSelectedClients === 'function') {
                renderSelectedClients();
            }
            ToastManager.show('Itinéraire restauré depuis la sauvegarde', 'info', 3000);
        }
        
        const savedStart = StorageManager.loadStartPoint();
        if (savedStart && savedStart.length === 2 && typeof setStartPoint === 'function') {
            setStartPoint(savedStart, 'Point restauré');
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

// Intercepter les fonctions existantes pour ajouter toasts et persistence
// Ces fonctions seront appelées après que le script principal soit chargé
setTimeout(() => {
    // Wrapper pour addClientToRoute
    if (typeof addClientToRoute !== 'undefined') {
        const originalAddClientToRoute = addClientToRoute;
        window.addClientToRoute = async function(client) {
            const result = await originalAddClientToRoute.call(this, client);
            if (result !== false && typeof StorageManager !== 'undefined' && typeof selectedClients !== 'undefined') {
                StorageManager.saveSelectedClients(selectedClients);
                const countEl = document.getElementById('selectedClientsCount');
                if (countEl) {
                    countEl.textContent = selectedClients.length;
                }
                ToastManager.show(`Client "${client.name}" ajouté`, 'success', 2000);
            }
            return result;
        };
    }
    
    // Wrapper pour setStartPoint
    if (typeof setStartPoint !== 'undefined') {
        const originalSetStartPoint = setStartPoint;
        window.setStartPoint = function(latlng, label) {
            originalSetStartPoint.call(this, latlng, label);
            if (typeof StorageManager !== 'undefined' && typeof startPoint !== 'undefined') {
                StorageManager.saveStartPoint(startPoint);
                ToastManager.show('Point de départ défini', 'success', 2000);
            }
        };
    }
    
    // Wrapper pour renderSelectedClients
    if (typeof renderSelectedClients !== 'undefined') {
        const originalRenderSelectedClients = renderSelectedClients;
        window.renderSelectedClients = function() {
            originalRenderSelectedClients.call(this);
            if (typeof selectedClients !== 'undefined') {
                const countEl = document.getElementById('selectedClientsCount');
                if (countEl) {
                    countEl.textContent = selectedClients.length;
                }
                if (typeof StorageManager !== 'undefined') {
                    StorageManager.saveSelectedClients(selectedClients);
                }
            }
        };
    }
}, 500);

</script>

