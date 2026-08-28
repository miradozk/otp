#!/usr/bin/env bash
# Démarre le serveur TOTP sur 127.0.0.1 (port en argument, 8080 par défaut).
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

if ! command -v php >/dev/null 2>&1; then
    echo "Erreur : « php » est introuvable dans le PATH." >&2
    exit 1
fi

if [[ ! -f vendor/autoload.php ]]; then
    echo "Erreur : vendor/autoload.php manquant — lance d'abord « composer install »." >&2
    exit 1
fi

PORT="${1:-8080}"
mkdir -p data

echo "Serveur TOTP : http://127.0.0.1:${PORT}   (Ctrl+C pour arrêter)"
exec php -S "127.0.0.1:${PORT}" -t public public/index.php
