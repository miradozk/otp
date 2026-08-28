<?php

declare(strict_types=1);

namespace Tests;

use App\Totp;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_KEY = '12345678901234567890';

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
}
