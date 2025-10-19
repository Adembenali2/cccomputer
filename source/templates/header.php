<?php
// header.php (template sécurisé)
$csrf = $_SESSION['csrf_token'] ?? '';
$isAdmin = (($_SESSION['emploi'] ?? '') === 'Administrateur');  // exemple
$canCommercial = in_array(($_SESSION['emploi'] ?? ''), ['Commercial', 'Administrateur'], true);
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
        <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
        <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
      </svg>
      <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display:none;">
        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
      </svg>
      <span class="nav-label">Thème</span>
    </button>

    <a href="/public/dashboard.php" aria-label="Accueil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9,22 9,12 15,12 15,22"/>
      </svg>
      <span class="nav-label">Accueil</span>
    </a>

    <a href="/public/contact.php" aria-label="Contact">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>
      <span class="nav-label">Contact</span>
    </a>

    <a href="/public/agenda.php" aria-label="Agenda">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
      </svg>
      <span class="nav-label">Agenda</span>
    </a>

    <?php if ($canCommercial): ?>
      <a href="/public/commercial.php" aria-label="Espace commercial">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M4 7h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V9a2 2 0 0 1 2-2z"/>
          <path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M12 12h.01"/>
        </svg>
        <span class="nav-label">Espace commercial</span>
      </a>
    <?php endif; ?>

    <a href="/public/cartes.php" aria-label="Cartes">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <polygon points="1,6 1,22 8,18 16,22 23,18 23,2 16,6 8,2"/>
        <line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/>
      </svg>
      <span class="nav-label">Cartes</span>
    </a>

    <a href="/public/profil.php" aria-label="Profil">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
      </svg>
      <span class="nav-label">Profil</span>
    </a>

    <a href="/includes/logout.php" id="logout-link" aria-label="Déconnexion">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16,17 21,12 16,7"/><line x1="21" y1="12" x2="9" y2="12"/>
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
});
</script>
