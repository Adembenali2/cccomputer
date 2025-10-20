// /assets/js/clients.js
(function() {
  // -------- Filtre client-side simple --------
  const q = document.getElementById('q');
  const clear = document.getElementById('clearQ');
  const rows = Array.from(document.querySelectorAll('#tbl tbody tr'));

  function applyFilter() {
    const val = (q?.value || '').trim().toLowerCase();
    rows.forEach(tr => {
      const hay = tr.getAttribute('data-search') || '';
      tr.style.display = hay.includes(val) ? '' : 'none';
    });
  }

  if (q) q.addEventListener('input', applyFilter);
  if (clear) clear.addEventListener('click', () => { if (q) { q.value = ''; applyFilter(); } });

  // -------- Panneau ajout client --------
  const btnAdd = document.getElementById('btnAddClient');
  const btnCancel = document.getElementById('btnCancelAdd');
  const panel = document.getElementById('addClientPanel');

  function openPanel() {
    if (!panel) return;
    panel.removeAttribute('hidden');
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function closePanel() {
    if (!panel) return;
    panel.setAttribute('hidden', 'hidden');
  }

  if (btnAdd) btnAdd.addEventListener('click', () => {
    if (panel?.hasAttribute('hidden')) openPanel(); else closePanel();
  });
  if (btnCancel) btnCancel.addEventListener('click', () => closePanel());

  // Ouvrir automatiquement si le serveur a détecté des erreurs de validation
  if (panel && panel.getAttribute('data-init-open') === '1') {
    openPanel();
  }

  // -------- Copie adresse de livraison si identique --------
  const chkLiv = document.getElementById('livraison_identique');
  const adr = document.querySelector('input[name="adresse"]');
  const adrLiv = document.getElementById('adresse_livraison');

  function syncLivraison() {
    if (!chkLiv || !adr || !adrLiv) return;
    if (chkLiv.checked) {
      adrLiv.value = adr.value;
      adrLiv.setAttribute('readonly', 'readonly');
    } else {
      adrLiv.removeAttribute('readonly');
    }
  }

  if (chkLiv) chkLiv.addEventListener('change', syncLivraison);
  if (adr) adr.addEventListener('input', () => { if (chkLiv?.checked && adrLiv) adrLiv.value = adr.value; });

  // init au chargement
  syncLivraison();
})();
