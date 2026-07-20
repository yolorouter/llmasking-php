<?php

// src/Internal/JsonPatchEntry.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A single staged replacement: substitute the bytes at $span with the
 * JSON-encoded form of $replacement (a decoded string). The patcher applies
 * entries in reverse byte order so a length-changing replacement cannot
 * invalidate later offsets (spec §9.3).
 *
 * $span always points at the CONTENT between framing quotes (never the
 * framing quotes themselves), as produced by JsonString::contentSpan() — the
 * patcher re-encodes only the inner content and leaves the surrounding
 * quotes intact.
 */
final class JsonPatchEntry
{
    public function __construct(
        public readonly StringSpan $span,
        public readonly string $replacement,
    ) {
    }
}
