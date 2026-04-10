(function() {
  function applyInitialMode() {
    if (localStorage.getItem('darkMode') === '1') {
      document.documentElement.classList.add('dark-mode');
      document.body.classList.add('dark-mode');
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', applyInitialMode, { once: true });
  } else {
    applyInitialMode();
  }
})();

function toggleDarkMode() {
  const body = document.body;
  const isDark = body.classList.toggle('dark-mode');
  document.documentElement.classList.toggle('dark-mode', isDark);
  localStorage.setItem('darkMode', isDark ? '1' : '0');
  const icon = document.getElementById('iconDarkMode');
  if (icon) icon.textContent = isDark ? '☀️' : '🌙';
}

document.addEventListener('DOMContentLoaded', function() {
  const isDark = document.body.classList.contains('dark-mode');
  const icon = document.getElementById('iconDarkMode');
  if (icon) icon.textContent = isDark ? '☀️' : '🌙';
});

function toggleProfilMenu() {
  const m = document.getElementById('profilMenu');
  if (m) m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', function(e) {
  if (!e.target.closest('.profil-wrapper')) {
    const m = document.getElementById('profilMenu');
    if (m) m.style.display = 'none';
  }
});

function ouvrirCalendrier() { alert('📅 Calendrier — Bientôt disponible'); }
function ouvrirNotifications() { alert('🔔 Notifications — Bientôt disponible'); }
function ouvrirMessagerie() { alert('💬 Messagerie — Bientôt disponible'); }
function ouvrirMaps() { alert('🗺️ Maps — Bientôt disponible'); }

document.addEventListener('DOMContentLoaded', function() {
  const darkBtn = document.getElementById('btnDarkMode');
  if (darkBtn && !darkBtn.dataset.boundDark) {
    darkBtn.dataset.boundDark = '1';
    darkBtn.addEventListener('click', toggleDarkMode);
  }
  const profilBtn = document.getElementById('btnProfilMenu');
  if (profilBtn && !profilBtn.dataset.boundProfil) {
    profilBtn.dataset.boundProfil = '1';
    profilBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      toggleProfilMenu();
    });
  }
  document.querySelectorAll('.js-cal').forEach(el => el.addEventListener('click', function(e){ e.preventDefault(); ouvrirCalendrier(); }));
  document.querySelectorAll('.js-notif').forEach(el => el.addEventListener('click', function(e){ e.preventDefault(); ouvrirNotifications(); }));
  document.querySelectorAll('.js-msg').forEach(el => el.addEventListener('click', function(e){ e.preventDefault(); ouvrirMessagerie(); }));
  document.querySelectorAll('.js-maps').forEach(el => el.addEventListener('click', function(e){ e.preventDefault(); ouvrirMaps(); }));
});
