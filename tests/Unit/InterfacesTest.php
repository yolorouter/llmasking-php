<?php

// tests/Unit/InterfacesTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{Recognizer, Strategy, Finding};

final class InterfacesTest extends TestCase
{
    public function testRecognizerContract(): void
    {
        $r = new class () implements Recognizer {
            public function name(): string
            {
                return 'demo';
            }
            public function recognize(string $text): array
            {
                return [];
            }
        };
        self::assertSame('demo', $r->name());
        self::assertSame([], $r->recognize('x'));
    }

    public function testStrategyContract(): void
    {
        $s = new class () implements Strategy {
            public function apply(Finding $f, int $sequence): string
            {
                return '[' . $f->entity . '_' . $sequence . ']';
            }
        };
        self::assertSame('[PHONE_1]', $s->apply(new Finding('PHONE', 0, 1, 1.0, 'x'), 1));
    }
}
