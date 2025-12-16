#!/bin/bash
# Script wrapper pour l'import SFTP en CLI
# Usage: ./scripts/run_sftp_import.sh
# Ce script peut être appelé directement ou via cron/systemd

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="$(which php)"
LOG_DIR="$PROJECT_ROOT/logs"
LOG_FILE="$LOG_DIR/sftp_import.log"
ERROR_LOG="$LOG_DIR/sftp_import_error.log"
IMPORT_SCRIPT="$PROJECT_ROOT/API/scripts/upload_compteur.php"

# Créer le répertoire de logs s'il n'existe pas
mkdir -p "$LOG_DIR"

# Fonction de logging
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"
}

log_error() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] ERROR: $1" | tee -a "$ERROR_LOG" | tee -a "$LOG_FILE"
}

# Vérifier que PHP est disponible
if [ ! -f "$PHP_BIN" ]; then
    log_error "PHP introuvable. Vérifiez que PHP est installé et dans le PATH."
    exit 1
fi

# Vérifier que le script d'import existe
if [ ! -f "$IMPORT_SCRIPT" ]; then
    log_error "Script d'import introuvable: $IMPORT_SCRIPT"
    exit 1
fi

# Charger les variables d'environnement depuis .env si présent
ENV_FILE="$PROJECT_ROOT/.env"
if [ -f "$ENV_FILE" ]; then
    log "Chargement des variables d'environnement depuis .env"
    # Source le fichier .env en exportant les variables
    set -a
    source "$ENV_FILE"
    set +a
else
    log "Fichier .env non trouvé, utilisation des variables d'environnement système"
fi

# Vérifier que les variables SFTP essentielles sont présentes
if [ -z "$SFTP_HOST" ] || [ -z "$SFTP_USER" ] || [ -z "$SFTP_PASS" ]; then
    log_error "Variables d'environnement SFTP manquantes (SFTP_HOST, SFTP_USER, SFTP_PASS)"
    log_error "Assurez-vous que ces variables sont définies dans .env ou dans l'environnement système"
    exit 1
fi

# Exécuter le script d'import
log "=== Démarrage de l'import SFTP ==="
log "PHP: $PHP_BIN"
log "Script: $IMPORT_SCRIPT"
log "PID: $$"

# Exécuter le script et capturer la sortie
cd "$PROJECT_ROOT" || exit 1

# Exécuter avec timeout de 60 secondes pour éviter les blocages
if command -v timeout >/dev/null 2>&1; then
    timeout 60 "$PHP_BIN" "$IMPORT_SCRIPT" >> "$LOG_FILE" 2>> "$ERROR_LOG"
    EXIT_CODE=$?
    if [ $EXIT_CODE -eq 124 ]; then
        log_error "TIMEOUT: Le script a dépassé la limite de 60 secondes"
        exit 1
    fi
else
    # Si timeout n'est pas disponible, exécuter sans timeout
    "$PHP_BIN" "$IMPORT_SCRIPT" >> "$LOG_FILE" 2>> "$ERROR_LOG"
    EXIT_CODE=$?
fi

if [ $EXIT_CODE -eq 0 ]; then
    log "=== Import SFTP terminé avec succès ==="
else
    log_error "=== Import SFTP terminé avec erreur (code: $EXIT_CODE) ==="
fi

exit $EXIT_CODE

