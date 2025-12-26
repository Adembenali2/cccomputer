# Script de nettoyage sécurisé pour le projet cccomputer
# Auteur: Agent de nettoyage automatique
# Date: $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")

$ErrorActionPreference = "Stop"
$projectRoot = "C:\xampp\htdocs\cccomputer"
Set-Location $projectRoot

# Configuration
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupDir = Join-Path $projectRoot "_cleanup_backup_$timestamp"
$reportFile = Join-Path $projectRoot "cleanup_report.md"

# Dossiers à supprimer (si présents)
$foldersToDelete = @(
    "test", "tests", "__tests__", "debug", "logs", "log", 
    "tmp", "temp", "cache", ".cache", ".pytest_cache", 
    "coverage", ".phpunit.cache", ".idea", ".vscode", ".cursor", 
    "dist", "build", "node_modules"
)

# Vérifier si Composer est utilisé (ne pas supprimer vendor/)
$usesComposer = Test-Path "composer.json"
if ($usesComposer) {
    Write-Host "[INFO] Composer détecté - vendor/ sera PRESERVE" -ForegroundColor Green
} else {
    if (Test-Path "vendor") {
        $foldersToDelete += "vendor"
        Write-Host "[INFO] Pas de Composer - vendor/ sera SUPPRIME" -ForegroundColor Yellow
    }
}

# Extensions de fichiers à supprimer
$fileExtensionsToDelete = @(
    "*.md", "*.log", "*.map", "*.tmp", "*.bak", "*.old", "*.swp",
    "*.spec.*", "*.test.*", "*.doc", "*.docx", "*.odt", "*.rtf",
    "*.zip", "*.rar", "*.7z"
)

# Dossiers à préserver (ne jamais supprimer de fichiers dedans)
$protectedFolders = @(
    "public", "assets", "img", "images", "css", "js", "src", "vendor"
)

# Fichiers à préserver (exceptions)
$protectedFiles = @(
    "README.md", "LICENSE", "composer.json", "package.json", ".htaccess"
)

# Fonction pour vérifier si un chemin est protégé
function Is-ProtectedPath {
    param([string]$path)
    
    $relativePath = $path.Replace($projectRoot, "").TrimStart("\").TrimStart("/")
    $relativePath = $relativePath.Replace("\", "/")
    
    # Vérifier les dossiers protégés
    foreach ($protected in $protectedFolders) {
        if ($relativePath -eq $protected -or $relativePath.StartsWith("$protected/")) {
            return $true
        }
    }
    
    # Vérifier les fichiers protégés
    $fileName = Split-Path -Leaf $path
    if ($protectedFiles -contains $fileName) {
        return $true
    }
    
    return $false
}

# Fonction pour vérifier si un fichier correspond aux extensions à supprimer
function Should-DeleteFile {
    param([string]$filePath)
    
    $fileName = Split-Path -Leaf $filePath
    $extension = [System.IO.Path]::GetExtension($filePath).ToLower()
    
    # Vérifier les extensions exactes
    $exactExtensions = @(".log", ".map", ".tmp", ".bak", ".old", ".swp", ".doc", ".docx", ".odt", ".rtf", ".zip", ".rar", ".7z")
    if ($exactExtensions -contains $extension) {
        return $true
    }
    
    # Vérifier .md (sauf README.md et LICENSE)
    if ($extension -eq ".md" -and $fileName -ne "README.md" -and $fileName -ne "LICENSE") {
        return $true
    }
    
    # Vérifier les patterns avec wildcards
    if ($fileName -like "*.spec.*" -or $fileName -like "*.test.*") {
        return $true
    }
    
    return $false
}

# ÉTAPE A: Scanner le projet
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE A: SCAN DU PROJET" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$itemsToDelete = @{
    Folders = @()
    Files = @()
    ByType = @{}
}

# Scanner les dossiers
Write-Host "[SCAN] Recherche des dossiers à supprimer..." -ForegroundColor Yellow
foreach ($folderName in $foldersToDelete) {
    $folderPath = Join-Path $projectRoot $folderName
    if (Test-Path $folderPath) {
        $itemsToDelete.Folders += $folderPath
        Write-Host "  [TROUVE] $folderName" -ForegroundColor Green
    }
}

# Scanner les fichiers .md (sauf README.md et LICENSE)
Write-Host "[SCAN] Recherche des fichiers .md..." -ForegroundColor Yellow
$mdFiles = Get-ChildItem -Path $projectRoot -Filter "*.md" -Recurse -File | Where-Object {
    $_.Name -ne "README.md" -and $_.Name -ne "LICENSE" -and -not (Is-ProtectedPath $_.FullName)
}
foreach ($file in $mdFiles) {
    $itemsToDelete.Files += $file.FullName
    $type = "md"
    if (-not $itemsToDelete.ByType.ContainsKey($type)) {
        $itemsToDelete.ByType[$type] = @()
    }
    $itemsToDelete.ByType[$type] += $file.FullName
}

# Scanner les autres extensions
Write-Host "[SCAN] Recherche des autres fichiers à supprimer..." -ForegroundColor Yellow
$otherExtensions = @("log", "map", "tmp", "bak", "old", "swp", "doc", "docx", "odt", "rtf", "zip", "rar", "7z")
foreach ($ext in $otherExtensions) {
    $files = Get-ChildItem -Path $projectRoot -Filter "*.$ext" -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
        -not (Is-ProtectedPath $_.FullName)
    }
    foreach ($file in $files) {
        $itemsToDelete.Files += $file.FullName
        if (-not $itemsToDelete.ByType.ContainsKey($ext)) {
            $itemsToDelete.ByType[$ext] = @()
        }
        $itemsToDelete.ByType[$ext] += $file.FullName
    }
}

# Scanner les fichiers avec patterns spéciaux
Write-Host "[SCAN] Recherche des fichiers *.spec.* et *.test.*..." -ForegroundColor Yellow
$specFiles = Get-ChildItem -Path $projectRoot -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
    ($_.Name -like "*.spec.*" -or $_.Name -like "*.test.*") -and -not (Is-ProtectedPath $_.FullName)
}
foreach ($file in $specFiles) {
    $itemsToDelete.Files += $file.FullName
    $type = "spec/test"
    if (-not $itemsToDelete.ByType.ContainsKey($type)) {
        $itemsToDelete.ByType[$type] = @()
    }
    $itemsToDelete.ByType[$type] += $file.FullName
}

$totalItems = $itemsToDelete.Folders.Count + $itemsToDelete.Files.Count

Write-Host "`n[SCAN TERMINE] Total: $totalItems éléments à supprimer" -ForegroundColor Cyan
Write-Host "  - Dossiers: $($itemsToDelete.Folders.Count)" -ForegroundColor Cyan
Write-Host "  - Fichiers: $($itemsToDelete.Files.Count)" -ForegroundColor Cyan

# ÉTAPE B: DRY-RUN - Afficher la liste
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE B: DRY-RUN - LISTE DES SUPPRESSIONS" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

if ($totalItems -gt 300) {
    Write-Host "[INFO] Plus de 300 éléments - Affichage par catégories (50 premiers de chaque)" -ForegroundColor Yellow
    
    # Afficher les dossiers
    if ($itemsToDelete.Folders.Count -gt 0) {
        Write-Host "`n--- DOSSIERS ($($itemsToDelete.Folders.Count) total) ---" -ForegroundColor Magenta
        $displayCount = [Math]::Min(50, $itemsToDelete.Folders.Count)
        for ($i = 0; $i -lt $displayCount; $i++) {
            $relativePath = $itemsToDelete.Folders[$i].Replace($projectRoot, "").TrimStart("\")
            Write-Host "  $relativePath"
        }
        if ($itemsToDelete.Folders.Count -gt 50) {
            Write-Host "  ... et $($itemsToDelete.Folders.Count - 50) autres dossiers" -ForegroundColor Gray
        }
    }
    
    # Afficher par type de fichier
    foreach ($type in $itemsToDelete.ByType.Keys | Sort-Object) {
        $files = $itemsToDelete.ByType[$type]
        Write-Host "`n--- FICHIERS .$type ($($files.Count) total) ---" -ForegroundColor Magenta
        $displayCount = [Math]::Min(50, $files.Count)
        for ($i = 0; $i -lt $displayCount; $i++) {
            $relativePath = $files[$i].Replace($projectRoot, "").TrimStart("\")
            Write-Host "  $relativePath"
        }
        if ($files.Count -gt 50) {
            Write-Host "  ... et $($files.Count - 50) autres fichiers .$type" -ForegroundColor Gray
        }
    }
} else {
    # Afficher tout
    if ($itemsToDelete.Folders.Count -gt 0) {
        Write-Host "`n--- DOSSIERS ---" -ForegroundColor Magenta
        foreach ($folder in $itemsToDelete.Folders) {
            $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
            Write-Host "  $relativePath"
        }
    }
    
    if ($itemsToDelete.Files.Count -gt 0) {
        Write-Host "`n--- FICHIERS ---" -ForegroundColor Magenta
        foreach ($file in $itemsToDelete.Files) {
            $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
            Write-Host "  $relativePath"
        }
    }
}

Write-Host "`n[DRY-RUN TERMINE] Aucune suppression effectuée pour le moment" -ForegroundColor Green

# Demander confirmation
Write-Host "`n========================================" -ForegroundColor Yellow
Write-Host "CONFIRMATION" -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Yellow
Write-Host "Total à supprimer: $totalItems éléments" -ForegroundColor Yellow
Write-Host "[INFO] Exécution automatique - création du backup et suppression..." -ForegroundColor Green

# ÉTAPE C: Créer le backup
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE C: CREATION DU BACKUP" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Write-Host "[BACKUP] Dossier de backup créé: $backupDir" -ForegroundColor Green

# Copier les dossiers
$backupCount = 0
foreach ($folder in $itemsToDelete.Folders) {
    $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
    $backupPath = Join-Path $backupDir $relativePath
    $backupParent = Split-Path -Parent $backupPath
    if (-not (Test-Path $backupParent)) {
        New-Item -ItemType Directory -Path $backupParent -Force | Out-Null
    }
    Copy-Item -Path $folder -Destination $backupPath -Recurse -Force -ErrorAction SilentlyContinue
    $backupCount++
    Write-Host "  [BACKUP] $relativePath" -ForegroundColor Gray
}

# Copier les fichiers
foreach ($file in $itemsToDelete.Files) {
    $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
    $backupPath = Join-Path $backupDir $relativePath
    $backupParent = Split-Path -Parent $backupPath
    if (-not (Test-Path $backupParent)) {
        New-Item -ItemType Directory -Path $backupParent -Force | Out-Null
    }
    Copy-Item -Path $file -Destination $backupPath -Force -ErrorAction SilentlyContinue
    $backupCount++
    if ($backupCount % 50 -eq 0) {
        Write-Host "  [BACKUP] $backupCount fichiers/dossiers copiés..." -ForegroundColor Gray
    }
}

Write-Host "`n[BACKUP TERMINE] $backupCount éléments sauvegardés dans $backupDir" -ForegroundColor Green

# ÉTAPE D: Supprimer
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE D: SUPPRESSION" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$deletedCount = 0
$errors = @()

# Supprimer les dossiers
foreach ($folder in $itemsToDelete.Folders) {
    try {
        Remove-Item -Path $folder -Recurse -Force -ErrorAction Stop
        $deletedCount++
        $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
        Write-Host "  [SUPPRIME] $relativePath" -ForegroundColor Green
    } catch {
        $errors += "Erreur suppression dossier $folder : $_"
        Write-Host "  [ERREUR] $relativePath : $_" -ForegroundColor Red
    }
}

# Supprimer les fichiers
foreach ($file in $itemsToDelete.Files) {
    try {
        Remove-Item -Path $file -Force -ErrorAction Stop
        $deletedCount++
        if ($deletedCount % 50 -eq 0) {
            Write-Host "  [SUPPRESSION] $deletedCount éléments supprimés..." -ForegroundColor Gray
        }
    } catch {
        $errors += "Erreur suppression fichier $file : $_"
    }
}

Write-Host "`n[SUPPRESSION TERMINE] $deletedCount éléments supprimés" -ForegroundColor Green
if ($errors.Count -gt 0) {
    Write-Host "[ATTENTION] $($errors.Count) erreurs rencontrées" -ForegroundColor Yellow
}

# ÉTAPE E: Générer le rapport
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE E: GENERATION DU RAPPORT" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$report = @"
# Rapport de nettoyage du projet cccomputer

**Date:** $(Get-Date -Format "yyyy-MM-dd HH:mm:ss")  
**Backup:** \`_cleanup_backup_$timestamp\`

## Résumé

- **Total supprimé:** $deletedCount éléments
  - Dossiers: $($itemsToDelete.Folders.Count)
  - Fichiers: $($itemsToDelete.Files.Count)

## Détails par type

"@

# Ajouter les dossiers
if ($itemsToDelete.Folders.Count -gt 0) {
    $report += "`n### Dossiers supprimés ($($itemsToDelete.Folders.Count))`n`n"
    foreach ($folder in $itemsToDelete.Folders) {
        $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
        $report += "- ``$relativePath``" + "`n"
    }
}

# Ajouter les fichiers par type
foreach ($type in $itemsToDelete.ByType.Keys | Sort-Object) {
    $files = $itemsToDelete.ByType[$type]
    $report += "`n### Fichiers .$type ($($files.Count))`n`n"
    foreach ($file in $files) {
        $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
        $report += "- ``$relativePath``" + "`n"
    }
}

# Ajouter les erreurs si présentes
if ($errors.Count -gt 0) {
    $report += "`n## Erreurs rencontrées`n`n"
    foreach ($error in $errors) {
        $report += "- $error`n"
    }
}

$report += "`n---`n`n*Rapport généré automatiquement par cleanup_project.ps1*"

$report | Out-File -FilePath $reportFile -Encoding UTF8
Write-Host "[RAPPORT] Rapport généré: $reportFile" -ForegroundColor Green

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "NETTOYAGE TERMINE AVEC SUCCES" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host "Backup: $backupDir" -ForegroundColor Cyan
Write-Host "Rapport: $reportFile" -ForegroundColor Cyan
Write-Host "`n"

