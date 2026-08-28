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
