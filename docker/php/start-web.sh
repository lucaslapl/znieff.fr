#!/bin/sh
# Démarrage du serveur web applicatif (développement local Docker) :
# - php-fpm avec son pool www (site public) ;
# - nginx en avant-plan (port 8000) : fichiers statiques (dont les GeoJSON
#   précalculés de public/geo) et routage des requêtes vers PHP.
# Les migrations sont déjà faites par entrypoint.sh avant ce script.
set -e

mkdir -p /run/php
php-fpm -D

exec nginx -g 'daemon off;'
