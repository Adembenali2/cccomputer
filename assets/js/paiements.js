        // Instance Chart.js pour le graphique (évite la collision avec l'élément DOM id="statsChart")
        let statsChart = null;
        let currentData = null;

        /**
         * Récupère le token CSRF depuis le data attribute du body
         */
        function getCsrfToken() {
            return document.body.dataset.csrfToken || '';
        }

        /**
         * Retourne les headers avec le token CSRF pour les requêtes modifiantes
         */
        function getCsrfHeaders() {
            const token = getCsrfToken();
            return token ? { 'X-CSRF-Token': token } : {};
        }

        // ============================================
        // FONCTIONS GLOBALES - Doivent être définies en premier
        // ============================================
        
        /**
         * Ouvre une section spécifique
         */
        function openSection(section) {
            console.log('openSection appelé avec:', section);
            if (section === 'generer-facture') {
                openFactureModal();
            } else if (section === 'factures' || section === 'paiements') {
                openFacturesListModal();
            } else if (section === 'payer') {
                openPayerModal();
            } else if (section === 'facture-mail') {
                openFactureMailModal();
            } else if (section === 'generation-facture-clients') {
                openGenerationFactureClientsModal();
            } else if (section === 'envoi-masse') {
                openEnvoiMasseModal();
            } else if (section === 'programmer-envois') {
                openProgrammerEnvoisModal();
            } else {
                console.log('Ouverture de la section:', section);
                showToast(`Section "${section}" - À implémenter`, 'info');
            }
        }

        /**
         * Ouvre le modal de génération de facture
         */
        function openFactureModal() {
            console.log('Ouverture du modal de facture');
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            
            if (!modal) {
                console.error('Modal factureModal introuvable');
                showToast('Erreur: Le modal de facture est introuvable. Vérifiez la console pour plus de détails.', 'error');
                return;
            }
            if (!overlay) {
                console.error('Overlay factureModalOverlay introuvable');
                showToast('Erreur: L\'overlay du modal est introuvable. Vérifiez la console pour plus de détails.', 'error');
                return;
            }
            
            try {
                // Réinitialiser le formulaire
                const form = document.getElementById('factureForm');
                if (form) {
                    form.reset();
                    // Réinitialiser la date à aujourd'hui
                    const dateInput = document.getElementById('factureDate');
                    if (dateInput) {
                        dateInput.value = new Date().toISOString().split('T')[0];
                    }
                }
                
                // Réinitialiser les lignes
                const lignesContainer = document.getElementById('factureLignes');
                if (lignesContainer) {
                    lignesContainer.innerHTML = '';
                }
                document.getElementById('factureLignesContainer').style.display = 'none';
                document.getElementById('factureConsommationInfo').style.display = 'none';
                document.getElementById('factureClientNotifications').style.display = 'none';
                document.getElementById('factureClientNotifications').innerHTML = '';
                window.factureMachineData = null;
                
                // Réinitialiser les totaux
                calculateFactureTotal();
                
                // Réinitialiser le champ offre
                document.getElementById('factureOffre').value = '';
                
                // Réinitialiser les champs Achat
                const achatProduitsContainer = document.getElementById('factureAchatProduits');
                if (achatProduitsContainer) {
                    achatProduitsContainer.innerHTML = '';
                    addAchatProduit(); // Ajouter une première ligne vide
                }
                
                // Réinitialiser les champs Service
                const serviceDescription = document.getElementById('factureServiceDescription');
                const serviceMontant = document.getElementById('factureServiceMontant');
                if (serviceDescription) serviceDescription.value = '';
                if (serviceMontant) serviceMontant.value = '';
                
                // Afficher/masquer les champs selon le type
                onFactureTypeChange();
                
                // Afficher le modal (seulement l'overlay a besoin de la classe active)
                overlay.classList.add('active');
                // Le modal s'affichera automatiquement via le CSS .modal-overlay.active .modal
                document.body.style.overflow = 'hidden';
                
                // Charger la liste des clients
                loadClientsForFacture();
                console.log('Modal ouvert avec succès');
            } catch (error) {
                console.error('Erreur lors de l\'ouverture du modal:', error);
                showToast('Erreur lors de l\'ouverture du modal: ' + error.message, 'error');
            }
        }

        /**
         * Ferme le modal de génération de facture
         */
        function closeFactureModal() {
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                // Le modal se cachera automatiquement
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                const form = document.getElementById('factureForm');
                if (form) {
                    form.reset();
                }
                const lignes = document.getElementById('factureLignes');
                if (lignes) {
                    lignes.innerHTML = '';
                    addFactureLigne();
                }
            }
        }

        /**
         * Réinitialise le champ client (autocomplete) à l'ouverture du modal
         */
        function loadClientsForFacture() {
            const searchInput = document.getElementById('client_search');
            const hiddenInput = document.getElementById('factureClient');
            const suggestions = document.getElementById('client_suggestions');
            if (searchInput) searchInput.value = '';
            if (hiddenInput) hiddenInput.value = '';
            if (suggestions) { suggestions.innerHTML = ''; suggestions.style.display = 'none'; }
        }

        /**
         * Autocomplete client - debounce, AbortController, clavier
         */
        (function initClientAutocomplete() {
            const DEBOUNCE_MS = 200;
            const searchInput = document.getElementById('client_search');
            const hiddenInput = document.getElementById('factureClient');
            const suggestionsEl = document.getElementById('client_suggestions');
            if (!searchInput || !hiddenInput || !suggestionsEl) return;

            let debounceTimer = null;
            let abortController = null;
            let currentResults = [];
            let highlightedIndex = -1;

            function hideSuggestions() {
                suggestionsEl.innerHTML = '';
                suggestionsEl.style.display = 'none';
                highlightedIndex = -1;
            }

            function showSuggestions(results) {
                currentResults = results;
                highlightedIndex = -1;
                suggestionsEl.innerHTML = '';
                if (results.length === 0) {
                    suggestionsEl.style.display = 'none';
                    return;
                }
                results.forEach((r, i) => {
                    const div = document.createElement('div');
                    div.className = 'client-suggestion-item';
                    div.setAttribute('role', 'option');
                    div.setAttribute('data-id', r.id);
                    div.setAttribute('data-nom', r.nom);
                    div.textContent = r.nom;
                    div.addEventListener('click', () => selectClient(r.id, r.nom));
                    suggestionsEl.appendChild(div);
                });
                suggestionsEl.style.display = 'block';
            }

            function selectClient(id, nom) {
                hiddenInput.value = String(id);
                searchInput.value = nom;
                hideSuggestions();
                onFactureClientChange();
            }

            function updateHighlight() {
                const items = suggestionsEl.querySelectorAll('.client-suggestion-item');
                items.forEach((el, i) => el.classList.toggle('active', i === highlightedIndex));
            }

            searchInput.addEventListener('input', () => {
                hiddenInput.value = '';
                const q = searchInput.value.trim();
                if (q.length < 1) {
                    hideSuggestions();
                    return;
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    if (abortController) abortController.abort();
                    abortController = new AbortController();
                    fetch('/API/clients_search.php?q=' + encodeURIComponent(q), {
                        credentials: 'include',
                        signal: abortController.signal
                    })
                        .then(r => r.json())
                        .then(data => {
                            if (data.ok && data.results) showSuggestions(data.results);
                            else hideSuggestions();
                        })
                        .catch(err => { if (err.name !== 'AbortError') hideSuggestions(); });
                }, DEBOUNCE_MS);
            });

            searchInput.addEventListener('keydown', (e) => {
                const items = suggestionsEl.querySelectorAll('.client-suggestion-item');
                if (items.length === 0) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    highlightedIndex = Math.min(highlightedIndex + 1, items.length - 1);
                    updateHighlight();
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    highlightedIndex = Math.max(highlightedIndex - 1, -1);
                    updateHighlight();
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (highlightedIndex >= 0 && currentResults[highlightedIndex]) {
                        selectClient(currentResults[highlightedIndex].id, currentResults[highlightedIndex].nom);
                    }
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    hideSuggestions();
                }
            });

            searchInput.addEventListener('blur', () => {
                setTimeout(hideSuggestions, 150);
            });
        })();

        /**
         * Gère le changement de client
         */
        async function onFactureClientChange() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            
            // Vérifier les imprimantes et afficher les notifications
            await checkClientNotifications(clientId);
            
            if (clientId && offre) {
                await checkClientPhotocopieurs(clientId, offre);
                await loadConsommationData();
            }
        }

        /**
         * Vérifie les notifications pour un client (imprimantes et relevés)
         */
        async function checkClientNotifications(clientId) {
            const notificationsContainer = document.getElementById('factureClientNotifications');
            if (!notificationsContainer || !clientId) {
                if (notificationsContainer) notificationsContainer.style.display = 'none';
                return;
            }
            
            try {
                const response = await fetch(`/API/factures_check_photocopieurs.php?client_id=${encodeURIComponent(clientId)}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (!data.ok) {
                    notificationsContainer.style.display = 'none';
                    return;
                }
                
                const nbPhotocopieurs = data.nb_photocopieurs || 0;
                const dernierReleveJours = data.dernier_releve_jours;
                const notifications = [];
                
                // Notification 1: Pas d'imprimante attribuée
                if (nbPhotocopieurs === 0) {
                    notifications.push({
                        type: 'warning',
                        message: '⚠️ Ce client n\'a pas d\'imprimante attribuée.'
                    });
                }
                
                // Notification 2: Pas de relevé depuis plus de 2 jours
                if (nbPhotocopieurs > 0 && dernierReleveJours !== null && dernierReleveJours > 2) {
                    const joursText = dernierReleveJours === 1 ? 'jour' : 'jours';
                    notifications.push({
                        type: 'info',
                        message: `ℹ️ Aucun relevé de compteur reçu depuis ${dernierReleveJours} ${joursText}.`
                    });
                }
                
                // Afficher les notifications
                if (notifications.length > 0) {
                    notificationsContainer.innerHTML = '';
                    notifications.forEach(notif => {
                        const notifDiv = document.createElement('div');
                        notifDiv.style.padding = '0.75rem 1rem';
                        notifDiv.style.borderRadius = 'var(--radius-md)';
                        notifDiv.style.marginBottom = '0.5rem';
                        notifDiv.style.fontSize = '0.875rem';
                        
                        if (notif.type === 'warning') {
                            notifDiv.style.backgroundColor = '#FEF3C7';
                            notifDiv.style.border = '1px solid #FCD34D';
                            notifDiv.style.color = '#92400E';
                        } else {
                            notifDiv.style.backgroundColor = '#DBEAFE';
                            notifDiv.style.border = '1px solid #93C5FD';
                            notifDiv.style.color = '#1E40AF';
                        }
                        
                        notifDiv.textContent = notif.message;
                        notificationsContainer.appendChild(notifDiv);
                    });
                    notificationsContainer.style.display = 'block';
                } else {
                    notificationsContainer.style.display = 'none';
                }
            } catch (error) {
                console.error('Erreur lors de la vérification des notifications:', error);
                notificationsContainer.style.display = 'none';
            }
        }

        /**
         * Gère le changement de type de facture
         */
        function onFactureTypeChange() {
            const typeSelect = document.getElementById('factureType');
            if (!typeSelect) return;
            
            const type = typeSelect.value;
            const consommationFields = document.getElementById('factureConsommationFields');
            const achatFields = document.getElementById('factureAchatFields');
            const serviceFields = document.getElementById('factureServiceFields');
            const consommationInfo = document.getElementById('factureConsommationInfo');
            const lignesContainer = document.getElementById('factureLignesContainer');
            const factureOffre = document.getElementById('factureOffre');
            
            if (type === 'Achat') {
                // Masquer les autres champs
                if (consommationFields) consommationFields.style.display = 'none';
                if (serviceFields) serviceFields.style.display = 'none';
                if (consommationInfo) consommationInfo.style.display = 'none';
                if (lignesContainer) lignesContainer.style.display = 'none';
                
                // Retirer l'attribut required des champs Consommation et Service
                if (factureOffre) factureOffre.removeAttribute('required');
                const dateDebut = document.getElementById('factureDateDebut');
                const dateFin = document.getElementById('factureDateFin');
                if (dateDebut) dateDebut.removeAttribute('required');
                if (dateFin) dateFin.removeAttribute('required');
                const serviceDescription = document.getElementById('factureServiceDescription');
                const serviceMontant = document.getElementById('factureServiceMontant');
                if (serviceDescription) serviceDescription.removeAttribute('required');
                if (serviceMontant) serviceMontant.removeAttribute('required');
                
                // Afficher les champs Achat
                if (achatFields) achatFields.style.display = 'block';
                
                // Rendre les champs Achat requis
                const achatProduits = document.querySelectorAll('.achat-produit');
                achatProduits.forEach(produit => {
                    const typeSelect = produit.querySelector('.achat-produit-type');
                    const quantiteInput = produit.querySelector('.achat-produit-quantite');
                    const prixInput = produit.querySelector('.achat-produit-prix');
                    
                    if (typeSelect) typeSelect.setAttribute('required', 'required');
                    if (quantiteInput) quantiteInput.setAttribute('required', 'required');
                    if (prixInput) prixInput.setAttribute('required', 'required');
                });
                
                // Ajouter une première ligne de produit si le conteneur est vide
                const achatProduitsContainer = document.getElementById('factureAchatProduits');
                if (achatProduitsContainer && achatProduitsContainer.children.length === 0) {
                    addAchatProduit();
                }
                
                // Réinitialiser les totaux
                calculateFactureTotalAchat();
            } else if (type === 'Service') {
                // Masquer les autres champs
                if (consommationFields) consommationFields.style.display = 'none';
                if (achatFields) achatFields.style.display = 'none';
                if (consommationInfo) consommationInfo.style.display = 'none';
                if (lignesContainer) lignesContainer.style.display = 'none';
                
                // Retirer l'attribut required des champs Consommation et Achat
                if (factureOffre) factureOffre.removeAttribute('required');
                const dateDebut = document.getElementById('factureDateDebut');
                const dateFin = document.getElementById('factureDateFin');
                if (dateDebut) dateDebut.removeAttribute('required');
                if (dateFin) dateFin.removeAttribute('required');
                const achatProduits = document.querySelectorAll('.achat-produit');
                achatProduits.forEach(produit => {
                    const typeSelect = produit.querySelector('.achat-produit-type');
                    const autreInput = produit.querySelector('.achat-produit-autre');
                    const quantiteInput = produit.querySelector('.achat-produit-quantite');
                    const prixInput = produit.querySelector('.achat-produit-prix');
                    
                    if (typeSelect) typeSelect.removeAttribute('required');
                    if (autreInput) autreInput.removeAttribute('required');
                    if (quantiteInput) quantiteInput.removeAttribute('required');
                    if (prixInput) prixInput.removeAttribute('required');
                });
                
                // Afficher les champs Service
                if (serviceFields) serviceFields.style.display = 'block';
                
                // Rendre les champs Service requis
                const serviceDescription = document.getElementById('factureServiceDescription');
                const serviceMontant = document.getElementById('factureServiceMontant');
                if (serviceDescription) serviceDescription.setAttribute('required', 'required');
                if (serviceMontant) serviceMontant.setAttribute('required', 'required');
                
                // Réinitialiser les totaux
                calculateFactureTotalService();
            } else {
                // Type Consommation
                // Masquer les autres champs
                if (achatFields) achatFields.style.display = 'none';
                if (serviceFields) serviceFields.style.display = 'none';
                
                // Retirer l'attribut required de tous les champs Achat et Service pour éviter l'erreur de validation
                const achatProduits = document.querySelectorAll('.achat-produit');
                achatProduits.forEach(produit => {
                    const typeSelect = produit.querySelector('.achat-produit-type');
                    const autreInput = produit.querySelector('.achat-produit-autre');
                    const quantiteInput = produit.querySelector('.achat-produit-quantite');
                    const prixInput = produit.querySelector('.achat-produit-prix');
                    
                    if (typeSelect) typeSelect.removeAttribute('required');
                    if (autreInput) autreInput.removeAttribute('required');
                    if (quantiteInput) quantiteInput.removeAttribute('required');
                    if (prixInput) prixInput.removeAttribute('required');
                });
                const serviceDescription = document.getElementById('factureServiceDescription');
                const serviceMontant = document.getElementById('factureServiceMontant');
                if (serviceDescription) serviceDescription.removeAttribute('required');
                if (serviceMontant) serviceMontant.removeAttribute('required');
                
                // Afficher les champs Consommation
                if (consommationFields) consommationFields.style.display = 'flex';
                
                // Rendre les champs Consommation requis
                if (factureOffre) {
                    factureOffre.setAttribute('required', 'required');
                }
                
                // Réinitialiser les totaux
                calculateFactureTotal();
            }
        }

        /**
         * Calcule les totaux pour une facture de type Service
         */
        function calculateFactureTotalService() {
            const montantHT = parseFloat(document.getElementById('factureServiceMontant').value) || 0;
            const tauxTVA = 20; // TVA à 20%
            const tva = montantHT * (tauxTVA / 100);
            const totalTTC = montantHT + tva;
            
            document.getElementById('factureMontantHT').value = montantHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
        }

        /**
         * Ajoute une ligne de produit pour une facture Achat
         */
        function addAchatProduit() {
            const container = document.getElementById('factureAchatProduits');
            if (!container) return;
            
            const produitIndex = container.children.length;
            const produitDiv = document.createElement('div');
            produitDiv.className = 'facture-ligne achat-produit';
            produitDiv.style.marginBottom = '1rem';
            produitDiv.style.padding = '1rem';
            produitDiv.style.border = '1px solid var(--border-color)';
            produitDiv.style.borderRadius = 'var(--radius-md)';
            produitDiv.style.backgroundColor = 'var(--bg-secondary)';
            
            produitDiv.innerHTML = `
                <div class="facture-ligne-row" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr auto; gap: 1rem; align-items: end;">
                    <div class="facture-ligne-field">
                        <label>Type de produit <span style="color: #ef4444;">*</span></label>
                        <select name="achatProduits[${produitIndex}][type]" class="achat-produit-type" required onchange="onAchatProduitTypeChange(this)">
                            <option value="">Sélectionner un type</option>
                            <option value="PC">PC</option>
                            <option value="LCD">LCD</option>
                            <option value="Imprimante">Imprimante</option>
                            <option value="Papier">Papier</option>
                            <option value="Toner">Toner</option>
                            <option value="Autre">Autre (à préciser)</option>
                        </select>
                    </div>
                    <div class="facture-ligne-field achat-produit-autre-group" style="display: none;">
                        <label>Précision <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="achatProduits[${produitIndex}][autre]" class="achat-produit-autre" placeholder="Ex: Clavier, Souris">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Quantité <span style="color: #ef4444;">*</span></label>
                        <input type="number" name="achatProduits[${produitIndex}][quantite]" class="achat-produit-quantite" step="0.01" min="0" value="1" required onchange="calculateAchatProduitTotal(this)">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Prix unitaire HT (€) <span style="color: #ef4444;">*</span></label>
                        <input type="number" name="achatProduits[${produitIndex}][prix]" class="achat-produit-prix" step="0.01" min="0" value="0" required onchange="calculateAchatProduitTotal(this)">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Total HT (€)</label>
                        <input type="number" name="achatProduits[${produitIndex}][total]" class="achat-produit-total" step="0.01" value="0" readonly style="font-weight: 600;">
                    </div>
                    <div class="facture-ligne-actions">
                        <button type="button" class="btn-remove-ligne" onclick="removeAchatProduit(this)" style="padding: 0.5rem; background: var(--danger); color: white; border: none; border-radius: var(--radius-sm); cursor: pointer;">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6L6 18M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(produitDiv);
        }

        /**
         * Supprime une ligne de produit Achat
         */
        function removeAchatProduit(btn) {
            const container = document.getElementById('factureAchatProduits');
            if (container && container.children.length > 1) {
                btn.closest('.achat-produit').remove();
                calculateFactureTotalAchat();
            } else if (container && container.children.length === 1) {
                showToast('Vous devez avoir au moins un produit', 'error');
            }
        }

        /**
         * Gère le changement de type de produit (pour afficher/masquer le champ "Autre")
         */
        function onAchatProduitTypeChange(select) {
            const produitDiv = select.closest('.achat-produit');
            const autreGroup = produitDiv.querySelector('.achat-produit-autre-group');
            const autreInput = produitDiv.querySelector('.achat-produit-autre');
            
            if (select.value === 'Autre') {
                autreGroup.style.display = 'block';
                if (autreInput) autreInput.setAttribute('required', 'required');
            } else {
                autreGroup.style.display = 'none';
                if (autreInput) {
                    autreInput.removeAttribute('required');
                    autreInput.value = '';
                }
            }
            
            calculateAchatProduitTotal(select);
        }

        /**
         * Calcule le total d'un produit Achat
         */
        function calculateAchatProduitTotal(element) {
            const produitDiv = element.closest('.achat-produit');
            const quantite = parseFloat(produitDiv.querySelector('.achat-produit-quantite').value) || 0;
            const prix = parseFloat(produitDiv.querySelector('.achat-produit-prix').value) || 0;
            const total = quantite * prix;
            produitDiv.querySelector('.achat-produit-total').value = total.toFixed(2);
            calculateFactureTotalAchat();
        }

        /**
         * Calcule les totaux pour une facture de type Achat
         */
        function calculateFactureTotalAchat() {
            let totalHT = 0;
            document.querySelectorAll('.achat-produit-total').forEach(input => {
                totalHT += parseFloat(input.value) || 0;
            });
            
            const tauxTVA = 20; // TVA à 20%
            const tva = totalHT * (tauxTVA / 100);
            const totalTTC = totalHT + tva;
            
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
        }

        /**
         * Gère le changement d'offre
         */
        async function onFactureOffreChange() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            
            if (clientId && offre) {
                await checkClientPhotocopieurs(clientId, offre);
                await loadConsommationData();
            }
        }

        /**
         * Vérifie le nombre de photocopieurs pour l'offre 2000
         */
        async function checkClientPhotocopieurs(clientId, offre) {
            if (offre !== '2000') {
                return; // Pas de vérification nécessaire pour l'offre 1000
            }
            
            try {
                const response = await fetch(`/API/factures_check_photocopieurs.php?client_id=${clientId}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (data.nb_photocopieurs !== 2) {
                        showToast(`L'offre 2000 nécessite exactement 2 photocopieurs. Ce client a ${data.nb_photocopieurs} photocopieur(s).`, 'error');
                        document.getElementById('factureOffre').value = '';
                        return false;
                    }
                } else {
                    showToast('Erreur lors de la vérification des photocopieurs: ' + (data.error || 'Erreur inconnue'), 'error');
                    return false;
                }
            } catch (error) {
                console.error('Erreur lors de la vérification:', error);
                showToast('Erreur lors de la vérification des photocopieurs', 'error');
                return false;
            }
            
            return true;
        }

        /**
         * Charge les données de consommation et calcule automatiquement les lignes
         */
        async function loadConsommationData() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            const dateDebut = document.getElementById('factureDateDebut').value;
            const dateFin = document.getElementById('factureDateFin').value;
            
            if (!clientId || !offre) {
                document.getElementById('factureConsommationInfo').style.display = 'none';
                document.getElementById('factureLignesContainer').style.display = 'none';
                return;
            }
            
            if (!dateDebut || !dateFin) {
                document.getElementById('factureConsommationInfo').style.display = 'block';
                document.getElementById('factureConsommationContent').innerHTML = 
                    '<p style="color: var(--text-secondary);">Veuillez sélectionner les dates de début et fin de période</p>';
                document.getElementById('factureLignesContainer').style.display = 'none';
                return;
            }
            
            try {
                const url = `/API/factures_get_consommation.php?client_id=${encodeURIComponent(clientId)}&offre=${encodeURIComponent(offre)}&date_debut=${encodeURIComponent(dateDebut)}&date_fin=${encodeURIComponent(dateFin)}`;
                const response = await fetch(url, {
                    credentials: 'include'
                });
                
                // Vérifier si la réponse est OK
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    document.getElementById('factureConsommationInfo').style.display = 'block';
                    document.getElementById('factureConsommationContent').innerHTML = 
                        '<p style="color: #ef4444;">Erreur HTTP ' + response.status + ': ' + (errorText.substring(0, 200) || 'Erreur serveur') + '</p>';
                    document.getElementById('factureLignesContainer').style.display = 'none';
                    return;
                }
                
                const data = await response.json();
                
                if (data.ok) {
                    // Vérifier si des dates ont été ajustées et mettre à jour les champs
                    const notificationsContainer = document.getElementById('factureClientNotifications');
                    if (data.dates_ajustees && data.dates_ajustees.length > 0) {
                        // Mettre à jour les champs de date avec les dates ajustées
                        data.dates_ajustees.forEach(ajustement => {
                            if (ajustement.type === 'debut') {
                                // Convertir la date utilisée (dd/mm/yyyy) en format YYYY-MM-DD pour le champ
                                const dateParts = ajustement.date_utilisee.split('/');
                                if (dateParts.length === 3) {
                                    const dateAjustee = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
                                    document.getElementById('factureDateDebut').value = dateAjustee;
                                }
                            } else if (ajustement.type === 'fin') {
                                const dateParts = ajustement.date_utilisee.split('/');
                                if (dateParts.length === 3) {
                                    const dateAjustee = `${dateParts[2]}-${dateParts[1]}-${dateParts[0]}`;
                                    document.getElementById('factureDateFin').value = dateAjustee;
                                }
                            }
                        });
                        
                        // Afficher les notifications d'ajustement
                        if (notificationsContainer) {
                            let notificationsHtml = '';
                            data.dates_ajustees.forEach(ajustement => {
                                const typeText = ajustement.type === 'debut' ? 'début' : 'fin';
                                notificationsHtml += `
                                    <div style="padding: 0.75rem 1rem; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: var(--radius-md); margin-bottom: 0.5rem; font-size: 0.875rem; color: #92400E;">
                                        ℹ️ Aucun relevé reçu le ${ajustement.date_demandee}. Utilisation du dernier relevé disponible du ${ajustement.date_utilisee} (${ajustement.machine}).
                                    </div>
                                `;
                            });
                            notificationsContainer.innerHTML = notificationsHtml;
                            notificationsContainer.style.display = 'block';
                        }
                    }
                    
                    // Afficher les informations de consommation avec les compteurs de début et fin
                    let infoHtml = '<h4 style="margin: 0 0 1rem; font-size: 1rem; font-weight: 600;">Relevés de compteurs:</h4>';
                    
                    data.machines.forEach((machine, index) => {
                        const dateDebutFormatted = machine.date_debut_releve ? new Date(machine.date_debut_releve).toLocaleString('fr-FR') : 'Non trouvé';
                        const dateFinFormatted = machine.date_fin_releve ? new Date(machine.date_fin_releve).toLocaleString('fr-FR') : 'Non trouvé';
                        
                        infoHtml += `
                            <div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                                <div style="margin-bottom: 0.75rem; font-weight: 600; color: var(--text-primary); font-size: 1.05rem;">
                                    ${machine.nom} ${machine.modele ? '(' + machine.modele + ')' : ''}
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem;">
                                    <div style="padding: 0.75rem; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Jour de début période</div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem;">${dateDebutFormatted}</div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                            <span style="color: var(--text-primary);">N&B:</span>
                                            <strong style="color: var(--text-primary);">${machine.compteur_debut_nb.toLocaleString('fr-FR')}</strong>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: var(--text-primary);">Couleur:</span>
                                            <strong style="color: var(--text-primary);">${machine.compteur_debut_couleur.toLocaleString('fr-FR')}</strong>
                                        </div>
                                    </div>
                                    
                                    <div style="padding: 0.75rem; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Jour de fin période</div>
                                        <div style="font-size: 0.8rem; color: var(--text-muted); margin-bottom: 0.5rem;">${dateFinFormatted}</div>
                                        <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                            <span style="color: var(--text-primary);">N&B:</span>
                                            <strong style="color: var(--text-primary);">${machine.compteur_fin_nb.toLocaleString('fr-FR')}</strong>
                                        </div>
                                        <div style="display: flex; justify-content: space-between;">
                                            <span style="color: var(--text-primary);">Couleur:</span>
                                            <strong style="color: var(--text-primary);">${machine.compteur_fin_couleur.toLocaleString('fr-FR')}</strong>
                                        </div>
                                    </div>
                                </div>
                                
                                <div style="padding: 0.75rem; background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(16, 185, 129, 0.1)); border-radius: var(--radius-sm); border: 1px solid var(--accent-primary);">
                                    <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.5rem; font-weight: 600;">Consommation calculée (Fin - Début):</div>
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                                        <span style="color: var(--text-primary); font-weight: 600;">N&B:</span>
                                        <strong style="color: var(--accent-primary); font-size: 1.1rem;">${machine.conso_nb.toLocaleString('fr-FR')} copies</strong>
                                    </div>
                                    <div style="display: flex; justify-content: space-between;">
                                        <span style="color: var(--text-primary); font-weight: 600;">Couleur:</span>
                                        <strong style="color: var(--accent-primary); font-size: 1.1rem;">${machine.conso_couleur.toLocaleString('fr-FR')} copies</strong>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    document.getElementById('factureConsommationContent').innerHTML = infoHtml;
                    document.getElementById('factureConsommationInfo').style.display = 'block';
                    
                    // Générer les lignes de facture automatiquement
                    generateFactureLinesFromConsommation(data);
                } else {
                    document.getElementById('factureConsommationInfo').style.display = 'block';
                    document.getElementById('factureConsommationContent').innerHTML = 
                        '<p style="color: #ef4444;">Erreur: ' + (data.error || 'Impossible de charger les consommations') + '</p>';
                    document.getElementById('factureLignesContainer').style.display = 'none';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des consommations:', error);
                document.getElementById('factureConsommationInfo').style.display = 'block';
                document.getElementById('factureConsommationContent').innerHTML = 
                    '<p style="color: #ef4444;">Erreur lors du chargement des consommations</p>';
                document.getElementById('factureLignesContainer').style.display = 'none';
            }
        }

        /**
         * Génère les lignes de facture depuis les données de consommation
         */
        function generateFactureLinesFromConsommation(data) {
            const container = document.getElementById('factureLignes');
            container.innerHTML = '';
            
            // Préparer les données pour l'API
            const machines = {};
            data.machines.forEach((machine, index) => {
                machines[`machine${index + 1}`] = {
                    conso_nb: machine.conso_nb,
                    conso_couleur: machine.conso_couleur,
                    nom: machine.nom,
                    compteur_debut_nb: machine.compteur_debut_nb || 0,
                    compteur_debut_couleur: machine.compteur_debut_couleur || 0,
                    compteur_fin_nb: machine.compteur_fin_nb || 0,
                    compteur_fin_couleur: machine.compteur_fin_couleur || 0,
                    date_debut_releve: machine.date_debut_releve || null,
                    date_fin_releve: machine.date_fin_releve || null
                };
            });
            
            // Stocker les données pour la soumission
            window.factureMachineData = {
                offre: parseInt(data.offre),
                nb_imprimantes: data.machines.length,
                machines: machines
            };
            
            // Calculer les totaux côté client pour prévisualisation
            let totalHT = 0;
            const forfaitHT = 100.0;
            const prixExcessNB = 0.05;
            const prixCouleur = 0.09;
            const offre = parseInt(data.offre);
            
            // Calculer selon l'offre
            if (offre === 1000) {
                // Offre 1000: forfait 100€ HT + dépassement NB au-delà de 1000 + couleur × 0.09
                totalHT += forfaitHT; // Toujours le forfait
                
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    // Dépassement NB si > 1000
                    if (consoNB > 1000) {
                        const excessNB = consoNB - 1000;
                        totalHT += excessNB * prixExcessNB;
                    }
                    
                    // Couleur toujours calculée
                    if (consoCouleur > 0) {
                        totalHT += consoCouleur * prixCouleur;
                    }
                });
            } else if (offre === 2000) {
                // Offre 2000: forfait 100€ HT (une seule fois) + par imprimante (dépassement > 2000 + couleur)
                totalHT += forfaitHT; // Forfait une seule fois
                
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    // Dépassement NB si > 2000 par imprimante
                    if (consoNB > 2000) {
                        const excessNB = consoNB - 2000;
                        totalHT += excessNB * prixExcessNB;
                    }
                    
                    // Couleur toujours calculée par imprimante
                    if (consoCouleur > 0) {
                        totalHT += consoCouleur * prixCouleur;
                    }
                });
            }
            
            // Calculer TVA et TTC
            const tva = totalHT * 0.20;
            const totalTTC = totalHT + tva;
            
            // Mettre à jour l'affichage des totaux
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
            
            // Afficher un récapitulatif du calcul
            const infoDiv = document.createElement('div');
            infoDiv.style.padding = '1rem';
            infoDiv.style.background = 'var(--bg-secondary)';
            infoDiv.style.borderRadius = 'var(--radius-md)';
            infoDiv.style.marginBottom = '1rem';
            
            let detailCalcul = '';
            if (offre === 1000) {
                data.machines.forEach((machine, index) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    if (index === 0) {
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Forfait:</strong> ${forfaitHT.toFixed(2)} € HT</div>`;
                    }
                    
                    if (consoNB > 1000) {
                        const excessNB = consoNB - 1000;
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Dépassement N&B:</strong> ${excessNB} copies × ${prixExcessNB.toFixed(2)} € = ${(excessNB * prixExcessNB).toFixed(2)} € HT</div>`;
                    }
                    
                    if (consoCouleur > 0) {
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Couleur (${machine.nom}):</strong> ${consoCouleur} copies × ${prixCouleur.toFixed(2)} € = ${(consoCouleur * prixCouleur).toFixed(2)} € HT</div>`;
                    }
                });
            } else if (offre === 2000) {
                detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Forfait:</strong> ${forfaitHT.toFixed(2)} € HT</div>`;
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    if (consoNB > 2000) {
                        const excessNB = consoNB - 2000;
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Dépassement N&B (${machine.nom}):</strong> ${excessNB} copies × ${prixExcessNB.toFixed(2)} € = ${(excessNB * prixExcessNB).toFixed(2)} € HT</div>`;
                    }
                    
                    if (consoCouleur > 0) {
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Couleur (${machine.nom}):</strong> ${consoCouleur} copies × ${prixCouleur.toFixed(2)} € = ${(consoCouleur * prixCouleur).toFixed(2)} € HT</div>`;
                    }
                });
            }
            
            infoDiv.innerHTML = `
                <div style="margin-bottom: 0.75rem;">
                    <strong style="color: var(--text-primary); font-size: 1.05rem;">${data.machines.length} imprimante(s) détectée(s) - Offre ${offre}</strong>
                </div>
                <div style="font-size: 0.95rem; color: var(--text-secondary);">
                    ${detailCalcul || '<div>Aucun dépassement</div>'}
                </div>
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span><strong>Total HT:</strong></span>
                        <strong>${totalHT.toFixed(2)} €</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span><strong>TVA (20%):</strong></span>
                        <strong>${tva.toFixed(2)} €</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">
                        <span><strong style="font-size: 1.1rem;">Total TTC:</strong></span>
                        <strong style="font-size: 1.1rem; color: var(--accent-primary);">${totalTTC.toFixed(2)} €</strong>
                    </div>
                </div>
            `;
            container.appendChild(infoDiv);
            
            document.getElementById('factureLignesContainer').style.display = 'block';
        }

        /**
         * Ajoute une ligne de facture
         */
        function addFactureLigne() {
            const container = document.getElementById('factureLignes');
            const ligneIndex = container.children.length;
            const ligneDiv = document.createElement('div');
            ligneDiv.className = 'facture-ligne';
            ligneDiv.innerHTML = `
                <div class="facture-ligne-row">
                    <div class="facture-ligne-field">
                        <label>Description</label>
                        <input type="text" name="lignes[${ligneIndex}][description]" required placeholder="Description de la ligne">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Type</label>
                        <select name="lignes[${ligneIndex}][type]" required>
                            <option value="N&B">Noir et Blanc</option>
                            <option value="Couleur">Couleur</option>
                            <option value="Service">Service</option>
                            <option value="Produit">Produit</option>
                        </select>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Quantité</label>
                        <input type="number" name="lignes[${ligneIndex}][quantite]" step="0.01" min="0" value="1" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Prix unitaire HT (€)</label>
                        <input type="number" name="lignes[${ligneIndex}][prix_unitaire]" step="0.01" min="0" value="0" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Total HT (€)</label>
                        <input type="number" name="lignes[${ligneIndex}][total_ht]" step="0.01" min="0" value="0" readonly class="ligne-total">
                    </div>
                    <div class="facture-ligne-actions">
                        <button type="button" class="btn-remove-ligne" onclick="removeFactureLigne(this)">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6L6 18M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(ligneDiv);
            
            // Ajouter les event listeners pour calculer le total
            const inputs = ligneDiv.querySelectorAll('input[name*="[quantite]"], input[name*="[prix_unitaire]"]');
            inputs.forEach(input => {
                input.addEventListener('input', calculateLigneTotal);
            });
        }

        /**
         * Supprime une ligne de facture
         */
        function removeFactureLigne(btn) {
            if (document.getElementById('factureLignes').children.length > 1) {
                btn.closest('.facture-ligne').remove();
                calculateFactureTotal();
            }
        }

        /**
         * Calcule le total d'une ligne
         */
        function calculateLigneTotal(e) {
            const ligne = e.target.closest('.facture-ligne');
            const quantite = parseFloat(ligne.querySelector('input[name*="[quantite]"]').value) || 0;
            const prixUnitaire = parseFloat(ligne.querySelector('input[name*="[prix_unitaire]"]').value) || 0;
            const total = quantite * prixUnitaire;
            ligne.querySelector('.ligne-total').value = total.toFixed(2);
            calculateFactureTotal();
        }

        /**
         * Calcule le total de la facture
         */
        function calculateFactureTotal() {
            // Si on a des données de machine, ne pas recalculer (déjà calculé dans generateFactureLinesFromConsommation)
            if (window.factureMachineData) {
                return; // Les totaux sont déjà calculés et affichés
            }
            
            // Calcul classique depuis les lignes manuelles
            let totalHT = 0;
            document.querySelectorAll('.ligne-total').forEach(input => {
                totalHT += parseFloat(input.value) || 0;
            });
            
            const tauxTVA = 20; // TVA à 20%
            const tva = totalHT * (tauxTVA / 100);
            const totalTTC = totalHT + tva;
            
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
        }

        /**
         * Soumet le formulaire de facture
         */
        async function submitFactureForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('factureForm');
            const formData = new FormData(form);
            const data = {};
            
            // Convertir FormData en objet
            for (let [key, value] of formData.entries()) {
                if (key.startsWith('lignes[')) {
                    const match = key.match(/lignes\[(\d+)\]\[(\w+)\]/);
                    if (match) {
                        const index = parseInt(match[1]);
                        const field = match[2];
                        if (!data.lignes) data.lignes = [];
                        if (!data.lignes[index]) data.lignes[index] = {};
                        data.lignes[index][field] = value;
                    }
                } else {
                    data[key] = value;
                }
            }
            
            // Validation
            if (!data.factureClient) {
                showToast('Veuillez sélectionner un client dans la liste (tapez pour rechercher, puis cliquez ou appuyez sur Entrée)', 'error');
                return;
            }
            
            // Gérer le type "Service"
            if (data.factureType === 'Service') {
                const serviceDescription = data.serviceDescription || '';
                const serviceMontant = parseFloat(data.serviceMontant) || 0;
                
                if (!serviceDescription.trim()) {
                    showToast('Veuillez saisir une description du service', 'error');
                    return;
                }
                
                if (serviceMontant <= 0) {
                    showToast('Veuillez saisir un montant valide', 'error');
                    return;
                }
                
                // Créer la ligne de facture pour le service
                data.lignes = [{
                    description: serviceDescription.trim(),
                    type: 'Service',
                    quantite: 1,
                    prix_unitaire: serviceMontant,
                    total_ht: serviceMontant
                }];
            } else if (data.factureType === 'Achat') {
                // Récupérer tous les produits
                const produits = [];
                const produitInputs = document.querySelectorAll('.achat-produit');
                
                if (produitInputs.length === 0) {
                    showToast('Veuillez ajouter au moins un produit', 'error');
                    return;
                }
                
                produitInputs.forEach((produitDiv, index) => {
                    const type = produitDiv.querySelector('.achat-produit-type').value;
                    const autre = produitDiv.querySelector('.achat-produit-autre')?.value || '';
                    const quantite = parseFloat(produitDiv.querySelector('.achat-produit-quantite').value) || 0;
                    const prix = parseFloat(produitDiv.querySelector('.achat-produit-prix').value) || 0;
                    const total = parseFloat(produitDiv.querySelector('.achat-produit-total').value) || 0;
                    
                    if (!type) {
                        showToast(`Veuillez sélectionner un type pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    if (type === 'Autre' && !autre.trim()) {
                        showToast(`Veuillez préciser le type pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    if (quantite <= 0) {
                        showToast(`Veuillez saisir une quantité valide pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    if (prix <= 0) {
                        showToast(`Veuillez saisir un prix valide pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    const produitNom = type === 'Autre' ? autre.trim() : type;
                    produits.push({
                        description: produitNom,
                        type: 'Produit',
                        quantite: quantite,
                        prix_unitaire: prix,
                        total_ht: total
                    });
                });
                
                if (produits.length === 0) {
                    showToast('Veuillez ajouter au moins un produit valide', 'error');
                    return;
                }
                
                data.lignes = produits;
            } else if (window.factureMachineData) {
                // Si on a des données de machine (nouveau format), utiliser celui-ci
                data.offre = window.factureMachineData.offre;
                data.nb_imprimantes = window.factureMachineData.nb_imprimantes;
                data.machines = window.factureMachineData.machines;
            } else {
                // Ancien format: validation des lignes manuelles
                if (!data.lignes || data.lignes.length === 0) {
                    showToast('Veuillez ajouter au moins une ligne de facture ou sélectionner une offre', 'error');
                    return;
                }
            }
            
            const btnSubmit = document.getElementById('btnGenererFacture');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Génération en cours...';
            
            try {
                console.log('Envoi des données:', data);
                const response = await fetch('/API/factures_generer.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify(data),
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        showToast('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status), 'error');
                    } catch {
                        showToast('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200), 'error');
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    showToast('Facture générée avec succès !', 'success');
                    if (result.pdf_url) {
                        window.open(result.pdf_url, '_blank');
                    }
                    closeFactureModal();
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    showToast('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la requête:', error);
                showToast('Erreur lors de la génération de la facture: ' + error.message, 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Générer la facture';
            }
        }

        /**
         * Ouvre le modal de liste des factures
         */
        function openFacturesListModal() {
            const modal = document.getElementById('facturesListModal');
            const overlay = document.getElementById('facturesListModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal facturesListModal introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            switchFacturesTab('mois_en_cours');
            
            // Charger les factures
            loadFacturesList();
        }

        /**
         * Ferme le modal de liste des factures
         */
        function closeFacturesListModal() {
            const modal = document.getElementById('facturesListModal');
            const overlay = document.getElementById('facturesListModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        // Variable globale pour stocker toutes les factures
        let allFactures = [];
        let facturesActiveTab = 'mois_en_cours'; // 'mois_en_cours' | 'archive'
        let currentFactureStatusFilter = 'all';

        function getFacturesForCurrentTab() {
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth();
            const firstDayOfMonth = new Date(currentYear, currentMonth, 1);
            const firstDayStr = firstDayOfMonth.toISOString().slice(0, 10);
            const lastDayOfMonth = new Date(currentYear, currentMonth + 1, 0);
            const lastDayStr = lastDayOfMonth.toISOString().slice(0, 10);

            return allFactures.filter(f => {
                const d = f.date_facture || '';
                if (facturesActiveTab === 'mois_en_cours') {
                    return d >= firstDayStr && d <= lastDayStr;
                }
                return d < firstDayStr;
            });
        }

        function switchFacturesTab(tab) {
            facturesActiveTab = tab;
            const btnMois = document.getElementById('facturesTabMoisEnCours');
            const btnArchive = document.getElementById('facturesTabArchive');
            if (btnMois) {
                btnMois.style.color = tab === 'mois_en_cours' ? 'var(--accent-primary)' : 'var(--text-secondary)';
                btnMois.style.borderBottomColor = tab === 'mois_en_cours' ? 'var(--accent-primary)' : 'transparent';
            }
            if (btnArchive) {
                btnArchive.style.color = tab === 'archive' ? 'var(--accent-primary)' : 'var(--text-secondary)';
                btnArchive.style.borderBottomColor = tab === 'archive' ? 'var(--accent-primary)' : 'transparent';
            }
            filterFactures();
        }

        function updateFacturesTabCounts() {
            const now = new Date();
            const firstDayStr = new Date(now.getFullYear(), now.getMonth(), 1).toISOString().slice(0, 10);
            const moisCount = allFactures.filter(f => (f.date_facture || '') >= firstDayStr).length;
            const archiveCount = allFactures.filter(f => (f.date_facture || '') < firstDayStr).length;
            const spanMois = document.getElementById('facturesTabMoisCount');
            const spanArchive = document.getElementById('facturesTabArchiveCount');
            if (spanMois) spanMois.textContent = moisCount > 0 ? `(${moisCount})` : '';
            if (spanArchive) spanArchive.textContent = archiveCount > 0 ? `(${archiveCount})` : '';
        }

        /**
         * Charge la liste des factures depuis l'API
         */
        async function loadFacturesList() {
            const loadingDiv = document.getElementById('facturesListLoading');
            const container = document.getElementById('facturesListContainer');
            const errorDiv = document.getElementById('facturesListError');
            const countSpan = document.getElementById('facturesCount');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            // Réinitialiser les filtres
            ['facturesFilterNom', 'facturesFilterPrenom', 'facturesFilterNumero'].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.value = '';
            });
            
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    // Stocker toutes les factures pour le filtrage
                    allFactures = data.factures;
                    allPaiements = data.factures; // Alias pour compatibilité (bloc fusionné)
                    updateFacturesTabCounts();
                    // Afficher selon l'onglet actif (mois en cours par défaut)
                    filterFactures();
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    errorDiv.textContent = data.error || 'Erreur lors du chargement des factures';
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des factures:', error);
                errorDiv.textContent = 'Erreur: ' + error.message;
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
            }
        }

        /**
         * Affiche les factures dans le tableau
         */
        function displayFactures(factures) {
            const tableBody = document.getElementById('facturesListTableBody');
            const countSpan = document.getElementById('facturesCount');
            const filteredCountSpan = document.getElementById('facturesFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (countSpan) countSpan.textContent = factures.length;
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture trouvée
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
                updateFacturesSelectionCount();
            } else {
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Deux badges : Envoyé + Échéance (Payé/En attente/En cours/En retard)
                    const envoiLabel = (facture.statut_envoi === 'envoye') ? 'Envoyé' : 'Non envoyé';
                    const envoiColor = (facture.statut_envoi === 'envoye') ? '#3b82f6' : '#6b7280';
                    const echeanceLabels = { payee: 'Payé', en_attente: 'En attente', en_cours: 'En cours', en_retard: 'En retard', annulee: 'Annulée' };
                    const echeanceColors = { payee: '#10b981', en_attente: '#6b7280', en_cours: '#f59e0b', en_retard: '#ef4444', annulee: '#000000' };
                    const statutEcheance = facture.statut_echeance || facture.statut;
                    const echeanceLabel = echeanceLabels[statutEcheance] || statutEcheance;
                    const echeanceColor = echeanceColors[statutEcheance] || '#6b7280';
                    
                    // Boutons Actions : PDF, Modifier, Supprimer
                    const dateForInput = facture.date_facture || (facture.date_facture_formatted ? facture.date_facture_formatted.split('/').reverse().join('-') : '');
                    const safeNumero = (facture.numero || '').replace(/'/g, "\\'");
                    let actionButtons = `
                        <div style="display: flex; gap: 0.4rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                            ${facture.pdf_path ? `
                            <button onclick="viewFacturePDFById(${facture.id}, '${safeNumero}')" style="padding: 0.35rem 0.6rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem;" title="Voir PDF">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                PDF
                            </button>
                            ` : ''}
                            <button onclick="openModifierFactureModal(${facture.id}, '${safeNumero}', '${dateForInput}', '${facture.statut}', '${facture.type}', '${facture.date_debut_periode || ''}', '${facture.date_fin_periode || ''}', ${facture.client_id || 0})" style="padding: 0.35rem 0.6rem; background: #6366f1; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem;" title="Modifier">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                                Modifier
                            </button>
                            <button onclick="confirmSupprimerFacture(${facture.id}, '${safeNumero}')" style="padding: 0.35rem 0.6rem; background: #ef4444; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.8rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.3rem;" title="Supprimer">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><line x1="10" y1="11" x2="10" y2="17"></line><line x1="14" y1="11" x2="14" y2="17"></line></svg>
                                Supprimer
                            </button>
                        </div>
                    `;
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; text-align: center;">
                            <input type="checkbox" class="facture-select-cb" data-facture-id="${facture.id}" onchange="updateFacturesSelectionCount()">
                        </td>
                        <td style="padding: 0.75rem; font-weight: 600; color: var(--text-primary);">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${facture.client_nom}${facture.client_code ? ' (' + facture.client_code + ')' : ''}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.type}</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.montant_ht.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.tva.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">${facture.montant_ttc.toFixed(2).replace('.', ',')} €</td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <span style="display: inline-flex; gap: 0.4rem; flex-wrap: wrap; justify-content: center;">
                                <span style="display: inline-block; padding: 0.25rem 0.6rem; border-radius: var(--radius-md); background: ${envoiColor}20; color: ${envoiColor}; font-size: 0.8rem; font-weight: 600;">${envoiLabel}</span>
                                <span style="display: inline-block; padding: 0.25rem 0.6rem; border-radius: var(--radius-md); background: ${echeanceColor}20; color: ${echeanceColor}; font-size: 0.8rem; font-weight: 600;">${echeanceLabel}</span>
                            </span>
                        </td>
                                <td style="padding: 0.75rem; text-align: center;">${actionButtons}</td>
                    `;
                    tableBody.appendChild(row);
                });
                
                // Afficher le nombre de résultats filtrés si différent du total de l'onglet
                const tabTotal = getFacturesForCurrentTab().length;
                if (factures.length !== tabTotal) {
                    filteredCountSpan.textContent = `(${factures.length} sur ${tabTotal})`;
                } else {
                    filteredCountSpan.textContent = '';
                }
                updateFacturesSelectionCount();
            }
        }

        // Variable globale pour stocker le chemin du PDF actuel
        let currentPDFPath = '';

        /**
         * Ouvre le PDF d'une facture par son ID (avec régénération si nécessaire)
         */
        function viewFacturePDFById(factureId, factureNumero) {
            const pdfUrl = `/public/view_facture.php?id=${factureId}`;
            window.open(pdfUrl, '_blank');
        }

        let modifierFactureClientId = 0;

        /**
         * Ouvre le modal de modification d'une facture
         */
        function openModifierFactureModal(factureId, factureNumero, dateFacture, statut, type, dateDebutPeriode, dateFinPeriode, clientId) {
            modifierFactureClientId = clientId || 0;
            document.getElementById('modifierFactureId').value = factureId;
            document.getElementById('modifierFactureDate').value = dateFacture || '';
            document.getElementById('modifierFactureType').value = type || 'Consommation';
            document.getElementById('modifierFactureDateDebut').value = dateDebutPeriode || '';
            document.getElementById('modifierFactureDateFin').value = dateFinPeriode || '';
            document.getElementById('modifierFactureStatut').value = statut || 'brouillon';
            document.getElementById('modifierFactureError').style.display = 'none';
            const isConsommation = (type || '') === 'Consommation';
            document.getElementById('modifierFactureConsommation').style.display = isConsommation ? 'block' : 'none';
            document.getElementById('modifierFactureSimple').style.display = isConsommation ? 'none' : 'block';
            document.getElementById('modifierFactureMachinesContainer').innerHTML = '';
            if (isConsommation && modifierFactureClientId && dateDebutPeriode && dateFinPeriode) {
                refreshModifierFactureCompteurs();
            }
            document.getElementById('modifierFactureModalOverlay').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        async function refreshModifierFactureCompteurs() {
            const dateDebut = document.getElementById('modifierFactureDateDebut')?.value;
            const dateFin = document.getElementById('modifierFactureDateFin')?.value;
            const container = document.getElementById('modifierFactureMachinesContainer');
            if (!modifierFactureClientId || !dateDebut || !dateFin || !container) return;
            container.innerHTML = '<p style="color: var(--text-secondary);">Chargement des compteurs...</p>';
            try {
                const res = await fetch(`/API/factures_get_consommation.php?client_id=${modifierFactureClientId}&offre=1000&date_debut=${encodeURIComponent(dateDebut)}&date_fin=${encodeURIComponent(dateFin)}`, { credentials: 'include' });
                const data = await res.json();
                if (data.ok && data.machines && data.machines.length > 0) {
                    let html = '';
                    data.machines.forEach((m, i) => {
                        const nom = (m.nom || 'Imprimante ' + (i + 1)).replace(/"/g, '&quot;');
                        html += `
                            <div class="modifier-facture-machine-block" data-machine-nom="${nom}" style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-secondary); border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                                <strong style="display: block; margin-bottom: 0.75rem;">${m.nom || 'Imprimante ' + (i + 1)}</strong>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                    <div>
                                        <label style="font-size: 0.8rem; color: var(--text-secondary);">Compteur début N&B</label>
                                        <input type="number" min="0" class="modifier-facture-compteur" data-field="compteur_debut_nb" value="${m.compteur_debut_nb || 0}" style="width: 100%; padding: 0.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: var(--text-secondary);">Compteur début Couleur</label>
                                        <input type="number" min="0" class="modifier-facture-compteur" data-field="compteur_debut_couleur" value="${m.compteur_debut_couleur || 0}" style="width: 100%; padding: 0.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: var(--text-secondary);">Compteur fin N&B</label>
                                        <input type="number" min="0" class="modifier-facture-compteur" data-field="compteur_fin_nb" value="${m.compteur_fin_nb || 0}" style="width: 100%; padding: 0.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                                    </div>
                                    <div>
                                        <label style="font-size: 0.8rem; color: var(--text-secondary);">Compteur fin Couleur</label>
                                        <input type="number" min="0" class="modifier-facture-compteur" data-field="compteur_fin_couleur" value="${m.compteur_fin_couleur || 0}" style="width: 100%; padding: 0.5rem; border: 2px solid var(--border-color); border-radius: var(--radius-md);">
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<p style="color: #ef4444;">' + (data.error || 'Aucune donnée de consommation pour cette période') + '</p>';
                }
            } catch (e) {
                container.innerHTML = '<p style="color: #ef4444;">Erreur de chargement</p>';
            }
        }

        function closeModifierFactureModal() {
            document.getElementById('modifierFactureModalOverlay').classList.remove('active');
            document.body.style.overflow = '';
        }

        async function submitModifierFacture(event) {
            event.preventDefault();
            const factureId = document.getElementById('modifierFactureId').value;
            const dateFacture = document.getElementById('modifierFactureDate').value;
            const statut = document.getElementById('modifierFactureStatut').value;
            const type = document.getElementById('modifierFactureType').value;
            const dateDebut = document.getElementById('modifierFactureDateDebut').value;
            const dateFin = document.getElementById('modifierFactureDateFin').value;
            const errorDiv = document.getElementById('modifierFactureError');
            errorDiv.style.display = 'none';
            const csrf = document.body.dataset.csrfToken || document.querySelector('[data-csrf-token]')?.dataset?.csrfToken || '';
            const isConsommation = type === 'Consommation';
            try {
                let url, body;
                if (isConsommation && dateDebut && dateFin) {
                    const machines = [];
                    document.querySelectorAll('.modifier-facture-machine-block').forEach(block => {
                        const nom = block.dataset.machineNom || 'Imprimante';
                        const inputs = block.querySelectorAll('.modifier-facture-compteur');
                        const m = { nom };
                        inputs.forEach(inp => {
                            m[inp.dataset.field] = parseInt(inp.value, 10) || 0;
                        });
                        if (m.compteur_debut_nb !== undefined || m.compteur_fin_nb !== undefined) machines.push(m);
                    });
                    url = '/API/factures_regenerer.php';
                    body = JSON.stringify({
                        facture_id: parseInt(factureId),
                        date_debut_periode: dateDebut,
                        date_fin_periode: dateFin,
                        date_facture: dateFacture,
                        machines: machines.length > 0 ? machines : undefined
                    });
                } else {
                    url = '/API/factures_modifier.php';
                    body = JSON.stringify({ facture_id: parseInt(factureId), date_facture: dateFacture, statut, type });
                }
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body,
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    closeModifierFactureModal();
                    loadFacturesList();
                    showToast(isConsommation ? 'Facture régénérée avec succès' : 'Facture modifiée avec succès', 'success');
                } else {
                    errorDiv.textContent = data.error || 'Erreur inconnue';
                    errorDiv.style.display = 'block';
                }
            } catch (e) {
                errorDiv.textContent = 'Erreur de connexion';
                errorDiv.style.display = 'block';
            }
            return false;
        }

        function confirmSupprimerFacture(factureId, factureNumero) {
            if (confirm(`Êtes-vous sûr de vouloir supprimer la facture ${factureNumero} ?\n\nCette action est irréversible.`)) {
                supprimerFacture(factureId);
            }
        }

        async function supprimerFacture(factureId) {
            const csrf = document.body.dataset.csrfToken || document.querySelector('[data-csrf-token]')?.dataset?.csrfToken || '';
            try {
                const res = await fetch('/API/factures_supprimer.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ facture_id: parseInt(factureId) }),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    loadFacturesList();
                    showToast('Facture supprimée avec succès', 'success');
                } else {
                    showToast(data.error || 'Erreur lors de la suppression', 'error');
                }
            } catch (e) {
                showToast('Erreur de connexion', 'error');
            }
        }

        /**
         * Ouvre le modal pour voir le PDF d'une facture
         */
        function viewFacturePDF(pdfPath, factureNumero) {
            const modal = document.getElementById('pdfViewerModal');
            const overlay = document.getElementById('pdfViewerModalOverlay');
            const embed = document.getElementById('pdfViewerEmbed');
            const fallback = document.getElementById('pdfViewerFallback');
            const title = document.getElementById('pdfViewerTitle');
            
            if (!modal || !overlay) {
                console.error('Éléments du modal PDF introuvables');
                // Fallback : ouvrir directement dans un nouvel onglet
                window.open(pdfPath, '_blank');
                return;
            }
            
            currentPDFPath = pdfPath;
            title.textContent = `Facture ${factureNumero}`;
            
            // Essayer d'afficher avec embed
            if (embed) {
                embed.src = pdfPath;
                embed.style.display = 'block';
                if (fallback) {
                    fallback.style.display = 'none';
                }
                
                // Vérifier si le PDF se charge (timeout de 2 secondes)
                setTimeout(function() {
                    // Si l'embed n'a pas chargé, afficher le fallback
                    try {
                        if (embed.offsetHeight === 0 || embed.offsetWidth === 0) {
                            if (fallback) {
                                fallback.style.display = 'block';
                                embed.style.display = 'none';
                            }
                        }
                    } catch (e) {
                        // Si erreur, afficher le fallback
                        if (fallback) {
                            fallback.style.display = 'block';
                            embed.style.display = 'none';
                        }
                    }
                }, 2000);
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        /**
         * Ouvre le PDF dans un nouvel onglet
         */
        function openPDFInNewTab() {
            if (currentPDFPath) {
                window.open(currentPDFPath, '_blank');
            }
        }

        /**
         * Ferme le modal PDF
         */
        function closePDFViewer() {
            const modal = document.getElementById('pdfViewerModal');
            const overlay = document.getElementById('pdfViewerModalOverlay');
            const embed = document.getElementById('pdfViewerEmbed');
            
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Vider l'embed pour libérer la mémoire
                if (embed) {
                    embed.src = '';
                }
                currentPDFPath = '';
            }
        }


        /**
         * Filtre les factures selon nom, prénom, numéro (dans l'onglet actif)
         */
        function filterFacturesByStatus(status) {
            currentFactureStatusFilter = status;
            document.querySelectorAll('#facturesListModal .filter-btn-factures[data-status]').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.status === status) {
                    btn.classList.add('active');
                    btn.style.background = 'var(--accent-primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--accent-primary)';
                } else {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.color = 'var(--text-primary)';
                    btn.style.borderColor = 'var(--border-color)';
                }
            });
            filterFactures();
        }

        function filterFactures() {
            let baseFactures = getFacturesForCurrentTab();
            const filterNom = (document.getElementById('facturesFilterNom')?.value || '').toLowerCase().trim();
            const filterPrenom = (document.getElementById('facturesFilterPrenom')?.value || '').toLowerCase().trim();
            const filterNumero = (document.getElementById('facturesFilterNumero')?.value || '').toLowerCase().trim();
            const searchTerm = (document.getElementById('facturesSearchInput')?.value || '').toLowerCase().trim();

            // Filtre par statut
            if (currentFactureStatusFilter !== 'all') {
                if (['payee', 'en_attente', 'en_cours', 'en_retard', 'annulee'].includes(currentFactureStatusFilter)) {
                    baseFactures = baseFactures.filter(f => (f.statut_echeance || f.statut) === currentFactureStatusFilter);
                } else if (currentFactureStatusFilter === 'envoyee') {
                    baseFactures = baseFactures.filter(f => f.statut_envoi === 'envoye');
                } else if (currentFactureStatusFilter === 'brouillon') {
                    baseFactures = baseFactures.filter(f => f.statut_envoi === 'non_envoye');
                } else {
                    baseFactures = baseFactures.filter(f => f.statut === currentFactureStatusFilter);
                }
            }

            // Recherche globale
            if (searchTerm) {
                baseFactures = baseFactures.filter(f => {
                    const num = (f.numero || '').toLowerCase();
                    const client = (f.client_nom || '').toLowerCase();
                    const date = (f.date_facture_formatted || f.date_facture || '').toLowerCase();
                    return num.includes(searchTerm) || client.includes(searchTerm) || date.includes(searchTerm);
                });
            }

            // Filtres Nom, Prénom, Numéro
            if (filterNom || filterPrenom || filterNumero) {
                baseFactures = baseFactures.filter(facture => {
                    if (filterNumero && (!facture.numero || !facture.numero.toLowerCase().includes(filterNumero))) return false;
                    if (filterNom) {
                        const matchNom = (facture.client_nom && facture.client_nom.toLowerCase().includes(filterNom)) ||
                            (facture.client_nom_dirigeant && facture.client_nom_dirigeant.toLowerCase().includes(filterNom)) ||
                            (facture.client_code && facture.client_code.toLowerCase().includes(filterNom));
                        if (!matchNom) return false;
                    }
                    if (filterPrenom && (!facture.client_prenom_dirigeant || !facture.client_prenom_dirigeant.toLowerCase().includes(filterPrenom))) return false;
                    return true;
                });
            }

            displayFactures(baseFactures);
        }

        function toggleFacturesSelectAll(checkbox) {
            document.querySelectorAll('.facture-select-cb').forEach(cb => { cb.checked = checkbox.checked; });
            updateFacturesSelectionCount();
        }

        function updateFacturesSelectionCount() {
            const checked = document.querySelectorAll('.facture-select-cb:checked');
            const count = checked.length;
            const btn = document.getElementById('btnSupprimerSelection');
            const span = document.getElementById('facturesSelectedCount');
            if (btn) btn.disabled = count === 0;
            if (span) span.textContent = count;
            const selectAll = document.getElementById('facturesSelectAll');
            if (selectAll) selectAll.checked = count > 0 && count === document.querySelectorAll('.facture-select-cb').length;
        }

        async function supprimerFacturesSelection() {
            const checked = document.querySelectorAll('.facture-select-cb:checked');
            const ids = Array.from(checked).map(cb => parseInt(cb.dataset.factureId)).filter(id => id > 0);
            if (ids.length === 0) return;
            if (!confirm(`Supprimer ${ids.length} facture(s) ?\n\nCette action est irréversible.`)) return;
            const csrf = document.body.dataset.csrfToken || document.querySelector('[data-csrf-token]')?.dataset?.csrfToken || '';
            try {
                const res = await fetch('/API/factures_supprimer_masse.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                    body: JSON.stringify({ facture_ids: ids }),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    loadFacturesList();
                    const nb = data.supprimees?.length || ids.length;
                    const errs = data.erreurs?.length || 0;
                    showToast(errs > 0 ? `${nb} supprimée(s), ${errs} non supprimée(s) (paiements liés)` : `${nb} facture(s) supprimée(s)`, 'success');
                } else {
                    showToast(data.error || 'Erreur', 'error');
                }
            } catch (e) {
                showToast('Erreur de connexion', 'error');
            }
        }

        // ============================================
        // GESTION DES PAIEMENTS
        // ============================================
        
        let allPaiements = [];
        let filteredPaiements = [];
        let currentPaiementStatusFilter = 'all';

        /**
         * Ouvre le modal des paiements (redirige vers Factures - fusionné)
         */
        function openPaiementsModal() {
            openFacturesListModal();
        }

        /**
         * Ferme le modal des paiements (redirige vers Factures - fusionné)
         */
        function closePaiementsModal() {
            closeFacturesListModal();
        }

        /**
         * Charge la liste des factures pour les paiements (alias - fusionné dans Factures)
         */
        async function loadPaiementsList() {
            loadFacturesList();
        }

        /**
         * Affiche les factures (alias - fusionné, redirige vers displayFactures)
         */
        function displayPaiements(factures) {
            displayFactures(factures);
        }

        /**
         * Filtre les paiements selon le terme de recherche
         */
        function filterPaiements() {
            const searchInput = document.getElementById('paiementsSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            let filtered = allPaiements;
            
            // Filtrer par statut (statut_echeance pour payee/en_attente/en_cours/en_retard, statut_envoi pour envoyee/non_envoye)
            if (currentPaiementStatusFilter !== 'all') {
                if (['payee', 'en_attente', 'en_cours', 'en_retard', 'annulee'].includes(currentPaiementStatusFilter)) {
                    filtered = filtered.filter(f => (f.statut_echeance || f.statut) === currentPaiementStatusFilter);
                } else if (currentPaiementStatusFilter === 'envoyee') {
                    filtered = filtered.filter(f => f.statut_envoi === 'envoye');
                } else if (currentPaiementStatusFilter === 'brouillon') {
                    filtered = filtered.filter(f => f.statut_envoi === 'non_envoye');
                } else {
                    filtered = filtered.filter(f => f.statut === currentPaiementStatusFilter);
                }
            }
            
            // Filtrer par recherche textuelle
            if (searchTerm) {
                filtered = filtered.filter(facture => {
                    if (facture.numero && facture.numero.toLowerCase().includes(searchTerm)) return true;
                    if (facture.date_facture_formatted && facture.date_facture_formatted.includes(searchTerm)) return true;
                    if (facture.client_nom && facture.client_nom.toLowerCase().includes(searchTerm)) return true;
                    if (facture.client_code && facture.client_code.toLowerCase().includes(searchTerm)) return true;
                    return false;
                });
            }
            
            filteredPaiements = filtered;
            displayPaiements(filtered);
        }

        /**
         * Filtre les paiements par statut
         */
        function filterPaiementsByStatus(status) {
            currentPaiementStatusFilter = status;
            
            // Mettre à jour les boutons de filtre (paiements modal uniquement)
            document.querySelectorAll('#paiementsModal .filter-btn[data-status]').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.status === status) {
                    btn.classList.add('active');
                    btn.style.background = 'var(--accent-primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--accent-primary)';
                } else {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.color = 'var(--text-primary)';
                    btn.style.borderColor = 'var(--border-color)';
                }
            });
            
            filterPaiements();
        }

        /**
         * Met à jour le statut de paiement d'une facture
         */
        async function updatePaiementStatut(factureId, newStatut, factureNumero, selectElement) {
            const statutLabels = {
                'brouillon': 'En cours',
                'envoyee': 'En attente',
                'payee': 'Payé',
                'en_retard': 'En retard',
                'annulee': 'Annulée'
            };
            const newStatutLabel = statutLabels[newStatut] || newStatut;
            
            // Sauvegarder l'ancienne valeur au cas où l'utilisateur annule
            const oldValue = selectElement ? selectElement.getAttribute('data-old-value') || selectElement.value : null;
            
            if (!confirm(`Voulez-vous vraiment changer le statut de la facture ${factureNumero} en "${newStatutLabel}" ?`)) {
                // Remettre l'ancienne valeur si l'utilisateur annule
                if (selectElement && oldValue) {
                    selectElement.value = oldValue;
                }
                return;
            }
            
            // Sauvegarder la valeur actuelle
            if (selectElement) {
                selectElement.setAttribute('data-old-value', newStatut);
                selectElement.disabled = true;
                selectElement.style.opacity = '0.6';
                selectElement.style.cursor = 'wait';
            }
            
            try {
                const response = await fetch('/API/factures_update_statut.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify({
                        facture_id: factureId,
                        statut: newStatut
                    }),
                    credentials: 'include'
                });
                
                const result = await response.json();
                
                if (result.ok) {
                    // Afficher un message de succès
                    showMessage('Statut mis à jour avec succès', 'success');
                    // Recharger la liste
                    loadPaiementsList();
                } else {
                    showMessage('Erreur : ' + (result.error || 'Impossible de mettre à jour le statut'), 'error');
                    // Remettre l'ancienne valeur en cas d'erreur
                    if (selectElement && oldValue) {
                        selectElement.value = oldValue;
                    }
                    // Réactiver le select
                    if (selectElement) {
                        selectElement.disabled = false;
                        selectElement.style.opacity = '1';
                        selectElement.style.cursor = 'pointer';
                    }
                }
            } catch (error) {
                console.error('Erreur lors de la mise à jour du statut:', error);
                showMessage('Erreur lors de la mise à jour du statut', 'error');
                // Remettre l'ancienne valeur en cas d'erreur
                if (selectElement && oldValue) {
                    selectElement.value = oldValue;
                }
                // Réactiver le select
                if (selectElement) {
                    selectElement.disabled = false;
                    selectElement.style.opacity = '1';
                    selectElement.style.cursor = 'pointer';
                }
            }
        }
        
        /**
         * Affiche un message à l'utilisateur
         */
        function showMessage(message, type = 'success') {
            const messageContainer = document.getElementById('messageContainer');
            if (!messageContainer) return;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            
            const icon = type === 'success' ? '✓' : type === 'error' ? '✗' : '⚠';
            messageDiv.innerHTML = `
                <span class="message-icon">${icon}</span>
                <span>${message}</span>
            `;
            
            messageContainer.innerHTML = '';
            messageContainer.appendChild(messageDiv);
            
            // Masquer le message après 5 secondes
            setTimeout(() => {
                messageDiv.style.opacity = '0';
                messageDiv.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    messageContainer.innerHTML = '';
                }, 300);
            }, 5000);
        }

        // ============================================
        // GESTION DE L'HISTORIQUE DES PAIEMENTS
        // ============================================
        
        let allHistoriquePaiements = [];
        let filteredHistoriquePaiements = [];
        let currentHistoriqueStatusFilter = 'all';
        
        /**
         * Ouvre le modal d'historique des paiements
         */
        function openHistoriquePaiementsModal() {
            const modal = document.getElementById('historiquePaiementsModal');
            const overlay = document.getElementById('historiquePaiementsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal historique paiements introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser la recherche
            const searchInput = document.getElementById('historiquePaiementsSearchInput');
            if (searchInput) {
                searchInput.value = '';
            }
            
            // Charger l'historique
            loadHistoriquePaiements();
        }

        /**
         * Ferme le modal d'historique des paiements
         */
        function closeHistoriquePaiementsModal() {
            const modal = document.getElementById('historiquePaiementsModal');
            const overlay = document.getElementById('historiquePaiementsModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        /**
         * Charge l'historique des paiements
         */
        async function loadHistoriquePaiements() {
            const loadingDiv = document.getElementById('historiquePaiementsLoading');
            const container = document.getElementById('historiquePaiementsContainer');
            const errorDiv = document.getElementById('historiquePaiementsError');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/API/paiements_historique.php', {
                    credentials: 'include'
                });
                const result = await response.json();
                
                if (result.ok && result.paiements) {
                    allHistoriquePaiements = result.paiements;
                    filteredHistoriquePaiements = [...allHistoriquePaiements];
                    displayHistoriquePaiements(allHistoriquePaiements);
                    
                    // Mettre à jour le compteur
                    document.getElementById('historiquePaiementsCount').textContent = allHistoriquePaiements.length;
                    
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = result.error || 'Erreur lors du chargement de l\'historique';
                }
            } catch (error) {
                console.error('Erreur lors du chargement de l\'historique:', error);
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Erreur lors du chargement de l\'historique';
            }
        }

        /**
         * Affiche les paiements dans le tableau
         */
        function displayHistoriquePaiements(paiements) {
            const tableBody = document.getElementById('historiquePaiementsTableBody');
            const filteredCountSpan = document.getElementById('historiquePaiementsFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (paiements.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucun paiement trouvé
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                filteredCountSpan.textContent = `${paiements.length} affiché(s)`;
                
                paiements.forEach(paiement => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Badge de statut
                    const statutBadge = `
                        <span style="display: inline-block; padding: 0.4rem 0.75rem; border-radius: var(--radius-md); background: ${paiement.statut_color}20; color: ${paiement.statut_color}; font-size: 0.85rem; font-weight: 600; border: 1px solid ${paiement.statut_color}40;">
                            ${paiement.statut_label}
                        </span>
                    `;
                    
                    // Actions (Valider pour en_cours, Voir/Envoyer pour recu)
                    let actions = '<span style="color: var(--text-muted); font-size: 0.85rem;">-</span>';
                    if (paiement.statut === 'en_cours') {
                        actions = `
                            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center;">
                                ${paiement.recu_path ? `
                                    <button onclick="viewJustificatif('${paiement.recu_path}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line></svg>
                                        Voir
                                    </button>
                                ` : ''}
                                <button onclick="validatePaiement(${paiement.id}, '${(paiement.reference || '').replace(/'/g, "\\'")}')" style="padding: 0.4rem 0.75rem; background: #10b981; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';" title="Valider le paiement et envoyer le reçu au client">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                    Valider
                                </button>
                            </div>
                        `;
                    } else if (paiement.recu_path) {
                        actions = `
                            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center;">
                                <button onclick="viewJustificatif('${paiement.recu_path}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                    </svg>
                                    Voir
                                </button>
                                ${paiement.client_email ? `
                                    <button onclick="sendRecuEmail(${paiement.id}, '${(paiement.reference || '').replace(/'/g, "\\'")}')" style="padding: 0.4rem 0.75rem; background: #10b981; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';" title="Envoyer le reçu par email">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                                            <polyline points="22,6 12,13 2,6"></polyline>
                                        </svg>
                                        Envoyer
                                    </button>
                                ` : `
                                    <span style="color: var(--text-muted); font-size: 0.75rem;" title="Client sans email">Pas d'email</span>
                                `}
                            </div>
                        `;
                    }
                    
                    // Facture info
                    const factureInfo = paiement.facture_numero 
                        ? `${paiement.facture_numero}${paiement.facture_date_formatted ? ' (' + paiement.facture_date_formatted + ')' : ''}`
                        : '<span style="color: var(--text-muted); font-size: 0.85rem;">Sans facture</span>';
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; color: var(--text-primary);">${paiement.date_paiement_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${factureInfo}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">
                            ${paiement.client_nom || 'Client inconnu'}
                            ${paiement.client_code ? ` (${paiement.client_code})` : ''}
                        </td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary); font-weight: 600;">
                            ${paiement.montant.toFixed(2).replace('.', ',')} €
                        </td>
                        <td style="padding: 0.75rem; text-align: center; color: var(--text-primary);">
                            ${paiement.mode_paiement_label}
                        </td>
                        <td style="padding: 0.75rem; color: var(--text-secondary); font-size: 0.9rem;">
                            ${paiement.reference || '-'}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${statutBadge}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${actions}
                        </td>
                    `;
                    
                    tableBody.appendChild(row);
                });
                
                // Afficher le nombre de résultats filtrés si différent du total
                if (paiements.length !== allHistoriquePaiements.length) {
                    filteredCountSpan.textContent = `(${paiements.length} sur ${allHistoriquePaiements.length})`;
                } else {
                    filteredCountSpan.textContent = '';
                }
            }
        }

        /**
         * Filtre l'historique selon le terme de recherche
         */
        function filterHistoriquePaiements() {
            const searchInput = document.getElementById('historiquePaiementsSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            let filtered = allHistoriquePaiements;
            
            // Filtrer par statut
            if (currentHistoriqueStatusFilter !== 'all') {
                filtered = filtered.filter(p => p.statut === currentHistoriqueStatusFilter);
            }
            
            // Filtrer par recherche textuelle
            if (searchTerm) {
                filtered = filtered.filter(paiement => {
                    if (paiement.facture_numero && paiement.facture_numero.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.date_paiement_formatted && paiement.date_paiement_formatted.includes(searchTerm)) return true;
                    if (paiement.client_nom && paiement.client_nom.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.client_code && paiement.client_code.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.reference && paiement.reference.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.mode_paiement_label && paiement.mode_paiement_label.toLowerCase().includes(searchTerm)) return true;
                    if (paiement.commentaire && paiement.commentaire.toLowerCase().includes(searchTerm)) return true;
                    return false;
                });
            }
            
            filteredHistoriquePaiements = filtered;
            displayHistoriquePaiements(filtered);
        }

        /**
         * Filtre l'historique par statut
         */
        function filterHistoriquePaiementsByStatus(status) {
            currentHistoriqueStatusFilter = status;
            
            // Mettre à jour les boutons de filtre
            document.querySelectorAll('#historiquePaiementsModal .filter-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.filter === status) {
                    btn.classList.add('active');
                    btn.style.background = 'var(--accent-primary)';
                    btn.style.color = 'white';
                    btn.style.borderColor = 'var(--accent-primary)';
                } else {
                    btn.style.background = 'var(--bg-secondary)';
                    btn.style.color = 'var(--text-primary)';
                    btn.style.borderColor = 'var(--border-color)';
                }
            });
            
            filterHistoriquePaiements();
        }

        /**
         * Ouvre le justificatif dans un nouvel onglet
         */
        function viewJustificatif(recuPath) {
            if (recuPath) {
                window.open(recuPath, '_blank');
            }
        }

        /**
         * Valide un paiement (virement/chèque) : en_cours → recu, envoie le reçu au client
         */
        async function validatePaiement(paiementId, reference) {
            if (!paiementId) {
                (typeof showToast === 'function' ? showToast : alert)('Erreur: ID de paiement manquant', 'error');
                return;
            }
            if (!confirm(`Valider le paiement ${reference || ''} ? Les fonds seront considérés comme reçus et le reçu sera envoyé au client par email.`)) {
                return;
            }
            try {
                const response = await fetch('/API/paiements_valider.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...getCsrfHeaders() },
                    body: JSON.stringify({ paiement_id: paiementId, csrf_token: getCsrfToken() }),
                    credentials: 'include'
                });
                const data = await response.json();
                if (data.ok) {
                    (typeof showToast === 'function' ? showToast : alert)(data.recu_envoye ? 'Paiement validé. Reçu envoyé au client.' : 'Paiement validé.', 'success');
                    if (typeof loadHistoriquePaiements === 'function') loadHistoriquePaiements();
                } else {
                    (typeof showToast === 'function' ? showToast : alert)('Erreur: ' + (data.error || 'Validation échouée'), 'error');
                }
            } catch (error) {
                console.error('Erreur validation paiement:', error);
                (typeof showToast === 'function' ? showToast : alert)('Erreur lors de la validation', 'error');
            }
        }

        /**
         * Envoie le reçu de paiement par email au client
         */
        async function sendRecuEmail(paiementId, reference) {
            if (!paiementId) {
                if (typeof showNotification === 'function') {
                    showNotification('Erreur', 'ID de paiement manquant', 'error');
                } else {
                    showToast('Erreur: ID de paiement manquant', 'error');
                }
                return;
            }

            if (!confirm(`Êtes-vous sûr de vouloir envoyer le reçu ${reference || ''} par email au client ?`)) {
                return;
            }

            try {
                const response = await fetch('/API/paiements_envoyer_recu.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify({
                        paiement_id: paiementId
                    })
                });

                const data = await response.json();

                if (data.ok) {
                    if (typeof showNotification === 'function') {
                        showNotification('Succès', `Reçu envoyé avec succès à ${data.email || 'le client'}`, 'success');
                    } else {
                        showToast(`Reçu envoyé avec succès à ${data.email || 'le client'}`);
                    }
                    // Recharger l'historique pour mettre à jour le statut
                    if (typeof loadHistoriquePaiements === 'function') {
                        loadHistoriquePaiements();
                    }
                } else {
                    if (typeof showNotification === 'function') {
                        showNotification('Erreur', data.error || 'Erreur lors de l\'envoi du reçu', 'error');
                    } else {
                        showToast('Erreur: ' + (data.error || 'Erreur lors de l\'envoi du reçu'), 'error');
                    }
                }
            } catch (error) {
                console.error('Erreur envoi reçu:', error);
                if (typeof showNotification === 'function') {
                    showNotification('Erreur', 'Erreur lors de l\'envoi du reçu', 'error');
                } else {
                    showToast('Erreur lors de l\'envoi du reçu', 'error');
                }
            }
        }

        // ============================================
        // GESTION DU MODAL FACTURE MAIL
        // ============================================
        
        /**
         * Ouvre le modal d'envoi de facture par email
         */
        function openFactureMailModal() {
            const modal = document.getElementById('factureMailModal');
            const overlay = document.getElementById('factureMailModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal facture mail introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire et l'état
            const form = document.getElementById('factureMailForm');
            if (form) {
                form.reset();
            }
            
            factureMailState.isSubmitting = false;
            factureMailState.selectedFacture = null;
            hideFactureMailStatus();
            hideFactureMailResult();
            setFactureMailLoadingState(false);
            
            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
            if (btnRenvoyer) {
                btnRenvoyer.style.display = 'none';
            }
            
            // Réinitialiser la recherche facture
            resetFactureSearch();
        }

        /**
         * Réinitialise le champ recherche facture
         */
        function resetFactureSearch() {
            const searchInput = document.getElementById('facture_search');
            const hiddenInput = document.getElementById('facture_id');
            if (searchInput) searchInput.value = '';
            if (hiddenInput) hiddenInput.value = '';
            hideFactureSearchPanel('facture');
            const filterQ = document.getElementById('factureSearchFilterQ');
            if (filterQ) filterQ.value = '';
        }

        /**
         * Ferme le modal d'envoi de facture par email
         */
        function closeFactureMailModal() {
            const modal = document.getElementById('factureMailModal');
            const overlay = document.getElementById('factureMailModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                const form = document.getElementById('factureMailForm');
                if (form) {
                    form.reset();
                }
                resetFactureSearch();
            }
        }

        // État global pour l'envoi d'email
        let factureMailState = {
            isSubmitting: false,
            selectedFacture: null
        };

        /**
         * Format display text pour une facture
         */
        function formatFactureDisplayText(r) {
            return `N°${r.numero} - ${r.client_nom} - ${r.date_emission}`;
        }

        /**
         * Sélectionne une facture (modal email - sélection unique)
         */
        function selectFacture(r) {
            const searchInput = document.getElementById('facture_search');
            const hiddenInput = document.getElementById('facture_id');
            if (!searchInput || !hiddenInput) return;
            hiddenInput.value = String(r.id);
            searchInput.value = formatFactureDisplayText(r);
            hideFactureSearchPanel('facture');
            factureMailState.selectedFacture = {
                id: String(r.id),
                numero: r.numero,
                emailEnvoye: r.email_envoye || 0,
                dateEnvoi: r.date_envoi_email || ''
            };
            const emailInput = document.getElementById('factureMailEmail');
            if (emailInput) emailInput.value = r.client_email || '';
            const sujetInput = document.getElementById('factureMailSujet');
            if (sujetInput) sujetInput.value = `Facture ${r.numero} - CC Computer`;
            updateFactureMailStatus(String(r.email_envoye || 0), r.date_envoi_email || '');
            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
            if (btnRenvoyer) {
                if (r.email_envoye === 1) {
                    btnRenvoyer.style.display = 'inline-block';
                    btnRenvoyer.disabled = false;
                } else {
                    btnRenvoyer.style.display = 'none';
                    btnRenvoyer.disabled = true;
                }
            }
        }

        /**
         * Charge les factures depuis l'API et affiche les résultats
         */
        async function factureSearchLoad(context, params) {
            const cfg = context === 'facture' ? {
                panel: 'factureSearchPanel',
                results: 'facture_search_results',
                loading: 'facture_search_loading',
                empty: 'facture_search_empty',
                filterQ: 'factureSearchFilterQ',
                filterNom: 'factureSearchFilterNom',
                filterPrenom: 'factureSearchFilterPrenom',
                filterNumero: 'factureSearchFilterNumero',
                filterEmail: 'factureSearchFilterEmail',
                filterDate: 'factureSearchFilterDate',
                filterStatut: 'factureSearchFilterStatut'
            } : {
                panel: 'progFactureSearchPanel',
                results: 'progFactureSearchResults',
                loading: 'progFactureSearchLoading',
                empty: 'progFactureSearchEmpty',
                filterQ: 'progFactureSearchFilterQ',
                filterNom: 'progFactureSearchFilterNom',
                filterPrenom: 'progFactureSearchFilterPrenom',
                filterNumero: 'progFactureSearchFilterNumero',
                filterEmail: 'progFactureSearchFilterEmail',
                filterDate: 'progFactureSearchFilterDate',
                filterStatut: 'progFactureSearchFilterStatut'
            };
            const resultsEl = document.getElementById(cfg.results);
            const loadingEl = document.getElementById(cfg.loading);
            const emptyEl = document.getElementById(cfg.empty);
            if (!resultsEl || !loadingEl || !emptyEl) return [];

            loadingEl.style.display = 'block';
            resultsEl.style.display = 'none';
            emptyEl.style.display = 'none';

            const q = (params && params.q) || '';
            const nom = (params && params.nom) || '';
            const prenom = (params && params.prenom) || '';
            const numero = (params && params.numero_facture) || '';
            const email = (params && params.email) || '';
            const date = (params && params.date) || '';
            const statut = (params && params.statut) || '';

            const searchParams = new URLSearchParams();
            if (q) searchParams.set('q', q);
            if (nom) searchParams.set('nom', nom);
            if (prenom) searchParams.set('prenom', prenom);
            if (numero) searchParams.set('numero_facture', numero);
            if (email) searchParams.set('email', email);
            if (date) searchParams.set('date', date);
            if (statut) searchParams.set('statut', statut);
            searchParams.set('limit', '25');

            try {
                const res = await fetch('/API/factures_search.php?' + searchParams.toString(), { credentials: 'include' });
                const data = await res.json();
                loadingEl.style.display = 'none';
                if (data.ok && data.results && data.results.length > 0) {
                    resultsEl.style.display = 'block';
                    emptyEl.style.display = 'none';
                    return data.results;
                }
                resultsEl.style.display = 'none';
                emptyEl.style.display = 'block';
                return [];
            } catch (e) {
                loadingEl.style.display = 'none';
                emptyEl.style.display = 'block';
                emptyEl.textContent = 'Erreur de chargement';
                return [];
            }
        }

        /**
         * Affiche les résultats dans le panneau
         */
        function factureSearchRenderResults(context, results, multiSelect) {
            const cfg = context === 'facture' ? {
                results: 'facture_search_results',
                empty: 'facture_search_empty'
            } : {
                results: 'progFactureSearchResults',
                empty: 'progFactureSearchEmpty'
            };
            const resultsEl = document.getElementById(cfg.results);
            const emptyEl = document.getElementById(cfg.empty);
            if (!resultsEl || !emptyEl) return;

            const statutLabels = { brouillon: 'Brouillon', en_attente: 'En attente', envoyee: 'Envoyée', en_cours: 'En cours', payee: 'Payée', en_retard: 'En retard', annulee: 'Annulée' };
            resultsEl.innerHTML = '';
            results.forEach(r => {
                const row = document.createElement('div');
                row.className = 'facture-search-result-row';
                row.dataset.id = r.id;
                const montant = r.montant_ttc != null ? r.montant_ttc.toFixed(2).replace('.', ',') + ' €' : '-';
                const statutLabel = statutLabels[r.statut] || r.statut || '-';
                if (multiSelect) {
                    row.innerHTML = `
                        <input type="checkbox" class="result-checkbox" data-id="${r.id}" onclick="event.stopPropagation()">
                        <div class="result-content">
                            <span class="result-numero">${escapeHtml(r.numero || '#' + r.id)}</span>
                            <span class="result-client">${escapeHtml(r.client_nom || '')}</span>
                            <span class="result-email">${escapeHtml(r.client_email || '-')}</span>
                            <span class="result-date">${escapeHtml(r.date_emission || '-')}</span>
                            <span class="result-montant">${escapeHtml(montant)}</span>
                            <span class="result-statut">${escapeHtml(statutLabel)}</span>
                        </div>
                    `;
                    row.addEventListener('click', (e) => {
                        if (!e.target.classList.contains('result-checkbox')) {
                            const cb = row.querySelector('.result-checkbox');
                            if (cb) {
                                cb.checked = !cb.checked;
                                row.classList.toggle('selected', cb.checked);
                            }
                        }
                    });
                    row.querySelector('.result-checkbox').addEventListener('change', function() {
                        row.classList.toggle('selected', this.checked);
                    });
                } else {
                    row.innerHTML = `
                        <div class="result-content" style="grid-column: 1 / -1;">
                            <span class="result-numero">${escapeHtml(r.numero || '#' + r.id)}</span>
                            <span class="result-client">${escapeHtml(r.client_nom || '')}</span>
                            <span class="result-email">${escapeHtml(r.client_email || '-')}</span>
                            <span class="result-date">${escapeHtml(r.date_emission || '-')}</span>
                            <span class="result-montant">${escapeHtml(montant)}</span>
                            <span class="result-statut">${escapeHtml(statutLabel)}</span>
                        </div>
                    `;
                    row.addEventListener('click', () => selectFacture(r));
                }
                resultsEl.appendChild(row);
            });
        }

        function escapeHtml(s) {
            if (s == null) return '';
            const div = document.createElement('div');
            div.textContent = String(s);
            return div.innerHTML;
        }

        /**
         * Affiche le panneau de recherche et charge les résultats
         */
        function showFactureSearchPanel(context) {
            const panelId = context === 'facture' ? 'factureSearchPanel' : 'progFactureSearchPanel';
            const panel = document.getElementById(panelId);
            if (!panel) return;
            panel.style.display = 'flex';
            const params = factureSearchGetFilterParams(context);
            factureSearchLoad(context, params).then(results => {
                factureSearchRenderResults(context, results, context === 'prog');
            });
        }

        /**
         * Cache le panneau de recherche
         */
        function hideFactureSearchPanel(context) {
            const panelId = context === 'facture' ? 'factureSearchPanel' : 'progFactureSearchPanel';
            const panel = document.getElementById(panelId);
            if (panel) panel.style.display = 'none';
        }

        /**
         * Récupère les paramètres des filtres
         */
        function factureSearchGetFilterParams(context) {
            const prefix = context === 'facture' ? 'factureSearchFilter' : 'progFactureSearchFilter';
            const qEl = document.getElementById(prefix + 'Q');
            const mainInput = context === 'facture' ? document.getElementById('facture_search') : document.getElementById('progFactureSearch');
            const q = (mainInput && mainInput.value.trim()) || (qEl && qEl.value.trim()) || '';
            return {
                q,
                nom: (document.getElementById(prefix + 'Nom') || {}).value || '',
                prenom: (document.getElementById(prefix + 'Prenom') || {}).value || '',
                numero_facture: (document.getElementById(prefix + 'Numero') || {}).value || '',
                email: (document.getElementById(prefix + 'Email') || {}).value || '',
                date: (document.getElementById(prefix + 'Date') || {}).value || '',
                statut: (document.getElementById(prefix + 'Statut') || {}).value || ''
            };
        }

        /**
         * Applique les filtres et recharge
         */
        function factureSearchApplyFilters(context) {
            const params = factureSearchGetFilterParams(context);
            factureSearchLoad(context, params).then(results => {
                factureSearchRenderResults(context, results, context === 'prog');
            });
        }

        /**
         * Init recherche facture - modal email (sélection unique)
         */
        (function initFactureSearch() {
            const DEBOUNCE_MS = 350;
            const searchInput = document.getElementById('facture_search');
            const hiddenInput = document.getElementById('facture_id');
            const panel = document.getElementById('factureSearchPanel');
            const filterQ = document.getElementById('factureSearchFilterQ');
            if (!searchInput || !hiddenInput || !panel) return;

            let debounceTimer = null;

            searchInput.addEventListener('focus', () => {
                if (filterQ) filterQ.value = searchInput.value.trim();
                showFactureSearchPanel('facture');
            });
            searchInput.addEventListener('input', () => {
                hiddenInput.value = '';
                factureMailState.selectedFacture = null;
                hideFactureMailStatus();
                const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
                if (btnRenvoyer) btnRenvoyer.style.display = 'none';
                if (filterQ) filterQ.value = searchInput.value;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => factureSearchApplyFilters('facture'), DEBOUNCE_MS);
            });
            if (filterQ) {
                filterQ.addEventListener('input', () => { searchInput.value = filterQ.value; });
            }
            document.addEventListener('click', (e) => {
                if (panel.contains(e.target) || searchInput.contains(e.target)) return;
                hideFactureSearchPanel('facture');
            });
        })();

        /**
         * Init recherche facture - modal programmer (sélection multiple)
         */
        (function initProgFactureSearch() {
            const DEBOUNCE_MS = 350;
            const searchInput = document.getElementById('progFactureSearch');
            const panel = document.getElementById('progFactureSearchPanel');
            const filterQ = document.getElementById('progFactureSearchFilterQ');
            if (!searchInput || !panel) return;

            let debounceTimer = null;

            searchInput.addEventListener('focus', () => {
                if (filterQ) filterQ.value = searchInput.value.trim();
                showFactureSearchPanel('prog');
            });
            searchInput.addEventListener('input', () => {
                if (filterQ) filterQ.value = searchInput.value;
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => factureSearchApplyFilters('prog'), DEBOUNCE_MS);
            });
            if (filterQ) {
                filterQ.addEventListener('input', () => { searchInput.value = filterQ.value; });
            }
            document.addEventListener('click', (e) => {
                if (panel.contains(e.target) || searchInput.contains(e.target)) return;
                hideFactureSearchPanel('prog');
            });
        })();

        function progFactureSelectAll() {
            document.querySelectorAll('#progFactureSearchResults .result-checkbox').forEach(cb => {
                cb.checked = true;
                cb.closest('.facture-search-result-row').classList.add('selected');
            });
        }
        function progFactureDeselectAll() {
            document.querySelectorAll('#progFactureSearchResults .result-checkbox').forEach(cb => {
                cb.checked = false;
                cb.closest('.facture-search-result-row').classList.remove('selected');
            });
        }
        function progFactureValidateSelection() {
            const checked = Array.from(document.querySelectorAll('#progFactureSearchResults .result-checkbox:checked')).map(cb => parseInt(cb.dataset.id));
            const typeEnvoi = document.getElementById('progTypeEnvoi').value;
            if (typeEnvoi === 'une_facture') {
                if (checked.length === 1) {
                    progFacturesMultiSelected = checked;
                    document.getElementById('progFactureId').value = checked[0];
                    const row = document.querySelector(`#progFactureSearchResults .result-checkbox[data-id="${checked[0]}"]`)?.closest('.facture-search-result-row');
                    const numero = row?.querySelector('.result-numero')?.textContent || '#' + checked[0];
                    const client = row?.querySelector('.result-client')?.textContent || '';
                    document.getElementById('progFactureSearch').value = `N°${numero} - ${client}`;
                    hideFactureSearchPanel('prog');
                } else if (checked.length > 1) {
                    showToast('Sélectionnez une seule facture pour ce type d\'envoi', 'error');
                } else {
                    showToast('Sélectionnez une facture', 'error');
                }
            } else {
                // Fusionner avec la sélection existante (évite de perdre les factures des recherches précédentes)
                progFacturesMultiSelected = [...new Set([...progFacturesMultiSelected, ...checked])];
                const listEl = document.getElementById('progFacturesMultiList');
                if (listEl) {
                    listEl.innerHTML = progFacturesMultiSelected.length ? progFacturesMultiSelected.map(fid => `<span style="display:inline-block; margin:0.25rem; padding:0.25rem 0.5rem; background:var(--bg-secondary); border-radius:4px;">#${fid} <button type="button" style="margin-left:0.25rem; border:none; background:none; cursor:pointer; color:#ef4444;" onclick="progRemoveFacture(${fid})">&times;</button></span>`).join('') : '<em style="color:var(--text-secondary);">Aucune facture sélectionnée</em>';
                }
                document.getElementById('progFactureSearch').value = '';
                hideFactureSearchPanel('prog');
                showToast(progFacturesMultiSelected.length + ' facture(s) sélectionnée(s)', 'success');
            }
        }

        /**
         * Met à jour le badge de statut de la facture
         */
        function updateFactureMailStatus(emailEnvoye, dateEnvoi) {
            const badge = document.getElementById('factureMailStatusBadge');
            if (!badge) return;
            
            badge.style.display = 'inline-flex';
            const badgeText = badge.querySelector('.status-badge-text');
            const badgeIcon = badge.querySelector('.status-badge-icon');
            
            // Retirer toutes les classes de statut
            badge.classList.remove('status-none', 'status-pending', 'status-sent', 'status-failed');
            
            if (emailEnvoye === '0') {
                badge.classList.add('status-none');
                badgeText.textContent = 'Non envoyée';
            } else if (emailEnvoye === '2') {
                badge.classList.add('status-pending');
                badgeText.textContent = 'En cours d\'envoi';
            } else if (emailEnvoye === '1') {
                badge.classList.add('status-sent');
                if (dateEnvoi) {
                    const date = new Date(dateEnvoi);
                    badgeText.textContent = `Envoyée le ${date.toLocaleDateString('fr-FR')} à ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
                } else {
                    badgeText.textContent = 'Envoyée';
                }
            } else {
                badge.classList.add('status-failed');
                badgeText.textContent = 'Échec';
            }
        }

        /**
         * Cache le badge de statut
         */
        function hideFactureMailStatus() {
            const badge = document.getElementById('factureMailStatusBadge');
            if (badge) {
                badge.style.display = 'none';
            }
        }

        /**
         * Soumet le formulaire d'envoi de facture par email
         */
        async function submitFactureMailForm(e) {
            e.preventDefault();
            
            // Protection contre double clic
            if (factureMailState.isSubmitting) {
                return;
            }
            
            const form = document.getElementById('factureMailForm');
            const formData = new FormData(form);
            
            // Validation
            const factureId = formData.get('facture_id');
            const email = formData.get('email');
            
            if (!factureId) {
                showToast('Veuillez sélectionner une facture', 'error');
                return;
            }
            
            if (!email || !email.includes('@')) {
                showToast('Veuillez saisir une adresse email valide', 'error');
                return;
            }
            
            // Mettre à jour l'état
            factureMailState.isSubmitting = true;
            setFactureMailLoadingState(true);
            
            try {
                const data = {
                    facture_id: parseInt(factureId)
                };
                
                const response = await fetch('/API/factures_envoyer_email.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify(data),
                    credentials: 'include'
                });
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    // Succès
                    showFactureMailSuccess(result);
                    showToast('Email envoyé avec succès !', 'success');
                    
                    // Réinitialiser pour permettre un nouvel envoi
                    setTimeout(() => {
                        resetFactureSearch();
                    }, 1000);
                } else {
                    // Erreur
                    const errorMsg = result.error || 'Erreur inconnue';
                    showFactureMailError(errorMsg);
                    showToast('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de l\'envoi:', error);
                showFactureMailError('Erreur de connexion : ' + error.message);
                showToast('Erreur lors de l\'envoi de l\'email', 'error');
            } finally {
                factureMailState.isSubmitting = false;
                setFactureMailLoadingState(false);
            }
        }

        /**
         * Renvoie une facture déjà envoyée
         */
        async function renvoyerFactureMail() {
            if (factureMailState.isSubmitting || !factureMailState.selectedFacture) {
                return;
            }
            
            // Utiliser la même fonction mais avec force=true (géré côté backend)
            await submitFactureMailForm(new Event('submit'));
        }

        /**
         * Met à jour l'état de chargement de l'UI
         */
        function setFactureMailLoadingState(loading) {
            const btnSubmit = document.getElementById('btnEnvoyerFactureMail');
            const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
            const btnText = btnSubmit.querySelector('.btn-text');
            const btnLoader = btnSubmit.querySelector('.btn-loader');
            const form = document.getElementById('factureMailForm');
            
            if (loading) {
                btnSubmit.disabled = true;
                if (btnRenvoyer) btnRenvoyer.disabled = true;
                if (btnText) btnText.style.display = 'none';
                if (btnLoader) btnLoader.style.display = 'inline-flex';
                if (form) {
                    const inputs = form.querySelectorAll('input, select, textarea, button');
                    inputs.forEach(input => {
                        if (input !== btnSubmit && input !== btnRenvoyer) {
                            input.disabled = true;
                        }
                    });
                }
            } else {
                btnSubmit.disabled = false;
                if (btnRenvoyer) btnRenvoyer.disabled = factureMailState.selectedFacture?.emailEnvoye !== 1;
                if (btnText) btnText.style.display = 'inline';
                if (btnLoader) btnLoader.style.display = 'none';
                if (form) {
                    const inputs = form.querySelectorAll('input, select, textarea');
                    inputs.forEach(input => {
                        input.disabled = false;
                    });
                }
            }
        }

        /**
         * Affiche le résultat de succès
         */
        function showFactureMailSuccess(result) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result success';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = '✓';
            if (message) message.textContent = 'Email envoyé avec succès !';
            
            let detailsText = '';
            if (result.message_id) {
                detailsText += `Message-ID: ${result.message_id}`;
            }
            if (result.log_id) {
                if (detailsText) detailsText += '\n';
                detailsText += `Log ID: ${result.log_id}`;
            }
            if (result.email) {
                if (detailsText) detailsText += '\n';
                detailsText += `Destinataire: ${result.email}`;
            }
            
            if (details) {
                details.textContent = detailsText || 'Aucun détail disponible';
                details.onclick = () => {
                    navigator.clipboard.writeText(detailsText).then(() => {
                        showToast('Détails copiés !', 'success');
                    });
                };
            }
        }

        /**
         * Affiche le résultat d'erreur
         */
        function showFactureMailError(errorMsg) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result error';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = '✗';
            if (message) message.textContent = 'Erreur lors de l\'envoi';
            if (details) {
                details.textContent = errorMsg;
                details.onclick = null;
            }
        }

        /**
         * Cache le résultat
         */
        function hideFactureMailResult() {
            const resultDiv = document.getElementById('factureMailResult');
            if (resultDiv) {
                resultDiv.style.display = 'none';
            }
        }

        /**
         * Affiche un toast
         */
        function showToast(message, type = 'success') {
            // Créer le conteneur s'il n'existe pas
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            
            // Créer le toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = document.createElement('span');
            icon.className = 'toast-icon';
            icon.textContent = type === 'success' ? '✓' : '✗';
            
            const text = document.createElement('span');
            text.textContent = message;
            
            toast.appendChild(icon);
            toast.appendChild(text);
            container.appendChild(toast);
            
            // Supprimer après 5 secondes
            setTimeout(() => {
                toast.classList.add('fade-out');
                setTimeout(() => {
                    toast.remove();
                    if (container.children.length === 0) {
                        container.remove();
                    }
                }, 300);
            }, 5000);
        }

        // ============================================
        // GESTION DU MODAL GÉNÉRATION FACTURE CLIENTS
        // ============================================
        
        /**
         * Ouvre le modal de génération de factures pour clients
         */
        function openGenerationFactureClientsModal() {
            const modal = document.getElementById('generationFactureClientsModal');
            const overlay = document.getElementById('generationFactureClientsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal génération facture clients introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire
            const form = document.getElementById('generationFactureClientsForm');
            const progressContainer = document.getElementById('genFactureProgressContainer');
            const btnSubmit = document.getElementById('btnGenererFacturesClients');
            const btnCancel = document.getElementById('btnCancelGeneration');
            
            if (form) {
                form.reset();
                form.style.display = 'block';
                // Réinitialiser la date à aujourd'hui
                const dateInput = document.getElementById('genFactureDate');
                if (dateInput) {
                    dateInput.value = new Date().toISOString().split('T')[0];
                }
                // Masquer les notifications
                const notificationsDiv = document.getElementById('genFactureNotifications');
                if (notificationsDiv) {
                    notificationsDiv.style.display = 'none';
                    notificationsDiv.innerHTML = '';
                }
            }
            
            // Masquer la zone de progression
            if (progressContainer) {
                progressContainer.style.display = 'none';
                progressContainer.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
            }
            
            // Réinitialiser les boutons
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.style.display = 'inline-block';
                btnSubmit.textContent = 'Générer les factures';
            }
            if (btnCancel) {
                btnCancel.disabled = false;
            }
            
            // Réinitialiser la barre de progression
            const progressBar = document.getElementById('genFactureProgressBar');
            const percentDisplay = document.getElementById('genFactureProgressPercentDisplay');
            const statusText = document.getElementById('genFactureProgressStatus');
            
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.classList.remove('complete', 'running');
                progressBar.style.background = 'linear-gradient(90deg, #10b981 0%, #3b82f6 50%, #8b5cf6 100%)';
                progressBar.style.backgroundSize = '200% 100%';
            }
            if (percentDisplay) {
                percentDisplay.classList.remove('complete');
            }
            if (statusText) {
                statusText.textContent = 'Génération en cours...';
                statusText.classList.remove('complete');
            }
            
            // Réinitialiser les compteurs
            updateProgress(0, 0, 0, 0);
            
            // Vider le log
            const logContent = document.getElementById('genFactureProgressLogContent');
            if (logContent) {
                logContent.innerHTML = '';
            }
            const logContainer = document.getElementById('genFactureProgressLog');
            if (logContainer) {
                logContainer.style.display = 'none';
            }
        }
        
        /**
         * Met à jour l'affichage de la progression
         */
        function updateProgress(percent, clients, generees, exclus) {
            const percentEl = document.getElementById('genFactureProgressPercent');
            const barEl = document.getElementById('genFactureProgressBar');
            const clientsEl = document.getElementById('genFactureStatsClients');
            const genereesEl = document.getElementById('genFactureStatsGenerees');
            const exclusEl = document.getElementById('genFactureStatsExclus');
            
            if (percentEl) percentEl.textContent = Math.round(percent);
            if (barEl) barEl.style.width = percent + '%';
            if (clientsEl) clientsEl.textContent = clients;
            if (genereesEl) genereesEl.textContent = generees;
            if (exclusEl) exclusEl.textContent = exclus;
        }

        /**
         * Ferme le modal de génération de factures pour clients
         */
        function closeGenerationFactureClientsModal() {
            const modal = document.getElementById('generationFactureClientsModal');
            const overlay = document.getElementById('generationFactureClientsModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                const form = document.getElementById('generationFactureClientsForm');
                if (form) {
                    form.reset();
                }
            }
        }


        /**
         * Soumet le formulaire de génération de factures pour clients
         */
        async function submitGenerationFactureClientsForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('generationFactureClientsForm');
            const formData = new FormData(form);
            
            const dateDebut = formData.get('date_debut');
            const dateFin = formData.get('date_fin');
            const dateFacture = formData.get('date_facture');
            const offre = formData.get('offre');
            
            if (!dateDebut || !dateFin || !dateFacture || !offre) {
                showToast('Veuillez remplir tous les champs obligatoires', 'error');
                return;
            }
            
            const btnSubmit = document.getElementById('btnGenererFacturesClients');
            const btnCancel = document.getElementById('btnCancelGeneration');
            const progressContainer = document.getElementById('genFactureProgressContainer');
            const formContainer = form.parentElement;
            
            // Masquer le formulaire et afficher la progression
            form.style.display = 'none';
            if (progressContainer) {
                progressContainer.style.display = 'block';
            }
            
            // Initialiser la barre de progression avec l'état "running"
            const progressBar = document.getElementById('genFactureProgressBar');
            const percentDisplay = document.getElementById('genFactureProgressPercentDisplay');
            const statusText = document.getElementById('genFactureProgressStatus');
            
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.classList.remove('complete');
                progressBar.classList.add('running');
                progressBar.style.background = 'linear-gradient(90deg, #10b981 0%, #3b82f6 50%, #8b5cf6 100%)';
                progressBar.style.backgroundSize = '200% 100%';
            }
            if (percentDisplay) {
                percentDisplay.classList.remove('complete');
            }
            if (statusText) {
                statusText.textContent = 'Génération en cours...';
                statusText.classList.remove('complete');
            }
            
            btnSubmit.disabled = true;
            btnSubmit.style.display = 'none';
            if (btnCancel) {
                btnCancel.disabled = true;
            }
            
            // Masquer les notifications précédentes
            const notificationsDiv = document.getElementById('genFactureNotifications');
            if (notificationsDiv) {
                notificationsDiv.style.display = 'none';
                notificationsDiv.innerHTML = '';
            }
            
            // Initialiser les compteurs
            let progressPercent = 0;
            let statsClients = 0;
            let statsGenerees = 0;
            let statsExclus = 0;
            let progressInterval;
            
            // Fonction pour calculer la couleur du gradient basée sur le pourcentage
            function getProgressGradient(percent) {
                if (percent >= 100) {
                    // État de succès : vert solide
                    return 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
                }
                
                // Gradient dynamique : vert → bleu → violet
                // 0-33% : vert vers bleu
                // 33-66% : bleu vers violet
                // 66-100% : violet plus intense
                
                if (percent <= 33) {
                    // Vert vers bleu
                    const ratio = percent / 33;
                    const greenStop = Math.max(0, 100 - (ratio * 50));
                    const blueStart = 50 + (ratio * 20);
                    return `linear-gradient(90deg, #10b981 0%, #3b82f6 ${greenStop}%, #3b82f6 ${blueStart}%, #8b5cf6 100%)`;
                } else if (percent <= 66) {
                    // Bleu vers violet
                    const ratio = (percent - 33) / 33;
                    const blueStop = 50 - (ratio * 20);
                    const purpleStart = 50 + (ratio * 20);
                    return `linear-gradient(90deg, #10b981 0%, #3b82f6 ${blueStop}%, #8b5cf6 ${purpleStart}%, #7c3aed 100%)`;
                } else {
                    // Violet intense
                    const ratio = (percent - 66) / 34;
                    const purpleIntensity = 0.5 + (ratio * 0.5);
                    return `linear-gradient(90deg, #10b981 0%, #3b82f6 30%, #8b5cf6 60%, #7c3aed 100%)`;
                }
            }

            // Fonction pour mettre à jour la progression avec animations
            function updateProgressWithAnimation(percent, clients, generees, exclus) {
                progressPercent = Math.min(100, Math.max(0, percent));
                statsClients = clients;
                statsGenerees = generees;
                statsExclus = exclus;
                
                const percentEl = document.getElementById('genFactureProgressPercent');
                const percentDisplay = document.getElementById('genFactureProgressPercentDisplay');
                const statusText = document.getElementById('genFactureProgressStatus');
                const barEl = document.getElementById('genFactureProgressBar');
                const clientsEl = document.getElementById('genFactureStatsClients');
                const genereesEl = document.getElementById('genFactureStatsGenerees');
                const exclusEl = document.getElementById('genFactureStatsExclus');
                
                // Mettre à jour le pourcentage avec transition douce
                if (percentEl) {
                    const roundedPercent = Math.round(progressPercent);
                    // Animation de compteur pour le pourcentage (plus fluide)
                    let currentValue = parseInt(percentEl.textContent) || 0;
                    const targetValue = roundedPercent;
                    
                    if (currentValue !== targetValue) {
                        // Annuler toute animation en cours
                        if (window.progressCounterAnimation) {
                            cancelAnimationFrame(window.progressCounterAnimation);
                        }
                        
                        const updateCounter = () => {
                            if (currentValue < targetValue) {
                                currentValue = Math.min(targetValue, currentValue + Math.ceil((targetValue - currentValue) / 5) || 1);
                                percentEl.textContent = currentValue;
                                if (currentValue < targetValue) {
                                    window.progressCounterAnimation = requestAnimationFrame(updateCounter);
                                } else {
                                    percentEl.textContent = targetValue;
                                    window.progressCounterAnimation = null;
                                }
                            } else if (currentValue > targetValue) {
                                currentValue = Math.max(targetValue, currentValue - Math.ceil((currentValue - targetValue) / 5) || 1);
                                percentEl.textContent = currentValue;
                                if (currentValue > targetValue) {
                                    window.progressCounterAnimation = requestAnimationFrame(updateCounter);
                                } else {
                                    percentEl.textContent = targetValue;
                                    window.progressCounterAnimation = null;
                                }
                            }
                        };
                        window.progressCounterAnimation = requestAnimationFrame(updateCounter);
                    }
                }
                
                // Mettre à jour la barre de progression
                if (barEl) {
                    barEl.style.width = progressPercent + '%';
                    
                    // Gérer les états (running vs complete)
                    if (progressPercent >= 100) {
                        // État de succès
                        barEl.classList.remove('running');
                        barEl.classList.add('complete');
                        barEl.style.background = getProgressGradient(100);
                        barEl.style.backgroundSize = '100% 100%';
                        
                        if (percentDisplay) {
                            percentDisplay.classList.add('complete');
                        }
                        if (statusText) {
                            statusText.textContent = '✓ Génération terminée avec succès !';
                            statusText.classList.add('complete');
                        }
                    } else {
                        // État en cours
                        barEl.classList.remove('complete');
                        barEl.classList.add('running');
                        barEl.style.background = getProgressGradient(progressPercent);
                        barEl.style.backgroundSize = '200% 100%';
                        
                        if (percentDisplay) {
                            percentDisplay.classList.remove('complete');
                        }
                        if (statusText) {
                            statusText.textContent = 'Génération en cours...';
                            statusText.classList.remove('complete');
                        }
                    }
                }
                
                // Mettre à jour les statistiques avec animations
                if (clientsEl) {
                    clientsEl.textContent = statsClients;
                    clientsEl.style.transition = 'transform 0.2s ease';
                    clientsEl.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        clientsEl.style.transform = 'scale(1)';
                    }, 200);
                }
                if (genereesEl) {
                    genereesEl.textContent = statsGenerees;
                    genereesEl.style.transition = 'transform 0.2s ease';
                    genereesEl.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        genereesEl.style.transform = 'scale(1)';
                    }, 200);
                }
                if (exclusEl) {
                    exclusEl.textContent = statsExclus;
                    exclusEl.style.transition = 'transform 0.2s ease';
                    exclusEl.style.transform = 'scale(1.1)';
                    setTimeout(() => {
                        exclusEl.style.transform = 'scale(1)';
                    }, 200);
                }
            }
            
            // Simuler une progression pendant le chargement
            let simulatedProgress = 0;
            progressInterval = setInterval(() => {
                simulatedProgress += Math.random() * 3;
                if (simulatedProgress < 90) {
                    updateProgressWithAnimation(simulatedProgress, statsClients, statsGenerees, statsExclus);
                }
            }, 200);
            
            try {
                const data = {
                    date_facture: dateFacture,
                    date_debut: dateDebut,
                    date_fin: dateFin,
                    offre: parseInt(offre)
                };
                
                const startTime = Date.now();
                const response = await fetch('/API/factures_generer_clients.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify(data),
                    credentials: 'include'
                });
                
                    clearInterval(progressInterval);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    updateProgressWithAnimation(100, statsClients, statsGenerees, statsExclus);
                    
                    // Afficher l'erreur
                    if (progressContainer) {
                        progressContainer.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                    }
                    
                    try {
                        const errorJson = JSON.parse(errorText);
                        showMessage('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status), 'error');
                    } catch {
                        showMessage('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200), 'error');
                    }
                    
                    btnSubmit.disabled = false;
                    btnSubmit.style.display = 'inline-block';
                    if (btnCancel) btnCancel.disabled = false;
                    form.style.display = 'block';
                    if (progressContainer) progressContainer.style.display = 'none';
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                // Mettre à jour avec les vraies valeurs
                const totalClients = (result.total_clients || 0) + (result.total_exclus || 0);
                updateProgressWithAnimation(100, totalClients, result.total_generees || 0, result.total_exclus || 0);
                
                if (result.ok) {
                    // Changer le style pour le succès
                    if (progressContainer) {
                        progressContainer.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                    }
                    
                    // Afficher les résultats dans le log
                    const logContainer = document.getElementById('genFactureProgressLog');
                    const logContent = document.getElementById('genFactureProgressLogContent');
                    
                    if (logContainer && logContent) {
                        logContent.innerHTML = '';
                        
                        // Afficher les factures générées (style carte + bouton PDF)
                        if (result.factures_generees && result.factures_generees.length > 0) {
                            result.factures_generees.forEach(facture => {
                                const logItem = document.createElement('div');
                                logItem.style.cssText = 'padding: 0.75rem 1rem; background: rgba(15,23,42,0.6); border-radius: var(--radius-md); color: white; font-size: 0.85rem; border: 1px solid rgba(148,163,184,0.4); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; box-shadow: 0 8px 20px rgba(15,23,42,0.4);';

                                const montant = parseFloat(facture.montant_ttc || 0).toFixed(2).replace('.', ',');

                                logItem.innerHTML = `
                                    <div style="display:flex; align-items:flex-start; gap:0.6rem;">
                                        <div style="width: 22px; height: 22px; border-radius: 999px; background: rgba(16,185,129,0.2); display:flex; align-items:center; justify-content:center; color:#10b981;">
                                            ✓
                                        </div>
                                        <div>
                                            <div style="font-weight:600; margin-bottom:0.15rem; color: #1a202c;">
                                                ${facture.client_nom}
                                            </div>
                                            <div style="font-size:0.8rem; color: rgba(26,32,44,0.7);">
                                                Facture <strong>${facture.numero}</strong> • <span style="color:#059669;">${montant} € TTC</span>
                                            </div>
                                        </div>
                                    </div>
                                    <button type="button"
                                        onclick="viewFacturePDFById(${facture.facture_id}, '${facture.numero}')"
                                        style="padding:0.4rem 0.9rem; border-radius:999px; border:1px solid var(--border-color); background:var(--accent-primary); color:white; font-size:0.78rem; font-weight:500; display:inline-flex; align-items:center; gap:0.35rem; cursor:pointer; transition:all 0.15s ease;">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                            <polyline points="14 2 14 8 20 8"></polyline>
                                            <line x1="16" y1="13" x2="8" y2="13"></line>
                                            <line x1="16" y1="17" x2="8" y2="17"></line>
                                        </svg>
                                        Ouvrir le PDF
                                    </button>
                                `;

                                logItem.addEventListener('mouseenter', () => {
                                    logItem.style.transform = 'translateY(-2px)';
                                    logItem.style.boxShadow = '0 4px 12px rgba(0,0,0,0.15)';
                                });
                                logItem.addEventListener('mouseleave', () => {
                                    logItem.style.transform = 'translateY(0)';
                                    logItem.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
                                });

                                logContent.appendChild(logItem);
                            });
                        }
                        
                        // Afficher les clients exclus
                        if (result.clients_exclus && result.clients_exclus.length > 0) {
                            result.clients_exclus.forEach(client => {
                                const logItem = document.createElement('div');
                                logItem.style.cssText = 'padding: 0.5rem; background: rgba(245,158,11,0.15); border-radius: var(--radius-sm); color: #92400e; font-size: 0.85rem; border-left: 3px solid #f59e0b;';
                                logItem.innerHTML = `⚠️ <strong>${client.client_nom}</strong> - ${client.raison}`;
                                logContent.appendChild(logItem);
                            });
                        }
                        
                        logContainer.style.display = 'block';
                    }
                    
                    // Afficher le message de succès
                    let message = `${result.total_generees} facture(s) générée(s) avec succès`;
                    if (result.total_exclus > 0) {
                        message += `. ${result.total_exclus} client(s) exclu(s)`;
                    }
                    showMessage(message, 'success');
                    
                    // Afficher les notifications pour les clients exclus dans le formulaire
                    if (result.clients_exclus && result.clients_exclus.length > 0 && notificationsDiv) {
                        let notificationsHtml = '<div style="padding: 1rem; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: var(--radius-md); margin-top: 1rem;">';
                        notificationsHtml += '<div style="font-weight: 600; color: #92400E; margin-bottom: 0.75rem;">⚠️ Clients exclus de la génération:</div>';
                        notificationsHtml += '<div style="max-height: 200px; overflow-y: auto;">';
                        
                        result.clients_exclus.forEach(client => {
                            notificationsHtml += `
                                <div style="padding: 0.5rem; margin-bottom: 0.5rem; background: white; border-radius: var(--radius-sm); border-left: 3px solid #FCD34D;">
                                    <div style="font-weight: 600; color: #92400E; font-size: 0.875rem;">${client.client_nom}</div>
                                    <div style="font-size: 0.8rem; color: #92400E; margin-top: 0.25rem;">${client.raison}</div>
                                </div>
                            `;
                        });
                        
                        notificationsHtml += '</div></div>';
                        notificationsDiv.innerHTML = notificationsHtml;
                        notificationsDiv.style.display = 'block';
                    }
                    
                    // Si toutes les factures ont été générées, fermer le modal après 5 secondes
                    if (result.total_exclus === 0) {
                        setTimeout(() => {
                            closeGenerationFactureClientsModal();
                            window.location.reload();
                        }, 5000);
                    } else {
                        // Afficher un bouton pour fermer
                        setTimeout(() => {
                            const closeBtn = document.createElement('button');
                            closeBtn.className = 'btn btn-primary';
                            closeBtn.textContent = 'Fermer';
                            closeBtn.style.marginTop = '1rem';
                            closeBtn.onclick = () => {
                                closeGenerationFactureClientsModal();
                                window.location.reload();
                            };
                            if (progressContainer) {
                                progressContainer.appendChild(closeBtn);
                            }
                        }, 2000);
                    }
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    
                    if (progressContainer) {
                        progressContainer.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                    }
                    
                    showMessage('Erreur : ' + errorMsg, 'error');
                    btnSubmit.disabled = false;
                    btnSubmit.style.display = 'inline-block';
                    if (btnCancel) btnCancel.disabled = false;
                    form.style.display = 'block';
                    if (progressContainer) progressContainer.style.display = 'none';
                }
            } catch (error) {
                clearInterval(progressInterval);
                console.error('Erreur lors de la génération:', error);
                
                updateProgressWithAnimation(100, statsClients, statsGenerees, statsExclus);
                
                if (progressContainer) {
                    progressContainer.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                }
                
                showMessage('Erreur lors de la génération des factures: ' + error.message, 'error');
                btnSubmit.disabled = false;
                btnSubmit.style.display = 'inline-block';
                if (btnCancel) btnCancel.disabled = false;
                form.style.display = 'block';
                if (progressContainer) progressContainer.style.display = 'none';
            }
        }

        // ============================================
        // GESTION DU MODAL PAYER
        // ============================================
        
        /**
         * Ouvre le modal de paiement
         */
        function openPayerModal() {
            const modal = document.getElementById('payerModal');
            const overlay = document.getElementById('payerModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal payer introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser le formulaire
            const form = document.getElementById('payerForm');
            if (form) {
                form.reset();
                // Réinitialiser la date à aujourd'hui
                const dateInput = document.getElementById('payerDate');
                if (dateInput) {
                    dateInput.value = new Date().toISOString().split('T')[0];
                }
                // Réinitialiser la référence (sera générée automatiquement)
                const referenceInput = document.getElementById('payerReference');
                if (referenceInput) {
                    referenceInput.value = '';
                    referenceInput.placeholder = 'Générée automatiquement';
                }
            }
            
            // Charger les factures non payées
            loadFacturesForPaiement();
        }

        /**
         * Ferme le modal de paiement
         */
        function closePayerModal() {
            const modal = document.getElementById('payerModal');
            const overlay = document.getElementById('payerModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
                // Réinitialiser le formulaire
                const form = document.getElementById('payerForm');
                if (form) {
                    form.reset();
                }
            }
        }

        /**
         * Charge les factures pour le select de paiement
         */
        async function loadFacturesForPaiement() {
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    const factureSelect = document.getElementById('payerFacture');
                    factureSelect.innerHTML = '<option value="">Sélectionner une facture</option>';
                    
                    // Filtrer les factures non payées et non annulées
                    const facturesDisponibles = data.factures.filter(f => 
                        f.statut !== 'payee' && f.statut !== 'annulee'
                    );
                    
                    facturesDisponibles.forEach(facture => {
                        const option = document.createElement('option');
                        option.value = facture.id;
                        option.textContent = `${facture.numero} - ${facture.client_nom} - ${facture.montant_ttc.toFixed(2)} € TTC`;
                        option.setAttribute('data-montant', facture.montant_ttc);
                        option.setAttribute('data-client-id', facture.client_id);
                        factureSelect.appendChild(option);
                    });
                    
                    // Écouter le changement pour pré-remplir le montant
                    factureSelect.addEventListener('change', function() {
                        const selectedOption = this.options[this.selectedIndex];
                        if (selectedOption && selectedOption.value) {
                            const montant = parseFloat(selectedOption.getAttribute('data-montant')) || 0;
                            const montantInput = document.getElementById('payerMontant');
                            if (montantInput) {
                                montantInput.value = montant.toFixed(2);
                            }
                        }
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des factures:', error);
            }
        }

        /**
         * Soumet le formulaire de paiement
         */
        async function submitPayerForm(e) {
            e.preventDefault();
            
            const form = document.getElementById('payerForm');
            const formData = new FormData(form);
            
            // Validation
            const factureId = formData.get('facture_id');
            const montant = parseFloat(formData.get('montant')) || 0;
            const modePaiement = formData.get('mode_paiement');
            
            if (!factureId) {
                showToast('Veuillez sélectionner une facture', 'error');
                return;
            }
            
            if (montant <= 0) {
                showToast('Le montant doit être supérieur à 0', 'error');
                return;
            }
            
            // Validation du justificatif (max 5MB)
            const justificatifInput = document.getElementById('payerJustificatif');
            if (justificatifInput?.files?.length > 0) {
                const file = justificatifInput.files[0];
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    showToast('Le fichier justificatif ne doit pas dépasser 5 Mo', 'error');
                    return;
                }
            }
            
            const btnSubmit = document.getElementById('btnEnregistrerPaiement');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'Enregistrement en cours...';
            
            try {
                const response = await fetch('/API/paiements_enregistrer.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Erreur HTTP:', response.status, errorText);
                    try {
                        const errorJson = JSON.parse(errorText);
                        showMessage('Erreur : ' + (errorJson.error || 'Erreur HTTP ' + response.status), 'error');
                    } catch {
                        showMessage('Erreur HTTP ' + response.status + ': ' + errorText.substring(0, 200), 'error');
                    }
                    return;
                }
                
                const result = await response.json();
                console.log('Réponse reçue:', result);
                
                if (result.ok) {
                    const refMessage = result.reference ? ` Référence: ${result.reference}` : '';
                    showMessage('Paiement enregistré avec succès !' + refMessage, 'success');
                    closePayerModal();
                    // Recharger la liste des paiements si le modal est ouvert
                    if (document.getElementById('facturesListModalOverlay')?.classList.contains('active')) {
                loadFacturesList();
                    }
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    showMessage('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la requête:', error);
                showMessage('Erreur lors de l\'enregistrement du paiement: ' + error.message, 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Enregistrer le paiement';
            }
        }

        // ============================================
        // GESTION DU MODAL ENVOI EN MASSE
        // ============================================
        
        let allEnvoiMasseFactures = [];
        let filteredEnvoiMasseFactures = [];
        
        /**
         * Ouvre le modal d'envoi en masse
         */
        function openEnvoiMasseModal() {
            const modal = document.getElementById('envoiMasseModal');
            const overlay = document.getElementById('envoiMasseModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal envoi masse introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Réinitialiser
            const progressContainer = document.getElementById('envoiMasseProgressContainer');
            const listContainer = document.getElementById('envoiMasseListContainer');
            if (progressContainer) progressContainer.style.display = 'none';
            if (listContainer) listContainer.style.display = 'none';
            
            // Charger les factures
            loadEnvoiMasseFactures();
        }
        
        /**
         * Ferme le modal d'envoi en masse
         */
        function closeEnvoiMasseModal() {
            const modal = document.getElementById('envoiMasseModal');
            const overlay = document.getElementById('envoiMasseModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }
        
        /**
         * Charge les factures pour l'envoi en masse
         */
        async function loadEnvoiMasseFactures() {
            const loadingDiv = document.getElementById('envoiMasseListLoading');
            const container = document.getElementById('envoiMasseListContainer');
            const errorDiv = document.getElementById('envoiMasseListError');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    // Filtrer uniquement les factures avec PDF et email client
                    allEnvoiMasseFactures = data.factures.filter(f => 
                        f.pdf_path && f.client_email
                    );
                    filteredEnvoiMasseFactures = [...allEnvoiMasseFactures];
                    
                    displayEnvoiMasseFactures(allEnvoiMasseFactures);
                    
                    document.getElementById('envoiMasseCount').textContent = allEnvoiMasseFactures.length;
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    errorDiv.textContent = data.error || 'Erreur lors du chargement des factures';
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                }
            } catch (error) {
                console.error('Erreur lors du chargement:', error);
                errorDiv.textContent = 'Erreur: ' + error.message;
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
            }
        }
        
        /**
         * Affiche les factures dans le tableau
         */
        function displayEnvoiMasseFactures(factures) {
            const tableBody = document.getElementById('envoiMasseTableBody');
            const countSpan = document.getElementById('envoiMasseFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture disponible
                        </td>
                    </tr>
                `;
                countSpan.textContent = '';
            } else {
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    const emailStatus = facture.email_envoye === 1 ? 
                        '<span style="color: #10b981; font-weight: 600;">✓ Envoyée</span>' : 
                        '<span style="color: var(--text-secondary);">Non envoyée</span>';
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; text-align: center;">
                            <input type="checkbox" class="envoi-masse-checkbox" value="${facture.id}" onchange="updateEnvoiMasseSelection()">
                        </td>
                        <td style="padding: 0.75rem; font-weight: 600; color: var(--text-primary);">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">
                            ${facture.client_nom || 'Client inconnu'}
                            ${facture.client_code ? ` (${facture.client_code})` : ''}
                        </td>
                        <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">
                            ${facture.montant_ttc.toFixed(2).replace('.', ',')} €
                        </td>
                        <td style="padding: 0.75rem; text-align: center; color: var(--text-secondary); font-size: 0.9rem;">
                            ${facture.client_email || '-'}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${emailStatus}
                        </td>
                    `;
                    tableBody.appendChild(row);
                });
                
                if (factures.length !== allEnvoiMasseFactures.length) {
                    countSpan.textContent = `(${factures.length} sur ${allEnvoiMasseFactures.length})`;
                } else {
                    countSpan.textContent = '';
                }
            }
            
            updateEnvoiMasseSelection();
        }
        
        /**
         * Filtre les factures selon le terme de recherche
         */
        function filterEnvoiMasseFactures() {
            const searchInput = document.getElementById('envoiMasseSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            if (!searchTerm) {
                filteredEnvoiMasseFactures = [...allEnvoiMasseFactures];
                displayEnvoiMasseFactures(filteredEnvoiMasseFactures);
                return;
            }
            
            filteredEnvoiMasseFactures = allEnvoiMasseFactures.filter(facture => {
                if (facture.numero && facture.numero.toLowerCase().includes(searchTerm)) return true;
                if (facture.date_facture_formatted && facture.date_facture_formatted.includes(searchTerm)) return true;
                if (facture.client_nom && facture.client_nom.toLowerCase().includes(searchTerm)) return true;
                if (facture.client_code && facture.client_code.toLowerCase().includes(searchTerm)) return true;
                if (facture.client_email && facture.client_email.toLowerCase().includes(searchTerm)) return true;
                return false;
            });
            
            displayEnvoiMasseFactures(filteredEnvoiMasseFactures);
        }
        
        /**
         * Met à jour le compteur de sélection
         */
        function updateEnvoiMasseSelection() {
            const checkboxes = document.querySelectorAll('.envoi-masse-checkbox:checked');
            const count = checkboxes.length;
            document.getElementById('envoiMasseSelectedCount').textContent = count;
            
            const btnEnvoyer = document.getElementById('btnEnvoyerMasse');
            if (btnEnvoyer) {
                btnEnvoyer.disabled = count === 0;
            }
        }
        
        /**
         * Sélectionne toutes les factures
         */
        function selectAllEnvoiMasse() {
            document.querySelectorAll('.envoi-masse-checkbox').forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('envoiMasseSelectAll').checked = true;
            updateEnvoiMasseSelection();
        }
        
        /**
         * Désélectionne toutes les factures
         */
        function deselectAllEnvoiMasse() {
            document.querySelectorAll('.envoi-masse-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('envoiMasseSelectAll').checked = false;
            updateEnvoiMasseSelection();
        }
        
        /**
         * Toggle sélection toutes
         */
        function toggleSelectAllEnvoiMasse(checkbox) {
            document.querySelectorAll('.envoi-masse-checkbox').forEach(cb => {
                cb.checked = checkbox.checked;
            });
            updateEnvoiMasseSelection();
        }
        
        /**
         * Soumet l'envoi en masse
         */
        async function submitEnvoiMasse() {
            const checkboxes = document.querySelectorAll('.envoi-masse-checkbox:checked');
            const factureIds = Array.from(checkboxes).map(cb => parseInt(cb.value));
            
            if (factureIds.length === 0) {
                showToast('Veuillez sélectionner au moins une facture', 'error');
                return;
            }
            
            if (!confirm(`Voulez-vous envoyer ${factureIds.length} facture(s) à leurs clients respectifs ?`)) {
                return;
            }
            
            const listContainer = document.getElementById('envoiMasseListContainer');
            const progressContainer = document.getElementById('envoiMasseProgressContainer');
            const btnEnvoyer = document.getElementById('btnEnvoyerMasse');
            const btnCancel = document.getElementById('btnCancelEnvoiMasse');
            
            // Masquer la liste et afficher la progression
            if (listContainer) listContainer.style.display = 'none';
            if (progressContainer) progressContainer.style.display = 'block';
            if (btnEnvoyer) btnEnvoyer.disabled = true;
            if (btnCancel) btnCancel.disabled = true;
            
            // Initialiser la barre de progression
            const progressBar = document.getElementById('envoiMasseProgressBar');
            const percentDisplay = document.getElementById('envoiMasseProgressPercentDisplay');
            const statusText = document.getElementById('envoiMasseProgressStatus');
            
            if (progressBar) {
                progressBar.style.width = '0%';
                progressBar.classList.remove('complete');
                progressBar.classList.add('running');
            }
            if (percentDisplay) percentDisplay.classList.remove('complete');
            if (statusText) {
                statusText.textContent = 'Envoi en cours...';
                statusText.classList.remove('complete');
            }
            
            // Réinitialiser les compteurs
            updateEnvoiMasseProgress(0, factureIds.length, 0, 0);
            
            try {
                const response = await fetch('/API/factures_envoyer_masse.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        ...getCsrfHeaders(),
                    },
                    body: JSON.stringify({
                        facture_ids: factureIds
                    }),
                    credentials: 'include'
                });
                
                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(errorText || 'Erreur HTTP ' + response.status);
                }
                
                const result = await response.json();
                
                if (result.ok) {
                    // Mettre à jour avec les résultats
                    updateEnvoiMasseProgress(100, result.total, result.success, result.failed);
                    
                    if (progressBar) {
                        progressBar.classList.remove('running');
                        progressBar.classList.add('complete');
                    }
                    if (percentDisplay) percentDisplay.classList.add('complete');
                    if (statusText) {
                        statusText.textContent = `✓ Envoi terminé : ${result.success} réussie(s), ${result.failed} échec(s)`;
                        statusText.classList.add('complete');
                    }
                    
                    // Afficher les résultats détaillés
                    displayEnvoiMasseResults(result.results);
                    
                    // Réactiver les boutons
                    if (btnCancel) btnCancel.disabled = false;
                    
                    showMessage(`${result.success} facture(s) envoyée(s) avec succès${result.failed > 0 ? `, ${result.failed} échec(s)` : ''}`, 'success');
                } else {
                    throw new Error(result.error || 'Erreur inconnue');
                }
            } catch (error) {
                console.error('Erreur lors de l\'envoi en masse:', error);
                showMessage('Erreur lors de l\'envoi en masse: ' + error.message, 'error');
                
                if (progressContainer) progressContainer.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                if (btnEnvoyer) btnEnvoyer.disabled = false;
                if (btnCancel) btnCancel.disabled = false;
            }
        }
        
        /**
         * Met à jour la progression de l'envoi
         */
        function updateEnvoiMasseProgress(percent, total, success, failed) {
            const percentEl = document.getElementById('envoiMasseProgressPercent');
            const barEl = document.getElementById('envoiMasseProgressBar');
            const totalEl = document.getElementById('envoiMasseStatsTotal');
            const successEl = document.getElementById('envoiMasseStatsSuccess');
            const failedEl = document.getElementById('envoiMasseStatsFailed');
            
            if (percentEl) percentEl.textContent = Math.round(percent);
            if (barEl) barEl.style.width = percent + '%';
            if (totalEl) totalEl.textContent = total;
            if (successEl) successEl.textContent = success;
            if (failedEl) failedEl.textContent = failed;
        }
        
        /**
         * Affiche les résultats détaillés
         */
        function displayEnvoiMasseResults(results) {
            const logContainer = document.getElementById('envoiMasseProgressLog');
            const logContent = document.getElementById('envoiMasseProgressLogContent');
            
            if (!logContainer || !logContent) return;
            
            logContent.innerHTML = '';
            
            results.forEach(result => {
                const logItem = document.createElement('div');
                if (result.success) {
                    logItem.style.cssText = 'padding: 0.75rem 1rem; background: rgba(16,185,129,0.15); border-radius: var(--radius-md); color: #065f46; font-size: 0.85rem; border: 1px solid rgba(16,185,129,0.3);';
                    logItem.innerHTML = `✓ Facture #${result.facture_id} : ${result.message}`;
                } else {
                    logItem.style.cssText = 'padding: 0.75rem 1rem; background: rgba(239,68,68,0.15); border-radius: var(--radius-md); color: #991b1b; font-size: 0.85rem; border: 1px solid rgba(239,68,68,0.3);';
                    logItem.innerHTML = `✗ Facture #${result.facture_id} : ${result.error || 'Erreur inconnue'}`;
                }
                logContent.appendChild(logItem);
            });
            
            logContainer.style.display = 'block';
        }

        // ============================================
        // PROGRAMMER ENVOIS
        // ============================================
        let progFacturesMultiSelected = [];

        function openProgrammerEnvoisModal() {
            const modal = document.getElementById('programmerEnvoisModal');
            const overlay = document.getElementById('programmerEnvoisModalOverlay');
            if (!modal || !overlay) return;
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            // Date par défaut : demain à 09:00 pour éviter "date doit être dans le futur"
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            document.getElementById('progDateEnvoi').value = tomorrow.toISOString().split('T')[0];
            document.getElementById('progHeureEnvoi').value = '09:00';
            document.getElementById('progFactureId').value = '';
            document.getElementById('progFactureSearch').value = '';
            document.getElementById('progUseClientEmail').checked = true;
            document.getElementById('progAllClients').checked = false;
            document.getElementById('progEmailDestination').value = '';
            document.getElementById('progEmailManualGroup').style.display = 'none';
            progFacturesMultiSelected = [];
            onProgTypeEnvoiChange();
            loadProgrammerEnvoisList();
        }

        function closeProgrammerEnvoisModal() {
            const overlay = document.getElementById('programmerEnvoisModalOverlay');
            if (overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function onProgTypeEnvoiChange() {
            const type = document.getElementById('progTypeEnvoi').value;
            const factureGroup = document.getElementById('progFactureGroup');
            const multiGroup = document.getElementById('progFacturesMultiGroup');
            const multiGroupLabel = multiGroup ? multiGroup.querySelector('label') : null;
            const searchHint = document.getElementById('progFactureSearch') ? document.getElementById('progFactureSearch').parentElement : null;
            if (type === 'une_facture') {
                factureGroup.style.display = 'block';
                if (multiGroupLabel) multiGroupLabel.textContent = 'Facture';
                if (searchHint && searchHint.querySelector('.input-hint')) searchHint.querySelector('.input-hint').textContent = 'Tapez le nom du client pour rechercher ses factures';
            } else if (type === 'plusieurs_factures' || type === 'toutes_selectionnees') {
                factureGroup.style.display = 'block';
                if (factureGroup) {
                    const lbl = factureGroup.querySelector('label');
                    if (lbl) lbl.textContent = 'Rechercher une facture pour l\'ajouter';
                    const hint = factureGroup.querySelector('.input-hint');
                    if (hint) hint.textContent = 'Cliquez sur une suggestion pour l\'ajouter à la liste';
                }
                multiGroup.style.display = 'block';
            } else {
                factureGroup.style.display = 'block';
                multiGroup.style.display = 'none';
            }
        }

        function onProgEmailOptionChange() {
            const useClient = document.getElementById('progUseClientEmail').checked;
            const allClients = document.getElementById('progAllClients').checked;
            const manualGroup = document.getElementById('progEmailManualGroup');
            manualGroup.style.display = (!useClient && !allClients) ? 'block' : 'none';
        }

        function formatUtcToLocale(utcStr) {
            if (!utcStr) return '-';
            let d;
            if (utcStr.endsWith('Z') || utcStr.includes('+')) {
                d = new Date(utcStr);
            } else {
                d = new Date(utcStr.replace(' ', 'T') + 'Z');
            }
            if (isNaN(d.getTime())) return utcStr;
            return d.toLocaleString('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        async function loadProgrammerEnvoisList() {
            const loading = document.getElementById('programmerEnvoisListLoading');
            const container = document.getElementById('programmerEnvoisListContainer');
            const tbody = document.getElementById('programmerEnvoisTableBody');
            if (!loading || !container || !tbody) return;
            loading.style.display = 'block';
            container.style.display = 'none';
            try {
                const res = await fetch('/API/factures_programmations_liste.php', { credentials: 'include' });
                const data = await res.json();
                loading.style.display = 'none';
                if (data.ok && data.programmations) {
                    tbody.innerHTML = data.programmations.map(p => {
                        const facturesStr = (p.factures_info || []).map(f => f.numero || '#' + f.id).join(', ') || '-';
                        const statutLabels = { en_attente: 'En attente', envoye: 'Envoyé', annule: 'Annulé', echoue: 'Échoué' };
                        const statutClass = { en_attente: 'color:#f59e0b', envoye: 'color:#10b981', annule: 'color:#6b7280', echoue: 'color:#ef4444' };
                        let actions = '';
                        if (p.statut === 'en_attente') {
                            actions = `<button type="button" class="btn btn-secondary" style="font-size:0.75rem; padding:0.25rem 0.5rem;" onclick="progAnnuler(${p.id})">Annuler</button>
                                <button type="button" class="btn btn-primary" style="font-size:0.75rem; padding:0.25rem 0.5rem;" onclick="progEnvoyerMaintenant(${p.id})">Envoyer maintenant</button>`;
                        }
                        const dateAffichage = p.date_envoi_locale || (p.date_envoi_programmee ? formatUtcToLocale(p.date_envoi_programmee) : '-');
                        return `<tr style="border-bottom:1px solid var(--border-color);">
                            <td style="padding:0.5rem;">${p.id}</td>
                            <td style="padding:0.5rem;">${facturesStr}</td>
                            <td style="padding:0.5rem;">${p.destinataire || '-'}</td>
                            <td style="padding:0.5rem;">${dateAffichage}</td>
                            <td style="padding:0.5rem; text-align:center;"><span style="${statutClass[p.statut] || ''}">${statutLabels[p.statut] || p.statut}</span></td>
                            <td style="padding:0.5rem; text-align:center;">${actions}</td>
                        </tr>`;
                    }).join('');
                    container.style.display = 'block';
                }
            } catch (e) {
                loading.style.display = 'none';
                tbody.innerHTML = '<tr><td colspan="6" style="padding:1rem; color:#ef4444;">Erreur de chargement</td></tr>';
                container.style.display = 'block';
            }
        }

        async function progAnnuler(id) {
            if (!confirm('Annuler cette programmation ?')) return;
            try {
                const res = await fetch('/API/factures_programmation_annuler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...getCsrfHeaders() },
                    body: JSON.stringify({ id }),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('Programmation annulée', 'success');
                    loadProgrammerEnvoisList();
                } else {
                    showToast(data.error || 'Erreur', 'error');
                }
            } catch (e) {
                showToast('Erreur: ' + e.message, 'error');
            }
        }

        async function progEnvoyerMaintenant(id) {
            if (!confirm('Envoyer maintenant cette programmation ?')) return;
            try {
                const res = await fetch('/API/factures_programmation_envoyer_maintenant.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...getCsrfHeaders() },
                    body: JSON.stringify({ id }),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    showToast(data.message || 'Envoi effectué', 'success');
                    loadProgrammerEnvoisList();
                } else {
                    showToast(data.error || 'Erreur', 'error');
                }
            } catch (e) {
                showToast('Erreur: ' + e.message, 'error');
            }
        }

        /**
         * Exécute les envois programmés dont la date est dépassée
         * (Alternative au cron - utile en local ou sans cron configuré)
         */
        async function executerEnvoisProgrammes() {
            const btn = document.getElementById('btnExecuterEnvoisProgrammes');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span style="display:inline-flex;align-items:center;gap:0.5rem;"><span class="spinner" style="width:14px;height:14px;border:2px solid currentColor;border-top-color:transparent;border-radius:50%;animation:spin 0.8s linear infinite;"></span> Exécution...</span>';
            }
            try {
                const res = await fetch('/API/cron_execute_scheduled.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...getCsrfHeaders() },
                    body: JSON.stringify({}),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    showToast(data.message || 'Envois exécutés', 'success');
                    loadProgrammerEnvoisList();
                } else {
                    showToast(data.error || 'Erreur', 'error');
                }
            } catch (e) {
                showToast('Erreur: ' + e.message, 'error');
            }
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"></polyline></svg> Exécuter les envois programmés`;
            }
        }

        function progRemoveFacture(id) {
            progFacturesMultiSelected = progFacturesMultiSelected.filter(x => x !== id);
            const listEl = document.getElementById('progFacturesMultiList');
            if (listEl) {
                listEl.innerHTML = progFacturesMultiSelected.length ? progFacturesMultiSelected.map(fid => `<span style="display:inline-block; margin:0.25rem; padding:0.25rem 0.5rem; background:var(--bg-secondary); border-radius:4px;">#${fid} <button type="button" style="margin-left:0.25rem; border:none; background:none; cursor:pointer; color:#ef4444;" onclick="progRemoveFacture(${fid})">&times;</button></span>`).join('') : '<em style="color:var(--text-secondary);">Aucune facture sélectionnée</em>';
            }
        }

        function openProgFacturesPicker() {
            const type = document.getElementById('progTypeEnvoi').value;
            if (type === 'plusieurs_factures' || type === 'toutes_selectionnees') {
                document.getElementById('progFactureSearch').focus();
                showToast('Recherchez une facture puis cliquez sur une suggestion pour l\'ajouter à la liste', 'info');
            }
        }

        async function submitProgrammerEnvoisForm(e) {
            e.preventDefault();
            const typeEnvoi = document.getElementById('progTypeEnvoi').value;
            const useClientEmail = document.getElementById('progUseClientEmail').checked;
            const allClients = document.getElementById('progAllClients').checked;
            const emailDest = document.getElementById('progEmailDestination').value.trim();
            if (!useClientEmail && !allClients && !emailDest) {
                showToast('Indiquez un email ou cochez "Utiliser l\'email du client"', 'error');
                return;
            }
            let factureId = null;
            let factureIds = [];
            if (typeEnvoi === 'une_facture') {
                factureId = parseInt(document.getElementById('progFactureId').value);
                if (!factureId) {
                    showToast('Sélectionnez une facture', 'error');
                    return;
                }
            } else if (typeEnvoi === 'plusieurs_factures' || typeEnvoi === 'toutes_selectionnees') {
                factureIds = progFacturesMultiSelected.length ? progFacturesMultiSelected : [];
                if (factureIds.length === 0) {
                    showToast('Sélectionnez au moins une facture', 'error');
                    return;
                }
            }
            const dateEnvoi = document.getElementById('progDateEnvoi').value;
            const heureEnvoi = document.getElementById('progHeureEnvoi').value;
            const dateHeure = dateEnvoi + 'T' + (heureEnvoi || '09:00');
            const dateLocale = new Date(dateHeure);
            if (isNaN(dateLocale.getTime())) {
                showToast('Date/heure invalide', 'error');
                return;
            }
            if (dateLocale <= new Date()) {
                showToast('La date/heure doit être dans le futur', 'error');
                return;
            }
            const payload = {
                type_envoi: typeEnvoi,
                facture_id: factureId,
                facture_ids: factureIds,
                use_client_email: useClientEmail ? 1 : 0,
                all_clients: allClients ? 1 : 0,
                email_destination: emailDest || null,
                date_envoi_programmee: dateHeure,
                sujet: document.getElementById('progSujet').value.trim() || null,
                message: document.getElementById('progMessage').value.trim() || null
            };
            const btn = document.getElementById('btnProgrammerEnvois');
            if (btn) btn.disabled = true;
            try {
                const res = await fetch('/API/factures_programmer_envoi.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', ...getCsrfHeaders() },
                    body: JSON.stringify(payload),
                    credentials: 'include'
                });
                const data = await res.json();
                if (data.ok) {
                    showToast('Programmation créée', 'success');
                    document.getElementById('programmerEnvoisForm').reset();
                    document.getElementById('progFactureId').value = '';
                    document.getElementById('progFactureSearch').value = '';
                    progFacturesMultiSelected = [];
                    // Réinitialiser la date pour la prochaine programmation
                    const tomorrow = new Date();
                    tomorrow.setDate(tomorrow.getDate() + 1);
                    document.getElementById('progDateEnvoi').value = tomorrow.toISOString().split('T')[0];
                    document.getElementById('progHeureEnvoi').value = '09:00';
                    document.getElementById('progUseClientEmail').checked = true;
                    document.getElementById('progAllClients').checked = false;
                    onProgEmailOptionChange();
                    loadProgrammerEnvoisList();
                } else {
                    showToast(data.error || 'Erreur', 'error');
                }
            } catch (err) {
                showToast('Erreur: ' + err.message, 'error');
            }
            if (btn) btn.disabled = false;
        }

        // Exposer les fonctions globalement pour les onclick
        window.openSection = openSection;
        window.openFactureModal = openFactureModal;
        window.closeFactureModal = closeFactureModal;
        window.openFacturesListModal = openFacturesListModal;
        window.closeFacturesListModal = closeFacturesListModal;
        window.openModifierFactureModal = openModifierFactureModal;
        window.closeModifierFactureModal = closeModifierFactureModal;
        window.confirmSupprimerFacture = confirmSupprimerFacture;
        window.toggleFacturesSelectAll = toggleFacturesSelectAll;
        window.updateFacturesSelectionCount = updateFacturesSelectionCount;
        window.supprimerFacturesSelection = supprimerFacturesSelection;
        window.refreshModifierFactureCompteurs = refreshModifierFactureCompteurs;
        window.switchFacturesTab = switchFacturesTab;
        window.viewFacturePDF = viewFacturePDF;
        window.viewFacturePDFById = viewFacturePDFById;
        window.closePDFViewer = closePDFViewer;
        window.openPDFInNewTab = openPDFInNewTab;
        window.filterFactures = filterFactures;
        window.filterFacturesByStatus = filterFacturesByStatus;
        window.addFactureLigne = addFactureLigne;
        window.removeFactureLigne = removeFactureLigne;
        window.submitFactureForm = submitFactureForm;
        window.openPaiementsModal = openPaiementsModal;
        window.closePaiementsModal = closePaiementsModal;
        window.filterPaiements = filterPaiements;
        window.filterPaiementsByStatus = filterPaiementsByStatus;
        window.updatePaiementStatut = updatePaiementStatut;
        window.openPayerModal = openPayerModal;
        window.closePayerModal = closePayerModal;
        window.submitPayerForm = submitPayerForm;
        window.openHistoriquePaiementsModal = openHistoriquePaiementsModal;
        window.closeHistoriquePaiementsModal = closeHistoriquePaiementsModal;
        window.filterHistoriquePaiements = filterHistoriquePaiements;
        window.filterHistoriquePaiementsByStatus = filterHistoriquePaiementsByStatus;
        window.viewJustificatif = viewJustificatif;
        window.validatePaiement = validatePaiement;
        window.sendRecuEmail = sendRecuEmail;
        window.openFactureMailModal = openFactureMailModal;
        window.closeFactureMailModal = closeFactureMailModal;
        window.submitFactureMailForm = submitFactureMailForm;
        window.openGenerationFactureClientsModal = openGenerationFactureClientsModal;
        window.closeGenerationFactureClientsModal = closeGenerationFactureClientsModal;
        window.submitGenerationFactureClientsForm = submitGenerationFactureClientsForm;
        window.openEnvoiMasseModal = openEnvoiMasseModal;
        window.closeEnvoiMasseModal = closeEnvoiMasseModal;
        window.filterEnvoiMasseFactures = filterEnvoiMasseFactures;
        window.selectAllEnvoiMasse = selectAllEnvoiMasse;
        window.deselectAllEnvoiMasse = deselectAllEnvoiMasse;
        window.toggleSelectAllEnvoiMasse = toggleSelectAllEnvoiMasse;
        window.updateEnvoiMasseSelection = updateEnvoiMasseSelection;
        window.submitEnvoiMasse = submitEnvoiMasse;
        window.openProgrammerEnvoisModal = openProgrammerEnvoisModal;
        window.closeProgrammerEnvoisModal = closeProgrammerEnvoisModal;
        window.submitProgrammerEnvoisForm = submitProgrammerEnvoisForm;
        window.progAnnuler = progAnnuler;
        window.progEnvoyerMaintenant = progEnvoyerMaintenant;
        window.executerEnvoisProgrammes = executerEnvoisProgrammes;
        window.progRemoveFacture = progRemoveFacture;
        window.openProgFacturesPicker = openProgFacturesPicker;
        window.factureSearchApplyFilters = factureSearchApplyFilters;
        window.progFactureSelectAll = progFactureSelectAll;
        window.progFactureDeselectAll = progFactureDeselectAll;
        window.progFactureValidateSelection = progFactureValidateSelection;

        document.addEventListener('DOMContentLoaded', function() {
            const messageContainer = document.getElementById('messageContainer');

            // Initialisation de la section statistiques
            initStatsSection();

            // Ne pas ajouter de ligne au chargement, seulement quand le modal s'ouvre
            // addFactureLigne() sera appelé dans openFactureModal()

            // Fermer les modals avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const factureModal = document.getElementById('factureModal');
                    const factureModalOverlay = document.getElementById('factureModalOverlay');
                    const facturesListModal = document.getElementById('facturesListModal');
                    const facturesListModalOverlay = document.getElementById('facturesListModalOverlay');
                    const pdfViewerModalOverlay = document.getElementById('pdfViewerModalOverlay');
                    const payerModalOverlay = document.getElementById('payerModalOverlay');
                    const historiquePaiementsModalOverlay = document.getElementById('historiquePaiementsModalOverlay');
                    const factureMailModalOverlay = document.getElementById('factureMailModalOverlay');
                    const generationFactureClientsModalOverlay = document.getElementById('generationFactureClientsModalOverlay');
                    const envoiMasseModalOverlay = document.getElementById('envoiMasseModalOverlay');
                    const programmerEnvoisModalOverlay = document.getElementById('programmerEnvoisModalOverlay');
                    
                    if (pdfViewerModalOverlay && pdfViewerModalOverlay.classList.contains('active')) {
                        closePDFViewer();
                    } else if (programmerEnvoisModalOverlay && programmerEnvoisModalOverlay.classList.contains('active')) {
                        closeProgrammerEnvoisModal();
                    } else if (envoiMasseModalOverlay && envoiMasseModalOverlay.classList.contains('active')) {
                        closeEnvoiMasseModal();
                    } else if (generationFactureClientsModalOverlay && generationFactureClientsModalOverlay.classList.contains('active')) {
                        closeGenerationFactureClientsModal();
                    } else if (factureMailModalOverlay && factureMailModalOverlay.classList.contains('active')) {
                        closeFactureMailModal();
                    } else if (historiquePaiementsModalOverlay && historiquePaiementsModalOverlay.classList.contains('active')) {
                        closeHistoriquePaiementsModal();
                    } else if (payerModalOverlay && payerModalOverlay.classList.contains('active')) {
                        closePayerModal();
                    } else if (factureModalOverlay && factureModalOverlay.classList.contains('active')) {
                        closeFactureModal();
                    } else if (facturesListModalOverlay && facturesListModalOverlay.classList.contains('active')) {
                        closeFacturesListModal();
                    }
                }
            });

        });

        /**
         * Initialise la section statistiques
         */
        function initStatsSection() {
            // Charger la liste des clients
            loadClients();
            
            // Remplir les années (5 dernières années)
            const yearSelect = document.getElementById('filterAnnee');
            const currentYear = new Date().getFullYear();
            for (let i = currentYear; i >= currentYear - 5; i--) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i;
                yearSelect.appendChild(option);
            }
            
            // Définir l'année en cours par défaut
            yearSelect.value = currentYear;
            
            // Charger les données initiales
            loadStatsData();
            
            // Écouter les changements de filtres
            document.getElementById('filterClient').addEventListener('change', loadStatsData);
            document.getElementById('filterMois').addEventListener('change', () => { updateViewModeSegments(); loadStatsData(); });
            document.getElementById('filterAnnee').addEventListener('change', loadStatsData);

            // Switch Mensuel/Journalier cliquable
            document.getElementById('segmentMonthly')?.addEventListener('click', () => {
                document.getElementById('filterMois').value = '';
                updateViewModeSegments();
                loadStatsData();
            });
            document.getElementById('segmentDaily')?.addEventListener('click', () => {
                const m = new Date().getMonth() + 1;
                document.getElementById('filterMois').value = String(m);
                updateViewModeSegments();
                loadStatsData();
            });
            
            // Bouton export Excel
            document.getElementById('btnExportExcel').addEventListener('click', exportToExcel);
        }

        /**
         * Charge la liste des clients
         */
        async function loadClients() {
            try {
                const response = await fetch('/API/messagerie_get_first_clients.php?limit=1000', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.clients) {
                    const clientSelect = document.getElementById('filterClient');
                    data.clients.forEach(client => {
                        const option = document.createElement('option');
                        option.value = client.id;
                        option.textContent = client.name;
                        clientSelect.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erreur lors du chargement des clients:', error);
            }
        }

        /**
         * Charge les données statistiques
         */
        async function loadStatsData() {
            const loadingDiv = document.getElementById('chartLoading');
            const canvas = document.getElementById('statsChart');
            const skeleton = document.getElementById('chartSkeleton');
            const loadingText = loadingDiv?.querySelector('.chart-loading-text');
            
            loadingDiv.style.display = 'flex';
            if (skeleton) { skeleton.style.display = 'flex'; }
            if (loadingText) { loadingText.style.display = 'block'; loadingText.textContent = 'Chargement des données...'; }
            canvas.style.display = 'none';
            const estimatePill = document.getElementById('statsEstimateText');
            if (estimatePill) { estimatePill.textContent = ''; estimatePill.style.display = 'none'; }
            updateViewModeSegments();
            
            const clientId = document.getElementById('filterClient').value;
            const mois = document.getElementById('filterMois').value;
            const annee = document.getElementById('filterAnnee').value;
            
            const params = new URLSearchParams();
            if (clientId) params.append('client_id', clientId);
            if (mois) params.append('mois', mois);
            if (annee) params.append('annee', annee);
            
            try {
                const response = await fetch(`/API/paiements_get_stats.php?${params.toString()}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.data) {
                    currentData = data.data;
                    if (data.data.labels && data.data.labels.length > 0) {
                        updateChart(data.data);
                        loadingDiv.style.display = 'none';
                        canvas.style.display = 'block';
                    } else {
                        if (skeleton) skeleton.style.display = 'none';
                        if (loadingText) loadingText.textContent = 'Aucune donnée disponible pour les filtres sélectionnés';
                        canvas.style.display = 'none';
                        if (estimatePill) { estimatePill.textContent = ''; estimatePill.style.display = 'none'; }
                    }
                } else {
                    if (skeleton) skeleton.style.display = 'none';
                    if (loadingText) loadingText.textContent = data.error || 'Erreur lors du chargement des données';
                    canvas.style.display = 'none';
                    if (estimatePill) { estimatePill.textContent = ''; estimatePill.style.display = 'none'; }
                }
            } catch (error) {
                console.error('Erreur lors du chargement des statistiques:', error);
                if (skeleton) skeleton.style.display = 'none';
                if (loadingText) loadingText.textContent = 'Erreur lors du chargement des données';
                canvas.style.display = 'none';
                const ep = document.getElementById('statsEstimateText');
                if (ep) { ep.textContent = ''; ep.style.display = 'none'; }
            }
        }

        function updateViewModeSegments() {
            const mois = document.getElementById('filterMois')?.value || '';
            const segM = document.getElementById('segmentMonthly');
            const segD = document.getElementById('segmentDaily');
            const isMonthly = !mois || mois === '';
            if (segM) segM.classList.toggle('active', isMonthly);
            if (segD) segD.classList.toggle('active', !isMonthly);
        }

        /**
         * Calcule l'estimation mois prochain (moyenne mobile 3 derniers mois complets non nuls)
         * @param {number[]} totals - données mensuelles
         * @param {number} excludeFromIndex - exclure les mois à partir de cet index (ex: mois courant)
         * @param {number|null} currentMonthEstimate - estimation fin mois courant pour pondération
         */
        function computeNextMonthEstimate(totals, excludeFromIndex = totals.length, currentMonthEstimate = null) {
            const slice = totals.slice(0, excludeFromIndex);
            const nonZero = slice.filter(v => v > 0);
            if (nonZero.length < 2) return null;
            const last3 = nonZero.slice(-3);
            const avg = last3.reduce((a, b) => a + b, 0) / last3.length;
            if (currentMonthEstimate !== null && currentMonthEstimate > 0) {
                return Math.round(0.7 * avg + 0.3 * currentMonthEstimate);
            }
            return Math.round(avg);
        }

        /**
         * Met à jour le graphique (Total + N&B + Couleur) et la pill d'estimation en texte
         */
        function updateChart(data) {
            if (typeof Chart === 'undefined') {
                console.error('Chart.js n\'est pas chargé');
                return;
            }
            const statsCanvas = document.getElementById('statsChart');
            if (!statsCanvas) return;
            const ctx = statsCanvas.getContext('2d');
            if (statsChart && typeof statsChart.destroy === 'function') {
                statsChart.destroy();
                statsChart = null;
            }

            const groupBy = data.group_by || 'day';
            const isMonthly = groupBy === 'month';
            const chartTitle = isMonthly ? 'Consommation mensuelle' : 'Consommation quotidienne';
            const datesFull = data.dates_full || [];
            const labels = [...(data.labels || [])];
            const fmt = (n) => new Intl.NumberFormat('fr-FR').format(n);

            const n = labels.length;
            let totalData = (data.total_pages || []).map(v => Number(v) || 0);
            let nbData = (data.noir_blanc || []).map(v => Number(v) || 0);
            let couleurData = (data.couleur || []).map(v => Number(v) || 0);

            const len = Math.min(n, totalData.length, nbData.length, couleurData.length);
            totalData = totalData.slice(0, len);
            nbData = nbData.slice(0, len);
            couleurData = couleurData.slice(0, len);
            const labelsTrimmed = labels.slice(0, len);

            for (let i = 0; i < len; i++) {
                if (totalData[i] === 0 && (nbData[i] > 0 || couleurData[i] > 0)) {
                    totalData[i] = nbData[i] + couleurData[i];
                }
            }

            const anneeFilter = parseInt(document.getElementById('filterAnnee')?.value || new Date().getFullYear());
            const now = new Date();
            const currentYear = now.getFullYear();
            const currentMonth = now.getMonth() + 1;

            let chartSubtitle = isMonthly ? 'Pages par mois' : 'Pages par jour';
            let estimateEndOfMonth = null;
            let estimateNextMonth = null;
            let idxCurrentMonth = currentMonth - 1;
            let currentMonthSoFar = data.current_month_so_far || null;

            if (isMonthly && anneeFilter === currentYear && idxCurrentMonth < len) {
                if (currentMonthSoFar && currentMonthSoFar.days_elapsed >= 7 && currentMonthSoFar.total > 0) {
                    const runRate = currentMonthSoFar.total / currentMonthSoFar.days_elapsed;
                    estimateEndOfMonth = Math.round(runRate * currentMonthSoFar.days_in_month);
                    estimateEndOfMonth = Math.max(estimateEndOfMonth, currentMonthSoFar.total);
                }
            }

            const excludeIdx = (anneeFilter === currentYear && idxCurrentMonth < len) ? idxCurrentMonth : len;
            estimateNextMonth = computeNextMonthEstimate(totalData, excludeIdx, estimateEndOfMonth);

            const estimatePill = document.getElementById('statsEstimateText');
            if (estimatePill) {
                if (isMonthly) {
                    const parts = [];
                    if (estimateEndOfMonth !== null) {
                        parts.push('Est. mois en cours: ~' + fmt(estimateEndOfMonth) + ' pages');
                    } else if (anneeFilter === currentYear && currentMonthSoFar && currentMonthSoFar.days_elapsed < 7) {
                        parts.push('Est. mois en cours: indisponible');
                    }
                    if (estimateNextMonth !== null) {
                        parts.push('Est. mois prochain: ~' + fmt(estimateNextMonth) + ' pages');
                    }
                    estimatePill.textContent = parts.join(' • ');
                    estimatePill.style.display = parts.length ? 'block' : 'none';
                } else {
                    estimatePill.textContent = '';
                    estimatePill.style.display = 'none';
                }
            }

            const titleEl = document.getElementById('statsChartTitle');
            const subtitleEl = document.getElementById('statsChartSubtitle');
            if (titleEl) titleEl.textContent = chartTitle;
            if (subtitleEl) subtitleEl.textContent = chartSubtitle;

            const datasets = [
                {
                    type: 'line',
                    label: 'Total',
                    data: totalData,
                    borderColor: 'rgba(16, 185, 129, 0.9)',
                    backgroundColor: 'rgba(16, 185, 129, 0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: 'rgba(16, 185, 129, 0.9)',
                    pointBorderWidth: 2
                },
                {
                    type: 'line',
                    label: 'N&B',
                    data: nbData,
                    borderColor: 'rgba(30, 41, 59, 0.85)',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 4
                },
                {
                    type: 'line',
                    label: 'Couleur',
                    data: couleurData,
                    borderColor: 'rgba(59, 130, 246, 0.85)',
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.35,
                    pointRadius: 2,
                    pointHoverRadius: 4
                }
            ];

            const chartLabels = labelsTrimmed;
            const chartTotalData = totalData;
            const chartNbData = nbData;
            const chartCouleurData = couleurData;
            const chartDatesFull = datesFull.length >= len ? datesFull.slice(0, len) : [];

            statsChart = new Chart(ctx, {
                type: 'line',
                data: { labels: chartLabels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 600, easing: 'easeOutQuart' },
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        x: {
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.04)',
                                lineWidth: 1,
                                drawBorder: false
                            },
                            ticks: {
                                maxRotation: isMonthly ? 0 : 45,
                                minRotation: isMonthly ? 0 : 45,
                                maxTicksLimit: 12,
                                autoSkip: true,
                                font: { size: 11, weight: '400' },
                                color: 'var(--text-secondary)',
                                padding: 10
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                display: true,
                                color: 'rgba(0, 0, 0, 0.04)',
                                lineWidth: 1,
                                drawBorder: false
                            },
                            ticks: {
                                maxTicksLimit: 8,
                                font: { size: 11, weight: '400' },
                                color: 'var(--text-secondary)',
                                padding: 10,
                                callback: function(v) {
                                    return new Intl.NumberFormat('fr-FR').format(v);
                                }
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'center',
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 16,
                                font: { size: 12, weight: '500' },
                                color: 'var(--text-primary)',
                                boxWidth: 12,
                                boxHeight: 12
                            },
                            onClick: function(e, legendItem, legend) {
                                const idx = legendItem.datasetIndex;
                                const chart = legend.chart;
                                const meta = chart.getDatasetMeta(idx);
                                meta.hidden = meta.hidden === null ? !chart.data.datasets[idx].hidden : null;
                                chart.update();
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.96)',
                            titleColor: '#fff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(255,255,255,0.08)',
                            borderWidth: 1,
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: true,
                            boxPadding: 6,
                            usePointStyle: true,
                            titleFont: { size: 13, weight: '600' },
                            bodyFont: { size: 12, weight: '400' },
                            callbacks: {
                                label: function(ctx) {
                                    const v = ctx.parsed.y;
                                    if (v == null) return '';
                                    const lbl = ctx.dataset.label || '';
                                    if (lbl === 'Total') return 'Total: ' + fmt(v) + ' pages';
                                    if (lbl === 'N&B') return 'N&B: ' + fmt(v);
                                    if (lbl === 'Couleur') return 'Couleur: ' + fmt(v);
                                    return lbl + ': ' + fmt(v);
                                },
                                title: function(ctx) {
                                    const i = ctx[0]?.dataIndex;
                                    return chartDatesFull[i] || chartLabels[i] || '';
                                }
                            }
                        }
                    }
                }
            });
        }

        /**
         * Exporte les données en Excel avec les filtres appliqués
         */
        function exportToExcel() {
            try {
                const clientId = document.getElementById('filterClient')?.value || '';
                const mois = document.getElementById('filterMois')?.value || '';
                const annee = document.getElementById('filterAnnee')?.value || '';
                
                const params = new URLSearchParams();
                
                // Ajouter les filtres seulement s'ils ont une valeur
                if (clientId && clientId !== '' && clientId !== '0') {
                    params.append('client_id', clientId);
                }
                if (mois && mois !== '' && mois !== '0') {
                    params.append('mois', mois);
                }
                if (annee && annee !== '' && annee !== '0') {
                    params.append('annee', annee);
                }
                
                // Construire l'URL et déclencher le téléchargement
                const url = `/API/paiements_export_excel.php?${params.toString()}`;
                
                // Afficher un message de chargement
                const btn = document.getElementById('btnExportExcel');
                if (btn) {
                    const originalText = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 0.5rem;"><span class="spinner" style="width: 14px; height: 14px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite;"></span> Export en cours...</span>';
                    
                    // Créer un lien temporaire pour déclencher le téléchargement
                    const link = document.createElement('a');
                    link.href = url;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // Réinitialiser le bouton après un court délai
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }, 2000);
                } else {
                    // Fallback si le bouton n'est pas trouvé
                    window.location.href = url;
                }
            } catch (error) {
                console.error('Erreur lors de l\'export Excel:', error);
                showMessage('Erreur lors de l\'export Excel: ' + error.message, 'error');
            }
        }
