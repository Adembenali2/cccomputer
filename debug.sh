#!/bin/sh

# Affiche un message de départ dans les logs
echo "--- SCRIPT DE DÉBOGAGE DE L'ENVIRONNEMENT ---"
echo ""

# Affiche toutes les variables d'environnement triées par ordre alphabétique
echo "--- LISTE DES VARIABLES D'ENVIRONNEMENT REÇUES PAR LE SERVEUR ---"
printenv | sort
echo "--- FIN DE LA LISTE ---"
echo ""

# Démarre le serveur PHP comme d'habitude
echo "--- Démarrage du serveur PHP ---"
php -S 0.0.0.0:8080 -t .