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

        /* Main content area (empty for now) */
        .content-area {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-sm);
            min-height: 400px;
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
                    id="clientSearch" 
                    class="search-input" 
                    placeholder="Rechercher par nom, raison sociale, prénom ou numéro client"
                    autocomplete="off"
                    aria-label="Recherche de client"
                />
            </div>
        </div>

        <div class="content-area">
            <!-- Contenu à ajouter ultérieurement -->
        </div>
    </div>

    <script>
        // Placeholder pour la logique de recherche (à implémenter plus tard)
        document.addEventListener('DOMContentLoaded', () => {
            const searchInput = document.getElementById('clientSearch');
            
            if (searchInput) {
                // Écouter les changements dans le champ de recherche
                searchInput.addEventListener('input', (e) => {
                    const searchTerm = e.target.value.trim();
                    // Logique de recherche à implémenter plus tard
                    console.log('Recherche:', searchTerm);
                });

                // Gérer la soumission avec Enter (optionnel)
                searchInput.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        const searchTerm = e.target.value.trim();
                        // Logique de recherche à implémenter plus tard
                        console.log('Recherche (Enter):', searchTerm);
                    }
                });
            }
        });
    </script>
</body>
</html>

