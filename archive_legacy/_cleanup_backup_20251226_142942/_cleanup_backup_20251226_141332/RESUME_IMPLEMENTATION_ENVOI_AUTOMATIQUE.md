# Résumé Implémentation : Envoi Automatique de Factures

**Date :** 2025-01-XX  
**Lead Dev :** Auto (Cursor AI)

---

## ✅ CE QUI A ÉTÉ IMPLÉMENTÉ

### 1. Infrastructure

- ✅ **Table `email_logs`** : Journalisation complète des envois
- ✅ **Service `InvoiceEmailService`** : Logique centralisée d'envoi automatique
- ✅ **Template email HTML** : Template professionnel (optionnel, non utilisé actuellement)
- ✅ **Configuration** : Variable `AUTO_SEND_INVOICES` dans `config/app.php`

### 2. Intégration

- ✅ **`API/factures_generer.php`** : Envoi automatique après génération de facture
- ✅ **`API/factures_update_statut.php`** : Envoi automatique après validation admin (statut = 'envoyee')
- ✅ **Idempotence** : Protection contre double envoi (`email_envoye = 1`)
- ✅ **Gestion d'erreurs** : Erreurs non bloquantes, logs détaillés

### 3. Fonctionnalités

- ✅ Envoi automatique après génération (si `AUTO_SEND_INVOICES=true`)
- ✅ Envoi automatique après validation admin (toujours actif)
- ✅ Envoi manuel (déjà existant, conservé)
- ✅ Régénération PDF si fichier perdu (Railway stockage éphémère)
- ✅ Traçabilité complète dans `email_logs`

---

## 🎯 POINT DE DÉPART

### Premier fichier à créer/modifier

**1. Exécuter la migration SQL :**

```bash
# En local
php sql/run_migration_email_logs.php

# En production (Railway Shell)
cd /var/www/html  # ou /app
php sql/run_migration_email_logs.php
```

**2. Configurer les variables Railway :**

Railway Dashboard → Service `cccomputer` → Variables :

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

**3. Redéployer le service Railway**

Après ajout des variables, Railway redéploie automatiquement.

**4. Tester l'envoi automatique**

- Générer une facture via l'interface
- Vérifier la réception de l'email
- Consulter les logs Railway pour confirmer l'envoi

---

## ✅ CHECKLIST DE TESTS

### Tests développement (local)

#### Prérequis
- [ ] Migration `email_logs` exécutée
- [ ] Variables d'environnement configurées (`.env` ou `config/app.php`)
- [ ] SMTP configuré et testé (`/test_smtp.php`)

#### Tests fonctionnels
- [ ] Générer une facture → Vérifier envoi automatique
- [ ] Vérifier `email_logs` contient l'entrée avec `statut = 'sent'`
- [ ] Vérifier `factures.email_envoye = 1` et `date_envoi_email` rempli
- [ ] Tester idempotence : générer 2 fois la même facture → 1 seul envoi
- [ ] Tester avec email client invalide → Vérifier logs d'erreur
- [ ] Tester avec PDF manquant → Vérifier régénération dans `/tmp`
- [ ] Tester validation admin (statut → 'envoyee') → Vérifier envoi automatique

#### Tests de robustesse
- [ ] Tester avec `AUTO_SEND_INVOICES=false` → Pas d'envoi automatique
- [ ] Tester avec SMTP désactivé → Erreur gracieuse, facture générée
- [ ] Tester avec client sans email → Erreur gracieuse, facture générée

### Tests production (Railway)

#### Prérequis
- [ ] Variables Railway configurées (Service `cccomputer`, PAS MySQL)
- [ ] `SMTP_ENABLED=true` et credentials valides
- [ ] `AUTO_SEND_INVOICES=true`
- [ ] Migration `email_logs` exécutée en production

#### Tests fonctionnels
- [ ] Générer facture test → Vérifier réception email
- [ ] Vérifier logs Railway (`[InvoiceEmailService]`, `[MAIL]`)
- [ ] Vérifier `email_logs` en DB (table créée, entrées présentes)
- [ ] Tester validation admin → Vérifier envoi automatique
- [ ] Vérifier PDF joint à l'email (ouvrir et vérifier contenu)

#### Tests de monitoring
- [ ] Consulter logs Railway pour erreurs SMTP
- [ ] Vérifier table `email_logs` pour statistiques
- [ ] Tester endpoint manuel `/API/factures_envoyer_email.php` (fallback)

---

## ⚠️ PIÈGES RAILWAY

### 1. Variables d'environnement

**Piège :** Variables dans le mauvais service

**Solution :**
- ✅ Variables dans Service `cccomputer` (PAS MySQL)
- ✅ Redéployer après modification

**Vérification :**
```bash
# Railway Shell
echo $AUTO_SEND_INVOICES
echo $SMTP_ENABLED
```

### 2. Stockage éphémère

**Piège :** Fichiers dans `uploads/` perdus au redéploiement

**Solution :**
- ✅ Déjà géré : Fallback vers `/tmp` si PDF introuvable
- ✅ Régénération à la volée via `generateInvoicePdf()`

**Vérification :**
```bash
# Vérifier que /tmp est accessible
ls -la /tmp
```

### 3. Ports et timeouts

**Piège :** Timeout Railway (60s max) si envoi SMTP lent

**Solution :**
- ✅ Envoi synchrone actuel (fonctionne si SMTP < 60s)
- ⚠️ Si problème : Implémenter queue asynchrone (futur)

**Vérification :**
- Consulter logs Railway pour timeouts
- Tester avec SMTP rapide (Brevo recommandé)

### 4. Document root

**Piège :** Document root différent selon config Railway

**Solution :**
- ✅ Code gère plusieurs chemins possibles (`/app`, `/var/www/html`, `DOCUMENT_ROOT`)
- ✅ Fallback vers `/tmp` si PDF introuvable

**Vérification :**
```bash
# Railway Shell
php -r "echo \$_SERVER['DOCUMENT_ROOT'];"
```

### 5. Logs

**Piège :** Logs non visibles ou perdus

**Solution :**
- ✅ `error_log()` → Logs Railway Dashboard
- ✅ Table `email_logs` pour traçabilité persistante

**Vérification :**
- Railway Dashboard → Service `cccomputer` → Logs
- Rechercher `[InvoiceEmailService]`

### 6. SMTP credentials

**Piège :** Credentials incorrects ou domaine non validé

**Solution :**
- ✅ Utiliser `facturemail@cccomputer.fr` (domaine validé SPF/DKIM)
- ✅ Tester SMTP via `/test_smtp.php` avant production

**Vérification :**
```bash
curl -X POST https://votre-domaine.com/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"VOTRE_TOKEN","to":"test@example.com"}'
```

### 7. Base de données

**Piège :** Migration non exécutée ou table manquante

**Solution :**
- ✅ Script PHP de migration : `sql/run_migration_email_logs.php`
- ✅ Vérifier existence table avant utilisation

**Vérification :**
```sql
SHOW TABLES LIKE 'email_logs';
DESCRIBE email_logs;
```

---

## 📊 MONITORING ET MAINTENANCE

### Requêtes SQL utiles

**Factures non envoyées :**
```sql
SELECT 
    f.id,
    f.numero,
    f.date_facture,
    c.email as client_email,
    f.pdf_genere,
    f.email_envoye
FROM factures f
LEFT JOIN clients c ON f.id_client = c.id
WHERE f.pdf_genere = 1 
  AND f.email_envoye = 0
  AND f.statut != 'annulee'
ORDER BY f.date_facture DESC;
```

**Statistiques d'envoi (30 derniers jours) :**
```sql
SELECT 
    statut,
    COUNT(*) as count,
    COUNT(*) * 100.0 / (SELECT COUNT(*) FROM email_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as percentage
FROM email_logs
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
GROUP BY statut;
```

**Derniers envois :**
```sql
SELECT 
    el.id,
    el.facture_id,
    f.numero,
    el.destinataire,
    el.statut,
    el.sent_at,
    el.error_message
FROM email_logs el
LEFT JOIN factures f ON el.facture_id = f.id
ORDER BY el.created_at DESC
LIMIT 50;
```

### Alertes recommandées

1. **Taux d'échec élevé** : Si `statut = 'failed'` > 10% sur 24h
2. **Factures non envoyées** : Si factures avec `pdf_genere = 1` et `email_envoye = 0` > 5
3. **Erreurs SMTP répétées** : Si erreurs SMTP dans logs Railway

---

## 🔄 PROCÉDURE DE ROLLBACK

Si besoin de désactiver l'envoi automatique :

1. **Désactiver variable Railway :**
   ```bash
   AUTO_SEND_INVOICES=false
   ```

2. **Redéployer le service**

3. **Vérifier :**
   - Générer une facture → Pas d'envoi automatique
   - Envoi manuel toujours disponible

**Note :** Aucune modification de code nécessaire, juste la variable d'environnement.

---

## 📚 DOCUMENTATION COMPLÉMENTAIRE

- **`ANALYSE_ENVOI_AUTOMATIQUE_FACTURES.md`** : Analyse complète du système
- **`GUIDE_IMPLEMENTATION_ENVOI_AUTOMATIQUE.md`** : Guide d'utilisation détaillé
- **`RAPPORT_SMTP_RAILWAY.md`** : Configuration SMTP et résolution de problèmes

---

## 🎯 PROCHAINES ÉTAPES RECOMMANDÉES

1. ✅ **Tests en production** : Valider avec factures réelles
2. ⚠️ **Monitoring** : Mettre en place alertes sur échecs
3. ⚠️ **Template HTML** : Intégrer template dans `MailerService` (actuellement texte brut)
4. ⚠️ **Retry automatique** : Implémenter retry pour échecs temporaires
5. ⚠️ **Queue asynchrone** : Envoi en arrière-plan pour éviter timeouts

---

**Version :** 1.0  
**Statut :** ✅ Implémenté et prêt pour déploiement

