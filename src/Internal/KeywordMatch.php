<?php

// src/Internal/KeywordMatch.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Immutable result of KeywordMatcher::findLeftmostLongest(). Offsets are
 * half-open byte spans [start, end); patternIndex is the position of the
 * matched pattern in the construction-time list. Because byte-level duplicate
 * patterns are rejected at construction, a (start, end) span uniquely
 * identifies a single pattern; patternIndex is a defensive tiebreaker only.
 *
 * @internal
 */
final class KeywordMatch
{
    public function __construct(
        public readonly int $patternIndex,
        public readonly int $start,
        public readonly int $end,
    ) {
    }
}
