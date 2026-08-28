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
