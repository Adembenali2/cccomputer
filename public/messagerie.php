<?php
// /public/messagerie.php
// Messagerie privée utilisateur à utilisateur - Messages conservés 24h puis suppression automatique

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('messagerie', []);
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();
$CSRF = ensureCsrfToken();
$currentUserId = (int)($_SESSION['user_id'] ?? 0);
$currentUserNom = $_SESSION['user_nom'] ?? '';
$currentUserPrenom = $_SESSION['user_prenom'] ?? '';
$currentUserName = trim($currentUserPrenom . ' ' . $currentUserNom);

$tableExists = false;
try {
    $checkTable = $pdo->prepare("SHOW TABLES LIKE 'private_messages'");
    $checkTable->execute();
    $tableExists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur vérification table: ' . $e->getMessage());
}

// Purge des messages privés de plus de 24h (à chaque chargement de page)
if ($tableExists) {
    try {
        $stmt = $pdo->prepare("SELECT id, image_path FROM private_messages WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $stmt->execute();
        $oldMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($oldMessages as $msg) {
            if (!empty($msg['image_path'])) {
                $imagePath = dirname(__DIR__) . $msg['image_path'];
                if (file_exists($imagePath)) @unlink($imagePath);
            }
            $pdo->prepare("DELETE FROM private_messages WHERE id = ?")->execute([$msg['id']]);
        }
    } catch (PDOException $e) {
        error_log('messagerie.php - Purge: ' . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Messagerie privée - CCComputer</title>
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
        <div class="messagerie-tabs" role="tablist">
            <button type="button" class="messagerie-tab active" data-mode="private" role="tab" aria-selected="true" id="tabPrivate">
                Messages privés
            </button>
            <button type="button" class="messagerie-tab" data-mode="general" role="tab" aria-selected="false" id="tabGeneral">
                Chat général
                <span class="messagerie-tab-badge" id="generalBadge" style="display: none;">0</span>
            </button>
        </div>
    </header>

    <?php if (!$tableExists): ?>
        <div class="maps-message alert" style="margin: 1rem 0;">
            <strong>⚠️ Attention :</strong> La table <code>private_messages</code> n'existe pas.
            Exécutez la migration : <code>sql/migration_create_private_messages.sql</code>
        </div>
    <?php endif; ?>

    <div class="chatroom-container">
        <!-- Panneau Chat général (visible uniquement quand onglet Chat général actif) -->
        <div class="general-chat-panel" id="generalChatPanel" style="display: none;">
            <div class="chatroom-header general-chat-header">
                <h2>Chat général</h2>
                <span class="general-chat-warning" aria-live="polite">Visible par tous</span>
                <div class="general-chat-actions">
                    <button type="button" class="btn-refresh" id="btnRefresh" title="Actualiser les messages" aria-label="Actualiser">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        Actualiser
                    </button>
                </div>
            </div>
            <div class="chatroom-messages" id="generalMessages" role="log" aria-live="polite">
                <div class="chatroom-loading" id="generalLoading">Chargement...</div>
            </div>
            <div class="chatroom-input-container">
                <button type="button" class="image-upload-btn" id="generalImageBtn" title="Ajouter une image" aria-label="Ajouter une image">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </button>
                <input type="file" id="generalImageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                <div class="chatroom-input-wrapper">
                    <div id="generalImagePreview" class="image-preview-container" style="display: none;">
                        <img id="generalImageImg" class="image-preview" alt="Aperçu">
                        <button type="button" id="generalImageRemove" class="image-preview-remove">✕</button>
                    </div>
                    <textarea id="generalMessageInput" class="chatroom-input" placeholder="Message au chat général..." rows="1" maxlength="5000" aria-label="Message"></textarea>
                </div>
                <button type="button" class="chatroom-send-btn" id="generalSendBtn" title="Envoyer" aria-label="Envoyer">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </div>
        </div>

        <!-- Panneau Messages privés (visible par défaut) -->
        <div class="private-messaging-layout" id="privatePanel">
        <!-- Sidebar : liste des utilisateurs -->
        <aside class="private-sidebar" id="usersSidebar">
            <div class="private-sidebar-header">
                <h3>Conversations</h3>
                <input type="text" id="userSearch" class="private-search" placeholder="Rechercher un utilisateur..." aria-label="Rechercher">
            </div>
            <div class="private-users-list" id="usersList">
                <div class="chatroom-loading" id="usersLoading">Chargement...</div>
            </div>
        </aside>

        <!-- Zone principale : conversation -->
        <div class="private-main">
            <div class="private-conversation-placeholder" id="conversationPlaceholder">
                <p>Sélectionnez un utilisateur pour démarrer une conversation.</p>
                <small>Les messages sont conservés 24 heures.</small>
            </div>
            <div class="private-conversation-panel" id="conversationPanel" style="display: none;">
                <div class="chatroom-header private-conversation-header">
                    <h2 id="conversationTitle">Conversation</h2>
                    <span id="conversationStatus" class="conversation-status" aria-live="polite">
                        <span class="status-dot" id="statusDot"></span>
                        <span id="statusText">—</span>
                    </span>
                </div>
                <div class="chatroom-messages" id="chatroomMessages" role="log" aria-live="polite">
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

    <!-- Toast notification nouveaux messages -->
    <div id="newMessageToast" class="messagerie-toast" role="status" aria-live="polite" style="display: none;">
        Nouveau message reçu
    </div>
</main>

<script>
const CONFIG = {
    currentUserId: <?= $currentUserId ?>,
    currentUserName: <?= json_encode($currentUserName) ?>,
    csrfToken: <?= json_encode($CSRF) ?>,
    maxMessageLength: 5000
};

const messagesContainer = document.getElementById('chatroomMessages');
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
const inputContainer = document.getElementById('inputContainer');

const generalMessagesContainer = document.getElementById('generalMessages');
const generalLoading = document.getElementById('generalLoading');
const generalMessageInput = document.getElementById('generalMessageInput');
const generalSendBtn = document.getElementById('generalSendBtn');
const generalImageBtn = document.getElementById('generalImageBtn');
const generalImageInput = document.getElementById('generalImageInput');
const generalImagePreview = document.getElementById('generalImagePreview');
const generalImageImg = document.getElementById('generalImageImg');
const generalImageRemove = document.getElementById('generalImageRemove');
const generalChatPanel = document.getElementById('generalChatPanel');
const privatePanel = document.getElementById('privatePanel');
const tabGeneral = document.getElementById('tabGeneral');
const tabPrivate = document.getElementById('tabPrivate');
const btnRefresh = document.getElementById('btnRefresh');
const generalBadge = document.getElementById('generalBadge');
const newMessageToast = document.getElementById('newMessageToast');

let selectedUserId = null;
let selectedUserName = '';
let lastMessageId = 0;
let isLoading = false;
let isSending = false;
let selectedImage = null;
let refreshIntervalId = null;
let lastRenderedDateStr = null;

let currentMode = 'private';
let generalLastMessageId = 0;
let generalLastRenderedDateStr = null;
let generalIsLoading = false;
let generalIsSending = false;
let generalSelectedImage = null;
let generalPollIntervalId = null;
let generalUnreadCount = 0;
let onlineStatusIntervalId = null;

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

function renderMessage(msg) {
    if (!msg || typeof msg !== 'object') return '';
    const isMe = msg.is_me === true || msg.is_me === 1;
    const messageClass = isMe ? 'message-me' : 'message-other';
    const authorName = isMe ? 'Moi' : escapeHtml((msg.user_prenom || '') + ' ' + (msg.user_nom || ''));
    const userInfo = isMe ? '' : `<span class="message-author">${authorName}</span>`;
    let avatarInitials = isMe ? getInitials((CONFIG.currentUserName || '').split(' ')[0], (CONFIG.currentUserName || '').split(' ')[1]) : getInitials(msg.user_prenom || '', msg.user_nom || '');
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
    let label = msgDate.getTime() === today.getTime() ? 'Aujourd\'hui' : (msgDate.getTime() === today.getTime() - 86400000 ? 'Hier' : date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' }));
    const sep = document.createElement('div');
    sep.className = 'message-date-separator';
    sep.innerHTML = `<span>${label}</span>`;
    return sep;
}

function renderMessages(messages, append, container, loadingEl, state) {
    const c = container || messagesContainer;
    const le = loadingEl || loadingIndicator;
    const st = state || { lastId: lastMessageId, lastDate: lastRenderedDateStr };
    if (!messages || messages.length === 0) {
        if (!append && le) { le.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>'; le.style.display = 'block'; }
        return;
    }
    if (le) le.style.display = 'none';
    const wasAtBottom = c.scrollHeight - c.scrollTop <= c.clientHeight + 50;
    if (append) {
        messages.forEach(msg => {
            if (!msg || msg.id === undefined) return;
            if (c.querySelector(`[data-message-id="${msg.id}"]`)) return;
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (st.lastDate !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                st.lastDate = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg);
            div.firstElementChild.classList.add('message-new');
            c.appendChild(div.firstElementChild);
        });
        st.lastId = Math.max(st.lastId, ...messages.map(m => m.id || 0));
        if (wasAtBottom) c.scrollTo({ top: c.scrollHeight, behavior: 'smooth' });
    } else {
        c.innerHTML = '';
        st.lastDate = null;
        messages.forEach(msg => {
            const msgDateStr = new Date(msg.date_envoi).toDateString();
            if (st.lastDate !== msgDateStr) {
                c.appendChild(addDateSeparator(msg.date_envoi));
                st.lastDate = msgDateStr;
            }
            const div = document.createElement('div');
            div.innerHTML = renderMessage(msg);
            c.appendChild(div.firstElementChild);
        });
        st.lastId = messages.length ? Math.max(...messages.map(m => m.id)) : 0;
        c.scrollTo({ top: c.scrollHeight, behavior: 'auto' });
    }
    if (!state) {
        lastMessageId = st.lastId;
        lastRenderedDateStr = st.lastDate;
    }
}

const generalState = { lastId: 0, lastDate: null };

async function loadGeneralMessages(append) {
    if (generalIsLoading) return;
    generalIsLoading = true;
    try {
        const sinceId = append && generalState.lastId > 0 ? generalState.lastId : 0;
        const url = sinceId > 0
            ? `/API/chatroom_get.php?since_id=${sinceId}&limit=50`
            : `/API/chatroom_get.php?limit=50`;
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Erreur');
        if (data.ok && data.messages) {
            const isViewingGeneral = currentMode === 'general';
            const fromOthers = data.messages.filter(m => !m.is_me).length;
            renderMessages(data.messages, append, generalMessagesContainer, generalLoading, generalState);
            if (append && fromOthers > 0 && isViewingGeneral) {
                showToast();
            } else if (append && fromOthers > 0 && !isViewingGeneral) {
                generalUnreadCount += fromOthers;
                updateGeneralBadge();
            }
            if (!append && generalLoading) generalLoading.style.display = 'none';
        } else if (!append) {
            generalMessagesContainer.innerHTML = '';
            if (generalLoading) {
                generalLoading.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
                generalLoading.style.display = 'block';
                generalLoading.className = 'chatroom-loading';
                generalMessagesContainer.appendChild(generalLoading);
            }
        }
    } catch (e) {
        generalMessagesContainer.innerHTML = '';
        if (generalLoading) {
            generalLoading.innerHTML = 'Erreur de chargement';
            generalLoading.style.display = 'block';
            generalLoading.className = 'chatroom-loading';
            generalMessagesContainer.appendChild(generalLoading);
        }
    } finally {
        generalIsLoading = false;
    }
}

function showToast() {
    if (!newMessageToast) return;
    newMessageToast.style.display = 'flex';
    newMessageToast.classList.add('messagerie-toast-visible');
    setTimeout(() => {
        newMessageToast.classList.remove('messagerie-toast-visible');
        setTimeout(() => { newMessageToast.style.display = 'none'; }, 300);
    }, 2500);
}

function updateGeneralBadge() {
    if (!generalBadge) return;
    if (generalUnreadCount <= 0) {
        generalBadge.style.display = 'none';
        return;
    }
    generalBadge.textContent = generalUnreadCount > 99 ? '99+' : String(generalUnreadCount);
    generalBadge.style.display = 'inline-flex';
}

async function sendGeneralMessage() {
    if (currentMode !== 'general') return;
    const text = generalMessageInput.value.trim();
    const hasImage = generalSelectedImage && generalSelectedImage instanceof File;
    if (!text && !hasImage) return;
    if (text && text.length > CONFIG.maxMessageLength) return;
    if (generalIsSending) return;

    generalIsSending = true;
    generalSendBtn.disabled = true;
    const originalMessage = text;
    const originalImage = hasImage ? generalSelectedImage : null;

    const tempMsg = { id: 'temp_' + Date.now(), message: text || '(image)', date_envoi: new Date().toISOString(), is_me: true, sending: true, image_path: hasImage ? URL.createObjectURL(originalImage) : null };
    renderMessages([tempMsg], true, generalMessagesContainer, generalLoading, generalState);

    if (generalImageImg?.src?.startsWith('blob:')) URL.revokeObjectURL(generalImageImg.src);
    generalMessageInput.value = '';
    generalSelectedImage = null;
    if (generalImagePreview) generalImagePreview.style.display = 'none';

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
            body: JSON.stringify({ csrf_token: CONFIG.csrfToken, message: originalMessage || '', mentions: [], image_path: imagePath || null }),
            credentials: 'include'
        });
        const data = await res.json();

        const tempEl = generalMessagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();

        if (res.ok && data.ok && data.message) {
            renderMessages([data.message], true, generalMessagesContainer, generalLoading, generalState);
        } else {
            throw new Error(data.error || 'Erreur envoi');
        }
    } catch (e) {
        const tempEl = generalMessagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();
        alert('Erreur : ' + e.message);
        generalMessageInput.value = originalMessage;
        if (originalImage) {
            generalSelectedImage = originalImage;
            generalImageImg.src = URL.createObjectURL(originalImage);
            generalImagePreview.style.display = 'flex';
        }
    } finally {
        generalIsSending = false;
        generalSendBtn.disabled = false;
        generalMessageInput.focus();
    }
}

function setMode(mode) {
    currentMode = mode;
    tabGeneral.classList.toggle('active', mode === 'general');
    tabGeneral.setAttribute('aria-selected', mode === 'general');
    tabPrivate.classList.toggle('active', mode === 'private');
    tabPrivate.setAttribute('aria-selected', mode === 'private');
    generalChatPanel.style.display = mode === 'general' ? 'flex' : 'none';
    privatePanel.style.display = mode === 'private' ? 'flex' : 'none';

    if (mode === 'general') {
        generalUnreadCount = 0;
        updateGeneralBadge();
        loadGeneralMessages(false);
        clearInterval(generalPollIntervalId);
        generalPollIntervalId = setInterval(() => { if (!generalIsLoading) loadGeneralMessages(true); }, 2000);
    } else {
        clearInterval(generalPollIntervalId);
        generalPollIntervalId = null;
    }

    if (mode === 'private') {
        if (selectedUserId) { loadMessages(false); startOnlineStatusPolling(); }
        if (!refreshIntervalId) refreshIntervalId = setInterval(() => { if (selectedUserId && !isLoading) loadMessages(true); }, 3000);
    } else {
        stopOnlineStatusPolling();
        if (refreshIntervalId) { clearInterval(refreshIntervalId); refreshIntervalId = null; }
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
            const onlineClass = isOnline ? ' user-online' : '';
            item.innerHTML = `<span class="private-user-avatar${onlineClass}">${getInitials(u.prenom, u.nom)}</span><span class="private-user-status-dot ${isOnline ? 'status-online' : 'status-offline'}" aria-label="${isOnline ? 'En ligne' : 'Hors ligne'}"></span><span class="private-user-name">${escapeHtml(u.display_name)}</span>`;
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
    lastMessageId = 0;
    loadMessages(false);
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
        if (selectedUserId && currentMode === 'private') {
            fetchOnlineStatus(selectedUserId);
            heartbeatPresence();
        }
    }, 30000);
}

function stopOnlineStatusPolling() {
    clearInterval(onlineStatusIntervalId);
    onlineStatusIntervalId = null;
}

async function loadMessages(append) {
    if (!selectedUserId || isLoading) return;
    isLoading = true;
    try {
        const url = append && lastMessageId > 0
            ? `/API/private_messages_get.php?with=${selectedUserId}&since_id=${lastMessageId}`
            : `/API/private_messages_get.php?with=${selectedUserId}&limit=100`;
        const res = await fetch(url, { credentials: 'include' });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Erreur');
        if (data.ok && data.messages) {
            if (data.messages.length > 0) renderMessages(data.messages, append);
            else if (!append) {
                loadingIndicator.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
                loadingIndicator.style.display = 'block';
            }
        }
    } catch (e) {
        if (loadingIndicator) loadingIndicator.innerHTML = '<div class="chatroom-loading">Erreur de chargement</div>';
    } finally {
        isLoading = false;
    }
}

async function sendMessage() {
    if (currentMode !== 'private') return;
    const text = messageInput.value.trim();
    const hasImage = selectedImage && selectedImage instanceof File;
    if (!text && !hasImage) return;
    if (text && text.length > CONFIG.maxMessageLength) return;
    if (!selectedUserId || isSending) return;

    isSending = true;
    sendButton.disabled = true;
    const originalMessage = text;
    const originalImage = hasImage ? selectedImage : null;

    const tempMsg = { id: 'temp_' + Date.now(), message: text || '(image)', date_envoi: new Date().toISOString(), is_me: true, sending: true, image_path: hasImage ? URL.createObjectURL(originalImage) : null };
    renderMessages([tempMsg], true);

    if (imagePreview.src && imagePreview.src.startsWith('blob:')) URL.revokeObjectURL(imagePreview.src);
    messageInput.value = '';
    selectedImage = null;
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

        const tempEl = messagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();

        if (res.ok && data.ok && data.message) {
            renderMessages([data.message], true);
            lastMessageId = Math.max(lastMessageId, data.message.id);
        } else {
            throw new Error(data.error || 'Erreur envoi');
        }
    } catch (e) {
        const tempEl = messagesContainer.querySelector(`[data-message-id="${tempMsg.id}"]`);
        if (tempEl) tempEl.remove();
        alert('Erreur : ' + e.message);
        messageInput.value = originalMessage;
        if (originalImage) {
            selectedImage = originalImage;
            imagePreview.src = URL.createObjectURL(originalImage);
            imagePreviewContainer.style.display = 'flex';
        }
    } finally {
        isSending = false;
        sendButton.disabled = false;
        messageInput.focus();
    }
}

function adjustTextareaHeight() {
    messageInput.style.height = 'auto';
    messageInput.style.height = Math.min(messageInput.scrollHeight, 80) + 'px';
}

tabGeneral.addEventListener('click', () => setMode('general'));
tabPrivate.addEventListener('click', () => setMode('private'));

btnRefresh.addEventListener('click', () => {
    if (currentMode === 'general') loadGeneralMessages(false);
});

generalSendBtn.addEventListener('click', sendGeneralMessage);
generalMessageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendGeneralMessage(); }
});
generalMessageInput.addEventListener('input', () => {
    generalMessageInput.style.height = 'auto';
    generalMessageInput.style.height = Math.min(generalMessageInput.scrollHeight, 80) + 'px';
});
generalImageBtn.addEventListener('click', () => generalImageInput.click());
generalImageInput.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (file && file.type.startsWith('image/')) {
        if (file.size > 5 * 1024 * 1024) { alert('Image trop volumineuse (max 5MB)'); return; }
        generalSelectedImage = file;
        generalImageImg.src = URL.createObjectURL(file);
        generalImagePreview.style.display = 'flex';
    }
    e.target.value = '';
});
generalImageRemove.addEventListener('click', () => {
    if (generalImageImg?.src?.startsWith('blob:')) URL.revokeObjectURL(generalImageImg.src);
    generalSelectedImage = null;
    generalImagePreview.style.display = 'none';
    generalImageInput.value = '';
});

userSearch.addEventListener('input', () => loadUsers(userSearch.value.trim()));
imageUploadButton.addEventListener('click', () => imageInput.click());
imageInput.addEventListener('change', (e) => {
    const file = e.target.files?.[0];
    if (file && file.type.startsWith('image/')) {
        if (file.size > 5 * 1024 * 1024) { alert('Image trop volumineuse (max 5MB)'); return; }
        selectedImage = file;
        imagePreview.src = URL.createObjectURL(file);
        imagePreviewContainer.style.display = 'flex';
    }
    e.target.value = '';
});
removeImagePreview.addEventListener('click', () => {
    if (imagePreview.src?.startsWith('blob:')) URL.revokeObjectURL(imagePreview.src);
    selectedImage = null;
    imagePreviewContainer.style.display = 'none';
    imageInput.value = '';
});

document.addEventListener('paste', (e) => {
    const items = e.clipboardData?.items;
    if (!items) return;
    const isGeneralFocused = document.activeElement === generalMessageInput;
    for (let i = 0; i < items.length; i++) {
        if (items[i].kind === 'file' && items[i].type.startsWith('image/')) {
            e.preventDefault();
            const file = items[i].getAsFile();
            if (file && file.size > 0 && file.size <= 5 * 1024 * 1024) {
                if (isGeneralFocused) {
                    generalSelectedImage = file;
                    generalImageImg.src = URL.createObjectURL(file);
                    generalImagePreview.style.display = 'flex';
                } else {
                    selectedImage = file;
                    imagePreview.src = URL.createObjectURL(file);
                    imagePreviewContainer.style.display = 'flex';
                }
            }
            break;
        }
    }
});

messageInput.addEventListener('input', adjustTextareaHeight);
messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
});
sendButton.addEventListener('click', sendMessage);

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

async function init() {
    await loadUsers();
    setMode('private');
}
init();
window.addEventListener('beforeunload', () => {
    clearInterval(refreshIntervalId);
    clearInterval(generalPollIntervalId);
    stopOnlineStatusPolling();
});
</script>
</body>
</html>
