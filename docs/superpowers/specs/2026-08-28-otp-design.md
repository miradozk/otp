# Spec — Mini serveur TOTP en PHP (2026-08-28)

## Objectif

Une petite application HTTP locale, en PHP 8.5 sans dépendance runtime, qui
stocke des secrets TOTP dans SQLite et renvoie le code à 6 chiffres courant
pour un secret donné — l'équivalent d'une appli d'authentification, interrogée
via HTTP.

## Hors périmètre

- Flux « générer + vérifier un code » (codes aléatoires liés à un email/téléphone).
- Page HTML / interface graphique (peut s'ajouter plus tard sur la même API).
- Authentification de l'API, chiffrement des secrets au repos, HTTPS.
- Algorithmes autres que HMAC-SHA1 (SHA-256/512 non pris en charge).

## Arborescence

```
otp/
├── public/index.php      # point d'entrée : routeur + handlers, réponses JSON
├── src/Totp.php          # base32Decode(), hotp(), totp() — pur, sans I/O
├── src/Store.php         # PDO SQLite : add / list / find / delete
├── src/App.php           # handle(method, path, body, now): [status, body]
├── data/otp.sqlite       # créé automatiquement au premier démarrage (ignoré par git)
├── tests/                # PHPUnit
├── composer.json         # autoload PSR-4 (App\ → src/), phpunit en require-dev
├── serve.sh              # démarre le serveur (crée data/, php -S 127.0.0.1:PORT -t public)
└── .gitignore            # vendor/, data/*.sqlite
```

Lancement : `./serve.sh [port]` (défaut 8080), qui exécute
`php -S 127.0.0.1:PORT -t public` — localhost uniquement.

### `serve.sh` — script de démarrage

`#!/usr/bin/env bash`, `set -euo pipefail`, se place dans le dossier du script,
vérifie que `php` est présent (message d'erreur clair sinon), crée `data/` si
absent, affiche l'URL, puis lance `exec php -S 127.0.0.1:"${1:-8080}" -t public`.

## Composants

### `Totp` (src/Totp.php) — algorithme pur

- `base32Decode(string $b32): string` — RFC 4648, insensible à la casse,
  ignore les espaces et le padding `=`. Lève `InvalidArgumentException` sur
  un caractère hors alphabet ou une chaîne vide.
- `hotp(string $key, int $counter, int $digits = 6): string` — RFC 4226 :
  compteur en big-endian 8 octets, HMAC-SHA1, troncature dynamique, modulo
  `10^digits`, complété à gauche par des zéros.
- `totp(string $key, int $now, int $period = 30, int $digits = 6): string` —
  RFC 6238 : `hotp(key, intdiv(now, period), digits)`.
- `expiresIn(int $now, int $period = 30): int` — secondes restantes avant le
  prochain code : `period - (now % period)`.

Dépendances : `hash_hmac`, `pack` uniquement.

### `Store` (src/Store.php) — persistance

Constructeur : `new Store(string $dsn)` (`sqlite:data/otp.sqlite` en prod,
`sqlite::memory:` en test). Crée la table si absente :

```sql
CREATE TABLE IF NOT EXISTS secrets (
  name       TEXT PRIMARY KEY,
  secret     TEXT NOT NULL,          -- base32, tel que fourni (normalisé en majuscules)
  digits     INTEGER NOT NULL DEFAULT 6,
  period     INTEGER NOT NULL DEFAULT 30,
  created_at TEXT NOT NULL           -- ISO-8601 UTC
);
```

Méthodes :
- `add(string $name, string $secret, int $digits, int $period): void` —
  lève `DuplicateNameException` si le nom existe.
- `names(): string[]` — noms triés alphabétiquement.
- `find(string $name): ?array` — `['name','secret','digits','period']` ou `null`.
- `delete(string $name): bool` — `true` si une ligne a été supprimée.

Le Store ne valide pas le contenu du secret : c'est le rôle de `App`.

### `App` (src/App.php) — routage et règles métier

`handle(string $method, string $path, string $body, int $now): array{int, array}`
retourne `[statut HTTP, tableau à encoder en JSON]`. Aucune lecture de
superglobale ni d'écriture de sortie : `public/index.php` fait le pont.

| Méthode  | Route             | Succès                                             |
|----------|-------------------|----------------------------------------------------|
| `POST`   | `/secrets`        | 201 `{"name":"github"}`                            |
| `GET`    | `/secrets`        | 200 `{"names":["github","gitlab"]}`                |
| `DELETE` | `/secrets/{name}` | 204, corps vide                                    |
| `GET`    | `/otp/{name}`     | 200 `{"name":"github","code":"492039","expires_in":17}` |

Corps de `POST /secrets` : JSON `{"name": str, "secret": str, "digits"?: 6|8, "period"?: int ≥ 1}`.

Règles de validation à l'ajout :
- `name` : obligatoire, 1–64 caractères, `[A-Za-z0-9._-]` uniquement.
- `secret` : obligatoire, doit passer `Totp::base32Decode` (donc non vide).
- `digits` : 6 ou 8, défaut 6. `period` : entier 1–300, défaut 30.

Erreurs, toujours `{"error": "message"}` :
- 400 — JSON invalide, champ manquant ou invalide (message précis).
- 404 — nom inconnu, ou route inconnue.
- 405 — méthode non prise en charge sur une route connue.
- 409 — nom déjà utilisé.

`GET /secrets` ne renvoie **jamais** les secrets, seulement les noms.

### `public/index.php` — adaptateur HTTP

Charge l'autoload, instancie `Store` sur `data/otp.sqlite` (crée `data/` si
besoin), appelle `App::handle()` avec `$_SERVER['REQUEST_METHOD']`, le chemin
de `REQUEST_URI` (sans query string), `file_get_contents('php://input')` et
`time()`. Émet le statut, `Content-Type: application/json`, et le corps.

## Flux de données

```
curl → index.php → App::handle() → Store::find() → Totp::totp() → JSON
```

## Sécurité (limites assumées)

- Secrets en clair dans SQLite : outil local personnel, fichier ignoré par git.
- Serveur lié à `127.0.0.1` seulement ; pas d'authentification.
- Les secrets ne transitent que dans le corps du `POST`, jamais dans une URL.

## Tests (PHPUnit, `vendor/bin/phpunit`)

- `TotpTest` — vecteurs RFC 6238 (secret ASCII `12345678901234567890`) :
  T=59 → `287082` (6) / `94287082` (8), T=1111111109 → `081804`,
  T=1234567890 → `005924`, T=20000000000 → `353130`. Décodage base32
  (`JBSWY3DPEHPK3PXP` → `Hello!\xDE\xAD\xBE\xEF`), rejet des caractères
  invalides, `expiresIn`.
- `StoreTest` — sur `sqlite::memory:` : add/names/find/delete, doublon → exception.
- `AppTest` — `handle()` appelé directement avec un Store en mémoire : chaque
  route, chaque code d'erreur, `GET /otp/{name}` avec `$now` fixé pour un
  code déterministe.

## Contribution en mode apprentissage

`Totp::hotp()` est préparé avec signature et commentaires ; l'utilisateur
l'implémente (≈ 8 lignes), les tests `TotpTest` servant de guide.
