<?php

// tests/Unit/Internal/ValidateTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\Validate;

final class ValidateTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function luhn(): array
    {
        return [
            'valid visa'   => ['4111111111111111', true],
            'bad checksum' => ['4111111111111112', false],
            'too short'    => ['1', false],
            'non-digit'    => ['4111A1111111111', false],
        ];
    }
    /** @dataProvider luhn */
    public function testLuhn(string $digits, bool $expected): void
    {
        self::assertSame($expected, Validate::luhn($digits));
    }

    public function testChinaIdChecksumLowercaseX(): void
    {
        // Known valid structure with correct ISO 7064 MOD 11-2 check digit.
        self::assertTrue(Validate::chinaIdChecksum('11010519491231002X'));
        self::assertTrue(Validate::chinaIdChecksum('11010519491231002x'));
        self::assertFalse(Validate::chinaIdChecksum('110105194912310020'));
    }

    public function testSsnInvalidAreas(): void
    {
        self::assertFalse(Validate::ssnValid('000-12-3456')); // area 000
        self::assertFalse(Validate::ssnValid('666-12-3456')); // area 666
        self::assertFalse(Validate::ssnValid('900-12-3456')); // area 9xx
        self::assertFalse(Validate::ssnValid('123-00-3456')); // group 00
        self::assertFalse(Validate::ssnValid('123-12-0000')); // serial 0000
        self::assertTrue(Validate::ssnValid('123-45-6789'));
    }

    public function testShannonEntropy(): void
    {
        self::assertSame(0.0, Validate::shannonEntropy(''));
        // 'aaaa' -> 0 bits/byte; two distinct equally likely -> 1.0
        self::assertEqualsWithDelta(0.0, Validate::shannonEntropy('aaaa'), 1e-9);
        self::assertEqualsWithDelta(1.0, Validate::shannonEntropy('ab'), 1e-9);
    }
}
