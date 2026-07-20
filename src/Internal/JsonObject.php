<?php

// src/Internal/JsonObject.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A JSON object token. $members preserves raw member order; duplicate keys
 * are retained (the library never folds an object into an associative map).
 */
final class JsonObject extends JsonValue
{
    /** @param list<JsonProperty> $members */
    public function __construct(
        int $start,
        int $end,
        int $depth,
        public readonly array $members,
    ) {
        parent::__construct($start, $end, $depth);
    }

    public function kind(): string
    {
        return 'object';
    }
}
