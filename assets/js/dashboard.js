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

    // --- Filtre de recherche pour la pop-up ---
    if (clientSearchInput && allClientCards.length > 0) {
        clientSearchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();

            allClientCards.forEach(card => {
                const nom = card.dataset.nom || '';
                const prenom = card.dataset.prenom || '';
                const raison = card.dataset.raison || '';
                const numero = card.dataset.numero || '';

                if (nom.includes(searchTerm) || prenom.includes(searchTerm) || raison.includes(searchTerm) || numero.includes(searchTerm)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }
});