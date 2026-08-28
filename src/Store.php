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
