<?php

// src/Internal/JsonTree.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Shared, allocation-free helpers for reading the tokenizer's raw-span tree.
 *
 * The walkers (RequestWalker / ResponseWalker / SchemaWalker) and the SSE
 * restorer all need to (a) decode a JsonString token to its PHP value from the
 * original JSON bytes and (b) collect member values of a JsonObject by decoded
 * key name. Centralizing those two operations here removes four near-identical
 * copies without changing behaviour: callers bind their own JSON source
 * ($doc->json / $json) and delegate.
 *
 * @internal
 */
final class JsonTree
{
    /**
     * Decode a JSON string token to its PHP string value. The tokenizer has
     * already validated the syntax, so json_decode cannot throw here.
     */
    public static function decodeString(JsonString $s, string $json): string
    {
        $quoted = \substr($json, $s->start, $s->end - $s->start);
        $result = \json_decode($quoted, false, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_string($result));

        return $result;
    }

    /**
     * Find all member values whose decoded key equals $name, in raw member
     * order. Decoded comparison ensures escaped key forms (e.g. "description")
     * match the same way as their unescaped equivalent.
     *
     * @return list<JsonValue>
     */
    public static function membersByName(JsonObject $obj, string $name, string $json): array
    {
        $out = [];
        foreach ($obj->members as $member) {
            if (self::decodeString($member->key, $json) === $name) {
                $out[] = $member->value;
            }
        }

        return $out;
    }
}
