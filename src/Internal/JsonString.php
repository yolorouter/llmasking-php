<?php

// src/Internal/JsonString.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A JSON string token. start/end include the framing double-quotes;
 * contentStart/contentLength span only the raw bytes BETWEEN those quotes
 * (escapes preserved verbatim, no decoding). rawContent() is a convenience
 * accessor that substrings the original JSON.
 *
 * The same class represents both member keys and string values; member order
 * and duplicate-key occurrences are preserved by the enclosing JsonObject's
 * $members list, never by folding into a PHP associative array.
 */
final class JsonString extends JsonValue
{
    public function __construct(
        int $start,
        int $end,
        int $depth,
        public readonly int $contentStart,
        public readonly int $contentLength,
    ) {
        parent::__construct($start, $end, $depth);
    }

    public function kind(): string
    {
        return 'string';
    }

    /** Raw bytes between the framing quotes (escapes preserved, not decoded). */
    public function rawContent(string $json): string
    {
        return \substr($json, $this->contentStart, $this->contentLength);
    }

    /** Span pointing at the content between the framing quotes (excl. quotes). */
    public function contentSpan(): StringSpan
    {
        return new StringSpan($this->contentStart, $this->contentLength);
    }
}
