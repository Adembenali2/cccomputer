<?php
// /public/messagerie.php
// Chatroom Globale - Interface moderne avec mentions et liens
// Tous les utilisateurs connectés peuvent discuter ensemble en temps réel

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('messagerie', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$CSRF = ensureCsrfToken();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserNom = $_SESSION['user_nom'] ?? '';
$currentUserPrenom = $_SESSION['user_prenom'] ?? '';
$currentUserEmploi = $_SESSION['emploi'] ?? '';

// Récupérer les informations de l'utilisateur pour l'affichage
$currentUserName = trim($currentUserPrenom . ' ' . $currentUserNom);

// Vérifier si la table existe
$tableExists = false;
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'chatroom_messages'");
    $tableExists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur vérification table: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Chatroom Globale - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/chatroom.css">
</head>
<body class="page-maps page-chatroom">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">💬 Chatroom Globale</h1>
        <p class="page-sub">
            Discutez en temps réel avec tous vos collègues connectés. Mentionnez des utilisateurs avec @nom ou liez des clients/SAVs/livraisons.
        </p>
    </header>

    <?php if (!$tableExists): ?>
        <div class="maps-message alert" style="margin: 1rem 0;">
            <strong>⚠️ Attention :</strong> La table <code>chatroom_messages</code> n'existe pas encore.
            Veuillez exécuter la migration SQL : <code>sql/migration_create_chatroom_messages.sql</code>
        </div>
    <?php endif; ?>

    <div class="chatroom-container">
        <!-- Header de la chatroom -->
        <div class="chatroom-header">
            <div>
                <h2>💬 Chatroom Globale</h2>
                <div class="chatroom-status">
                    <span class="chatroom-status-indicator"></span>
                    <span>En ligne</span>
                </div>
            </div>
        </div>

        <!-- Zone de messages (scrollable) -->
        <div class="chatroom-messages" id="chatroomMessages">
            <div class="chatroom-loading" id="loadingIndicator">
                Chargement des messages...
            </div>
        </div>

        <!-- Sélecteur de liens (clients/SAVs/livraisons) -->
        <div class="chatroom-links-selector" id="linksSelector" style="display:none;">
            <div class="section-title">Lier à (optionnel)</div>
            <select id="linkTypeSelect" class="chatroom-link-type-select">
                <option value="">— Aucun lien —</option>
                <option value="client">👤 Client</option>
                <option value="livraison">📦 Livraison</option>
                <option value="sav">🔧 SAV</option>
            </select>
            <div class="chatroom-link-search-container">
                <input type="text" id="linkSearchInput" class="chatroom-link-search" placeholder="Rechercher..." autocomplete="off">
                <div id="linkSearchResults" class="chatroom-link-results"></div>
            </div>
            <div id="linkSelected" class="chatroom-link-selected" style="display:none;">
                <span id="linkSelectedLabel"></span>
                <button type="button" onclick="clearLink()">✕</button>
            </div>
        </div>

        <!-- Barre de saisie (fixe en bas) -->
        <div class="chatroom-input-container">
            <div class="chatroom-input-wrapper">
                <textarea 
                    id="messageInput" 
                    class="chatroom-input" 
                    placeholder="Tapez votre message... (Utilisez @ pour mentionner, ou cliquez sur le bouton pour lier un client/SAV/livraison)"
                    rows="1"
                    maxlength="5000"></textarea>
                <div id="mentionSuggestions" class="chatroom-mention-suggestions"></div>
            </div>
            <button 
                type="button" 
                id="linkToggleBtn" 
                class="chatroom-send-btn" 
                style="background: var(--bg-tertiary); color: var(--text-primary); margin-right: 0.5rem;"
                title="Lier un client/SAV/livraison"
                aria-label="Lier">
                🔗
            </button>
            <button 
                type="button" 
                id="sendButton" 
                class="chatroom-send-btn" 
                title="Envoyer le message"
                aria-label="Envoyer le message">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                </svg>
            </button>
        </div>
    </div>
</main>

<script>
// ============================================
// Configuration globale
// ============================================
const CONFIG = {
    currentUserId: <?= $currentUserId ?>,
    currentUserName: <?= json_encode($currentUserName) ?>,
    csrfToken: <?= json_encode($CSRF) ?>,
    refreshInterval: 2000, // 2 secondes
    maxMessageLength: 5000
};

// ============================================
// Éléments DOM
// ============================================
const messagesContainer = document.getElementById('chatroomMessages');
const messageInput = document.getElementById('messageInput');
const sendButton = document.getElementById('sendButton');
const loadingIndicator = document.getElementById('loadingIndicator');
const linksSelector = document.getElementById('linksSelector');
const linkTypeSelect = document.getElementById('linkTypeSelect');
const linkSearchInput = document.getElementById('linkSearchInput');
const linkSearchResults = document.getElementById('linkSearchResults');
const linkSelected = document.getElementById('linkSelected');
const linkSelectedLabel = document.getElementById('linkSelectedLabel');
const linkToggleBtn = document.getElementById('linkToggleBtn');
const mentionSuggestions = document.getElementById('mentionSuggestions');

// ============================================
// Variables d'état
// ============================================
let lastMessageId = 0;
let isLoading = false;
let isSending = false;
let autoScrollEnabled = true;
let refreshIntervalId = null;
let selectedLink = null; // {type: 'client'|'livraison'|'sav', id: number, label: string}
let mentionSearchTimeout = null;
let mentionSearchQuery = '';
let mentionSearchIndex = -1;
let mentionSuggestionsList = [];
let allUsers = []; // Cache des utilisateurs pour les mentions

// ============================================
// Fonctions utilitaires
// ============================================
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    
    if (diff < 60000) return 'À l\'instant';
    if (diff < 3600000) return `Il y a ${Math.floor(diff / 60000)} min`;
    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return 'Hier à ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }
    return date.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function scrollToBottom(smooth = true) {
    if (!autoScrollEnabled) return;
    setTimeout(() => {
        messagesContainer.scrollTo({ top: messagesContainer.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
    }, 100);
}

// ============================================
// Gestion des mentions
// ============================================
async function searchUsers(query) {
    if (!query || query.length < 1) {
        mentionSuggestions.classList.remove('show');
        return;
    }
    
    try {
        const response = await fetch(`/API/chatroom_search_users.php?q=${encodeURIComponent(query)}&limit=10`, {
            credentials: 'same-origin'
        });
        const data = await response.json();
        
        if (data.ok && data.users) {
            mentionSuggestionsList = data.users;
            displayMentionSuggestions();
        }
    } catch (error) {
        console.error('Erreur recherche utilisateurs:', error);
    }
}

function displayMentionSuggestions() {
    mentionSuggestions.innerHTML = '';
    if (mentionSuggestionsList.length === 0) {
        mentionSuggestions.classList.remove('show');
        return;
    }
    
    mentionSuggestionsList.forEach((user, index) => {
        const item = document.createElement('div');
        item.className = 'chatroom-mention-item' + (index === mentionSearchIndex ? ' selected' : '');
        item.innerHTML = `<strong>${escapeHtml(user.display_name)}</strong><br><small>${escapeHtml(user.emploi)}</small>`;
        item.addEventListener('click', () => insertMention(user));
        mentionSuggestions.appendChild(item);
    });
    mentionSuggestions.classList.add('show');
}

function insertMention(user) {
    const text = messageInput.value;
    const cursorPos = messageInput.selectionStart;
    const textBefore = text.substring(0, cursorPos);
    const textAfter = text.substring(cursorPos);
    
    // Trouver la position du @
    const atIndex = textBefore.lastIndexOf('@');
    if (atIndex === -1) return;
    
    const newText = text.substring(0, atIndex) + `@${user.display_name} ` + textAfter;
    messageInput.value = newText;
    const newCursorPos = atIndex + user.display_name.length + 2;
    messageInput.setSelectionRange(newCursorPos, newCursorPos);
    mentionSuggestions.classList.remove('show');
    mentionSearchIndex = -1;
    adjustTextareaHeight();
}

// Détecter les mentions dans le texte
function detectMentions(text) {
    const mentionRegex = /@([^\s@]+)/g;
    const mentions = [];
    let match;
    while ((match = mentionRegex.exec(text)) !== null) {
        mentions.push(match[1]);
    }
    return mentions;
}

// Extraire les IDs des utilisateurs mentionnés
async function extractMentionIds(mentions) {
    if (mentions.length === 0) return [];
    
    // Charger tous les utilisateurs si nécessaire
    if (allUsers.length === 0) {
        try {
            const response = await fetch('/API/chatroom_search_users.php?q=&limit=1000', { credentials: 'same-origin' });
            const data = await response.json();
            if (data.ok && data.users) {
                allUsers = data.users;
            }
        } catch (error) {
            console.error('Erreur chargement utilisateurs:', error);
        }
    }
    
    const mentionIds = [];
    mentions.forEach(mentionName => {
        const user = allUsers.find(u => 
            u.display_name.toLowerCase() === mentionName.toLowerCase() ||
            `${u.prenom} ${u.nom}`.toLowerCase() === mentionName.toLowerCase()
        );
        if (user) mentionIds.push(user.id);
    });
    
    return mentionIds;
}

// Formater le message avec mentions et liens
function formatMessageContent(message, mentions = [], lien = null) {
    let content = escapeHtml(message);
    
    // Remplacer les mentions par des spans stylisés
    if (mentions.length > 0) {
        mentions.forEach(mentionName => {
            const regex = new RegExp(`@${escapeHtml(mentionName).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'gi');
            content = content.replace(regex, `<span class="mention">@${escapeHtml(mentionName)}</span>`);
        });
    }
    
    return content;
}

// ============================================
// Gestion des liens (clients/SAVs/livraisons)
// ============================================
linkToggleBtn.addEventListener('click', () => {
    linksSelector.style.display = linksSelector.style.display === 'none' ? 'block' : 'none';
});

linkTypeSelect.addEventListener('change', () => {
    const type = linkTypeSelect.value;
    if (type) {
        linkSearchInput.placeholder = type === 'client' ? 'Rechercher un client...' 
                                    : type === 'livraison' ? 'Rechercher une livraison...'
                                    : 'Rechercher un SAV...';
        linkSearchInput.style.display = 'block';
    } else {
        linkSearchInput.style.display = 'none';
        linkSearchResults.classList.remove('show');
        clearLink();
    }
});

let linkSearchTimeout = null;
linkSearchInput.addEventListener('input', () => {
    const query = linkSearchInput.value.trim();
    const type = linkTypeSelect.value;
    
    clearTimeout(linkSearchTimeout);
    
    if (!type || !query || query.length < 1) {
        linkSearchResults.classList.remove('show');
        return;
    }
    
    linkSearchTimeout = setTimeout(async () => {
        try {
            let url = '';
            if (type === 'client') {
                url = `/API/maps_search_clients_test.php?q=${encodeURIComponent(query)}&limit=15`;
            } else if (type === 'livraison') {
                url = `/API/messagerie_search_livraisons.php?q=${encodeURIComponent(query)}&limit=15`;
            } else if (type === 'sav') {
                url = `/API/messagerie_search_sav.php?q=${encodeURIComponent(query)}&limit=15`;
            }
            
            const response = await fetch(url, { credentials: 'same-origin' });
            const data = await response.json();
            
            linkSearchResults.innerHTML = '';
            
            if (data.ok) {
                const results = type === 'client' ? (data.clients || []) : (data.results || []);
                if (results.length === 0) {
                    linkSearchResults.innerHTML = '<div class="chatroom-link-result-item">Aucun résultat</div>';
                } else {
                    results.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'chatroom-link-result-item';
                        if (type === 'client') {
                            div.innerHTML = `<strong>${escapeHtml(item.name)}</strong>${item.dirigeant_complet ? '<br><small>' + escapeHtml(item.dirigeant_complet) + '</small>' : ''}`;
                        } else {
                            div.innerHTML = `<strong>${escapeHtml(item.label || item.reference || '')}</strong>`;
                        }
                        div.addEventListener('click', () => {
                            selectedLink = { type, id: item.id, label: type === 'client' ? item.name : (item.label || item.reference || '') };
                            linkSelectedLabel.textContent = selectedLink.label;
                            linkSelected.style.display = 'flex';
                            linkSearchInput.value = '';
                            linkSearchResults.classList.remove('show');
                        });
                        linkSearchResults.appendChild(div);
                    });
                }
                linkSearchResults.classList.add('show');
            }
        } catch (error) {
            console.error('Erreur recherche lien:', error);
        }
    }, 300);
});

function clearLink() {
    selectedLink = null;
    linkTypeSelect.value = '';
    linkSearchInput.value = '';
    linkSelected.style.display = 'none';
    linkSearchResults.classList.remove('show');
}

// ============================================
// Affichage des messages
// ============================================
function renderMessage(message) {
    const isMe = message.is_me;
    const messageClass = isMe ? 'message-me' : 'message-other';
    const authorName = isMe ? 'Moi' : escapeHtml((message.user_prenom || '') + ' ' + (message.user_nom || ''));
    const userInfo = isMe ? '' : `<span class="message-author">${authorName}</span>`;
    
    // Formater le contenu avec mentions
    let messageContent = formatMessageContent(message.message, message.mentions || []);
    
    // Ajouter le lien si présent
    let lienHtml = '';
    if (message.lien) {
        const lienIcons = { client: '👤', livraison: '📦', sav: '🔧' };
        const lienUrls = {
            client: `/public/client_fiche.php?id=${message.lien.id}`,
            livraison: `/public/livraison.php?ref=${encodeURIComponent(message.lien.label)}`,
            sav: `/public/sav.php?ref=${encodeURIComponent(message.lien.label)}`
        };
        lienHtml = `<div style="margin-top: 0.5rem; font-size: 0.85rem;">
            <a href="${lienUrls[message.lien.type]}" target="_blank" class="message-link">
                ${lienIcons[message.lien.type]} ${escapeHtml(message.lien.label)}
            </a>
        </div>`;
    }
    
    const messageHtml = `
        <div class="chatroom-message ${messageClass}" data-message-id="${message.id}">
            <div class="message-bubble">
                <p class="message-content">${messageContent}</p>
                ${lienHtml}
            </div>
            <div class="message-info">
                ${userInfo}
                <span class="message-time">${formatTime(message.date_envoi)}</span>
            </div>
        </div>
    `;
    
    return messageHtml;
}

function renderMessages(messages, append = false) {
    if (!messages || messages.length === 0) {
        if (!append && loadingIndicator) {
            loadingIndicator.innerHTML = '<div class="chatroom-empty"><div class="chatroom-empty-icon">💬</div><p>Aucun message pour le moment.<br>Soyez le premier à écrire !</p></div>';
        }
        return;
    }
    
    if (loadingIndicator) loadingIndicator.style.display = 'none';
    
    const wasAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop <= messagesContainer.clientHeight + 100;
    
    if (append) {
        messages.forEach(msg => {
            const messageElement = document.createElement('div');
            messageElement.innerHTML = renderMessage(msg);
            const messageDiv = messageElement.firstElementChild;
            messageDiv.classList.add('message-new');
            messagesContainer.appendChild(messageDiv);
        });
        if (messages.length > 0) {
            lastMessageId = Math.max(lastMessageId, ...messages.map(m => m.id));
        }
        if (wasAtBottom || autoScrollEnabled) scrollToBottom(true);
    } else {
        messagesContainer.innerHTML = '';
        messages.forEach(msg => {
            const messageElement = document.createElement('div');
            messageElement.innerHTML = renderMessage(msg);
            messagesContainer.appendChild(messageElement.firstElementChild);
        });
        if (messages.length > 0) {
            lastMessageId = Math.max(...messages.map(m => m.id));
        }
        scrollToBottom(false);
    }
}

// ============================================
// Chargement des messages
// ============================================
async function loadMessages(append = false) {
    if (isLoading) return;
    isLoading = true;
    
    try {
        const url = append && lastMessageId > 0
            ? `/API/chatroom_get.php?since_id=${lastMessageId}`
            : `/API/chatroom_get.php?limit=100`;
        
        const response = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        
        const data = await response.json();
        if (data.ok && data.messages) {
            renderMessages(data.messages, append);
        }
    } catch (error) {
        console.error('Erreur chargement messages:', error);
        if (loadingIndicator) {
            loadingIndicator.innerHTML = '<div class="chatroom-loading">Erreur de chargement. Vérifiez votre connexion.</div>';
        }
    } finally {
        isLoading = false;
    }
}

// ============================================
// Envoi de message
// ============================================
async function sendMessage() {
    const messageText = messageInput.value.trim();
    if (!messageText || messageText.length === 0) return;
    if (messageText.length > CONFIG.maxMessageLength) {
        alert(`Le message est trop long (max ${CONFIG.maxMessageLength} caractères)`);
        return;
    }
    if (isSending) return;
    
    isSending = true;
    sendButton.disabled = true;
    const originalMessage = messageText;
    
    // Extraire les mentions
    const mentionNames = detectMentions(messageText);
    const mentionIds = await extractMentionIds(mentionNames);
    
    messageInput.value = '';
    adjustTextareaHeight();
    clearLink();
    linksSelector.style.display = 'none';
    
    try {
        const response = await fetch('/API/chatroom_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: CONFIG.csrfToken,
                message: originalMessage,
                mentions: mentionIds,
                type_lien: selectedLink ? selectedLink.type : null,
                id_lien: selectedLink ? selectedLink.id : null
            }),
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `HTTP ${response.status}`);
        }
        
        const data = await response.json();
        if (data.ok && data.message) {
            renderMessages([data.message], true);
            setTimeout(() => loadMessages(true), 500);
        } else {
            throw new Error(data.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Erreur envoi message:', error);
        alert('Erreur lors de l\'envoi du message: ' + error.message);
        messageInput.value = originalMessage;
        adjustTextareaHeight();
    } finally {
        isSending = false;
        sendButton.disabled = false;
        messageInput.focus();
    }
}

// ============================================
// Gestion de la hauteur du textarea et mentions
// ============================================
function adjustTextareaHeight() {
    messageInput.style.height = 'auto';
    messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
}

messageInput.addEventListener('input', (e) => {
    adjustTextareaHeight();
    
    const text = e.target.value;
    const cursorPos = e.target.selectionStart;
    const textBefore = text.substring(0, cursorPos);
    const atIndex = textBefore.lastIndexOf('@');
    
    if (atIndex !== -1) {
        const query = textBefore.substring(atIndex + 1).trim();
        if (query.length > 0 && !query.includes(' ')) {
            clearTimeout(mentionSearchTimeout);
            mentionSearchTimeout = setTimeout(() => searchUsers(query), 300);
            return;
        }
    }
    mentionSuggestions.classList.remove('show');
});

messageInput.addEventListener('keydown', (e) => {
    if (mentionSuggestions.classList.contains('show') && mentionSuggestionsList.length > 0) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            mentionSearchIndex = Math.min(mentionSearchIndex + 1, mentionSuggestionsList.length - 1);
            displayMentionSuggestions();
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            mentionSearchIndex = Math.max(mentionSearchIndex - 1, -1);
            displayMentionSuggestions();
            return;
        }
        if (e.key === 'Enter' && mentionSearchIndex >= 0) {
            e.preventDefault();
            insertMention(mentionSuggestionsList[mentionSearchIndex]);
            return;
        }
        if (e.key === 'Escape') {
            mentionSuggestions.classList.remove('show');
            return;
        }
    }
    
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

// Fermer les suggestions au clic extérieur
document.addEventListener('click', (e) => {
    if (!messageInput.contains(e.target) && !mentionSuggestions.contains(e.target)) {
        mentionSuggestions.classList.remove('show');
    }
    if (!linksSelector.contains(e.target) && e.target !== linkToggleBtn) {
        linkSearchResults.classList.remove('show');
    }
});

// ============================================
// Gestion du scroll
// ============================================
messagesContainer.addEventListener('scroll', () => {
    const isAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop <= messagesContainer.clientHeight + 100;
    autoScrollEnabled = isAtBottom;
});

// ============================================
// Event Listeners
// ============================================
sendButton.addEventListener('click', sendMessage);

// ============================================
// Initialisation
// ============================================
async function init() {
    await loadMessages(false);
    refreshIntervalId = setInterval(() => loadMessages(true), CONFIG.refreshInterval);
    messageInput.focus();
}

init();

window.addEventListener('beforeunload', () => {
    if (refreshIntervalId) clearInterval(refreshIntervalId);
});
</script>
</body>
</html>
