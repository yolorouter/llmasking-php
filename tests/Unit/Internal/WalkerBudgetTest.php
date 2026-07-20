<?php

// tests/Unit/Internal/WalkerBudgetTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Internal\WalkerBudget;

/**
 * Coverage for the cumulative projected-body budget (codex #15): the budget
 * starts at the initial body length, accumulates the (encoded-length −
 * span-length) delta of every staged patch, saturates upward, and rejects as
 * soon as the projected size would exceed MaxOutputBytes.
 */
final class WalkerBudgetTest extends TestCase
{
    public function testInitialProjectionEqualsBodyLength(): void
    {
        $b = new WalkerBudget(100, 1000);
        self::assertSame(100, $b->projectedBytes());
    }

    public function testGrowthPatchAccumulatesIntoProjection(): void
    {
        $b = new WalkerBudget(100, 1000);
        $b->stage(spanLength: 5, encodedLength: 8); // +3
        self::assertSame(103, $b->projectedBytes());
        $b->stage(spanLength: 4, encodedLength: 10); // +6
        self::assertSame(109, $b->projectedBytes());
    }

    public function testShrinkPatchReducesProjectionButNeverBelowZero(): void
    {
        $b = new WalkerBudget(100, 1000);
        $b->stage(spanLength: 10, encodedLength: 2); // -8
        self::assertSame(92, $b->projectedBytes());
        // A shrink that would drive the projection below 0 clamps to 0.
        $b->stage(spanLength: 200, encodedLength: 1);
        self::assertSame(0, $b->projectedBytes());
    }

    public function testRejectsWhenProjectionExceedsMaxOutputBytes(): void
    {
        $b = new WalkerBudget(100, 110);
        $b->stage(spanLength: 5, encodedLength: 10); // 100 + 5 = 105, OK
        self::assertSame(105, $b->projectedBytes());
        $this->expectException(LimitExceededException::class);
        $b->stage(spanLength: 1, encodedLength: 10); // 105 + 9 = 114 > 110
    }

    public function testRejectsOnExactBoundaryPlusOne(): void
    {
        $b = new WalkerBudget(50, 50);
        // Body already at the cap; any growth, however small, must fail-closed.
        $this->expectException(LimitExceededException::class);
        $b->stage(spanLength: 5, encodedLength: 6); // +1 → 51 > 50
    }

    public function testAllowsGrowthUpToAndIncludingTheCap(): void
    {
        $b = new WalkerBudget(50, 55);
        $b->stage(spanLength: 5, encodedLength: 10); // 50 + 5 = 55, OK (not >)
        self::assertSame(55, $b->projectedBytes());
    }

    public function testSaturatesUpwardToPreventOverflowToNegative(): void
    {
        // A long stream of growth-only patches cannot overflow the running
        // counter to negative and slip under the cap.
        $b = new WalkerBudget(0, \PHP_INT_MAX);
        for ($i = 0; $i < 100; $i++) {
            $b->stage(spanLength: 0, encodedLength: \PHP_INT_MAX - 10);
        }
        // The projection is saturated at PHP_INT_MAX, never overflowed.
        self::assertSame(\PHP_INT_MAX, $b->projectedBytes());
    }

    public function testSaturatedProjectionStillRejectsAboveCap(): void
    {
        // Initial body already over the cap: every subsequent stage() (even a
        // zero-delta one) must reject because projected > cap.
        $b = new WalkerBudget(1000, 100);
        self::assertSame(1000, $b->projectedBytes());
        $this->expectException(LimitExceededException::class);
        $b->stage(spanLength: 5, encodedLength: 5); // delta 0; still rejects
    }

    public function testNegativeBodyLengthClampsToZero(): void
    {
        $b = new WalkerBudget(-5, 100);
        self::assertSame(0, $b->projectedBytes());
    }
}
