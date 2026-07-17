<?php

// src/MaskEvent.php

namespace Yolorouter\Llmasking;

/** One masking operation emitted by Session::anonymize. Offsets are UTF-8 byte offsets, half-open [start, end). */
final class MaskEvent
{
    public function __construct(
        public readonly string $entity,
        public readonly int $start,
        public readonly int $end,
        public readonly float $score,
        public readonly string $replacement,
        public readonly bool $reversible,
        public readonly string $source = '',
    ) {
    }
}
