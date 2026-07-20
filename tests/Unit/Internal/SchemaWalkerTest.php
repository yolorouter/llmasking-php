<?php

// tests/Unit/Internal/SchemaWalkerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\SchemaWalker;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\MaskEvent;

/**
 * Coverage for the JSON Schema routing-key walker (spec §9.3 SchemaNode
 * traversal): it locates SchemaNode-level free-text targets
 * (description/title/$comment), uses routing keys only to find the NEXT level
 * of SchemaNodes (never validates), sorts dynamic map keys by decoded UTF-8
 * bytes, and processes duplicate keys in raw member order.
 */
final class SchemaWalkerTest extends TestCase
{
    /**
     * Returns [processor, getSeen] where processor records each decoded string
     * it receives and wraps it in brackets (always differs from the original,
     * guaranteeing a patch). getSeen() returns the recorded values.
     *
     * @return array{0: \Closure(string): array{0:string, 1:list<MaskEvent>}, 1: \Closure(): list<string>}
     */
    private static function recordingProcessor(): array
    {
        $seen = [];
        $processor = static function (string $decoded) use (&$seen): array {
            $seen[] = $decoded;
            return ['<' . $decoded . '>', []];
        };
        $getSeen = static function () use (&$seen): array {
            return $seen;
        };
        return [$processor, $getSeen];
    }

    public function testProcessesDescriptionTitleCommentInOrder(): void
    {
        $json = '{"description":"d","title":"t","$comment":"c","type":"object"}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['d', 't', 'c'], $getSeen());
        self::assertCount(3, $patches);
        $out = JsonPatch::apply($json, $patches);
        self::assertSame('{"description":"<d>","title":"<t>","$comment":"<c>","type":"object"}', $out);
    }

    public function testNonStringTargetValuesAreSkipped(): void
    {
        // null, number, true, false, object, array — none are valid string targets.
        $json = '{"description":null,"title":42,"$comment":true,"type":"string"}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame([], $getSeen());
        self::assertSame([], $patches);
    }

    public function testRoutingKeyItemsAsSingleSchema(): void
    {
        $json = '{"description":"root","items":{"description":"item"}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['root', 'item'], $getSeen());
        self::assertCount(2, $patches);
    }

    public function testRoutingKeyItemsAsTupleArray(): void
    {
        $json = '{"items":[{"description":"a"},{"description":"b"}]}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['a', 'b'], $getSeen());
        self::assertCount(2, $patches);
    }

    public function testRoutingKeyAllOfAnyOfOneOfInTableOrder(): void
    {
        // Table order: prefixItems, allOf, anyOf, oneOf — regardless of raw order.
        $json = '{"oneOf":[{"description":"1"}],"anyOf":[{"description":"2"}],"allOf":[{"description":"3"}]}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // allOf before anyOf before oneOf
        self::assertSame(['3', '2', '1'], $getSeen());
    }

    public function testSingleRoutingKeysLeadToNestedProcessing(): void
    {
        $json = '{"additionalProperties":{"description":"ap"},"not":{"description":"not"},'
            . '"if":{"description":"if"},"then":{"description":"then"},"else":{"description":"else"},'
            . '"contains":{"description":"contains"},"propertyNames":{"description":"pn"}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Single routing key order: additionalItems, contains, contentSchema,
        // additionalProperties, unevaluatedItems, unevaluatedProperties,
        // propertyNames, not, if, then, else
        self::assertSame(['contains', 'ap', 'pn', 'not', 'if', 'then', 'else'], $getSeen());
    }

    public function testMapRoutingKeysSortByDecodedUtf8Bytes(): void
    {
        $json = '{"properties":{"zebra":{"description":"z"},"apple":{"description":"a"},"mango":{"description":"m"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Dynamic keys sorted by decoded UTF-8 byte ascending order.
        self::assertSame(['a', 'm', 'z'], $getSeen());
    }

    public function testPatternPropertiesDefsDefinitionsDependentSchemasTraversed(): void
    {
        $json = '{"patternProperties":{"^a":{"description":"pa"}},'
            . '"$defs":{"foo":{"description":"def"}},'
            . '"definitions":{"bar":{"description":"defi"}},'
            . '"dependentSchemas":{"baz":{"description":"ds"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Order: properties (none), patternProperties, $defs, definitions, dependentSchemas
        self::assertSame(['pa', 'def', 'defi', 'ds'], $getSeen());
    }

    public function testDependenciesObjectFormTraversedArrayFormSkipped(): void
    {
        // Object form (schema dependency) → traversed; array form (property list) → skipped.
        $json = '{"dependencies":{"foo":{"description":"dep"},"bar":["other","props"]}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Keys sorted by decoded bytes: bar (array, skipped), foo (object, processed).
        self::assertSame(['dep'], $getSeen());
    }

    public function testPropertyNameMatchingTargetNameIsNotItselfProcessed(): void
    {
        // The key "description" inside properties is a dynamic map key, not a
        // SchemaNode-level target. Only the nested SchemaNode's description is processed.
        $json = '{"description":"root desc","properties":{"description":{"description":"prop desc"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['root desc', 'prop desc'], $getSeen());
    }

    public function testProtocolKeywordsNotTraversedOrProcessed(): void
    {
        // enum, const, default, examples, $ref, $id, $schema, type, format,
        // pattern, required, etc. are all protocol fields — never processed or traversed.
        $json = '{"description":"d","enum":["a","b"],"const":"c","default":"def",'
            . '"examples":["e"],"$ref":"#/foo","$id":"http://x","$schema":"http://y",'
            . '"type":"object","format":"uri","pattern":"^a","required":["x"]}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['d'], $getSeen());
    }

    public function testBooleanSchemaTrueFalseSkipped(): void
    {
        $json = '{"properties":{"a":true,"b":false,"c":{"description":"ok"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['ok'], $getSeen());
    }

    public function testDuplicateTargetKeysProcessedInRawOrder(): void
    {
        // Duplicate description members — each occurrence processed in raw order.
        $json = '{"description":"first","description":"second"}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['first', 'second'], $getSeen());
        self::assertCount(2, $patches);
    }

    public function testDuplicateRoutingKeysProcessedInRawOrder(): void
    {
        $json = '{"allOf":[{"description":"a"}],"allOf":[{"description":"b"}]}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['a', 'b'], $getSeen());
    }

    public function testDuplicateMapKeysSortedByRawOrderForSameDecodedKey(): void
    {
        // Two properties with the same decoded key "foo": raw order tiebreaker.
        $json = '{"properties":{"foo":{"description":"first"},"foo":{"description":"second"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['first', 'second'], $getSeen());
    }

    public function testNoPatchWhenReplacementEqualsOriginal(): void
    {
        // Processor returns the same string — no patch staged.
        $json = '{"description":"keep"}';
        $doc = JsonTokenizer::parse($json);
        [$patches, $events] = SchemaWalker::walk(
            $doc->root,
            $doc->json,
            static fn (string $s): array => [$s, [new MaskEvent('E', 0, 0, 0.0, 'r', true)]],
        );

        self::assertSame([], $patches);
        // No phantom event: an identity (unchanged) result commits none (codex med).
        self::assertSame([], $events);
    }

    public function testIdentityResultAlongsideRealPatchCommitsOnlyRealEvent(): void
    {
        // description is identity (no change → no event); title changes (patch →
        // its event committed). Only the real patch's event is reported.
        $json = '{"description":"a","title":"b"}';
        $doc = JsonTokenizer::parse($json);
        $call = 0;
        [$patches, $events] = SchemaWalker::walk(
            $doc->root,
            $doc->json,
            static function (string $decoded) use (&$call): array {
                $call++;
                // description (call 1): identity; title (call 2): changed.
                return $call === 1
                    ? [$decoded, [new MaskEvent('ID', 0, 0, 0.0, $decoded, true)]]
                    : ['<' . $decoded . '>', [new MaskEvent('REAL', 0, 0, 0.0, '<' . $decoded . '>', true)]];
            },
        );

        self::assertCount(1, $patches);
        self::assertCount(1, $events);
        self::assertSame('REAL', $events[0]->entity);
    }

    public function testEmptyObjectSchemaIsNoOp(): void
    {
        $json = '{}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame([], $getSeen());
        self::assertSame([], $patches);
    }

    public function testNonObjectRootIsSkipped(): void
    {
        // A schema that is just true/false/null/string — not traversable.
        foreach (['true', 'false', 'null', '"hello"', '42'] as $json) {
            $doc = JsonTokenizer::parse($json);
            [$proc, $getSeen] = self::recordingProcessor();
            [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

            self::assertSame([], $getSeen(), "root=$json");
            self::assertSame([], $patches, "root=$json");
        }
    }

    public function testDeeplyNestedSchemaTraversal(): void
    {
        $json = '{"allOf":[{"properties":{"a":{"allOf":[{"properties":{"b":{"description":"deep"}}}]}}}],' .
            '"description":"root"}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Root description first, then allOf → properties.a → allOf → properties.b → description.
        self::assertSame(['root', 'deep'], $getSeen());
        self::assertCount(2, $patches);
    }

    public function testPatchSpansPointAtContentBetweenQuotes(): void
    {
        // Verify the span in each patch covers the content bytes (not framing quotes).
        $json = '{"description":"abc"}';
        $doc = JsonTokenizer::parse($json);
        [$patches] = SchemaWalker::walk(
            $doc->root,
            $doc->json,
            static fn (string $s): array => ['X', []],
        );

        self::assertCount(1, $patches);
        $patch = $patches[0];
        $spanned = \substr($doc->json, $patch->span->start, $patch->span->length);
        self::assertSame('abc', $spanned, 'span must point at content between quotes');
        self::assertSame('X', $patch->replacement);
    }

    public function testRoutingKeyPrefixItemsTraversedAsArray(): void
    {
        $json = '{"prefixItems":[{"description":"p1"},{"description":"p2"}]}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        self::assertSame(['p1', 'p2'], $getSeen());
    }

    public function testRoutingKeyAdditionalItemsContentSchemaUnevaluatedHandled(): void
    {
        $json = '{"additionalItems":{"description":"ai"},"contentSchema":{"description":"cs"},'
            . '"unevaluatedItems":{"description":"ui"},"unevaluatedProperties":{"description":"up"}}';
        $doc = JsonTokenizer::parse($json);
        [$proc, $getSeen] = self::recordingProcessor();
        SchemaWalker::walk($doc->root, $doc->json, $proc);

        // Order: additionalItems, contains(none), contentSchema, additionalProperties(none),
        // unevaluatedItems, unevaluatedProperties, ...
        self::assertSame(['ai', 'cs', 'ui', 'up'], $getSeen());
    }

    public function testFullOutputPreservesNonTargetBytes(): void
    {
        // Apply patches and verify non-target fields are preserved byte-for-byte.
        $json = '{"description":"mask me","type":"object","properties":{"x":{"type":"integer"}}}';
        $doc = JsonTokenizer::parse($json);
        [$proc] = self::recordingProcessor();
        [$patches] = SchemaWalker::walk($doc->root, $doc->json, $proc);

        $out = JsonPatch::apply($json, $patches);
        self::assertStringContainsString('"type":"object"', $out);
        self::assertStringContainsString('"properties":{"x":{"type":"integer"}}', $out);
        self::assertStringContainsString('"description":"<mask me>"', $out);
    }
}
