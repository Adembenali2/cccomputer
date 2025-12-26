# Script de nettoyage v2 pour le projet cccomputer
# Règles: Supprimer certains fichiers/dossiers, DÉPLACER les .md dans _docs_archive/

$ErrorActionPreference = "Stop"
$projectRoot = "C:\xampp\htdocs\cccomputer"
Set-Location $projectRoot

# Configuration
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$backupDir = Join-Path $projectRoot "_cleanup_backup_$timestamp"
$docsArchiveDir = Join-Path $projectRoot "_docs_archive"
$reportFile = Join-Path $projectRoot "cleanup_report.md"

# Dossiers à supprimer (selon les règles)
$foldersToDelete = @(
    "cache", "tmp", "temp", "logs", "debug", "tests", "__tests__", 
    "coverage", ".pytest_cache", ".phpunit.cache"
)

# Extensions de fichiers à supprimer (selon les règles)
$fileExtensionsToDelete = @(
    "*.log", "*.tmp", "*.bak", "*.old", "*.swp", "*.map", 
    "*.spec.*", "*.test.*", "*.doc", "*.docx", "*.odt", "*.rtf"
)

# Dossiers à préserver (ne jamais toucher)
$protectedFolders = @(
    "public", "assets", "img", "images", "css", "js", "src", 
    "vendor", "node_modules", "_docs_archive", "_cleanup_backup_*"
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
        if ($protected -like "*_*") {
            # Pattern avec wildcard
            if ($relativePath -like $protected.Replace("*", "*")) {
                return $true
            }
        } else {
            if ($relativePath -eq $protected -or $relativePath.StartsWith("$protected/")) {
                return $true
            }
        }
    }
    
    # Vérifier les fichiers protégés
    $fileName = Split-Path -Leaf $path
    if ($protectedFiles -contains $fileName) {
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

$itemsToMove = @{
    Files = @()
    ByType = @{}
}

# Scanner les dossiers à supprimer
Write-Host "[SCAN] Recherche des dossiers à supprimer..." -ForegroundColor Yellow
foreach ($folderName in $foldersToDelete) {
    $folderPath = Join-Path $projectRoot $folderName
    if (Test-Path $folderPath) {
        if (-not (Is-ProtectedPath $folderPath)) {
            $itemsToDelete.Folders += $folderPath
            Write-Host "  [TROUVE] $folderName" -ForegroundColor Green
        } else {
            Write-Host "  [PROTEGE] $folderName (ignoré)" -ForegroundColor Gray
        }
    }
}

# Scanner les fichiers .md à DÉPLACER (pas supprimer)
Write-Host "[SCAN] Recherche des fichiers .md à déplacer..." -ForegroundColor Yellow
$mdFiles = Get-ChildItem -Path $projectRoot -Filter "*.md" -Recurse -File -ErrorAction SilentlyContinue | Where-Object {
    $_.Name -ne "README.md" -and $_.Name -ne "LICENSE" -and -not (Is-ProtectedPath $_.FullName)
}
foreach ($file in $mdFiles) {
    $itemsToMove.Files += $file.FullName
    if (-not $itemsToMove.ByType.ContainsKey("md")) {
        $itemsToMove.ByType["md"] = @()
    }
    $itemsToMove.ByType["md"] += $file.FullName
}
Write-Host "  [TROUVE] $($mdFiles.Count) fichiers .md à déplacer" -ForegroundColor Green

# Scanner les autres fichiers à supprimer
Write-Host "[SCAN] Recherche des autres fichiers à supprimer..." -ForegroundColor Yellow
$otherExtensions = @("log", "tmp", "bak", "old", "swp", "map", "doc", "docx", "odt", "rtf")
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

# Scanner les fichiers avec patterns spéciaux (*.spec.*, *.test.*)
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

$totalToDelete = $itemsToDelete.Folders.Count + $itemsToDelete.Files.Count
$totalToMove = $itemsToMove.Files.Count
$totalItems = $totalToDelete + $totalToMove

Write-Host "`n[SCAN TERMINE] Total: $totalItems éléments" -ForegroundColor Cyan
Write-Host "  - À supprimer: $totalToDelete (dossiers: $($itemsToDelete.Folders.Count), fichiers: $($itemsToDelete.Files.Count))" -ForegroundColor Cyan
Write-Host "  - À déplacer (.md): $totalToMove" -ForegroundColor Cyan

# ÉTAPE B: DRY-RUN - Afficher la liste
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE B: DRY-RUN - LISTE DES MODIFICATIONS" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

if ($totalItems -gt 300) {
    Write-Host "[INFO] Plus de 300 éléments - Affichage par catégories (50 premiers de chaque)" -ForegroundColor Yellow
    
    # Afficher les dossiers à supprimer
    if ($itemsToDelete.Folders.Count -gt 0) {
        Write-Host "`n--- DOSSIERS À SUPPRIMER ($($itemsToDelete.Folders.Count) total) ---" -ForegroundColor Magenta
        $displayCount = [Math]::Min(50, $itemsToDelete.Folders.Count)
        for ($i = 0; $i -lt $displayCount; $i++) {
            $relativePath = $itemsToDelete.Folders[$i].Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [SUPPRIMER] $relativePath"
        }
        if ($itemsToDelete.Folders.Count -gt 50) {
            Write-Host "  ... et $($itemsToDelete.Folders.Count - 50) autres dossiers" -ForegroundColor Gray
        }
    }
    
    # Afficher les fichiers à supprimer par type
    foreach ($type in $itemsToDelete.ByType.Keys | Sort-Object) {
        $files = $itemsToDelete.ByType[$type]
        Write-Host "`n--- FICHIERS .$type À SUPPRIMER ($($files.Count) total) ---" -ForegroundColor Magenta
        $displayCount = [Math]::Min(50, $files.Count)
        for ($i = 0; $i -lt $displayCount; $i++) {
            $relativePath = $files[$i].Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [SUPPRIMER] $relativePath"
        }
        if ($files.Count -gt 50) {
            Write-Host "  ... et $($files.Count - 50) autres fichiers .$type" -ForegroundColor Gray
        }
    }
    
    # Afficher les fichiers .md à déplacer
    if ($itemsToMove.Files.Count -gt 0) {
        Write-Host "`n--- FICHIERS .md À DÉPLACER ($($itemsToMove.Files.Count) total) ---" -ForegroundColor Yellow
        $displayCount = [Math]::Min(50, $itemsToMove.Files.Count)
        for ($i = 0; $i -lt $displayCount; $i++) {
            $relativePath = $itemsToMove.Files[$i].Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [DÉPLACER] $relativePath -> _docs_archive/$relativePath"
        }
        if ($itemsToMove.Files.Count -gt 50) {
            Write-Host "  ... et $($itemsToMove.Files.Count - 50) autres fichiers .md" -ForegroundColor Gray
        }
    }
} else {
    # Afficher tout
    if ($itemsToDelete.Folders.Count -gt 0) {
        Write-Host "`n--- DOSSIERS À SUPPRIMER ---" -ForegroundColor Magenta
        foreach ($folder in $itemsToDelete.Folders) {
            $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [SUPPRIMER] $relativePath"
        }
    }
    
    if ($itemsToDelete.Files.Count -gt 0) {
        Write-Host "`n--- FICHIERS À SUPPRIMER ---" -ForegroundColor Magenta
        foreach ($file in $itemsToDelete.Files) {
            $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [SUPPRIMER] $relativePath"
        }
    }
    
    if ($itemsToMove.Files.Count -gt 0) {
        Write-Host "`n--- FICHIERS .md À DÉPLACER ---" -ForegroundColor Yellow
        foreach ($file in $itemsToMove.Files) {
            $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
            Write-Host "  [DÉPLACER] $relativePath -> _docs_archive/$relativePath"
        }
    }
}

Write-Host "`n[DRY-RUN TERMINE] Aucune modification effectuée pour le moment" -ForegroundColor Green

# ÉTAPE C: Créer le backup
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE C: CREATION DU BACKUP" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

New-Item -ItemType Directory -Path $backupDir -Force | Out-Null
Write-Host "[BACKUP] Dossier de backup créé: $backupDir" -ForegroundColor Green

# Copier les dossiers à supprimer
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

# Copier les fichiers à supprimer
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

# Copier les fichiers .md à déplacer
foreach ($file in $itemsToMove.Files) {
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

# ÉTAPE D: Déplacer les fichiers .md
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE D: DEPLACEMENT DES FICHIERS .md" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$movedCount = 0
$moveErrors = @()

# Créer le dossier _docs_archive s'il n'existe pas
if (-not (Test-Path $docsArchiveDir)) {
    New-Item -ItemType Directory -Path $docsArchiveDir -Force | Out-Null
    Write-Host "[ARCHIVE] Dossier _docs_archive créé" -ForegroundColor Green
}

foreach ($file in $itemsToMove.Files) {
    try {
        $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
        $archivePath = Join-Path $docsArchiveDir $relativePath
        $archiveParent = Split-Path -Parent $archivePath
        if (-not (Test-Path $archiveParent)) {
            New-Item -ItemType Directory -Path $archiveParent -Force | Out-Null
        }
        Move-Item -Path $file -Destination $archivePath -Force -ErrorAction Stop
        $movedCount++
        if ($movedCount % 10 -eq 0) {
            Write-Host "  [DEPLACE] $movedCount fichiers déplacés..." -ForegroundColor Gray
        }
    } catch {
        $moveErrors += "Erreur déplacement $file : $_"
        Write-Host "  [ERREUR] $relativePath : $_" -ForegroundColor Red
    }
}

Write-Host "`n[DEPLACEMENT TERMINE] $movedCount fichiers .md déplacés dans _docs_archive/" -ForegroundColor Green
if ($moveErrors.Count -gt 0) {
    Write-Host "[ATTENTION] $($moveErrors.Count) erreurs rencontrées" -ForegroundColor Yellow
}

# ÉTAPE E: Supprimer les fichiers et dossiers
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE E: SUPPRESSION" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$deletedCount = 0
$deleteErrors = @()

# Supprimer les dossiers
foreach ($folder in $itemsToDelete.Folders) {
    try {
        Remove-Item -Path $folder -Recurse -Force -ErrorAction Stop
        $deletedCount++
        $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
        Write-Host "  [SUPPRIME] $relativePath" -ForegroundColor Green
    } catch {
        $deleteErrors += "Erreur suppression dossier $folder : $_"
        $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
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
        $deleteErrors += "Erreur suppression fichier $file : $_"
    }
}

Write-Host "`n[SUPPRESSION TERMINE] $deletedCount éléments supprimés" -ForegroundColor Green
if ($deleteErrors.Count -gt 0) {
    Write-Host "[ATTENTION] $($deleteErrors.Count) erreurs rencontrées" -ForegroundColor Yellow
}

# ÉTAPE F: Générer le rapport
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host "ETAPE F: GENERATION DU RAPPORT" -ForegroundColor Cyan
Write-Host "========================================`n" -ForegroundColor Cyan

$reportDate = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
$report = @"
# Rapport de nettoyage du projet cccomputer

**Date:** $reportDate  
**Backup:** ``_cleanup_backup_$timestamp``

## Résumé

- **Total modifié:** $totalItems éléments
  - Supprimés: $deletedCount (dossiers: $($itemsToDelete.Folders.Count), fichiers: $($itemsToDelete.Files.Count))
  - Déplacés (.md): $movedCount

## Détails par type

"@

# Ajouter les dossiers supprimés
if ($itemsToDelete.Folders.Count -gt 0) {
    $report += "`n### Dossiers supprimés ($($itemsToDelete.Folders.Count))`n`n"
    foreach ($folder in $itemsToDelete.Folders) {
        $relativePath = $folder.Replace($projectRoot, "").TrimStart("\")
        $report += "- ``$relativePath``" + "`n"
    }
}

# Ajouter les fichiers supprimés par type
foreach ($type in $itemsToDelete.ByType.Keys | Sort-Object) {
    $files = $itemsToDelete.ByType[$type]
    $report += "`n### Fichiers .$type supprimés ($($files.Count))`n`n"
    foreach ($file in $files) {
        $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
        $report += "- ``$relativePath``" + "`n"
    }
}

# Ajouter les fichiers .md déplacés
if ($itemsToMove.Files.Count -gt 0) {
    $report += "`n### Fichiers .md déplacés dans _docs_archive/ ($($itemsToMove.Files.Count))`n`n"
    foreach ($file in $itemsToMove.Files) {
        $relativePath = $file.Replace($projectRoot, "").TrimStart("\")
        $report += "- ``$relativePath`` -> ``_docs_archive/$relativePath``" + "`n"
    }
}

# Ajouter les erreurs si présentes
$allErrors = $moveErrors + $deleteErrors
if ($allErrors.Count -gt 0) {
    $report += "`n## Erreurs rencontrées`n`n"
    foreach ($error in $allErrors) {
        $report += "- $error`n"
    }
}

$report += "`n---`n`n*Rapport généré automatiquement par cleanup_project_v2.ps1*"

$report | Out-File -FilePath $reportFile -Encoding UTF8
Write-Host "[RAPPORT] Rapport généré: $reportFile" -ForegroundColor Green

Write-Host "`n========================================" -ForegroundColor Green
Write-Host "NETTOYAGE TERMINE AVEC SUCCES" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
Write-Host "Backup: $backupDir" -ForegroundColor Cyan
Write-Host "Archive docs: $docsArchiveDir" -ForegroundColor Cyan
Write-Host "Rapport: $reportFile" -ForegroundColor Cyan
Write-Host "`n"

