/**
 * Module centralisé pour les appels API
 * Gère les erreurs, retry, AbortController, et notifications
 */

class ApiClient {
    constructor() {
        this.controllers = new Map(); // Pour stocker les AbortController actifs
        this.defaultRetries = 3;
        this.defaultRetryDelay = 1000; // 1 seconde
    }

    /**
     * Effectue une requête fetch avec gestion d'erreurs, retry et AbortController
     * 
     * @param {string} url URL de l'API
     * @param {RequestInit} options Options de fetch (method, body, headers, etc.)
     * @param {Object} config Configuration additionnelle (retries, retryDelay, abortKey)
     * @returns {Promise<Response>} Réponse fetch
     */
    async request(url, options = {}, config = {}) {
        const {
            retries = this.defaultRetries,
            retryDelay = this.defaultRetryDelay,
            abortKey = null
        } = config;

        // Annuler la requête précédente si une clé est fournie
        if (abortKey && this.controllers.has(abortKey)) {
            this.controllers.get(abortKey).abort();
        }

        // Créer un nouvel AbortController
        const controller = new AbortController();
        if (abortKey) {
            this.controllers.set(abortKey, controller);
        }

        // Options par défaut
        const defaultOptions = {
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                ...options.headers
            },
            signal: controller.signal
        };

        const fetchOptions = { ...defaultOptions, ...options };

        // Tentatives avec retry
        let lastError = null;
        for (let attempt = 0; attempt <= retries; attempt++) {
            try {
                const response = await fetch(url, fetchOptions);

                // Nettoyer le controller si succès
                if (abortKey) {
                    this.controllers.delete(abortKey);
                }

                if (!response.ok) {
                    // Erreur HTTP (4xx, 5xx)
                    const errorText = await response.text().catch(() => '');
                    let errorData = null;
                    try {
                        errorData = errorText ? JSON.parse(errorText) : null;
                    } catch (e) {
                        // Pas de JSON valide
                    }

                    // Ne pas retry sur les erreurs 4xx (sauf 429 Too Many Requests)
                    if (response.status >= 400 && response.status < 500 && response.status !== 429) {
                        throw new ApiError(
                            errorData?.error || `Erreur HTTP ${response.status}`,
                            response.status,
                            errorData
                        );
                    }

                    // Retry sur erreurs 5xx ou 429
                    if (attempt < retries) {
                        await this._delay(retryDelay * (attempt + 1));
                        continue;
                    }

                    throw new ApiError(
                        errorData?.error || `Erreur HTTP ${response.status}`,
                        response.status,
                        errorData
                    );
                }

                return response;

            } catch (error) {
                lastError = error;

                // Ne pas retry si la requête a été annulée
                if (error.name === 'AbortError') {
                    throw error;
                }

                // Ne pas retry sur les erreurs réseau si c'est la dernière tentative
                if (attempt < retries) {
                    await this._delay(retryDelay * (attempt + 1));
                    continue;
                }
            }
        }

        // Nettoyer le controller en cas d'échec
        if (abortKey) {
            this.controllers.delete(abortKey);
        }

        throw lastError || new Error('Erreur inconnue lors de la requête');
    }

    /**
     * Effectue une requête JSON et parse la réponse
     * 
     * @param {string} url URL de l'API
     * @param {RequestInit} options Options de fetch
     * @param {Object} config Configuration additionnelle
     * @returns {Promise<Object>} Données JSON parsées
     */
    async json(url, options = {}, config = {}) {
        const response = await this.request(url, options, config);
        const text = await response.text();
        
        if (!text) {
            return null;
        }

        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Réponse JSON invalide: ' + text.substring(0, 100));
        }
    }

    /**
     * Annule une requête en cours
     * 
     * @param {string} abortKey Clé de la requête à annuler
     */
    abort(abortKey) {
        if (this.controllers.has(abortKey)) {
            this.controllers.get(abortKey).abort();
            this.controllers.delete(abortKey);
        }
    }

    /**
     * Annule toutes les requêtes en cours
     */
    abortAll() {
        this.controllers.forEach(controller => controller.abort());
        this.controllers.clear();
    }

    /**
     * Délai avant retry (backoff exponentiel)
     * 
     * @private
     * @param {number} ms Millisecondes à attendre
     * @returns {Promise<void>}
     */
    _delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

/**
 * Classe d'erreur personnalisée pour les erreurs API
 */
class ApiError extends Error {
    constructor(message, status = 0, data = null) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.data = data;
    }
}

/**
 * Helper pour afficher des notifications d'erreur (sans changer le design)
 * 
 * @param {string} message Message d'erreur
 * @param {string} type Type de notification ('error', 'success', 'info')
 */
function showNotification(message, type = 'error') {
    // Utiliser les notifications existantes si disponibles
    // Sinon, utiliser console.error (ne change pas le design)
    if (typeof window.showFlashMessage === 'function') {
        window.showFlashMessage(message, type);
    } else {
        console.error(`[${type.toUpperCase()}] ${message}`);
    }
}

// Instance globale
const apiClient = new ApiClient();

// Export pour utilisation dans d'autres scripts
if (typeof window !== 'undefined') {
    window.ApiClient = ApiClient;
    window.ApiError = ApiError;
    window.apiClient = apiClient;
    window.showNotification = showNotification;
}

