<?php
// /public/messagerie.php
// Messagerie CCComputer : Chat général + Messageries privées
// - Général : chat commun visible par tous, messages et images expirés après 24h
// - Privé : conversations 1-à-1 strictement isolées, messages et images expirés après 24h

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('messagerie', []);
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/messagerie_purge.php';

$pdo = getPdo();
$CSRF = ensureCsrfToken();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserNom = $_SESSION['user_nom'] ?? '';
$currentUserPrenom = $_SESSION['user_prenom'] ?? '';
$currentUserName = trim($currentUserPrenom . ' ' . $currentUserNom);

$tablePrivateExists = false;
$tableGeneralExists = false;
try {
    $check = $pdo->prepare("SHOW TABLES LIKE 'private_messages'");
    $check->execute();
    $tablePrivateExists = $check->rowCount() > 0;
    $check = $pdo->prepare("SHOW TABLES LIKE 'chatroom_messages'");
    $check->execute();
    $tableGeneralExists = $check->rowCount() > 0;
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur vérification tables: ' . $e->getMessage());
}

// Purge automatique 24h : général + privé + images (à chaque chargement)
if ($tablePrivateExists || $tableGeneralExists) {
    try {
        purgeMessagerie24h($pdo);
    } catch (Throwable $e) {
        error_log('messagerie.php - Purge: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Messagerie - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="/assets/logos/logo.png">
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/chatroom.css">
    <script src="/assets/js/api.js" defer></script>
</head>
<body class="page-maps page-chatroom">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Messagerie</h1>
        <nav class="messagerie-tabs" role="tablist">
            <button type="button" class="messagerie-tab active" id="tabGeneral" role="tab" aria-selected="true" aria-controls="generalPanel">Chat général</button>
            <button type="button" class="messagerie-tab" id="tabPrivate" role="tab" aria-selected="false" aria-controls="privatePanel">Messages privés</button>
        </nav>
    </header>

    <?php if (!$tablePrivateExists || !$tableGeneralExists): ?>
        <div class="maps-message alert" style="margin: 1rem 0;">
            <strong>⚠️ Attention :</strong>
            <?php if (!$tableGeneralExists): ?>La table <code>chatroom_messages</code> n'existe pas.<?php endif; ?>
            <?php if (!$tablePrivateExists): ?>La table <code>private_messages</code> n'existe pas. Exécutez <code>sql/migration_create_private_messages.sql</code>.<?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="chatroom-container">
        <!-- Panneau Chat général -->
        <div class="general-chat-panel" id="generalPanel" role="tabpanel" aria-labelledby="tabGeneral">
            <div class="general-chat-header">
                <h2>Chat général</h2>
                <span class="general-chat-warning">Messages conservés 24 heures</span>
                <div class="general-chat-actions">
                    <button type="button" class="btn-refresh" id="refreshGeneral" title="Actualiser">Actualiser</button>
                </div>
            </div>
            <div class="chatroom-messages" id="generalMessages" role="log" aria-live="polite">
                <div class="chatroom-loading" id="generalLoading">Chargement...</div>
            </div>
            <div class="chatroom-input-container" id="generalInputContainer">
                <button type="button" id="generalImageUploadButton" class="image-upload-btn" title="Ajouter une image" aria-label="Ajouter une image">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </button>
                <input type="file" id="generalImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                <div class="chatroom-input-wrapper">
                    <div id="generalImagePreviewContainer" class="image-preview-container" style="display: none;">
                        <img id="generalImagePreview" class="image-preview" alt="Aperçu">
                        <button type="button" id="generalRemoveImagePreview" class="image-preview-remove">✕</button>
                    </div>
                    <textarea id="generalMessageInput" class="chatroom-input" placeholder="Message au chat général..." rows="1" maxlength="5000" aria-label="Message"></textarea>
                </div>
                <button type="button" id="generalSendButton" class="chatroom-send-btn" title="Envoyer" aria-label="Envoyer">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </div>
        </div>

        <!-- Panneau Messages privés -->
        <div class="private-messaging-layout" id="privatePanel" role="tabpanel" aria-labelledby="tabPrivate" style="display: none;">
            <aside class="private-sidebar" id="usersSidebar">
                <div class="private-sidebar-header">
                    <h3>Conversations</h3>
                    <input type="text" id="userSearch" class="private-search" placeholder="Rechercher un utilisateur..." aria-label="Rechercher">
                </div>
                <div class="private-users-list" id="usersList">
                    <div class="chatroom-loading" id="usersLoading">Chargement...</div>
                </div>
            </aside>
            <div class="private-main">
                <div class="private-conversation-placeholder" id="conversationPlaceholder">
                    <p>Sélectionnez un utilisateur pour démarrer une conversation privée.</p>
                    <small>Les messages sont visibles uniquement entre vous et le destinataire. Conservés 24 heures.</small>
                </div>
                <div class="private-conversation-panel" id="conversationPanel" style="display: none;">
                    <div class="chatroom-header private-conversation-header">
                        <h2 id="conversationTitle">Conversation</h2>
                        <span id="conversationStatus" class="conversation-status" aria-live="polite">
                            <span class="status-dot" id="statusDot"></span>
                            <span id="statusText">—</span>
                        </span>
                    </div>
                    <div class="chatroom-messages" id="privateMessages" role="log" aria-live="polite">
                        <div class="chatroom-loading" id="loadingIndicator">Chargement...</div>
                    </div>
                    <div class="chatroom-input-container" id="inputContainer">
                        <button type="button" id="imageUploadButton" class="image-upload-btn" title="Ajouter une image" aria-label="Ajouter une image">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </button>
                        <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                        <div class="chatroom-input-wrapper">
                            <div id="imagePreviewContainer" class="image-preview-container" style="display: none;">
                                <img id="imagePreview" class="image-preview" alt="Aperçu">
                                <button type="button" id="removeImagePreview" class="image-preview-remove">✕</button>
                            </div>
                            <textarea id="messageInput" class="chatroom-input" placeholder="Tapez votre message..." rows="1" maxlength="5000" aria-label="Message"></textarea>
                        </div>
                        <button type="button" id="sendButton" class="chatroom-send-btn" title="Envoyer" aria-label="Envoyer">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
const CONFIG = {
    currentUserId: <?= $currentUserId ?>,
    currentUserName: <?= json_encode($currentUserName) ?>,
    csrfToken: <?= json_encode($CSRF) ?>,
    maxMessageLength: 5000
};

// === Éléments DOM ===
const tabGeneral = document.getElementById('tabGeneral');
const tabPrivate = document.getElementById('tabPrivate');
const generalPanel = document.getElementById('generalPanel');
const privatePanel = document.getElementById('privatePanel');
const generalMessages = document.getElementById('generalMessages');
const generalLoading = document.getElementById('generalLoading');
const generalMessageInput = document.getElementById('generalMessageInput');
const generalSendButton = document.getElementById('generalSendButton');
const generalImageUploadButton = document.getElementById('generalImageUploadButton');
const generalImageInput = document.getElementById('generalImageInput');
const generalImagePreviewContainer = document.getElementById('generalImagePreviewContainer');
const generalImagePreview = document.getElementById('generalImagePreview');
const generalRemoveImagePreview = document.getElementById('generalRemoveImagePreview');
const refreshGeneral = document.getElementById('refreshGeneral');

const privateMessagesContainer = document.getElementById('privateMessages');
const messageInput = document.getElementById('messageInput');
const sendButton = document.getElementById('sendButton');
const loadingIndicator = document.getElementById('loadingIndicator');
const imageUploadButton = document.getElementById('imageUploadButton');
const imageInput = document.getElementById('imageInput');
const imagePreviewContainer = document.getElementById('imagePreviewContainer');
const imagePreview = document.getElementById('imagePreview');
const removeImagePreview = document.getElementById('removeImagePreview');
const usersList = document.getElementById('usersList');
const userSearch = document.getElementById('userSearch');
const conversationPlaceholder = document.getElementById('conversationPlaceholder');
const conversationPanel = document.getElementById('conversationPanel');
const conversationTitle = document.getElementById('conversationTitle');
const conversationStatus = document.getElementById('conversationStatus');
const statusDot = document.getElementById('statusDot');
const statusText = document.getElementById('statusText');

// === État ===
let activeTab = 'general';
let selectedUserId = null;
let selectedUserName = '';
let lastGeneralMessageId = 0;
let lastPrivateMessageId = 0;
let isLoadingGeneral = false;
let isLoadingPrivate = false;
let isSendingGeneral = false;
let isSendingPrivate = false;
let selectedImageGeneral = null;
let selectedImagePrivate = null;
let generalRefreshIntervalId = null;
let privateRefreshIntervalId = null;
let lastRenderedDateStr = null;
let onlineStatusIntervalId = null;

// === Utilitaires ===
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';
    const now = new Date();
    const diff = now - date;
    if (diff < 60000) return 'À l\'instant';
    if (diff < 3600000) return `Il y a ${Math.floor(diff / 60000)} min`;
    if (date.toDateString() === now.toDateString()) return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) return 'Hier à ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    return date.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

function formatMessageContent(text) {
    let content = escapeHtml(text);
    content = content.replace(/(https?:\/\/[^\s]+)/g, url => `<a href="${escapeHtml(url)}" target="_blank" rel="noopener noreferrer" class="message-link">${escapeHtml(url)}</a>`);
    return content;
}

function getInitials(prenom, nom) {
    const first = (prenom || '').charAt(0).toUpperCase();
    const last = (nom || '').charAt(0).toUpperCase();
    return (first + last) || '?';
}

function renderMessage(msg, currentUserName) {
    if (!msg || typeof msg !== 'object') return '';
    const isMe = msg.is_me === true || msg.is_me === 1;
    const messageClass = isMe ? 'message-me' : 'message-other';
    const authorName = isMe ? 'Moi' : escapeHtml((msg.user_prenom || '') + ' ' + (msg.user_nom || ''));
    const userInfo = isMe ? '' : `<span class="message-author">${authorName}</span>`;
    const avatarInitials = isMe ? getInitials((currentUserName || '').split(' ')[0], (currentUserName || '').split(' ')[1]) : getInitials(msg.user_prenom || '', msg.user_nom || '');
    const avatarHtml = `<div class="message-avatar" aria-hidden="true">${avatarInitials}</div>`;
    let messageContent = msg.message ? `<p class="message-content">${formatMessageContent(msg.message)}</p>` : '';
    let imageContent = '';
    if (msg.image_path) {
        imageContent = `<div class="message-image-wrapper"><img src="${escapeHtml(msg.image_path)}" alt="Image" class="message-image" loading="lazy" onclick="openImageLightbox('${escapeHtml(msg.image_path)}')" onerror="this.onerror=null; this.style.opacity='0.5'; this.alt='Image non disponible';"></div>`;
    }
    const sendingIndicator = msg.sending ? '<span class="message-sending"><span class="spinner"></span> Envoi...</span>' : '';
    return `
        <div class="chatroom-message ${messageClass}" data-message-id="${msg.id}" role="article">
            ${!isMe ? avatarHtml : ''}
            <div class="message-content-wrapper">
                <div class="message-bubble">${messageContent}${imageContent}${sendingIndicator}</div>
                <div class="message-info">${userInfo}<span class="message-time">${formatTime(msg.date_envoi)}</span></div>
            </div>
            ${isMe ? avatarHtml : ''}
        </div>`;
}

function addDateSeparator(dateString) {
    const date = new Date(dateString);
    const today = new Date();
    today.setHours(0,0,0,0);
    const msgDate = new Date(date);
    msgDate.setHours(0,0,0,0);
    const label = msgDate.getTime() === today.getTime() ? 'Aujourd\'hui' : (msgDate.getTime() === today.getTime() - 86400000 ? 'Hier' : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }));
    const sep = document.createElement('div');
    sep.className = 'message-date-separator';
    sep.innerHTML = `<span>${label}</span>`;
    return sep;
}

// === Onglets ===
function switchTab(tab) {
    activeTab = tab;
    tabGeneral.classList.toggle('active', tab === 'general');
    tabPrivate.classList.toggle('active', tab === 'private');
    tabGeneral.setAttribute('aria-selected', tab === 'general');
    tabPrivate.setAttribute('aria-selected', tab === 'private');
    generalPanel.style.display = tab === 'general' ? 'flex' : 'none';
    privatePanel.style.display = tab === 'private' ? 'flex' : 'none';

    if (tab === 'general') {
        stopPrivatePolling();
        loadGeneralMessages(false);
        startGeneralPolling();
    } else {
        stopGeneralPolling();
        loadUsers();
        if (selectedUserId) loadPrivateMessages(false);
        startPrivatePolling();
    }
}

// === Chat général ===
function renderGeneralMessages(messages, append) {
    const c = generalMessages;
    const le = generalLoading;
    if (!messages || messages.length === 0) {
        if (!append && le) { le.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>'; le.style.display = 'block'; }
        return;
    }
    if (le) le.style.display = 'none';
    const wasAtBottom = c.scrollHeight - c.scrollTop <= c.clientHeight + 50;
    let lastDateStr = null;
    if (append) {
        messages.forEach(msg => {
            if (!msg || msg.id === undefined) return;
            if (c.querySelector(`[data-message-id="${msg.id}"]`)) return;
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (lastDateStr !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                lastDateStr = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg, CONFIG.currentUserName);
            div.firstElementChild.classList.add('message-new');
            c.appendChild(div.firstElementChild);
        });
        lastGeneralMessageId = Math.max(lastGeneralMessageId, ...(messages.map(m => m.id || 0)));
        if (wasAtBottom) c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' });
    } else {
        c.innerHTML = '';
        lastDateStr = null;
        messages.forEach(msg => {
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (lastDateStr !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                lastDateStr = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg, CONFIG.currentUserName);
            c.appendChild(div.firstElementChild);
        });
        lastGeneralMessageId = messages.length ? Math.max(...messages.map(m => m.id)) : 0;
        c.scrollTo({ top: c.scrollHeight, behavior: 'auto' });
    }
}

async function loadGeneralMessages(append) {
    if (isLoadingGeneral) return;
    isLoadingGeneral = true;
    try {
        const url = append && lastGeneralMessageId > 0
            ? `/API/chatroom_get.php?since_id=${lastGeneralMessageId}&limit=50`
            : `/API/chatroom_get.php?limit=100`;
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Erreur');
        if (data.ok && data.messages) {
            if (data.messages.length > 0) renderGeneralMessages(data.messages, append);
            else if (!append) {
                generalLoading.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
                generalLoading.style.display = 'block';
            }
        }
    } catch (e) {
        if (generalLoading) generalLoading.innerHTML = '<div class="chatroom-loading">Erreur de chargement</div>';
    } finally {
        isLoadingGeneral = false;
    }
}

async function sendGeneralMessage() {
    const text = generalMessageInput.value.trim();
    const hasImage = selectedImageGeneral && selectedImageGeneral instanceof File;
    if (!text && !hasImage) return;
    if (text && text.length > CONFIG.maxMessageLength) return;
    if (isSendingGeneral) return;

    isSendingGeneral = true;
    generalSendButton.disabled = true;
    const originalMessage = text;
    const originalImage = hasImage ? selectedImageGeneral : null;

    const tempMsg = { id: 'temp_' + Date.now(), message: text || '(image)', date_envoi: new Date().toISOString(), is_me: true, sending: true, image_path: hasImage ? URL.createObjectURL(originalImage) : null };
    renderGeneralMessages([tempMsg], true);

    if (generalImagePreview.src && generalImagePreview.src.startsWith('blob:')) URL.revokeObjectURL(generalImagePreview.src);
    generalMessageInput.value = '';
    selectedImageGeneral = null;
    generalImagePreviewContainer.style.display = 'none';

    try {
        let imagePath = null;
        if (hasImage && originalImage instanceof File) {
            const formData = new FormData();
            formData.append('image', originalImage);
            formData.append('csrf_token', CONFIG.csrfToken);
            const upRes = await fetch('/API/chatroom_upload_image.php', { method: 'POST', body: formData, credentials: 'include' });
            if (!upRes.ok) {
                const err = await upRes.json();
                throw new Error(err.error || 'Erreur upload');
            }
            const upData = await upRes.json();
            if (upData.ok && upData.image_path) imagePath = upData.image_path;
        }

        const res = await fetch('/API/chatroom_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf_token: CONFIG.csrfToken, message: originalMessage || '', image_path: imagePath || null }),
            credentials: 'include'
        });
        const data = await res.json();

        const tempEl = generalMessages.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();

        if (res.ok && data.ok && data.message) {
            renderGeneralMessages([data.message], true);
            lastGeneralMessageId = Math.max(lastGeneralMessageId, data.message.id);
        } else {
            throw new Error(data.error || 'Erreur envoi');
        }
    } catch (e) {
        const tempEl = generalMessages.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();
        alert('Erreur : ' + e.message);
        generalMessageInput.value = originalMessage;
        if (originalImage) {
            selectedImageGeneral = originalImage;
            generalImagePreview.src = URL.createObjectURL(originalImage);
            generalImagePreviewContainer.style.display = 'flex';
        }
    } finally {
        isSendingGeneral = false;
        generalSendButton.disabled = false;
        generalMessageInput.focus();
    }
}

function startGeneralPolling() {
    stopGeneralPolling();
    generalRefreshIntervalId = setInterval(() => {
        if (activeTab === 'general' && !isLoadingGeneral) loadGeneralMessages(true);
    }, 2000);
}

function stopGeneralPolling() {
    clearInterval(generalRefreshIntervalId);
    generalRefreshIntervalId = null;
}

// === Messages privés ===
function renderPrivateMessages(messages, append) {
    const c = privateMessagesContainer;
    if (!messages || messages.length === 0) {
        if (!append) c.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
        return;
    }
    const wasAtBottom = c.scrollHeight - c.scrollTop <= c.clientHeight + 50;
    if (append) {
        messages.forEach(msg => {
            if (!msg || msg.id === undefined) return;
            if (c.querySelector(`[data-message-id="${msg.id}"]`)) return;
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (lastRenderedDateStr !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                lastRenderedDateStr = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg, CONFIG.currentUserName);
            div.firstElementChild.classList.add('message-new');
            c.appendChild(div.firstElementChild);
        });
        lastPrivateMessageId = Math.max(lastPrivateMessageId, ...messages.map(m => m.id || 0));
        if (wasAtBottom) c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' });
    } else {
        c.innerHTML = '';
        lastRenderedDateStr = null;
        messages.forEach(msg => {
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (lastRenderedDateStr !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                lastRenderedDateStr = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg, CONFIG.currentUserName);
            c.appendChild(div.firstElementChild);
        });
        lastPrivateMessageId = messages.length ? Math.max(...messages.map(m => m.id)) : 0;
        c.scrollTo({ top: c.scrollHeight, behavior: 'auto' });
    }
}

async function loadUsers(query = '') {
    try {
        const url = `/API/private_messages_list_users.php?q=${encodeURIComponent(query)}&limit=100`;
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        if (!data.ok || !data.users) return;
        usersList.innerHTML = '';
        if (data.users.length === 0) {
            usersList.innerHTML = '<div class="chatroom-empty"><p>Aucun utilisateur trouvé</p></div>';
            return;
        }
        data.users.forEach(u => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'private-user-item' + (selectedUserId === u.id ? ' selected' : '');
            item.dataset.userId = u.id;
            const isOnline = u.online === true;
            item.innerHTML = `<span class="private-user-avatar${isOnline ? ' user-online' : ''}">${getInitials(u.prenom, u.nom)}</span><span class="private-user-status-dot ${isOnline ? 'status-online' : 'status-offline'}" aria-label="${isOnline ? 'En ligne' : 'Hors ligne'}"></span><span class="private-user-name">${escapeHtml(u.display_name)}</span>`;
            item.addEventListener('click', () => selectUser(u.id, u.display_name, isOnline));
            usersList.appendChild(item);
        });
    } catch (e) {
        usersList.innerHTML = '<div class="chatroom-empty"><p>Erreur chargement</p></div>';
    }
}

function selectUser(userId, userName, online) {
    selectedUserId = userId;
    selectedUserName = userName;
    usersList.querySelectorAll('.private-user-item').forEach(el => el.classList.toggle('selected', parseInt(el.dataset.userId) === userId));
    conversationPlaceholder.style.display = 'none';
    conversationPanel.style.display = 'flex';
    conversationTitle.textContent = userName;
    updateConversationStatus(online);
    lastPrivateMessageId = 0;
    lastRenderedDateStr = null;
    isLoadingPrivate = false;

    // Vider immédiatement le conteneur pour éviter d'afficher les messages d'une autre discussion
    privateMessagesContainer.innerHTML = '<div class="chatroom-loading" id="loadingIndicator">Chargement...</div>';
    loadPrivateMessages(false);
    messageInput.focus();
    messageInput.placeholder = 'Message privé à ' + userName + '...';
    startOnlineStatusPolling();
}

function updateConversationStatus(online) {
    if (!statusDot || !statusText) return;
    statusDot.className = 'status-dot ' + (online ? 'status-online' : 'status-offline');
    statusText.textContent = online ? 'En ligne' : 'Hors ligne';
}

async function fetchOnlineStatus(userId) {
    try {
        const res = await fetch(`/API/user_online_status.php?user_id=${userId}`, { credentials: 'include' });
        const data = await res.json();
        if (data.ok && selectedUserId === userId) updateConversationStatus(data.online);
    } catch (e) { /* ignore */ }
}

async function heartbeatPresence() {
    try {
        await fetch('/API/chatroom_get_online_users.php', { credentials: 'include' });
    } catch (e) { /* ignore */ }
}

function startOnlineStatusPolling() {
    clearInterval(onlineStatusIntervalId);
    if (!selectedUserId) return;
    fetchOnlineStatus(selectedUserId);
    heartbeatPresence();
    onlineStatusIntervalId = setInterval(() => {
        if (selectedUserId) {
            fetchOnlineStatus(selectedUserId);
            heartbeatPresence();
        }
    }, 30000);
}

function stopOnlineStatusPolling() {
    clearInterval(onlineStatusIntervalId);
    onlineStatusIntervalId = null;
}

async function loadPrivateMessages(append) {
    if (!selectedUserId || isLoadingPrivate) return;
    const requestedUserId = selectedUserId;
    isLoadingPrivate = true;
    try {
        const url = append && lastPrivateMessageId > 0
            ? `/API/private_messages_get.php?with=${selectedUserId}&since_id=${lastPrivateMessageId}`
            : `/API/private_messages_get.php?with=${selectedUserId}&limit=100`;
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        if (requestedUserId !== selectedUserId) return;
        if (!res.ok) throw new Error(data.error || 'Erreur');
        if (data.ok && data.messages) {
            if (data.messages.length > 0) renderPrivateMessages(data.messages, append);
            else if (!append) {
                privateMessagesContainer.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
            }
        }
    } catch (e) {
        if (requestedUserId === selectedUserId) {
            privateMessagesContainer.innerHTML = '<div class="chatroom-loading">Erreur de chargement</div>';
        }
    } finally {
        isLoadingPrivate = false;
    }
}

async function sendPrivateMessage() {
    const text = messageInput.value.trim();
    const hasImage = selectedImagePrivate && selectedImagePrivate instanceof File;
    if (!text && !hasImage) return;
    if (text && text.length > CONFIG.maxMessageLength) return;
    if (!selectedUserId || isSendingPrivate) return;

    isSendingPrivate = true;
    sendButton.disabled = true;
    const originalMessage = text;
    const originalImage = hasImage ? selectedImagePrivate : null;

    const tempMsg = { id: 'temp_' + Date.now(), message: text || '(image)', date_envoi: new Date().toISOString(), is_me: true, sending: true, image_path: hasImage ? URL.createObjectURL(originalImage) : null };
    renderPrivateMessages([tempMsg], true);

    if (imagePreview.src && imagePreview.src.startsWith('blob:')) URL.revokeObjectURL(imagePreview.src);
    messageInput.value = '';
    selectedImagePrivate = null;
    imagePreviewContainer.style.display = 'none';

    try {
        let imagePath = null;
        if (hasImage && originalImage instanceof File) {
            const formData = new FormData();
            formData.append('image', originalImage);
            formData.append('csrf_token', CONFIG.csrfToken);
            const upRes = await fetch('/API/chatroom_upload_image.php', { method: 'POST', body: formData, credentials: 'include' });
            if (!upRes.ok) {
                const err = await upRes.json();
                throw new Error(err.error || 'Erreur upload');
            }
            const upData = await upRes.json();
            if (upData.ok && upData.image_path) imagePath = upData.image_path;
        }

        const res = await fetch('/API/private_messages_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ csrf_token: CONFIG.csrfToken, id_receiver: selectedUserId, message: originalMessage || '', image_path: imagePath || null }),
            credentials: 'include'
        });
        const data = await res.json();

        const tempEl = privateMessagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();

        if (res.ok && data.ok && data.message) {
            renderPrivateMessages([data.message], true);
            lastPrivateMessageId = Math.max(lastPrivateMessageId, data.message.id);
        } else {
            throw new Error(data.error || 'Erreur envoi');
        }
    } catch (e) {
        const tempEl = privateMessagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();
        alert('Erreur : ' + e.message);
        messageInput.value = originalMessage;
        if (originalImage) {
            selectedImagePrivate = originalImage;
            imagePreview.src = URL.createObjectURL(originalImage);
            imagePreviewContainer.style.display = 'flex';
        }
    } finally {
        isSendingPrivate = false;
        sendButton.disabled = false;
        messageInput.focus();
    }
}

function startPrivatePolling() {
    stopPrivatePolling();
    privateRefreshIntervalId = setInterval(() => {
        if (selectedUserId && !isLoadingPrivate) loadPrivateMessages(true);
    }, 3000);
}

function stopPrivatePolling() {
    clearInterval(privateRefreshIntervalId);
    privateRefreshIntervalId = null;
}

// === Ajustement textarea ===
function adjustTextareaHeight(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 80) + 'px';
}

// === Lightbox images ===
function openImageLightbox(src) {
    const lb = document.createElement('div');
    lb.className = 'image-lightbox';
    lb.innerHTML = `<div class="lightbox-backdrop" onclick="closeImageLightbox()"></div><div class="lightbox-content"><button class="lightbox-close" onclick="closeImageLightbox()">✕</button><img src="${escapeHtml(src)}" alt="Image" class="lightbox-image"></div>`;
    document.body.appendChild(lb);
    document.body.style.overflow = 'hidden';
    document.addEventListener('keydown', function esc(e) { if (e.key === 'Escape') { closeImageLightbox(); document.removeEventListener('keydown', esc); } });
}
function closeImageLightbox() {
    const lb = document.querySelector('.image-lightbox');
    if (lb) { lb.remove(); document.body.style.overflow = ''; }
}
window.openImageLightbox = openImageLightbox;
window.closeImageLightbox = closeImageLightbox;

// === Event listeners ===
tabGeneral.addEventListener('click', () => switchTab('general'));
tabPrivate.addEventListener('click', () => switchTab('private'));

refreshGeneral.addEventListener('click', () => loadGeneralMessages(false));

generalImageUploadButton.addEventListener('click', () => generalImageInput.click());
generalImageInput.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (file && file.type.startsWith('image/')) {
        if (file.size > 5 * 1024 * 1024) { alert('Image trop volumineuse (max 5MB)'); return; }
        selectedImageGeneral = file;
        generalImagePreview.src = URL.createObjectURL(file);
        generalImagePreviewContainer.style.display = 'flex';
    }
    e.target.value = '';
});
generalRemoveImagePreview.addEventListener('click', () => {
    if (generalImagePreview.src?.startsWith('blob:')) URL.revokeObjectURL(generalImagePreview.src);
    selectedImageGeneral = null;
    generalImagePreviewContainer.style.display = 'none';
    generalImageInput.value = '';
});

generalMessageInput.addEventListener('input', () => adjustTextareaHeight(generalMessageInput));
generalMessageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendGeneralMessage(); }
});
generalSendButton.addEventListener('click', sendGeneralMessage);

userSearch.addEventListener('input', () => loadUsers(userSearch.value.trim()));
imageUploadButton.addEventListener('click', () => imageInput.click());
imageInput.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (file && file.type.startsWith('image/')) {
        if (file.size > 5 * 1024 * 1024) { alert('Image trop volumineuse (max 5MB)'); return; }
        selectedImagePrivate = file;
        imagePreview.src = URL.createObjectURL(file);
        imagePreviewContainer.style.display = 'flex';
    }
    e.target.value = '';
});
removeImagePreview.addEventListener('click', () => {
    if (imagePreview.src?.startsWith('blob:')) URL.revokeObjectURL(imagePreview.src);
    selectedImagePrivate = null;
    imagePreviewContainer.style.display = 'none';
    imageInput.value = '';
});

document.addEventListener('paste', (e) => {
    const target = e.target;
    const isGeneral = target.id === 'generalMessageInput' || target.closest('#generalInputContainer');
    const isPrivate = target.id === 'messageInput' || target.closest('#inputContainer');
    if (!isGeneral && !isPrivate) return;
    const items = e.clipboardData?.items;
    if (!items) return;
    for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type.startsWith('image/')) {
            e.preventDefault();
            const file = items[i].getAsFile();
            if (file && file.size > 0 && file.size <= 5 * 1024 * 1024) {
                if (isGeneral) {
                    selectedImageGeneral = file;
                    generalImagePreview.src = URL.createObjectURL(file);
                    generalImagePreviewContainer.style.display = 'flex';
                } else {
                    selectedImagePrivate = file;
                    imagePreview.src = URL.createObjectURL(file);
                    imagePreviewContainer.style.display = 'flex';
                }
            }
            break;
        }
    }
});

messageInput.addEventListener('input', () => adjustTextareaHeight(messageInput));
messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendPrivateMessage(); }
});
sendButton.addEventListener('click', sendPrivateMessage);

// === Init ===
async function init() {
    loadGeneralMessages(false);
    startGeneralPolling();
    await loadUsers();
}
init();

window.addEventListener('beforeunload', () => {
    stopGeneralPolling();
    stopPrivatePolling();
    stopOnlineStatusPolling();
});
</script>
</body>
</html>
