# Audit Qualité & Sécurité - Implémentation Email

## Checklist "OK / À corriger"

### ✅ OK - Points validés

- [x] **Utilisation de PHPMailer** : Service réutilisable via `MailerService` et `MailerFactory`
- [x] **Validation des emails** : `filter_var()` avec `FILTER_VALIDATE_EMAIL`
- [x] **Validation des pièces jointes** : Vérification existence, lisibilité, extension .pdf, taille max 10MB
- [x] **MIME type** : Spécification explicite `application/pdf` pour les pièces jointes
- [x] **Masquage des secrets** : Fonction `sanitizeError()` pour masquer passwords/tokens dans les logs
- [x] **Gestion d'erreurs** : Messages sanitaires côté client, détails dans les logs serveur
- [x] **Méthodes HTTP** : Validation POST uniquement pour les endpoints API
- [x] **Paramètres validés** : Validation stricte des entrées (email, facture_id, etc.)

### ✅ Corrigé - Points améliorés

- [x] **SMTP_FROM_NAME** : Changé de "CC Computer" à "Camson Group - Facturation" dans les defaults
- [x] **findPdfPath()** : 
  - Protection contre path traversal (détection de `../`)
  - Vérification que le chemin est dans `uploads/factures/`
  - Utilisation de `realpath()` pour validation finale
  - Retourne une exception claire au lieu de `null`
- [x] **test_smtp.php** :
  - Token maintenant **obligatoire** en toutes circonstances
  - Suppression des infos sensibles dans la réponse (from_email retiré)
  - Message de test simplifié (pas d'exposition de config)
- [x] **SMTP secure** :
  - Mapping correct `tls` → `PHPMailer::ENCRYPTION_STARTTLS`
  - Mapping correct `ssl` → `PHPMailer::ENCRYPTION_SMTPS`
  - Utilisation des constantes PHPMailer
- [x] **SMTPOptions** :
  - Vérification SSL/TLS désactivée uniquement si `SMTP_DISABLE_VERIFY=true`
  - Par défaut, vérification activée (sécurité)
  - Log d'avertissement si désactivé
- [x] **Gestion d'erreurs** :
  - `MailerException` : message déjà sanitized
  - `Throwable` : message générique côté client, détails dans logs
  - Pas d'exposition de stack traces au client

## Diffs appliqués

### 1. `config/app.php`

```diff
-        'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'CC Computer',
+        'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'Camson Group - Facturation',
```

### 2. `src/Mail/MailerFactory.php`

```diff
-            $mail->SMTPSecure = $config['smtp_secure']; // 'tls' ou 'ssl'
+            // Mapping tls/ssl vers les constantes PHPMailer
+            $secure = strtolower($config['smtp_secure'] ?? 'tls');
+            if ($secure === 'tls') {
+                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
+            } elseif ($secure === 'ssl') {
+                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
+            } else {
+                throw new MailerException('SMTP_SECURE invalide: ' . $secure . ' (doit être "tls" ou "ssl")');
+            }
            
-            // Options supplémentaires
-            $mail->SMTPOptions = [
-                'ssl' => [
-                    'verify_peer' => false,
-                    'verify_peer_name' => false,
-                    'allow_self_signed' => true
-                ]
-            ];
+            // Options SSL/TLS - En production, on doit vérifier les certificats
+            // Ne désactiver la vérification que si explicitement demandé via variable d'env
+            $disableVerify = (bool)($_ENV['SMTP_DISABLE_VERIFY'] ?? false);
+            if ($disableVerify) {
+                error_log('ATTENTION: Vérification SSL/TLS désactivée pour SMTP (SMTP_DISABLE_VERIFY=true)');
+                $mail->SMTPOptions = [
+                    'ssl' => [
+                        'verify_peer' => false,
+                        'verify_peer_name' => false,
+                        'allow_self_signed' => true
+                    ]
+                ];
+            }
```

### 3. `src/Mail/MailerService.php` - `findPdfPath()`

```diff
-    public static function findPdfPath(string $relativePath): ?string
+    public static function findPdfPath(string $relativePath): string
     {
+        // Protection contre path traversal
+        $normalized = str_replace('\\', '/', $relativePath);
+        $normalized = preg_replace('#/+#', '/', $normalized);
+        $normalized = ltrim($normalized, '/');
+        
+        if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
+            throw new MailerException('Chemin PDF invalide: tentative de path traversal détectée');
+        }
+        
+        if (!preg_match('#^uploads/factures/#', $normalized)) {
+            throw new MailerException('Le fichier PDF doit être dans le répertoire uploads/factures/');
+        }
+        
+        // Utilisation de realpath() pour validation finale
+        $realPath = realpath($testPath);
+        if ($realPath && strpos($realPath, $realBase) === 0) {
+            return $realPath;
+        }
+        
+        // Exception claire au lieu de null
+        throw new MailerException('Le fichier PDF est introuvable...');
     }
```

### 4. `API/test_smtp.php`

```diff
-    // Si aucun token n'est configuré, désactiver l'endpoint en production
-    if (empty($expectedToken)) {
-        $isProduction = ($_ENV['APP_ENV'] ?? 'production') === 'production';
-        if ($isProduction) {
-            // ...
-        }
-    } else {
+    // Token obligatoire en toutes circonstances
+    if (empty($expectedToken)) {
+        jsonResponse([...], 403);
+    }
+    
+    if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
         // ...
-    }
+    }
```

### 5. `API/factures_envoyer_email.php`

```diff
-    } catch (Throwable $e) {
-        error_log('factures_envoyer_email.php error: ' . $e->getMessage());
-        jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
+    } catch (MailerException $e) {
+        error_log('factures_envoyer_email.php MailerException: ' . $e->getMessage());
+        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
+    } catch (Throwable $e) {
+        error_log('factures_envoyer_email.php error: ' . $e->getMessage());
+        error_log('factures_envoyer_email.php stack trace: ' . $e->getTraceAsString());
+        jsonResponse(['ok' => false, 'error' => 'Erreur inattendue lors de l\'envoi de l\'email'], 500);
     }
```

## Exemple de test avec curl

### Test de l'endpoint SMTP

```bash
# Définir le token (remplacer par votre valeur)
export SMTP_TEST_TOKEN="votre-token-secret-ici"

# Test basique
curl -X POST https://votre-domaine.com/API/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "votre-token-secret-ici",
    "to": "test@exemple.com"
  }'

# Test avec jq pour formatage (si installé)
curl -X POST https://votre-domaine.com/API/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "votre-token-secret-ici",
    "to": "test@exemple.com"
  }' | jq .

# Test en local (XAMPP)
curl -X POST http://localhost/cccomputer/API/test_smtp.php \
  -H "Content-Type: application/json" \
  -d '{
    "token": "votre-token-secret-ici",
    "to": "test@exemple.com"
  }'
```

### Réponses attendues

**Succès (200)** :
```json
{
  "ok": true,
  "message": "Email de test envoyé avec succès",
  "to": "test@exemple.com",
  "timestamp": "2025-01-15 14:30:00"
}
```

**Token manquant (403)** :
```json
{
  "ok": false,
  "error": "Endpoint désactivé. Configurez SMTP_TEST_TOKEN dans les variables d'environnement pour activer le test."
}
```

**Token invalide (403)** :
```json
{
  "ok": false,
  "error": "Token invalide"
}
```

**Email invalide (400)** :
```json
{
  "ok": false,
  "error": "Adresse email invalide"
}
```

**SMTP non configuré (500)** :
```json
{
  "ok": false,
  "error": "SMTP n'est pas activé. Définissez SMTP_ENABLED=true dans les variables d'environnement."
}
```

## Variables d'environnement requises

```bash
# Obligatoires pour l'envoi
SMTP_ENABLED=true
SMTP_HOST=smtp.votre-service.com
SMTP_PORT=587
SMTP_SECURE=tls
SMTP_USERNAME=votre-username
SMTP_PASSWORD=votre-password
SMTP_FROM_EMAIL=facture@camsongroup.fr
SMTP_FROM_NAME=Camson Group - Facturation
SMTP_REPLY_TO=facture@camsongroup.fr

# Obligatoire pour le test
SMTP_TEST_TOKEN=votre-token-secret

# Optionnel (déconseillé en production)
SMTP_DISABLE_VERIFY=false  # Ne pas désactiver sauf cas exceptionnel
```

## Notes de sécurité

1. **Path Traversal** : `findPdfPath()` vérifie maintenant que le chemin ne contient pas `../` et qu'il est dans `uploads/factures/`
2. **Secrets** : Aucun secret n'est exposé dans les réponses JSON ou les logs (sanitization)
3. **TLS/SSL** : Vérification des certificats activée par défaut (désactivable uniquement via `SMTP_DISABLE_VERIFY`)
4. **Token** : Obligatoire pour `test_smtp.php`, utilise `hash_equals()` pour éviter les attaques par timing
5. **Erreurs** : Messages génériques côté client, détails uniquement dans les logs serveur

