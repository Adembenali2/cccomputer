<?php
// /public/messagerie.php
// Chatroom Globale - Interface moderne avec mentions et photos
// Tous les utilisateurs connectés peuvent discuter ensemble en temps réel
// Les messages sont automatiquement supprimés après 24h

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
    <style>
        .image-preview-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            margin-bottom: 0.5rem;
        }
        .image-preview {
            max-width: 100px;
            max-height: 100px;
            border-radius: var(--radius-sm);
            object-fit: cover;
        }
        .image-preview-remove {
            background: var(--bg-tertiary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 0.25rem 0.5rem;
            cursor: pointer;
            font-size: 0.85rem;
        }
        .message-image {
            max-width: 100%;
            max-height: 400px;
            border-radius: var(--radius-md);
            margin-top: 0.5rem;
            cursor: pointer;
            object-fit: contain;
        }
        .image-upload-btn {
            background: var(--bg-tertiary);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-right: 0.5rem;
        }
        .image-upload-btn:hover {
            background: var(--bg-primary);
            border-color: var(--accent-primary);
        }
        #imageInput {
            display: none;
        }
    </style>
</head>
<body class="page-maps page-chatroom">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">💬 Chatroom Globale</h1>
        <p class="page-sub">
            Discutez en temps réel avec tous vos collègues connectés. Mentionnez des utilisateurs avec @nom ou envoyez des photos.
            <br><small style="color: var(--text-secondary);">Les messages sont automatiquement supprimés après 24h.</small>
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
        
        <!-- Zone d'erreur de debug (masquée par défaut) -->
        <div id="debugErrorPanel" style="display: none; position: fixed; top: 10px; right: 10px; background: #ff4444; color: white; padding: 15px; border-radius: 8px; max-width: 500px; z-index: 10000; box-shadow: 0 4px 6px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <strong>🔴 Erreur Debug</strong>
                <button onclick="document.getElementById('debugErrorPanel').style.display='none'" style="background: rgba(255,255,255,0.2); border: none; color: white; padding: 5px 10px; border-radius: 4px; cursor: pointer;">✕</button>
            </div>
            <div id="debugErrorContent" style="font-family: monospace; font-size: 0.85em; white-space: pre-wrap; max-height: 300px; overflow-y: auto;"></div>
        </div>

        <!-- Barre de saisie (fixe en bas) -->
        <div class="chatroom-input-container">
            <div id="imagePreviewContainer" class="image-preview-container" style="display:none;">
                <img id="imagePreview" class="image-preview" src="" alt="Aperçu">
                <button type="button" id="removeImageBtn" class="image-preview-remove">✕ Supprimer</button>
            </div>
            <div class="chatroom-input-wrapper">
                <textarea 
                    id="messageInput" 
                    class="chatroom-input" 
                    placeholder="Tapez votre message... (Utilisez @ pour mentionner)"
                    rows="1"
                    maxlength="5000"></textarea>
                <div id="mentionSuggestions" class="chatroom-mention-suggestions"></div>
            </div>
            <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp">
            <button 
                type="button" 
                id="imageUploadBtn" 
                class="image-upload-btn" 
                title="Envoyer une photo"
                aria-label="Envoyer une photo">
                📷
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
const mentionSuggestions = document.getElementById('mentionSuggestions');
const imageInput = document.getElementById('imageInput');
const imageUploadBtn = document.getElementById('imageUploadBtn');
const imagePreviewContainer = document.getElementById('imagePreviewContainer');
const imagePreview = document.getElementById('imagePreview');
const removeImageBtn = document.getElementById('removeImageBtn');

// ============================================
// Variables d'état
// ============================================
let lastMessageId = 0;
let isLoading = false;
let isSending = false;
let autoScrollEnabled = true;
let refreshIntervalId = null;
let mentionSearchTimeout = null;
let mentionSearchIndex = -1;
let mentionSuggestionsList = [];
let allUsers = []; // Cache des utilisateurs pour les mentions
let selectedImageFile = null;

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

function detectMentions(text) {
    const mentionRegex = /@([^\s@]+)/g;
    const mentions = [];
    let match;
    while ((match = mentionRegex.exec(text)) !== null) {
        mentions.push(match[1]);
    }
    return mentions;
}

async function extractMentionIds(mentions) {
    if (mentions.length === 0) return [];
    
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

function formatMessageContent(message, mentions = []) {
    let content = escapeHtml(message);
    
    if (mentions.length > 0) {
        mentions.forEach(mentionName => {
            const regex = new RegExp(`@${escapeHtml(mentionName).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}`, 'gi');
            content = content.replace(regex, `<span class="mention">@${escapeHtml(mentionName)}</span>`);
        });
    }
    
    return content;
}

// ============================================
// Gestion des images
// ============================================
imageUploadBtn.addEventListener('click', () => {
    imageInput.click();
});

imageInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;
    
    // Vérifier le type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
        alert('Type de fichier non autorisé. Formats acceptés: JPEG, PNG, GIF, WebP');
        return;
    }
    
    // Vérifier la taille (max 5MB)
    if (file.size > 5 * 1024 * 1024) {
        alert('Fichier trop volumineux (max 5MB)');
        return;
    }
    
    selectedImageFile = file;
    
    // Afficher l'aperçu
    const reader = new FileReader();
    reader.onload = (e) => {
        imagePreview.src = e.target.result;
        imagePreviewContainer.style.display = 'flex';
    };
    reader.readAsDataURL(file);
});

removeImageBtn.addEventListener('click', () => {
    selectedImageFile = null;
    imageInput.value = '';
    imagePreviewContainer.style.display = 'none';
    imagePreview.src = '';
});

// ============================================
// Affichage des messages
// ============================================
function renderMessage(message) {
    const isMe = message.is_me;
    const messageClass = isMe ? 'message-me' : 'message-other';
    const authorName = isMe ? 'Moi' : escapeHtml((message.user_prenom || '') + ' ' + (message.user_nom || ''));
    const userInfo = isMe ? '' : `<span class="message-author">${authorName}</span>`;
    
    let messageContent = '';
    if (message.message) {
        messageContent = `<p class="message-content">${formatMessageContent(message.message, message.mentions || [])}</p>`;
    }
    
    let imageHtml = '';
    if (message.image_path) {
        imageHtml = `<img src="${escapeHtml(message.image_path)}" alt="Image" class="message-image" onclick="window.open('${escapeHtml(message.image_path)}', '_blank')">`;
    }
    
    const messageHtml = `
        <div class="chatroom-message ${messageClass}" data-message-id="${message.id}">
            <div class="message-bubble">
                ${messageContent}
                ${imageHtml}
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
// Fonction helper pour afficher les erreurs de debug
// ============================================
function displayDebugError(error, context, response = null) {
    console.group('🔴 ERREUR DEBUG - ' + context);
    console.error('Message:', error.message);
    console.error('Erreur complète:', error);
    
    if (response) {
        console.error('Status HTTP:', response.status, response.statusText);
        console.error('URL:', response.url);
    }
    
    let debugInfo = `ERREUR: ${context}\n\nMessage: ${error.message}\n`;
    
    // Essayer de récupérer les détails depuis la réponse
    if (response) {
        response.clone().json().then(data => {
            console.error('Réponse JSON:', data);
            debugInfo += `\nStatus HTTP: ${response.status} ${response.statusText}\n`;
            debugInfo += `URL: ${response.url}\n`;
            
            if (data.debug) {
                console.group('📋 Détails de débogage:');
                debugInfo += `\n=== DÉTAILS DE DÉBOGAGE ===\n`;
                debugInfo += `Message: ${data.debug.message || data.error || 'N/A'}\n`;
                debugInfo += `Type: ${data.debug.type || 'N/A'}\n`;
                debugInfo += `Fichier: ${data.debug.file || 'N/A'}\n`;
                debugInfo += `Ligne: ${data.debug.line || 'N/A'}\n`;
                if (data.debug.code) {
                    debugInfo += `Code erreur: ${data.debug.code}\n`;
                    console.error('Code erreur:', data.debug.code);
                }
                if (data.debug.sql_state) {
                    debugInfo += `SQL State: ${data.debug.sql_state}\n`;
                    console.error('SQL State:', data.debug.sql_state);
                }
                if (data.debug.driver_code) {
                    debugInfo += `Driver Code: ${data.debug.driver_code}\n`;
                    console.error('Driver Code:', data.debug.driver_code);
                }
                console.error('Message:', data.debug.message || data.error);
                console.error('Type:', data.debug.type);
                console.error('Fichier:', data.debug.file);
                console.error('Ligne:', data.debug.line);
                console.groupEnd();
            }
            
            // Afficher dans le panneau de debug
            const panel = document.getElementById('debugErrorPanel');
            const content = document.getElementById('debugErrorContent');
            if (panel && content) {
                content.textContent = debugInfo;
                panel.style.display = 'block';
            }
        }).catch(() => {
            response.clone().text().then(text => {
                console.error('Réponse texte:', text.substring(0, 500));
                debugInfo += `\nRéponse (texte): ${text.substring(0, 500)}\n`;
                
                const panel = document.getElementById('debugErrorPanel');
                const content = document.getElementById('debugErrorContent');
                if (panel && content) {
                    content.textContent = debugInfo;
                    panel.style.display = 'block';
                }
            });
        });
    } else {
        // Afficher dans le panneau même sans réponse
        const panel = document.getElementById('debugErrorPanel');
        const content = document.getElementById('debugErrorContent');
        if (panel && content) {
            content.textContent = debugInfo;
            panel.style.display = 'block';
        }
    }
    
    console.groupEnd();
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
        
        // Essayer de récupérer le JSON même en cas d'erreur
        let data;
        try {
            data = await response.json();
        } catch (e) {
            const text = await response.text();
            throw new Error(`Réponse non-JSON (${response.status}): ${text.substring(0, 200)}`);
        }
        
        if (!response.ok) {
            displayDebugError(new Error(data.error || `HTTP ${response.status}`), 'loadMessages', response);
            
            // Afficher les détails dans l'interface
            let errorMsg = data.error || `Erreur HTTP ${response.status}`;
            if (data.debug) {
                errorMsg += `\n\nDétails:\n`;
                errorMsg += `- Message: ${data.debug.message || 'N/A'}\n`;
                errorMsg += `- Fichier: ${data.debug.file || 'N/A'}\n`;
                errorMsg += `- Ligne: ${data.debug.line || 'N/A'}\n`;
                if (data.debug.code) errorMsg += `- Code: ${data.debug.code}\n`;
            }
            
            if (loadingIndicator) {
                loadingIndicator.innerHTML = `<div class="chatroom-loading" style="color: red;">
                    <strong>Erreur de chargement</strong><br>
                    <small style="font-family: monospace; font-size: 0.8em; white-space: pre-wrap;">${escapeHtml(errorMsg)}</small>
                </div>`;
            }
            throw new Error(errorMsg);
        }
        
        if (data.ok && data.messages) {
            renderMessages(data.messages, append);
        } else if (!data.ok) {
            throw new Error(data.error || 'Erreur inconnue');
        }
    } catch (error) {
        console.error('Erreur chargement messages:', error);
        displayDebugError(error, 'loadMessages');
        
        if (loadingIndicator && !loadingIndicator.innerHTML.includes('Erreur')) {
            loadingIndicator.innerHTML = `<div class="chatroom-loading" style="color: red;">
                <strong>Erreur de chargement</strong><br>
                <small>${escapeHtml(error.message)}</small><br>
                <small style="font-size: 0.7em; color: #666;">Vérifiez la console pour plus de détails</small>
            </div>`;
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
    
    // Le message ou l'image doit être présent
    if (!messageText && !selectedImageFile) {
        return;
    }
    
    if (messageText.length > CONFIG.maxMessageLength) {
        alert(`Le message est trop long (max ${CONFIG.maxMessageLength} caractères)`);
        return;
    }
    
    if (isSending) return;
    
    isSending = true;
    sendButton.disabled = true;
    const originalMessage = messageText;
    const originalImageFile = selectedImageFile;
    
    // Extraire les mentions
    const mentionNames = detectMentions(messageText);
    const mentionIds = await extractMentionIds(mentionNames);
    
    // Réinitialiser l'interface
    messageInput.value = '';
    adjustTextareaHeight();
    selectedImageFile = null;
    imageInput.value = '';
    imagePreviewContainer.style.display = 'none';
    imagePreview.src = '';
    
    try {
        let imagePath = null;
        
        // Upload de l'image si présente
        if (originalImageFile) {
            const formData = new FormData();
            formData.append('image', originalImageFile);
            
            const uploadResponse = await fetch('/API/chatroom_upload_image.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!uploadResponse.ok) {
                let errorData;
                try {
                    errorData = await uploadResponse.json();
                } catch (e) {
                    const text = await uploadResponse.text();
                    throw new Error(`Réponse non-JSON (${uploadResponse.status}): ${text.substring(0, 200)}`);
                }
                
                displayDebugError(new Error(errorData.error || `HTTP ${uploadResponse.status}`), 'uploadImage', uploadResponse);
                throw new Error(errorData.error || 'Erreur lors de l\'upload de l\'image');
            }
            
            const uploadData = await uploadResponse.json();
            if (uploadData.ok && uploadData.image_path) {
                imagePath = uploadData.image_path;
            } else {
                throw new Error('Erreur lors de l\'upload de l\'image');
            }
        }
        
        // Envoyer le message
        const response = await fetch('/API/chatroom_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: CONFIG.csrfToken,
                message: originalMessage || '',
                mentions: mentionIds,
                image_path: imagePath
            }),
            credentials: 'same-origin'
        });
        
        // Essayer de récupérer le JSON même en cas d'erreur
        let data;
        try {
            data = await response.json();
        } catch (e) {
            const text = await response.text();
            throw new Error(`Réponse non-JSON (${response.status}): ${text.substring(0, 200)}`);
        }
        
        if (!response.ok) {
            displayDebugError(new Error(data.error || `HTTP ${response.status}`), 'sendMessage', response);
            
            // Construire un message d'erreur détaillé
            let errorMsg = data.error || `Erreur HTTP ${response.status}`;
            if (data.debug) {
                errorMsg += `\n\nDétails de débogage:\n`;
                errorMsg += `- Message: ${data.debug.message || 'N/A'}\n`;
                errorMsg += `- Type: ${data.debug.type || 'N/A'}\n`;
                errorMsg += `- Fichier: ${data.debug.file || 'N/A'}\n`;
                errorMsg += `- Ligne: ${data.debug.line || 'N/A'}\n`;
                if (data.debug.code) errorMsg += `- Code erreur: ${data.debug.code}\n`;
                if (data.debug.sql_state) errorMsg += `- SQL State: ${data.debug.sql_state}\n`;
                if (data.debug.driver_code) errorMsg += `- Driver Code: ${data.debug.driver_code}\n`;
            }
            
            throw new Error(errorMsg);
        }
        
        if (data.ok && data.message) {
            renderMessages([data.message], true);
            setTimeout(() => loadMessages(true), 500);
        } else {
            throw new Error(data.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Erreur envoi message:', error);
        displayDebugError(error, 'sendMessage');
        
        // Afficher une alerte avec les détails
        let alertMsg = 'Erreur lors de l\'envoi du message:\n\n' + error.message;
        alert(alertMsg);
        messageInput.value = originalMessage;
        adjustTextareaHeight();
        if (originalImageFile) {
            selectedImageFile = originalImageFile;
            const reader = new FileReader();
            reader.onload = (e) => {
                imagePreview.src = e.target.result;
                imagePreviewContainer.style.display = 'flex';
            };
            reader.readAsDataURL(originalImageFile);
        }
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
