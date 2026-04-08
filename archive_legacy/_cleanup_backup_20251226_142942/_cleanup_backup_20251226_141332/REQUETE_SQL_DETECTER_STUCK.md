# Requête SQL : Détecter les Factures Stuck (Bloquées)

**Date :** 2025-01-XX  
**Version :** 1.0

---

## 🔍 Requête SQL pour Détecter les Factures Stuck

### Requête Complète

```sql
-- Détecter les factures bloquées en email_envoye=2 avec log pending > 15 minutes
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    f.email_envoye,
    f.date_envoi_email,
    el.id AS log_id,
    el.statut AS log_statut,
    el.created_at AS log_created_at,
    TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) AS minutes_stuck,
    el.destinataire,
    el.sujet
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.email_envoye = 2  -- En cours d'envoi
  AND el.statut = 'pending'  -- Log toujours en pending
  AND el.created_at < NOW() - INTERVAL 15 MINUTE  -- Créé il y a plus de 15 minutes
ORDER BY el.created_at ASC;  -- Les plus anciens en premier
```

---

## 📊 Variantes Utiles

### 1. Compter les Factures Stuck

```sql
SELECT COUNT(*) AS nb_factures_stuck
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.email_envoye = 2
  AND el.statut = 'pending'
  AND el.created_at < NOW() - INTERVAL 15 MINUTE;
```

### 2. Détails avec Informations Client

```sql
SELECT 
    f.id AS facture_id,
    f.numero AS facture_numero,
    f.email_envoye,
    f.date_envoi_email,
    c.raison_sociale AS client_nom,
    c.email AS client_email,
    el.id AS log_id,
    el.statut AS log_statut,
    el.created_at AS log_created_at,
    TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) AS minutes_stuck,
    el.destinataire,
    el.sujet
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
LEFT JOIN clients c ON f.id_client = c.id
WHERE f.email_envoye = 2
  AND el.statut = 'pending'
  AND el.created_at < NOW() - INTERVAL 15 MINUTE
ORDER BY el.created_at ASC;
```

### 3. Statistiques par Durée de Blocage

```sql
SELECT 
    CASE 
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) BETWEEN 15 AND 30 THEN '15-30 min'
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) BETWEEN 30 AND 60 THEN '30-60 min'
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) BETWEEN 60 AND 120 THEN '1-2h'
        WHEN TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) >= 120 THEN '> 2h'
    END AS duree_blocage,
    COUNT(*) AS nb_factures
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.email_envoye = 2
  AND el.statut = 'pending'
  AND el.created_at < NOW() - INTERVAL 15 MINUTE
GROUP BY duree_blocage
ORDER BY 
    CASE duree_blocage
        WHEN '15-30 min' THEN 1
        WHEN '30-60 min' THEN 2
        WHEN '1-2h' THEN 3
        WHEN '> 2h' THEN 4
    END;
```

### 4. Réinitialiser Manuellement une Facture Stuck

```sql
-- ÉTAPE 1 : Identifier la facture stuck
SELECT f.id, f.numero, el.id AS log_id
FROM factures f
INNER JOIN email_logs el ON el.facture_id = f.id
WHERE f.id = :facture_id
  AND f.email_envoye = 2
  AND el.statut = 'pending'
  AND el.created_at < NOW() - INTERVAL 15 MINUTE;

-- ÉTAPE 2 : Marquer le log comme failed
UPDATE email_logs 
SET statut = 'failed', 
    error_message = 'Réinitialisation manuelle (stuck détecté)'
WHERE facture_id = :facture_id
  AND statut = 'pending'
  AND created_at < NOW() - INTERVAL 15 MINUTE;

-- ÉTAPE 3 : Remettre email_envoye à 0
UPDATE factures 
SET email_envoye = 0 
WHERE id = :facture_id
  AND email_envoye = 2;
```

---

## 🔧 Requête pour Monitoring (Dashboard)

```sql
-- Vue d'ensemble : factures en cours, stuck, envoyées
SELECT 
    CASE 
        WHEN f.email_envoye = 0 THEN 'Non envoyé'
        WHEN f.email_envoye = 2 AND el.id IS NULL THEN 'En cours (pas de log)'
        WHEN f.email_envoye = 2 AND el.statut = 'pending' AND el.created_at >= NOW() - INTERVAL 15 MINUTE THEN 'En cours (normal)'
        WHEN f.email_envoye = 2 AND el.statut = 'pending' AND el.created_at < NOW() - INTERVAL 15 MINUTE THEN 'Stuck (>15 min)'
        WHEN f.email_envoye = 1 THEN 'Envoyé'
        ELSE 'Statut inconnu'
    END AS statut_detaille,
    COUNT(*) AS nombre
FROM factures f
LEFT JOIN email_logs el ON el.facture_id = f.id 
    AND el.statut = 'pending'
    AND el.id = (
        SELECT id FROM email_logs 
        WHERE facture_id = f.id 
        ORDER BY created_at DESC 
        LIMIT 1
    )
WHERE f.pdf_genere = 1  -- Seulement les factures avec PDF généré
GROUP BY statut_detaille
ORDER BY 
    CASE statut_detaille
        WHEN 'Stuck (>15 min)' THEN 1
        WHEN 'En cours (normal)' THEN 2
        WHEN 'Non envoyé' THEN 3
        WHEN 'Envoyé' THEN 4
        ELSE 5
    END;
```

---

## ⚠️ Notes Importantes

1. **Seuil de 15 minutes** : Configuré dans le code PHP, ajustable si nécessaire
2. **Log pending** : Un log `pending` > 15 min indique un process crash/timeout
3. **Réinitialisation automatique** : Le code PHP réinitialise automatiquement les stuck
4. **Monitoring** : Exécuter régulièrement pour détecter les stuck

---

## 🧪 Test de la Requête

```sql
-- Créer un test (facture stuck simulée)
-- ATTENTION : Ne pas exécuter en production sans précaution

-- 1. Créer une facture test
INSERT INTO factures (id_client, numero, date_facture, montant_ttc, email_envoye, pdf_genere)
VALUES (1, 'TEST-STUCK', CURDATE(), 100.00, 2, 1);

SET @facture_id = LAST_INSERT_ID();

-- 2. Créer un log pending ancien (> 15 min)
INSERT INTO email_logs (facture_id, type_email, destinataire, sujet, statut, created_at)
VALUES (@facture_id, 'facture', 'test@example.com', 'Test Stuck', 'pending', NOW() - INTERVAL 20 MINUTE);

-- 3. Vérifier que la requête détecte le stuck
SELECT * FROM (
    SELECT 
        f.id AS facture_id,
        f.numero,
        TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) AS minutes_stuck
    FROM factures f
    INNER JOIN email_logs el ON el.facture_id = f.id
    WHERE f.id = @facture_id
      AND f.email_envoye = 2
      AND el.statut = 'pending'
      AND el.created_at < NOW() - INTERVAL 15 MINUTE
) AS stuck_test;

-- 4. Nettoyer (optionnel)
-- DELETE FROM email_logs WHERE facture_id = @facture_id;
-- DELETE FROM factures WHERE id = @facture_id;
```

---

**Version :** 1.0  
**Statut :** ✅ Requête validée

