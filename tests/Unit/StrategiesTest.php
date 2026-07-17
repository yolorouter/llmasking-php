<?php

// tests/Unit/StrategiesTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{EntityType, Finding, Strategies};
use Yolorouter\Llmasking\Internal\Reversibility;

final class StrategiesTest extends TestCase
{
    public function testPlaceholderAndRedactSameFormat(): void
    {
        $f = new Finding(EntityType::PHONE, 0, 2, 1.0, '13');
        self::assertSame('[PHONE_1]', Strategies::placeholder()->apply($f, 1));
        self::assertSame('[PHONE_1]', Strategies::redact()->apply($f, 1));
    }

    public function testOnlyPlaceholderIsReversible(): void
    {
        self::assertTrue(Reversibility::isReversible(Strategies::placeholder()));
        self::assertFalse(Reversibility::isReversible(Strategies::redact()));
        self::assertFalse(Reversibility::isReversible(Strategies::maskMiddle()));
        self::assertFalse(Reversibility::isReversible(Strategies::hash()));
    }

    public function testMaskMiddleAndHash(): void
    {
        $f = new Finding(EntityType::PHONE, 0, 11, 1.0, '13800138000');
        self::assertSame('138****8000', Strategies::maskMiddle()->apply($f, 1));
        $short = new Finding(EntityType::PHONE, 0, 4, 1.0, 'abcd');
        self::assertSame('****', Strategies::maskMiddle()->apply($short, 1));
        // hash: first 8 hex of sha256('13')
        self::assertSame(\substr(\hash('sha256', '13'), 0, 8), Strategies::hash()->apply(new Finding(EntityType::PHONE, 0, 2, 1.0, '13'), 1));
    }
}
