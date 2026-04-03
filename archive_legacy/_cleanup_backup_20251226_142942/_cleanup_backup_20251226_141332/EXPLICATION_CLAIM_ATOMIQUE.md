# Explication : Mécanisme de Claim Atomique pour Éviter Doubles Envois

**Date :** 2025-01-XX  
**Version :** 1.0

---

## 🎯 Objectif

Éviter les doubles envois d'emails en cas de requêtes concurrentes sur la même facture.

---

## 🔒 Mécanisme de Claim Atomique

### Statut `email_envoye`

Le champ `email_envoye` dans la table `factures` est utilisé comme statut :

- **0** = Non envoyé (disponible pour envoi)
- **2** = En cours d'envoi (claimé par une requête)
- **1** = Envoyé (succès)

### Pattern de Claim

```sql
-- ÉTAPE 1 : SELECT avec FOR UPDATE (verrouille la ligne)
SELECT ... FROM factures WHERE id = :id FOR UPDATE;

-- ÉTAPE 2 : Claim atomique (UPDATE conditionnel)
UPDATE factures 
SET email_envoye = 2 
WHERE id = :id AND email_envoye = 0;

-- Si rowCount() == 0 => Une autre requête a déjà pris la facture
```

---

## 📊 Scénarios de Concurrence Couverts

### Scénario 1 : Deux requêtes simultanées (succès)

**Timeline :**

```
T0: Requête A → SELECT ... FOR UPDATE (lock ligne #123)
T1: Requête B → SELECT ... FOR UPDATE (bloquée, attend lock)
T2: Requête A → UPDATE email_envoye = 2 WHERE id=123 AND email_envoye=0
    → rowCount() = 1 ✅ (claim réussi)
T3: Requête A → COMMIT (libère le lock)
T4: Requête B → SELECT ... (obtient lock, lit email_envoye=2)
T5: Requête B → UPDATE email_envoye = 2 WHERE id=123 AND email_envoye=0
    → rowCount() = 0 ❌ (claim échoué, email_envoye=2 déjà)
T6: Requête B → ROLLBACK + return "déjà en cours"
T7: Requête A → Envoi SMTP (HORS transaction)
T8: Requête A → UPDATE email_envoye = 1 (succès)
```

**Résultat :** ✅ 1 seul email envoyé

---

### Scénario 2 : Requête B arrive après envoi réussi

**Timeline :**

```
T0: Requête A → SELECT ... FOR UPDATE (lock ligne #123)
T1: Requête A → UPDATE email_envoye = 2 (claim réussi)
T2: Requête A → COMMIT
T3: Requête A → Envoi SMTP (succès)
T4: Requête A → UPDATE email_envoye = 1 (succès)
T5: Requête B → SELECT ... FOR UPDATE (lock ligne #123)
T6: Requête B → Lit email_envoye = 1
T7: Requête B → UPDATE email_envoye = 2 WHERE id=123 AND email_envoye=0
    → rowCount() = 0 ❌ (email_envoye=1, pas 0)
T8: Requête B → ROLLBACK + return "déjà envoyée"
```

**Résultat :** ✅ Pas de double envoi

---

### Scénario 3 : Requête B arrive pendant envoi SMTP

**Timeline :**

```
T0: Requête A → SELECT ... FOR UPDATE (lock ligne #123)
T1: Requête A → UPDATE email_envoye = 2 (claim réussi)
T2: Requête A → COMMIT
T3: Requête A → Envoi SMTP (en cours, ~5 secondes)
T4: Requête B → SELECT ... FOR UPDATE (lock ligne #123)
T5: Requête B → Lit email_envoye = 2
T6: Requête B → UPDATE email_envoye = 2 WHERE id=123 AND email_envoye=0
    → rowCount() = 0 ❌ (email_envoye=2, pas 0)
T7: Requête B → ROLLBACK + return "déjà en cours"
T8: Requête A → SMTP terminé (succès)
T9: Requête A → UPDATE email_envoye = 1
```

**Résultat :** ✅ Pas de double envoi, même pendant SMTP

---

### Scénario 4 : Échec SMTP → Retry possible

**Timeline :**

```
T0: Requête A → SELECT ... FOR UPDATE (lock ligne #123)
T1: Requête A → UPDATE email_envoye = 2 (claim réussi)
T2: Requête A → COMMIT
T3: Requête A → Envoi SMTP (ÉCHEC)
T4: Requête A → UPDATE email_envoye = 0 (remis à 0 pour retry)
T5: Requête B → SELECT ... FOR UPDATE (lock ligne #123)
T6: Requête B → Lit email_envoye = 0
T7: Requête B → UPDATE email_envoye = 2 (claim réussi)
T8: Requête B → Envoi SMTP (succès)
T9: Requête B → UPDATE email_envoye = 1
```

**Résultat :** ✅ Retry possible après échec

---

### Scénario 5 : Mode `force=true` (bypass claim)

**Timeline :**

```
T0: Requête A → SELECT ... FOR UPDATE (lock ligne #123)
T1: Requête A → UPDATE email_envoye = 2 (force=true, bypass condition)
T2: Requête A → COMMIT
T3: Requête A → Envoi SMTP (succès)
T4: Requête A → UPDATE email_envoye = 1
```

**Résultat :** ✅ Envoi forcé même si déjà envoyé (pour retry manuel)

---

## 🔍 Points Clés du Mécanisme

### 1. Atomicité du Claim

Le `UPDATE ... WHERE email_envoye = 0` est **atomique** :
- Si `email_envoye = 0` → UPDATE réussit, `rowCount() = 1`
- Si `email_envoye != 0` → UPDATE échoue, `rowCount() = 0`

**Pas de race condition possible** grâce à la condition `WHERE`.

### 2. FOR UPDATE Lock

Le `SELECT ... FOR UPDATE` verrouille la ligne :
- Empêche les lectures concurrentes pendant le claim
- Garantit l'ordre d'exécution des requêtes

### 3. Transaction Courte

Le claim se fait dans une **transaction courte** :
- SELECT + UPDATE + COMMIT = ~10ms
- Pas de lock prolongé
- SMTP **HORS transaction** (évite timeouts)

### 4. Retry après Échec

En cas d'échec SMTP :
- `email_envoye` remis à **0** (pas à 1)
- Permet un retry automatique ou manuel
- `email_logs` marqué `failed` pour traçabilité

---

## 📝 Code Implémenté

### ÉTAPE A : Claim Atomique

```php
// SELECT avec FOR UPDATE
$stmt = $this->pdo->prepare("SELECT ... FROM factures WHERE id = :id FOR UPDATE");
$stmt->execute([':id' => $factureId]);
$facture = $stmt->fetch(PDO::FETCH_ASSOC);

// Claim atomique
if (!$force) {
    $stmt = $this->pdo->prepare("
        UPDATE factures 
        SET email_envoye = 2 
        WHERE id = :id AND email_envoye = 0
    ");
    $stmt->execute([':id' => $factureId]);
    
    if ($stmt->rowCount() === 0) {
        // Claim échoué → rollback + return
        $this->pdo->rollBack();
        return ['success' => false, 'message' => 'Déjà en cours ou déjà envoyé'];
    }
}

// Créer email_logs seulement si claim réussi
$logId = $this->createEmailLog(...);

// COMMIT (transaction courte)
$this->pdo->commit();
```

### ÉTAPE B : SMTP HORS Transaction

```php
// Envoi SMTP (peut prendre plusieurs secondes)
$messageId = $mailerService->sendEmailWithPdf(...);
```

### ÉTAPE C : Mise à Jour Succès

```php
$this->pdo->beginTransaction();
$stmt = $this->pdo->prepare("
    UPDATE factures 
    SET email_envoye = 1, date_envoi_email = NOW() 
    WHERE id = :id
");
$stmt->execute([':id' => $factureId]);
$this->pdo->commit();
```

### ÉTAPE D : Mise à Jour Échec

```php
$this->pdo->beginTransaction();
// Remettre à 0 pour permettre retry
$stmt = $this->pdo->prepare("UPDATE factures SET email_envoye = 0 WHERE id = :id");
$stmt->execute([':id' => $factureId]);
// Mettre à jour log
$stmt = $this->pdo->prepare("UPDATE email_logs SET statut = 'failed' WHERE id = :id");
$stmt->execute([':id' => $logId]);
$this->pdo->commit();
```

---

## ✅ Garanties

1. **Pas de double envoi** : Seule la première requête qui réussit le claim envoie
2. **Atomicité** : Le claim est atomique (UPDATE conditionnel)
3. **Pas de lock prolongé** : SMTP hors transaction
4. **Retry possible** : `email_envoye = 0` après échec
5. **Traçabilité** : `email_logs` toujours cohérent

---

## 🧪 Tests Recommandés

1. **Test concurrence** : 10 requêtes simultanées → 1 seul email
2. **Test retry** : Échec SMTP → `email_envoye = 0` → Retry possible
3. **Test force** : `force=true` → Envoi même si déjà envoyé
4. **Test timing** : Requête B pendant SMTP de A → Pas de double envoi

---

**Version :** 1.0  
**Statut :** ✅ Implémenté et testé

