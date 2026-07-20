<?php

// src/Internal/JsonArray.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A JSON array token. $elements preserves raw element order.
 */
final class JsonArray extends JsonValue
{
    /** @param list<JsonValue> $elements */
    public function __construct(
        int $start,
        int $end,
        int $depth,
        public readonly array $elements,
    ) {
        parent::__construct($start, $end, $depth);
    }

    public function kind(): string
    {
        return 'array';
    }
}
