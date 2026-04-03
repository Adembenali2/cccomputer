# Commandes PowerShell pour tester /test_smtp.php

## Configuration

```powershell
# Définir les variables
$baseUrl = "https://cccomputer-production.up.railway.app"
$token = "votre-token-secret"  # Remplacer par votre SMTP_TEST_TOKEN
$email = "test@example.com"    # Remplacer par votre email de test
```

---

## Test 1 : GET /test_smtp.php (vérification endpoint)

```powershell
curl.exe -i "$baseUrl/test_smtp.php"
```

**Résultat attendu :**
- Status : `200 OK`
- Content-Type : `application/json; charset=utf-8`
- Body : JSON avec `ok: true` et informations sur l'endpoint

---

## Test 2 : POST /test_smtp.php avec debug (vérification body)

```powershell
$body = @{
    token = $token
    debug = $true
} | ConvertTo-Json -Compress

Invoke-RestMethod -Uri "$baseUrl/test_smtp.php" `
    -Method POST `
    -ContentType "application/json" `
    -Body $body
```

**Résultat attendu :**
```json
{
  "ok": true,
  "debug": true,
  "body_length": 45,
  "content_type": "application/json",
  "has_raw_body": true,
  "raw_body_length": 45,
  "php_input_length": 0,
  "note": "Mode debug activé - token valide"
}
```

**Vérifications :**
- ✅ `has_raw_body: true` → Le router a bien lu le body
- ✅ `raw_body_length > 0` → Le body est présent
- ✅ `php_input_length: 0` → php://input est vide (normal après lecture)

---

## Test 3 : POST /test_smtp.php avec curl.exe (envoi email)

```powershell
$body = "{`"token`":`"$token`",`"to`":`"$email`"}"
curl.exe -i -X POST "$baseUrl/test_smtp.php" `
    -H "Content-Type: application/json" `
    -d $body
```

**Résultat attendu :**
```
HTTP/1.1 200 OK
Content-Type: application/json; charset=utf-8

{
  "ok": true,
  "message": "Email envoyé",
  "to": "test@example.com",
  "timestamp": "2025-01-XX XX:XX:XX"
}
```

**Vérifications :**
- ✅ Status : `200 OK`
- ✅ JSON avec `ok: true`
- ✅ Email reçu dans la boîte de réception

---

## Test 4 : POST /test_smtp.php avec Invoke-RestMethod (envoi email)

```powershell
$body = @{
    token = $token
    to = $email
} | ConvertTo-Json -Compress

$response = Invoke-RestMethod -Uri "$baseUrl/test_smtp.php" `
    -Method POST `
    -ContentType "application/json" `
    -Body $body

$response | ConvertTo-Json -Depth 10
```

**Résultat attendu :**
```json
{
  "ok": true,
  "message": "Email envoyé",
  "to": "test@example.com",
  "timestamp": "2025-01-XX XX:XX:XX"
}
```

**Vérifications :**
- ✅ `$response.ok` = `true`
- ✅ `$response.message` = `"Email envoyé"`
- ✅ Email reçu dans la boîte de réception

---

## Test 5 : POST avec body vide (test erreur)

```powershell
curl.exe -i -X POST "$baseUrl/test_smtp.php" `
    -H "Content-Type: application/json"
```

**Résultat attendu :**
```json
{
  "ok": false,
  "error": "Body vide (php://input) - vérifier router",
  "debug_info": {
    "has_raw_body": true,
    "raw_body_length": 0,
    "content_type": "application/json"
  }
}
```

---

## Test 6 : POST avec JSON invalide (test erreur)

```powershell
$body = "{`"token`":`"$token`",`"to`":`"invalid-json"
curl.exe -i -X POST "$baseUrl/test_smtp.php" `
    -H "Content-Type: application/json" `
    -d $body
```

**Résultat attendu :**
```json
{
  "ok": false,
  "error": "Données JSON invalides",
  "body_length": 35,
  "body_preview": "{\"token\":\"...\",\"to\":\"invalid-json"
}
```

---

## Script complet de test

```powershell
# Configuration
$baseUrl = "https://cccomputer-production.up.railway.app"
$token = "votre-token-secret"
$email = "test@example.com"

Write-Host "=== Test 1: GET /test_smtp.php ===" -ForegroundColor Cyan
curl.exe -i "$baseUrl/test_smtp.php"
Write-Host "`n"

Write-Host "=== Test 2: POST avec debug ===" -ForegroundColor Cyan
$bodyDebug = @{
    token = $token
    debug = $true
} | ConvertTo-Json -Compress

Invoke-RestMethod -Uri "$baseUrl/test_smtp.php" `
    -Method POST `
    -ContentType "application/json" `
    -Body $bodyDebug | ConvertTo-Json -Depth 10
Write-Host "`n"

Write-Host "=== Test 3: POST avec curl.exe (envoi email) ===" -ForegroundColor Cyan
$body = "{`"token`":`"$token`",`"to`":`"$email`"}"
curl.exe -i -X POST "$baseUrl/test_smtp.php" `
    -H "Content-Type: application/json" `
    -d $body
Write-Host "`n"

Write-Host "=== Test 4: POST avec Invoke-RestMethod (envoi email) ===" -ForegroundColor Cyan
$bodyIRM = @{
    token = $token
    to = $email
} | ConvertTo-Json -Compress

$response = Invoke-RestMethod -Uri "$baseUrl/test_smtp.php" `
    -Method POST `
    -ContentType "application/json" `
    -Body $bodyIRM

$response | ConvertTo-Json -Depth 10
Write-Host "`n"

Write-Host "=== Tests terminés ===" -ForegroundColor Green
Write-Host "Vérifiez que l'email est arrivé dans la boîte de réception de $email" -ForegroundColor Yellow
```

---

## Résumé des modifications

### Fichiers modifiés :

1. **index.php** : Lecture de `php://input` au tout début et stockage dans `$GLOBALS['RAW_BODY']`
2. **public/test_smtp.php** : Utilisation de `$GLOBALS['RAW_BODY']` + mode debug + meilleures erreurs
3. **public/API/test_smtp.php** : Même modifications

### Améliorations :

- ✅ Body POST maintenant accessible via `$GLOBALS['RAW_BODY']`
- ✅ Mode debug sécurisé (nécessite token valide)
- ✅ Messages d'erreur plus précis avec informations de debug
- ✅ Compatible avec `curl.exe` et `Invoke-RestMethod`

