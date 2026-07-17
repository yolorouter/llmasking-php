<?php

// src/RestoreEvent.php

namespace Yolorouter\Llmasking;

/** One restore operation emitted by Session::restore / StreamRestorer. Offsets are UTF-8 byte offsets, half-open [start, end). */
final class RestoreEvent
{
    public function __construct(
        public readonly string $entity,
        public readonly int $start,
        public readonly int $end,
        public readonly string $placeholder,
        public readonly bool $restored,
        public readonly string $source = '',
    ) {
    }
}
