<?php
// /source/templates/header.php (template sécurisé, aucune session_start ici)

// On lit les infos de session si elles existent (la page appelante gère la session)
$emploi          = $_SESSION['emploi']        ?? '';
$csrf            = $_SESSION['csrf_token']    ?? '';
$isAdmin         = ($emploi === 'Admin'); // Utilise la valeur exacte de la base de données
$canCommercial   = in_array($emploi, ['Chargé relation clients', 'Admin'], true); // 'Chargé relation clients' remplace 'Commercial'

// Modules activés (parametres_app) - masquer les liens des modules désactivés
$modEnabled = ['dashboard' => true, 'messagerie' => true, 'agenda' => true, 'commercial' => true, 'maps' => true, 'profil' => true];
$pdoNav = null;
$navBizDash = false;
$navBizRec = false;
$navBizOpp = false;
if (function_exists('getPdo')) {
    try {
        require_once __DIR__ . '/../../includes/parametres.php';
        $pdoNav = getPdo();
        $modEnabled['dashboard']   = isModuleEnabled($pdoNav, 'dashboard');
        $modEnabled['messagerie']  = isModuleEnabled($pdoNav, 'messagerie');
        $modEnabled['agenda']      = isModuleEnabled($pdoNav, 'agenda');
        $modEnabled['commercial']  = isModuleEnabled($pdoNav, 'commercial');
        $modEnabled['maps']        = isModuleEnabled($pdoNav, 'maps');
        $modEnabled['profil']      = isModuleEnabled($pdoNav, 'profil');
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
            if (class_exists(\App\Services\ProductTier::class)) {
                $navBizDash = in_array($emploi, ['Admin', 'Dirigeant'], true)
                    && \App\Services\ProductTier::canUseFeature($pdoNav, 'module_dashboard_business');
                $navBizRec = in_array($emploi, ['Admin', 'Dirigeant'], true)
                    && \App\Services\ProductTier::canUseFeature($pdoNav, 'module_factures_recurrentes');
                $navBizOpp = in_array($emploi, ['Admin', 'Dirigeant', 'Chargé relation clients'], true)
                    && \App\Services\ProductTier::canUseFeature($pdoNav, 'module_opportunites');
            }
        }
    } catch (Throwable $e) {
        // Table parametres_app peut ne pas exister
    }
}

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
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string {
        $nonce = $GLOBALS['csp_nonce'] ?? '';
        if ($nonce === '') {
            return '';
        }
        return 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }
}
?>
<link rel="stylesheet" href="/assets/css/header.css">
<link rel="stylesheet" href="/assets/css/form-helpers.css">

<a href="#main-content" class="skip-link">Aller au contenu</a>
<header class="main-header" data-csrf-token="<?= h($csrf) ?>">
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

    <div class="notif-wrap nav-notif-wrap">
      <button type="button" class="notif-btn" id="global-notif-btn" aria-label="Notifications" aria-expanded="false" aria-haspopup="true">
        <span class="notif-bell" aria-hidden="true">🔔</span>
        <span class="nav-label">Notifications</span>
        <span class="notif-badge" id="global-notif-badge">0</span>
      </button>
      <div class="notif-dropdown" id="global-notif-dropdown" role="menu" aria-hidden="true">
        <div class="notif-actions">
          <span>Notifications</span>
          <button type="button" class="notif-markall" id="global-notif-markall">Tout marquer lu</button>
        </div>
        <div id="global-notif-list"></div>
        <div id="global-notif-empty" class="notif-item" style="display:none;">Aucune notification non lue.</div>
      </div>
    </div>

    <?php if ($modEnabled['dashboard']): ?>
    <a href="/public/dashboard.php" aria-label="Accueil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
        <polyline points="9,22 9,12 15,12 15,22"/>
      </svg>
      <span class="nav-label">Accueil</span>
    </a>
    <?php endif; ?>

    <?php if ($navBizDash): ?>
    <a href="/public/dashboard_business.php" aria-label="Pilotage">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
      </svg>
      <span class="nav-label">Pilotage</span>
    </a>
    <?php endif; ?>

    <?php if ($navBizRec): ?>
    <a href="/public/factures_recurrentes.php" aria-label="Factures récurrentes">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>
      </svg>
      <span class="nav-label">Récurrent</span>
    </a>
    <?php endif; ?>

    <?php if ($navBizOpp): ?>
    <a href="/public/opportunites.php" aria-label="Opportunités">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
        <circle cx="12" cy="12" r="3"/>
      </svg>
      <span class="nav-label">Opportunités</span>
    </a>
    <?php endif; ?>

    <?php if ($modEnabled['messagerie']): ?>
    <a href="/public/messagerie.php" aria-label="Messagerie" class="messagerie-link" id="messagerie-link">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span class="nav-label">Messagerie</span>
      <span class="messagerie-badge" id="messagerie-badge" style="display:none;">0</span>
    </a>
    <?php endif; ?>

    <?php if ($modEnabled['agenda']): ?>
    <a href="/public/agenda.php" aria-label="Agenda">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
        <line x1="16" y1="2" x2="16" y2="6"/>
        <line x1="8" y1="2" x2="8" y2="6"/>
        <line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <span class="nav-label">Agenda</span>
    </a>
    <?php endif; ?>

    <?php if ($modEnabled['commercial'] && $canCommercial): ?>
      <a href="/public/commercial.php" aria-label="Espace commercial">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z"/>
          <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/>
          <path d="M12 12h.01"/>
        </svg>
        <span class="nav-label">Espace commercial</span>
      </a>
    <?php endif; ?>

    <?php if ($modEnabled['maps']): ?>
    <a href="/public/maps.php" aria-label="Cartes">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
        <line x1="8" y1="2" x2="8" y2="18"/>
        <line x1="16" y1="6" x2="16" y2="22"/>
      </svg>
      <span class="nav-label">Cartes</span>
    </a>
    <?php endif; ?>

    <?php if ($modEnabled['profil']): ?>
    <a href="/public/profil.php" aria-label="Profil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
      <span class="nav-label">Profil</span>
    </a>
    <?php endif; ?>

    <?php
    // [Fonctionnalité C] Dernière connexion (session précédente)
    $lastLoginNav = $_SESSION['last_login_at'] ?? null;
    if ($lastLoginNav !== null && $lastLoginNav !== '' && !empty($_SESSION['user_id'])):
        $lastLoginTs = strtotime((string)$lastLoginNav);
    ?>
    <span class="nav-last-login" style="font-size:0.78rem;color:#9ca3af;line-height:1.2;text-align:right;max-width:160px;align-self:center;">
      Dernière connexion : <?= $lastLoginTs ? h(date('d/m/Y à H:i', $lastLoginTs)) : '—' ?>
    </span>
    <?php endif; ?>

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

<div id="alert-container" class="alert-container" role="region" aria-label="Notifications"></div>
<?php if (!empty($_SESSION['user_id'])): ?>
<div id="inactivity-warning" style="display:none;position:fixed;bottom:20px;right:20px;background:#f59e0b;color:#fff;padding:12px 18px;border-radius:8px;font-size:0.9rem;z-index:9999;box-shadow:0 2px 8px rgba(0,0,0,0.2);"></div>
<script <?= csp_nonce() ?>>
(function() {
  // [Fonctionnalité B] Avertissement client avant déconnexion inactivité (aligné sur 30 min serveur)
  const INACTIVITY_LIMIT = 1800;
  const WARNING_BEFORE = 300;
  let lastActivity = Date.now();
  let warningShown = false;

  function resetTimer() {
    lastActivity = Date.now();
    warningShown = false;
    const banner = document.getElementById('inactivity-warning');
    if (banner) banner.style.display = 'none';
  }

  ['mousemove','keydown','click','scroll','touchstart'].forEach(function(e) {
    document.addEventListener(e, resetTimer, { passive: true });
  });

  setInterval(function() {
    const elapsed = Math.floor((Date.now() - lastActivity) / 1000);
    const remaining = INACTIVITY_LIMIT - elapsed;
    if (remaining <= WARNING_BEFORE && !warningShown) {
      warningShown = true;
      const banner = document.getElementById('inactivity-warning');
      if (banner) {
        banner.textContent = 'Vous serez déconnecté dans ' + Math.ceil(remaining / 60) + ' minute(s) pour inactivité.';
        banner.style.display = 'block';
      }
    }
    if (remaining <= 0) {
      window.location.href = '/includes/logout.php';
    }
  }, 10000);
})();
</script>
<?php endif; ?>
<script src="/assets/js/form-helpers.js"></script>

<script <?= csp_nonce() ?>>
document.addEventListener('DOMContentLoaded', () => {
  // --- INDICATEUR PAGE ACTIVE ---
  const path = window.location.pathname.replace(/\/$/, '');
  let bestLink = null, bestLen = 0;
  document.querySelectorAll('.nav-header a[href]').forEach(link => {
    const href = (link.getAttribute('href') || '').replace(/\/$/, '').split('?')[0];
    if (href && (path === href || path.startsWith(href + '/')) && href.length > bestLen) {
      bestLink = link; bestLen = href.length;
    }
  });
  if (bestLink) bestLink.classList.add('nav-active');

  // --- ANCRE SKIP LINK ---
  const mainEl = document.querySelector('main, .dashboard-wrapper, .page-container');
  if (mainEl && !mainEl.id) mainEl.id = 'main-content';

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
    let lastBadgeCount = 0;
    let isFirstBadgeUpdate = true;
    const UPDATE_INTERVAL = 10000; // 10 secondes
    const MIN_UPDATE_INTERVAL = 3000; // Minimum 3 secondes entre mises à jour
    
    function showNewMessageToast(count) {
      if (document.hidden) return;
      const isOnMessagerie = /\/messagerie\.php/.test(window.location.pathname);
      if (isOnMessagerie) return;
      let toast = document.getElementById('header-messagerie-toast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'header-messagerie-toast';
        toast.className = 'header-messagerie-toast';
        toast.setAttribute('role', 'status');
        document.body.appendChild(toast);
      }
      toast.textContent = count === 1 ? '1 nouveau message' : count + ' nouveaux messages';
      toast.classList.add('header-messagerie-toast-visible');
      setTimeout(() => toast.classList.remove('header-messagerie-toast-visible'), 3000);
    }
    
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
        if (!isFirstBadgeUpdate && lastBadgeCount === 0 && totalCount > 0) {
          showNewMessageToast(totalCount);
        }
        isFirstBadgeUpdate = false;
        lastBadgeCount = totalCount;
        
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

  // --- NOTIFICATIONS GLOBALES (hors chatroom) ---
  (function initGlobalNotifications() {
    const btn = document.getElementById('global-notif-btn');
    const panel = document.getElementById('global-notif-dropdown');
    const listEl = document.getElementById('global-notif-list');
    const emptyEl = document.getElementById('global-notif-empty');
    const badge = document.getElementById('global-notif-badge');
    const markAllBtn = document.getElementById('global-notif-markall');
    const headerEl = document.querySelector('.main-header');
    if (!btn || !panel || !listEl || !badge || !headerEl) return;

    const csrfToken = headerEl.dataset.csrfToken || '';
    const POLL_MS = 30000;
    let notifItems = [];

    function notifHref(n) {
      const id = n.id_lien;
      const tl = n.type_lien;
      if (id == null || id === '' || !tl) return null;
      const sid = String(id);
      if (tl === 'sav') return '/public/sav.php?sav_id=' + encodeURIComponent(sid);
      if (tl === 'livraison') return '/public/livraison.php?livraison_id=' + encodeURIComponent(sid);
      if (tl === 'facture') return '/public/view_facture.php?id=' + encodeURIComponent(sid);
      if (tl === 'paiement') return '/public/paiements.php';
      return null;
    }

    function formatNotifTime(isoOrSql) {
      if (!isoOrSql) return '';
      const s = String(isoOrSql).replace(' ', 'T');
      const d = new Date(s);
      if (Number.isNaN(d.getTime())) return '';
      return d.toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' });
    }

    function setBadgeCount(n) {
      if (n > 0) {
        badge.textContent = n > 99 ? '99+' : String(n);
        btn.classList.add('has-unread');
      } else {
        badge.textContent = '0';
        btn.classList.remove('has-unread');
      }
    }

    function renderList() {
      listEl.innerHTML = '';
      if (!notifItems.length) {
        emptyEl.style.display = 'block';
        return;
      }
      emptyEl.style.display = 'none';
      notifItems.forEach((n) => {
        const id = n.id;
        const row = document.createElement('button');
        row.type = 'button';
        row.className = 'notif-item unread';
        row.setAttribute('role', 'menuitem');
        const title = document.createElement('div');
        title.className = 'title';
        title.textContent = n.titre || 'Notification';
        const body = document.createElement('div');
        body.className = 'body';
        body.textContent = n.message || '';
        const time = document.createElement('div');
        time.className = 'time';
        time.textContent = formatNotifTime(n.date_creation);
        row.appendChild(title);
        if (n.message) row.appendChild(body);
        row.appendChild(time);
        row.addEventListener('click', async () => {
          const href = notifHref(n);
          try {
            await fetch('/API/notifications_mark_read.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
              },
              body: JSON.stringify({ id_notification: id }),
              credentials: 'include'
            });
          } catch (e) { /* ignore */ }
          if (href) window.location.href = href;
          else closePanel();
        });
        listEl.appendChild(row);
      });
    }

    function closePanel() {
      panel.classList.remove('open');
      panel.setAttribute('aria-hidden', 'true');
      btn.setAttribute('aria-expanded', 'false');
    }

    function openPanel() {
      panel.classList.add('open');
      panel.setAttribute('aria-hidden', 'false');
      btn.setAttribute('aria-expanded', 'true');
      renderList();
    }

    async function loadNotifications() {
      try {
        const res = await fetch('/API/notifications_get.php', { cache: 'no-cache', credentials: 'include' });
        if (res.status === 401) {
          btn.closest('.notif-wrap').style.display = 'none';
          return;
        }
        const data = await res.json();
        if (!data.success) return;
        notifItems = Array.isArray(data.notifications) ? data.notifications : [];
        const c = typeof data.count === 'number' ? data.count : notifItems.length;
        setBadgeCount(c);
        if (panel.classList.contains('open')) renderList();
      } catch (e) {
        /* silencieux */
      }
    }

    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      if (panel.classList.contains('open')) closePanel();
      else openPanel();
    });

    document.addEventListener('click', () => closePanel());
    panel.addEventListener('click', (e) => e.stopPropagation());

    if (markAllBtn) {
      markAllBtn.addEventListener('click', async (e) => {
        e.stopPropagation();
        try {
          await fetch('/API/notifications_mark_read.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ all: true }),
            credentials: 'include'
          });
        } catch (err) { /* ignore */ }
        notifItems = [];
        setBadgeCount(0);
        renderList();
      });
    }

    loadNotifications();
    setInterval(loadNotifications, POLL_MS);
  })();

  // --- EXÉCUTION AUTOMATIQUE DES ENVOIS PROGRAMMÉS (toutes les 1 min) ---
  (function initScheduledSendsPolling() {
    const INTERVAL_MS = 60000; // 1 minute
    const DELAY_FIRST_RUN = 5000;  // Premier appel après 5 secondes (éviter surcharge au chargement)
    let isRunning = false;

    async function executeScheduledSends() {
      if (isRunning) return;
      if (document.hidden) return; // Ne pas exécuter si l'onglet est en arrière-plan
      isRunning = true;
      try {
        const header = document.querySelector('.main-header');
        const csrfToken = header?.dataset?.csrfToken || '';
        const res = await fetch('/API/cron_execute_scheduled.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
          },
          body: JSON.stringify({}),
          credentials: 'include'
        });
        const data = await res.json();
        if (data.ok && data.executed > 0 && data.sent > 0) {
          // Rafraîchir la liste si on est sur la page paiements
          if (typeof window.loadProgrammerEnvoisList === 'function') {
            window.loadProgrammerEnvoisList();
          }
        }
      } catch (err) {
        // Silencieux - ne pas perturber l'utilisateur
      } finally {
        isRunning = false;
      }
    }

    setTimeout(executeScheduledSends, DELAY_FIRST_RUN);
    setInterval(executeScheduledSends, INTERVAL_MS);
  })();
});
</script>
