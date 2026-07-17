<?php

// src/Finding.php

namespace Yolorouter\Llmasking;

/** One recognized span. All offsets are UTF-8 byte offsets, half-open [start, end). */
final class Finding
{
    public function __construct(
        public readonly string $entity,
        public readonly int $start,
        public readonly int $end,
        public readonly float $score,
        public readonly string $text,
    ) {
    }
}
