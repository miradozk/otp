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
        $message = pack('J', $counter); // 64 bits non signés, big-endian
        $hash = hash_hmac('sha1', $message, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        $code = $binary % (10 ** $digits);

        return str_pad((string) $code, $digits, '0', STR_PAD_LEFT);
    }
}
