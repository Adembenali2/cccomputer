document.addEventListener('DOMContentLoaded', function() {

    // --- Notification d'import ---
    const notif = document.getElementById('importNotif');
    if (notif) {
        notif.style.display = 'flex';
        setTimeout(() => { notif.style.opacity = '0'; }, 3800);
        setTimeout(() => { notif.style.display = 'none'; }, 4400);
    }

    // --- Cartes cliquables ---
    const cards = document.querySelectorAll('.dash-card[data-href]');
    cards.forEach(card => {
        // Au clic sur la carte, on redirige vers la page
        card.addEventListener('click', () => {
            window.location.href = card.dataset.href;
        });

        // Pour l'accessibilité (activation avec les touches Entrée ou Espace)
        card.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                card.click();
            }
        });
    });

    // --- Pop-up de support ---
    const supportButton = document.getElementById('supportButton');
    const supportOverlay = document.getElementById('supportOverlay');
    const supportPopup = document.getElementById('supportPopup');
    const closePopup = document.getElementById('closePopup');
    const clientSearchInput = document.getElementById('clientSearchInput');
    const allClientCards = document.querySelectorAll('.clients-list .client-card');

    function openPopup() {
        if (supportOverlay && supportPopup) {
            supportOverlay.classList.add('active');
            supportPopup.classList.add('active');
        }
    }

    function closeThePopup() {
        if (supportOverlay && supportPopup) {
            supportOverlay.classList.remove('active');
            supportPopup.classList.remove('active');
        }
    }

    if (supportButton && supportPopup && closePopup && supportOverlay) {
        supportButton.addEventListener('click', openPopup);
        closePopup.addEventListener('click', closeThePopup);
        supportOverlay.addEventListener('click', closeThePopup);
    }

    // --- Filtre de recherche pour la pop-up (avec debounce pour performance) ---
    if (clientSearchInput && allClientCards.length > 0) {
        let searchTimeout;
        clientSearchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value.toLowerCase().trim();
            
            // Debounce: attendre 150ms avant de filtrer pour éviter trop de calculs
            searchTimeout = setTimeout(() => {
                let visibleCount = 0;
                allClientCards.forEach(card => {
                    const nom = (card.dataset.nom || '').toLowerCase();
                    const prenom = (card.dataset.prenom || '').toLowerCase();
                    const raison = (card.dataset.raison || '').toLowerCase();
                    const numero = (card.dataset.numero || '').toLowerCase();

                    if (!searchTerm || nom.includes(searchTerm) || prenom.includes(searchTerm) || raison.includes(searchTerm) || numero.includes(searchTerm)) {
                        card.style.display = 'flex';
                        visibleCount++;
                    } else {
                        card.style.display = 'none';
                    }
                });
                
                // Afficher un message si aucun résultat
                const listContainer = document.querySelector('.clients-list');
                if (listContainer) {
                    let noResultsMsg = listContainer.querySelector('.no-results-msg');
                    if (visibleCount === 0 && searchTerm) {
                        if (!noResultsMsg) {
                            noResultsMsg = document.createElement('div');
                            noResultsMsg.className = 'no-results-msg';
                            noResultsMsg.style.cssText = 'padding: 2rem; text-align: center; color: #666; font-style: italic;';
                            noResultsMsg.textContent = 'Aucun client trouvé';
                            listContainer.appendChild(noResultsMsg);
                        }
                        noResultsMsg.style.display = 'block';
                    } else if (noResultsMsg) {
                        noResultsMsg.style.display = 'none';
                    }
                }
            }, 150);
        });
    }
});