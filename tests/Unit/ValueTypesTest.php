<?php

// tests/Unit/ValueTypesTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{EntityType, Region, Finding};

final class ValueTypesTest extends TestCase
{
    public function testEntityTypeConstants(): void
    {
        self::assertSame('PHONE', EntityType::PHONE);
        self::assertSame('SECRET', EntityType::SECRET);
    }

    public function testRegionIsBackedEnum(): void
    {
        self::assertSame('CN', Region::CN->value);
        self::assertSame('UNIVERSAL', Region::Universal->value);
    }

    public function testFindingIsImmutable(): void
    {
        $f = new Finding('PHONE', 0, 11, 0.7, '13800138000');
        self::assertSame('PHONE', $f->entity);
        self::assertSame(0, $f->start);
        self::assertSame(11, $f->end);
        self::assertSame(0.7, $f->score);
        self::assertSame('13800138000', $f->text);
    }
}
