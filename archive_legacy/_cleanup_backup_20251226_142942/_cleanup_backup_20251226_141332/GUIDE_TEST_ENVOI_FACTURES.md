# Guide de Test : Envoi de Factures par Email

**Date :** 2025-01-XX  
**Version :** 1.0  
**Environnement :** Railway + SMTP Brevo + Claim Atomique

---

## 🎯 Objectif

Tester l'envoi d'emails de factures de manière sûre, sans risquer d'envoyer plusieurs emails au client réel.

---

## 1. PROCÉDURE DE TEST RECOMMANDÉE

### ✅ Test Recommandé : Avec Votre Email Personnel

**Principe :** Utiliser votre propre email comme destinataire pour tester sans impact client.

**Étapes :**

1. **Préparer une facture de test**
   - Créer/modifier un client avec VOTRE email
   - Générer une facture pour ce client
   - Vérifier que `email_envoye = 0`

2. **Tester l'envoi**
   - Utiliser une des 3 méthodes (A, B ou C ci-dessous)
   - Vérifier la réception dans VOTRE boîte email

3. **Vérifier la cohérence**
   - Requêtes SQL pour vérifier `email_logs` et `factures`
   - Vérifier que le claim atomique fonctionne

4. **Nettoyer (optionnel)**
   - Remettre l'email client original
   - Ou garder votre email pour tests futurs

---

## 2. TROIS MÉTHODES DE TEST

### Méthode A : Test via Génération de Facture

**Quand utiliser :** Tester l'envoi automatique après génération.

#### Action UI/API

**Via Interface :**
1. Aller sur la page de génération de facture
2. Sélectionner un client (avec VOTRE email)
3. Remplir les lignes de facture
4. Cliquer sur "Générer la facture"

**Via API directe :**
```bash
POST /API/factures_generer.php
Content-Type: application/json

{
  "factureClient": 123,
  "factureDate": "2025-01-15",
  "factureType": "Consommation",
  "lignes": [
    {
      "description": "Test envoi email",
      "type": "Service",
      "quantite": 1,
      "prix_unitaire": 10.00,
      "total_ht": 10.00
    }
  ]
}
```

#### État Avant Test

```sql
-- Vérifier l'état initial
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    f.date_envoi_email,
    c.email as client_email
FROM factures f
LEFT JOIN clients c ON f.id_client = c.id
WHERE f.id = :facture_id;
```

**Résultat attendu :**
- `email_envoye = 0` (ou NULL)
- `date_envoi_email = NULL`
- `client_email = votre-email@example.com`

#### État Après Test (Succès)

```sql
-- Vérifier l'état après envoi
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    f.date_envoi_email,
    el.statut as log_statut,
    el.message_id,
    el.sent_at,
    el.error_message
FROM factures f
LEFT JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
ORDER BY el.created_at DESC
LIMIT 1;
```

**Résultat attendu :**
- `email_envoye = 1` ✅
- `date_envoi_email = 2025-01-15 14:30:00` (timestamp récent) ✅
- `log_statut = 'sent'` ✅
- `message_id = '<timestamp.random@cccomputer.fr>'` ✅
- `sent_at = 2025-01-15 14:30:00` ✅

#### Comment Éviter Double Envoi

**Protection automatique :**
- Le claim atomique empêche le double envoi
- Si `AUTO_SEND_INVOICES=true` et facture déjà envoyée (`email_envoye=1`), pas d'envoi automatique
- Si facture en cours (`email_envoye=2`), refus avec message "déjà en cours"

**Test de double envoi :**
```sql
-- Tenter de générer la même facture 2 fois rapidement
-- Résultat : 1 seul email envoyé (claim atomique)
```

---

### Méthode B : Test via Changement de Statut (envoyee)

**Quand utiliser :** Tester l'envoi automatique après validation admin.

#### Action UI/API

**Via Interface :**
1. Aller sur la liste des factures
2. Sélectionner une facture avec `email_envoye = 0`
3. Changer le statut de `brouillon` → `envoyee`
4. Sauvegarder

**Via API directe :**
```bash
POST /API/factures_update_statut.php
Content-Type: application/json

{
  "facture_id": 123,
  "statut": "envoyee"
}
```

#### État Avant Test

```sql
-- Vérifier l'état initial
SELECT 
    f.id,
    f.numero,
    f.statut,
    f.email_envoye,
    f.date_envoi_email
FROM factures f
WHERE f.id = :facture_id;
```

**Résultat attendu :**
- `statut = 'brouillon'` (ou autre, pas 'envoyee')
- `email_envoye = 0`
- `date_envoi_email = NULL`

#### État Après Test (Succès)

**Même requête que Méthode A**

**Résultat attendu :**
- `statut = 'envoyee'` ✅
- `email_envoye = 1` ✅
- `date_envoi_email` rempli ✅
- `email_logs.statut = 'sent'` ✅

#### Comment Éviter Double Envoi

**Protection automatique :**
- Le code vérifie `email_envoye = 0` avant envoi
- Si `email_envoye = 1`, pas d'envoi (déjà envoyé)
- Si `email_envoye = 2`, refus avec message "déjà en cours"

**Note :** Le mode `force=true` est activé automatiquement dans `factures_update_statut.php` si statut passe à `envoyee`, MAIS il refuse si `email_envoye=2` pour éviter double envoi.

---

### Méthode C : Test via Mode force=true (Renvoi Manuel)

**Quand utiliser :** Tester un renvoi manuel ou forcer l'envoi d'une facture déjà envoyée.

#### Action UI/API

**Via Interface :**
- Utiliser un bouton "Renvoyer par email" (si disponible)
- Ou utiliser l'endpoint manuel

**Via API directe :**
```bash
POST /API/factures_envoyer_email.php
Content-Type: application/json

{
  "facture_id": 123,
  "email": "votre-email@example.com",
  "sujet": "Facture P202501001 - CC Computer",
  "message": "Message personnalisé (optionnel)"
}
```

**Via Code PHP (pour test avancé) :**
```php
require_once __DIR__ . '/vendor/autoload.php';
$config = require __DIR__ . '/config/app.php';
$pdo = getPdo();

$invoiceEmailService = new \App\Services\InvoiceEmailService($pdo, $config);
$result = $invoiceEmailService->sendInvoiceAfterGeneration(123, true); // force=true

var_dump($result);
```

#### État Avant Test

**Même requête que Méthode A**

**Cas possibles :**
- `email_envoye = 0` → Envoi normal
- `email_envoye = 1` → Envoi forcé (retry)
- `email_envoye = 2` → **REFUSÉ** (pour éviter double envoi)

#### État Après Test (Succès)

**Même requête que Méthode A**

#### Comment Éviter Double Envoi

**Protection automatique :**
- Si `email_envoye = 2` → **REFUSÉ** même en mode force
- Message : "Facture déjà en cours d'envoi. Mode force refusé pour éviter double envoi."
- Si `email_envoye = 1` → Envoi autorisé (retry)

**Test de protection :**
```sql
-- 1. Mettre une facture en cours
UPDATE factures SET email_envoye = 2 WHERE id = 123;

-- 2. Tenter envoi force=true
-- Résultat : REFUSÉ avec message explicite
```

---

## 3. REQUÊTES SQL EXACTES

### 3.1 Vérifier si l'Email est Parti

```sql
-- Requête complète pour vérifier l'envoi
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    f.email_envoye,
    f.date_envoi_email,
    c.email AS client_email,
    el.id AS log_id,
    el.statut AS log_statut,
    el.message_id,
    el.sent_at,
    el.error_message,
    TIMESTAMPDIFF(SECOND, el.sent_at, NOW()) AS seconds_ago
FROM factures f
LEFT JOIN clients c ON f.id_client = c.id
LEFT JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
ORDER BY el.created_at DESC
LIMIT 1;
```

**Interprétation :**
- ✅ `email_envoye = 1` ET `log_statut = 'sent'` ET `sent_at IS NOT NULL` → **Email parti avec succès**
- ❌ `email_envoye = 0` ET `log_statut = 'failed'` → **Email non parti (échec)**
- ⚠️ `email_envoye = 2` ET `log_statut = 'pending'` → **En cours ou stuck**

---

### 3.2 Vérifier une Erreur SMTP

```sql
-- Vérifier les erreurs SMTP
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    f.email_envoye,
    el.statut AS log_statut,
    el.error_message,
    el.created_at AS log_created_at,
    TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) AS minutes_ago
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
  AND el.statut = 'failed'
ORDER BY el.created_at DESC
LIMIT 1;
```

**Exemples d'erreurs SMTP :**
- `"SMTP connect() failed"` → Problème connexion SMTP
- `"Invalid address"` → Email client invalide
- `"Authentication failed"` → Credentials SMTP incorrects
- `"Timeout"` → Timeout SMTP (vérifier `SMTP_TIMEOUT`)

---

### 3.3 Vérifier si une Facture est Bloquée (email_envoye=2)

```sql
-- Vérifier si facture bloquée (stuck)
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    f.email_envoye,
    el.id AS log_id,
    el.statut AS log_statut,
    el.created_at AS log_created_at,
    TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) AS minutes_stuck,
    CASE 
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) >= 15 THEN 'STUCK (>15 min)'
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) < 15 THEN 'En cours (normal)'
        ELSE 'Pas de log'
    END AS statut_detaille
FROM factures f
LEFT JOIN email_logs el ON el.facture_id = f.id
    AND el.statut = 'pending'
    AND el.id = (
        SELECT id FROM email_logs 
        WHERE facture_id = f.id 
        ORDER BY created_at DESC 
        LIMIT 1
    )
WHERE f.id = :facture_id
  AND f.email_envoye = 2;
```

**Interprétation :**
- `email_envoye = 2` ET `minutes_stuck >= 15` → **STUCK** (sera réinitialisé automatiquement)
- `email_envoye = 2` ET `minutes_stuck < 15` → **En cours normal** (attendre)
- `email_envoye = 2` ET `log_id IS NULL` → **Pas de log** (anormal, vérifier)

---

### 3.4 Vérifier le Dernier Message-ID

```sql
-- Récupérer le dernier Message-ID envoyé
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    el.message_id,
    el.sent_at,
    el.destinataire,
    el.sujet
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
  AND el.statut = 'sent'
  AND el.message_id IS NOT NULL
ORDER BY el.sent_at DESC
LIMIT 1;
```

**Format Message-ID attendu :**
```
<1704067200.a1b2c3d4e5f6g7h8@cccomputer.fr>
```

**Vérification dans l'email reçu :**
- Ouvrir l'email reçu
- Afficher les headers complets
- Chercher `Message-ID: <...>`
- Comparer avec `email_logs.message_id`

---

## 4. TESTER SANS ENVOYER AU CLIENT RÉEL

### Méthode 1 : Changer Temporairement l'Email du Client

**Étapes :**

1. **Sauvegarder l'email original**
```sql
-- Sauvegarder l'email original
SELECT id, email FROM clients WHERE id = :client_id;
-- Noter l'email original
```

2. **Changer temporairement pour votre email**
```sql
-- Changer l'email du client
UPDATE clients 
SET email = 'votre-email@example.com'
WHERE id = :client_id;
```

3. **Tester l'envoi**
- Utiliser Méthode A, B ou C
- Vérifier la réception dans VOTRE boîte

4. **Remettre l'email original**
```sql
-- Remettre l'email original
UPDATE clients 
SET email = 'email-original@client.com'
WHERE id = :client_id;
```

**Avantage :** Test réaliste avec vraie facture

**Inconvénient :** Nécessite modification DB

---

### Méthode 2 : Utiliser une Adresse de Test

**Étapes :**

1. **Créer un client de test**
```sql
-- Créer un client de test
INSERT INTO clients (
    numero_client, raison_sociale, adresse, code_postal, ville, 
    siret, telephone1, email, offre
) VALUES (
    'TEST-001', 'Client Test Email', '1 rue Test', '75001', 'Paris',
    '12345678901234', '0100000000', 'votre-email@example.com', 'packbronze'
);
```

2. **Générer une facture pour ce client**
- Utiliser Méthode A
- Facture envoyée à votre email

3. **Nettoyer (optionnel)**
```sql
-- Supprimer le client de test (cascade supprime les factures)
DELETE FROM clients WHERE numero_client = 'TEST-001';
```

**Avantage :** Pas de modification de données réelles

**Inconvénient :** Client de test visible dans l'interface

---

### Méthode 3 : Utiliser force=true Intelligemment

**Quand utiliser :** Pour tester un renvoi sans risquer d'envoyer au client.

**Étapes :**

1. **Vérifier que la facture est déjà envoyée**
```sql
SELECT id, email_envoye, date_envoi_email 
FROM factures 
WHERE id = :facture_id;
-- email_envoye = 1
```

2. **Modifier temporairement l'email client**
```sql
UPDATE clients 
SET email = 'votre-email@example.com'
WHERE id = (SELECT id_client FROM factures WHERE id = :facture_id);
```

3. **Utiliser force=true pour renvoyer**
- Via API ou code PHP
- Email envoyé à VOTRE adresse

4. **Remettre l'email original**
```sql
UPDATE clients 
SET email = 'email-original@client.com'
WHERE id = (SELECT id_client FROM factures WHERE id = :facture_id);
```

**Avantage :** Test de renvoi réaliste

**Inconvénient :** Nécessite modification DB

---

## 5. CHECKLIST DE VALIDATION FINALE

### ✅ Email Reçu

- [ ] Email reçu dans la boîte de réception (pas spam)
- [ ] Expéditeur : `facturemail@cccomputer.fr` (ou configuré)
- [ ] Sujet : `Facture P202501001 - CC Computer`
- [ ] Date de réception correspond à `email_logs.sent_at`

**Vérification SQL :**
```sql
SELECT sent_at FROM email_logs WHERE facture_id = :facture_id AND statut = 'sent';
-- Comparer avec la date de réception de l'email
```

---

### ✅ PDF Joint

- [ ] PDF présent en pièce jointe
- [ ] Nom du fichier : `facture_P202501001_xxx.pdf`
- [ ] PDF s'ouvre correctement
- [ ] Contenu du PDF correspond à la facture
- [ ] Taille du PDF raisonnable (< 10MB)

**Vérification :**
- Ouvrir l'email
- Télécharger le PDF
- Vérifier le contenu

---

### ✅ HTML Correct

- [ ] Email HTML s'affiche correctement (Gmail, Outlook, etc.)
- [ ] Header avec branding (couleur bleue)
- [ ] Nom du client correctement affiché
- [ ] Numéro de facture visible
- [ ] Montant TTC mis en évidence (vert)
- [ ] Date de facturation affichée
- [ ] Footer avec informations légales
- [ ] Avertissement "email automatique" visible

**Test sur différents clients :**
- [ ] Gmail (web)
- [ ] Outlook (web)
- [ ] Apple Mail
- [ ] Client mobile (iOS/Android)

**Fallback texte :**
- [ ] Version texte lisible si HTML désactivé
- [ ] Informations essentielles présentes

---

### ✅ email_logs Cohérent

- [ ] Une seule entrée `email_logs` avec `statut = 'sent'`
- [ ] `message_id` présent et valide (format `<timestamp.random@domain>`)
- [ ] `sent_at` rempli et récent
- [ ] `destinataire` correspond à l'email client
- [ ] `sujet` correspond au sujet de l'email

**Requête de vérification :**
```sql
SELECT 
    id,
    facture_id,
    statut,
    message_id,
    sent_at,
    destinataire,
    sujet,
    error_message
FROM email_logs
WHERE facture_id = :facture_id
ORDER BY created_at DESC;
```

**Résultat attendu :**
- 1 entrée avec `statut = 'sent'`
- `message_id` non NULL
- `sent_at` non NULL
- `error_message` NULL

---

### ✅ Pas de Double Envoi

- [ ] `email_envoye = 1` (pas 2, pas 0)
- [ ] `date_envoi_email` rempli une seule fois
- [ ] Un seul email reçu (vérifier boîte de réception)
- [ ] Un seul log avec `statut = 'sent'`

**Test de double envoi :**
```sql
-- Tenter d'envoyer 2 fois rapidement
-- Résultat : 1 seul email, 1 seul log sent
```

**Vérification :**
```sql
-- Compter les envois réussis
SELECT COUNT(*) AS nb_envois_reussis
FROM email_logs
WHERE facture_id = :facture_id
  AND statut = 'sent';
-- Résultat attendu : 1
```

---

## 6. DÉPANNAGE : Email Ne Part Pas

### 6.1 Causes Probables

#### A) Problème SMTP (Credentials/Configuration)

**Symptômes :**
- `email_logs.statut = 'failed'`
- `email_logs.error_message` contient "SMTP connect() failed" ou "Authentication failed"

**Vérifications :**

1. **Variables Railway (Service `cccomputer`)**
```bash
# Vérifier dans Railway Dashboard
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-email@brevo.com
SMTP_PASSWORD=votre-password-brevo
```

2. **Test SMTP direct**
```bash
curl -X POST https://votre-domaine.com/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"VOTRE_SMTP_TEST_TOKEN","to":"test@example.com"}'
```

**Où regarder :**
- Railway Dashboard → Service `cccomputer` → Logs
- Rechercher `[SMTP_TEST]` ou `[MAIL]`
- `email_logs.error_message`

---

#### B) Variables d'Environnement Manquantes

**Symptômes :**
- `email_logs.statut = 'failed'`
- `email_logs.error_message` contient "Configuration SMTP incomplète"

**Vérifications :**

1. **Variables requises**
```bash
# Railway Dashboard → Service cccomputer → Variables
SMTP_ENABLED=true
SMTP_HOST=...
SMTP_USERNAME=...
SMTP_PASSWORD=...
```

2. **Vérifier dans les logs**
```bash
# Railway Logs
[InvoiceEmailService] Configuration SMTP incomplète
```

**Où regarder :**
- Railway Dashboard → Variables
- Railway Logs → Rechercher "Configuration SMTP"

---

#### C) Email FROM Non Validé (SPF/DKIM)

**Symptômes :**
- Email envoyé mais rejeté (spam)
- Email non reçu
- `email_logs.statut = 'sent'` mais email introuvable

**Vérifications :**

1. **Email FROM configuré**
```bash
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
```

2. **Vérifier validation Brevo**
- Brevo Dashboard → Senders
- Vérifier que `cccomputer.fr` est validé SPF/DKIM

**Où regarder :**
- Brevo Dashboard → Senders
- Email reçu dans spam (vérifier)

---

#### D) Timeout SMTP

**Symptômes :**
- `email_logs.statut = 'failed'`
- `email_logs.error_message` contient "Timeout"

**Vérifications :**

1. **Timeout configuré**
```bash
SMTP_TIMEOUT=15  # ou 30 si SMTP lent
```

2. **Vérifier latence SMTP**
```bash
# Test de connexion SMTP
telnet smtp-relay.brevo.com 587
```

**Où regarder :**
- Railway Logs → Rechercher "Timeout"
- `email_logs.error_message`

---

#### E) Email Client Invalide

**Symptômes :**
- `email_logs.statut = 'failed'`
- `email_logs.error_message` contient "Adresse email invalide"
- Pas de log créé (erreur avant création log)

**Vérifications :**

1. **Email client valide**
```sql
SELECT id, email FROM clients WHERE id = :client_id;
-- Vérifier format email
```

2. **Vérifier dans les logs**
```bash
# Railway Logs
[InvoiceEmailService] Email client invalide pour facture #X
```

**Où regarder :**
- `clients.email` en DB
- Railway Logs → Rechercher "Email client invalide"

---

#### F) Facture Stuck (email_envoye=2)

**Symptômes :**
- `email_envoye = 2`
- `email_logs.statut = 'pending'` > 15 minutes
- Pas d'envoi

**Vérifications :**

1. **Détecter stuck**
```sql
-- Voir section 3.3
SELECT ... WHERE email_envoye = 2 AND minutes_stuck >= 15;
```

2. **Réinitialisation automatique**
- Le code réinitialise automatiquement si stuck > 15 min
- Sinon, réinitialiser manuellement :

```sql
-- Réinitialiser manuellement
UPDATE factures SET email_envoye = 0 WHERE id = :facture_id;
UPDATE email_logs SET statut = 'failed', error_message = 'Réinitialisation manuelle' 
WHERE facture_id = :facture_id AND statut = 'pending';
```

**Où regarder :**
- Requête SQL section 3.3
- Railway Logs → Rechercher "stuck"

---

#### G) Problème Code (Exception PHP)

**Symptômes :**
- `email_logs.statut = 'failed'`
- `email_logs.error_message` contient exception PHP
- Railway Logs avec stack trace

**Vérifications :**

1. **Logs Railway**
```bash
# Railway Dashboard → Logs
[InvoiceEmailService] ❌ Erreur critique: ...
[InvoiceEmailService] Stack trace: ...
```

2. **Vérifier les fichiers**
- `src/Services/InvoiceEmailService.php` existe
- `src/Mail/MailerService.php` existe
- `src/Mail/MailerFactory.php` existe
- Template HTML existe : `src/Mail/templates/invoice_email.html`

**Où regarder :**
- Railway Logs → Stack trace complète
- Vérifier que tous les fichiers sont déployés

---

### 6.2 Où Regarder pour Dépanner

#### Railway Logs

**Accès :**
- Railway Dashboard → Service `cccomputer` → Logs

**Rechercher :**
- `[InvoiceEmailService]` → Logs du service
- `[MAIL]` → Logs MailerService
- `[SMTP_TEST]` → Logs test SMTP
- `❌` → Erreurs
- `✅` → Succès

**Exemples de logs utiles :**
```
[InvoiceEmailService] ✅ Claim réussi pour facture #123
[InvoiceEmailService] ✅ Facture #123 envoyée avec succès
[InvoiceEmailService] ❌ Erreur envoi facture #123: SMTP connect() failed
[InvoiceEmailService] 🔓 Facture #123 détectée comme stuck, réinitialisation...
```

---

#### email_logs.error_message

**Requête :**
```sql
SELECT 
    id,
    facture_id,
    statut,
    error_message,
    created_at
FROM email_logs
WHERE facture_id = :facture_id
  AND statut = 'failed'
ORDER BY created_at DESC
LIMIT 1;
```

**Messages d'erreur courants :**
- `"SMTP connect() failed"` → Problème connexion
- `"Authentication failed"` → Credentials incorrects
- `"Adresse email invalide"` → Email client invalide
- `"Timeout"` → Timeout SMTP
- `"Configuration SMTP incomplète"` → Variables manquantes

---

#### Variables Railway

**Vérifier :**
- Railway Dashboard → Service `cccomputer` → Variables
- **IMPORTANT :** Service `cccomputer` (PAS MySQL)

**Variables requises :**
```bash
SMTP_ENABLED=true
SMTP_HOST=smtp-relay.brevo.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=...
SMTP_PASSWORD=...
SMTP_FROM_EMAIL=facturemail@cccomputer.fr
AUTO_SEND_INVOICES=true  # Si envoi automatique souhaité
```

---

## 📋 CHECKLIST RAPIDE DE TEST

### Avant Test

- [ ] Variables Railway configurées (Service `cccomputer`)
- [ ] SMTP testé et fonctionnel (`/test_smtp.php`)
- [ ] Client avec VOTRE email (pas le client réel)
- [ ] Facture générée avec `email_envoye = 0`

### Pendant Test

- [ ] Action déclenchée (génération/statut/force)
- [ ] Vérifier Railway Logs (`[InvoiceEmailService]`)
- [ ] Vérifier `email_logs` (statut pending → sent)

### Après Test

- [ ] Email reçu dans VOTRE boîte
- [ ] PDF joint et valide
- [ ] HTML correct
- [ ] `email_envoye = 1`
- [ ] `email_logs.statut = 'sent'`
- [ ] `message_id` présent
- [ ] Pas de double envoi

---

## 🎯 PROCÉDURE RECOMMANDÉE (Résumé)

1. **Préparer**
   - Créer/modifier client avec VOTRE email
   - Générer facture test

2. **Tester**
   - Utiliser Méthode A (génération) ou B (statut)
   - Vérifier Railway Logs

3. **Vérifier**
   - Email reçu
   - Requêtes SQL (section 3)
   - Checklist (section 5)

4. **Nettoyer**
   - Remettre email client original (si modifié)
   - Ou garder pour tests futurs

---

**Version :** 1.0  
**Statut :** ✅ Guide complet et actionnable

