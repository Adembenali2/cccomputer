# Guide d'Implémentation : Envoi Automatique de Factures

**Version :** 1.0  
**Date :** 2025-01-XX

---

## 📋 RÉSUMÉ

Ce guide décrit comment activer et utiliser le système d'envoi automatique de factures par email, implémenté pour le projet CC Computer déployé sur Railway.

---

## 🚀 ÉTAPES D'INSTALLATION

### 1. Exécuter la migration SQL

**En local (développement) :**
```bash
php sql/run_migration_email_logs.php
```

**En production (Railway) :**
- Option A : Via Railway Shell
  ```bash
  cd /var/www/html  # ou /app selon votre config
  php sql/run_migration_email_logs.php
  ```

- Option B : Via MySQL directement
  - Railway Dashboard → MySQL Service → Connect
  - Exécuter le contenu de `sql/migrations/create_email_logs_table.sql`

### 2. Configurer les variables Railway

**Service : `cccomputer` (PAS MySQL)**

Dans Railway Dashboard → Service `cccomputer` → Variables :

```bash
# Activation envoi automatique
AUTO_SEND_INVOICES=true

# Configuration SMTP (déjà existantes normalement)
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr

# Options avancées (optionnelles)
AUTO_SEND_INVOICES_RETRY=false  # Désactiver retry automatique
AUTO_SEND_INVOICES_DELAY=0      # Délai en secondes avant envoi (0 = immédiat)

# Configuration Timeout SMTP
SMTP_TIMEOUT=15                 # Timeout SMTP en secondes (défaut: 15)

# Configuration Message-ID
MAIL_MESSAGE_ID_DOMAIN=cccomputer.fr  # Domaine pour Message-ID (défaut: cccomputer.fr)

# Configuration Application
APP_URL=https://cccomputer-production.up.railway.app  # URL de base pour liens emails
```

**⚠️ IMPORTANT :** 
- Toutes les variables doivent être définies sur le **Service Web `cccomputer`** (PAS MySQL)
- Après ajout/modification de variables, redéployer le service
- Voir `VARIABLES_RAILWAY_EMAIL.md` pour la documentation complète des variables

### 3. Vérifier la configuration

**Test SMTP :**
```bash
curl -X POST https://votre-domaine.com/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"VOTRE_SMTP_TEST_TOKEN","to":"test@example.com"}'
```

**Vérifier les logs :**
- Railway Dashboard → Service `cccomputer` → Logs
- Rechercher `[InvoiceEmailService]` pour voir les envois automatiques

---

## 📝 UTILISATION

### Envoi automatique après génération

**Comportement :**
- Quand une facture est générée via `API/factures_generer.php`
- Si `AUTO_SEND_INVOICES=true` ET `email_envoye = 0`
- L'email est envoyé automatiquement au client

**Réponse API :**
```json
{
  "ok": true,
  "facture_id": 123,
  "numero": "P202501001",
  "pdf_url": "/uploads/factures/2025/facture_P202501001_20250101120000.pdf",
  "email_sent": true,
  "message": "Facture générée et envoyée par email"
}
```

### Envoi automatique après validation admin

**Comportement :**
- Quand le statut d'une facture passe à `envoyee` via `API/factures_update_statut.php`
- Si `email_envoye = 0`
- L'email est envoyé automatiquement (même si `AUTO_SEND_INVOICES=false`)

**Réponse API :**
```json
{
  "ok": true,
  "facture_id": 123,
  "statut": "envoyee",
  "email_sent": true,
  "message": "Statut mis à jour et facture envoyée par email"
}
```

### Envoi manuel (déjà existant)

**Endpoint :** `POST /API/factures_envoyer_email.php`

**Body :**
```json
{
  "facture_id": 123,
  "email": "client@example.com",
  "sujet": "Facture P202501001 - CC Computer",
  "message": "Message personnalisé (optionnel)"
}
```

---

## 🔍 TRAÇABILITÉ

### Table `email_logs`

Tous les envois sont journalisés dans la table `email_logs` :

```sql
SELECT * FROM email_logs 
WHERE facture_id = 123 
ORDER BY created_at DESC;
```

**Colonnes importantes :**
- `statut` : `pending`, `sent`, `failed`
- `message_id` : ID retourné par SMTP (pour traçabilité)
- `error_message` : Message d'erreur si échec
- `sent_at` : Date d'envoi effectif

### Logs serveur

Rechercher dans les logs Railway :
- `[InvoiceEmailService]` : Logs du service d'envoi
- `[MAIL]` : Logs de MailerService
- `[SMTP_TEST]` : Logs de test SMTP

---

## ⚙️ CONFIGURATION AVANCÉE

### Désactiver l'envoi automatique

```bash
AUTO_SEND_INVOICES=false
```

L'envoi manuel reste disponible.

### Activer le retry automatique

```bash
AUTO_SEND_INVOICES_RETRY=true
```

⚠️ **Non implémenté actuellement** - À développer si nécessaire.

### Ajouter un délai avant envoi

```bash
AUTO_SEND_INVOICES_DELAY=5  # 5 secondes
```

Utile pour laisser le temps au PDF de se finaliser.

---

## 🐛 DÉPANNAGE

### Problème : Email non envoyé

**Vérifications :**
1. ✅ `AUTO_SEND_INVOICES=true` dans Railway
2. ✅ `SMTP_ENABLED=true` et credentials valides
3. ✅ Email client valide dans table `clients`
4. ✅ PDF généré (`pdf_genere = 1`)
5. ✅ Logs Railway pour erreurs SMTP

**Logs à consulter :**
```bash
# Railway Dashboard → Logs → Rechercher :
[InvoiceEmailService] ❌ Erreur
[MAIL] Erreur
```

### Problème : Double envoi

**Cause :** Idempotence non respectée

**Solution :** Vérifier que `email_envoye = 1` après envoi :
```sql
SELECT id, numero, email_envoye, date_envoi_email 
FROM factures 
WHERE id = 123;
```

### Problème : PDF introuvable

**Cause :** Stockage éphémère Railway

**Solution :** Déjà géré automatiquement - Le PDF est régénéré dans `/tmp` si introuvable.

**Vérifier les logs :**
```
[InvoiceEmailService] PDF régénéré dans /tmp: /tmp/facture_xxx.pdf
```

---

## 📊 MONITORING

### Statistiques d'envoi

```sql
-- Succès vs échecs
SELECT 
    statut,
    COUNT(*) as count
FROM email_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY statut;

-- Factures non envoyées
SELECT 
    f.id,
    f.numero,
    f.date_facture,
    c.email as client_email
FROM factures f
LEFT JOIN clients c ON f.id_client = c.id
WHERE f.pdf_genere = 1 
  AND f.email_envoye = 0
  AND f.statut != 'annulee'
ORDER BY f.date_facture DESC;
```

---

## ✅ CHECKLIST DE TESTS

### Tests développement

- [ ] Migration `email_logs` exécutée
- [ ] Variable `AUTO_SEND_INVOICES=true` dans `.env` local
- [ ] Générer une facture → Vérifier envoi automatique
- [ ] Vérifier `email_logs` contient l'entrée
- [ ] Vérifier `factures.email_envoye = 1`
- [ ] Tester idempotence (double génération → 1 seul envoi)
- [ ] Tester avec email invalide → Vérifier logs d'erreur
- [ ] Tester avec PDF manquant → Vérifier régénération

### Tests production (Railway)

- [ ] Variables Railway configurées (Service `cccomputer`)
- [ ] `SMTP_ENABLED=true` et credentials valides
- [ ] `AUTO_SEND_INVOICES=true`
- [ ] Générer facture test → Vérifier réception email
- [ ] Vérifier logs Railway (erreurs SMTP)
- [ ] Vérifier `email_logs` en DB
- [ ] Tester validation admin → Vérifier envoi automatique

---

## 🔐 SÉCURITÉ

### Idempotence

✅ **Implémenté :**
- Vérification `email_envoye = 1` avant envoi
- Transaction DB avec `FOR UPDATE` (lock)
- Logs pour traçabilité

### Validation email

✅ **Implémenté :**
- `filter_var($email, FILTER_VALIDATE_EMAIL)`
- Vérification email client non vide

### Gestion d'erreurs

✅ **Implémenté :**
- Erreurs non bloquantes (génération facture continue même si envoi échoue)
- Logs détaillés pour debugging
- Table `email_logs` pour traçabilité

---

## 📚 FICHIERS CRÉÉS/MODIFIÉS

### Nouveaux fichiers

- ✅ `sql/migrations/create_email_logs_table.sql`
- ✅ `sql/run_migration_email_logs.php`
- ✅ `src/Services/InvoiceEmailService.php`
- ✅ `ANALYSE_ENVOI_AUTOMATIQUE_FACTURES.md`
- ✅ `GUIDE_IMPLEMENTATION_ENVOI_AUTOMATIQUE.md`

### Fichiers modifiés

- ✅ `config/app.php` - Ajout `auto_send_invoices`
- ✅ `API/factures_generer.php` - Envoi automatique après génération
- ✅ `API/factures_update_statut.php` - Envoi automatique après validation

---

## 🎯 PROCHAINES AMÉLIORATIONS (OPTIONNEL)

1. **Template email HTML** : Créer `src/Mail/templates/invoice_email.html`
2. **Retry automatique** : Implémenter retry pour échecs temporaires
3. **Queue asynchrone** : Envoi en arrière-plan pour éviter timeouts
4. **Webhook notifications** : Notifier admin en cas d'échec
5. **Statistiques dashboard** : Graphiques d'envoi dans l'interface admin

---

**Version :** 1.0  
**Statut :** ✅ Implémenté et prêt pour tests

