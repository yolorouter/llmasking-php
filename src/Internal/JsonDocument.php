<?php

// src/Internal/JsonDocument.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Result of {@see JsonTokenizer::parse()}. Holds the original JSON bytes
 * alongside the parsed tree, plus the resource-bound counters observed
 * during parsing. The tree preserves object member order and string raw
 * spans so the walker (spec §9.3) can navigate by path and stage patches
 * without ever calling json_decode/json_encode on the whole document.
 */
final class JsonDocument
{
    public function __construct(
        public readonly string $json,
        public readonly JsonValue $root,
        public readonly int $nodeCount,
        public readonly int $maxDepthReached,
    ) {
    }

    /**
     * Flat ordered list of every JSON string *value* (NOT member keys) in
     * raw byte order. Each span points at the content between framing quotes.
     *
     * This is the "ordered list of string-value spans" required by spec §9.2:
     * the walker enumerates target string occurrences from this list (or by
     * navigating the tree directly) when staging patches. Member keys are
     * excluded because the walker matches them via path navigation, not via
     * the value-span list.
     *
     * @return list<StringSpan>
     */
    public function stringValueSpans(): array
    {
        $out = [];
        $this->collectValueStrings($this->root, $out);
        return $out;
    }

    /** @param list<StringSpan> $out */
    private function collectValueStrings(JsonValue $v, array &$out): void
    {
        if ($v instanceof JsonString) {
            $out[] = new StringSpan($v->contentStart, $v->contentLength);
        } elseif ($v instanceof JsonObject) {
            foreach ($v->members as $m) {
                $this->collectValueStrings($m->value, $out);
            }
        } elseif ($v instanceof JsonArray) {
            foreach ($v->elements as $e) {
                $this->collectValueStrings($e, $out);
            }
        }
    }
}
