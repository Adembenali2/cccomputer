<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Paiement - CC Computer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <style>
        .paiement-page {
            max-width: 800px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .paiement-header {
            margin-bottom: 2rem;
        }

        .paiement-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }

        .paiement-card {
            background: var(--bg-primary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-md);
        }

        .paiement-card-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }

        .paiement-form {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .paiement-form-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .paiement-form-group label {
            font-weight: 600;
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .paiement-form-group input,
        .paiement-form-group select,
        .paiement-form-group textarea {
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 1rem;
            color: var(--text-primary);
            background-color: var(--bg-secondary);
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            width: 100%;
        }

        .paiement-form-group input:focus,
        .paiement-form-group select:focus,
        .paiement-form-group textarea:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }

        .paiement-form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .paiement-form-actions {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
        }

        .btn-payer {
            background-color: var(--accent-primary);
            color: #fff;
            border: none;
            border-radius: var(--radius-md);
            padding: 0.875rem 2rem;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: background-color 0.3s ease;
            flex: 1;
        }

        .btn-payer:hover {
            background-color: var(--accent-secondary);
        }

        .btn-payer:disabled {
            background-color: var(--text-muted);
            cursor: not-allowed;
        }

        .message {
            padding: 1rem;
            border-radius: var(--radius-md);
            margin-bottom: 1.5rem;
            display: none;
        }

        .message.success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }

        .message.error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }

        .message.show {
            display: block;
        }

        @media (max-width: 768px) {
            .paiement-page {
                margin: 1rem auto;
                padding: 0 0.5rem;
            }

            .paiement-card {
                padding: 1.5rem;
            }

            .paiement-header h1 {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <?php
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/auth_role.php';
    authorize_page('paiements', []); // Accessible à tous les utilisateurs connectés
    require_once __DIR__ . '/../source/templates/header.php';
    ?>

    <div class="paiement-page">
        <div class="paiement-header">
            <h1>Paiement</h1>
        </div>

        <div id="messageContainer"></div>

        <div class="paiement-card">
            <h2 class="paiement-card-title">Facturation</h2>
            
            <form id="paiementForm" class="paiement-form">
                <div class="paiement-form-group">
                    <label for="nom">Nom *</label>
                    <input type="text" id="nom" name="nom" required placeholder="Nom du client">
                </div>

                <div class="paiement-form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required placeholder="email@exemple.com">
                </div>

                <div class="paiement-form-group">
                    <label for="reference">Référence client</label>
                    <input type="text" id="reference" name="reference" placeholder="Référence optionnelle">
                </div>

                <div class="paiement-form-group">
                    <label for="montant">Montant (€)</label>
                    <input type="number" id="montant" name="montant" step="0.01" min="0" placeholder="0.00">
                </div>

                <div class="paiement-form-group">
                    <label for="commentaire">Commentaire</label>
                    <textarea id="commentaire" name="commentaire" placeholder="Commentaire optionnel"></textarea>
                </div>

                <div class="paiement-form-actions">
                    <button type="submit" class="btn-payer" id="btnPayer">Payer</button>
                </div>
            </form>
        </div>
    </div>

    <script>
            document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('paiementForm');
            const messageContainer = document.getElementById('messageContainer');
            const btnPayer = document.getElementById('btnPayer');

            function showMessage(text, type) {
                messageContainer.innerHTML = `<div class="message ${type} show">${text}</div>`;
                setTimeout(() => {
                    const message = messageContainer.querySelector('.message');
                    if (message) {
                        message.classList.remove('show');
                        setTimeout(() => {
                            messageContainer.innerHTML = '';
                        }, 300);
                    }
                }, 5000);
            }

            function validateForm() {
                const nom = document.getElementById('nom').value.trim();
                const email = document.getElementById('email').value.trim();
                
                if (!nom) {
                    showMessage('Le nom est requis.', 'error');
                    return false;
                }
                
                if (!email) {
                    showMessage('L\'email est requis.', 'error');
                    return false;
                }
                
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    showMessage('Veuillez entrer un email valide.', 'error');
                    return false;
                }
                
                return true;
            }

            form.addEventListener('submit', function(e) {
            e.preventDefault();
                
                if (!validateForm()) {
                    return;
                }

                // Désactiver le bouton pendant le traitement
                btnPayer.disabled = true;
                btnPayer.textContent = 'Traitement...';

                // Simulation d'un traitement (à remplacer par un appel API réel si nécessaire)
                setTimeout(() => {
                    showMessage('Paiement enregistré avec succès !', 'success');
                    form.reset();
                    btnPayer.disabled = false;
                    btnPayer.textContent = 'Payer';
                }, 1500);
            });
        });
    </script>
</body>
</html>


