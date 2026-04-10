<?php
// Header unifie — inclus sur toutes les pages
$userNom  = $_SESSION['nom'] ?? $_SESSION['user_nom'] ?? 'Utilisateur';
$userRole = $_SESSION['role'] ?? $_SESSION['emploi'] ?? '';
$pageActuelle = basename((string)($_SERVER['PHP_SELF'] ?? ''));

if (!function_exists('getNavIcon')) {
    function getNavIcon(string $name): string {
        $icons = [
            'grid'   => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
            'users'  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>',
            'file'   => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg>',
            'tool'   => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94l-3.76 3.76z"/></svg>',
            'box'    => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><path d="M3.27 6.96L12 12.01l8.73-5.05M12 22.08V12"/></svg>',
            'truck'  => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 3v5h-7V8zM5.5 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3zM18.5 21a1.5 1.5 0 100-3 1.5 1.5 0 000 3z"/></svg>',
            'credit' => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
            'history' => '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 102.13-9.36L1 10"/></svg>',
        ];
        return $icons[$name] ?? '';
    }
}
?>
<header class="site-header" data-csrf-token="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
  <a href="/public/dashboard.php" class="header-logo">
    <img src="/assets/logos/logo.png" alt="CC" onerror="this.style.display='none'">
    <span>CCComputer</span>
  </a>
  <button type="button" class="header-menu-toggle" id="headerMenuToggle" aria-label="Menu" aria-expanded="false">
    <svg class="icon-menu" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg>
    <svg class="icon-close" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
  </button>
  <nav class="header-nav" id="headerNav">
    <?php
    $navItems = [
      ['href'=>'dashboard.php','icon'=>'grid','label'=>'Dashboard'],
      ['href'=>'clients.php','icon'=>'users','label'=>'Clients'],
      ['href'=>'factures.php','icon'=>'file','label'=>'Factures'],
      ['href'=>'sav.php','icon'=>'tool','label'=>'SAV'],
      ['href'=>'stock.php','icon'=>'box','label'=>'Stock'],
      ['href'=>'livraison.php','icon'=>'truck','label'=>'Livraisons'],
      ['href'=>'paiements.php','icon'=>'credit','label'=>'Paiements'],
      ['href'=>'historique.php','icon'=>'history','label'=>'Historique'],
    ];
    foreach ($navItems as $item):
      $actif = ($pageActuelle === $item['href']);
    ?>
      <a href="/public/<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>" class="nav-item <?= $actif ? 'nav-active' : '' ?>" title="<?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?>">
        <?= getNavIcon($item['icon']) ?>
        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <!-- RECHERCHE GLOBALE -->
  <div class="global-search-wrapper" id="globalSearchWrapper">
    <input
      type="search"
      id="globalSearchInput"
      class="global-search-input"
      placeholder="Rechercher..."
      autocomplete="off"
      aria-label="Recherche globale"
      aria-haspopup="listbox"
      aria-expanded="false"
    >
    <div class="global-search-dropdown" id="globalSearchDropdown" role="listbox" hidden></div>
  </div>
  <div class="header-right">
    <button type="button" class="header-icon-btn" id="btnDarkMode" title="Mode nuit"><span id="iconDarkMode">🌙</span></button>
    <a href="/public/espace_commercial.php"
       class="header-icon-btn"
       title="Espace Commercial"
       style="<?= $pageActuelle === 'espace_commercial.php' ? 'background:rgba(255,255,255,.15);color:#fff;' : '' ?>">
      <svg width="18" height="18" fill="none" stroke="currentColor"
           stroke-width="2" viewBox="0 0 24 24">
        <path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/>
        <path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/>
      </svg>
    </a>
    <a href="/public/agenda.php" class="header-icon-btn <?= $pageActuelle === 'agenda.php' ? 'nav-icon-active' : '' ?>" title="Agenda">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    </a>
    <!-- NOTIFICATIONS -->
    <div class="notif-wrapper" id="notifWrapper">
      <button type="button" class="notif-bell" id="notifBell" aria-label="Notifications" aria-expanded="false">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 10-12 0v3.159c0 .538-.214 1.055-.595 1.437L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        <span class="notif-badge" id="notifBadge" hidden>0</span>
      </button>
      <div class="notif-dropdown" id="notifDropdown" hidden>
        <div class="notif-header">
          <span>Notifications</span>
          <button type="button" class="notif-mark-read" id="notifMarkRead">Tout lire</button>
        </div>
        <ul class="notif-list" id="notifList">
          <li class="notif-empty">Aucune notification</li>
        </ul>
      </div>
    </div>
    <a href="/public/messagerie.php" class="header-icon-btn messagerie-link <?= $pageActuelle === 'messagerie.php' ? 'nav-icon-active' : '' ?>" title="Messagerie" id="btnMessagerie">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
      <span class="messagerie-badge" id="msgBadge" style="display:none">0</span>
    </a>
    <a href="/public/maps.php" class="header-icon-btn <?= $pageActuelle === 'maps.php' ? 'nav-icon-active' : '' ?>" title="Maps">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
    </a>
    <div class="profil-wrapper">
      <button type="button" class="profil-btn" id="btnProfilMenu">
        <div class="profil-avatar"><?= htmlspecialchars(strtoupper((string)mb_substr((string)$userNom,0,1)), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="profil-info"><span class="profil-nom"><?= htmlspecialchars((string)$userNom, ENT_QUOTES, 'UTF-8') ?></span><span class="profil-role"><?= htmlspecialchars((string)ucfirst((string)$userRole), ENT_QUOTES, 'UTF-8') ?></span></div>
        <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
      </button>
      <div id="profilMenu" class="profil-menu" style="display:none;">
        <a href="/public/profil.php" class="profil-menu-item">👤 Mon profil</a>
        <a href="/public/parametres.php" class="profil-menu-item">⚙️ Paramètres</a>
        <hr class="profil-menu-sep">
        <a href="/source/connexion/logout.php" class="profil-menu-item profil-menu-danger">🚪 Déconnexion</a>
      </div>
    </div>
  </div>
  <!-- TOAST NOTIFICATIONS (position fixed) -->
  <div id="toastContainer" aria-live="polite"></div>
<script<?php
  $__hnCsp = (string)($GLOBALS['csp_nonce'] ?? '');
  echo $__hnCsp !== '' ? ' nonce="' . htmlspecialchars($__hnCsp, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
(function() {
  // ── Badge messagerie (polling 30s) ───────────────────────
  function updateMsgBadge() {
    fetch('/API/messagerie_get_unread_count.php', { credentials: 'include' })
      .then(r => r.json())
      .then(d => {
        const badge = document.getElementById('msgBadge');
        if (!badge) return;
        const c = typeof d.count === 'number' ? d.count : 0;
        if (c > 0) {
          badge.textContent = c > 99 ? '99+' : String(c);
          badge.style.display = 'inline-block';
        } else {
          badge.style.display = 'none';
        }
      }).catch(() => {});
  }
  updateMsgBadge();
  setInterval(updateMsgBadge, 30000);
})();
</script>
<script<?php
  $__hnCspNotif = (string)($GLOBALS['csp_nonce'] ?? '');
  echo $__hnCspNotif !== '' ? ' nonce="' . htmlspecialchars($__hnCspNotif, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
(function () {
  const POLL_INTERVAL = 30000;
  let pollTimer = null;
  let lastIds = new Set();
  let dropdownOpen = false;
  let initialNotifFetch = true;

  const bell = document.getElementById('notifBell');
  const badge = document.getElementById('notifBadge');
  const dropdown = document.getElementById('notifDropdown');
  const list = document.getElementById('notifList');
  const markReadBtn = document.getElementById('notifMarkRead');
  const notifWrapper = document.getElementById('notifWrapper');

  if (!bell || !badge || !dropdown || !list) return;

  const csrfToken = document.querySelector('.site-header')?.getAttribute('data-csrf-token') || '';
  window.__CSRF_TOKEN__ = csrfToken;

  const TYPE_ICONS = {
    sav:       '🔧',
    facture:   '📄',
    livraison: '🚚',
    stock:     '📦',
    paiement:  '💶',
    info:      'ℹ️',
  };

  function getIcon(type) {
    return TYPE_ICONS[type] || TYPE_ICONS.info;
  }

  function escHtml(s) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s ?? '')));
    return d.innerHTML;
  }

  function escAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  }

  async function fetchNotifications() {
    try {
      const res = await fetch('/API/notifications_get.php', { credentials: 'same-origin' });
      if (!res.ok) return;
      const data = await res.json();
      if (!data.success) return;
      renderNotifications(data.notifications || [], data.count || 0);
    } catch (e) { /* réseau indisponible */ }
  }

  function renderNotifications(notifications, count) {
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.hidden = false;
    } else {
      badge.hidden = true;
    }

    if (!initialNotifFetch) {
      const newItems = notifications.filter(n => !lastIds.has(n.id));
      newItems.forEach(n => showToast(n));
    } else {
      initialNotifFetch = false;
    }
    lastIds = new Set(notifications.map(n => n.id));

    list.innerHTML = '';
    if (notifications.length === 0) {
      const li = document.createElement('li');
      li.className = 'notif-empty';
      li.textContent = 'Aucune notification';
      list.appendChild(li);
      return;
    }
    notifications.forEach(n => {
      const li = document.createElement('li');
      li.className = 'notif-item notif-type-' + (n.type || 'info');
      const href = n.url || '/public/dashboard.php';
      li.innerHTML =
        '<a href="' + escAttr(href) + '" class="notif-link">' +
        '<span class="notif-icon">' + getIcon(n.type) + '</span>' +
        '<span class="notif-text">' +
        '<span class="notif-msg">' + escHtml(n.message) + '</span>' +
        '<span class="notif-time">' + escHtml(n.created_at || '') + '</span>' +
        '</span></a>';
      list.appendChild(li);
    });
  }

  function showToast(notif) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'notif-toast';
    toast.innerHTML =
      '<span class="toast-icon">' + getIcon(notif.type) + '</span>' +
      '<span class="toast-msg">' + escHtml(notif.message) + '</span>' +
      '<button type="button" class="toast-close" aria-label="Fermer">×</button>';
    container.appendChild(toast);

    requestAnimationFrame(function() { toast.classList.add('show'); });

    const dismiss = function() {
      toast.classList.remove('show');
      toast.addEventListener('transitionend', function() { toast.remove(); }, { once: true });
    };
    toast.querySelector('.toast-close').addEventListener('click', dismiss);
    setTimeout(dismiss, 5000);
  }

  async function markAllRead(e) {
    if (e) { e.preventDefault(); e.stopPropagation(); }
    try {
      await fetch('/API/notifications_mark_read.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': window.__CSRF_TOKEN__ || '',
        },
        body: JSON.stringify({ all: true, csrf_token: window.__CSRF_TOKEN__ || '' }),
      });
    } catch (err) {}
    badge.hidden = true;
    list.innerHTML = '';
    const li = document.createElement('li');
    li.className = 'notif-empty';
    li.textContent = 'Aucune notification';
    list.appendChild(li);
    lastIds.clear();
  }

  bell.addEventListener('click', function(e) {
    e.stopPropagation();
    dropdownOpen = !dropdownOpen;
    dropdown.hidden = !dropdownOpen;
    bell.setAttribute('aria-expanded', dropdownOpen ? 'true' : 'false');
    if (dropdownOpen) fetchNotifications();
  });

  document.addEventListener('click', function(e) {
    if (dropdownOpen && notifWrapper && !notifWrapper.contains(e.target)) {
      dropdownOpen = false;
      dropdown.hidden = true;
      bell.setAttribute('aria-expanded', 'false');
    }
  });

  if (markReadBtn) markReadBtn.addEventListener('click', markAllRead);

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function startPolling() {
    stopPolling();
    fetchNotifications();
    pollTimer = setInterval(fetchNotifications, POLL_INTERVAL);
  }

  document.addEventListener('visibilitychange', function() {
    if (document.hidden) stopPolling();
    else startPolling();
  });
  window.addEventListener('focus', function() {
    if (!pollTimer) startPolling();
  });
  window.addEventListener('blur', stopPolling);

  startPolling();
})();
</script>
<script<?php
  $__hnCsp2 = (string)($GLOBALS['csp_nonce'] ?? '');
  echo $__hnCsp2 !== '' ? ' nonce="' . htmlspecialchars($__hnCsp2, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
(function() {
  const toggle = document.getElementById('headerMenuToggle');
  const nav    = document.getElementById('headerNav');
  if (!toggle || !nav) return;
  toggle.addEventListener('click', function() {
    const open = nav.classList.toggle('nav-open');
    toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    toggle.classList.toggle('is-open', open);
  });
  nav.querySelectorAll('a').forEach(a => {
    a.addEventListener('click', () => {
      nav.classList.remove('nav-open');
      toggle.classList.remove('is-open');
      toggle.setAttribute('aria-expanded', 'false');
    });
  });
})();
</script>
<script<?php
  $__hnCsp3 = (string)($GLOBALS['csp_nonce'] ?? '');
  echo $__hnCsp3 !== '' ? ' nonce="' . htmlspecialchars($__hnCsp3, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
(function() {
  const input    = document.getElementById('globalSearchInput');
  const dropdown = document.getElementById('globalSearchDropdown');
  const wrapper  = document.getElementById('globalSearchWrapper');
  if (!input || !dropdown || !wrapper) return;

  let debounceTimer = null;
  let lastQuery = '';

  const ICONS = { clients: '👥', factures: '📄', sav: '🔧', livraisons: '📦' };
  const LABELS = { clients: 'Clients', factures: 'Factures', sav: 'SAV', livraisons: 'Livraisons' };

  function escHtml(s) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(String(s ?? '')));
    return d.innerHTML;
  }
  function escAttr(s) {
    return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;');
  }

  function showDropdown(results) {
    const categories = ['clients', 'factures', 'sav', 'livraisons'];
    let html = '';
    let total = 0;

    categories.forEach(cat => {
      const items = results[cat] || [];
      if (!items.length) return;
      total += items.length;
      html += '<div class="gs-group"><div class="gs-group-title">' + ICONS[cat] + ' ' + LABELS[cat] + '</div>';
      items.forEach(item => {
        html += '<a href="' + escAttr(item.url) + '" class="gs-item" role="option">' +
          '<span class="gs-item-label">' + escHtml(item.label) + '</span>' +
          (item.sub ? '<span class="gs-item-sub">' + escHtml(item.sub) + '</span>' : '') +
          '</a>';
      });
      html += '</div>';
    });

    if (total === 0) {
      html = '<div class="gs-empty">Aucun résultat</div>';
    }

    dropdown.innerHTML = html;
    dropdown.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function hideDropdown() {
    dropdown.hidden = true;
    input.setAttribute('aria-expanded', 'false');
  }

  function search(q) {
    if (q.length < 2) { hideDropdown(); return; }
    if (q === lastQuery) return;
    lastQuery = q;

    fetch('/API/global_search.php?q=' + encodeURIComponent(q), { credentials: 'include' })
      .then(r => r.json())
      .then(d => { if (d.ok) showDropdown(d.results); })
      .catch(() => {});
  }

  input.addEventListener('input', function() {
    clearTimeout(debounceTimer);
    const q = this.value.trim();
    if (q.length < 2) { hideDropdown(); lastQuery = ''; return; }
    debounceTimer = setTimeout(function() { search(q); }, 280);
  });

  input.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { hideDropdown(); this.blur(); }
    if (e.key === 'Enter') {
      const first = dropdown.querySelector('.gs-item');
      if (first) window.location.href = first.getAttribute('href');
    }
  });

  document.addEventListener('click', function(e) {
    if (!wrapper.contains(e.target)) hideDropdown();
  });

  input.addEventListener('focus', function() {
    if (this.value.trim().length >= 2) search(this.value.trim());
  });
})();
</script>
</header>
