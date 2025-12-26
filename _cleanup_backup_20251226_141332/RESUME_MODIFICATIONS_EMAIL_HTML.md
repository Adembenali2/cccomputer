# Résumé des Modifications : Email HTML + Logs Fiables

**Date :** 2025-01-XX  
**Version :** 2.0

---

## 📋 MODIFICATIONS APPORTÉES

### 1. `src/Mail/MailerFactory.php`

**Modifications :**
- ✅ Correction `SMTP_DISABLE_VERIFY` avec `filter_var(..., FILTER_VALIDATE_BOOLEAN)`
- ✅ Ajout timeout SMTP configurable via `SMTP_TIMEOUT` (défaut: 15s)

**Code ajouté :**
```php
$disableVerify = filter_var($_ENV['SMTP_DISABLE_VERIFY'] ?? false, FILTER_VALIDATE_BOOLEAN);

$smtpTimeout = (int)($_ENV['SMTP_TIMEOUT'] ?? 15);
if ($smtpTimeout < 1 || $smtpTimeout > 300) {
    $smtpTimeout = 15;
}
$mail->Timeout = $smtpTimeout;
```

---

### 2. `src/Mail/MailerService.php`

**Modifications :**
- ✅ Nouvelle signature `sendEmailWithPdf()` avec support HTML + texte
- ✅ Génération Message-ID réel (RFC 5322)
- ✅ Retour du Message-ID pour traçabilité

**Nouvelle signature :**
```php
public function sendEmailWithPdf(
    string $to,
    string $subject,
    string $textBody,
    ?string $pdfPath = null,
    ?string $pdfFileName = null,
    ?string $htmlBody = null
): string  // Retourne le Message-ID
```

**Fonctionnalités :**
- Si `htmlBody` fourni → `isHTML(true)`, `Body=htmlBody`, `AltBody=textBody`
- Sinon → `isHTML(false)`, `Body=textBody`, `AltBody=textBody`
- Message-ID généré : `<timestamp.random@domain>`
- Domaine configurable via `MAIL_MESSAGE_ID_DOMAIN` (défaut: `cccomputer.fr`)

---

### 3. `src/Mail/templates/invoice_email.html`

**Création :** Template HTML professionnel avec placeholders

**Placeholders supportés :**
- `{{brand_name}}` - Nom de la marque (CC Computer)
- `{{client_name}}` - Nom du client
- `{{invoice_number}}` - Numéro de facture
- `{{invoice_date}}` - Date de facturation
- `{{invoice_total_ttc}}` - Montant TTC
- `{{site_url}}` - URL du site (depuis `APP_URL`)
- `{{legal_name}}` - Nom légal (Camson Group)
- `{{legal_address}}` - Adresse légale
- `{{legal_details}}` - Détails légaux (SIRET, etc.)

**Caractéristiques :**
- Compatible clients email (pas de JS, CSS inline)
- Design moderne (header bleu, card, footer)
- Responsive (mobile-friendly)
- Fallback texte automatique

---

### 4. `src/Services/InvoiceEmailService.php`

**Modifications majeures :**

#### 4.1 Correction cast booléens
- ✅ `AUTO_SEND_INVOICES` : `filter_var(..., FILTER_VALIDATE_BOOLEAN)`
- ✅ `AUTO_SEND_INVOICES_RETRY` : `filter_var(..., FILTER_VALIDATE_BOOLEAN)`

#### 4.2 Nouvelle méthode `buildEmailHtmlBody()`
- ✅ Lit le template `invoice_email.html`
- ✅ Remplace les placeholders avec données facture/client
- ✅ Escape HTML pour sécurité
- ✅ Utilise `APP_URL` pour `site_url`

#### 4.3 Correction gestion transactions
**Pattern implémenté :**

```
A) Transaction courte :
   - SELECT facture FOR UPDATE
   - Vérifications (idempotence, email valide)
   - INSERT email_logs (statut=pending)
   - COMMIT

B) Envoi SMTP HORS transaction
   - Régénération PDF si nécessaire (/tmp)
   - Appel MailerService->sendEmailWithPdf()
   - Récupération Message-ID

C) Transaction courte :
   - Si succès : UPDATE factures + UPDATE email_logs (statut=sent, message_id)
   - Si échec : UPDATE email_logs (statut=failed, error_message)
   - COMMIT
```

**Avantages :**
- ✅ Pas de transaction ouverte pendant SMTP (évite timeouts)
- ✅ `email_logs` toujours cohérent (pas perdu en rollback)
- ✅ Message-ID réel stocké dans `email_logs`

#### 4.4 Nettoyage PDF temporaire
- ✅ Nettoyage après succès
- ✅ Nettoyage après erreur
- ✅ Logs pour traçabilité

---

## 📝 FICHIERS CRÉÉS/MODIFIÉS

### Fichiers modifiés
- ✅ `src/Mail/MailerFactory.php`
- ✅ `src/Mail/MailerService.php`
- ✅ `src/Services/InvoiceEmailService.php`
- ✅ `src/Mail/templates/invoice_email.html` (créé)

### Documentation
- ✅ `VARIABLES_RAILWAY_EMAIL.md` - Documentation complète des variables
- ✅ `CHECKLIST_TESTS_ENVOI_FACTURES.md` - Checklist de tests détaillée
- ✅ `GUIDE_IMPLEMENTATION_ENVOI_AUTOMATIQUE.md` - Mis à jour avec nouvelles variables

---

## 🔧 VARIABLES RAILWAY À CRÉER

**Service : `cccomputer` (PAS MySQL)**

### Nouvelles variables (à ajouter)
```bash
APP_URL=https://cccomputer-production.up.railway.app
MAIL_MESSAGE_ID_DOMAIN=cccomputer.fr
SMTP_TIMEOUT=15
```

### Variables existantes (vérifier)
```bash
AUTO_SEND_INVOICES=true
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr
```

**Voir `VARIABLES_RAILWAY_EMAIL.md` pour la documentation complète.**

---

## ✅ CHECKLIST DE TESTS

### Tests fonctionnels
- [ ] Envoi automatique après génération → Email reçu
- [ ] PDF attaché et valide
- [ ] Email HTML stylé correctement
- [ ] Fallback texte lisible
- [ ] Message-ID présent dans headers email
- [ ] `email_logs.message_id` correspond au Message-ID
- [ ] Idempotence (pas de double envoi)

### Tests de robustesse
- [ ] Email client invalide → Pas d'envoi, log failed
- [ ] PDF introuvable → Régénération dans /tmp
- [ ] Timeout SMTP → Erreur gracieuse, log failed
- [ ] Variables booléennes (`"false"`, `"0"` → false)

### Tests de cohérence
- [ ] `email_logs` cohérent même en cas d'erreur
- [ ] Pas de transaction ouverte pendant SMTP
- [ ] Message-ID unique pour chaque email
- [ ] PDF temporaire nettoyé après envoi/erreur

**Voir `CHECKLIST_TESTS_ENVOI_FACTURES.md` pour la checklist complète.**

---

## 🎯 POINTS CLÉS

### 1. Transactions DB
✅ **Corrigé :** SMTP n'est plus dans une transaction DB
- Transaction courte : Préparation + INSERT email_logs
- Envoi SMTP HORS transaction
- Transaction courte : Mise à jour succès/échec

### 2. Message-ID Réel
✅ **Implémenté :** Message-ID conforme RFC 5322
- Format : `<timestamp.random@domain>`
- Domaine configurable via `MAIL_MESSAGE_ID_DOMAIN`
- Stocké dans `email_logs.message_id`

### 3. Email HTML
✅ **Implémenté :** Template HTML professionnel
- Compatible clients email (Gmail, Outlook, etc.)
- Fallback texte automatique
- Design moderne et responsive

### 4. Logs Fiables
✅ **Corrigé :** `email_logs` toujours cohérent
- Entrée créée AVANT envoi (statut=pending)
- Mise à jour APRÈS envoi (statut=sent/failed)
- Pas de perte en cas de rollback

### 5. Variables Booléennes
✅ **Corrigé :** `filter_var(..., FILTER_VALIDATE_BOOLEAN)`
- `"false"`, `"0"` → `false`
- `"true"`, `"1"` → `true`

---

## 📚 DOCUMENTATION

- **`VARIABLES_RAILWAY_EMAIL.md`** - Toutes les variables Railway
- **`CHECKLIST_TESTS_ENVOI_FACTURES.md`** - Checklist de tests complète
- **`GUIDE_IMPLEMENTATION_ENVOI_AUTOMATIQUE.md`** - Guide d'utilisation

---

**Version :** 2.0  
**Statut :** ✅ Implémenté et prêt pour tests

