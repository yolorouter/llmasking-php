<?php

// src/Internal/StringSpan.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Immutable byte-span reference into a JSON document. Both fields are byte
 * offsets (not character offsets); the span covers [start, start + length).
 *
 * Used by JsonDocument::stringValueSpans() to enumerate string-value targets
 * and by JsonPatchEntry to point at the content bytes that must be replaced.
 */
final class StringSpan
{
    public function __construct(
        public readonly int $start,
        public readonly int $length,
    ) {
        if ($start < 0 || $length < 0) {
            throw new \InvalidArgumentException('StringSpan start and length must be non-negative');
        }
    }

    /** Exclusive end offset (start + length). */
    public function end(): int
    {
        return $this->start + $this->length;
    }
}
