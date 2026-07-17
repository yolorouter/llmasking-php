<?php

// src/StreamRestoreResult.php

namespace Yolorouter\Llmasking;

/** Immutable result of StreamRestorer::write / StreamRestorer::flush: restored text plus the list of restore events. */
final class StreamRestoreResult
{
    /**
     * @param list<RestoreEvent> $events
     */
    public function __construct(
        public readonly string $text,
        public readonly array $events,
    ) {
    }
}
