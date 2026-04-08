# Requêtes SQL Rapides : Test Envoi Email Factures

**Référence rapide pour tester l'envoi d'emails de factures**

---

## 🔍 VÉRIFICATIONS RAPIDES

### 1. État d'une Facture (Avant Test)

```sql
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    f.date_envoi_email,
    f.statut,
    c.email as client_email,
    c.raison_sociale as client_nom
FROM factures f
LEFT JOIN clients c ON f.id_client = c.id
WHERE f.id = :facture_id;
```

**Remplacer :** `:facture_id` par l'ID de votre facture de test

---

### 2. État d'une Facture (Après Test)

```sql
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    f.date_envoi_email,
    el.statut as log_statut,
    el.message_id,
    el.sent_at,
    el.error_message,
    TIMESTAMPDIFF(SECOND, el.sent_at, NOW()) as seconds_ago
FROM factures f
LEFT JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
ORDER BY el.created_at DESC
LIMIT 1;
```

**Interprétation :**
- ✅ `email_envoye = 1` ET `log_statut = 'sent'` → **Email parti**
- ❌ `email_envoye = 0` ET `log_statut = 'failed'` → **Échec**
- ⚠️ `email_envoye = 2` → **En cours ou stuck**

---

### 3. Vérifier Erreur SMTP

```sql
SELECT 
    f.numero,
    el.error_message,
    el.created_at
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
  AND el.statut = 'failed'
ORDER BY el.created_at DESC
LIMIT 1;
```

---

### 4. Vérifier Facture Stuck

```sql
SELECT 
    f.id,
    f.numero,
    f.email_envoye,
    el.created_at as log_created_at,
    TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) as minutes_stuck,
    CASE 
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) >= 15 THEN 'STUCK'
        ELSE 'En cours (normal)'
    END as statut
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

---

### 5. Message-ID du Dernier Envoi

```sql
SELECT 
    f.numero,
    el.message_id,
    el.sent_at,
    el.destinataire
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
  AND el.statut = 'sent'
  AND el.message_id IS NOT NULL
ORDER BY el.sent_at DESC
LIMIT 1;
```

---

## 🔧 PRÉPARATION TEST

### Changer Email Client Temporairement

```sql
-- 1. Sauvegarder email original
SELECT id, email FROM clients WHERE id = :client_id;

-- 2. Changer pour votre email
UPDATE clients 
SET email = 'votre-email@example.com'
WHERE id = :client_id;

-- 3. Après test : remettre email original
UPDATE clients 
SET email = 'email-original@client.com'
WHERE id = :client_id;
```

---

### Réinitialiser Facture Stuck

```sql
-- Si facture bloquée en email_envoye=2
UPDATE factures 
SET email_envoye = 0 
WHERE id = :facture_id
  AND email_envoye = 2;

UPDATE email_logs 
SET statut = 'failed', 
    error_message = 'Réinitialisation manuelle'
WHERE facture_id = :facture_id
  AND statut = 'pending';
```

---

## 📊 STATISTIQUES

### Compter Factures Stuck

```sql
SELECT COUNT(*) AS nb_stuck
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.email_envoye = 2
  AND el.statut = 'pending'
  AND el.created_at < NOW() - INTERVAL 15 MINUTE;
```

### Derniers Envois (24h)

```sql
SELECT 
    f.id,
    f.numero,
    el.statut,
    el.sent_at,
    el.destinataire
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE el.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY el.created_at DESC
LIMIT 20;
```

---

**Version :** 1.0  
**Usage :** Copier-coller les requêtes en remplaçant `:facture_id` ou `:client_id`

