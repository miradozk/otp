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
            return [400, ['error' => 'champ « name » manquant ou invalide (1 à 64 caractères parmi lettres, chiffres, « . », « _ », « - »)']];
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

    /** @return array{0: int, 1: array<string, mixed>} */
    private static function methodNotAllowed(): array
    {
        return [405, ['error' => 'méthode non autorisée']];
    }
}
