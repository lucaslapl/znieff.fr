#!/bin/sh
# Démarrage du conteneur applicatif (développement local Docker).
# 1. attend Postgres,
# 2. installe vendor/ si absent (volume dédié znieff_vendor),
# 3. génère APP_KEY si absente,
# 4. lance migrate (idempotent),
# 5. exécute la commande passée (défaut : start-web.sh).
set -e
cd /var/www/html

# --- Dépendances PHP (le volume nommé znieff_vendor masque le vendor/ hôte) ---
if [ ! -f vendor/autoload.php ]; then
    echo "entrypoint: composer install..."
    composer install --no-interaction --prefer-dist --no-progress
fi

# --- APP_KEY manquante ? ---
if [ ! -f .env ] || ! grep -qE '^APP_KEY=[^[:space:]]+' .env; then
    echo "entrypoint: génération de APP_KEY..."
    php artisan key:generate --no-interaction
fi

# --- Droits d'écriture pour FPM (www-data) : sur un bind mount Windows,
#     les dossiers storage/ et bootstrap/cache arrivent en root:755 et
#     Laravel échoue (« tempnam(): file created in the system's temporary
#     directory »). Sans gravité si l'hôte est déjà correctement réglé.
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true

# --- En dev Docker, le .env du poste hôte peut être utilisé via bind mount :
#     on écarte tout cache config/routes généré hors conteneur.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

# --- Attendre que Postgres réponde ---
# (identifiants explicites : sans eux PDO tente une connexion en tant
# qu'utilisateur système www-data, sans mot de passe → échec permanent.)
echo "entrypoint: attente de la base de données..."
i=0
until php -r 'try { new PDO(sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST") ?: "127.0.0.1", getenv("DB_PORT") ?: "5432", getenv("DB_DATABASE") ?: "znieff"), getenv("DB_USERNAME") ?: "znieff", getenv("DB_PASSWORD") ?: "znieff"); echo "ok"; } catch (Throwable $e) { exit(1); }' 2>/dev/null | grep -q ok; do
    i=$((i+1))
    if [ "$i" -ge 60 ]; then
        echo "entrypoint: la base de données ne répond pas après 60 tentatives."
        exit 1
    fi
    sleep 2
done

php artisan migrate --force

exec "$@"
