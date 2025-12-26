# Checklist de Tests : Envoi de Factures par Email

**Date :** 2025-01-XX  
**Version :** 1.0

---

## Prérequis

- [ ] Migration `email_logs` exécutée
- [ ] Variables Railway configurées (Service `cccomputer`)
- [ ] SMTP testé et fonctionnel (`/test_smtp.php`)

---

## Tests Fonctionnels

### 1. Envoi Automatique après Génération

**Scénario :** Générer une facture avec `AUTO_SEND_INVOICES=true`

**Actions :**
1. Créer une facture via l'interface ou `API/factures_generer.php`
2. Vérifier la réception de l'email

**Vérifications :**
- [ ] Email reçu dans la boîte du client
- [ ] Email contient le PDF en pièce jointe
- [ ] Email HTML s'affiche correctement (clients email modernes)
- [ ] Email texte visible (clients email basiques)
- [ ] `factures.email_envoye = 1` en DB
- [ ] `factures.date_envoi_email` rempli
- [ ] `email_logs` contient une entrée avec `statut = 'sent'`
- [ ] `email_logs.message_id` contient un Message-ID valide (format: `<timestamp.random@domain>`)
- [ ] Logs Railway contiennent `[InvoiceEmailService] ✅ Facture #X envoyée`

**Requête SQL de vérification :**
```sql
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    f.date_envoi_email,
    el.statut,
    el.message_id,
    el.sent_at
FROM factures f
LEFT JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
ORDER BY el.created_at DESC
LIMIT 1;
```

---

### 2. PDF en Pièce Jointe

**Scénario :** Vérifier que le PDF est correctement attaché

**Actions :**
1. Ouvrir l'email reçu
2. Télécharger et ouvrir le PDF

**Vérifications :**
- [ ] PDF présent en pièce jointe
- [ ] Nom du fichier correct (ex: `facture_P202501001_xxx.pdf`)
- [ ] PDF s'ouvre correctement
- [ ] Contenu du PDF correspond à la facture
- [ ] Taille du PDF raisonnable (< 10MB)

---

### 3. Email HTML Stylé

**Scénario :** Vérifier le rendu HTML de l'email

**Actions :**
1. Ouvrir l'email dans un client email moderne (Gmail, Outlook, etc.)
2. Vérifier le rendu visuel

**Vérifications :**
- [ ] Header avec branding (couleur bleue #3b82f6)
- [ ] Nom du client correctement affiché
- [ ] Numéro de facture visible
- [ ] Montant TTC mis en évidence (vert #059669)
- [ ] Date de facturation affichée
- [ ] Footer avec informations légales
- [ ] Avertissement "email automatique" visible
- [ ] Design responsive (mobile-friendly)

**Test sur différents clients :**
- [ ] Gmail (web)
- [ ] Outlook (web)
- [ ] Apple Mail
- [ ] Client mobile (iOS/Android)

---

### 4. Fallback Texte

**Scénario :** Vérifier que le texte est visible si HTML non supporté

**Actions :**
1. Désactiver HTML dans le client email (si possible)
2. Ou consulter la version texte de l'email

**Vérifications :**
- [ ] Version texte lisible
- [ ] Informations essentielles présentes (numéro, montant, date)
- [ ] Pas de balises HTML visibles
- [ ] Formatage correct (lignes, sauts de ligne)

---

### 5. Idempotence (Pas de Double Envoi)

**Scénario :** Vérifier qu'une facture n'est envoyée qu'une seule fois

**Actions :**
1. Générer une facture (premier envoi)
2. Tenter de régénérer/envoyer à nouveau la même facture

**Vérifications :**
- [ ] Premier envoi : Email reçu
- [ ] Deuxième tentative : Pas d'envoi (idempotence)
- [ ] `email_logs` contient 1 seule entrée avec `statut = 'sent'`
- [ ] Logs Railway : `[InvoiceEmailService] Facture #X déjà envoyée`
- [ ] `factures.email_envoye = 1` reste à 1

**Test avec force :**
- [ ] Forcer l'envoi (`force = true`) : Email envoyé même si `email_envoye = 1`
- [ ] `email_logs` contient 2 entrées (1 sent, 1 sent)

---

### 6. Cohérence email_logs

**Scénario :** Vérifier que les logs sont cohérents même en cas d'erreur

**Actions :**
1. Simuler une erreur SMTP (mauvais credentials, timeout, etc.)
2. Vérifier les logs en DB

**Vérifications :**
- [ ] `email_logs` contient une entrée même en cas d'erreur
- [ ] `email_logs.statut = 'failed'` si échec
- [ ] `email_logs.error_message` contient le message d'erreur
- [ ] `email_logs.sent_at` est NULL si échec
- [ ] `factures.email_envoye = 0` si échec (pas mis à jour)
- [ ] Pas de rollback qui supprime l'entrée `email_logs`

**Requête SQL de vérification :**
```sql
SELECT 
    id,
    facture_id,
    statut,
    message_id,
    sent_at,
    error_message,
    created_at
FROM email_logs
WHERE facture_id = :facture_id
ORDER BY created_at DESC;
```

---

### 7. Régénération PDF si Introuvable

**Scénario :** Vérifier la régénération automatique du PDF

**Actions :**
1. Supprimer manuellement le PDF de `uploads/factures/`
2. Générer/envoyer la facture

**Vérifications :**
- [ ] PDF régénéré automatiquement dans `/tmp`
- [ ] Email envoyé avec le PDF régénéré
- [ ] PDF temporaire supprimé après envoi
- [ ] Logs : `[InvoiceEmailService] PDF régénéré dans /tmp`

---

### 8. Message-ID Réel

**Scénario :** Vérifier que le Message-ID est généré correctement

**Actions :**
1. Envoyer une facture
2. Vérifier le Message-ID dans les headers de l'email

**Vérifications :**
- [ ] Message-ID présent dans les headers de l'email
- [ ] Format correct : `<timestamp.random@domain>`
- [ ] Message-ID unique (pas de doublon)
- [ ] `email_logs.message_id` correspond au Message-ID de l'email
- [ ] Domaine configuré via `MAIL_MESSAGE_ID_DOMAIN`

**Exemple de Message-ID valide :**
```
<1704067200.a1b2c3d4e5f6g7h8@cccomputer.fr>
```

---

## Tests de Robustesse

### 9. Email Client Invalide

**Scénario :** Facture avec email client invalide

**Actions :**
1. Créer une facture avec email client invalide (ex: `invalid-email`)
2. Tenter l'envoi automatique

**Vérifications :**
- [ ] Pas d'envoi d'email
- [ ] `email_logs` contient une entrée avec `statut = 'failed'` (si log créé)
- [ ] Logs : `[InvoiceEmailService] Email client invalide`
- [ ] Facture générée normalement (non bloquant)

---

### 10. Timeout SMTP

**Scénario :** Simuler un timeout SMTP

**Actions :**
1. Configurer `SMTP_TIMEOUT=1` (1 seconde, très court)
2. Utiliser un serveur SMTP lent ou inaccessible
3. Tenter l'envoi

**Vérifications :**
- [ ] Timeout respecté (erreur après 1 seconde)
- [ ] `email_logs.statut = 'failed'`
- [ ] `email_logs.error_message` contient info timeout
- [ ] Logs Railway : Erreur timeout SMTP

---

### 11. Variables Booléennes

**Scénario :** Tester les différentes valeurs booléennes

**Actions :**
1. Tester `AUTO_SEND_INVOICES=false` → Pas d'envoi
2. Tester `AUTO_SEND_INVOICES=0` → Pas d'envoi
3. Tester `AUTO_SEND_INVOICES=true` → Envoi
4. Tester `AUTO_SEND_INVOICES=1` → Envoi

**Vérifications :**
- [ ] `"false"` → Pas d'envoi automatique
- [ ] `"0"` → Pas d'envoi automatique
- [ ] `"true"` → Envoi automatique
- [ ] `"1"` → Envoi automatique
- [ ] Logs : `[InvoiceEmailService] Envoi automatique désactivé` si false

---

### 12. Transaction DB (Pas de SMTP dans Transaction)

**Scénario :** Vérifier que SMTP n'est pas dans une transaction DB

**Actions :**
1. Activer les logs de transaction MySQL
2. Envoyer une facture
3. Analyser les logs

**Vérifications :**
- [ ] Transaction courte : SELECT + INSERT email_logs + COMMIT
- [ ] Envoi SMTP HORS transaction
- [ ] Transaction courte : UPDATE factures + UPDATE email_logs + COMMIT
- [ ] Pas de transaction ouverte pendant l'envoi SMTP

**Requête SQL de vérification :**
```sql
-- Vérifier que les entrées email_logs existent même si facture non mise à jour
SELECT 
    el.id,
    el.facture_id,
    el.statut,
    f.email_envoye,
    CASE 
        WHEN el.statut = 'sent' AND f.email_envoye = 1 THEN 'OK'
        WHEN el.statut = 'failed' AND f.email_envoye = 0 THEN 'OK'
        ELSE 'INCOHERENT'
    END as coherence
FROM email_logs el
LEFT JOIN factures f ON el.facture_id = f.id
WHERE el.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
ORDER BY el.created_at DESC;
```

---

## Tests de Performance

### 13. Envoi Multiple

**Scénario :** Envoyer plusieurs factures en succession

**Actions :**
1. Générer 5 factures rapidement
2. Vérifier que tous les emails sont envoyés

**Vérifications :**
- [ ] Tous les emails reçus
- [ ] Pas de timeout
- [ ] `email_logs` contient 5 entrées avec `statut = 'sent'`
- [ ] Message-ID uniques pour chaque email

---

## Tests Production (Railway)

### 14. Configuration Railway

**Vérifications :**
- [ ] Variables définies sur Service `cccomputer` (PAS MySQL)
- [ ] `APP_URL` correspond à l'URL Railway
- [ ] `MAIL_MESSAGE_ID_DOMAIN` défini (ou utilise défaut)
- [ ] `SMTP_TIMEOUT` défini si nécessaire
- [ ] Service redéployé après modification variables

---

### 15. Logs Railway

**Vérifications :**
- [ ] Logs `[InvoiceEmailService]` visibles dans Railway Dashboard
- [ ] Logs `[MAIL]` pour détails SMTP
- [ ] Pas d'erreurs PHP fatales
- [ ] Pas de timeouts Railway (60s max)

---

## Résumé des Vérifications

### Base de Données

- [ ] `factures.email_envoye = 1` si succès
- [ ] `factures.date_envoi_email` rempli si succès
- [ ] `email_logs` contient une entrée pour chaque tentative
- [ ] `email_logs.message_id` valide si succès
- [ ] `email_logs.statut` cohérent (sent/failed)
- [ ] Pas de perte d'entrée `email_logs` en cas d'erreur

### Email

- [ ] Email reçu
- [ ] PDF attaché et valide
- [ ] HTML stylé correctement
- [ ] Texte lisible (fallback)
- [ ] Message-ID présent dans headers

### Logs

- [ ] Logs Railway clairs
- [ ] Message-ID loggé
- [ ] Erreurs détaillées si échec

---

**Version :** 1.0  
**Statut :** ✅ Checklist complète

