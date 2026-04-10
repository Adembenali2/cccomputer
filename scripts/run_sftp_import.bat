@echo off
REM Script batch pour exécuter l'import SFTP sur Windows
REM Usage: double-clic sur ce fichier ou exécution via Task Scheduler

REM Aller dans le répertoire du script
cd /d "%~dp0\.."

REM Exécuter le script PHP
php scripts\import_sftp_cron.php

REM Garder la fenêtre ouverte en cas d'erreur (optionnel)
if errorlevel 1 (
    echo.
    echo ERREUR: Le script a retourne un code d'erreur
    pause
)

