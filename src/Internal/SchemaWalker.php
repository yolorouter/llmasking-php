<?php

// src/Internal/SchemaWalker.php

namespace Yolorouter\Llmasking\Internal;

/**
 * JSON Schema routing-key walker (spec §9.3 SchemaNode traversal).
 *
 * NOT a JSON Schema validator. Uses the spec §9.3 routing-key table solely to
 * locate nested SchemaNodes and process each SchemaNode object's free-text
 * annotation members (description, title, $comment). Only string values are
 * targets; null/number/object/array values are skipped. Routing-key shapes that
 * aren't traversable (e.g. a boolean schema where an object is expected) are
 * skipped as-is — no validation.
 *
 * The dynamic keys inside SchemaNode maps (properties, patternProperties, etc.)
 * are traversed in decoded UTF-8 byte ascending order; duplicate decoded keys
 * break ties by raw member order. Fixed routing/target keys process duplicate
 * occurrences in raw member order. This deterministic order ensures stable
 * placeholder numbering and event ordering (spec §9.3).
 *
 * @internal
 */
final class SchemaWalker
{
    /**
     * Fixed target member names within each SchemaNode, in traversal order
     * (spec §9.3: SchemaNode free-text targets in order: description, title, $comment).
     */
    private const TARGET_KEYS = ['description', 'title', '$comment'];

    /**
     * Single-SchemaNode routing keys (spec §9.3 table), excluding `items` which
     * is handled specially (it can be a single SchemaNode OR a tuple array).
     */
    private const SINGLE_ROUTING_KEYS = [
        'additionalItems',
        'contains',
        'contentSchema',
        'additionalProperties',
        'unevaluatedItems',
        'unevaluatedProperties',
        'propertyNames',
        'not',
        'if',
        'then',
        'else',
    ];

    /**
     * SchemaNode-array routing keys (spec §9.3 table). `items` is excluded
     * (handled specially); `prefixItems`, `allOf`, `anyOf`, `oneOf` are always
     * arrays when present.
     */
    private const ARRAY_ROUTING_KEYS = ['prefixItems', 'allOf', 'anyOf', 'oneOf'];

    /**
     * SchemaNode-map routing keys (spec §9.3 table): properties,
     * patternProperties, $defs, definitions, dependentSchemas. Dynamic member
     * keys are sorted by decoded UTF-8 byte ascending order.
     */
    private const MAP_ROUTING_KEYS = [
        'properties',
        'patternProperties',
        '$defs',
        'definitions',
        'dependentSchemas',
    ];

    /** @var list<JsonPatchEntry> */
    private array $patches = [];

    private function __construct(
        private readonly string $json,
        private readonly \Closure $process,
        private readonly ?WalkerBudget $budget,
    ) {
    }

    /**
     * Walk a SchemaNode subtree, staging patches for every description/title/
     * $comment string value found.
     *
     * $process receives each decoded string value and returns its replacement.
     * A patch is staged only when the replacement differs from the decoded
     * original (spec §9.3: only actual replacements generate patches). Each
     * staged patch is charged against $budget when non-null, so a caller that
     * shares one WalkerBudget across walkers gets a single cumulative
     * projected-body bound (codex #15).
     *
     * @param \Closure(string): string $process Decoded value -> replacement.
     * @return list<JsonPatchEntry>
     */
    public static function walk(JsonValue $node, string $json, \Closure $process, ?WalkerBudget $budget = null): array
    {
        $walker = new self($json, $process, $budget);
        $walker->visitSchemaNode($node);
        return $walker->patches;
    }

    /**
     * Visit a single SchemaNode: process its own targets, then traverse its
     * routing keys in spec table order to find nested SchemaNodes. Non-object
     * values (boolean schemas, null, etc.) are silently skipped.
     */
    private function visitSchemaNode(JsonValue $node): void
    {
        if (!$node instanceof JsonObject) {
            return;
        }

        // 1. SchemaNode's own free-text targets (fixed order: description, title, $comment).
        // Duplicate target-key occurrences are processed in raw member order.
        foreach (self::TARGET_KEYS as $name) {
            foreach ($this->membersByName($node, $name) as $value) {
                $this->processStringTarget($value);
            }
        }

        // 2. `items` — special: may be a single SchemaNode or a tuple array of
        //    SchemaNodes (spec table lists items in BOTH categories). Check shape.
        foreach ($this->membersByName($node, 'items') as $items) {
            if ($items instanceof JsonArray) {
                foreach ($items->elements as $element) {
                    $this->visitSchemaNode($element);
                }
            } else {
                $this->visitSchemaNode($items);
            }
        }

        // 3. Single-SchemaNode routing keys (table order).
        foreach (self::SINGLE_ROUTING_KEYS as $key) {
            foreach ($this->membersByName($node, $key) as $value) {
                $this->visitSchemaNode($value);
            }
        }

        // 4. SchemaNode-array routing keys (table order).
        foreach (self::ARRAY_ROUTING_KEYS as $key) {
            foreach ($this->membersByName($node, $key) as $value) {
                if ($value instanceof JsonArray) {
                    foreach ($value->elements as $element) {
                        $this->visitSchemaNode($element);
                    }
                }
                // Non-array value for an array-key: skip as-is (not traversable).
            }
        }

        // 5. SchemaNode-map routing keys (table order). Dynamic keys sorted.
        foreach (self::MAP_ROUTING_KEYS as $key) {
            foreach ($this->membersByName($node, $key) as $value) {
                $this->visitSchemaMap($value);
            }
        }

        // 6. dependencies (draft-07 mixed): object values are SchemaNodes;
        //    array values (string dependency lists) are skipped.
        foreach ($this->membersByName($node, 'dependencies') as $value) {
            $this->visitSchemaMap($value);
        }
    }

    /**
     * Visit a SchemaNode map: an object whose member VALUES are SchemaNodes.
     * Dynamic member keys are sorted by decoded UTF-8 byte ascending order;
     * ties broken by raw member order (spec §9.3). Non-object member values
     * (boolean schemas, arrays, etc.) are skipped by visitSchemaNode.
     */
    private function visitSchemaMap(JsonValue $map): void
    {
        if (!$map instanceof JsonObject) {
            return;
        }

        /** @var list<array{key:string, ord:int, value:JsonValue}> $entries */
        $entries = [];
        $ord = 0;
        foreach ($map->members as $member) {
            $entries[] = [
                'key' => $this->decodeString($member->key),
                'ord' => $ord++,
                'value' => $member->value,
            ];
        }

        \usort(
            $entries,
            /** @param array{key:string, ord:int, value:JsonValue} $a */
            /** @param array{key:string, ord:int, value:JsonValue} $b */
            static function (array $a, array $b): int {
                $cmp = \strcmp($a['key'], $b['key']);
                return $cmp !== 0 ? $cmp : ($a['ord'] <=> $b['ord']);
            },
        );

        foreach ($entries as $entry) {
            $this->visitSchemaNode($entry['value']);
        }
    }

    /**
     * Process a single target member value: if it is a JSON string, decode it,
     * call the processor, and stage a patch when the replacement differs from
     * the decoded original. Each staged patch is charged against the shared
     * projected-body budget before being appended (codex #15).
     */
    private function processStringTarget(JsonValue $value): void
    {
        if (!$value instanceof JsonString) {
            return;
        }
        $decoded = $this->decodeString($value);
        $replacement = ($this->process)($decoded);
        if ($replacement !== $decoded) {
            $encodedLength = \strlen(JsonPatch::encodeStringContent($replacement));
            $this->budget?->stage($value->contentLength, $encodedLength);
            $this->patches[] = new JsonPatchEntry($value->contentSpan(), $replacement);
        }
    }

    /**
     * Find all member values whose decoded key equals $name, in raw member
     * order. Uses decoded comparison so escaped key forms (e.g.
     * "description") match the same way as their unescaped equivalent.
     *
     * @return list<JsonValue>
     */
    private function membersByName(JsonObject $obj, string $name): array
    {
        $out = [];
        foreach ($obj->members as $member) {
            if ($this->decodeString($member->key) === $name) {
                $out[] = $member->value;
            }
        }
        return $out;
    }

    /**
     * Decode a JSON string token to its PHP string value. The tokenizer has
     * already validated the syntax, so json_decode cannot throw here.
     */
    private function decodeString(JsonString $s): string
    {
        // Substring includes framing quotes so json_decode sees a valid JSON string.
        $quoted = \substr($this->json, $s->start, $s->end - $s->start);
        $result = \json_decode($quoted, false, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_string($result));

        return $result;
    }
}
