<?php
// /source/templates/header.php (template sécurisé, aucune session_start ici)

// On lit les infos de session si elles existent (la page appelante gère la session)
$emploi          = $_SESSION['emploi']        ?? '';
$csrf            = $_SESSION['csrf_token']    ?? '';
$isAdmin         = ($emploi === 'Admin'); // Utilise la valeur exacte de la base de données
$canCommercial   = in_array($emploi, ['Chargé relation clients', 'Admin'], true); // 'Chargé relation clients' remplace 'Commercial'

// Helper pour l'échappement XSS
// Utilise le helper centralisé si disponible, sinon définit une fonction locale
if (!function_exists('h')) {
    // Essayer de charger le helper centralisé
    $helpersFile = __DIR__ . '/../../includes/helpers.php';
    if (file_exists($helpersFile)) {
        require_once $helpersFile;
    } else {
        // Fallback local
        function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    }
}
?>
<link rel="stylesheet" href="/assets/css/header.css">

<header class="main-header">
  <a href="/public/dashboard.php" class="logo-header" id="logo-link">
    <img src="/assets/logos/logo.png" alt="Logo CCComputer" width="32" height="32" class="logo-animated" id="logo-img">
    <h1 class="company-name">CCComputer</h1>
  </a>

  <button class="menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="nav-links">
    <svg class="icon-menu" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
    <svg class="icon-close" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
      <line x1="18" y1="6" x2="6" y2="18"/>
      <line x1="6" y1="6" x2="18" y2="18"/>
    </svg>
  </button>

  <nav class="nav-header" id="nav-links" role="navigation">
    <button class="theme-toggle" type="button" aria-label="Basculer le thème" title="Changer le thème">
      <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="12" cy="12" r="5"/>
        <line x1="12" y1="1" x2="12" y2="3"/>
        <line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/>
        <line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
      <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
      <span class="nav-label">Thème</span>
    </button>

    <a href="/public/dashboard.php" aria-label="Accueil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9,22 9,12 15,12 15,22"/>
      </svg>
      <span class="nav-label">Accueil</span>
    </a>

    <a href="/public/messagerie.php" aria-label="Messagerie" class="messagerie-link" id="messagerie-link">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span class="nav-label">Messagerie</span>
      <span class="messagerie-badge" id="messagerie-badge" style="display:none;">0</span>
    </a>

    <a href="/public/agenda.php" aria-label="Agenda">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <span class="nav-label">Agenda</span>
    </a>

    <?php if ($canCommercial): ?>
      <a href="/public/commercial.php" aria-label="Espace commercial">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z"/>
          <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
          <path d="M12 12h.01"/>
        </svg>
        <span class="nav-label">Espace commercial</span>
      </a>
    <?php endif; ?>

    <a href="/public/maps.php" aria-label="Cartes">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
        <line x1="8" y1="2" x2="8" y2="18"/>
        <line x1="16" y1="6" x2="16" y2="22"/>
      </svg>
      <span class="nav-label">Cartes</span>
    </a>

    <a href="/public/profil.php" aria-label="Profil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      <span class="nav-label">Profil</span>
    </a>

    <a href="/includes/logout.php" id="logout-link" aria-label="Déconnexion">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
        <polyline points="16,17 21,12 16,7"/>
        <line x1="21" y1="12" x2="9" y2="12"/>
      </svg>
      <span class="nav-label">Déconnexion</span>
    </a>
  </nav>
</header>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // --- GESTION DU MENU MOBILE ---
  const header = document.querySelector('.main-header');
  const menuToggle = document.querySelector('.menu-toggle');
  if (menuToggle && header) {
    menuToggle.addEventListener('click', () => {
      const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
      menuToggle.setAttribute('aria-expanded', !isExpanded);
      header.classList.toggle('nav-open');
    });
  }

  // --- GESTION DU THÈME ---
  const themeToggleButton = document.querySelector('.theme-toggle');
  if (themeToggleButton) {
    const sunIcon = themeToggleButton.querySelector('.sun-icon');
    const moonIcon = themeToggleButton.querySelector('.moon-icon');
    const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

    const updateThemeIcon = (theme) => {
      if (!sunIcon || !moonIcon) return;
      sunIcon.style.display = (theme === 'dark') ? 'none' : 'block';
      moonIcon.style.display = (theme === 'dark') ? 'block' : 'none';
    };

    const applyTheme = (theme) => {
      document.documentElement.setAttribute('data-theme', theme);
      localStorage.setItem('theme', theme);
      updateThemeIcon(theme);
    };

    const toggleTheme = () => {
      const currentTheme = document.documentElement.getAttribute('data-theme') || (mediaQuery.matches ? 'dark' : 'light');
      const newTheme = (currentTheme === 'dark') ? 'light' : 'dark';
      applyTheme(newTheme);
    };

    themeToggleButton.addEventListener('click', toggleTheme);

    const initTheme = () => {
      const savedTheme = localStorage.getItem('theme');
      const preferredTheme = mediaQuery.matches ? 'dark' : 'light';
      applyTheme(savedTheme || preferredTheme);
    };

    mediaQuery.addEventListener('change', (e) => {
      // S'il n'y a pas de thème sauvegardé, on suit la préférence système
      if (!localStorage.getItem('theme')) {
        applyTheme(e.matches ? 'dark' : 'light');
      }
    });

    initTheme();
  }

  // --- ANIMATION DU LOGO ---
  const logoLink = document.getElementById('logo-link');
  if (logoLink) {
    logoLink.addEventListener('click', (e) => {
      e.preventDefault();
      const logoImg = document.getElementById('logo-img');
      if (logoImg) {
        logoImg.classList.remove('logo-animated');
        void logoImg.offsetWidth; // Force le recalcul du style
        logoImg.classList.add('logo-animated');
        setTimeout(() => window.location.href = logoLink.href, 500);
      } else {
        window.location.href = logoLink.href;
      }
    });
  }

  // --- CONFIRMATION DE DÉCONNEXION ---
  const logoutLink = document.getElementById('logout-link');
  if (logoutLink) {
    logoutLink.addEventListener('click', (event) => {
      if (!confirm('Êtes-vous sûr de vouloir vous déconnecter ?')) {
        event.preventDefault();
      }
    });
  }

  // --- BADGE MESSAGERIE (nombre de messages non lus + notifications chatroom) ---
  const messagerieBadge = document.getElementById('messagerie-badge');
  if (messagerieBadge) {
    let isUpdating = false;
    let lastUpdate = 0;
    const UPDATE_INTERVAL = 10000; // 10 secondes (plus fréquent pour les notifications chatroom)
    const MIN_UPDATE_INTERVAL = 3000; // Minimum 3 secondes entre mises à jour
    
    async function updateMessagerieBadge() {
      // Éviter les requêtes simultanées
      if (isUpdating) return;
      
      const now = Date.now();
      if (now - lastUpdate < MIN_UPDATE_INTERVAL) return;
      
      isUpdating = true;
      lastUpdate = now;
      
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);
        
        // Récupérer les notifications de chatroom
        let chatroomCount = 0;
        try {
          const chatroomResponse = await fetch('/API/chatroom_get_notifications.php', {
            signal: controller.signal,
            cache: 'no-cache',
            credentials: 'include'
          });
          if (chatroomResponse.ok) {
            const chatroomData = await chatroomResponse.json();
            if (chatroomData.ok) {
              chatroomCount = chatroomData.count || 0;
            }
          } else if (chatroomResponse.status === 401) {
            // 401 est normal si l'utilisateur n'est pas connecté ou la session a expiré
            // Ne pas logger d'erreur pour éviter le spam dans les logs
            chatroomCount = 0;
          }
        } catch (err) {
          // Ignorer les erreurs de chatroom (table peut ne pas exister, timeout, etc.)
          // Ne pas logger pour éviter le spam dans les logs
          chatroomCount = 0;
        }
        
        // Récupérer les messages non lus de l'ancienne messagerie
        let oldMessagerieCount = 0;
        try {
          const messagerieResponse = await fetch('/API/messagerie_get_unread_count.php', {
            signal: controller.signal,
            cache: 'no-cache',
            credentials: 'include'
          });
          if (messagerieResponse.ok) {
            const messagerieData = await messagerieResponse.json();
            if (messagerieData.ok) {
              oldMessagerieCount = messagerieData.count || 0;
            }
          }
        } catch (err) {
          // Ignorer les erreurs
        }
        
        clearTimeout(timeoutId);
        
        const totalCount = chatroomCount + oldMessagerieCount;
        if (totalCount > 0) {
          messagerieBadge.textContent = totalCount > 99 ? '99+' : String(totalCount);
          messagerieBadge.style.display = 'inline-block';
          messagerieBadge.setAttribute('aria-label', `${totalCount} notification${totalCount > 1 ? 's' : ''} non lu${totalCount > 1 ? 'es' : 'e'}`);
        } else {
          messagerieBadge.style.display = 'none';
          messagerieBadge.removeAttribute('aria-label');
        }
      } catch (err) {
        if (err.name !== 'AbortError') {
          console.error('Erreur mise à jour badge messagerie:', err);
        }
        messagerieBadge.style.display = 'none';
      } finally {
        isUpdating = false;
      }
    }
    
    // Charger au démarrage (avec délai pour ne pas bloquer le chargement de la page)
    setTimeout(updateMessagerieBadge, 1000);
    
    // Mettre à jour périodiquement
    setInterval(updateMessagerieBadge, UPDATE_INTERVAL);
    
    // Mettre à jour quand la page redevient visible (si l'utilisateur revient sur l'onglet)
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) {
        updateMessagerieBadge();
      }
    });
    
    // Exposer la fonction globalement pour qu'elle puisse être appelée depuis d'autres pages
    window.updateMessagerieBadge = updateMessagerieBadge;
  }
});
</script>
