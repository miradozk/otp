# Mini serveur TOTP — Plan d'implémentation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Un serveur HTTP local en PHP qui stocke des secrets TOTP dans SQLite et renvoie le code courant via `GET /otp/{name}`.

**Architecture:** Trois unités : `Totp` (algorithme pur RFC 4226/6238), `Store` (PDO SQLite), `App` (routeur + validation, `handle()` retourne `[statut, tableau]`). `public/index.php` n'est qu'un adaptateur entre les superglobales et `App::handle()`. `serve.sh` lance le serveur intégré de PHP.

**Tech Stack:** PHP 8.5 (`hash_hmac`, `pack`, `pdo_sqlite`), Composer (autoload PSR-4), PHPUnit 12 (dev uniquement), bash.

**Spec:** `docs/superpowers/specs/2026-08-28-otp-design.md`

## Global Constraints

- PHP `>=8.2`, extension `pdo_sqlite` obligatoire ; **aucune dépendance runtime** (PHPUnit en `require-dev` seulement).
- Tous les fichiers PHP commencent par `<?php` + `declare(strict_types=1);`. Namespace `App\` → `src/`, `Tests\` → `tests/`.
- Toute réponse HTTP est du JSON ; les erreurs ont la forme `{"error": "message"}`. `GET /secrets` ne renvoie jamais les secrets.
- Serveur lié à `127.0.0.1` uniquement, via `./serve.sh [port]` (défaut `8080`).
- `data/*.sqlite` et `vendor/` sont ignorés par git (déjà dans `.gitignore`).
- Messages d'erreur et commentaires en français ; identifiants de code en anglais.
- Commits : pas de mention « Generated with Claude Code », pas de `Co-Authored-By`.
- Commande de test : `vendor/bin/phpunit` (depuis la racine du projet).

---

## Fichiers

| Fichier | Responsabilité |
|---|---|
| `composer.json`, `phpunit.xml` | Autoload PSR-4, config PHPUnit |
| `src/Totp.php` | `base32Decode`, `hotp`, `totp`, `expiresIn` — pur, sans I/O |
| `src/DuplicateNameException.php` | Exception levée par `Store::add` sur doublon |
| `src/Store.php` | Table `secrets` en SQLite : `add`, `names`, `find`, `delete` |
| `src/App.php` | `handle(method, path, body, now)` : routage, validation, codes HTTP |
| `public/index.php` | Adaptateur HTTP (superglobales → `App::handle` → sortie JSON) |
| `serve.sh` | Script de démarrage |
| `README.md` | Usage en 10 lignes |
| `tests/TotpTest.php`, `tests/StoreTest.php`, `tests/AppTest.php` | Tests unitaires |

---

### Task 1 : Squelette du projet (Composer + PHPUnit)

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `tests/.gitkeep`

**Interfaces:**
- Produces: autoload `App\` → `src/`, `Tests\` → `tests/` ; commande `vendor/bin/phpunit`.

- [ ] **Step 1 : Créer `composer.json`**

```json
{
    "name": "miradozk/otp",
    "description": "Mini serveur TOTP en PHP, secrets dans SQLite",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.2",
        "ext-pdo_sqlite": "*"
    },
    "autoload": {
        "psr-4": { "App\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "Tests\\": "tests/" }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 2 : Installer PHPUnit en dev**

Run: `composer require --dev phpunit/phpunit:^12 --no-interaction`
Expected: `vendor/` créé, `composer.lock` créé, pas d'erreur. Si PHPUnit 12 refuse la version de PHP, retomber sur `^11`.

- [ ] **Step 3 : Créer `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true">
    <testsuites>
        <testsuite name="otp">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 4 : Vérifier que PHPUnit tourne**

Run: `mkdir -p src tests && touch tests/.gitkeep && vendor/bin/phpunit`
Expected: `No tests executed!` (code de sortie 0 ou 1 selon la version — l'important est qu'il n'y ait pas d'erreur de configuration).

- [ ] **Step 5 : Commit**

```bash
git add composer.json composer.lock phpunit.xml tests/.gitkeep
git commit -m "Squelette Composer + PHPUnit"
```

---

### Task 2 : `Totp::base32Decode`

**Files:**
- Create: `src/Totp.php`
- Test: `tests/TotpTest.php`

**Interfaces:**
- Produces: `Totp::base32Decode(string $b32): string` — retourne les octets bruts ; lève `InvalidArgumentException` si vide ou caractère hors alphabet. Insensible à la casse, ignore les espaces et le padding `=`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`tests/TotpTest.php` :

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    public function testBase32DecodeKnownValue(): void
    {
        self::assertSame("Hello!\xDE\xAD\xBE\xEF", Totp::base32Decode('JBSWY3DPEHPK3PXP'));
    }

    public function testBase32DecodeIgnoresCaseSpacesAndPadding(): void
    {
        self::assertSame("Hello!\xDE\xAD\xBE\xEF", Totp::base32Decode('jbsw y3dp ehpk 3pxp=='));
    }

    public function testBase32DecodeRejectsInvalidCharacter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::base32Decode('JBSWY3DP1');
    }

    public function testBase32DecodeRejectsEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Totp::base32Decode('===');
    }
}
```

- [ ] **Step 2 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter Base32`
Expected: ERROR `Class "App\Totp" not found`.

- [ ] **Step 3 : Implémenter**

`src/Totp.php` :

```php
<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Algorithmes HOTP (RFC 4226) et TOTP (RFC 6238), sans aucune I/O.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Décode une chaîne base32 (RFC 4648) en octets bruts.
     * Insensible à la casse ; les espaces et le padding « = » sont ignorés.
     *
     * @throws InvalidArgumentException si la chaîne est vide ou contient un caractère hors alphabet
     */
    public static function base32Decode(string $b32): string
    {
        $clean = strtoupper(rtrim((string) preg_replace('/\s+/', '', $b32), '='));
        if ($clean === '') {
            throw new InvalidArgumentException('secret base32 vide');
        }

        $bits = '';
        foreach (str_split($clean) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                throw new InvalidArgumentException("caractère base32 invalide « {$char} »");
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int) bindec($chunk));
            }
        }

        return $bytes;
    }
}
```

- [ ] **Step 4 : Vérifier le succès**

Run: `vendor/bin/phpunit --filter Base32`
Expected: `OK (4 tests, 4 assertions)`.

- [ ] **Step 5 : Commit**

```bash
git add src/Totp.php tests/TotpTest.php
git commit -m "Totp : décodage base32"
```

---

### Task 3 : `Totp::hotp` — contribution de l'utilisateur (mode apprentissage)

**Files:**
- Modify: `src/Totp.php` (ajouter la méthode après `base32Decode`)
- Test: `tests/TotpTest.php` (ajouter les tests)

**Interfaces:**
- Consumes: rien.
- Produces: `Totp::hotp(string $key, int $counter, int $digits = 6): string` — code à `$digits` chiffres, complété à gauche par des zéros.

> **Note d'exécution :** cette tâche est le cœur pédagogique du projet. L'exécuteur écrit les tests et le **squelette** de la méthode (signature + commentaires + `throw new \LogicException('à implémenter')`), puis **s'arrête et demande à l'utilisateur** d'écrire le corps (≈ 8 lignes). La solution de référence ci-dessous sert si l'utilisateur délègue, ou pour comparer après coup.

- [ ] **Step 1 : Ajouter les tests (vecteurs RFC 4226, annexe D)**

Dans `tests/TotpTest.php`, ajouter l'import `use PHPUnit\Framework\Attributes\DataProvider;` puis, dans la classe :

```php
    private const RFC_KEY = '12345678901234567890';

    /** @return iterable<string, array{int, string}> */
    public static function hotpVectors(): iterable
    {
        yield 'counter 0' => [0, '755224'];
        yield 'counter 1' => [1, '287082'];
        yield 'counter 2' => [2, '359152'];
        yield 'counter 5' => [5, '254676'];
        yield 'counter 9' => [9, '520489'];
    }

    #[DataProvider('hotpVectors')]
    public function testHotpMatchesRfc4226Vectors(int $counter, string $expected): void
    {
        self::assertSame($expected, Totp::hotp(self::RFC_KEY, $counter));
    }

    public function testHotpEightDigits(): void
    {
        self::assertSame('84755224', Totp::hotp(self::RFC_KEY, 0, 8));
    }

    public function testHotpPadsWithLeadingZeros(): void
    {
        // Le résultat doit toujours faire exactement $digits caractères.
        self::assertSame(6, strlen(Totp::hotp(self::RFC_KEY, 3)));
    }
```

- [ ] **Step 2 : Écrire le squelette**

Dans `src/Totp.php`, après `base32Decode` :

```php
    /**
     * HOTP (RFC 4226) : HMAC-SHA1 du compteur, puis « troncature dynamique ».
     *
     * Étapes :
     *  1. Sérialiser $counter en 8 octets big-endian  → pack('J', $counter)
     *  2. $hash = hash_hmac('sha1', $message, $key, true)  (20 octets bruts)
     *  3. $offset = 4 bits de poids faible du dernier octet : ord($hash[19]) & 0x0F
     *  4. Lire 4 octets à partir de $offset, masquer le bit de signe du premier (& 0x7F),
     *     assembler en entier 31 bits big-endian
     *  5. Réduire modulo 10 ** $digits, puis compléter à gauche avec des « 0 »
     *
     * @param string $key     clé secrète en octets bruts (sortie de base32Decode)
     * @param int    $counter compteur (pour TOTP : intdiv(temps, période))
     * @param int    $digits  longueur du code (6 ou 8)
     */
    public static function hotp(string $key, int $counter, int $digits = 6): string
    {
        throw new \LogicException('à implémenter');
    }
```

- [ ] **Step 3 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter Hotp`
Expected: 7 tests en erreur avec `LogicException: à implémenter`.

- [ ] **Step 4 : Demander la contribution de l'utilisateur**

Présenter à l'utilisateur le fichier `src/Totp.php`, la méthode `hotp` et la commande `vendor/bin/phpunit --filter Hotp`, et attendre qu'il remplace le `throw` par son implémentation. S'il préfère déléguer, utiliser la solution de référence :

```php
        $message = pack('J', $counter); // 64 bits non signés, big-endian
        $hash = hash_hmac('sha1', $message, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        $code = $binary % (10 ** $digits);

        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
```

- [ ] **Step 5 : Vérifier le succès**

Run: `vendor/bin/phpunit`
Expected: `OK (11 tests, 11 assertions)`.

- [ ] **Step 6 : Commit**

```bash
git add src/Totp.php tests/TotpTest.php
git commit -m "Totp : HOTP (RFC 4226)"
```

---

### Task 4 : `Totp::totp` et `Totp::expiresIn`

**Files:**
- Modify: `src/Totp.php`
- Test: `tests/TotpTest.php`

**Interfaces:**
- Consumes: `Totp::hotp(string $key, int $counter, int $digits): string`.
- Produces: `Totp::totp(string $key, int $now, int $period = 30, int $digits = 6): string` ; `Totp::expiresIn(int $now, int $period = 30): int`.

- [ ] **Step 1 : Ajouter les tests (vecteurs RFC 6238, SHA-1)**

Dans la classe `TotpTest` :

```php
    /** @return iterable<string, array{int, string}> */
    public static function totpVectors(): iterable
    {
        yield 'T=59' => [59, '287082'];
        yield 'T=1111111109' => [1111111109, '081804'];
        yield 'T=1111111111' => [1111111111, '050471'];
        yield 'T=1234567890' => [1234567890, '005924'];
        yield 'T=2000000000' => [2000000000, '279037'];
        yield 'T=20000000000' => [20000000000, '353130'];
    }

    #[DataProvider('totpVectors')]
    public function testTotpMatchesRfc6238Vectors(int $now, string $expected): void
    {
        self::assertSame($expected, Totp::totp(self::RFC_KEY, $now));
    }

    public function testTotpEightDigits(): void
    {
        self::assertSame('94287082', Totp::totp(self::RFC_KEY, 59, 30, 8));
    }

    public function testTotpCustomPeriod(): void
    {
        // Avec une période de 60 s, T=59 correspond au compteur 0.
        self::assertSame(Totp::hotp(self::RFC_KEY, 0), Totp::totp(self::RFC_KEY, 59, 60));
    }

    public function testExpiresIn(): void
    {
        self::assertSame(30, Totp::expiresIn(0));
        self::assertSame(1, Totp::expiresIn(59));
        self::assertSame(30, Totp::expiresIn(60));
        self::assertSame(17, Totp::expiresIn(43));
        self::assertSame(45, Totp::expiresIn(15, 60));
    }
```

- [ ] **Step 2 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter 'Totp|ExpiresIn'`
Expected: erreurs `Call to undefined method App\Totp::totp()`.

- [ ] **Step 3 : Implémenter**

Dans `src/Totp.php`, après `hotp` :

```php
    /**
     * TOTP (RFC 6238) : HOTP avec pour compteur le nombre de périodes écoulées.
     *
     * @param string $key    clé secrète en octets bruts
     * @param int    $now    horodatage Unix en secondes
     * @param int    $period durée d'un code en secondes
     * @param int    $digits longueur du code (6 ou 8)
     */
    public static function totp(string $key, int $now, int $period = 30, int $digits = 6): string
    {
        return self::hotp($key, intdiv($now, $period), $digits);
    }

    /** Secondes restantes avant le prochain code (entre 1 et $period inclus). */
    public static function expiresIn(int $now, int $period = 30): int
    {
        return $period - ($now % $period);
    }
```

- [ ] **Step 4 : Vérifier le succès**

Run: `vendor/bin/phpunit`
Expected: `OK (20 tests, 24 assertions)`.

- [ ] **Step 5 : Commit**

```bash
git add src/Totp.php tests/TotpTest.php
git commit -m "Totp : TOTP (RFC 6238) et expiresIn"
```

---

### Task 5 : `Store` (SQLite)

**Files:**
- Create: `src/DuplicateNameException.php`
- Create: `src/Store.php`
- Test: `tests/StoreTest.php`

**Interfaces:**
- Produces:
  - `new Store(string $dsn)` — `sqlite::memory:` en test, `sqlite:/chemin/otp.sqlite` en prod ; crée la table si absente.
  - `add(string $name, string $secret, int $digits = 6, int $period = 30): void` — lève `DuplicateNameException` si le nom existe.
  - `names(): string[]` — triés alphabétiquement.
  - `find(string $name): ?array` — `['name' => string, 'secret' => string, 'digits' => int, 'period' => int]` ou `null`.
  - `delete(string $name): bool` — `true` si une ligne a été supprimée.

- [ ] **Step 1 : Écrire les tests qui échouent**

`tests/StoreTest.php` :

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\DuplicateNameException;
use App\Store;
use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store('sqlite::memory:');
    }

    public function testNamesIsEmptyInitially(): void
    {
        self::assertSame([], $this->store->names());
    }

    public function testAddThenFind(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP', 8, 60);

        self::assertSame(
            ['name' => 'github', 'secret' => 'JBSWY3DPEHPK3PXP', 'digits' => 8, 'period' => 60],
            $this->store->find('github'),
        );
    }

    public function testAddUsesDefaults(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        $row = $this->store->find('github');
        self::assertSame(6, $row['digits']);
        self::assertSame(30, $row['period']);
    }

    public function testNamesAreSorted(): void
    {
        $this->store->add('zed', 'JBSWY3DPEHPK3PXP');
        $this->store->add('alpha', 'JBSWY3DPEHPK3PXP');

        self::assertSame(['alpha', 'zed'], $this->store->names());
    }

    public function testFindUnknownReturnsNull(): void
    {
        self::assertNull($this->store->find('nope'));
    }

    public function testAddDuplicateThrows(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        $this->expectException(DuplicateNameException::class);
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');
    }

    public function testDelete(): void
    {
        $this->store->add('github', 'JBSWY3DPEHPK3PXP');

        self::assertTrue($this->store->delete('github'));
        self::assertNull($this->store->find('github'));
        self::assertFalse($this->store->delete('github'));
    }
}
```

- [ ] **Step 2 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter StoreTest`
Expected: ERROR `Class "App\Store" not found`.

- [ ] **Step 3 : Implémenter**

`src/DuplicateNameException.php` :

```php
<?php

declare(strict_types=1);

namespace App;

use RuntimeException;

/** Levée par Store::add quand le nom existe déjà. */
final class DuplicateNameException extends RuntimeException
{
}
```

`src/Store.php` :

```php
<?php

declare(strict_types=1);

namespace App;

use PDO;
use PDOException;

/**
 * Persistance des secrets TOTP dans SQLite (une table « secrets »).
 */
final class Store
{
    private const SQLSTATE_INTEGRITY_CONSTRAINT = '23000';

    private PDO $pdo;

    /** @param string $dsn par ex. « sqlite:data/otp.sqlite » ou « sqlite::memory: » */
    public function __construct(string $dsn)
    {
        $this->pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS secrets (
                name       TEXT PRIMARY KEY,
                secret     TEXT NOT NULL,
                digits     INTEGER NOT NULL DEFAULT 6,
                period     INTEGER NOT NULL DEFAULT 30,
                created_at TEXT NOT NULL
            )
            SQL);
    }

    /** @throws DuplicateNameException si le nom existe déjà */
    public function add(string $name, string $secret, int $digits = 6, int $period = 30): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO secrets (name, secret, digits, period, created_at) VALUES (?, ?, ?, ?, ?)',
        );

        try {
            $stmt->execute([$name, $secret, $digits, $period, gmdate('c')]);
        } catch (PDOException $e) {
            if ($e->getCode() === self::SQLSTATE_INTEGRITY_CONSTRAINT) {
                throw new DuplicateNameException("le nom « {$name} » existe déjà", 0, $e);
            }
            throw $e;
        }
    }

    /** @return list<string> noms triés alphabétiquement */
    public function names(): array
    {
        return $this->pdo->query('SELECT name FROM secrets ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array{name: string, secret: string, digits: int, period: int}|null */
    public function find(string $name): ?array
    {
        $stmt = $this->pdo->prepare('SELECT name, secret, digits, period FROM secrets WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return [
            'name' => (string) $row['name'],
            'secret' => (string) $row['secret'],
            'digits' => (int) $row['digits'],
            'period' => (int) $row['period'],
        ];
    }

    /** @return bool true si une ligne a été supprimée */
    public function delete(string $name): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM secrets WHERE name = ?');
        $stmt->execute([$name]);

        return $stmt->rowCount() > 0;
    }
}
```

- [ ] **Step 4 : Vérifier le succès**

Run: `vendor/bin/phpunit`
Expected: `OK (27 tests, ...)`.

- [ ] **Step 5 : Commit**

```bash
git add src/Store.php src/DuplicateNameException.php tests/StoreTest.php
git commit -m "Store : persistance SQLite des secrets"
```

---

### Task 6 : `App` — `POST /secrets` et `GET /secrets`

**Files:**
- Create: `src/App.php`
- Test: `tests/AppTest.php`

**Interfaces:**
- Consumes: `Store::add/names`, `DuplicateNameException`, `Totp::base32Decode`.
- Produces: `new App(Store $store)` ; `handle(string $method, string $path, string $body, int $now): array{0: int, 1: array<string, mixed>}`.

- [ ] **Step 1 : Écrire les tests qui échouent**

`tests/AppTest.php` :

```php
<?php

declare(strict_types=1);

namespace Tests;

use App\App;
use App\Store;
use PHPUnit\Framework\TestCase;

final class AppTest extends TestCase
{
    private const SECRET = 'JBSWY3DPEHPK3PXP';

    private Store $store;
    private App $app;

    protected function setUp(): void
    {
        $this->store = new Store('sqlite::memory:');
        $this->app = new App($this->store);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function post(string $path, array $data): array
    {
        return $this->app->handle('POST', $path, json_encode($data, JSON_THROW_ON_ERROR), 0);
    }

    public function testListIsEmptyInitially(): void
    {
        self::assertSame([200, ['names' => []]], $this->app->handle('GET', '/secrets', '', 0));
    }

    public function testAddSecretReturns201AndStoresIt(): void
    {
        $response = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        self::assertSame([201, ['name' => 'github']], $response);
        self::assertSame(['name' => 'github', 'secret' => self::SECRET, 'digits' => 6, 'period' => 30], $this->store->find('github'));
    }

    public function testAddSecretNormalizesSecret(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => 'jbsw y3dp ehpk 3pxp']);

        self::assertSame(self::SECRET, $this->store->find('github')['secret']);
    }

    public function testAddSecretAcceptsDigitsAndPeriod(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'digits' => 8, 'period' => 60]);

        $row = $this->store->find('github');
        self::assertSame(8, $row['digits']);
        self::assertSame(60, $row['period']);
    }

    public function testListNeverExposesSecrets(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);
        $this->post('/secrets', ['name' => 'aws', 'secret' => self::SECRET]);

        [$status, $body] = $this->app->handle('GET', '/secrets', '', 0);

        self::assertSame(200, $status);
        self::assertSame(['names' => ['aws', 'github']], $body);
        self::assertStringNotContainsString(self::SECRET, json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testAddSecretRejectsInvalidJson(): void
    {
        [$status, $body] = $this->app->handle('POST', '/secrets', '{not json', 0);

        self::assertSame(400, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testAddSecretRejectsMissingName(): void
    {
        [$status, $body] = $this->post('/secrets', ['secret' => self::SECRET]);

        self::assertSame(400, $status);
        self::assertStringContainsString('name', $body['error']);
    }

    public function testAddSecretRejectsInvalidName(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'git hub/évil', 'secret' => self::SECRET]);

        self::assertSame(400, $status);
    }

    public function testAddSecretRejectsMissingSecret(): void
    {
        [$status, $body] = $this->post('/secrets', ['name' => 'github']);

        self::assertSame(400, $status);
        self::assertStringContainsString('secret', $body['error']);
    }

    public function testAddSecretRejectsInvalidBase32(): void
    {
        [$status, $body] = $this->post('/secrets', ['name' => 'github', 'secret' => 'not-base32!']);

        self::assertSame(400, $status);
        self::assertStringContainsString('secret', $body['error']);
    }

    public function testAddSecretRejectsBadDigits(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'digits' => 7]);

        self::assertSame(400, $status);
    }

    public function testAddSecretRejectsBadPeriod(): void
    {
        [$status] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET, 'period' => 0]);

        self::assertSame(400, $status);
    }

    public function testAddSecretDuplicateReturns409(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        [$status, $body] = $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        self::assertSame(409, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testSecretsRejectsOtherMethods(): void
    {
        [$status] = $this->app->handle('PUT', '/secrets', '', 0);

        self::assertSame(405, $status);
    }

    public function testUnknownRouteReturns404(): void
    {
        [$status] = $this->app->handle('GET', '/nope', '', 0);

        self::assertSame(404, $status);
    }
}
```

- [ ] **Step 2 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter AppTest`
Expected: ERROR `Class "App\App" not found`.

- [ ] **Step 3 : Implémenter**

`src/App.php` :

```php
<?php

declare(strict_types=1);

namespace App;

use InvalidArgumentException;

/**
 * Routage HTTP et règles métier. Aucune superglobale, aucune sortie :
 * handle() reçoit la requête et retourne [statut HTTP, tableau à encoder en JSON].
 */
final class App
{
    private const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,64}$/';
    private const ALLOWED_DIGITS = [6, 8];
    private const MAX_PERIOD = 300;

    public function __construct(private readonly Store $store)
    {
    }

    /**
     * @param string $method méthode HTTP (GET, POST, DELETE…)
     * @param string $path   chemin sans query string, déjà décodé (ex. « /otp/github »)
     * @param string $body   corps brut de la requête
     * @param int    $now    horodatage Unix courant (injecté pour les tests)
     * @return array{0: int, 1: array<string, mixed>} [statut HTTP, corps JSON]
     */
    public function handle(string $method, string $path, string $body, int $now): array
    {
        if ($path === '/secrets') {
            return match ($method) {
                'GET' => [200, ['names' => $this->store->names()]],
                'POST' => $this->addSecret($body),
                default => self::methodNotAllowed(),
            };
        }

        return [404, ['error' => 'route inconnue']];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function addSecret(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [400, ['error' => 'corps JSON invalide']];
        }

        $name = $data['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            return [400, ['error' => "champ « name » manquant ou invalide (1 à 64 caractères parmi lettres, chiffres, « . », « _ », « - »)"]];
        }

        $secret = $data['secret'] ?? null;
        if (!is_string($secret)) {
            return [400, ['error' => 'champ « secret » manquant']];
        }
        $secret = strtoupper((string) preg_replace('/\s+/', '', $secret));
        try {
            Totp::base32Decode($secret);
        } catch (InvalidArgumentException $e) {
            return [400, ['error' => 'champ « secret » invalide : ' . $e->getMessage()]];
        }

        $digits = $data['digits'] ?? 6;
        if (!in_array($digits, self::ALLOWED_DIGITS, true)) {
            return [400, ['error' => 'champ « digits » doit valoir 6 ou 8']];
        }

        $period = $data['period'] ?? 30;
        if (!is_int($period) || $period < 1 || $period > self::MAX_PERIOD) {
            return [400, ['error' => 'champ « period » doit être un entier entre 1 et ' . self::MAX_PERIOD]];
        }

        try {
            $this->store->add($name, $secret, $digits, $period);
        } catch (DuplicateNameException) {
            return [409, ['error' => "le nom « {$name} » existe déjà"]];
        }

        return [201, ['name' => $name]];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function methodNotAllowed(): array
    {
        return [405, ['error' => 'méthode non autorisée']];
    }
}
```

- [ ] **Step 4 : Vérifier le succès**

Run: `vendor/bin/phpunit`
Expected: tout vert (`OK (42 tests, ...)`).

- [ ] **Step 5 : Commit**

```bash
git add src/App.php tests/AppTest.php
git commit -m "App : ajout et liste des secrets"
```

---

### Task 7 : `App` — `DELETE /secrets/{name}` et `GET /otp/{name}`

**Files:**
- Modify: `src/App.php`
- Test: `tests/AppTest.php`

**Interfaces:**
- Consumes: `Store::find/delete`, `Totp::base32Decode/totp/expiresIn`.
- Produces: routes `DELETE /secrets/{name}` → `[204, []]` ; `GET /otp/{name}` → `[200, ['name', 'code', 'expires_in']]`.

- [ ] **Step 1 : Ajouter les tests**

Dans `tests/AppTest.php` :

```php
    public function testDeleteReturns204AndRemoves(): void
    {
        $this->post('/secrets', ['name' => 'github', 'secret' => self::SECRET]);

        self::assertSame([204, []], $this->app->handle('DELETE', '/secrets/github', '', 0));
        self::assertNull($this->store->find('github'));
    }

    public function testDeleteUnknownReturns404(): void
    {
        [$status, $body] = $this->app->handle('DELETE', '/secrets/nope', '', 0);

        self::assertSame(404, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testSecretsByNameRejectsOtherMethods(): void
    {
        [$status] = $this->app->handle('GET', '/secrets/github', '', 0);

        self::assertSame(405, $status);
    }

    public function testOtpReturnsCurrentCode(): void
    {
        // Secret RFC 6238 « 12345678901234567890 » en base32 ; à T=59 le code SHA-1 vaut 287082.
        $this->post('/secrets', ['name' => 'rfc', 'secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ']);

        $response = $this->app->handle('GET', '/otp/rfc', '', 59);

        self::assertSame([200, ['name' => 'rfc', 'code' => '287082', 'expires_in' => 1]], $response);
    }

    public function testOtpHonoursDigitsAndPeriod(): void
    {
        $this->post('/secrets', ['name' => 'rfc', 'secret' => 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'digits' => 8, 'period' => 60]);

        [$status, $body] = $this->app->handle('GET', '/otp/rfc', '', 59);

        self::assertSame(200, $status);
        self::assertSame('84755224', $body['code']); // compteur 0, 8 chiffres
        self::assertSame(1, $body['expires_in']);
    }

    public function testOtpUnknownReturns404(): void
    {
        [$status, $body] = $this->app->handle('GET', '/otp/nope', '', 0);

        self::assertSame(404, $status);
        self::assertArrayHasKey('error', $body);
    }

    public function testOtpRejectsOtherMethods(): void
    {
        [$status] = $this->app->handle('POST', '/otp/github', '', 0);

        self::assertSame(405, $status);
    }
```

- [ ] **Step 2 : Vérifier l'échec**

Run: `vendor/bin/phpunit --filter 'Delete|Otp|ByName'`
Expected: 7 échecs (404 « route inconnue » au lieu des statuts attendus).

- [ ] **Step 3 : Implémenter**

Dans `src/App.php`, remplacer la fin de `handle()` (le `return [404, ...]`) par :

```php
        if (preg_match('#^/secrets/([^/]+)$#', $path, $m) === 1) {
            if ($method !== 'DELETE') {
                return self::methodNotAllowed();
            }

            return $this->store->delete($m[1])
                ? [204, []]
                : [404, ['error' => "nom inconnu « {$m[1]} »"]];
        }

        if (preg_match('#^/otp/([^/]+)$#', $path, $m) === 1) {
            if ($method !== 'GET') {
                return self::methodNotAllowed();
            }

            return $this->currentCode($m[1], $now);
        }

        return [404, ['error' => 'route inconnue']];
```

Puis ajouter la méthode privée, avant `methodNotAllowed` :

```php
    /** @return array{0: int, 1: array<string, mixed>} */
    private function currentCode(string $name, int $now): array
    {
        $row = $this->store->find($name);
        if ($row === null) {
            return [404, ['error' => "nom inconnu « {$name} »"]];
        }

        $key = Totp::base32Decode($row['secret']);

        return [200, [
            'name' => $name,
            'code' => Totp::totp($key, $now, $row['period'], $row['digits']),
            'expires_in' => Totp::expiresIn($now, $row['period']),
        ]];
    }
```

- [ ] **Step 4 : Vérifier le succès**

Run: `vendor/bin/phpunit`
Expected: tout vert (`OK (49 tests, ...)`).

- [ ] **Step 5 : Commit**

```bash
git add src/App.php tests/AppTest.php
git commit -m "App : suppression d'un secret et calcul du code TOTP"
```

---

### Task 8 : `public/index.php`, `serve.sh`, README et test de bout en bout

**Files:**
- Create: `public/index.php`
- Create: `serve.sh` (exécutable)
- Create: `README.md`

**Interfaces:**
- Consumes: `App::handle`, `Store`.
- Produces: serveur HTTP fonctionnel sur `http://127.0.0.1:8080`.

- [ ] **Step 1 : Créer `public/index.php`**

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\App;
use App\Store;

// Adaptateur HTTP : superglobales → App::handle() → réponse JSON.

$dataDir = dirname(__DIR__) . '/data';
if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'impossible de créer le dossier data/']), "\n";
    exit;
}

$app = new App(new Store('sqlite:' . $dataDir . '/otp.sqlite'));

$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = rawurldecode(is_string($rawPath) && $rawPath !== '' ? $rawPath : '/');
$body = file_get_contents('php://input');

[$status, $payload] = $app->handle(
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    is_string($body) ? $body : '',
    time(),
);

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
if ($status !== 204) {
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
}
```

- [ ] **Step 2 : Créer `serve.sh`**

```bash
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
```

Run: `chmod +x serve.sh && bash -n serve.sh`
Expected: pas de sortie (syntaxe valide).

- [ ] **Step 3 : Créer `README.md`**

````markdown
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
````

- [ ] **Step 4 : Test de bout en bout**

Run (en une seule commande, le serveur est arrêté à la fin) :

```bash
./serve.sh 8099 >/dev/null 2>&1 & SERVER=$!; sleep 1
curl -s -o /dev/null -w '%{http_code}\n' -X POST localhost:8099/secrets -d '{"name":"rfc","secret":"GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"}'
curl -s localhost:8099/otp/rfc
curl -s localhost:8099/secrets
curl -s -o /dev/null -w '%{http_code}\n' -X DELETE localhost:8099/secrets/rfc
curl -s -o /dev/null -w '%{http_code}\n' localhost:8099/otp/rfc
kill $SERVER
```

Expected :
```
201
{"name":"rfc","code":"XXXXXX","expires_in":N}   (6 chiffres, 1 ≤ N ≤ 30)
{"names":["rfc"]}
204
404
```

Puis vérifier avec un calcul indépendant que le code est juste :
`php -r 'require "vendor/autoload.php"; echo App\Totp::totp(App\Totp::base32Decode("GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"), time()), "\n";'` doit afficher le même code que `curl` (si exécuté dans la même fenêtre de 30 s).

- [ ] **Step 5 : Nettoyer et committer**

```bash
rm -f data/otp.sqlite
git add public/index.php serve.sh README.md
git commit -m "Point d'entrée HTTP, script serve.sh et README"
```
