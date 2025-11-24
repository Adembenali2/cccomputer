<?php
// /public/messagerie.php
// Chatroom Globale - Interface moderne type Messenger/WhatsApp
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
            Discutez en temps réel avec tous vos collègues connectés.
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

        <!-- Barre de saisie (fixe en bas) -->
        <div class="chatroom-input-container">
            <div class="chatroom-input-wrapper">
                <textarea 
                    id="messageInput" 
                    class="chatroom-input" 
                    placeholder="Tapez votre message..."
                    rows="1"
                    maxlength="5000"></textarea>
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

// ============================================
// Variables d'état
// ============================================
let lastMessageId = 0;
let isLoading = false;
let isSending = false;
let autoScrollEnabled = true;
let refreshIntervalId = null;

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
    
    // Si moins d'une minute
    if (diff < 60000) {
        return 'À l\'instant';
    }
    
    // Si moins d'une heure
    if (diff < 3600000) {
        const minutes = Math.floor(diff / 60000);
        return `Il y a ${minutes} min`;
    }
    
    // Si aujourd'hui
    if (date.toDateString() === now.toDateString()) {
        return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }
    
    // Si hier
    const yesterday = new Date(now);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === yesterday.toDateString()) {
        return 'Hier à ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    }
    
    // Sinon, date complète
    return date.toLocaleString('fr-FR', { 
        day: '2-digit', 
        month: '2-digit', 
        year: 'numeric',
        hour: '2-digit', 
        minute: '2-digit' 
    });
}

function scrollToBottom(smooth = true) {
    if (!autoScrollEnabled) return;
    
    setTimeout(() => {
        messagesContainer.scrollTo({
            top: messagesContainer.scrollHeight,
            behavior: smooth ? 'smooth' : 'auto'
        });
    }, 100);
}

// ============================================
// Affichage des messages
// ============================================
function renderMessage(message) {
    const isMe = message.is_me;
    const messageClass = isMe ? 'message-me' : 'message-other';
    const authorName = isMe ? 'Moi' : escapeHtml((message.user_prenom || '') + ' ' + (message.user_nom || ''));
    const userInfo = isMe ? '' : `<span class="message-author">${authorName}</span>`;
    
    const messageHtml = `
        <div class="chatroom-message ${messageClass}" data-message-id="${message.id}">
            <div class="message-bubble">
                <p class="message-content">${escapeHtml(message.message)}</p>
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
    
    if (loadingIndicator) {
        loadingIndicator.style.display = 'none';
    }
    
    const wasAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop <= messagesContainer.clientHeight + 100;
    
    if (append) {
        // Ajouter les nouveaux messages à la fin
        messages.forEach(msg => {
            const messageElement = document.createElement('div');
            messageElement.innerHTML = renderMessage(msg);
            const messageDiv = messageElement.firstElementChild;
            messageDiv.classList.add('message-new');
            messagesContainer.appendChild(messageDiv);
        });
        
        // Mettre à jour le dernier ID
        if (messages.length > 0) {
            lastMessageId = Math.max(lastMessageId, ...messages.map(m => m.id));
        }
        
        // Scroll seulement si on était déjà en bas
        if (wasAtBottom || autoScrollEnabled) {
            scrollToBottom(true);
        }
    } else {
        // Remplacer tous les messages
        messagesContainer.innerHTML = '';
        messages.forEach(msg => {
            const messageElement = document.createElement('div');
            messageElement.innerHTML = renderMessage(msg);
            messagesContainer.appendChild(messageElement.firstElementChild);
        });
        
        // Mettre à jour le dernier ID
        if (messages.length > 0) {
            lastMessageId = Math.max(...messages.map(m => m.id));
        }
        
        // Scroll en bas
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
        
        const response = await fetch(url, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.ok && data.messages) {
            renderMessages(data.messages, append);
        } else {
            console.error('Erreur chargement messages:', data.error || 'Erreur inconnue');
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
    
    if (!messageText || messageText.length === 0) {
        return;
    }
    
    if (messageText.length > CONFIG.maxMessageLength) {
        alert(`Le message est trop long (max ${CONFIG.maxMessageLength} caractères)`);
        return;
    }
    
    if (isSending) {
        return;
    }
    
    isSending = true;
    sendButton.disabled = true;
    const originalMessage = messageText;
    
    // Vider le champ immédiatement pour une meilleure UX
    messageInput.value = '';
    adjustTextareaHeight();
    
    try {
        const response = await fetch('/API/chatroom_send.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                csrf_token: CONFIG.csrfToken,
                message: originalMessage
            }),
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || `HTTP ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.ok && data.message) {
            // Ajouter le message immédiatement à l'interface
            renderMessages([data.message], true);
            
            // Recharger les messages pour s'assurer d'avoir la dernière version
            setTimeout(() => {
                loadMessages(true);
            }, 500);
        } else {
            throw new Error(data.error || 'Erreur lors de l\'envoi');
        }
    } catch (error) {
        console.error('Erreur envoi message:', error);
        alert('Erreur lors de l\'envoi du message: ' + error.message);
        // Remettre le message dans le champ
        messageInput.value = originalMessage;
        adjustTextareaHeight();
    } finally {
        isSending = false;
        sendButton.disabled = false;
        messageInput.focus();
    }
}

// ============================================
// Gestion de la hauteur du textarea
// ============================================
function adjustTextareaHeight() {
    messageInput.style.height = 'auto';
    messageInput.style.height = Math.min(messageInput.scrollHeight, 120) + 'px';
}

// ============================================
// Gestion du scroll (détecter si l'utilisateur scroll vers le haut)
// ============================================
messagesContainer.addEventListener('scroll', () => {
    const isAtBottom = messagesContainer.scrollHeight - messagesContainer.scrollTop <= messagesContainer.clientHeight + 100;
    autoScrollEnabled = isAtBottom;
});

// ============================================
// Event Listeners
// ============================================

// Envoi avec le bouton
sendButton.addEventListener('click', sendMessage);

// Envoi avec Enter (Shift+Enter pour nouvelle ligne)
messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMessage();
    }
});

// Ajuster la hauteur du textarea
messageInput.addEventListener('input', adjustTextareaHeight);

// ============================================
// Initialisation
// ============================================
async function init() {
    // Charger les messages initiaux
    await loadMessages(false);
    
    // Démarrer le rafraîchissement automatique
    refreshIntervalId = setInterval(() => {
        loadMessages(true);
    }, CONFIG.refreshInterval);
    
    // Focus sur le champ de saisie
    messageInput.focus();
}

// Démarrer l'application
init();

// Nettoyer l'intervalle quand la page est fermée
window.addEventListener('beforeunload', () => {
    if (refreshIntervalId) {
        clearInterval(refreshIntervalId);
    }
});
</script>
</body>
</html>
