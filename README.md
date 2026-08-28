# otp — mini serveur TOTP

Stocke des secrets TOTP dans SQLite et renvoie le code courant via HTTP. PHP ≥ 8.2, aucune dépendance runtime.

## Démarrer

```bash
composer install      # une seule fois (PHPUnit, autoload)
./serve.sh            # ou ./serve.sh 9000
```

## Utiliser

```bash
# ajouter un secret (base32, tel que fourni par le service)
curl -s -X POST localhost:8080/secrets -d '{"name":"github","secret":"JBSWY3DPEHPK3PXP"}'

# obtenir le code courant
curl -s localhost:8080/otp/github
# → {"name":"github","code":"492039","expires_in":17}

# lister / supprimer
curl -s localhost:8080/secrets
curl -s -X DELETE localhost:8080/secrets/github
```

Options à l'ajout : `"digits": 6|8` (défaut 6), `"period": 1..300` (défaut 30).

## Tests

```bash
vendor/bin/phpunit
```

Les secrets sont stockés en clair dans `data/otp.sqlite` (ignoré par git) ; le serveur n'écoute que sur `127.0.0.1`.
