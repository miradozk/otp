# otp — mini serveur TOTP

Stocke des secrets TOTP dans SQLite et renvoie le code courant via HTTP. PHP ≥ 8.2, aucune dépendance runtime.

## Démarrer

```bash
composer install      # une seule fois (PHPUnit, autoload)
./serve.sh            # ou ./serve.sh 9000
```

## Utiliser

Ouvre <http://127.0.0.1:8080> : un formulaire pour ajouter un secret, et la liste des codes courants avec compte à rebours.

En ligne de commande :

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

## Base de données

Les secrets sont stockés en clair dans `data/otp.sqlite` (ignoré par git). Le fichier et la table `secrets` sont créés automatiquement à la première requête HTTP : aucune étape de migration n'est nécessaire.

Pour créer une base vide sans lancer le serveur (par exemple avant un premier déploiement) :

```bash
php -r 'require "vendor/autoload.php"; new App\Store("sqlite:data/otp.sqlite");'
```

Pour repartir de zéro, arrête le serveur puis supprime le fichier ; il sera recréé vide au prochain démarrage. **Cette opération efface définitivement tous les secrets enregistrés.**

```bash
rm data/otp.sqlite
```

## Tests

```bash
vendor/bin/phpunit
```

Le serveur n'écoute que sur `127.0.0.1`.
