@echo off
REM Script batch pour exécuter les imports SFTP et IONOS sur Windows
REM Usage: double-clic ou planification via Task Scheduler (toutes les minutes)

cd /d "%~dp0\.."

REM Import SFTP
php scripts\import_sftp_cron.php

REM Import IONOS
php scripts\import_ionos_cron.php
