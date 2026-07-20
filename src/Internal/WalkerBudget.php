<?php

// src/Internal/WalkerBudget.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Exception\LimitExceededException;

/**
 * Cumulative projected-output-byte budget shared by the walkers that stage
 * patches against one JSON body (RequestWalker, ResponseWalker, SchemaWalker).
 *
 * Each staged patch grows the projected body size by (encoded-length −
 * span-length); when the projected size would exceed MaxOutputBytes the stager
 * rejects with LimitExceededException, so a walker can never stage enough
 * near-MaxOutputBytes replacements to push the final patched body past the
 * bound (spec §5.1; codex #15).
 *
 * The running total saturates at PHP_INT_MAX so a long stream of growth-only
 * patches cannot overflow to a negative value and slip under the cap.
 *
 * @internal
 */
final class WalkerBudget
{
    private int $projected;

    public function __construct(int $bodyLength, private readonly int $maxOutputBytes)
    {
        if ($bodyLength < 0) {
            $bodyLength = 0;
        }
        $this->projected = $bodyLength;
    }

    /**
     * Account for one staged patch's encoded-length delta and reject when the
     * projected body size would exceed MaxOutputBytes. Callers MUST invoke this
     * for every staged patch BEFORE the patch list is handed to JsonPatch —
     * the budget is the walker's only chance to fail-closed before the patched
     * bytes are materialized.
     */
    public function stage(int $spanLength, int $encodedLength): void
    {
        $delta = $encodedLength - $spanLength;
        if ($delta >= 0) {
            // Saturate upward so an unbounded stream of growth deltas cannot
            // overflow the running counter (which would let a later shrink
            // drop us below the cap).
            if ($this->projected > \PHP_INT_MAX - $delta) {
                $this->projected = \PHP_INT_MAX;
            } else {
                $this->projected += $delta;
            }
        } elseif ($this->projected + $delta >= 0) {
            // Shrinking a span never trips the cap, but still track the new
            // projected size so a subsequent growth patch is measured against
            // the actual projected body length.
            $this->projected += $delta;
        } else {
            $this->projected = 0;
        }
        if ($this->projected > $this->maxOutputBytes) {
            throw new LimitExceededException('walker output exceeds MaxOutputBytes');
        }
    }

    /**
     * Current projected body length (initial body bytes + cumulative delta).
     */
    public function projectedBytes(): int
    {
        return $this->projected;
    }
}
