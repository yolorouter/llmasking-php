<?php

// src/Transport/SseRestoreResult.php

namespace Yolorouter\Llmasking\Transport;

use Yolorouter\Llmasking\RestoreEvent;

/**
 * Immutable result of SseRestorer::write / flush. Holds the restored SSE wire
 * output as a list of at-most-16 KiB segments (spec §9.5/§9.6), each carrying
 * the RestoreEvents whose restored text is fully contained in that segment's
 * bytes (an event attaches only to the segment containing the final byte of
 * its restored text, so a downstream stream never commits an event before its
 * plaintext is fully delivered).
 *
 * Each segment is `array{0:string, 1:list<RestoreEvent>}`. joinedBytes() and
 * allEvents() are convenience views for callers/tests that consume the whole
 * result at once; the streaming path consumes $segments one at a time.
 */
final class SseRestoreResult
{
    /**
     * @param list<array{0:string, 1:list<RestoreEvent>}> $segments
     */
    public function __construct(
        public readonly array $segments = [],
    ) {
    }

    /**
     * Concatenation of every segment's bytes (whole-result view for callers
     * that drain the entire result synchronously, e.g. tests).
     */
    public function joinedBytes(): string
    {
        $out = '';
        foreach ($this->segments as $seg) {
            $out .= $seg[0];
        }

        return $out;
    }

    /**
     * All RestoreEvents across segments, in delivery order (whole-result view).
     *
     * @return list<RestoreEvent>
     */
    public function allEvents(): array
    {
        $out = [];
        foreach ($this->segments as $seg) {
            foreach ($seg[1] as $e) {
                $out[] = $e;
            }
        }

        return $out;
    }
}
