<?php

// tests/Unit/SkeletonTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SkeletonTest extends TestCase
{
    public function testAutoloadAndMemoryLimit(): void
    {
        self::assertSame('128M', ini_get('memory_limit') ?: '128M');
        self::assertTrue(class_exists(\Yolorouter\Llmasking\EntityType::class));
    }
}
