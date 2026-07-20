<?php

// tests/Unit/Internal/JsonTokenizerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Internal\JsonArray;
use Yolorouter\Llmasking\Internal\JsonObject;
use Yolorouter\Llmasking\Internal\JsonScalar;
use Yolorouter\Llmasking\Internal\JsonString;
use Yolorouter\Llmasking\Internal\JsonSyntaxException;
use Yolorouter\Llmasking\Internal\JsonTokenizer;

/**
 * Coverage for the bounded JSON scanner (spec §9.2): byte-accurate string
 * spans, member-order preservation (incl. duplicate keys), depth/node caps
 * that reject adversarial input, and big-integer lexical fidelity.
 */
final class JsonTokenizerTest extends TestCase
{
    public function testParsesFlatObjectWithOneStringMember(): void
    {
        $doc = JsonTokenizer::parse('{"name":"Alice"}');
        $root = $doc->root;
        self::assertInstanceOf(JsonObject::class, $root);
        self::assertSame('object', $root->kind());
        self::assertSame(1, $root->depth);
        self::assertCount(1, $root->members);

        $member = $root->members[0];
        self::assertSame('name', $member->key->rawContent($doc->json));
        self::assertInstanceOf(JsonString::class, $member->value);
        self::assertSame('string', $member->value->kind());
        self::assertSame('Alice', $member->value->rawContent($doc->json));
    }

    public function testSpanOffsetsAreByteAccurateForStringContent(): void
    {
        // '  "abc"  ' — opening quote at byte 2, content "abc" at bytes 3..5,
        // closing quote at byte 6, end at byte 7.
        $doc = JsonTokenizer::parse('  "abc"  ');
        self::assertInstanceOf(JsonString::class, $doc->root);
        $s = $doc->root;
        self::assertSame(2, $s->start, 'opening quote offset');
        self::assertSame(7, $s->end, 'offset just after closing quote');
        self::assertSame(3, $s->contentStart);
        self::assertSame(3, $s->contentLength);
        self::assertSame('abc', \substr($doc->json, $s->contentStart, $s->contentLength));
    }

    public function testStringValueSpansHelperReturnsOnlyValuesInOrder(): void
    {
        $doc = JsonTokenizer::parse('{"a":"x","b":["y","z"],"c":42}');
        $spans = $doc->stringValueSpans();
        // Values: "x","y","z" — member keys "a","b","c" excluded.
        self::assertCount(3, $spans);
        self::assertSame('x', \substr($doc->json, $spans[0]->start, $spans[0]->length));
        self::assertSame('y', \substr($doc->json, $spans[1]->start, $spans[1]->length));
        self::assertSame('z', \substr($doc->json, $spans[2]->start, $spans[2]->length));
    }

    public function testPreservesObjectMemberOrder(): void
    {
        $doc = JsonTokenizer::parse('{"zebra":1,"apple":2,"mango":3}');
        self::assertInstanceOf(JsonObject::class, $doc->root);
        $keys = [];
        foreach ($doc->root->members as $m) {
            $keys[] = $m->key->rawContent($doc->json);
        }
        self::assertSame(['zebra', 'apple', 'mango'], $keys);
    }

    public function testDuplicateKeysAllSurviveInRawOrder(): void
    {
        // Spec §9.2/§9.3: must NOT fold into PHP associative array; every
        // duplicate occurrence is retained in raw member order for the walker.
        $doc = JsonTokenizer::parse('{"x":1,"x":2,"x":3}');
        self::assertInstanceOf(JsonObject::class, $doc->root);
        self::assertCount(3, $doc->root->members);
        foreach ($doc->root->members as $m) {
            self::assertSame('x', $m->key->rawContent($doc->json));
        }
        self::assertInstanceOf(JsonScalar::class, $doc->root->members[0]->value);
        $v0 = $doc->root->members[0]->value;
        $v1 = $doc->root->members[1]->value;
        $v2 = $doc->root->members[2]->value;
        self::assertInstanceOf(JsonScalar::class, $v0);
        self::assertInstanceOf(JsonScalar::class, $v1);
        self::assertInstanceOf(JsonScalar::class, $v2);
        self::assertSame('1', $v0->rawLiteral($doc->json));
        self::assertSame('2', $v1->rawLiteral($doc->json));
        self::assertSame('3', $v2->rawLiteral($doc->json));
    }

    public function testParsesNestedObjectsAndArrays(): void
    {
        $doc = JsonTokenizer::parse('{"a":[1,{"b":[2,3]}]}');
        self::assertInstanceOf(JsonObject::class, $doc->root);
        $aVal = $doc->root->members[0]->value;
        self::assertInstanceOf(JsonArray::class, $aVal);
        self::assertSame(2, $aVal->depth);
        self::assertCount(2, $aVal->elements);
        self::assertInstanceOf(JsonScalar::class, $aVal->elements[0]);
        self::assertSame('1', $aVal->elements[0]->rawLiteral($doc->json));
        self::assertInstanceOf(JsonObject::class, $aVal->elements[1]);
        self::assertSame(3, $aVal->elements[1]->depth);
        $inner = $aVal->elements[1];
        self::assertCount(1, $inner->members);
        self::assertSame('b', $inner->members[0]->key->rawContent($doc->json));
        $bVal = $inner->members[0]->value;
        self::assertInstanceOf(JsonArray::class, $bVal);
        self::assertSame(4, $bVal->depth);
        self::assertCount(2, $bVal->elements);
    }

    public function testParsesAllScalarKinds(): void
    {
        $doc = JsonTokenizer::parse('[true,false,null]');
        self::assertInstanceOf(JsonArray::class, $doc->root);
        $els = $doc->root->elements;
        self::assertCount(3, $els);
        self::assertSame('true', $els[0]->kind());
        self::assertSame('false', $els[1]->kind());
        self::assertSame('null', $els[2]->kind());
        // Local-variable narrowing so assertInstanceOf lets phpstan see JsonScalar.
        $e0 = $els[0];
        $e1 = $els[1];
        $e2 = $els[2];
        self::assertInstanceOf(JsonScalar::class, $e0);
        self::assertInstanceOf(JsonScalar::class, $e1);
        self::assertInstanceOf(JsonScalar::class, $e2);
        self::assertSame('true', $e0->rawLiteral($doc->json));
        self::assertSame('false', $e1->rawLiteral($doc->json));
        self::assertSame('null', $e2->rawLiteral($doc->json));
    }

    public function testNumberLexemePreservedWithoutLossyConversion(): void
    {
        // Big-int that overflows PHP_INT_MAX on 64-bit plus float/exp/neg forms.
        $big = '9223372036854775807';  // PHP_INT_MAX
        $bigger = '9223372036854775808';  // PHP_INT_MAX + 1 (would overflow)
        $float = '3.14159';
        $exp = '1.5e10';
        $neg = '-42';
        $zero = '0';
        $leadingZeroFloat = '0.5';
        $doc = JsonTokenizer::parse(\sprintf('[%s,%s,%s,%s,%s,%s,%s]', $big, $bigger, $float, $exp, $neg, $zero, $leadingZeroFloat));
        self::assertInstanceOf(JsonArray::class, $doc->root);
        $els = $doc->root->elements;
        self::assertCount(7, $els);
        // Raw lexeme preserved byte-for-byte (no float/int conversion).
        $literals = [];
        foreach ($els as $el) {
            self::assertInstanceOf(JsonScalar::class, $el);
            $literals[] = $el->rawLiteral($doc->json);
        }
        self::assertSame(
            [$big, $bigger, $float, $exp, $neg, $zero, $leadingZeroFloat],
            $literals,
        );
    }

    public function testStringEscapesArePreservedRaw(): void
    {
        // Quoted/escaped content stays as raw bytes; decoding is the walker's job.
        $input = '"line1\\nline2\\ttab\\u00e9quote\\"end"';
        $doc = JsonTokenizer::parse($input);
        self::assertInstanceOf(JsonString::class, $doc->root);
        // rawContent returns exactly the bytes between the framing quotes.
        self::assertSame(
            'line1\\nline2\\ttab\\u00e9quote\\"end',
            $doc->root->rawContent($doc->json),
        );
        // The whole token (including framing quotes) spans [0, strlen($input)).
        self::assertSame(0, $doc->root->start);
        self::assertSame(\strlen($input), $doc->root->end);
    }

    public function testUnicodeStringPreservedAsRawBytes(): void
    {
        // é (U+00E9) occupies 2 bytes in UTF-8: 0xC3 0xA9.
        $input = '"café"';
        $doc = JsonTokenizer::parse($input);
        self::assertInstanceOf(JsonString::class, $doc->root);
        self::assertSame('café', $doc->root->rawContent($doc->json));
        // Byte length: c(1) a(1) f(1) é(2) = 5 bytes of content.
        self::assertSame(5, $doc->root->contentLength);
        self::assertSame(1, $doc->root->contentStart);
    }

    public function testEmptyObjectAndArrayEachCountAsOneNode(): void
    {
        $doc1 = JsonTokenizer::parse('{}');
        self::assertSame(1, $doc1->nodeCount);
        self::assertInstanceOf(JsonObject::class, $doc1->root);
        self::assertSame(0, \count($doc1->root->members));

        $doc2 = JsonTokenizer::parse('[]');
        self::assertSame(1, $doc2->nodeCount);
        self::assertInstanceOf(JsonArray::class, $doc2->root);
        self::assertSame(0, \count($doc2->root->elements));
    }

    public function testTracksMaxDepthReached(): void
    {
        // 3 nested arrays + scalar at depth 4.
        $doc = JsonTokenizer::parse('[[[1]]]');
        self::assertSame(4, $doc->maxDepthReached);
    }

    public function testNodeCountTracksValuesNotKeys(): void
    {
        // {"a":1,"b":2} → 1 object + 2 scalar values = 3 nodes (keys excluded).
        $doc = JsonTokenizer::parse('{"a":1,"b":2}');
        self::assertSame(3, $doc->nodeCount);
    }

    public function testAllowsExactMaxDepth(): void
    {
        // 64 nested empty arrays — innermost at depth 64 (the limit).
        $json = \str_repeat('[', 64) . \str_repeat(']', 64);
        $doc = JsonTokenizer::parse($json);
        self::assertSame(64, $doc->maxDepthReached);
        self::assertSame(64, $doc->nodeCount);
    }

    public function testRejectsDepthBeyondMax(): void
    {
        // 65 nested empty arrays — parser attempts depth 65 → LimitExceededException.
        $json = \str_repeat('[', 65) . \str_repeat(']', 65);
        $this->expectException(LimitExceededException::class);
        $this->expectExceptionMessageMatches('/depth.*64/i');
        JsonTokenizer::parse($json);
    }

    public function testAllowsExactMaxNodes(): void
    {
        // [1,1,...,1] with 65535 scalars → 65536 nodes exactly (the limit).
        $ones = \implode(',', \array_fill(0, 65535, '1'));
        $json = '[' . $ones . ']';
        $doc = JsonTokenizer::parse($json);
        self::assertSame(65536, $doc->nodeCount);
    }

    public function testRejectsNodeCountBeyondMax(): void
    {
        // [1,1,...,1] with 65536 scalars → 65537 nodes → rejected.
        $ones = \implode(',', \array_fill(0, 65536, '1'));
        $json = '[' . $ones . ']';
        $this->expectException(LimitExceededException::class);
        $this->expectExceptionMessageMatches('/node.*65536/i');
        JsonTokenizer::parse($json);
    }

    public function testRejectsEmptyInput(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('');
    }

    public function testRejectsWhitespaceOnlyInput(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse(" \t\n\r ");
    }

    public function testRejectsTrailingContent(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('1 2');
    }

    public function testRejectsUnterminatedString(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"abc');
    }

    public function testRejectsUnterminatedObject(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('{"a":1');
    }

    public function testRejectsUnterminatedArray(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('[1,2');
    }

    public function testRejectsInvalidEscape(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\x"');
    }

    public function testRejectsInvalidUnicodeEscapeHex(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\u00ZZ"');
    }

    public function testRejectsShortUnicodeEscape(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\u00"');
    }

    public function testRejectsUnescapedControlCharInString(): void
    {
        $this->expectException(JsonSyntaxException::class);
        // Build a string containing a literal 0x01 between A and B (cannot
        // appear in source literally without escaping).
        JsonTokenizer::parse('"A' . "\x01" . 'B"');
    }

    public function testRejectsMissingColonBetweenKeyAndValue(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('{"a" 1}');
    }

    public function testRejectsNonStringKey(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('{1:2}');
    }

    public function testRejectsInvalidLiteral(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('tru');
    }

    public function testHandlesLeadingAndTrailingWhitespace(): void
    {
        $doc = JsonTokenizer::parse("\n\t {\"a\":1} \r\n");
        self::assertInstanceOf(JsonObject::class, $doc->root);
        self::assertCount(1, $doc->root->members);
    }

    public function testNestedEmptyContainers(): void
    {
        $doc = JsonTokenizer::parse('[{},{},[]]');
        self::assertInstanceOf(JsonArray::class, $doc->root);
        // 2 empty objects + 1 empty array = 3 elements.
        self::assertCount(3, $doc->root->elements);
        // 1 outer array + 3 inner empties = 4 nodes.
        self::assertSame(4, $doc->nodeCount);
    }

    public function testAcceptsStringAsRootValue(): void
    {
        $doc = JsonTokenizer::parse('"root string"');
        self::assertInstanceOf(JsonString::class, $doc->root);
        self::assertSame(1, $doc->root->depth);
        self::assertSame('root string', $doc->root->rawContent($doc->json));
    }

    public function testAcceptsNumberAsRootValue(): void
    {
        $doc = JsonTokenizer::parse('42');
        self::assertInstanceOf(JsonScalar::class, $doc->root);
        $root = $doc->root;
        self::assertSame('number', $root->kind());
        self::assertSame('42', $root->rawLiteral($doc->json));
    }

    public function testAcceptsLeadingZeroInFloatButNotInteger(): void
    {
        // '0.5' is valid (leading zero followed by fraction).
        $doc = JsonTokenizer::parse('0.5');
        self::assertInstanceOf(JsonScalar::class, $doc->root);
        $root = $doc->root;
        self::assertSame('0.5', $root->rawLiteral($doc->json));

        // '01' (leading zero then digit) is invalid per JSON grammar.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('01');
    }

    public function testStringWithAllSimpleEscapes(): void
    {
        // Verify every simple escape char is accepted and preserved raw.
        $input = '"\\"\\\\\\/\\b\\f\\n\\r\\t"';
        $doc = JsonTokenizer::parse($input);
        self::assertInstanceOf(JsonString::class, $doc->root);
        self::assertSame('\\"\\\\\\/\\b\\f\\n\\r\\t', $doc->root->rawContent($doc->json));
    }

    // -- UTF-8 validation (codex #14) --------------------------------------

    public function testAcceptsValidMultibyteUtf8InString(): void
    {
        // Mix of 2/3/4-byte UTF-8 sequences: é (U+00E9), 中 (U+4E2D),
        // 𠜎 (U+2070E). Each must scan as one well-formed sequence.
        $input = '"café 中 𠜎"';
        $doc = JsonTokenizer::parse($input);
        self::assertInstanceOf(JsonString::class, $doc->root);
        self::assertSame('café 中 𠜎', $doc->root->rawContent($doc->json));
    }

    public function testRejectsInvalidUtf8StartByteInString(): void
    {
        // 0xFF is never a valid UTF-8 start byte.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"a' . "\xFF" . 'b"');
    }

    public function testRejectsStrayUtf8ContinuationByteInString(): void
    {
        // 0x80 is a continuation byte with no preceding lead.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"a' . "\x80" . 'b"');
    }

    public function testRejectsOverlongTwoByteUtf8Sequence(): void
    {
        // 0xC0 0x80 is the overlong encoding of U+0000 — invalid UTF-8.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"' . "\xC0\x80" . '"');
    }

    public function testRejectsBadUtf8ContinuationByteInString(): void
    {
        // 0xC3 expects a continuation byte 0x80..0xBF; '"' (0x22) is not one.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"abc' . "\xC3" . '"');
    }

    public function testRejectsTruncatedThreeByteUtf8Sequence(): void
    {
        // E4 B8 AD would be 中 (U+4E2D); truncate before the final byte.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"' . "\xE4\xB8" . '"');
    }

    public function testRejectsSurrogateCodePointEncodedAsUtf8Bytes(): void
    {
        // 0xED 0xA0 0x80 encodes U+D800 — a surrogate code point, not valid
        // UTF-8 (surrogates are only reachable via \uD800 escapes).
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"' . "\xED\xA0\x80" . '"');
    }

    public function testRejectsOutOfRangeUtf8CodePoint(): void
    {
        // 0xF4 0x90 0x80 0x80 encodes U+110000, just past U+10FFFF.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"' . "\xF4\x90\x80\x80" . '"');
    }

    // -- \u surrogate pairing (codex #14) ----------------------------------

    public function testAcceptsValidSurrogatePairEscape(): void
    {
        // U+1F600 encoded as the 😀 surrogate pair.
        $input = '"\\uD83D\\uDE00"';
        $doc = JsonTokenizer::parse($input);
        self::assertInstanceOf(JsonString::class, $doc->root);
        self::assertSame('\\uD83D\\uDE00', $doc->root->rawContent($doc->json));
    }

    public function testRejectsLoneHighSurrogateEscape(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\uD83D"');
    }

    public function testRejectsLoneLowSurrogateEscape(): void
    {
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\uDE00"');
    }

    public function testRejectsHighSurrogateFollowedByPlainChar(): void
    {
        // High surrogate followed by a literal 'A' (not a \u low-surrogate).
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\uD83DA"');
    }

    public function testRejectsHighSurrogateFollowedByNonLowSurrogateEscape(): void
    {
        // High surrogate followed by A ('A') — not a low surrogate.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\uD83D\\u0041"');
    }

    public function testRejectsHighSurrogateAtEndOfString(): void
    {
        // High surrogate immediately followed by closing quote.
        $this->expectException(JsonSyntaxException::class);
        JsonTokenizer::parse('"\\uD83D"');
    }
}
