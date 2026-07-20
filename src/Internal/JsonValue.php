<?php

// src/Internal/JsonValue.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Base class for every node in the parsed JSON tree. Each concrete subclass
 * corresponds to one JSON value type. Offsets are byte offsets into the
 * original JSON document; [start, end) covers the whole token (including
 * framing quotes for strings, braces for objects, brackets for arrays).
 *
 * depth is 1 for the root value and increments for each nesting level
 * (object members and array elements live one level deeper than their
 * container). depth is what the spec §9.2 maxJsonDepth=64 bound is checked
 * against.
 */
abstract class JsonValue
{
    public function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly int $depth,
    ) {
    }

    /**
     * One of: 'object', 'array', 'string', 'number', 'true', 'false', 'null'.
     * For JsonScalar the kind is the underlying literal kind ('number' | 'true'
     * | 'false' | 'null'); for the container/string subclasses it is the
     * structural type.
     */
    abstract public function kind(): string;
}
