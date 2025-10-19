<?php
// /public/dashboard.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// ==== Imports SFTP désactivés ====
$import_status = 'ok'; // 'ok' ou 'off'

// ==================================================================
// Historique des actions (requêtes SQL réelles)
// ==================================================================
// 1. Nombre total d'historiques
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM historique");
    $nHistorique = (string)$stmt->fetchColumn();
} catch (PDOException $e) {
    error_log("Erreur de requête SQL (nHistorique) : " . $e->getMessage());
    $nHistorique = 'Erreur';
}

// 2. Historique par jour (si tu l’utilises côté JS/graph)
try {
    $sql = "SELECT DATE(date_action) AS date, COUNT(*) AS total_historique
            FROM historique
            GROUP BY DATE(date_action)
            ORDER BY date DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $historique_par_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erreur de requête SQL (historique_par_jour) : " . $e->getMessage());
    $historique_par_jour = [];
}

// Compteurs “dummy”
$nb_paiements_en_attente = 3;
$nb_sav_a_traiter        = 5;
$nb_livraisons_a_faire   = 8;

// Classe de couleur pour le compteur Paiements
$payClass = ($nb_paiements_en_attente > 0) ? 'count-bad' : 'count-ok';

// Récupération de tous les clients (liste statique)
$clients = [
    [
        'id' => 1,
        'raison_sociale'   => 'Tech Solutions SARL',
        'nom_dirigeant'    => 'Dupont',
        'prenom_dirigeant' => 'Jean',
        'numero_client'    => 'C001',
        'email'            => 'jean.dupont@techsarl.com'
    ],
    [
        'id' => 2,
        'raison_sociale'   => 'Innovate & Co.',
        'nom_dirigeant'    => 'Martin',
        'prenom_dirigeant' => 'Marie',
        'numero_client'    => 'C002',
        'email'            => 'marie.martin@innovate.co'
    ],
    [
        'id' => 3,
        'raison_sociale'   => 'Digital Création',
        'nom_dirigeant'    => 'Bernard',
        'prenom_dirigeant' => 'Luc',
        'numero_client'    => 'C003',
        'email'            => 'luc.bernard@digital-creation.fr'
    ],
    [
        'id' => 4,
        'raison_sociale'   => 'Le Grand Fournil',
        'nom_dirigeant'    => 'Petit',
        'prenom_dirigeant' => 'Sophie',
        'numero_client'    => 'C004',
        'email'            => 'sophie.petit@fournil.com'
    ]
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Dashboard - CCComputer</title>
    <!-- Chemins absolus pour éviter les surprises -->
    <link rel="stylesheet" href="/assets/css/dashboard.css" />
    <script src="/assets/js/dashboard.js" defer></script>
    <style>
        /* Notification d’import (maintenue ici car liée au PHP) */
        #importNotif {
            position: fixed;
            top: 18px;
            left: 50%;
            transform: translateX(-50%);
            background: #fff;
            color: #097c34;
            border-radius: 22px;
            box-shadow: 0 2px 12px #0002;
            font-size: 15px;
            padding: 8px 24px 8px 16px;
            z-index: 5000;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: opacity 0.4s;
            opacity: 0.98;
        }
        #importNotif .notif-icon { display:inline-flex; }
        #importNotif.error { color: #b90303; }
        #importNotif.error svg circle { fill: #e74c3c !important; }

        /* Couleurs du compteur Paiements */
        .card-count.count-bad { color: #dc2626; font-weight: 700; } /* rouge si impayés */
        .card-count.count-ok  { color: #16a34a; font-weight: 700; } /* vert si 0 impayé */
    </style>
</head>
<body class="page-dashboard">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <!-- Notification import -->
    <div
        id="importNotif"
        class="<?= $import_status === 'ok' ? '' : 'error' ?>"
        style="display:none;"
        role="status"
        aria-live="polite"
    >
        <span class="notif-icon" aria-hidden="true">
            <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="vertical-align:middle">
                <circle cx="10" cy="10" r="10" fill="<?= $import_status === 'ok' ? '#1de379' : '#e74c3c' ?>" />
                <path d="M6 10.5L9 13L14 8" stroke="#fff" stroke-width="2" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="notif-text">
            Import désactivé
        </span>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var notif = document.getElementById('importNotif');
        if (notif) {
            notif.style.display = 'flex';
            setTimeout(function() { notif.style.opacity = '0'; }, 3800);
            setTimeout(function() { notif.style.display = 'none'; }, 4400);
        }
    });
    </script>

    <div class="dashboard-wrapper">
        <div class="dashboard-header">
            <h2 class="dashboard-title">Tableau de Bord</h2>
        </div>

        <div class="dashboard-grid">
            <!-- Paiements -->
            <div class="dash-card" data-href="paiements.php" tabindex="0" role="button" aria-label="Voir les paiements en attente">
                <div class="card-icon payments" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
                        <line x1="1" y1="10" x2="23" y2="10"/>
                    </svg>
                </div>
                <h3 class="card-title">Paiements</h3>
                <p class="card-count <?= htmlspecialchars($payClass, ENT_QUOTES, 'UTF-8') ?>"><?= (int)$nb_paiements_en_attente ?></p>
            </div>

            <!-- SAV -->
            <div class="dash-card" data-href="sav.php" tabindex="0" role="button" aria-label="Accéder au SAV">
                <div class="card-icon sav" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
                <h3 class="card-title">SAV</h3>
                <p class="card-count"><?= (int)$nb_sav_a_traiter ?></p>
            </div>

            <!-- Livraisons -->
            <div class="dash-card" data-href="livraison.php" tabindex="0" role="button" aria-label="Accéder aux livraisons">
                <div class="card-icon deliveries" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2">
                        <rect x="1" y="3" width="15" height="13"/>
                        <polygon points="16,8 20,8 23,11 23,16 16,16 16,8"/>
                        <circle cx="5.5" cy="18.5" r="2.5"/>
                        <circle cx="18.5" cy="18.5" r="2.5"/>
                    </svg>
                </div>
                <h3 class="card-title">Livraisons</h3>
                <p class="card-count"><?= (int)$nb_livraisons_a_faire ?></p>
            </div>

            <!-- Clients -->
            <div class="dash-card" data-href="clients.php" tabindex="0" role="button" aria-label="Accéder aux clients">
                <div class="card-icon clients" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#8b5cf6" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h3 class="card-title">Clients</h3>
                <p class="card-count"><?= count($clients) ?></p>
            </div>

            <!-- Stock -->
            <div class="dash-card" data-href="stock.php" tabindex="0" role="button" aria-label="Accéder au stock">
                <div class="card-icon stock" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ec4899" stroke-width="2">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27,6.96 12,12.01 20.73,6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <h3 class="card-title">Stock</h3>
                <div class="card-multi-count" aria-label="Indicateurs stock">
                    <div class="count-item" title="Catégories">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                            <line x1="8" y1="21" x2="16" y2="21"/>
                            <line x1="12" y1="17" x2="12" y2="21"/>
                        </svg>
                        <span>3</span>
                    </div>
                    <div class="count-item" title="Références actives">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="12" cy="12" r="10"/>
                            <circle cx="12" cy="12" r="6"/>
                            <circle cx="12" cy="12" r="2"/>
                        </svg>
                        <span>124</span>
                    </div>
                    <div class="count-item" title="Alertes stock">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14,2 14,8 20,8"/>
                            <line x1="16" y1="13" x2="8" y2="13"/>
                            <line x1="16" y1="17" x2="8" y2="17"/>
                            <polyline points="10,9 9,9 8,9"/>
                        </svg>
                        <span>89</span>
                    </div>
                </div>
            </div>

            <!-- Historiques -->
            <div class="dash-card" data-href="historique.php" tabindex="0" role="button" aria-label="Accéder aux historiques">
                <div class="card-icon history" aria-hidden="true">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12,6 12,12 16,14"/>
                    </svg>
                </div>
                <h3 class="card-title">Historiques</h3>
                <p class="card-count"><?= htmlspecialchars($nHistorique, ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        </div>
    </div>

    <!-- Bouton Support -->
    <a href="#" class="support-btn" id="supportButton" aria-label="Support client">
        <span class="support-badge"><?= count($clients) ?></span>
        <svg width="32" height="32" viewBox="0 0 24 24"
            fill="none" stroke="currentColor" stroke-width="2.4"
            stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <circle cx="12" cy="8" r="5" fill="currentColor" fill-opacity="0.22"/>
        <path d="M3.5 10a8.5 8.5 0 0 1 17 0"/>
        <rect x="1.5" y="9" width="5" height="8" rx="2.5" ry="2.5"/>
        <rect x="17.5" y="9" width="5" height="8" rx="2.5" ry="2.5"/>
        <path d="M18.5 16.5a3.5 3.5 0 0 1-3.5 3.5H11"/>
        <circle cx="10.5" cy="20" r="0.9" fill="currentColor"/>
        <path d="M4 22a8 4 0 0 1 16 0"/>
        </svg>
    </a>

    <!-- Popup Support -->
    <div class="popup-overlay" id="supportOverlay"></div>
    <div class="support-popup" id="supportPopup" role="dialog" aria-modal="true" aria-labelledby="popupTitle">
        <div class="popup-header">
            <h3 class="popup-title" id="popupTitle">Liste des Clients</h3>
            <button class="close-btn" id="closePopup" aria-label="Fermer la fenêtre">&times;</button>
        </div>
        <div class="popup-content">
            <input
                type="text"
                id="clientSearchInput"
                class="client-search-bar"
                placeholder="Filtrer par nom, raison sociale, prénom ou numéro client…"
                autocomplete="off"
                aria-label="Rechercher un client"
            >
            <div class="clients-list" id="clientsList">
                <?php foreach ($clients as $client): ?>
                    <?php
                        $cId     = (int)($client['id'] ?? 0);
                        $raison  = htmlspecialchars($client['raison_sociale']   ?? '', ENT_QUOTES, 'UTF-8');
                        $nom     = htmlspecialchars($client['nom_dirigeant']    ?? '', ENT_QUOTES, 'UTF-8');
                        $prenom  = htmlspecialchars($client['prenom_dirigeant'] ?? '', ENT_QUOTES, 'UTF-8');
                        $numero  = htmlspecialchars($client['numero_client']    ?? '', ENT_QUOTES, 'UTF-8');
                        $email   = htmlspecialchars($client['email']            ?? '', ENT_QUOTES, 'UTF-8');

                        // Pour les data-* utilisés par la recherche côté front (en minuscules)
                        $dNom    = htmlspecialchars(strtolower($client['nom_dirigeant']    ?? ''), ENT_QUOTES, 'UTF-8');
                        $dPrenom = htmlspecialchars(strtolower($client['prenom_dirigeant'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $dRaison = htmlspecialchars(strtolower($client['raison_sociale']   ?? ''), ENT_QUOTES, 'UTF-8');
                        $dNum    = htmlspecialchars(strtolower($client['numero_client']    ?? ''), ENT_QUOTES, 'UTF-8');
                    ?>
                    <a href="#"
                       class="client-card"
                       data-client-id="<?= $cId ?>"
                       data-nom="<?= $dNom ?>"
                       data-prenom="<?= $dPrenom ?>"
                       data-raison="<?= $dRaison ?>"
                       data-numero="<?= $dNum ?>"
                       aria-label="Ouvrir la fiche du client <?= $raison ?>">
                        <div class="client-info">
                            <strong><?= $raison ?></strong>
                            <span><?= $nom ?> <?= $prenom ?></span>
                            <span><?= $numero ?></span>
                            <span><?= $email ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</body>
</html>
