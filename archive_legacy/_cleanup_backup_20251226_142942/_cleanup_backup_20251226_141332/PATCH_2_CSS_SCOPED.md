# PATCH 2 : CSS - Styles scoped sous #maps-page

## Fichier : `assets/css/maps.css`

**⚠️ IMPORTANT :** Remplacer TOUT le contenu du fichier par le CSS ci-dessous.

Le fichier complet est trop long pour être affiché ici. Voir `AMELIORATIONS_MAPS.md` section "PATCH 2" pour le CSS complet.

**Résumé des changements :**
- Tous les sélecteurs sont préfixés avec `#maps-page`
- Ajout de variables CSS locales
- Animations (fadeIn, slideInRight, pulse, spin)
- Styles pour toasts, progress bar, filtres, légende
- Responsive amélioré
- Focus visible pour accessibilité

**Classes ajoutées :**
- `.toast-container`, `.toast`, `.toast-close`
- `.progress-bar`, `.progress-bar-fill`
- `.map-filters`, `.map-filter-toggle`
- `.map-legend`, `.map-legend-item`
- `.client-search-wrapper`, `.client-search-icon`, `.client-search-clear`
- `.selected-clients-header`, `.selected-clients-count`
- `.section-content.collapsed`
- `.spinner`, `.skeleton`

**Media queries :**
- `@media (max-width: 960px)` - Layout responsive
- `@media (max-width: 768px)` - Mobile optimisé

