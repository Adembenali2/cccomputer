<?php
// /public/messagerie.php
// Messagerie - Interface de communication en temps réel

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('messagerie', []); // Accessible à tous les utilisateurs connectés
require_once __DIR__ . '/../includes/helpers.php';

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

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
    $checkTable = $pdo->prepare("SHOW TABLES LIKE 'chatroom_messages'");
    $checkTable->execute();
    $tableExists = $checkTable->rowCount() > 0;
} catch (PDOException $e) {
    error_log('messagerie.php - Erreur vérification table: ' . $e->getMessage());
}

// Supprimer automatiquement les messages de plus de 24 heures
if ($tableExists) {
    try {
        // Vérifier si la colonne image_path existe
        $hasImagePath = false;
        try {
            $checkColumn = $pdo->prepare("
                SELECT COUNT(*) 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'chatroom_messages' 
                AND COLUMN_NAME = 'image_path'
            ");
            $checkColumn->execute();
            $hasImagePath = (int)$checkColumn->fetchColumn() > 0;
        } catch (PDOException $e) {
            // Si la vérification échoue, on continue sans image_path
        }

        // Construire la requête selon la présence de la colonne image_path
        $selectColumns = $hasImagePath ? 'id, image_path' : 'id';
        $stmt = $pdo->prepare("
            SELECT {$selectColumns}
            FROM chatroom_messages 
            WHERE date_envoi < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute();
        $oldMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $deletedCount = 0;
        $deletedImages = 0;

        foreach ($oldMessages as $msg) {
            // Supprimer l'image associée si elle existe et si la colonne existe
            if ($hasImagePath && !empty($msg['image_path'])) {
                $imagePath = dirname(__DIR__) . $msg['image_path'];
                if (file_exists($imagePath)) {
                    @unlink($imagePath);
                    $deletedImages++;
                }
            }

            // Supprimer le message
            $deleteStmt = $pdo->prepare("DELETE FROM chatroom_messages WHERE id = :id");
            $deleteStmt->execute([':id' => $msg['id']]);
            $deletedCount++;
        }

        // Supprimer aussi les notifications orphelines (associées aux messages supprimés)
        if ($deletedCount > 0) {
            try {
                $pdo->exec("
                    DELETE FROM chatroom_notifications 
                    WHERE id_message NOT IN (SELECT id FROM chatroom_messages)
                ");
            } catch (PDOException $e) {
                // Ignorer les erreurs sur la table notifications (peut ne pas exister)
                error_log('messagerie.php - Erreur nettoyage notifications: ' . $e->getMessage());
            }
        }

        // Log uniquement si des messages ont été supprimés (pour éviter de surcharger les logs)
        if ($deletedCount > 0) {
            error_log("messagerie.php - Nettoyage automatique: {$deletedCount} message(s) supprimé(s)" . ($hasImagePath ? ", {$deletedImages} image(s) supprimée(s)" : ""));
        }
    } catch (PDOException $e) {
        // Erreur silencieuse pour ne pas perturber l'utilisateur
        error_log('messagerie.php - Erreur nettoyage automatique messages: ' . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Messagerie - CCComputer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="stylesheet" href="/assets/css/main.css">
    <link rel="stylesheet" href="/assets/css/chatroom.css">
    <script src="/assets/js/api.js" defer></script>
</head>
<body class="page-maps page-chatroom">

<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<main class="page-container">
    <header class="page-header">
        <h1 class="page-title">Messagerie</h1>
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
                <h2>Messagerie</h2>
                <div class="chatroom-status">
                    <span class="chatroom-status-indicator" id="onlineIndicator"></span>
                    <span id="statusText">En ligne</span>
                </div>
            </div>
        </div>

        <!-- Zone de messages (scrollable) -->
        <div class="chatroom-messages" id="chatroomMessages" role="log" aria-live="polite" aria-label="Messages de la messagerie">
            <div class="chatroom-loading" id="loadingIndicator">
                Chargement des messages...
            </div>
        </div>
        
        <!-- Barre de saisie (fixe en bas) -->
        <div class="chatroom-input-container">
            <button 
                type="button" 
                id="imageUploadButton" 
                class="image-upload-btn" 
                title="Ajouter une image"
                aria-label="Ajouter une image">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" width="20" height="20">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </button>
            <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
            <div class="chatroom-input-wrapper">
                <div id="imagePreviewContainer" class="image-preview-container" style="display: none;">
                    <img id="imagePreview" class="image-preview" alt="Aperçu">
                    <button type="button" id="removeImagePreview" class="image-preview-remove">✕</button>
                </div>
                <textarea 
                    id="messageInput" 
                    class="chatroom-input" 
                    placeholder="Tapez votre message..."
                    rows="1"
                    maxlength="5000"
                    aria-label="Zone de saisie de message"></textarea>
                <div id="mentionSuggestions" class="chatroom-mention-suggestions" role="listbox" aria-label="Suggestions de mentions"></div>
            </div>
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
        
        <!-- Indicateur de statut de connexion -->
        <div id="connectionStatus" class="connection-status" role="status" aria-live="polite" style="display: none;">
            <span class="connection-status-indicator"></span>
            <span class="connection-status-text"></span>
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
const imageUploadButton = document.getElementById('imageUploadButton');
const imageInput = document.getElementById('imageInput');
const imagePreviewContainer = document.getElementById('imagePreviewContainer');
const imagePreview = document.getElementById('imagePreview');
const removeImagePreview = document.getElementById('removeImagePreview');
const connectionStatusEl = document.getElementById('connectionStatus');
const statusText = document.getElementById('statusText');
const onlineIndicator = document.getElementById('onlineIndicator');

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
let selectedImage = null; // Image sélectionnée pour upload
let refreshInterval = 2000; // Intervalle de rafraîchissement dynamique
let consecutiveEmptyResponses = 0; // Compteur pour backoff exponentiel
let connectionStatus = 'online'; // Statut de connexion
let pendingMessageId = null; // ID du message en cours d'envoi (pour feedback visuel)

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
    // Permettre la recherche même avec query vide (pour afficher tous les utilisateurs)
    // query peut être une chaîne vide ou undefined
    const searchQuery = query || '';
    
    try {
        // Utiliser fetch directement si apiClient n'est pas disponible
        const response = await fetch(`/API/chatroom_search_users.php?q=${encodeURIComponent(searchQuery)}&limit=10`, {
            method: 'GET',
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.ok && data.users) {
            mentionSuggestionsList = data.users;
            displayMentionSuggestions();
        } else {
            mentionSuggestionsList = [];
            mentionSuggestions.classList.remove('show');
        }
    } catch (error) {
        if (error.name !== 'AbortError') {
            // Erreur silencieuse pour la recherche (ne pas perturber l'utilisateur)
            mentionSuggestions.classList.remove('show');
        }
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
    
    // Détecter automatiquement toutes les mentions dans le texte (format @nom)
    // Cette regex détecte @ suivi d'un ou plusieurs caractères (pas d'espaces ni @)
    const mentionRegex = /@([^\s@]+)/g;
    
    // Remplacer toutes les mentions par des spans stylisés (sans le @, juste le nom)
    content = content.replace(mentionRegex, (match, mentionName) => {
        return `<span class="mention">${mentionName}</span>`;
    });
    
    return content;
}

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
    
    // Afficher l'image si présente
    let imageContent = '';
    if (message.image_path) {
        imageContent = `<img src="${escapeHtml(message.image_path)}" alt="Image du message" class="message-image" loading="lazy">`;
    }
    
    // Indicateur d'envoi en cours
    const sendingIndicator = message.sending ? '<span class="message-sending">Envoi en cours...</span>' : '';
    
    const messageHtml = `
        <div class="chatroom-message ${messageClass}" data-message-id="${message.id}" role="article" aria-label="Message de ${authorName}">
            <div class="message-bubble">
                ${messageContent}
                ${imageContent}
                ${sendingIndicator}
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
            loadingIndicator.innerHTML = '<div class="chatroom-empty"><p>Aucun message</p></div>';
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
        
        // Essayer de récupérer le JSON même en cas d'erreur
        let data;
        try {
            data = await response.json();
        } catch (e) {
            // Si le JSON échoue, essayer de récupérer le texte
            try {
                const text = await response.text();
                throw new Error(`Réponse non-JSON (${response.status}): ${text.substring(0, 200)}`);
            } catch (textError) {
                throw new Error(`Impossible de lire la réponse (${response.status}): ${textError.message}`);
            }
        }
        
        if (!response.ok) {
            const errorMsg = data.error || `Erreur HTTP ${response.status}`;
            console.error('Erreur chargement messages:', errorMsg);
            
            if (loadingIndicator) {
                loadingIndicator.innerHTML = '<div class="chatroom-loading">Erreur de chargement</div>';
            }
            throw new Error(errorMsg);
        }
        
        if (data.ok && data.messages) {
            renderMessages(data.messages, append);
            
            // Optimisation du polling : backoff exponentiel si pas de nouveaux messages
            if (data.messages.length === 0) {
                consecutiveEmptyResponses++;
                // Augmenter progressivement l'intervalle (max 30 secondes)
                refreshInterval = Math.min(2000 * Math.pow(1.5, consecutiveEmptyResponses), 30000);
            } else {
                consecutiveEmptyResponses = 0;
                refreshInterval = 2000; // Reset à 2 secondes
            }
            
            updateConnectionStatus('online');
        } else if (!data.ok) {
            throw new Error(data.error || 'Erreur inconnue');
        }
    } catch (error) {
        console.error('Erreur chargement messages:', error);
        updateConnectionStatus('error');
        
        if (loadingIndicator) {
            loadingIndicator.innerHTML = '<div class="chatroom-loading">Erreur de chargement</div>';
        }
        
        // Afficher une notification d'erreur
        showErrorNotification('Erreur lors du chargement des messages. Nouvelle tentative...');
    } finally {
        isLoading = false;
    }
}

// ============================================
// Envoi de message
// ============================================
async function sendMessage() {
    const messageText = messageInput.value.trim();
    const hasImage = selectedImage !== null && selectedImage instanceof File;
    
    // Le message ou l'image doit être présent
    if (!messageText && !hasImage) {
        return;
    }
    
    if (messageText && messageText.length > CONFIG.maxMessageLength) {
        showErrorNotification(`Le message est trop long (max ${CONFIG.maxMessageLength} caractères)`);
        return;
    }
    
    if (isSending) return;
    
    isSending = true;
    sendButton.disabled = true;
    const originalMessage = messageText;
    const originalImage = hasImage ? selectedImage : null;
    
    // Afficher un message temporaire "Envoi en cours..."
    const tempMessage = {
        id: 'temp_' + Date.now(),
        message: messageText || '(image)',
        date_envoi: new Date().toISOString(),
        is_me: true,
        sending: true,
        image_path: hasImage ? URL.createObjectURL(originalImage) : null
    };
    pendingMessageId = tempMessage.id;
    renderMessages([tempMessage], true);
    
    // Extraire les mentions
    const mentionNames = detectMentions(messageText);
    const mentionIds = await extractMentionIds(mentionNames);
    
    // Réinitialiser l'interface
    messageInput.value = '';
    selectedImage = null;
    imagePreviewContainer.style.display = 'none';
    adjustTextareaHeight();
    
    try {
        let imagePath = null;
        
        // Upload de l'image si présente (vérification stricte)
        if (hasImage && originalImage instanceof File) {
            try {
                const formData = new FormData();
                formData.append('image', originalImage);
                
                const uploadResponse = await fetch('/API/chatroom_upload_image.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });
                
                // Vérifier si la réponse est OK avant de parser le JSON
                if (!uploadResponse.ok) {
                    let errorMsg = 'Erreur lors de l\'upload de l\'image';
                    try {
                        const errorData = await uploadResponse.json();
                        errorMsg = errorData.error || errorMsg;
                    } catch (e) {
                        // Si ce n'est pas du JSON, utiliser le texte de la réponse
                        try {
                            const errorText = await uploadResponse.text();
                            errorMsg = errorText || errorMsg;
                        } catch (textError) {
                            errorMsg = `Erreur HTTP ${uploadResponse.status}`;
                        }
                    }
                    throw new Error(errorMsg);
                }
                
                const uploadData = await uploadResponse.json();
                if (uploadData.ok && uploadData.image_path) {
                    imagePath = uploadData.image_path;
                } else {
                    throw new Error(uploadData.error || 'Erreur lors de l\'upload de l\'image');
                }
            } catch (uploadError) {
                // Si l'upload échoue, on ne peut pas continuer
                throw uploadError;
            }
        }
        
            // Envoyer le message
        const response = await fetch('/API/chatroom_send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
                csrf_token: CONFIG.csrfToken,
                message: originalMessage || null,
                mentions: mentionIds,
                image_path: imagePath || null
            }),
            credentials: 'same-origin'
        });
        
        // Essayer de récupérer le JSON même en cas d'erreur
        let data;
        try {
            data = await response.json();
        } catch (e) {
            // Si le JSON échoue, essayer de récupérer le texte
            try {
                const text = await response.text();
                throw new Error(`Réponse non-JSON (${response.status}): ${text.substring(0, 200)}`);
            } catch (textError) {
                throw new Error(`Impossible de lire la réponse (${response.status}): ${textError.message}`);
            }
        }
        
        if (!response.ok) {
            const errorMsg = data.error || `Erreur HTTP ${response.status}`;
            console.error('Erreur envoi message:', errorMsg);
            throw new Error(errorMsg);
        }
        
        // Supprimer le message temporaire
        const tempMsgEl = messagesContainer.querySelector(`[data-message-id="${pendingMessageId}"]`);
        if (tempMsgEl) {
            tempMsgEl.remove();
        }
        pendingMessageId = null;
        
        if (data.ok && data.message) {
            // Ajouter l'image_path au message si présent
            if (imagePath) {
                data.message.image_path = imagePath;
            }
            renderMessages([data.message], true);
            setTimeout(() => loadMessages(true), 500);
            updateConnectionStatus('online');
        } else {
            throw new Error(data.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Erreur envoi message:', error);
        
        // Supprimer le message temporaire en cas d'erreur
        const tempMsgEl = messagesContainer.querySelector(`[data-message-id="${pendingMessageId}"]`);
        if (tempMsgEl) {
            tempMsgEl.remove();
        }
        pendingMessageId = null;
        
        showErrorNotification('Erreur lors de l\'envoi du message: ' + error.message);
        messageInput.value = originalMessage;
        if (originalImage) {
            selectedImage = originalImage;
            imagePreview.src = URL.createObjectURL(originalImage);
            imagePreviewContainer.style.display = 'flex';
        }
        adjustTextareaHeight();
        updateConnectionStatus('error');
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
        // Récupérer le texte après le @ jusqu'au curseur
        const query = textBefore.substring(atIndex + 1).trim();
        
        // Vérifier qu'il n'y a pas d'espace entre @ et le curseur (sinon ce n'est pas une mention)
        const textAfterAt = textBefore.substring(atIndex + 1);
        if (!textAfterAt.includes(' ')) {
            // Lancer la recherche même si query est vide (pour afficher tous les utilisateurs)
            clearTimeout(mentionSearchTimeout);
            mentionSearchTimeout = setTimeout(() => {
                searchUsers(query);
            }, 200); // Réduire le délai pour une meilleure réactivité
            return;
        }
    }
    // Si on n'est pas dans une mention, cacher les suggestions
    mentionSuggestions.classList.remove('show');
    mentionSearchIndex = -1;
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
// Gestion de l'upload d'images
// ============================================
imageUploadButton.addEventListener('click', () => {
    imageInput.click();
});

imageInput.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file && file instanceof File) {
        // Vérifier le type
        if (!file.type.startsWith('image/')) {
            showErrorNotification('Veuillez sélectionner une image');
            imageInput.value = ''; // Réinitialiser l'input
            return;
        }
        
        // Vérifier la taille (5MB max)
        if (file.size > 5 * 1024 * 1024) {
            showErrorNotification('L\'image est trop volumineuse (max 5MB)');
            imageInput.value = ''; // Réinitialiser l'input
            return;
        }
        
        // Vérifier que le fichier n'est pas vide
        if (file.size === 0) {
            showErrorNotification('Le fichier sélectionné est vide');
            imageInput.value = ''; // Réinitialiser l'input
            return;
        }
        
        selectedImage = file;
        imagePreview.src = URL.createObjectURL(file);
        imagePreviewContainer.style.display = 'flex';
    } else {
        // Si aucun fichier n'est sélectionné, réinitialiser
        selectedImage = null;
        imagePreviewContainer.style.display = 'none';
    }
});

removeImagePreview.addEventListener('click', () => {
    selectedImage = null;
    imagePreviewContainer.style.display = 'none';
    imageInput.value = '';
});

// ============================================
// Gestion du statut de connexion
// ============================================
function updateConnectionStatus(status) {
    connectionStatus = status;
    
    if (status === 'online') {
        onlineIndicator.style.background = '#4ade80';
        statusText.textContent = 'En ligne';
        connectionStatusEl.style.display = 'none';
    } else if (status === 'offline' || status === 'error') {
        onlineIndicator.style.background = '#ef4444';
        statusText.textContent = 'Hors ligne';
        connectionStatusEl.style.display = 'flex';
        connectionStatusEl.querySelector('.connection-status-indicator').style.background = '#ef4444';
        connectionStatusEl.querySelector('.connection-status-text').textContent = 'Connexion perdue. Reconnexion en cours...';
    }
}

// Détecter les changements de connexion réseau
window.addEventListener('online', () => {
    updateConnectionStatus('online');
    loadMessages(true);
});

window.addEventListener('offline', () => {
    updateConnectionStatus('offline');
});

// ============================================
// Système de notifications
// ============================================
function showErrorNotification(message) {
    // Utiliser showNotification si disponible (depuis api.js), sinon alert
    if (typeof window.showNotification === 'function') {
        window.showNotification(message, 'error');
    } else {
        // Fallback : créer une notification visuelle simple
        const notification = document.createElement('div');
        notification.className = 'chatroom-notification error';
        notification.textContent = message;
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: #ef4444;
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            animation: slideIn 0.3s ease-out;
        `;
        document.body.appendChild(notification);
        
        setTimeout(() => {
            notification.style.animation = 'slideOut 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 3000);
    }
}

// ============================================
// Initialisation
// ============================================
async function init() {
    await loadMessages(false);
    
    // Utiliser l'intervalle dynamique pour le polling
    function scheduleNextRefresh() {
        if (refreshIntervalId) clearInterval(refreshIntervalId);
        refreshIntervalId = setTimeout(() => {
            loadMessages(true).then(() => {
                scheduleNextRefresh();
            });
        }, refreshInterval);
    }
    
    scheduleNextRefresh();
    messageInput.focus();
    updateConnectionStatus('online');
}

init();

window.addEventListener('beforeunload', () => {
    if (refreshIntervalId) clearInterval(refreshIntervalId);
});
</script>
</body>
</html>
