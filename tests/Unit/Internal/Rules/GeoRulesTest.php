<?php

// tests/Unit/Internal/Rules/GeoRulesTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal\Rules;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\Rules\{CnRules, UsRules};

final class GeoRulesTest extends TestCase
{
    public function testChinaIdCardChecksum(): void
    {
        $r = CnRules::chinaIdCard();
        $hits = $r->recognize('id 11010519491231002X end');
        self::assertCount(1, $hits);
        self::assertSame('11010519491231002X', $hits[0]->text);
        self::assertSame([], $r->recognize('110105194912310020 bad')); // check digit fail
    }

    public function testSsnValidity(): void
    {
        $r = UsRules::usSsn();
        self::assertCount(1, $r->recognize('123-45-6789'));
        self::assertSame([], $r->recognize('000-12-3456'));
    }

    public function testLandline(): void
    {
        $r = CnRules::landline();
        self::assertCount(1, $r->recognize('010-12345678'));
    }
}
