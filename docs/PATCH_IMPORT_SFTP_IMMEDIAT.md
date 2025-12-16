# Patch : Import SFTP immédiat au chargement du dashboard

## 📋 Résumé des modifications

### Objectif
Déclencher l'import SFTP **immédiatement** au chargement du dashboard (sans attendre "due"), tout en conservant la logique "if_due" toutes les 20 secondes.

### Fichiers modifiés
1. `public/dashboard.php` - JavaScript amélioré avec toast et appel immédiat
2. `import/run_import_if_due.php` - Gestion améliorée du paramètre `force=1` et réponses JSON détaillées

---

## 🔧 Patch pour `public/dashboard.php`

### Modifications apportées

1. **Ajout d'une fonction `showToast()`** pour afficher des notifications visibles
2. **Amélioration de `callJSON()`** :
   - Ajout de `credentials: 'same-origin'` et `cache: 'no-store'`
   - Logging détaillé dans la console
   - Affichage de toasts pour les erreurs (401, 403, 500, not_due, locked)
3. **Appel immédiat au chargement** avec `force=1`
4. **Modification de `tick()`** pour accepter un paramètre `showErrors`

### Code modifié (lignes ~1570-1664)

```javascript
// --- Import auto silencieux SFTP + badge (tick 20s, batch 10) ---
(function(){
    const SFTP_URL  = '/import/run_import_if_due.php';

    const badge = document.getElementById('importBadge');
    const ico   = document.getElementById('impIco');
    const txt   = document.getElementById('impTxt');

    // Fonction pour afficher un toast/notification
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            background: ${type === 'error' ? '#fee2e2' : type === 'success' ? '#d1fae5' : '#dbeafe'};
            color: ${type === 'error' ? '#dc2626' : type === 'success' ? '#065f46' : '#1e40af'};
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            max-width: 400px;
            font-size: 0.9rem;
            animation: slideIn 0.3s ease-out;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transition = 'opacity 0.3s';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }

    // ... setState() reste identique ...

    async function callJSON(url, showErrors = false){
        try{
            const res = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Cache-Control': 'no-cache'
                }
            });
            const text = await res.text();
            let data = null;
            try { 
                data = text ? JSON.parse(text) : null; 
            } catch(e){
                console.error(`[IMPORT] Erreur parsing JSON:`, e, text);
            }
            
            // Logger dans la console
            console.log(`[IMPORT] ${url} → ${res.status}`, data);
            
            if(!res.ok){
                const errorMsg = data?.error || data?.message || `HTTP ${res.status} ${res.statusText}`;
                console.error(`[IMPORT] ${url} → ${res.status} ${res.statusText}`, data || text);
                
                if (showErrors) {
                    if (res.status === 401 || res.status === 403) {
                        showToast(`Import SFTP: Erreur d'authentification (${res.status})`, 'error');
                    } else if (res.status === 500) {
                        showToast(`Import SFTP: Erreur serveur (${errorMsg})`, 'error');
                    } else {
                        showToast(`Import SFTP: ${errorMsg}`, 'error');
                    }
                }
                
                return { 
                    ok: false, 
                    status: res.status, 
                    body: data || text,
                    reason: data?.reason || 'http_error',
                    error: errorMsg
                };
            }
            
            // Afficher un toast si "not_due" ou "locked"
            if (showErrors && data) {
                if (data.reason === 'not_due') {
                    const nextDue = data.next_due_in_sec || 0;
                    const nextDueMin = Math.ceil(nextDue / 60);
                    showToast(`Import SFTP: Déjà exécuté récemment (prochain dans ${nextDueMin} min)`, 'info');
                } else if (data.reason === 'locked') {
                    showToast('Import SFTP: Un import est déjà en cours', 'info');
                } else if (data.ok && data.ran) {
                    const count = (data.inserted || 0) + (data.updated || 0);
                    if (count > 0) {
                        showToast(`Import SFTP: ${count} élément(s) traité(s)`, 'success');
                    }
                }
            }
            
            return { ok: true, status: res.status, body: data };
        }catch(err){
            console.error(`[IMPORT] ${url} → fetch failed`, err);
            if (showErrors) {
                showToast(`Import SFTP: Erreur de connexion (${err.message})`, 'error');
            }
            return { ok: false, error: String(err) };
        }
    }

    // ... refresh() reste identique ...

    async function tick(showErrors = false){
        const result = await callJSON(SFTP_URL + '?limit=20', showErrors);
        setTimeout(refresh, 1500);
        return result;
    }

    // Appel immédiat au chargement avec force=1 (bypass "due" mais garde auth + lock)
    (async function immediateImport(){
        console.log('[IMPORT] Déclenchement immédiat de l\'import SFTP (force=1)');
        setState('run', 'Import SFTP : démarrage...');
        const result = await callJSON(SFTP_URL + '?limit=20&force=1', true);
        
        if (result.ok && result.body && result.body.ran) {
            console.log('[IMPORT] Import immédiat lancé avec succès', result.body);
            setTimeout(refresh, 2000); // Rafraîchir le badge après 2 secondes
        } else {
            console.warn('[IMPORT] Import immédiat non lancé', result);
            // Rafraîchir quand même pour afficher l'état actuel
            setTimeout(refresh, 1000);
        }
    })();
    
    refresh();     // premier badge (état actuel)
    setInterval(() => tick(false), 20000); // toutes les 20s (sans afficher les erreurs)
})();
```

---

## 🔧 Patch pour `import/run_import_if_due.php`

### Modifications apportées

1. **Amélioration de la gestion de `force=1`** :
   - Bypass la vérification "due" mais conserve auth + lock
   - Calcul de `next_due_in_sec` pour la réponse JSON

2. **Réponses JSON détaillées** avec :
   - `reason` : not_due, locked, started, auth_failed, script_not_found, timeout, script_error
   - `last_run_at` : timestamp de la dernière exécution
   - `next_due_in_sec` : secondes avant la prochaine exécution due
   - `message` : message descriptif

3. **Libération garantie du lock** :
   - Tous les `exit` appellent `$releaseLock()`
   - Try/finally équivalent à la fin du script

### Code modifié (extraits clés)

#### 1. Gestion améliorée de `force=1` (lignes ~115-144)

```php
// Vérifier le mode force (debug) - support GET et POST
$force = (isset($_GET['force']) && $_GET['force'] === '1') || (isset($_POST['force']) && $_POST['force'] === '1');
$forced = false;

$stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmt->execute([$key]);
$last = $stmt->fetchColumn();
$lastTimestamp = $last ? strtotime((string)$last) : 0;
$elapsed = time() - $lastTimestamp;
$due  = $elapsed >= $INTERVAL;
$nextDueInSec = $due ? 0 : ($INTERVAL - $elapsed);

// Si force=1, ignorer le check not_due mais conserver le lock et l'auth
if (!$due && !$force) {
  $releaseLock();
  echo json_encode([
    'ok' => false, 
    'ran' => false,
    'reason' => 'not_due', 
    'last_run' => $last,
    'last_run_at' => $last ? date('Y-m-d H:i:s', $lastTimestamp) : null,
    'next_due_in_sec' => $nextDueInSec,
    'forced' => false,
    'message' => "Import non dû (dernier: $last, interval: {$INTERVAL}s, écoulé: {$elapsed}s)"
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

// Si force=1 et not_due, on force l'exécution (bypass "due" mais garde auth + lock)
if (!$due && $force) {
  $forced = true;
  debugLog("Mode FORCE activé - import exécuté même si not_due", [
    'last_run' => $last,
    'interval' => $INTERVAL,
    'elapsed' => $elapsed,
    'next_due_in_sec' => $nextDueInSec
  ]);
}
```

#### 2. Réponse JSON améliorée (lignes ~350-371)

```php
// Calculer next_due_in_sec pour la réponse
$stmtNext = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmtNext->execute([$key]);
$nextLast = $stmtNext->fetchColumn();
$nextLastTimestamp = $nextLast ? strtotime((string)$nextLast) : 0;
$nextElapsed = time() - $nextLastTimestamp;
$nextDueInSec = ($INTERVAL - $nextElapsed) > 0 ? ($INTERVAL - $nextElapsed) : 0;

// Réponse JSON structurée
$response = [
  'ok'           => $success,
  'ran'          => true,
  'forced'       => $forced,
  'reason'        => $success ? 'started' : ($timeoutReached ? 'timeout' : 'script_error'),
  'inserted'     => $inserted,
  'updated'      => $updated,
  'skipped'      => $skipped,
  'errors'       => $errors,
  'stdout'       => trim($out),
  'stderr'       => trim($err),
  'last_run'     => date('Y-m-d H:i:s'),
  'last_run_at'  => date('Y-m-d H:i:s'),
  'next_due_in_sec' => $nextDueInSec,
  'code'         => $code,
  'timeout'      => $timeoutReached,
  'error'        => $errorMsg,
  'duration_ms'  => (time() - $startTime) * 1000,
  'message'      => $success 
    ? "Import SFTP exécuté avec succès (inséré: $inserted, mis à jour: $updated, ignoré: $skipped)"
    : ($errorMsg ?: "Import SFTP échoué (code: $code)")
];
```

#### 3. Libération garantie du lock (tous les exit)

Tous les `exit` appellent `$releaseLock()` avant de quitter, et un try/finally équivalent à la fin garantit la libération même en cas d'exception.

---

## 📄 Exemples de réponses JSON

### 1. Succès (import lancé)

```json
{
  "ok": true,
  "ran": true,
  "forced": true,
  "reason": "started",
  "inserted": 5,
  "updated": 2,
  "skipped": 0,
  "errors": [],
  "stdout": "✅ Connexion à la base établie.\n✅ Connexion SFTP établie.\n...",
  "stderr": "",
  "last_run": "2024-01-15 10:30:00",
  "last_run_at": "2024-01-15 10:30:00",
  "next_due_in_sec": 280,
  "code": 0,
  "timeout": false,
  "error": null,
  "duration_ms": 5234,
  "message": "Import SFTP exécuté avec succès (inséré: 5, mis à jour: 2, ignoré: 0)"
}
```

### 2. Not due (sans force=1)

```json
{
  "ok": false,
  "ran": false,
  "reason": "not_due",
  "last_run": "2024-01-15 10:28:00",
  "last_run_at": "2024-01-15 10:28:00",
  "next_due_in_sec": 15,
  "forced": false,
  "message": "Import non dû (dernier: 2024-01-15 10:28:00, interval: 20s, écoulé: 5s)"
}
```

### 3. Locked (import déjà en cours)

```json
{
  "ok": false,
  "ran": false,
  "reason": "locked",
  "message": "Un import est déjà en cours (verrou MySQL actif)",
  "last_run_at": null,
  "next_due_in_sec": null
}
```

### 4. Auth failed (401/403)

```json
{
  "ok": false,
  "ran": false,
  "reason": "auth_failed",
  "error": "Erreur chargement auth.php: Session expirée",
  "message": "Erreur d'authentification"
}
```

### 5. Script not found (500)

```json
{
  "ok": false,
  "ran": false,
  "reason": "script_not_found",
  "error": "Script upload_compteur.php introuvable",
  "path": "/var/www/cccomputer/API/scripts/upload_compteur.php",
  "message": "Script d'import introuvable"
}
```

### 6. Timeout

```json
{
  "ok": false,
  "ran": true,
  "forced": false,
  "reason": "timeout",
  "inserted": 0,
  "updated": 0,
  "skipped": 0,
  "errors": ["TIMEOUT: Le processus d'import SFTP a dépassé la limite de 60 secondes"],
  "code": -1,
  "timeout": true,
  "error": "TIMEOUT: Le processus d'import SFTP a dépassé la limite de 60 secondes",
  "duration_ms": 60000,
  "message": "TIMEOUT: Le processus d'import SFTP a dépassé la limite de 60 secondes"
}
```

---

## 🧪 Plan de test

### Test 1 : Import immédiat au chargement

**Étapes** :
1. Ouvrir le dashboard dans un navigateur
2. Ouvrir la console (F12)
3. Vérifier les logs console

**Résultat attendu** :
- ✅ Console : `[IMPORT] Déclenchement immédiat de l'import SFTP (force=1)`
- ✅ Console : `[IMPORT] /import/run_import_if_due.php?limit=20&force=1 → 200`
- ✅ Toast visible : "Import SFTP: X élément(s) traité(s)" (si succès)
- ✅ Badge mis à jour après 2 secondes

**Vérification DB** :
```sql
SELECT * FROM import_run 
WHERE msg LIKE '%"source":"SFTP"%' 
ORDER BY ran_at DESC 
LIMIT 1;
```

### Test 2 : Vérification "not_due" (sans force)

**Étapes** :
1. Attendre 5 secondes après le chargement
2. Ouvrir la console
3. Observer le prochain `tick()` (toutes les 20s)

**Résultat attendu** :
- ✅ Console : `[IMPORT] /import/run_import_if_due.php?limit=20 → 200`
- ✅ Réponse JSON avec `reason: "not_due"` si moins de 20s écoulées
- ✅ Pas de toast (showErrors=false pour les ticks réguliers)

### Test 3 : Test avec lock (double exécution)

**Étapes** :
1. Ouvrir deux onglets du dashboard simultanément
2. Observer les réponses

**Résultat attendu** :
- ✅ Premier onglet : Import lancé avec succès
- ✅ Deuxième onglet : Toast "Import SFTP: Un import est déjà en cours"
- ✅ Réponse JSON avec `reason: "locked"`

### Test 4 : Test erreur 401/403

**Étapes** :
1. Expirer la session (attendre ou supprimer le cookie)
2. Recharger le dashboard
3. Observer la réponse

**Résultat attendu** :
- ✅ Console : `[IMPORT] /import/run_import_if_due.php?limit=20&force=1 → 401` ou `403`
- ✅ Toast rouge : "Import SFTP: Erreur d'authentification (401)"
- ✅ Réponse JSON avec `reason: "auth_failed"`

### Test 5 : Test erreur 500

**Étapes** :
1. Renommer temporairement `API/scripts/upload_compteur.php`
2. Recharger le dashboard
3. Observer la réponse

**Résultat attendu** :
- ✅ Console : `[IMPORT] /import/run_import_if_due.php?limit=20&force=1 → 500`
- ✅ Toast rouge : "Import SFTP: Erreur serveur (Script d'import introuvable)"
- ✅ Réponse JSON avec `reason: "script_not_found"`

### Test 6 : Vérification DB import_run

**Requête SQL** :
```sql
SELECT 
    ran_at,
    imported,
    skipped,
    ok,
    JSON_EXTRACT(msg, '$.source') as source,
    JSON_EXTRACT(msg, '$.stage') as stage,
    JSON_EXTRACT(msg, '$.inserted') as inserted,
    JSON_EXTRACT(msg, '$.updated') as updated
FROM import_run
WHERE msg LIKE '%"source":"SFTP"%'
ORDER BY ran_at DESC
LIMIT 10;
```

**Vérifications** :
- ✅ `ok = 1` pour les imports réussis
- ✅ `imported > 0` ou `updated > 0` si des fichiers ont été traités
- ✅ `ran_at` correspond aux exécutions récentes

---

## ✅ Checklist de validation

- [ ] Import se déclenche immédiatement au chargement du dashboard
- [ ] Toast visible pour les erreurs (401, 403, 500, not_due, locked)
- [ ] Toast visible pour les succès (si éléments traités)
- [ ] Console loggée avec status et réponse JSON
- [ ] Badge mis à jour après l'import
- [ ] Logique "if_due" fonctionne toutes les 20 secondes
- [ ] Pas de double-run (lock fonctionne)
- [ ] Lock toujours relâché (vérifier avec `SHOW PROCESSLIST` MySQL)
- [ ] DB `import_run` contient les entrées attendues

---

## 🔍 Debugging

### Vérifier le lock MySQL

```sql
SHOW PROCESSLIST;
-- Chercher les processus avec GET_LOCK('import_compteur_sftp', ...)
```

### Vérifier les logs PHP

```bash
tail -f /var/log/php_errors.log | grep "run_import_if_due"
```

### Vérifier les cookies

Dans la console du navigateur :
```javascript
console.log(document.cookie);
// Vérifier que les cookies de session sont présents
```

---

## 📚 Notes techniques

1. **Credentials** : `credentials: 'same-origin'` garantit l'envoi des cookies de session
2. **Cache** : `cache: 'no-store'` évite les problèmes de cache navigateur
3. **Lock** : Le verrou MySQL (`GET_LOCK`) empêche les exécutions parallèles
4. **Force** : `force=1` bypass "due" mais conserve auth + lock pour la sécurité
5. **Toast** : Auto-dismiss après 4 secondes, animation slide-in

---

**Fin du document**

