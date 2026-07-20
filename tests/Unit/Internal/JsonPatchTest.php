<?php

// tests/Unit/Internal/JsonPatchTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\JsonArray;
use Yolorouter\Llmasking\Internal\JsonObject;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\JsonPatchEntry;
use Yolorouter\Llmasking\Internal\JsonString;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\Internal\StringSpan;

/**
 * Coverage for the raw-preserving patcher (spec §9.3): single + multiple
 * non-overlapping patches, length-changing replacements (escape expansion),
 * byte-for-byte preservation of unpatched spans, and the
 * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES encoding contract.
 */
final class JsonPatchTest extends TestCase
{
    public function testEmptyPatchesReturnsOriginalBytes(): void
    {
        $json = '{"a":"b"}';
        self::assertSame($json, JsonPatch::apply($json, []));
        self::assertSame('', JsonPatch::apply('', []));
    }

    public function testSinglePatchSubstitutesStringContent(): void
    {
        $json = '{"content":"hello world"}';
        $contentValue = self::stringValueAt($json, ['content']);
        self::assertInstanceOf(JsonString::class, $contentValue);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($contentValue->contentSpan(), '[PHONE_1]'),
        ]);
        self::assertSame('{"content":"[PHONE_1]"}', $patched);
    }

    public function testMultipleNonOverlappingPatchesAppliedCorrectly(): void
    {
        $json = '{"a":"AAA","b":"BBB","c":"CCC"}';
        $a = self::stringValueAt($json, ['a']);
        $b = self::stringValueAt($json, ['b']);
        $c = self::stringValueAt($json, ['c']);
        self::assertInstanceOf(JsonString::class, $a);
        self::assertInstanceOf(JsonString::class, $b);
        self::assertInstanceOf(JsonString::class, $c);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($a->contentSpan(), 'X1'),
            new JsonPatchEntry($b->contentSpan(), 'X2'),
            new JsonPatchEntry($c->contentSpan(), 'X3'),
        ]);
        self::assertSame('{"a":"X1","b":"X2","c":"X3"}', $patched);
    }

    public function testPatchPreservesNonTargetSpansByteForByte(): void
    {
        // Mix of: number literal, ad-hoc whitespace, key order, unknown fields,
        // and a nested array. Only messages[0].content is patched.
        $json = '{"model":"gpt-x","n":42, "messages":[{"role":"user","content":"call 13800138000"}]}';
        $messages = self::valueAt($json, ['messages']);
        self::assertInstanceOf(JsonArray::class, $messages);
        $msg0 = $messages->elements[0];
        self::assertInstanceOf(JsonObject::class, $msg0);
        $content = self::memberValue($msg0, $json, 'content');
        self::assertInstanceOf(JsonString::class, $content);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($content->contentSpan(), '[PHONE_1]'),
        ]);

        // Non-target fields preserved byte-for-byte, including the irregular
        // whitespace after the comma following "n":42.
        self::assertStringContainsString('"model":"gpt-x"', $patched);
        self::assertStringContainsString('"n":42, "messages"', $patched);
        self::assertStringContainsString('"role":"user"', $patched);
        self::assertStringContainsString('"content":"[PHONE_1]"', $patched);

        // The decoded "n" is still the integer 42 (no float coercion).
        $decoded = \json_decode($patched, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        self::assertSame(42, $decoded['n']);
        self::assertSame('[PHONE_1]', $decoded['messages'][0]['content']);
    }

    public function testPatchWithReplacementLongerThanOriginal(): void
    {
        // Escape expansion: replacement contains chars that JSON-encode to
        // more bytes than the original span (quote + newline + backslash).
        $json = '{"x":"a"}';
        $x = self::stringValueAt($json, ['x']);
        self::assertInstanceOf(JsonString::class, $x);

        $replacement = 'he said "hi"' . "\n" . 'tab\\here';
        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($x->contentSpan(), $replacement),
        ]);
        $decoded = \json_decode($patched, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        self::assertSame($replacement, $decoded['x']);
    }

    public function testPatchWithReplacementShorterThanOriginal(): void
    {
        $json = '{"x":"abcdefgh"}';
        $x = self::stringValueAt($json, ['x']);
        self::assertInstanceOf(JsonString::class, $x);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($x->contentSpan(), 'X'),
        ]);
        self::assertSame('{"x":"X"}', $patched);
    }

    public function testUnicodeReplacementUsesJsonUnescapedUnicode(): void
    {
        // With JSON_UNESCAPED_UNICODE, é stays as raw 2-byte UTF-8 on the wire.
        $json = '{"x":"a"}';
        $x = self::stringValueAt($json, ['x']);
        self::assertInstanceOf(JsonString::class, $x);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($x->contentSpan(), 'café'),
        ]);
        self::assertStringNotContainsString('\\u', $patched, 'non-ASCII must not be \\u escaped');
        self::assertSame('{"x":"café"}', $patched);
    }

    public function testSlashesInReplacementNotEscaped(): void
    {
        // With JSON_UNESCAPED_SLASHES, forward slashes stay bare.
        $json = '{"x":"a"}';
        $x = self::stringValueAt($json, ['x']);
        self::assertInstanceOf(JsonString::class, $x);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($x->contentSpan(), 'https://example.com/x/y'),
        ]);
        self::assertSame('{"x":"https://example.com/x/y"}', $patched);
    }

    public function testOverlappingPatchesRejected(): void
    {
        $json = '{"ab":"x"}';
        // Two patches whose spans overlap inside the same key string.
        $patches = [
            new JsonPatchEntry(new StringSpan(1, 3), 'A'),
            new JsonPatchEntry(new StringSpan(2, 2), 'B'),
        ];
        $this->expectException(\InvalidArgumentException::class);
        JsonPatch::apply($json, $patches);
    }

    public function testPatchSpanBeyondEndOfJsonRejected(): void
    {
        $json = '{"x":"a"}';
        $patches = [
            new JsonPatchEntry(new StringSpan(0, 9999), 'A'),
        ];
        $this->expectException(\InvalidArgumentException::class);
        JsonPatch::apply($json, $patches);
    }

    public function testEncodeStringContentRoundTripsThroughJsonDecode(): void
    {
        $decoded = 'line1' . "\n" . 'line2 "quote" \\ backslash / slash';
        $encoded = JsonPatch::encodeStringContent($decoded);
        // Placing the encoded content between framing quotes must yield a
        // valid JSON string that decodes back to the original.
        $roundTrip = \json_decode('"' . $encoded . '"', true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($decoded, $roundTrip);
    }

    public function testEncodeStringContentUsesUnescapedUnicodeAndSlashes(): void
    {
        $encoded = JsonPatch::encodeStringContent('café https://x');
        self::assertStringNotContainsString('\\u', $encoded);
        self::assertStringNotContainsString('\\/', $encoded);
        self::assertSame('café https://x', \json_decode('"' . $encoded . '"', true));
    }

    public function testMultiplePatchesWithMixedLengthChanges(): void
    {
        // Three patches — each changes length differently. Reverse-order
        // application keeps offsets stable for later-applied (earlier-byte)
        // patches regardless of length growth/shrinkage.
        $json = '{"a":"AAAA","b":"BB","c":"CCCCCCC"}';
        $a = self::stringValueAt($json, ['a']);
        $b = self::stringValueAt($json, ['b']);
        $c = self::stringValueAt($json, ['c']);
        self::assertInstanceOf(JsonString::class, $a);
        self::assertInstanceOf(JsonString::class, $b);
        self::assertInstanceOf(JsonString::class, $c);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($a->contentSpan(), 'X'),    // shrink 4 -> 1
            new JsonPatchEntry($b->contentSpan(), 'YYYY'), // grow 2 -> 4
            new JsonPatchEntry($c->contentSpan(), 'Z'),    // shrink 7 -> 1
        ]);
        self::assertSame('{"a":"X","b":"YYYY","c":"Z"}', $patched);
    }

    public function testZeroLengthPatchInsertsContent(): void
    {
        // A zero-length span (empty string value) is replaced by the encoded
        // replacement — i.e. content is inserted between the framing quotes.
        $json = '{"x":""}';
        $x = self::stringValueAt($json, ['x']);
        self::assertInstanceOf(JsonString::class, $x);
        self::assertSame(0, $x->contentLength);

        $patched = JsonPatch::apply($json, [
            new JsonPatchEntry($x->contentSpan(), 'filled'),
        ]);
        self::assertSame('{"x":"filled"}', $patched);
    }

    public function testReverseOrderApplicationKeepsLaterOffsetsStable(): void
    {
        // Forward single-pass invariant (codex #15): patches supplied in any
        // input order must yield identical output — internally sorted ascending
        // and stitched in a single forward sweep.
        $json = '{"a":"1","b":"2","c":"3"}';
        $a = self::stringValueAt($json, ['a']);
        $b = self::stringValueAt($json, ['b']);
        $c = self::stringValueAt($json, ['c']);
        self::assertInstanceOf(JsonString::class, $a);
        self::assertInstanceOf(JsonString::class, $b);
        self::assertInstanceOf(JsonString::class, $c);

        $asc = JsonPatch::apply($json, [
            new JsonPatchEntry($a->contentSpan(), 'AAA'),
            new JsonPatchEntry($b->contentSpan(), 'BBBB'),
            new JsonPatchEntry($c->contentSpan(), 'C'),
        ]);
        $desc = JsonPatch::apply($json, [
            new JsonPatchEntry($c->contentSpan(), 'C'),
            new JsonPatchEntry($b->contentSpan(), 'BBBB'),
            new JsonPatchEntry($a->contentSpan(), 'AAA'),
        ]);
        self::assertSame($asc, $desc);
        self::assertSame('{"a":"AAA","b":"BBBB","c":"C"}', $asc);
    }

    public function testManyPatchesScaleLinearlyOverJsonBytes(): void
    {
        // Stress the forward single-pass: many patches spread across a large
        // body. The output must match a plain expected concatenation (codex
        // #15: previously O(P*N) per-patch full-copy).
        $parts = [];
        $patches = [];
        $cursor = 0;
        $raw = '';
        for ($i = 0; $i < 200; $i++) {
            $orig = 'val' . $i;          // 4..6 ASCII bytes
            $span = new StringSpan(0, \strlen($orig));
            $raw .= $orig;
            $patches[] = new JsonPatchEntry($span, 'X' . $i);
        }
        // Build one big JSON with each value placed back-to-back and rewrite
        // the spans to point at their actual offsets.
        $json = '"' . $raw . '"';
        $patchList = [];
        $off = 1; // skip opening quote
        foreach ($patches as $i => $p) {
            $patchList[] = new JsonPatchEntry(
                new StringSpan($off, $p->span->length),
                $p->replacement,
            );
            $off += $p->span->length;
        }
        $patched = JsonPatch::apply($json, $patchList);
        $expected = '"' . \implode('', \array_map(
            static fn (int $i): string => 'X' . $i,
            \range(0, 199),
        )) . '"';
        self::assertSame($expected, $patched);
    }

    /**
     * Locate a nested value by traversing object member names from the root.
     *
     * @param list<string> $path
     */
    private static function valueAt(string $json, array $path): object
    {
        $doc = JsonTokenizer::parse($json);
        $node = $doc->root;
        foreach ($path as $name) {
            self::assertInstanceOf(JsonObject::class, $node);
            $node = self::memberValue($node, $json, $name);
        }
        return $node;
    }

    /** @param list<string> $path */
    private static function stringValueAt(string $json, array $path): object
    {
        return self::valueAt($json, $path);
    }

    private static function memberValue(JsonObject $obj, string $json, string $name): object
    {
        foreach ($obj->members as $m) {
            if ($m->key->rawContent($json) === $name) {
                return $m->value;
            }
        }
        self::fail('member not found: ' . $name);
    }
}
