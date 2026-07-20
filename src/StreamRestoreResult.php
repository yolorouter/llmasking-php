<?php

// src/StreamRestoreResult.php

namespace Yolorouter\Llmasking;

/**
 * Immutable result of StreamRestorer::write / flush: restored text plus the
 * list of restore events, AND the restored output as ordered pieces so a
 * downstream segment-based stream can attach each RestoreEvent to the segment
 * containing that placeholder's own final byte (spec §9.6 per-piece
 * placement), rather than to the whole replacement's end.
 *
 * Each piece is `array{0:string, 1:list<RestoreEvent>}`: a text fragment and
 * the events whose restored plaintext ends within it. Concatenating the piece
 * texts reproduces $text; flattening the piece events reproduces $events.
 */
final class StreamRestoreResult
{
    /**
     * @param list<RestoreEvent> $events
     * @param list<array{0:string, 1:list<RestoreEvent>}> $pieces
     */
    public function __construct(
        public readonly string $text,
        public readonly array $events,
        public readonly array $pieces = [],
    ) {
    }
}
