<?php

// tests/Unit/ReportingTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{MaskEvent, RestoreEvent, AnonymizeResult, RestoreResult};

final class ReportingTest extends TestCase
{
    public function testMaskEventDefaultsSourceEmpty(): void
    {
        $e = new MaskEvent('PHONE', 0, 11, 0.7, '[PHONE_1]', true);
        self::assertSame('', $e->source);
        self::assertTrue($e->reversible);
    }

    public function testAnonymizeResultHoldsTextAndEvents(): void
    {
        $r = new AnonymizeResult('x', []);
        self::assertSame('x', $r->text);
        self::assertSame([], $r->events);
    }

    public function testRestoreEventRestoredFlag(): void
    {
        $e = new RestoreEvent('PHONE', 0, 9, '[PHONE_1]', true);
        self::assertTrue($e->restored);
    }
}
