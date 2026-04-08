// /assets/js/clients.js
(function () {
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

  // -------- Popup "Ajouter un client" --------
  const openBtn  = document.getElementById('btnAddClient');
  const modal    = document.getElementById('clientModal');
  const overlay  = document.getElementById('clientModalOverlay');
  const closeBtn = document.getElementById('btnCloseModal');
  const cancelBtn= document.getElementById('btnCancelAdd');

  const chkLiv = document.getElementById('livraison_identique');
  const adr    = document.querySelector('input[name="adresse"]');
  const adrLiv = document.getElementById('adresse_livraison');

  function lockBody(lock) {
    document.documentElement.style.overflow = lock ? 'hidden' : '';
    document.body.style.overflow = lock ? 'hidden' : '';
  }

  function openModal() {
    if (!modal || !overlay) return;
    overlay.classList.add('active');
    modal.classList.add('active');
    lockBody(true);
    modal.setAttribute('aria-hidden', 'false');
    overlay.setAttribute('aria-hidden', 'false');

    // focus premier champ
    const first = modal.querySelector('input, select, textarea, button');
    if (first) first.focus();

    // sync livraison si coché
    syncLivraison();
  }

  function closeModal() {
    if (!modal || !overlay) return;
    overlay.classList.remove('active');
    modal.classList.remove('active');
    lockBody(false);
    modal.setAttribute('aria-hidden', 'true');
    overlay.setAttribute('aria-hidden', 'true');
  }

  if (openBtn)  openBtn.addEventListener('click', openModal);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
  if (overlay)  overlay.addEventListener('click', (e) => {
    // fermer uniquement si clic sur l’overlay, pas sur le contenu
    if (e.target === overlay) closeModal();
  });

  // Échap pour fermer
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal?.classList.contains('active')) {
      closeModal();
    }
  });

  // Ouverture auto si le serveur a renvoyé des erreurs
  if (window.__CLIENT_MODAL_INIT_OPEN__) openModal();

  // -------- Copie adresse de livraison si identique --------
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
})();
