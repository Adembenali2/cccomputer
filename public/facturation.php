<?php
// /public/facturation.php
// Page de gestion de facturation et paiements

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('facturation', ['Admin', 'Dirigeant', 'Chargé relation clients']); // Accessible aux admins, dirigeants et commerciaux
require_once __DIR__ . '/../includes/helpers.php';

// CSRF token
$CSRF = ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facturation & Paiements - CCComputer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <style>
        /* Styles spécifiques à la page facturation */
        .page-facturation {
            background-color: var(--bg-secondary);
            min-height: 100vh;
        }

        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
        }

        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0 0 0.5rem 0;
        }

        .page-sub {
            color: var(--text-secondary);
            font-size: 0.95rem;
            margin: 0;
        }

        /* Search bar container */
        .search-container {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 1rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
        }

        .search-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .search-icon {
            position: absolute;
            left: 0.75rem;
            width: 20px;
            height: 20px;
            color: var(--text-muted);
            pointer-events: none;
            flex-shrink: 0;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 0.75rem 0.75rem 2.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 1rem;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .search-input::placeholder {
            color: var(--text-muted);
        }

        /* Results container */
        #client-results {
            margin-top: 1rem;
        }

        .results-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            overflow: hidden;
        }

        .results-table thead {
            background: var(--bg-tertiary);
        }

        .results-table th {
            padding: 0.75rem 1rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-bottom: 2px solid var(--border-color);
        }

        .results-table td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-primary);
        }

        .results-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .results-table tbody tr:hover {
            background: var(--bg-secondary);
        }

        .results-table tbody tr:last-child td {
            border-bottom: none;
        }

        .no-results {
            text-align: center;
            padding: 3rem 1rem;
            color: var(--text-secondary);
            font-size: 1rem;
        }

        .no-results-icon {
            width: 48px;
            height: 48px;
            margin: 0 auto 1rem;
            color: var(--text-muted);
        }

        .loading {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }

        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid var(--border-color);
            border-top-color: var(--accent-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Main content area */
        .content-area {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            min-height: 200px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-container {
                padding: 1rem;
            }

            .page-title {
                font-size: 1.5rem;
            }

            .search-container {
                padding: 0.75rem;
            }

            .content-area {
                padding: 1rem;
            }
        }
    </style>
</head>
<body class="page-facturation">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="page-container">
        <div class="page-header">
            <h1 class="page-title">Facturation & Paiements</h1>
            <p class="page-sub">Gestion des factures, paiements et consommations clients</p>
        </div>

        <div class="search-container">
            <div class="search-wrapper">
                <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                <input 
                    type="text" 
                    id="client-search-input" 
                    class="search-input" 
                    placeholder="Rechercher par nom, raison sociale, prénom ou numéro client"
                    autocomplete="off"
                    aria-label="Recherche de client"
                />
            </div>
        </div>

        <div class="content-area">
            <div id="client-results">
                <!-- Les résultats de recherche s'afficheront ici -->
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('client-search-input');
            const resultsContainer = document.getElementById('client-results');
            let debounceTimer = null;
            let currentController = null;

            if (!searchInput || !resultsContainer) {
                console.error('Éléments de recherche non trouvés');
                return;
            }

            /**
             * Fonction de debounce pour limiter les appels API
             */
            function debounce(func, delay) {
                return function(...args) {
                    clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(() => func.apply(this, args), delay);
                };
            }

            /**
             * Affiche un message de chargement
             */
            function showLoading() {
                resultsContainer.innerHTML = `
                    <div class="loading">
                        <div class="loading-spinner"></div>
                        <p style="margin-top: 0.5rem;">Recherche en cours...</p>
                    </div>
                `;
            }

            /**
             * Affiche un message "aucun résultat"
             */
            function showNoResults() {
                resultsContainer.innerHTML = `
                    <div class="no-results">
                        <svg class="no-results-icon" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                        <p>Aucun client trouvé pour cette recherche.</p>
                    </div>
                `;
            }

            /**
             * Affiche les résultats dans un tableau
             */
            function renderResults(clients) {
                if (!clients || clients.length === 0) {
                    showNoResults();
                    return;
                }

                let html = `
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th>Numéro client</th>
                                <th>Raison sociale</th>
                                <th>Nom</th>
                                <th>Prénom</th>
                                <th>Ville</th>
                                <th>Téléphone</th>
                                <th>Email</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                clients.forEach(client => {
                    html += `
                        <tr>
                            <td>${escapeHtml(client.numero_client || '')}</td>
                            <td>${escapeHtml(client.raison_sociale || '')}</td>
                            <td>${escapeHtml(client.nom_dirigeant || '')}</td>
                            <td>${escapeHtml(client.prenom_dirigeant || '')}</td>
                            <td>${escapeHtml(client.ville || '')}</td>
                            <td>${escapeHtml(client.telephone1 || '')}</td>
                            <td>${escapeHtml(client.email || '')}</td>
                        </tr>
                    `;
                });

                html += `
                        </tbody>
                    </table>
                `;

                resultsContainer.innerHTML = html;
            }

            /**
             * Échappe le HTML pour éviter les injections XSS
             */
            function escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }

            /**
             * Effectue la recherche via l'API
             */
            async function performSearch(searchTerm) {
                // Annuler la requête précédente si elle existe
                if (currentController) {
                    currentController.abort();
                }

                // Si le terme est vide, vider les résultats
                if (!searchTerm || searchTerm.trim().length < 1) {
                    resultsContainer.innerHTML = '';
                    return;
                }

                // Afficher le chargement
                showLoading();

                // Créer un nouveau AbortController pour cette requête
                currentController = new AbortController();

                try {
                    const response = await fetch(`/API/clients_search.php?q=${encodeURIComponent(searchTerm)}`, {
                        method: 'GET',
                        headers: {
                            'Accept': 'application/json'
                        },
                        signal: currentController.signal,
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const data = await response.json();

                    if (!data.ok) {
                        throw new Error(data.error || 'Erreur lors de la recherche');
                    }

                    // Afficher les résultats
                    renderResults(data.clients || []);

                } catch (error) {
                    // Ignorer les erreurs d'annulation
                    if (error.name === 'AbortError') {
                        return;
                    }

                    console.error('Erreur lors de la recherche:', error);
                    resultsContainer.innerHTML = `
                        <div class="no-results">
                            <p style="color: var(--text-danger, #dc2626);">
                                Erreur lors de la recherche. Veuillez réessayer.
                            </p>
                        </div>
                    `;
                } finally {
                    currentController = null;
                }
            }

            // Fonction de recherche avec debounce (300ms)
            const debouncedSearch = debounce((searchTerm) => {
                performSearch(searchTerm);
            }, 300);

            // Écouter les changements dans le champ de recherche
            searchInput.addEventListener('input', (e) => {
                const searchTerm = e.target.value.trim();
                debouncedSearch(searchTerm);
            });

            // Gérer la soumission avec Enter (recherche immédiate)
            searchInput.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    const searchTerm = e.target.value.trim();
                    // Annuler le debounce et rechercher immédiatement
                    clearTimeout(debounceTimer);
                    performSearch(searchTerm);
                }
            });

            // Nettoyer lors du déchargement de la page
            window.addEventListener('beforeunload', () => {
                if (currentController) {
                    currentController.abort();
                }
            });
        });
    </script>
</body>
</html>

