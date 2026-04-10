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
  <nav class="header-nav">
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
    <div class="notif-wrap">
    <button type="button" class="header-icon-btn notif-btn js-notif" id="btnNotif" title="Notifications"><svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg><span class="notif-badge" style="display:none">0</span></button>
    <div class="notif-dropdown" id="notifDropdown">
      <div class="notif-actions">
        <span style="font-weight:600;font-size:.85rem">Notifications</span>
        <button type="button" class="notif-markall" id="notifMarkAll">Tout marquer lu</button>
      </div>
      <div id="notifList"><p style="padding:1rem;color:var(--text-secondary);font-size:.85rem">Chargement...</p></div>
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
<script<?php
  $__hnCsp = (string)($GLOBALS['csp_nonce'] ?? '');
  echo $__hnCsp !== '' ? ' nonce="' . htmlspecialchars($__hnCsp, ENT_QUOTES, 'UTF-8') . '"' : '';
?>>
(function() {
  function csrfHeader() {
    const t = document.querySelector('.site-header')?.getAttribute('data-csrf-token') || '';
    return t ? { 'Content-Type': 'application/json', 'X-CSRF-Token': t } : { 'Content-Type': 'application/json' };
  }

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

  // ── Dropdown notifications ────────────────────────────────
  const btnNotif = document.getElementById('btnNotif');
  const dropdown = document.getElementById('notifDropdown');

  if (btnNotif && dropdown) {
    btnNotif.addEventListener('click', function(e) {
      e.stopPropagation();
      const isOpen = dropdown.classList.contains('open');
      dropdown.classList.toggle('open', !isOpen);
      if (!isOpen) loadNotifications();
    });

    document.addEventListener('click', () => dropdown.classList.remove('open'));
    dropdown.addEventListener('click', e => e.stopPropagation());

    document.getElementById('notifMarkAll')?.addEventListener('click', function() {
      fetch('/API/notifications_mark_read.php', {
        method: 'POST',
        credentials: 'include',
        headers: csrfHeader(),
        body: JSON.stringify({ all: true })
      })
        .then(() => { loadNotifications(); updateNotifBadge(0); });
    });
  }

  function loadNotifications() {
    const list = document.getElementById('notifList');
    if (!list) return;
    fetch('/API/notifications_get.php', { credentials: 'include' })
      .then(r => r.json())
      .then(d => {
        const notifs = d.notifications || [];
        if (notifs.length === 0) {
          list.innerHTML = '<p style="padding:1rem;color:var(--text-secondary);font-size:.85rem">Aucune notification.</p>';
          updateNotifBadge(0);
          return;
        }
        list.innerHTML = notifs.slice(0, 15).map(n => `
          <div class="notif-item ${n.lu ? '' : 'unread'}">
            <div class="title">${escHtml(n.titre || n.title || '')}</div>
            <div class="body">${escHtml(n.message || n.body || '')}</div>
            <div class="time">${escHtml(n.date_creation || n.created_at || '')}</div>
          </div>`).join('');
        updateNotifBadge(notifs.filter(n => !n.lu).length);
      }).catch(() => {
        list.innerHTML = '<p style="padding:1rem;color:var(--text-secondary)">Erreur.</p>';
      });
  }

  function updateNotifBadge(count) {
    const badge = document.querySelector('.notif-btn .notif-badge');
    const btn = document.getElementById('btnNotif');
    if (!badge || !btn) return;
    if (count > 0) {
      badge.textContent = count > 99 ? '99+' : String(count);
      badge.style.display = 'inline-block';
      btn.classList.add('has-unread');
    } else {
      badge.style.display = 'none';
      btn.classList.remove('has-unread');
    }
  }

  function escHtml(str) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(str));
    return d.innerHTML;
  }

  loadNotifications();
})();
</script>
</header>
