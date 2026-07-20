<?php

// src/Internal/JsonProperty.php

namespace Yolorouter\Llmasking\Internal;

/**
 * A single object member: a key string plus its value. Member order is
 * preserved by the enclosing JsonObject's $members list, so duplicate keys
 * survive (the library never folds an object into an associative map — per
 * spec §9.2/§9.3 protocol-field duplicate keys AND duplicate free-text target
 * keys are both processed in their raw occurrence order by the walker).
 */
final class JsonProperty
{
    public function __construct(
        public readonly JsonString $key,
        public readonly JsonValue $value,
    ) {
    }
}
