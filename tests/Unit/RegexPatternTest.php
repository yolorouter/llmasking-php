<?php

// tests/Unit/RegexPatternTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\RegexPattern;
use Yolorouter\Llmasking\Exception\RegexException;

final class RegexPatternTest extends TestCase
{
    public function testCompileValidPattern(): void
    {
        $p = RegexPattern::compile('/[0-9]+/');
        self::assertSame('/[0-9]+/', $p->value());
    }

    public function testCompileInvalidPatternThrows(): void
    {
        $this->expectException(RegexException::class);
        RegexPattern::compile('/(/');
    }

    public function testCompileRestoresErrorHandler(): void
    {
        // A sentinel handler that records whether it is still on top of the
        // error-handler stack after a failing compile. If RegexPattern leaked
        // its own handler, the probe warning would not reach this sentinel.
        $fired = false;
        \set_error_handler(static function (int $severity, string $message) use (&$fired): bool {
            $fired = true;
            return true;
        });
        try {
            try {
                RegexPattern::compile('/(/');
            } catch (RegexException) {
            }
            // compile must convert its own failure via its scoped handler, not
            // let the warning reach our sentinel.
            self::assertFalse($fired, 'compile converted its own warning; sentinel not reached');
            // If compile restored the stack, our sentinel is still on top and
            // observes this user warning; if it leaked, the leaked handler runs
            // instead and $fired stays false (or throws RegexException).
            \trigger_error('probe', \E_USER_WARNING);
            self::assertTrue($fired, 'sentinel handler must still be on top after a failed compile');
        } finally {
            \restore_error_handler();
        }
    }
}
