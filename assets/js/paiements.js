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
         * Retourne les headers avec le token CSRF pour les requÃªtes modifiantes
         */
        function getCsrfHeaders() {
            const token = getCsrfToken();
            return token ? { 'X-CSRF-Token': token } : {};
        }

        // ============================================
        // FONCTIONS GLOBALES - Doivent ÃƒÂªtre dÃƒÂ©finies en premier
        // ============================================
        
        /**
         * Ouvre une section spÃƒÂ©cifique
         */
        function openSection(section) {
            console.log('openSection appelÃƒÂ© avec:', section);
            if (section === 'generer-facture') {
                openFactureModal();
            } else if (section === 'factures') {
                openFacturesListModal();
            } else if (section === 'paiements') {
                openPaiementsModal();
            } else if (section === 'payer') {
                openPayerModal();
            } else if (section === 'facture-mail') {
                openFactureMailModal();
            } else if (section === 'generation-facture-clients') {
                openGenerationFactureClientsModal();
            } else if (section === 'envoi-masse') {
                openEnvoiMasseModal();
            } else {
                console.log('Ouverture de la section:', section);
                showToast(`Section "${section}" - Ã€ implÃ©menter`, 'info');
            }
        }

        /**
         * Ouvre le modal de gÃƒÂ©nÃƒÂ©ration de facture
         */
        function openFactureModal() {
            console.log('Ouverture du modal de facture');
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            
            if (!modal) {
                console.error('Modal factureModal introuvable');
                showToast('Erreur: Le modal de facture est introuvable. VÃ©rifiez la console pour plus de dÃ©tails.', 'error');
                return;
            }
            if (!overlay) {
                console.error('Overlay factureModalOverlay introuvable');
                showToast('Erreur: L\'overlay du modal est introuvable. VÃ©rifiez la console pour plus de dÃ©tails.', 'error');
                return;
            }
            
            try {
                // RÃƒÂ©initialiser le formulaire
                const form = document.getElementById('factureForm');
                if (form) {
                    form.reset();
                    // RÃƒÂ©initialiser la date ÃƒÂ  aujourd'hui
                    const dateInput = document.getElementById('factureDate');
                    if (dateInput) {
                        dateInput.value = new Date().toISOString().split('T')[0];
                    }
                }
                
                // RÃƒÂ©initialiser les lignes
                const lignesContainer = document.getElementById('factureLignes');
                if (lignesContainer) {
                    lignesContainer.innerHTML = '';
                }
                document.getElementById('factureLignesContainer').style.display = 'none';
                document.getElementById('factureConsommationInfo').style.display = 'none';
                document.getElementById('factureClientNotifications').style.display = 'none';
                document.getElementById('factureClientNotifications').innerHTML = '';
                window.factureMachineData = null;
                
                // RÃƒÂ©initialiser les totaux
                calculateFactureTotal();
                
                // RÃƒÂ©initialiser le champ offre
                document.getElementById('factureOffre').value = '';
                
                // RÃƒÂ©initialiser les champs Achat
                const achatProduitsContainer = document.getElementById('factureAchatProduits');
                if (achatProduitsContainer) {
                    achatProduitsContainer.innerHTML = '';
                    addAchatProduit(); // Ajouter une premiÃƒÂ¨re ligne vide
                }
                
                // RÃƒÂ©initialiser les champs Service
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
                console.log('Modal ouvert avec succÃƒÂ¨s');
            } catch (error) {
                console.error('Erreur lors de l\'ouverture du modal:', error);
                showToast('Erreur lors de l\'ouverture du modal: ' + error.message, 'error');
            }
        }

        /**
         * Ferme le modal de gÃƒÂ©nÃƒÂ©ration de facture
         */
        function closeFactureModal() {
            const modal = document.getElementById('factureModal');
            const overlay = document.getElementById('factureModalOverlay');
            if (modal && overlay) {
                overlay.classList.remove('active');
                // Le modal se cachera automatiquement
                document.body.style.overflow = '';
                // RÃƒÂ©initialiser le formulaire
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
         * RÃƒÂ©initialise le champ client (autocomplete) ÃƒÂ  l'ouverture du modal
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
         * GÃƒÂ¨re le changement de client
         */
        async function onFactureClientChange() {
            const clientId = document.getElementById('factureClient').value;
            const offre = document.getElementById('factureOffre').value;
            
            // VÃƒÂ©rifier les imprimantes et afficher les notifications
            await checkClientNotifications(clientId);
            
            if (clientId && offre) {
                await checkClientPhotocopieurs(clientId, offre);
                await loadConsommationData();
            }
        }

        /**
         * VÃƒÂ©rifie les notifications pour un client (imprimantes et relevÃƒÂ©s)
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
                
                // Notification 1: Pas d'imprimante attribuÃƒÂ©e
                if (nbPhotocopieurs === 0) {
                    notifications.push({
                        type: 'warning',
                        message: 'Ã¢Å¡Â Ã¯Â¸Â Ce client n\'a pas d\'imprimante attribuÃƒÂ©e.'
                    });
                }
                
                // Notification 2: Pas de relevÃƒÂ© depuis plus de 2 jours
                if (nbPhotocopieurs > 0 && dernierReleveJours !== null && dernierReleveJours > 2) {
                    const joursText = dernierReleveJours === 1 ? 'jour' : 'jours';
                    notifications.push({
                        type: 'info',
                        message: `Ã¢â€žÂ¹Ã¯Â¸Â Aucun relevÃƒÂ© de compteur reÃƒÂ§u depuis ${dernierReleveJours} ${joursText}.`
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
                console.error('Erreur lors de la vÃƒÂ©rification des notifications:', error);
                notificationsContainer.style.display = 'none';
            }
        }

        /**
         * GÃƒÂ¨re le changement de type de facture
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
                
                // Ajouter une premiÃƒÂ¨re ligne de produit si le conteneur est vide
                const achatProduitsContainer = document.getElementById('factureAchatProduits');
                if (achatProduitsContainer && achatProduitsContainer.children.length === 0) {
                    addAchatProduit();
                }
                
                // RÃƒÂ©initialiser les totaux
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
                
                // RÃƒÂ©initialiser les totaux
                calculateFactureTotalService();
            } else {
                // Type Consommation
                // Masquer les autres champs
                if (achatFields) achatFields.style.display = 'none';
                if (serviceFields) serviceFields.style.display = 'none';
                
                // Retirer l'attribut required de tous les champs Achat et Service pour ÃƒÂ©viter l'erreur de validation
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
                
                // RÃƒÂ©initialiser les totaux
                calculateFactureTotal();
            }
        }

        /**
         * Calcule les totaux pour une facture de type Service
         */
        function calculateFactureTotalService() {
            const montantHT = parseFloat(document.getElementById('factureServiceMontant').value) || 0;
            const tauxTVA = 20; // TVA ÃƒÂ  20%
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
                            <option value="">SÃƒÂ©lectionner un type</option>
                            <option value="PC">PC</option>
                            <option value="LCD">LCD</option>
                            <option value="Imprimante">Imprimante</option>
                            <option value="Papier">Papier</option>
                            <option value="Toner">Toner</option>
                            <option value="Autre">Autre (ÃƒÂ  prÃƒÂ©ciser)</option>
                        </select>
                    </div>
                    <div class="facture-ligne-field achat-produit-autre-group" style="display: none;">
                        <label>PrÃƒÂ©cision <span style="color: #ef4444;">*</span></label>
                        <input type="text" name="achatProduits[${produitIndex}][autre]" class="achat-produit-autre" placeholder="Ex: Clavier, Souris">
                    </div>
                    <div class="facture-ligne-field">
                        <label>QuantitÃƒÂ© <span style="color: #ef4444;">*</span></label>
                        <input type="number" name="achatProduits[${produitIndex}][quantite]" class="achat-produit-quantite" step="0.01" min="0" value="1" required onchange="calculateAchatProduitTotal(this)">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Prix unitaire HT (Ã¢â€šÂ¬) <span style="color: #ef4444;">*</span></label>
                        <input type="number" name="achatProduits[${produitIndex}][prix]" class="achat-produit-prix" step="0.01" min="0" value="0" required onchange="calculateAchatProduitTotal(this)">
                    </div>
                    <div class="facture-ligne-field">
                        <label>Total HT (Ã¢â€šÂ¬)</label>
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
         * GÃƒÂ¨re le changement de type de produit (pour afficher/masquer le champ "Autre")
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
            
            const tauxTVA = 20; // TVA ÃƒÂ  20%
            const tva = totalHT * (tauxTVA / 100);
            const totalTTC = totalHT + tva;
            
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
        }

        /**
         * GÃƒÂ¨re le changement d'offre
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
         * VÃƒÂ©rifie le nombre de photocopieurs pour l'offre 2000
         */
        async function checkClientPhotocopieurs(clientId, offre) {
            if (offre !== '2000') {
                return; // Pas de vÃƒÂ©rification nÃƒÂ©cessaire pour l'offre 1000
            }
            
            try {
                const response = await fetch(`/API/factures_check_photocopieurs.php?client_id=${clientId}`, {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (data.nb_photocopieurs !== 2) {
                        showToast(`L'offre 2000 nÃ©cessite exactement 2 photocopieurs. Ce client a ${data.nb_photocopieurs} photocopieur(s).`, 'error');
                        document.getElementById('factureOffre').value = '';
                        return false;
                    }
                } else {
                    showToast('Erreur lors de la vÃ©rification des photocopieurs: ' + (data.error || 'Erreur inconnue'), 'error');
                    return false;
                }
            } catch (error) {
                console.error('Erreur lors de la vÃƒÂ©rification:', error);
                showToast('Erreur lors de la vÃ©rification des photocopieurs', 'error');
                return false;
            }
            
            return true;
        }

        /**
         * Charge les donnÃƒÂ©es de consommation et calcule automatiquement les lignes
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
                    '<p style="color: var(--text-secondary);">Veuillez sÃƒÂ©lectionner les dates de dÃƒÂ©but et fin de pÃƒÂ©riode</p>';
                document.getElementById('factureLignesContainer').style.display = 'none';
                return;
            }
            
            try {
                const url = `/API/factures_get_consommation.php?client_id=${encodeURIComponent(clientId)}&offre=${encodeURIComponent(offre)}&date_debut=${encodeURIComponent(dateDebut)}&date_fin=${encodeURIComponent(dateFin)}`;
                const response = await fetch(url, {
                    credentials: 'include'
                });
                
                // VÃƒÂ©rifier si la rÃƒÂ©ponse est OK
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
                    // VÃƒÂ©rifier si des dates ont ÃƒÂ©tÃƒÂ© ajustÃƒÂ©es et mettre ÃƒÂ  jour les champs
                    const notificationsContainer = document.getElementById('factureClientNotifications');
                    if (data.dates_ajustees && data.dates_ajustees.length > 0) {
                        // Mettre ÃƒÂ  jour les champs de date avec les dates ajustÃƒÂ©es
                        data.dates_ajustees.forEach(ajustement => {
                            if (ajustement.type === 'debut') {
                                // Convertir la date utilisÃƒÂ©e (dd/mm/yyyy) en format YYYY-MM-DD pour le champ
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
                                const typeText = ajustement.type === 'debut' ? 'dÃƒÂ©but' : 'fin';
                                notificationsHtml += `
                                    <div style="padding: 0.75rem 1rem; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: var(--radius-md); margin-bottom: 0.5rem; font-size: 0.875rem; color: #92400E;">
                                        Ã¢â€žÂ¹Ã¯Â¸Â Aucun relevÃƒÂ© reÃƒÂ§u le ${ajustement.date_demandee}. Utilisation du dernier relevÃƒÂ© disponible du ${ajustement.date_utilisee} (${ajustement.machine}).
                                    </div>
                                `;
                            });
                            notificationsContainer.innerHTML = notificationsHtml;
                            notificationsContainer.style.display = 'block';
                        }
                    }
                    
                    // Afficher les informations de consommation avec les compteurs de dÃƒÂ©but et fin
                    let infoHtml = '<h4 style="margin: 0 0 1rem; font-size: 1rem; font-weight: 600;">RelevÃƒÂ©s de compteurs:</h4>';
                    
                    data.machines.forEach((machine, index) => {
                        const dateDebutFormatted = machine.date_debut_releve ? new Date(machine.date_debut_releve).toLocaleString('fr-FR') : 'Non trouvÃƒÂ©';
                        const dateFinFormatted = machine.date_fin_releve ? new Date(machine.date_fin_releve).toLocaleString('fr-FR') : 'Non trouvÃƒÂ©';
                        
                        infoHtml += `
                            <div style="margin-bottom: 1rem; padding: 1rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                                <div style="margin-bottom: 0.75rem; font-weight: 600; color: var(--text-primary); font-size: 1.05rem;">
                                    ${machine.nom} ${machine.modele ? '(' + machine.modele + ')' : ''}
                                </div>
                                
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 0.75rem;">
                                    <div style="padding: 0.75rem; background: var(--bg-secondary); border-radius: var(--radius-sm);">
                                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Jour de dÃƒÂ©but pÃƒÂ©riode</div>
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
                                        <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.25rem;">Jour de fin pÃƒÂ©riode</div>
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
                                    <div style="font-size: 0.875rem; color: var(--text-secondary); margin-bottom: 0.5rem; font-weight: 600;">Consommation calculÃƒÂ©e (Fin - DÃƒÂ©but):</div>
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
                    
                    // GÃƒÂ©nÃƒÂ©rer les lignes de facture automatiquement
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
         * GÃƒÂ©nÃƒÂ¨re les lignes de facture depuis les donnÃƒÂ©es de consommation
         */
        function generateFactureLinesFromConsommation(data) {
            const container = document.getElementById('factureLignes');
            container.innerHTML = '';
            
            // PrÃƒÂ©parer les donnÃƒÂ©es pour l'API
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
            
            // Stocker les donnÃƒÂ©es pour la soumission
            window.factureMachineData = {
                offre: parseInt(data.offre),
                nb_imprimantes: data.machines.length,
                machines: machines
            };
            
            // Calculer les totaux cÃƒÂ´tÃƒÂ© client pour prÃƒÂ©visualisation
            let totalHT = 0;
            const forfaitHT = 100.0;
            const prixExcessNB = 0.05;
            const prixCouleur = 0.09;
            const offre = parseInt(data.offre);
            
            // Calculer selon l'offre
            if (offre === 1000) {
                // Offre 1000: forfait 100Ã¢â€šÂ¬ HT + dÃƒÂ©passement NB au-delÃƒÂ  de 1000 + couleur Ãƒâ€” 0.09
                totalHT += forfaitHT; // Toujours le forfait
                
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    // DÃƒÂ©passement NB si > 1000
                    if (consoNB > 1000) {
                        const excessNB = consoNB - 1000;
                        totalHT += excessNB * prixExcessNB;
                    }
                    
                    // Couleur toujours calculÃƒÂ©e
                    if (consoCouleur > 0) {
                        totalHT += consoCouleur * prixCouleur;
                    }
                });
            } else if (offre === 2000) {
                // Offre 2000: forfait 100Ã¢â€šÂ¬ HT (une seule fois) + par imprimante (dÃƒÂ©passement > 2000 + couleur)
                totalHT += forfaitHT; // Forfait une seule fois
                
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    // DÃƒÂ©passement NB si > 2000 par imprimante
                    if (consoNB > 2000) {
                        const excessNB = consoNB - 2000;
                        totalHT += excessNB * prixExcessNB;
                    }
                    
                    // Couleur toujours calculÃƒÂ©e par imprimante
                    if (consoCouleur > 0) {
                        totalHT += consoCouleur * prixCouleur;
                    }
                });
            }
            
            // Calculer TVA et TTC
            const tva = totalHT * 0.20;
            const totalTTC = totalHT + tva;
            
            // Mettre ÃƒÂ  jour l'affichage des totaux
            document.getElementById('factureMontantHT').value = totalHT.toFixed(2);
            document.getElementById('factureTVA').value = tva.toFixed(2);
            document.getElementById('factureMontantTTC').value = totalTTC.toFixed(2);
            
            // Afficher un rÃƒÂ©capitulatif du calcul
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
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Forfait:</strong> ${forfaitHT.toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                    }
                    
                    if (consoNB > 1000) {
                        const excessNB = consoNB - 1000;
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>DÃƒÂ©passement N&B:</strong> ${excessNB} copies Ãƒâ€” ${prixExcessNB.toFixed(2)} Ã¢â€šÂ¬ = ${(excessNB * prixExcessNB).toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                    }
                    
                    if (consoCouleur > 0) {
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Couleur (${machine.nom}):</strong> ${consoCouleur} copies Ãƒâ€” ${prixCouleur.toFixed(2)} Ã¢â€šÂ¬ = ${(consoCouleur * prixCouleur).toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                    }
                });
            } else if (offre === 2000) {
                detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Forfait:</strong> ${forfaitHT.toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                data.machines.forEach((machine) => {
                    const consoNB = parseFloat(machine.conso_nb) || 0;
                    const consoCouleur = parseFloat(machine.conso_couleur) || 0;
                    
                    if (consoNB > 2000) {
                        const excessNB = consoNB - 2000;
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>DÃƒÂ©passement N&B (${machine.nom}):</strong> ${excessNB} copies Ãƒâ€” ${prixExcessNB.toFixed(2)} Ã¢â€šÂ¬ = ${(excessNB * prixExcessNB).toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                    }
                    
                    if (consoCouleur > 0) {
                        detailCalcul += `<div style="margin-bottom: 0.5rem;"><strong>Couleur (${machine.nom}):</strong> ${consoCouleur} copies Ãƒâ€” ${prixCouleur.toFixed(2)} Ã¢â€šÂ¬ = ${(consoCouleur * prixCouleur).toFixed(2)} Ã¢â€šÂ¬ HT</div>`;
                    }
                });
            }
            
            infoDiv.innerHTML = `
                <div style="margin-bottom: 0.75rem;">
                    <strong style="color: var(--text-primary); font-size: 1.05rem;">${data.machines.length} imprimante(s) dÃƒÂ©tectÃƒÂ©e(s) - Offre ${offre}</strong>
                </div>
                <div style="font-size: 0.95rem; color: var(--text-secondary);">
                    ${detailCalcul || '<div>Aucun dÃƒÂ©passement</div>'}
                </div>
                <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid var(--border-color);">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span><strong>Total HT:</strong></span>
                        <strong>${totalHT.toFixed(2)} Ã¢â€šÂ¬</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 0.25rem;">
                        <span><strong>TVA (20%):</strong></span>
                        <strong>${tva.toFixed(2)} Ã¢â€šÂ¬</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-top: 0.5rem; border-top: 1px solid var(--border-color);">
                        <span><strong style="font-size: 1.1rem;">Total TTC:</strong></span>
                        <strong style="font-size: 1.1rem; color: var(--accent-primary);">${totalTTC.toFixed(2)} Ã¢â€šÂ¬</strong>
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
                        <label>QuantitÃƒÂ©</label>
                        <input type="number" name="lignes[${ligneIndex}][quantite]" step="0.01" min="0" value="1" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Prix unitaire HT (Ã¢â€šÂ¬)</label>
                        <input type="number" name="lignes[${ligneIndex}][prix_unitaire]" step="0.01" min="0" value="0" required>
                    </div>
                    <div class="facture-ligne-field">
                        <label>Total HT (Ã¢â€šÂ¬)</label>
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
            // Si on a des donnÃƒÂ©es de machine, ne pas recalculer (dÃƒÂ©jÃƒÂ  calculÃƒÂ© dans generateFactureLinesFromConsommation)
            if (window.factureMachineData) {
                return; // Les totaux sont dÃƒÂ©jÃƒÂ  calculÃƒÂ©s et affichÃƒÂ©s
            }
            
            // Calcul classique depuis les lignes manuelles
            let totalHT = 0;
            document.querySelectorAll('.ligne-total').forEach(input => {
                totalHT += parseFloat(input.value) || 0;
            });
            
            const tauxTVA = 20; // TVA ÃƒÂ  20%
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
                showToast('Veuillez sÃ©lectionner un client dans la liste (tapez pour rechercher, puis cliquez ou appuyez sur EntrÃ©e)', 'error');
                return;
            }
            
            // GÃƒÂ©rer le type "Service"
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
                
                // CrÃƒÂ©er la ligne de facture pour le service
                data.lignes = [{
                    description: serviceDescription.trim(),
                    type: 'Service',
                    quantite: 1,
                    prix_unitaire: serviceMontant,
                    total_ht: serviceMontant
                }];
            } else if (data.factureType === 'Achat') {
                // RÃƒÂ©cupÃƒÂ©rer tous les produits
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
                        showToast(`Veuillez sÃ©lectionner un type pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    if (type === 'Autre' && !autre.trim()) {
                        showToast(`Veuillez prÃ©ciser le type pour le produit ${index + 1}`, 'error');
                        return;
                    }
                    
                    if (quantite <= 0) {
                        showToast(`Veuillez saisir une quantitÃ© valide pour le produit ${index + 1}`, 'error');
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
                // Si on a des donnÃƒÂ©es de machine (nouveau format), utiliser celui-ci
                data.offre = window.factureMachineData.offre;
                data.nb_imprimantes = window.factureMachineData.nb_imprimantes;
                data.machines = window.factureMachineData.machines;
            } else {
                // Ancien format: validation des lignes manuelles
                if (!data.lignes || data.lignes.length === 0) {
                    showToast('Veuillez ajouter au moins une ligne de facture ou sÃ©lectionner une offre', 'error');
                    return;
                }
            }
            
            const btnSubmit = document.getElementById('btnGenererFacture');
            btnSubmit.disabled = true;
            btnSubmit.textContent = 'GÃƒÂ©nÃƒÂ©ration en cours...';
            
            try {
                console.log('Envoi des donnÃƒÂ©es:', data);
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
                console.log('RÃƒÂ©ponse reÃƒÂ§ue:', result);
                
                if (result.ok) {
                    showToast('Facture gÃ©nÃ©rÃ©e avec succÃ¨s !', 'success');
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
                console.error('Erreur lors de la requÃƒÂªte:', error);
                showToast('Erreur lors de la gÃ©nÃ©ration de la facture: ' + error.message, 'error');
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'GÃƒÂ©nÃƒÂ©rer la facture';
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

        /**
         * Charge la liste des factures depuis l'API
         */
        async function loadFacturesList() {
            const loadingDiv = document.getElementById('facturesListLoading');
            const container = document.getElementById('facturesListContainer');
            const errorDiv = document.getElementById('facturesListError');
            const tableBody = document.getElementById('facturesListTableBody');
            const countSpan = document.getElementById('facturesCount');
            const searchInput = document.getElementById('facturesSearchInput');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            // RÃƒÂ©initialiser la recherche
            if (searchInput) {
                searchInput.value = '';
            }
            
            try {
                const response = await fetch('/API/factures_liste.php', {
                    credentials: 'include'
                });
                const data = await response.json();
                
                if (data.ok && data.factures) {
                    // Stocker toutes les factures pour le filtrage
                    allFactures = data.factures;
                    
                    // Afficher toutes les factures initialement
                    displayFactures(allFactures);
                    
                    countSpan.textContent = data.total || data.factures.length;
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
            
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture trouvÃƒÂ©e
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Badge de statut
                    const statutColors = {
                        'brouillon': '#6b7280',
                        'envoyee': '#3b82f6',
                        'payee': '#10b981',
                        'en_retard': '#ef4444',
                        'annulee': '#000000'
                    };
                    const statutLabels = {
                        'brouillon': 'Brouillon',
                        'envoyee': 'EnvoyÃƒÂ©e',
                        'payee': 'PayÃƒÂ©e',
                        'en_retard': 'En retard',
                        'annulee': 'AnnulÃƒÂ©e'
                    };
                    const statutColor = statutColors[facture.statut] || '#6b7280';
                    const statutLabel = statutLabels[facture.statut] || facture.statut;
                    
                    // Bouton Action PDF
                    let actionButtons = '<span style="color: var(--text-muted); font-size: 0.85rem;">N/A</span>';
                    if (facture.pdf_path) {
                        actionButtons = `
                            <div style="display: flex; gap: 0.5rem; justify-content: center; align-items: center; flex-wrap: wrap;">
                                <button onclick="viewFacturePDFById(${facture.id}, '${facture.numero}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.4rem;">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                        <polyline points="14 2 14 8 20 8"></polyline>
                                        <line x1="16" y1="13" x2="8" y2="13"></line>
                                        <line x1="16" y1="17" x2="8" y2="17"></line>
                                    </svg>
                                    PDF
                                </button>
                            </div>
                        `;
                    }
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; font-weight: 600; color: var(--text-primary);">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${facture.client_nom}${facture.client_code ? ' (' + facture.client_code + ')' : ''}</td>
                        <td style="padding: 0.75rem; color: var(--text-secondary);">${facture.type}</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.montant_ht.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬</td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary);">${facture.tva.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬</td>
                        <td style="padding: 0.75rem; text-align: right; font-weight: 600; color: var(--text-primary);">${facture.montant_ttc.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬</td>
                        <td style="padding: 0.75rem; text-align: center;">
                            <span style="display: inline-block; padding: 0.25rem 0.75rem; border-radius: var(--radius-md); background: ${statutColor}20; color: ${statutColor}; font-size: 0.85rem; font-weight: 600;">${statutLabel}</span>
                        </td>
                                <td style="padding: 0.75rem; text-align: center;">${actionButtons}</td>
                    `;
                    tableBody.appendChild(row);
                });
                
                // Afficher le nombre de rÃƒÂ©sultats filtrÃƒÂ©s si diffÃƒÂ©rent du total
                if (factures.length !== allFactures.length) {
                    filteredCountSpan.textContent = `(${factures.length} sur ${allFactures.length})`;
                } else {
                    filteredCountSpan.textContent = '';
                }
            }
        }

        // Variable globale pour stocker le chemin du PDF actuel
        let currentPDFPath = '';

        /**
         * Ouvre le PDF d'une facture par son ID (avec rÃƒÂ©gÃƒÂ©nÃƒÂ©ration si nÃƒÂ©cessaire)
         */
        function viewFacturePDFById(factureId, factureNumero) {
            // Utiliser le script PHP qui gÃƒÂ¨re la rÃƒÂ©gÃƒÂ©nÃƒÂ©ration si nÃƒÂ©cessaire
            const pdfUrl = `/public/view_facture.php?id=${factureId}`;
            window.open(pdfUrl, '_blank');
        }

        /**
         * Ouvre le PDF d'une facture par son ID (avec rÃƒÂ©gÃƒÂ©nÃƒÂ©ration si nÃƒÂ©cessaire)
         */
        function viewFacturePDFById(factureId, factureNumero) {
            // Utiliser le script PHP qui gÃƒÂ¨re la rÃƒÂ©gÃƒÂ©nÃƒÂ©ration si nÃƒÂ©cessaire
            const pdfUrl = `/public/view_facture.php?id=${factureId}`;
            window.open(pdfUrl, '_blank');
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
                console.error('Ãƒâ€°lÃƒÂ©ments du modal PDF introuvables');
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
                
                // VÃƒÂ©rifier si le PDF se charge (timeout de 2 secondes)
                setTimeout(function() {
                    // Si l'embed n'a pas chargÃƒÂ©, afficher le fallback
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
                // Vider l'embed pour libÃƒÂ©rer la mÃƒÂ©moire
                if (embed) {
                    embed.src = '';
                }
                currentPDFPath = '';
            }
        }


        /**
         * Filtre les factures selon le terme de recherche
         */
        function filterFactures() {
            const searchInput = document.getElementById('facturesSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            if (!searchTerm) {
                // Afficher toutes les factures si la recherche est vide
                displayFactures(allFactures);
                return;
            }
            
            // Filtrer les factures
            const filtered = allFactures.filter(facture => {
                // Rechercher dans le numÃƒÂ©ro de facture
                if (facture.numero && facture.numero.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans la date (format franÃƒÂ§ais et format ISO)
                if (facture.date_facture_formatted && facture.date_facture_formatted.includes(searchTerm)) {
                    return true;
                }
                if (facture.date_facture && facture.date_facture.includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le nom du client (raison sociale)
                if (facture.client_nom && facture.client_nom.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le code client
                if (facture.client_code && facture.client_code.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le nom du dirigeant
                if (facture.client_nom_dirigeant && facture.client_nom_dirigeant.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le prÃƒÂ©nom du dirigeant
                if (facture.client_prenom_dirigeant && facture.client_prenom_dirigeant.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                // Rechercher dans le type
                if (facture.type && facture.type.toLowerCase().includes(searchTerm)) {
                    return true;
                }
                
                return false;
            });
            
            // Afficher les factures filtrÃƒÂ©es
            displayFactures(filtered);
        }

        // ============================================
        // GESTION DES PAIEMENTS
        // ============================================
        
        let allPaiements = [];
        let filteredPaiements = [];
        let currentPaiementStatusFilter = 'all';

        /**
         * Ouvre le modal des paiements
         */
        function openPaiementsModal() {
            const modal = document.getElementById('paiementsModal');
            const overlay = document.getElementById('paiementsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal paiements introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // Charger les factures
            loadPaiementsList();
        }

        /**
         * Ferme le modal des paiements
         */
        function closePaiementsModal() {
            const modal = document.getElementById('paiementsModal');
            const overlay = document.getElementById('paiementsModalOverlay');
            
            if (modal && overlay) {
                overlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        /**
         * Charge la liste des factures pour les paiements
         */
        async function loadPaiementsList() {
            const loadingDiv = document.getElementById('paiementsListLoading');
            const container = document.getElementById('paiementsListContainer');
            const errorDiv = document.getElementById('paiementsListError');
            
            loadingDiv.style.display = 'block';
            container.style.display = 'none';
            errorDiv.style.display = 'none';
            
            try {
                const response = await fetch('/API/factures_liste.php');
                const result = await response.json();
                
                if (result.ok && result.factures) {
                    allPaiements = result.factures;
                    filteredPaiements = [...allPaiements];
                    displayPaiements(allPaiements);
                    
                    // Mettre ÃƒÂ  jour le compteur
                    document.getElementById('paiementsCount').textContent = allPaiements.length;
                    
                    loadingDiv.style.display = 'none';
                    container.style.display = 'block';
                } else {
                    loadingDiv.style.display = 'none';
                    errorDiv.style.display = 'block';
                    errorDiv.textContent = result.error || 'Erreur lors du chargement des factures';
                }
            } catch (error) {
                console.error('Erreur lors du chargement des paiements:', error);
                loadingDiv.style.display = 'none';
                errorDiv.style.display = 'block';
                errorDiv.textContent = 'Erreur lors du chargement des factures';
            }
        }

        /**
         * Affiche les factures dans le tableau des paiements
         */
        function displayPaiements(factures) {
            const tableBody = document.getElementById('paiementsListTableBody');
            const filteredCountSpan = document.getElementById('paiementsFilteredCount');
            
            tableBody.innerHTML = '';
            
            if (factures.length === 0) {
                tableBody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2rem; color: var(--text-secondary);">
                            Aucune facture trouvÃƒÂ©e
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                filteredCountSpan.textContent = `${factures.length} affichÃƒÂ©e(s)`;
                
                factures.forEach(facture => {
                    const row = document.createElement('tr');
                    row.style.borderBottom = '1px solid var(--border-color)';
                    row.style.transition = 'background 0.2s';
                    row.onmouseenter = function() { this.style.background = 'var(--bg-secondary)'; };
                    row.onmouseleave = function() { this.style.background = ''; };
                    
                    // Couleurs et labels pour les statuts
                    const statutColors = {
                        'brouillon': '#6b7280',
                        'envoyee': '#3b82f6',
                        'payee': '#10b981',
                        'en_retard': '#ef4444',
                        'annulee': '#000000'
                    };
                    const statutLabels = {
                        'brouillon': 'En cours',
                        'envoyee': 'En attente',
                        'payee': 'PayÃƒÂ©',
                        'en_retard': 'En retard',
                        'annulee': 'AnnulÃƒÂ©e'
                    };
                    const currentStatutColor = statutColors[facture.statut] || '#6b7280';
                    const currentStatutLabel = statutLabels[facture.statut] || facture.statut;
                    
                    // Badge de statut en lecture seule (pas de modification possible)
                    const statutBadge = `
                        <span style="display: inline-block; padding: 0.5rem 1rem; border-radius: var(--radius-md); background: ${currentStatutColor}20; color: ${currentStatutColor}; font-size: 0.9rem; font-weight: 600; border: 1px solid ${currentStatutColor}40;">
                            ${currentStatutLabel}
                        </span>
                    `;
                    
                    row.innerHTML = `
                        <td style="padding: 0.75rem; color: var(--text-primary); font-weight: 600;">${facture.numero}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">${facture.date_facture_formatted}</td>
                        <td style="padding: 0.75rem; color: var(--text-primary);">
                            ${facture.client_nom || 'Client inconnu'}
                            ${facture.client_code ? ` (${facture.client_code})` : ''}
                        </td>
                        <td style="padding: 0.75rem; text-align: right; color: var(--text-primary); font-weight: 600;">
                            ${facture.montant_ttc.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${statutBadge}
                        </td>
                        <td style="padding: 0.75rem; text-align: center;">
                            ${facture.pdf_path ? `
                                <button onclick="viewFacturePDFById(${facture.id}, '${facture.numero}')" style="padding: 0.4rem 0.75rem; background: var(--accent-primary); color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';">
                                    PDF
                                </button>
                            ` : '<span style="color: var(--text-muted); font-size: 0.85rem;">N/A</span>'}
                        </td>
                    `;
                    
                    tableBody.appendChild(row);
                });
            }
        }

        /**
         * Filtre les paiements selon le terme de recherche
         */
        function filterPaiements() {
            const searchInput = document.getElementById('paiementsSearchInput');
            const searchTerm = (searchInput.value || '').toLowerCase().trim();
            
            let filtered = allPaiements;
            
            // Filtrer par statut
            if (currentPaiementStatusFilter !== 'all') {
                filtered = filtered.filter(f => f.statut === currentPaiementStatusFilter);
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
            
            // Mettre ÃƒÂ  jour les boutons de filtre
            document.querySelectorAll('.filter-btn').forEach(btn => {
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
         * Met ÃƒÂ  jour le statut de paiement d'une facture
         */
        async function updatePaiementStatut(factureId, newStatut, factureNumero, selectElement) {
            const statutLabels = {
                'brouillon': 'En cours',
                'envoyee': 'En attente',
                'payee': 'PayÃƒÂ©',
                'en_retard': 'En retard',
                'annulee': 'AnnulÃƒÂ©e'
            };
            const newStatutLabel = statutLabels[newStatut] || newStatut;
            
            // Sauvegarder l'ancienne valeur au cas oÃƒÂ¹ l'utilisateur annule
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
                    // Afficher un message de succÃƒÂ¨s
                    showMessage('Statut mis ÃƒÂ  jour avec succÃƒÂ¨s', 'success');
                    // Recharger la liste
                    loadPaiementsList();
                } else {
                    showMessage('Erreur : ' + (result.error || 'Impossible de mettre ÃƒÂ  jour le statut'), 'error');
                    // Remettre l'ancienne valeur en cas d'erreur
                    if (selectElement && oldValue) {
                        selectElement.value = oldValue;
                    }
                    // RÃƒÂ©activer le select
                    if (selectElement) {
                        selectElement.disabled = false;
                        selectElement.style.opacity = '1';
                        selectElement.style.cursor = 'pointer';
                    }
                }
            } catch (error) {
                console.error('Erreur lors de la mise ÃƒÂ  jour du statut:', error);
                showMessage('Erreur lors de la mise ÃƒÂ  jour du statut', 'error');
                // Remettre l'ancienne valeur en cas d'erreur
                if (selectElement && oldValue) {
                    selectElement.value = oldValue;
                }
                // RÃƒÂ©activer le select
                if (selectElement) {
                    selectElement.disabled = false;
                    selectElement.style.opacity = '1';
                    selectElement.style.cursor = 'pointer';
                }
            }
        }
        
        /**
         * Affiche un message ÃƒÂ  l'utilisateur
         */
        function showMessage(message, type = 'success') {
            const messageContainer = document.getElementById('messageContainer');
            if (!messageContainer) return;
            
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${type}`;
            
            const icon = type === 'success' ? 'Ã¢Å“â€œ' : type === 'error' ? 'Ã¢Å“â€¢' : 'Ã¢Å¡Â ';
            messageDiv.innerHTML = `
                <span class="message-icon">${icon}</span>
                <span>${message}</span>
            `;
            
            messageContainer.innerHTML = '';
            messageContainer.appendChild(messageDiv);
            
            // Masquer le message aprÃƒÂ¨s 5 secondes
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
            
            // RÃƒÂ©initialiser la recherche
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
                    
                    // Mettre ÃƒÂ  jour le compteur
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
                            Aucun paiement trouvÃƒÂ©
                        </td>
                    </tr>
                `;
                filteredCountSpan.textContent = '';
            } else {
                filteredCountSpan.textContent = `${paiements.length} affichÃƒÂ©(s)`;
                
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
                    
                    // Actions (justificatif et envoi si disponible)
                    let actions = '<span style="color: var(--text-muted); font-size: 0.85rem;">-</span>';
                    if (paiement.recu_path) {
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
                                    <button onclick="sendRecuEmail(${paiement.id}, '${paiement.reference || ''}')" style="padding: 0.4rem 0.75rem; background: #10b981; color: white; border: none; border-radius: var(--radius-md); cursor: pointer; font-size: 0.85rem; font-weight: 600; transition: all 0.2s ease; display: inline-flex; align-items: center; gap: 0.25rem;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.boxShadow='var(--shadow-md)';" onmouseleave="this.style.transform='translateY(0)'; this.style.boxShadow='none';" title="Envoyer le reÃƒÂ§u par email">
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
                            ${paiement.montant.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬
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
                
                // Afficher le nombre de rÃƒÂ©sultats filtrÃƒÂ©s si diffÃƒÂ©rent du total
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
            
            // Mettre ÃƒÂ  jour les boutons de filtre
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
         * Envoie le reÃƒÂ§u de paiement par email au client
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

            if (!confirm(`ÃƒÅ tes-vous sÃƒÂ»r de vouloir envoyer le reÃƒÂ§u ${reference || ''} par email au client ?`)) {
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
                        showNotification('SuccÃƒÂ¨s', `ReÃƒÂ§u envoyÃƒÂ© avec succÃƒÂ¨s ÃƒÂ  ${data.email || 'le client'}`, 'success');
                    } else {
                        showToast(`ReÃƒÂ§u envoyÃƒÂ© avec succÃƒÂ¨s ÃƒÂ  ${data.email || 'le client'}`);
                    }
                    // Recharger l'historique pour mettre ÃƒÂ  jour le statut
                    if (typeof loadHistoriquePaiements === 'function') {
                        loadHistoriquePaiements();
                    }
                } else {
                    if (typeof showNotification === 'function') {
                        showNotification('Erreur', data.error || 'Erreur lors de l\'envoi du reÃƒÂ§u', 'error');
                    } else {
                        showToast('Erreur: ' + (data.error || 'Erreur lors de l\'envoi du reÃ§u'), 'error');
                    }
                }
            } catch (error) {
                console.error('Erreur envoi reÃƒÂ§u:', error);
                if (typeof showNotification === 'function') {
                    showNotification('Erreur', 'Erreur lors de l\'envoi du reÃƒÂ§u', 'error');
                } else {
                    showToast('Erreur lors de l\'envoi du reÃ§u', 'error');
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
            
            // RÃƒÂ©initialiser le formulaire et l'ÃƒÂ©tat
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
            
            // RÃƒÂ©initialiser la recherche facture
            resetFactureSearch();
        }

        /**
         * RÃƒÂ©initialise le champ recherche facture
         */
        function resetFactureSearch() {
            const searchInput = document.getElementById('facture_search');
            const hiddenInput = document.getElementById('facture_id');
            const suggestions = document.getElementById('facture_suggestions');
            if (searchInput) searchInput.value = '';
            if (hiddenInput) hiddenInput.value = '';
            if (suggestions) { suggestions.innerHTML = ''; suggestions.style.display = 'none'; }
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

        // Ãƒâ€°tat global pour l'envoi d'email
        let factureMailState = {
            isSubmitting: false,
            selectedFacture: null
        };

        /**
         * Autocomplete facture - debounce, AbortController, clavier
         */
        (function initFactureAutocomplete() {
            const DEBOUNCE_MS = 200;
            const searchInput = document.getElementById('facture_search');
            const hiddenInput = document.getElementById('facture_id');
            const suggestionsEl = document.getElementById('facture_suggestions');
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

            function formatSuggestionText(r) {
                return `${r.client_nom} Ã¢â‚¬â€ ${r.numero} Ã¢â‚¬â€ ${r.date_emission}`;
            }

            function formatDisplayText(r) {
                return `NÃ‚Â°${r.numero} Ã¢â‚¬â€ ${r.client_nom} Ã¢â‚¬â€ ${r.date_emission}`;
            }

            function selectFacture(r) {
                hiddenInput.value = String(r.id);
                searchInput.value = formatDisplayText(r);
                hideSuggestions();
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
                    div.textContent = formatSuggestionText(r);
                    div.addEventListener('click', () => selectFacture(r));
                    suggestionsEl.appendChild(div);
                });
                suggestionsEl.style.display = 'block';
            }

            function updateHighlight() {
                const items = suggestionsEl.querySelectorAll('.client-suggestion-item');
                items.forEach((el, i) => el.classList.toggle('active', i === highlightedIndex));
            }

            searchInput.addEventListener('input', () => {
                hiddenInput.value = '';
                factureMailState.selectedFacture = null;
                hideFactureMailStatus();
                const btnRenvoyer = document.getElementById('btnRenvoyerFactureMail');
                if (btnRenvoyer) btnRenvoyer.style.display = 'none';
                const q = searchInput.value.trim();
                if (q.length < 1) {
                    hideSuggestions();
                    return;
                }
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    if (abortController) abortController.abort();
                    abortController = new AbortController();
                    fetch('/API/factures_search.php?q=' + encodeURIComponent(q), {
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
                        selectFacture(currentResults[highlightedIndex]);
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
         * Met ÃƒÂ  jour le badge de statut de la facture
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
                badgeText.textContent = 'Non envoyÃƒÂ©e';
            } else if (emailEnvoye === '2') {
                badge.classList.add('status-pending');
                badgeText.textContent = 'En cours d\'envoi';
            } else if (emailEnvoye === '1') {
                badge.classList.add('status-sent');
                if (dateEnvoi) {
                    const date = new Date(dateEnvoi);
                    badgeText.textContent = `EnvoyÃƒÂ©e le ${date.toLocaleDateString('fr-FR')} ÃƒÂ  ${date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
                } else {
                    badgeText.textContent = 'EnvoyÃƒÂ©e';
                }
            } else {
                badge.classList.add('status-failed');
                badgeText.textContent = 'Ãƒâ€°chec';
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
                showToast('Veuillez sÃƒÂ©lectionner une facture', 'error');
                return;
            }
            
            if (!email || !email.includes('@')) {
                showToast('Veuillez saisir une adresse email valide', 'error');
                return;
            }
            
            // Mettre ÃƒÂ  jour l'ÃƒÂ©tat
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
                console.log('RÃƒÂ©ponse reÃƒÂ§ue:', result);
                
                if (result.ok) {
                    // SuccÃƒÂ¨s
                    showFactureMailSuccess(result);
                    showToast('Email envoyÃƒÂ© avec succÃƒÂ¨s !', 'success');
                    
                    // RÃƒÂ©initialiser pour permettre un nouvel envoi
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
         * Renvoie une facture dÃƒÂ©jÃƒÂ  envoyÃƒÂ©e
         */
        async function renvoyerFactureMail() {
            if (factureMailState.isSubmitting || !factureMailState.selectedFacture) {
                return;
            }
            
            // Utiliser la mÃƒÂªme fonction mais avec force=true (gÃƒÂ©rÃƒÂ© cÃƒÂ´tÃƒÂ© backend)
            await submitFactureMailForm(new Event('submit'));
        }

        /**
         * Met ÃƒÂ  jour l'ÃƒÂ©tat de chargement de l'UI
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
         * Affiche le rÃƒÂ©sultat de succÃƒÂ¨s
         */
        function showFactureMailSuccess(result) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result success';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = 'Ã¢Å“â€œ';
            if (message) message.textContent = 'Email envoyÃƒÂ© avec succÃƒÂ¨s !';
            
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
                details.textContent = detailsText || 'Aucun dÃƒÂ©tail disponible';
                details.onclick = () => {
                    navigator.clipboard.writeText(detailsText).then(() => {
                        showToast('DÃƒÂ©tails copiÃƒÂ©s !', 'success');
                    });
                };
            }
        }

        /**
         * Affiche le rÃƒÂ©sultat d'erreur
         */
        function showFactureMailError(errorMsg) {
            const resultDiv = document.getElementById('factureMailResult');
            if (!resultDiv) return;
            
            resultDiv.style.display = 'block';
            resultDiv.className = 'facture-mail-result error';
            
            const icon = resultDiv.querySelector('.result-icon');
            const message = resultDiv.querySelector('.result-message');
            const details = resultDiv.querySelector('.result-details');
            
            if (icon) icon.textContent = 'Ã¢Å“â€”';
            if (message) message.textContent = 'Erreur lors de l\'envoi';
            if (details) {
                details.textContent = errorMsg;
                details.onclick = null;
            }
        }

        /**
         * Cache le rÃƒÂ©sultat
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
            // CrÃƒÂ©er le conteneur s'il n'existe pas
            let container = document.querySelector('.toast-container');
            if (!container) {
                container = document.createElement('div');
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            
            // CrÃƒÂ©er le toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = document.createElement('span');
            icon.className = 'toast-icon';
            icon.textContent = type === 'success' ? 'Ã¢Å“â€œ' : 'Ã¢Å“â€”';
            
            const text = document.createElement('span');
            text.textContent = message;
            
            toast.appendChild(icon);
            toast.appendChild(text);
            container.appendChild(toast);
            
            // Supprimer aprÃƒÂ¨s 5 secondes
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
        // GESTION DU MODAL GÃƒâ€°NÃƒâ€°RATION FACTURE CLIENTS
        // ============================================
        
        /**
         * Ouvre le modal de gÃƒÂ©nÃƒÂ©ration de factures pour clients
         */
        function openGenerationFactureClientsModal() {
            const modal = document.getElementById('generationFactureClientsModal');
            const overlay = document.getElementById('generationFactureClientsModalOverlay');
            
            if (!modal || !overlay) {
                console.error('Modal gÃƒÂ©nÃƒÂ©ration facture clients introuvable');
                return;
            }
            
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            
            // RÃƒÂ©initialiser le formulaire
            const form = document.getElementById('generationFactureClientsForm');
            const progressContainer = document.getElementById('genFactureProgressContainer');
            const btnSubmit = document.getElementById('btnGenererFacturesClients');
            const btnCancel = document.getElementById('btnCancelGeneration');
            
            if (form) {
                form.reset();
                form.style.display = 'block';
                // RÃƒÂ©initialiser la date ÃƒÂ  aujourd'hui
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
            
            // RÃƒÂ©initialiser les boutons
            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.style.display = 'inline-block';
                btnSubmit.textContent = 'GÃƒÂ©nÃƒÂ©rer les factures';
            }
            if (btnCancel) {
                btnCancel.disabled = false;
            }
            
            // RÃƒÂ©initialiser la barre de progression
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
                statusText.textContent = 'GÃƒÂ©nÃƒÂ©ration en cours...';
                statusText.classList.remove('complete');
            }
            
            // RÃƒÂ©initialiser les compteurs
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
         * Met ÃƒÂ  jour l'affichage de la progression
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
         * Ferme le modal de gÃƒÂ©nÃƒÂ©ration de factures pour clients
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
         * Soumet le formulaire de gÃƒÂ©nÃƒÂ©ration de factures pour clients
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
            
            // Initialiser la barre de progression avec l'ÃƒÂ©tat "running"
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
                statusText.textContent = 'GÃƒÂ©nÃƒÂ©ration en cours...';
                statusText.classList.remove('complete');
            }
            
            btnSubmit.disabled = true;
            btnSubmit.style.display = 'none';
            if (btnCancel) {
                btnCancel.disabled = true;
            }
            
            // Masquer les notifications prÃƒÂ©cÃƒÂ©dentes
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
            
            // Fonction pour calculer la couleur du gradient basÃƒÂ©e sur le pourcentage
            function getProgressGradient(percent) {
                if (percent >= 100) {
                    // Ãƒâ€°tat de succÃƒÂ¨s : vert solide
                    return 'linear-gradient(90deg, #10b981 0%, #059669 100%)';
                }
                
                // Gradient dynamique : vert Ã¢â€ â€™ bleu Ã¢â€ â€™ violet
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

            // Fonction pour mettre ÃƒÂ  jour la progression avec animations
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
                
                // Mettre ÃƒÂ  jour le pourcentage avec transition douce
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
                
                // Mettre ÃƒÂ  jour la barre de progression
                if (barEl) {
                    barEl.style.width = progressPercent + '%';
                    
                    // GÃƒÂ©rer les ÃƒÂ©tats (running vs complete)
                    if (progressPercent >= 100) {
                        // Ãƒâ€°tat de succÃƒÂ¨s
                        barEl.classList.remove('running');
                        barEl.classList.add('complete');
                        barEl.style.background = getProgressGradient(100);
                        barEl.style.backgroundSize = '100% 100%';
                        
                        if (percentDisplay) {
                            percentDisplay.classList.add('complete');
                        }
                        if (statusText) {
                            statusText.textContent = 'Ã¢Å“â€œ GÃƒÂ©nÃƒÂ©ration terminÃƒÂ©e avec succÃƒÂ¨s !';
                            statusText.classList.add('complete');
                        }
                    } else {
                        // Ãƒâ€°tat en cours
                        barEl.classList.remove('complete');
                        barEl.classList.add('running');
                        barEl.style.background = getProgressGradient(progressPercent);
                        barEl.style.backgroundSize = '200% 100%';
                        
                        if (percentDisplay) {
                            percentDisplay.classList.remove('complete');
                        }
                        if (statusText) {
                            statusText.textContent = 'GÃƒÂ©nÃƒÂ©ration en cours...';
                            statusText.classList.remove('complete');
                        }
                    }
                }
                
                // Mettre ÃƒÂ  jour les statistiques avec animations
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
                console.log('RÃƒÂ©ponse reÃƒÂ§ue:', result);
                
                // Mettre ÃƒÂ  jour avec les vraies valeurs
                const totalClients = (result.total_clients || 0) + (result.total_exclus || 0);
                updateProgressWithAnimation(100, totalClients, result.total_generees || 0, result.total_exclus || 0);
                
                if (result.ok) {
                    // Changer le style pour le succÃƒÂ¨s
                    if (progressContainer) {
                        progressContainer.style.background = 'linear-gradient(135deg, #10b981 0%, #059669 100%)';
                    }
                    
                    // Afficher les rÃƒÂ©sultats dans le log
                    const logContainer = document.getElementById('genFactureProgressLog');
                    const logContent = document.getElementById('genFactureProgressLogContent');
                    
                    if (logContainer && logContent) {
                        logContent.innerHTML = '';
                        
                        // Afficher les factures gÃƒÂ©nÃƒÂ©rÃƒÂ©es (style carte + bouton PDF)
                        if (result.factures_generees && result.factures_generees.length > 0) {
                            result.factures_generees.forEach(facture => {
                                const logItem = document.createElement('div');
                                logItem.style.cssText = 'padding: 0.75rem 1rem; background: rgba(15,23,42,0.6); border-radius: var(--radius-md); color: white; font-size: 0.85rem; border: 1px solid rgba(148,163,184,0.4); display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; box-shadow: 0 8px 20px rgba(15,23,42,0.4);';

                                const montant = parseFloat(facture.montant_ttc || 0).toFixed(2).replace('.', ',');

                                logItem.innerHTML = `
                                    <div style="display:flex; align-items:flex-start; gap:0.6rem;">
                                        <div style="width: 22px; height: 22px; border-radius: 999px; background: rgba(16,185,129,0.2); display:flex; align-items:center; justify-content:center; color:#10b981;">
                                            Ã¢Å“â€œ
                                        </div>
                                        <div>
                                            <div style="font-weight:600; margin-bottom:0.15rem; color: #1a202c;">
                                                ${facture.client_nom}
                                            </div>
                                            <div style="font-size:0.8rem; color: rgba(26,32,44,0.7);">
                                                Facture <strong>${facture.numero}</strong> Ã¢â‚¬Â¢ <span style="color:#059669;">${montant} Ã¢â€šÂ¬ TTC</span>
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
                                logItem.innerHTML = `Ã¢Å¡Â Ã¯Â¸Â <strong>${client.client_nom}</strong> - ${client.raison}`;
                                logContent.appendChild(logItem);
                            });
                        }
                        
                        logContainer.style.display = 'block';
                    }
                    
                    // Afficher le message de succÃƒÂ¨s
                    let message = `${result.total_generees} facture(s) gÃƒÂ©nÃƒÂ©rÃƒÂ©e(s) avec succÃƒÂ¨s`;
                    if (result.total_exclus > 0) {
                        message += `. ${result.total_exclus} client(s) exclu(s)`;
                    }
                    showMessage(message, 'success');
                    
                    // Afficher les notifications pour les clients exclus dans le formulaire
                    if (result.clients_exclus && result.clients_exclus.length > 0 && notificationsDiv) {
                        let notificationsHtml = '<div style="padding: 1rem; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: var(--radius-md); margin-top: 1rem;">';
                        notificationsHtml += '<div style="font-weight: 600; color: #92400E; margin-bottom: 0.75rem;">Ã¢Å¡Â Ã¯Â¸Â Clients exclus de la gÃƒÂ©nÃƒÂ©ration:</div>';
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
                    
                    // Si toutes les factures ont ÃƒÂ©tÃƒÂ© gÃƒÂ©nÃƒÂ©rÃƒÂ©es, fermer le modal aprÃƒÂ¨s 5 secondes
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
                console.error('Erreur lors de la gÃƒÂ©nÃƒÂ©ration:', error);
                
                updateProgressWithAnimation(100, statsClients, statsGenerees, statsExclus);
                
                if (progressContainer) {
                    progressContainer.style.background = 'linear-gradient(135deg, #ef4444 0%, #dc2626 100%)';
                }
                
                showMessage('Erreur lors de la gÃƒÂ©nÃƒÂ©ration des factures: ' + error.message, 'error');
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
            
            // RÃƒÂ©initialiser le formulaire
            const form = document.getElementById('payerForm');
            if (form) {
                form.reset();
                // RÃƒÂ©initialiser la date ÃƒÂ  aujourd'hui
                const dateInput = document.getElementById('payerDate');
                if (dateInput) {
                    dateInput.value = new Date().toISOString().split('T')[0];
                }
                // RÃƒÂ©initialiser la rÃƒÂ©fÃƒÂ©rence (sera gÃƒÂ©nÃƒÂ©rÃƒÂ©e automatiquement)
                const referenceInput = document.getElementById('payerReference');
                if (referenceInput) {
                    referenceInput.value = '';
                    referenceInput.placeholder = 'GÃƒÂ©nÃƒÂ©rÃƒÂ©e automatiquement';
                }
            }
            
            // Charger les factures non payÃƒÂ©es
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
                // RÃƒÂ©initialiser le formulaire
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
                    factureSelect.innerHTML = '<option value="">SÃƒÂ©lectionner une facture</option>';
                    
                    // Filtrer les factures non payÃƒÂ©es et non annulÃƒÂ©es
                    const facturesDisponibles = data.factures.filter(f => 
                        f.statut !== 'payee' && f.statut !== 'annulee'
                    );
                    
                    facturesDisponibles.forEach(facture => {
                        const option = document.createElement('option');
                        option.value = facture.id;
                        option.textContent = `${facture.numero} - ${facture.client_nom} - ${facture.montant_ttc.toFixed(2)} Ã¢â€šÂ¬ TTC`;
                        option.setAttribute('data-montant', facture.montant_ttc);
                        option.setAttribute('data-client-id', facture.client_id);
                        factureSelect.appendChild(option);
                    });
                    
                    // Ãƒâ€°couter le changement pour prÃƒÂ©-remplir le montant
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
                showToast('Veuillez sÃ©lectionner une facture', 'error');
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
                console.log('RÃƒÂ©ponse reÃƒÂ§ue:', result);
                
                if (result.ok) {
                    const refMessage = result.reference ? ` RÃƒÂ©fÃƒÂ©rence: ${result.reference}` : '';
                    showMessage('Paiement enregistrÃƒÂ© avec succÃƒÂ¨s !' + refMessage, 'success');
                    closePayerModal();
                    // Recharger la liste des paiements si le modal est ouvert
                    if (document.getElementById('paiementsModalOverlay')?.classList.contains('active')) {
                loadPaiementsList();
                    }
                } else {
                    const errorMsg = result.error || 'Erreur inconnue';
                    console.error('Erreur API:', errorMsg);
                    showMessage('Erreur : ' + errorMsg, 'error');
                }
            } catch (error) {
                console.error('Erreur lors de la requÃƒÂªte:', error);
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
            
            // RÃƒÂ©initialiser
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
                        '<span style="color: #10b981; font-weight: 600;">Ã¢Å“â€œ EnvoyÃƒÂ©e</span>' : 
                        '<span style="color: var(--text-secondary);">Non envoyÃƒÂ©e</span>';
                    
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
                            ${facture.montant_ttc.toFixed(2).replace('.', ',')} Ã¢â€šÂ¬
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
         * Met ÃƒÂ  jour le compteur de sÃƒÂ©lection
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
         * SÃƒÂ©lectionne toutes les factures
         */
        function selectAllEnvoiMasse() {
            document.querySelectorAll('.envoi-masse-checkbox').forEach(cb => {
                cb.checked = true;
            });
            document.getElementById('envoiMasseSelectAll').checked = true;
            updateEnvoiMasseSelection();
        }
        
        /**
         * DÃƒÂ©sÃƒÂ©lectionne toutes les factures
         */
        function deselectAllEnvoiMasse() {
            document.querySelectorAll('.envoi-masse-checkbox').forEach(cb => {
                cb.checked = false;
            });
            document.getElementById('envoiMasseSelectAll').checked = false;
            updateEnvoiMasseSelection();
        }
        
        /**
         * Toggle sÃƒÂ©lection toutes
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
                showToast('Veuillez sÃ©lectionner au moins une facture', 'error');
                return;
            }
            
            if (!confirm(`Voulez-vous envoyer ${factureIds.length} facture(s) ÃƒÂ  leurs clients respectifs ?`)) {
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
            
            // RÃƒÂ©initialiser les compteurs
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
                    // Mettre ÃƒÂ  jour avec les rÃƒÂ©sultats
                    updateEnvoiMasseProgress(100, result.total, result.success, result.failed);
                    
                    if (progressBar) {
                        progressBar.classList.remove('running');
                        progressBar.classList.add('complete');
                    }
                    if (percentDisplay) percentDisplay.classList.add('complete');
                    if (statusText) {
                        statusText.textContent = `Ã¢Å“â€œ Envoi terminÃƒÂ© : ${result.success} rÃƒÂ©ussie(s), ${result.failed} ÃƒÂ©chec(s)`;
                        statusText.classList.add('complete');
                    }
                    
                    // Afficher les rÃƒÂ©sultats dÃƒÂ©taillÃƒÂ©s
                    displayEnvoiMasseResults(result.results);
                    
                    // RÃƒÂ©activer les boutons
                    if (btnCancel) btnCancel.disabled = false;
                    
                    showMessage(`${result.success} facture(s) envoyÃƒÂ©e(s) avec succÃƒÂ¨s${result.failed > 0 ? `, ${result.failed} ÃƒÂ©chec(s)` : ''}`, 'success');
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
         * Met ÃƒÂ  jour la progression de l'envoi
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
         * Affiche les rÃƒÂ©sultats dÃƒÂ©taillÃƒÂ©s
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
                    logItem.innerHTML = `Ã¢Å“â€œ Facture #${result.facture_id} : ${result.message}`;
                } else {
                    logItem.style.cssText = 'padding: 0.75rem 1rem; background: rgba(239,68,68,0.15); border-radius: var(--radius-md); color: #991b1b; font-size: 0.85rem; border: 1px solid rgba(239,68,68,0.3);';
                    logItem.innerHTML = `Ã¢Å“â€” Facture #${result.facture_id} : ${result.error || 'Erreur inconnue'}`;
                }
                logContent.appendChild(logItem);
            });
            
            logContainer.style.display = 'block';
        }

        // Exposer les fonctions globalement pour les onclick
        window.openSection = openSection;
        window.openFactureModal = openFactureModal;
        window.closeFactureModal = closeFactureModal;
        window.openFacturesListModal = openFacturesListModal;
        window.closeFacturesListModal = closeFacturesListModal;
        window.viewFacturePDF = viewFacturePDF;
        window.viewFacturePDFById = viewFacturePDFById;
        window.closePDFViewer = closePDFViewer;
        window.openPDFInNewTab = openPDFInNewTab;
        window.filterFactures = filterFactures;
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

        document.addEventListener('DOMContentLoaded', function() {
            const messageContainer = document.getElementById('messageContainer');

            // Initialisation de la section statistiques
            initStatsSection();

            // Ne pas ajouter de ligne au chargement, seulement quand le modal s'ouvre
            // addFactureLigne() sera appelÃƒÂ© dans openFactureModal()

            // Fermer les modals avec Escape
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const factureModal = document.getElementById('factureModal');
                    const factureModalOverlay = document.getElementById('factureModalOverlay');
                    const facturesListModal = document.getElementById('facturesListModal');
                    const facturesListModalOverlay = document.getElementById('facturesListModalOverlay');
                    const paiementsModalOverlay = document.getElementById('paiementsModalOverlay');
                    const pdfViewerModalOverlay = document.getElementById('pdfViewerModalOverlay');
                    const payerModalOverlay = document.getElementById('payerModalOverlay');
                    const historiquePaiementsModalOverlay = document.getElementById('historiquePaiementsModalOverlay');
                    const factureMailModalOverlay = document.getElementById('factureMailModalOverlay');
                    const generationFactureClientsModalOverlay = document.getElementById('generationFactureClientsModalOverlay');
                    const envoiMasseModalOverlay = document.getElementById('envoiMasseModalOverlay');
                    
                    if (pdfViewerModalOverlay && pdfViewerModalOverlay.classList.contains('active')) {
                        closePDFViewer();
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
                    } else if (paiementsModalOverlay && paiementsModalOverlay.classList.contains('active')) {
                        closePaiementsModal();
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
            
            // Remplir les annÃƒÂ©es (5 derniÃƒÂ¨res annÃƒÂ©es)
            const yearSelect = document.getElementById('filterAnnee');
            const currentYear = new Date().getFullYear();
            for (let i = currentYear; i >= currentYear - 5; i--) {
                const option = document.createElement('option');
                option.value = i;
                option.textContent = i;
                yearSelect.appendChild(option);
            }
            
            // DÃƒÂ©finir l'annÃƒÂ©e en cours par dÃƒÂ©faut
            yearSelect.value = currentYear;
            
            // Charger les donnÃƒÂ©es initiales
            loadStatsData();
            
            // Ãƒâ€°couter les changements de filtres
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
         * Charge les donnÃƒÂ©es statistiques
         */
        async function loadStatsData() {
            const loadingDiv = document.getElementById('chartLoading');
            const canvas = document.getElementById('statsChart');
            const skeleton = document.getElementById('chartSkeleton');
            const loadingText = loadingDiv?.querySelector('.chart-loading-text');
            
            loadingDiv.style.display = 'flex';
            if (skeleton) { skeleton.style.display = 'flex'; }
            if (loadingText) { loadingText.style.display = 'block'; loadingText.textContent = 'Chargement des donnÃƒÂ©es...'; }
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
                        if (loadingText) loadingText.textContent = 'Aucune donnÃƒÂ©e disponible pour les filtres sÃƒÂ©lectionnÃƒÂ©s';
                        canvas.style.display = 'none';
                        if (estimatePill) { estimatePill.textContent = ''; estimatePill.style.display = 'none'; }
                    }
                } else {
                    if (skeleton) skeleton.style.display = 'none';
                    if (loadingText) loadingText.textContent = data.error || 'Erreur lors du chargement des donnÃƒÂ©es';
                    canvas.style.display = 'none';
                    if (estimatePill) { estimatePill.textContent = ''; estimatePill.style.display = 'none'; }
                }
            } catch (error) {
                console.error('Erreur lors du chargement des statistiques:', error);
                if (skeleton) skeleton.style.display = 'none';
                if (loadingText) loadingText.textContent = 'Erreur lors du chargement des donnÃƒÂ©es';
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
         * @param {number[]} totals - donnÃƒÂ©es mensuelles
         * @param {number} excludeFromIndex - exclure les mois ÃƒÂ  partir de cet index (ex: mois courant)
         * @param {number|null} currentMonthEstimate - estimation fin mois courant pour pondÃƒÂ©ration
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
         * Met ÃƒÂ  jour le graphique (Total + N&B + Couleur) et la pill d'estimation en texte
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
                    estimatePill.textContent = parts.join(' Ã¢â‚¬Â¢ ');
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
         * Exporte les donnÃƒÂ©es en Excel avec les filtres appliquÃƒÂ©s
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
                
                // Construire l'URL et dÃƒÂ©clencher le tÃƒÂ©lÃƒÂ©chargement
                const url = `/API/paiements_export_excel.php?${params.toString()}`;
                
                // Afficher un message de chargement
                const btn = document.getElementById('btnExportExcel');
                if (btn) {
                    const originalText = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<span style="display: inline-flex; align-items: center; gap: 0.5rem;"><span class="spinner" style="width: 14px; height: 14px; border: 2px solid currentColor; border-top-color: transparent; border-radius: 50%; animation: spin 0.8s linear infinite;"></span> Export en cours...</span>';
                    
                    // CrÃƒÂ©er un lien temporaire pour dÃƒÂ©clencher le tÃƒÂ©lÃƒÂ©chargement
                    const link = document.createElement('a');
                    link.href = url;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    
                    // RÃƒÂ©initialiser le bouton aprÃƒÂ¨s un court dÃƒÂ©lai
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }, 2000);
                } else {
                    // Fallback si le bouton n'est pas trouvÃƒÂ©
                    window.location.href = url;
                }
            } catch (error) {
                console.error('Erreur lors de l\'export Excel:', error);
                showMessage('Erreur lors de l\'export Excel: ' + error.message, 'error');
            }
        }
