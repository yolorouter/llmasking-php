<?php

// src/Internal/PlaceholderToken.php

namespace Yolorouter\Llmasking\Internal;

/**
 * One placeholder-shaped span recognized by PlaceholderLexer. `valid` is true
 * only when the sequence-number digit run is within the maxSeqDigits width and
 * its decimal value fits in a native int: an over-wide zero-padded run is still
 * reported (so Restore can surface it as unresolved) but must never be parsed
 * into an int and looked up, since that would collapse it onto a shorter,
 * genuine placeholder.
 */
final class PlaceholderToken
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly string $entity,
        public readonly string $seqRaw,
        public readonly int $seq,
        public readonly bool $valid,
    ) {
    }
}
