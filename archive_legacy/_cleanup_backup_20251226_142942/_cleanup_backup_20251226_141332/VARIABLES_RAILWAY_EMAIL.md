# Variables Railway pour Envoi de Factures par Email

**Service :** `cccomputer` (PAS MySQL)  
**Date :** 2025-01-XX

---

## ⚠️ IMPORTANT : Service Correct

Toutes les variables d'environnement doivent être définies sur le **Service Web `cccomputer`**, **PAS** sur le service MySQL.

Railway Dashboard → Service `cccomputer` → Variables

---

## Variables Requises

### Configuration SMTP (déjà existantes)

```bash
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

### Configuration Envoi Automatique

```bash
# Activer l'envoi automatique après génération de facture
AUTO_SEND_INVOICES=true

# Options avancées (optionnelles)
AUTO_SEND_INVOICES_RETRY=false  # Retry automatique en cas d'échec (non implémenté actuellement)
AUTO_SEND_INVOICES_DELAY=0      # Délai en secondes avant envoi (0 = immédiat)
```

### Configuration Timeout SMTP

```bash
# Timeout SMTP en secondes (défaut: 15)
SMTP_TIMEOUT=15
```

**Recommandation :** 15-30 secondes selon la latence de votre serveur SMTP.

### Configuration Message-ID

```bash
# Domaine pour générer les Message-ID (défaut: cccomputer.fr)
MAIL_MESSAGE_ID_DOMAIN=cccomputer.fr
```

**Format généré :** `<timestamp.random@cccomputer.fr>`

### Configuration Application

```bash
# URL de base de l'application (pour liens dans emails)
APP_URL=https://cccomputer-production.up.railway.app
```

**Utilisation :** Liens dans les templates email HTML.

### Configuration SSL/TLS (optionnelle, déconseillée)

```bash
# Désactiver la vérification SSL/TLS (UNIQUEMENT pour tests)
SMTP_DISABLE_VERIFY=false
```

**⚠️ ATTENTION :** Ne jamais activer en production sauf si absolument nécessaire.

---

## Valeurs par Défaut

Si une variable n'est pas définie, les valeurs par défaut sont :

| Variable | Défaut | Description |
|----------|--------|-------------|
| `AUTO_SEND_INVOICES` | `false` | Envoi automatique désactivé |
| `AUTO_SEND_INVOICES_RETRY` | `false` | Retry désactivé |
| `AUTO_SEND_INVOICES_DELAY` | `0` | Pas de délai |
| `SMTP_TIMEOUT` | `15` | 15 secondes |
| `MAIL_MESSAGE_ID_DOMAIN` | `cccomputer.fr` | Domaine pour Message-ID |
| `APP_URL` | `https://cccomputer-production.up.railway.app` | URL de l'application |
| `SMTP_DISABLE_VERIFY` | `false` | Vérification SSL activée |

---

## Validation des Variables Booléennes

Les variables booléennes sont validées avec `filter_var(..., FILTER_VALIDATE_BOOLEAN)` :

- `"true"`, `"1"`, `"yes"`, `"on"` → `true`
- `"false"`, `"0"`, `"no"`, `"off"`, `""` → `false`

**Exemple :**
```bash
AUTO_SEND_INVOICES=false  # ✅ Correct (sera interprété comme false)
AUTO_SEND_INVOICES=0      # ✅ Correct (sera interprété comme false)
AUTO_SEND_INVOICES=true   # ✅ Correct (sera interprété comme true)
```

---

## Checklist de Configuration

- [ ] Variables définies sur Service `cccomputer` (PAS MySQL)
- [ ] `SMTP_ENABLED=true` et credentials valides
- [ ] `AUTO_SEND_INVOICES=true` (si envoi automatique souhaité)
- [ ] `APP_URL` défini avec l'URL correcte
- [ ] `MAIL_MESSAGE_ID_DOMAIN` défini (optionnel, défaut: cccomputer.fr)
- [ ] `SMTP_TIMEOUT` défini si nécessaire (défaut: 15)
- [ ] Service redéployé après modification des variables

---

## Test de Configuration

Après configuration, tester l'envoi SMTP :

```bash
curl -X POST https://votre-domaine.com/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{"token":"VOTRE_SMTP_TEST_TOKEN","to":"test@example.com"}'
```

---

## Dépannage

### Variable non prise en compte

1. Vérifier que la variable est sur le **Service Web** (pas MySQL)
2. Redéployer le service après modification
3. Vérifier les logs Railway pour erreurs de configuration

### Envoi automatique ne fonctionne pas

1. Vérifier `AUTO_SEND_INVOICES=true` (pas `"false"` comme string)
2. Vérifier les logs : `[InvoiceEmailService]`
3. Vérifier que `SMTP_ENABLED=true`

### Timeout SMTP

1. Augmenter `SMTP_TIMEOUT` (ex: 30)
2. Vérifier la latence du serveur SMTP
3. Consulter les logs Railway pour détails

---

**Version :** 1.0  
**Dernière mise à jour :** 2025-01-XX

