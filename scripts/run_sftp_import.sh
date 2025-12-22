#!/bin/bash
# Script shell pour exécuter l'import SFTP
# Usage: ./scripts/run_sftp_import.sh

# Aller dans le répertoire du projet
cd "$(dirname "$0")/.." || exit 1

# Exécuter le script PHP
php scripts/import_sftp_cron.php

# Retourner le code de sortie
exit $?

