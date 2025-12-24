# Améliorations proposées pour la page Messagerie

## 🔴 **Améliorations critiques (à corriger rapidement)**

### 1. **Problème avec `apiClient` non défini**
- **Problème** : Le code utilise `apiClient.json()` ligne 251, mais `apiClient` n'est pas inclus dans la page
- **Solution** : Ajouter `<script src="/assets/js/api.js"></script>` dans le `<head>` ou utiliser `fetch()` directement
- **Impact** : Erreur JavaScript qui empêche les mentions de fonctionner

### 2. **Gestion d'erreurs silencieuses**
- **Problème** : Plusieurs erreurs sont capturées mais pas affichées à l'utilisateur
- **Solution** : Ajouter un système de notifications visuelles (toast/alert) pour informer l'utilisateur
- **Impact** : L'utilisateur ne sait pas quand quelque chose ne fonctionne pas

### 3. **Pas d'indicateur de statut de connexion**
- **Problème** : Aucune indication si la connexion est perdue ou si les messages ne se chargent plus
- **Solution** : Ajouter un indicateur de statut (en ligne/hors ligne) et détecter les erreurs réseau
- **Impact** : Mauvaise expérience utilisateur en cas de problème réseau

---

## 🟡 **Améliorations importantes (UX/Performance)**

### 4. **Upload d'images non implémenté dans l'interface**
- **Problème** : L'API `chatroom_upload_image.php` existe mais n'est pas utilisée dans l'interface
- **Solution** : Ajouter un bouton d'upload d'image avec aperçu avant envoi
- **Impact** : Fonctionnalité manquante alors que le backend est prêt

### 5. **Pas de feedback visuel lors de l'envoi**
- **Problème** : Aucun indicateur visuel quand un message est en cours d'envoi
- **Solution** : Afficher un message "Envoi en cours..." ou un spinner dans la bulle de message
- **Impact** : L'utilisateur ne sait pas si son message est parti

### 6. **Polling toutes les 2 secondes (performance)**
- **Problème** : Requêtes HTTP toutes les 2 secondes même si aucun nouveau message
- **Solution** : 
  - Augmenter l'intervalle progressivement si pas de nouveaux messages (backoff exponentiel)
  - Utiliser Server-Sent Events (SSE) ou WebSockets pour du vrai temps réel
- **Impact** : Charge serveur inutile et consommation de bande passante

### 7. **Pas de chargement des anciens messages (pagination)**
- **Problème** : Impossible de voir les messages plus anciens que les 100 derniers
- **Solution** : Implémenter un scroll infini ou un bouton "Charger plus de messages"
- **Impact** : Limite l'historique visible

### 8. **Notifications non visibles**
- **Problème** : Le système de notifications existe (`chatroom_notifications`) mais n'est pas affiché
- **Solution** : 
  - Afficher un compteur de notifications non lues dans le header
  - Marquer les messages comme lus quand ils sont affichés
- **Impact** : Les utilisateurs ne savent pas qu'ils ont été mentionnés

---

## 🟢 **Améliorations optionnelles (nice to have)**

### 9. **Édition/Suppression de messages**
- **Problème** : Impossible d'éditer ou supprimer ses propres messages
- **Solution** : Ajouter un menu contextuel (clic droit ou bouton) sur les messages de l'utilisateur
- **Impact** : Fonctionnalité standard dans les messageries modernes

### 10. **Formatage de texte (Markdown/BBCode)**
- **Problème** : Pas de support pour le gras, italique, liens, etc.
- **Solution** : Parser le Markdown ou BBCode dans les messages
- **Impact** : Messages plus expressifs

### 11. **Recherche dans les messages**
- **Problème** : Impossible de rechercher dans l'historique
- **Solution** : Ajouter une barre de recherche avec filtrage en temps réel
- **Impact** : Utile pour retrouver des informations

### 12. **Indicateur "en train d'écrire" (typing indicator)**
- **Problème** : Pas d'indication quand quelqu'un est en train d'écrire
- **Solution** : Envoyer un événement "typing" après X secondes d'inactivité dans le textarea
- **Impact** : Meilleure expérience temps réel

### 13. **Regroupement des messages par auteur**
- **Problème** : Chaque message affiche le nom de l'auteur même si c'est le même
- **Solution** : Regrouper les messages consécutifs du même auteur et n'afficher le nom qu'une fois
- **Impact** : Interface plus propre et moins répétitive

### 14. **Séparateurs de date**
- **Problème** : Pas de séparation visuelle entre les messages de différents jours
- **Solution** : Ajouter des séparateurs "Aujourd'hui", "Hier", "15 janvier 2024", etc.
- **Impact** : Meilleure navigation dans l'historique

### 15. **Accessibilité (ARIA)**
- **Problème** : Manque d'attributs ARIA pour les lecteurs d'écran
- **Solution** : Ajouter `role`, `aria-label`, `aria-live` pour les nouvelles messages
- **Impact** : Accessibilité améliorée

### 16. **Copier le texte d'un message**
- **Problème** : Impossible de copier facilement le texte d'un message
- **Solution** : Ajouter un bouton "Copier" sur chaque message ou sélection de texte améliorée
- **Impact** : Fonctionnalité pratique

### 17. **Réactions/Emojis**
- **Problème** : Pas de système de réactions aux messages
- **Solution** : Permettre d'ajouter des emojis comme réactions aux messages
- **Impact** : Interaction plus riche

### 18. **Optimisation du nettoyage automatique**
- **Problème** : Le nettoyage des messages de 24h se fait à chaque chargement de page
- **Solution** : Déplacer le nettoyage dans un cron job ou le faire moins fréquemment
- **Impact** : Performance améliorée

---

## 📊 **Priorisation recommandée**

### Phase 1 (Critique - à faire immédiatement)
1. ✅ Corriger le problème `apiClient`
2. ✅ Ajouter un système de notifications d'erreur
3. ✅ Indicateur de statut de connexion

### Phase 2 (Important - cette semaine)
4. ✅ Implémenter l'upload d'images
5. ✅ Feedback visuel lors de l'envoi
6. ✅ Optimiser le polling (backoff exponentiel)
7. ✅ Afficher les notifications non lues

### Phase 3 (Optionnel - prochaines semaines)
8. Pagination des anciens messages
9. Édition/Suppression de messages
10. Formatage de texte
11. Recherche dans les messages
12. Autres améliorations UX

---

## 💡 **Suggestions techniques**

### Pour le polling optimisé :
```javascript
let refreshInterval = 2000; // 2 secondes de base
let consecutiveEmptyResponses = 0;

function loadMessages() {
    // ... chargement ...
    if (messages.length === 0) {
        consecutiveEmptyResponses++;
        // Augmenter progressivement l'intervalle (max 30 secondes)
        refreshInterval = Math.min(2000 * Math.pow(1.5, consecutiveEmptyResponses), 30000);
    } else {
        consecutiveEmptyResponses = 0;
        refreshInterval = 2000; // Reset à 2 secondes
    }
}
```

### Pour les notifications :
- Utiliser l'API `chatroom_get_notifications.php` pour récupérer les notifications non lues
- Afficher un badge dans le header avec le nombre de notifications
- Marquer comme lues quand l'utilisateur consulte la messagerie

### Pour l'upload d'images :
- Ajouter un bouton avec icône d'image à côté du bouton d'envoi
- Afficher un aperçu de l'image avant l'envoi
- Utiliser `FormData` pour l'upload multipart
- Afficher l'image dans le message avec possibilité de zoom

