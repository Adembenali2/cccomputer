<?php
// /public/messagerie.php
// Messagerie interne entre utilisateurs avec possibilité de lier à un client, livraison ou SAV

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/historique.php';

function h(?string $s): string {
    return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8');
}

function ensureCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

$CSRF = ensureCsrfToken();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserRole = $_SESSION['emploi'] ?? '';

// Vue active (boite_reception, envoyes, tous)
$view = $_GET['view'] ?? 'boite_reception';
if (!in_array($view, ['boite_reception', 'envoyes', 'tous'], true)) {
    $view = 'boite_reception';
}

// Filtre par type de lien
$filterType = $_GET['filter_type'] ?? '';
$allowedTypes = ['client', 'livraison', 'sav'];
if ($filterType && !in_array($filterType, $allowedTypes, true)) {
    $filterType = '';
}

// Récupérer les utilisateurs pour le sélecteur de destinataire
$utilisateurs = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, nom, prenom, Email, Emploi, statut
        FROM utilisateurs
        WHERE statut = 'actif'
        ORDER BY nom ASC, prenom ASC
    ");
    $stmt->execute();
    $utilisateurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur récupération utilisateurs: ' . $e->getMessage());
}

// Compter les messages non lus (incluant les messages à tous)
$unreadCount = 0;
try {
    // Messages directs non lus
    $stmt1 = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messagerie 
        WHERE id_destinataire = :user_id
          AND lu = 0 
          AND supprime_destinataire = 0
    ");
    $stmt1->execute([':user_id' => $currentUserId]);
    $countDirect = (int)$stmt1->fetchColumn();
    
    // Messages "à tous" non lus
    $countBroadcast = 0;
    try {
        $stmt2 = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messagerie m
            LEFT JOIN messagerie_lectures ml ON ml.id_message = m.id AND ml.id_utilisateur = :user_id
            WHERE m.id_destinataire IS NULL
              AND m.id_expediteur != :user_id2
              AND m.supprime_destinataire = 0
              AND ml.id IS NULL
        ");
        $stmt2->execute([':user_id' => $currentUserId, ':user_id2' => $currentUserId]);
        $countBroadcast = (int)$stmt2->fetchColumn();
    } catch (PDOException $e) {
        // Si la table n'existe pas encore, compter tous les messages à tous
        $stmt2b = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messagerie 
            WHERE id_destinataire IS NULL
              AND id_expediteur != :user_id
              AND supprime_destinataire = 0
        ");
        $stmt2b->execute([':user_id' => $currentUserId]);
        $countBroadcast = (int)$stmt2b->fetchColumn();
    }
    
    $unreadCount = $countDirect + $countBroadcast;
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur comptage non lus: ' . $e->getMessage());
}

// Récupérer les messages selon la vue
$messages = [];
try {
    $where = [];
    $params = [];
    
    if ($view === 'boite_reception') {
        $where[] = "(id_destinataire = :user_id OR (id_destinataire IS NULL AND id_expediteur != :user_id))";
        $where[] = "supprime_destinataire = 0";
        $params[':user_id'] = $currentUserId;
    } elseif ($view === 'envoyes') {
        $where[] = "id_expediteur = :user_id";
        $where[] = "supprime_expediteur = 0";
        $params[':user_id'] = $currentUserId;
    } else { // tous
        $where[] = "(id_expediteur = :user_id OR id_destinataire = :user_id2 OR (id_destinataire IS NULL AND id_expediteur != :user_id3))";
        $where[] = "((id_expediteur = :user_id4 AND supprime_expediteur = 0) OR (id_destinataire = :user_id5 AND supprime_destinataire = 0) OR (id_destinataire IS NULL AND id_expediteur != :user_id6))";
        $params[':user_id'] = $currentUserId;
        $params[':user_id2'] = $currentUserId;
        $params[':user_id3'] = $currentUserId;
        $params[':user_id4'] = $currentUserId;
        $params[':user_id5'] = $currentUserId;
        $params[':user_id6'] = $currentUserId;
    }
    
    if ($filterType) {
        $where[] = "type_lien = :filter_type";
        $params[':filter_type'] = $filterType;
    }
    
    $sql = "
        SELECT 
            m.*,
            exp.nom AS expediteur_nom,
            exp.prenom AS expediteur_prenom,
            exp.Emploi AS expediteur_role,
            dest.nom AS destinataire_nom,
            dest.prenom AS destinataire_prenom,
            dest.Emploi AS destinataire_role,
            c.raison_sociale AS client_nom,
            l.reference AS livraison_ref,
            s.reference AS sav_ref,
            ml.id AS lu_par_moi
        FROM messagerie m
        LEFT JOIN utilisateurs exp ON exp.id = m.id_expediteur
        LEFT JOIN utilisateurs dest ON dest.id = m.id_destinataire
        LEFT JOIN clients c ON c.id = m.id_lien AND m.type_lien = 'client'
        LEFT JOIN livraisons l ON l.id = m.id_lien AND m.type_lien = 'livraison'
        LEFT JOIN sav s ON s.id = m.id_lien AND m.type_lien = 'sav'
        LEFT JOIN messagerie_lectures ml ON ml.id_message = m.id AND ml.id_utilisateur = :current_user_id
    ";
    
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    
    $sql .= ' ORDER BY m.date_envoi DESC LIMIT 200';
    
    $params[':current_user_id'] = $currentUserId;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Pour les messages "à tous", vérifier si lus via la table de lectures
    foreach ($messages as &$msg) {
        if ($msg['id_destinataire'] === null) {
            $msg['lu'] = !empty($msg['lu_par_moi']) ? 1 : 0;
        }
    }
    unset($msg);
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur récupération messages: ' . $e->getMessage());
}

$totalMessages = count($messages);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Messagerie interne - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/maps.css">
    <link rel="stylesheet" href="/assets/css/messagerie.css">
</head>
<body class="page-maps page-messagerie">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Messagerie interne</h1>
        <p class="page-sub">
            Communiquez avec vos collègues et liez vos messages à un client, une livraison ou un SAV.<br>
            <?php if ($unreadCount > 0): ?>
                <strong style="color: #dc2626;"><?= h((string)$unreadCount) ?> message(s) non lu(s)</strong>
            <?php else: ?>
                Aucun message non lu.
            <?php endif; ?>
        </p>
    </header>

    <section class="maps-layout">
        <!-- PANNEAU GAUCHE : NOUVEAU MESSAGE / FILTRES -->
        <aside class="maps-panel" aria-label="Panneau de messagerie">
            <h2>Nouveau message</h2>
            <small>Envoyez un message à un collègue ou à tous les utilisateurs.</small>

            <form id="newMessageForm" class="messagerie-form">
                <input type="hidden" name="csrf_token" value="<?= h($CSRF) ?>">
                
                <div class="section-title">1. Destinataire</div>
                <select name="id_destinataire" id="selectDestinataire" class="messagerie-select">
                    <option value="">📢 Tous les utilisateurs</option>
                    <?php foreach ($utilisateurs as $u): ?>
                        <?php if ((int)$u['id'] !== $currentUserId): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= h($u['prenom'] . ' ' . $u['nom']) ?> (<?= h($u['Emploi']) ?>)
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>

                <div class="section-title">2. Lier à (optionnel)</div>
                <select name="type_lien" id="selectTypeLien" class="messagerie-select">
                    <option value="">— Aucun lien —</option>
                    <option value="client">👤 Client</option>
                    <option value="livraison">📦 Livraison</option>
                    <option value="sav">🔧 SAV</option>
                </select>

                <div id="lienContainer" style="display:none; margin-top: 0.5rem;">
                    <input type="text" 
                           id="lienSearch" 
                           class="client-search-input" 
                           placeholder="Tapez pour rechercher..." 
                           autocomplete="off">
                    <div id="lienResults" class="client-results" style="display:none;"></div>
                    <input type="hidden" name="id_lien" id="idLien" value="">
                    <div id="lienSelected" class="lien-selected" style="display:none; margin-top: 0.5rem;"></div>
                </div>

                <div class="section-title">3. Sujet</div>
                <input type="text" 
                       name="sujet" 
                       id="inputSujet" 
                       class="messagerie-input" 
                       placeholder="Sujet du message..." 
                       required 
                       maxlength="255">

                <div class="section-title">4. Message</div>
                <textarea name="message" 
                          id="textareaMessage" 
                          class="messagerie-textarea" 
                          rows="6" 
                          placeholder="Votre message..." 
                          required></textarea>

                <div class="btn-group" style="margin-top: 0.75rem;">
                    <button type="submit" class="primary">📤 Envoyer</button>
                    <button type="reset" class="secondary">Effacer</button>
                </div>

                <div id="messageStatus" class="maps-message" style="display:none; margin-top: 0.5rem;"></div>
            </form>

            <div style="margin-top: 1.5rem; padding-top: 1rem; border-top: 1px solid var(--border-color);">
                <div class="section-title">Filtres</div>
                <div class="btn-group" style="flex-direction: column; gap: 0.4rem;">
                    <a href="/public/messagerie.php?view=boite_reception" 
                       class="btn <?= $view === 'boite_reception' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📥 Boîte de réception<?= $unreadCount > 0 ? ' (' . $unreadCount . ')' : '' ?>
                    </a>
                    <a href="/public/messagerie.php?view=envoyes" 
                       class="btn <?= $view === 'envoyes' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📤 Messages envoyés
                    </a>
                    <a href="/public/messagerie.php?view=tous" 
                       class="btn <?= $view === 'tous' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none;">
                        📋 Tous les messages
                    </a>
                </div>

                <?php if ($view !== 'envoyes'): ?>
                <div class="section-title" style="margin-top: 0.75rem;">Filtrer par type</div>
                <div class="btn-group" style="flex-direction: column; gap: 0.3rem;">
                    <a href="/public/messagerie.php?view=<?= h($view) ?>" 
                       class="btn <?= !$filterType ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none; font-size: 0.85rem;">
                        Tous les types
                    </a>
                    <a href="/public/messagerie.php?view=<?= h($view) ?>&filter_type=client" 
                       class="btn <?= $filterType === 'client' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none; font-size: 0.85rem;">
                        👤 Clients
                    </a>
                    <a href="/public/messagerie.php?view=<?= h($view) ?>&filter_type=livraison" 
                       class="btn <?= $filterType === 'livraison' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none; font-size: 0.85rem;">
                        📦 Livraisons
                    </a>
                    <a href="/public/messagerie.php?view=<?= h($view) ?>&filter_type=sav" 
                       class="btn <?= $filterType === 'sav' ? 'primary' : 'secondary' ?>"
                       style="width: 100%; text-align: center; text-decoration: none; font-size: 0.85rem;">
                        🔧 SAV
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </aside>

        <!-- PANNEAU DROIT : LISTE DES MESSAGES -->
        <section class="map-wrapper">
            <div class="map-toolbar">
                <div class="map-toolbar-left">
                    <strong>Messages</strong> – <?= h((string)$totalMessages) ?> message(s)
                </div>
                <div class="map-toolbar-right">
                    <span class="badge">Vue : <?= $view === 'boite_reception' ? 'Réception' : ($view === 'envoyes' ? 'Envoyés' : 'Tous') ?></span>
                    <?php if ($filterType): ?>
                        <span class="badge">Filtre : <?= h(ucfirst($filterType)) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="messages-container">
                <?php if (empty($messages)): ?>
                    <div class="messages-empty">
                        <p>Aucun message à afficher.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                        <?php
                        $isFromMe = (int)$msg['id_expediteur'] === $currentUserId;
                        $isToMe = ($msg['id_destinataire'] === null || (int)$msg['id_destinataire'] === $currentUserId);
                        $isUnread = !$msg['lu'] && $isToMe && !$isFromMe;
                        $expediteurNom = trim(($msg['expediteur_prenom'] ?? '') . ' ' . ($msg['expediteur_nom'] ?? ''));
                        $destinataireNom = $msg['id_destinataire'] 
                            ? trim(($msg['destinataire_prenom'] ?? '') . ' ' . ($msg['destinataire_nom'] ?? ''))
                            : 'Tous les utilisateurs';
                        
                        $lienLabel = '';
                        $lienUrl = '';
                        if ($msg['type_lien'] && $msg['id_lien']) {
                            if ($msg['type_lien'] === 'client') {
                                $lienLabel = '👤 Client: ' . h($msg['client_nom'] ?? 'N/A');
                                $lienUrl = '/public/client_fiche.php?id=' . (int)$msg['id_lien'];
                            } elseif ($msg['type_lien'] === 'livraison') {
                                $lienLabel = '📦 Livraison: ' . h($msg['livraison_ref'] ?? 'N/A');
                                $lienUrl = '/public/livraison.php?ref=' . urlencode($msg['livraison_ref'] ?? '');
                            } elseif ($msg['type_lien'] === 'sav') {
                                $lienLabel = '🔧 SAV: ' . h($msg['sav_ref'] ?? 'N/A');
                                $lienUrl = '/public/sav.php?ref=' . urlencode($msg['sav_ref'] ?? '');
                            }
                        }
                        
                        $dateEnvoi = $msg['date_envoi'] ?? '';
                        $dateFormatted = $dateEnvoi ? date('d/m/Y à H:i', strtotime($dateEnvoi)) : '';
                        ?>
                        <div class="message-item <?= $isUnread ? 'unread' : '' ?>" data-message-id="<?= (int)$msg['id'] ?>">
                            <div class="message-header">
                                <div class="message-from">
                                    <strong><?= h($expediteurNom) ?></strong>
                                    <span class="message-role"><?= h($msg['expediteur_role'] ?? '') ?></span>
                                    <?php if ($isUnread): ?>
                                        <span class="message-badge-unread">Nouveau</span>
                                    <?php endif; ?>
                                </div>
                                <div class="message-meta">
                                    <span class="message-date"><?= h($dateFormatted) ?></span>
                                    <?php if ($isToMe && !$msg['lu'] && !$isFromMe): ?>
                                        <button type="button" 
                                                class="btn-mark-read" 
                                                data-id="<?= (int)$msg['id'] ?>"
                                                title="Marquer comme lu">
                                            ✓ Lu
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!$isFromMe): ?>
                            <div class="message-to">
                                À : <strong><?= h($destinataireNom) ?></strong>
                            </div>
                            <?php else: ?>
                            <div class="message-to">
                                À : <strong><?= h($destinataireNom) ?></strong>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($lienLabel): ?>
                                <div class="message-lien">
                                    <a href="<?= h($lienUrl) ?>" target="_blank"><?= $lienLabel ?></a>
                                </div>
                            <?php endif; ?>
                            
                            <div class="message-subject">
                                <strong><?= h($msg['sujet']) ?></strong>
                            </div>
                            
                            <div class="message-body">
                                <?= nl2br(h($msg['message'])) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </section>
</main>

<script>
// Configuration
const currentUserId = <?= $currentUserId ?>;
const csrfToken = <?= json_encode($CSRF) ?>;

// Éléments DOM
const form = document.getElementById('newMessageForm');
const selectTypeLien = document.getElementById('selectTypeLien');
const lienContainer = document.getElementById('lienContainer');
const lienSearch = document.getElementById('lienSearch');
const lienResults = document.getElementById('lienResults');
const lienSelected = document.getElementById('lienSelected');
const idLienInput = document.getElementById('idLien');
const messageStatus = document.getElementById('messageStatus');

let searchTimeout = null;

// Afficher/masquer le champ de recherche selon le type de lien
if (selectTypeLien && lienContainer && lienSearch) {
    selectTypeLien.addEventListener('change', () => {
        const type = selectTypeLien.value;
        if (type) {
            lienContainer.style.display = 'block';
            lienSearch.placeholder = type === 'client' ? 'Tapez le nom, prénom du dirigeant ou raison sociale...' 
                                    : type === 'livraison' ? 'Rechercher une livraison...'
                                    : 'Rechercher un SAV...';
            // Réinitialiser les valeurs
            if (lienSearch) lienSearch.value = '';
            if (idLienInput) idLienInput.value = '';
            if (lienSelected) {
                lienSelected.style.display = 'none';
                lienSelected.innerHTML = '';
            }
            if (lienResults) {
                lienResults.style.display = 'none';
                lienResults.innerHTML = '';
            }
            // Si c'est un client, charger les 3 premiers au focus
            if (type === 'client') {
                // Attendre un peu pour que le champ soit visible
                setTimeout(() => {
                    if (lienSearch) lienSearch.focus();
                }, 100);
            }
        } else {
            lienContainer.style.display = 'none';
            if (idLienInput) idLienInput.value = '';
            if (lienSelected) lienSelected.style.display = 'none';
            if (lienSearch) lienSearch.value = '';
            if (lienResults) {
                lienResults.style.display = 'none';
                lienResults.innerHTML = '';
            }
        }
    });
}

// Fonction pour afficher les résultats de recherche
function displaySearchResults(results, type) {
    if (!lienResults) return;
    
    lienResults.innerHTML = '';
    
    if (!results || results.length === 0) {
        const item = document.createElement('div');
        item.className = 'client-result-item empty';
        item.textContent = 'Aucun résultat trouvé.';
        lienResults.appendChild(item);
        return;
    }
    
    results.forEach(item => {
        const div = document.createElement('div');
        div.className = 'client-result-item';
        
        if (type === 'client') {
            // Affichage amélioré pour les clients : raison sociale + dirigeant + adresse
            let html = `<strong>${escapeHtml(item.name || 'N/A')}</strong>`;
            if (item.dirigeant) {
                html += `<br><span style="color: var(--text-secondary); font-size: 0.85rem;">👤 ${escapeHtml(item.dirigeant)}</span>`;
            }
            if (item.address) {
                html += `<br><span style="color: var(--text-muted); font-size: 0.8rem;">📍 ${escapeHtml(item.address)}</span>`;
            }
            div.innerHTML = html;
        } else {
            // Pour livraisons et SAV, affichage simple
            div.innerHTML = `<strong>${escapeHtml(item.label || item.name || item.reference || '')}</strong>`;
        }
        
        div.addEventListener('click', () => {
            if (type === 'client') {
                const displayText = item.dirigeant 
                    ? `${item.name} (${item.dirigeant})`
                    : item.name;
                if (idLienInput) idLienInput.value = item.id;
                if (lienSelected) {
                    lienSelected.innerHTML = `<strong>${escapeHtml(displayText)}</strong> <button type="button" onclick="clearLien()" style="margin-left: 0.5rem; padding: 0.2rem 0.4rem; font-size: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-tertiary); cursor: pointer;">✕</button>`;
                    lienSelected.style.display = 'block';
                }
            } else {
                if (idLienInput) idLienInput.value = item.id;
                if (lienSelected) {
                    lienSelected.innerHTML = `<strong>${escapeHtml(item.label || item.name || item.reference || '')}</strong> <button type="button" onclick="clearLien()" style="margin-left: 0.5rem; padding: 0.2rem 0.4rem; font-size: 0.75rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: var(--bg-tertiary); cursor: pointer;">✕</button>`;
                    lienSelected.style.display = 'block';
                }
            }
            if (lienSearch) lienSearch.value = '';
            if (lienResults) lienResults.style.display = 'none';
        });
        lienResults.appendChild(div);
    });
}

// Fonction pour charger les premiers clients (par défaut)
async function loadFirstClients() {
    if (!lienResults || !selectTypeLien || selectTypeLien.value !== 'client') return;
    
    try {
        const response = await fetch('/API/messagerie_get_first_clients.php?limit=3');
        const data = await response.json();
        
        if (data.ok && data.clients) {
            const results = data.clients.map(c => ({
                id: c.id,
                name: c.name,
                dirigeant: c.dirigeant_complet || (c.prenom_dirigeant && c.nom_dirigeant ? `${c.prenom_dirigeant} ${c.nom_dirigeant}` : null),
                address: c.address,
                code: c.code
            }));
            displaySearchResults(results, 'client');
            if (lienResults) lienResults.style.display = 'block';
        }
    } catch (err) {
        console.error('Erreur chargement premiers clients:', err);
    }
}

// Recherche de client/livraison/SAV
if (lienSearch) {
    // Au focus, charger les 3 premiers clients si type = client
    lienSearch.addEventListener('focus', () => {
        const type = selectTypeLien ? selectTypeLien.value : '';
        if (type === 'client' && (!lienSearch.value || lienSearch.value.trim().length === 0)) {
            loadFirstClients();
        }
    });
    
    lienSearch.addEventListener('input', () => {
        const query = lienSearch.value.trim();
        const type = selectTypeLien ? selectTypeLien.value : '';
        
        clearTimeout(searchTimeout);
        
        if (!type) {
            if (lienResults) {
                lienResults.innerHTML = '';
                lienResults.style.display = 'none';
            }
            return;
        }
        
        // Si le champ est vide, afficher les 3 premiers clients
        if (!query || query.length === 0) {
            if (type === 'client') {
                loadFirstClients();
            } else {
                if (lienResults) {
                    lienResults.innerHTML = '';
                    lienResults.style.display = 'none';
                }
            }
            return;
        }
        
        if (lienResults) {
            lienResults.innerHTML = '<div class="client-result-item loading">Recherche en cours…</div>';
            lienResults.style.display = 'block';
        }
        
        // Debounce réduit à 200ms pour une recherche plus réactive
        searchTimeout = setTimeout(async () => {
            try {
                let url = '';
                if (type === 'client') {
                    url = `/API/maps_search_clients.php?q=${encodeURIComponent(query)}&limit=15`;
                } else if (type === 'livraison') {
                    url = `/API/messagerie_search_livraisons.php?q=${encodeURIComponent(query)}&limit=15`;
                } else if (type === 'sav') {
                    url = `/API/messagerie_search_sav.php?q=${encodeURIComponent(query)}&limit=15`;
                }
                
                const response = await fetch(url);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!lienResults) return;
                
                let results = [];
                if (type === 'client' && data.ok && data.clients) {
                    results = data.clients.map(c => ({
                        id: c.id,
                        name: c.name,
                        dirigeant: c.dirigeant_complet || (c.prenom_dirigeant && c.nom_dirigeant ? `${c.prenom_dirigeant} ${c.nom_dirigeant}` : null),
                        address: c.address,
                        code: c.code
                    }));
                } else if ((type === 'livraison' || type === 'sav') && data.ok && data.results) {
                    results = data.results;
                }
                
                displaySearchResults(results, type);
            } catch (err) {
                console.error('Erreur recherche:', err);
                if (lienResults) {
                    lienResults.innerHTML = '<div class="client-result-item empty">Erreur de recherche: ' + escapeHtml(err.message) + '</div>';
                }
            }
        }, 200);
    });
}

function clearLien() {
    idLienInput.value = '';
    lienSelected.style.display = 'none';
    selectTypeLien.value = '';
    lienContainer.style.display = 'none';
}

// Fermer les résultats au clic extérieur
document.addEventListener('click', (e) => {
    if (!lienContainer.contains(e.target)) {
        lienResults.style.display = 'none';
    }
});

// Envoi du message
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(form);
    const data = {
        csrf_token: formData.get('csrf_token'),
        id_destinataire: formData.get('id_destinataire') || null,
        type_lien: formData.get('type_lien') || null,
        id_lien: formData.get('id_lien') || null,
        sujet: formData.get('sujet'),
        message: formData.get('message')
    };
    
    messageStatus.style.display = 'block';
    messageStatus.textContent = 'Envoi en cours…';
    messageStatus.className = 'maps-message hint';
    
    try {
        const response = await fetch('/API/messagerie_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.ok) {
            messageStatus.textContent = 'Message envoyé avec succès !';
            messageStatus.className = 'maps-message success';
            form.reset();
            clearLien();
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            messageStatus.textContent = 'Erreur : ' + (result.error || 'Erreur inconnue');
            messageStatus.className = 'maps-message alert';
        }
    } catch (err) {
        console.error('Erreur envoi:', err);
        messageStatus.textContent = 'Erreur lors de l\'envoi du message.';
        messageStatus.className = 'maps-message alert';
    }
});

// Marquer comme lu
document.querySelectorAll('.btn-mark-read').forEach(btn => {
    btn.addEventListener('click', async () => {
        const messageId = btn.getAttribute('data-id');
        
        try {
            const response = await fetch('/API/messagerie_mark_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    message_id: messageId
                })
            });
            
            const result = await response.json();
            if (result.ok) {
                btn.closest('.message-item').classList.remove('unread');
                btn.remove();
                // Mettre à jour le compteur dans le header si présent
                const badge = document.querySelector('.messagerie-badge');
                if (badge) {
                    const current = parseInt(badge.textContent) || 0;
                    if (current > 0) {
                        badge.textContent = current - 1;
                        if (current - 1 === 0) {
                            badge.style.display = 'none';
                        }
                    }
                }
            }
        } catch (err) {
            console.error('Erreur marquer lu:', err);
        }
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>

