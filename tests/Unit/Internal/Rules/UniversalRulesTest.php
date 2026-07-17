<?php

// tests/Unit/Internal/Rules/UniversalRulesTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal\Rules;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\Rules\UniversalRules;

final class UniversalRulesTest extends TestCase
{
    public function testEmail(): void
    {
        $r = UniversalRules::email();
        $hits = $r->recognize('contact a.b+c@example.co now');
        self::assertCount(1, $hits);
        self::assertSame('a.b+c@example.co', $hits[0]->text);
    }

    public function testBankCardLuhnOnly(): void
    {
        $r = UniversalRules::bankCard();
        $hits = $r->recognize('4111111111111111 ok');
        self::assertCount(1, $hits);
        self::assertSame('4111111111111111', $hits[0]->text);
        self::assertSame([], $r->recognize('4111111111111112 bad')); // Luhn fail
    }

    public function testIntlPhoneBoundary(): void
    {
        $r = UniversalRules::intlPhone();
        self::assertCount(1, $r->recognize('call +8613800138000'));
        self::assertSame([], $r->recognize('123+8613800138000')); // adjacent digits
    }
}
