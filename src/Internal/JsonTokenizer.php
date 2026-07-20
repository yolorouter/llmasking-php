<?php

// src/Internal/JsonTokenizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Exception\LimitExceededException;

/**
 * Bounded recursive-descent JSON scanner.
 *
 * Walks the JSON byte-by-byte WITHOUT calling json_decode/json_encode (those
 * lose big-integer precision, fold duplicate keys into one, and reorder
 * members), producing a {@see JsonDocument} whose tree preserves object
 * member order and the raw byte spans of every string value.
 *
 * Resource bounds (spec §9.2):
 *   - maxJsonDepth = 64  (root is depth 1)
 *   - maxJsonNodes = 65536  (every scalar/container value = 1 node; empty
 *     containers still count; member key strings do NOT count, matching the
 *     spec's literal "every scalar/container value" wording).
 *
 * Both bounds throw {@see LimitExceededException} — a controlled library
 * exception, never TypeError or ext-json's \JsonException — to fail-closed
 * on adversarial depth/node counts. Syntax errors throw
 * {@see JsonSyntaxException}. None of these validate API parameter semantics;
 * they only protect the library's own parser (spec §9 fail-closed scope).
 *
 * @internal
 */
final class JsonTokenizer
{
    public const MAX_DEPTH = 64;
    public const MAX_NODES = 65536;

    public static function parse(string $json): JsonDocument
    {
        $parser = new self($json);
        return $parser->run();
    }

    private function __construct(private readonly string $json)
    {
    }

    private int $pos = 0;
    private int $len = 0;
    private int $nodeCount = 0;
    private int $maxDepthReached = 0;

    private function run(): JsonDocument
    {
        $this->len = \strlen($this->json);
        $this->skipWs();
        if ($this->pos >= $this->len) {
            throw JsonSyntaxException::at($this->pos, 'empty input');
        }
        $root = $this->parseValue(1);
        $this->skipWs();
        if ($this->pos < $this->len) {
            throw JsonSyntaxException::at($this->pos, 'trailing content after root value');
        }
        return new JsonDocument($this->json, $root, $this->nodeCount, $this->maxDepthReached);
    }

    private function parseValue(int $depth): JsonValue
    {
        if ($depth > self::MAX_DEPTH) {
            throw new LimitExceededException(
                'JSON depth ' . $depth . ' exceeds maximum of ' . self::MAX_DEPTH,
            );
        }
        if ($depth > $this->maxDepthReached) {
            $this->maxDepthReached = $depth;
        }
        $this->bumpNode();

        if ($this->pos >= $this->len) {
            throw JsonSyntaxException::at($this->pos, 'unexpected end of input');
        }
        $c = $this->json[$this->pos];
        if ($c === '{') {
            return $this->parseObject($depth);
        }
        if ($c === '[') {
            return $this->parseArray($depth);
        }
        if ($c === '"') {
            return $this->parseString($depth);
        }
        if ($c === 't') {
            return $this->parseKeyword('true', 'true', $depth);
        }
        if ($c === 'f') {
            return $this->parseKeyword('false', 'false', $depth);
        }
        if ($c === 'n') {
            return $this->parseKeyword('null', 'null', $depth);
        }
        if ($c === '-' || ($c >= '0' && $c <= '9')) {
            return $this->parseNumber($depth);
        }
        throw JsonSyntaxException::at($this->pos, 'unexpected character ' . \var_export($c, true));
    }

    private function parseObject(int $depth): JsonObject
    {
        $start = $this->pos;
        // Caller guaranteed $json[$pos] === '{'.
        $this->pos++;
        $this->skipWs();

        $members = [];
        if ($this->pos < $this->len && $this->json[$this->pos] === '}') {
            $this->pos++;
            return new JsonObject($start, $this->pos, $depth, $members);
        }

        while (true) {
            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw JsonSyntaxException::at($this->pos, 'unterminated object');
            }
            if ($this->json[$this->pos] !== '"') {
                throw JsonSyntaxException::at($this->pos, 'expected string key in object');
            }
            // Key shares the object's depth+1 level (it lives syntactically
            // inside the object braces, parallel to its value).
            $key = $this->parseString($depth + 1);

            $this->skipWs();
            if ($this->pos >= $this->len || $this->json[$this->pos] !== ':') {
                throw JsonSyntaxException::at($this->pos, 'expected ":" after object key');
            }
            $this->pos++; // consume ':'
            $this->skipWs();

            $value = $this->parseValue($depth + 1);
            $members[] = new JsonProperty($key, $value);

            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw JsonSyntaxException::at($this->pos, 'unterminated object');
            }
            $c = $this->json[$this->pos];
            if ($c === ',') {
                $this->pos++;
                continue;
            }
            if ($c === '}') {
                $this->pos++;
                return new JsonObject($start, $this->pos, $depth, $members);
            }
            throw JsonSyntaxException::at($this->pos, 'expected "," or "}" in object');
        }
    }

    private function parseArray(int $depth): JsonArray
    {
        $start = $this->pos;
        // Caller guaranteed $json[$pos] === '['.
        $this->pos++;
        $this->skipWs();

        $elements = [];
        if ($this->pos < $this->len && $this->json[$this->pos] === ']') {
            $this->pos++;
            return new JsonArray($start, $this->pos, $depth, $elements);
        }

        while (true) {
            $value = $this->parseValue($depth + 1);
            $elements[] = $value;

            $this->skipWs();
            if ($this->pos >= $this->len) {
                throw JsonSyntaxException::at($this->pos, 'unterminated array');
            }
            $c = $this->json[$this->pos];
            if ($c === ',') {
                $this->pos++;
                $this->skipWs();
                continue;
            }
            if ($c === ']') {
                $this->pos++;
                return new JsonArray($start, $this->pos, $depth, $elements);
            }
            throw JsonSyntaxException::at($this->pos, 'expected "," or "]" in array');
        }
    }

    private function parseString(int $depth): JsonString
    {
        $start = $this->pos;
        // Caller guaranteed $json[$pos] === '"'.
        $this->pos++;
        $contentStart = $this->pos;

        while ($this->pos < $this->len) {
            $c = $this->json[$this->pos];
            if ($c === '"') {
                $contentLength = $this->pos - $contentStart;
                $this->pos++; // consume closing quote
                return new JsonString($start, $this->pos, $depth, $contentStart, $contentLength);
            }
            if ($c === '\\') {
                $backslashPos = $this->pos;
                $this->pos++;
                if ($this->pos >= $this->len) {
                    throw JsonSyntaxException::at($this->pos, 'unterminated escape in string');
                }
                $esc = $this->json[$this->pos];
                if ($esc === 'u') {
                    $this->pos++; // consume 'u'; consumeHex4 expects the first hex digit
                    $code = $this->consumeHex4();
                    // Enforce UTF-16 surrogate pairing (RFC 8259 §7): a high
                    // surrogate (D800..DBFF) MUST be followed by a \u low-
                    // surrogate escape (DC00..DFFF); a low surrogate MUST be
                    // preceded by a high surrogate. Lone surrogates are not
                    // valid JSON (ext-json rejects them too) — catching them
                    // here keeps the tokenizer's "json_decode cannot throw on
                    // our output" invariant (codex #14).
                    if ($code >= 0xD800 && $code <= 0xDBFF) {
                        if (
                            $this->pos + 1 >= $this->len
                            || $this->json[$this->pos] !== '\\'
                            || $this->json[$this->pos + 1] !== 'u'
                        ) {
                            throw JsonSyntaxException::at(
                                $backslashPos,
                                'high surrogate \\u escape not followed by \\u low surrogate escape',
                            );
                        }
                        $this->pos += 2; // consume '\' and 'u' of the low surrogate
                        $lowCode = $this->consumeHex4();
                        if ($lowCode < 0xDC00 || $lowCode > 0xDFFF) {
                            throw JsonSyntaxException::at(
                                $backslashPos,
                                'high surrogate \\u escape not followed by low surrogate',
                            );
                        }
                    } elseif ($code >= 0xDC00 && $code <= 0xDFFF) {
                        throw JsonSyntaxException::at(
                            $backslashPos,
                            'lone low surrogate \\u escape',
                        );
                    }
                } elseif (
                    $esc === '"' || $esc === '\\' || $esc === '/'
                    || $esc === 'b' || $esc === 'f' || $esc === 'n' || $esc === 'r' || $esc === 't'
                ) {
                    $this->pos++;
                } else {
                    throw JsonSyntaxException::at($this->pos, 'invalid escape sequence \\' . $esc);
                }
            } else {
                $o = \ord($c);
                // RFC 8259: control characters U+0000..U+001F must be escaped.
                if ($o < 0x20) {
                    throw JsonSyntaxException::at($this->pos, 'unescaped control character in string');
                }
                if ($o < 0x80) {
                    // ASCII byte (0x20..0x7F) — accept as-is.
                    $this->pos++;
                } else {
                    // Non-ASCII byte must open a well-formed UTF-8 sequence
                    // (RFC 8259 §8.1: JSON strings MUST be UTF-8 outside closed
                    // ecosystems). Stray continuation bytes, overlong encodings,
                    // surrogate code points, and out-of-range code points are
                    // rejected so later json_decode can't throw on raw string
                    // values (codex #14).
                    $size = $this->validateUtf8Char($this->pos);
                    $this->pos += $size;
                }
            }
        }
        throw JsonSyntaxException::at($this->pos, 'unterminated string');
    }

    /**
     * Consume exactly four hex digits starting at the current position and
     * return their integer value. Pre: $json[$pos] is the first hex digit.
     * Post: $pos points just past the fourth hex digit. Throws on truncated or
     * non-hex input.
     */
    private function consumeHex4(): int
    {
        $start = $this->pos;
        for ($i = 0; $i < 4; $i++) {
            if ($this->pos >= $this->len) {
                throw JsonSyntaxException::at($this->pos, 'unterminated \\u escape');
            }
            if (!self::isHex($this->json[$this->pos])) {
                throw JsonSyntaxException::at($this->pos, 'invalid hex digit in \\u escape');
            }
            $this->pos++;
        }
        return (int) \hexdec(\substr($this->json, $start, 4));
    }

    /**
     * Validate one well-formed UTF-8 sequence starting at $pos (whose lead byte
     * is >= 0x80) and return its byte length. Enforces the RFC 3629 production
     * (no overlong encodings, no surrogate code points, no code points beyond
     * U+10FFFF) so the tokenizer never admits bytes that ext-json would later
     * refuse (codex #14).
     */
    private function validateUtf8Char(int $pos): int
    {
        $b0 = \ord($this->json[$pos]);
        // Caller guarantees $b0 >= 0x80. 0x80..0xBF are stray continuation
        // bytes; 0xC0/0xC1 can only start overlong 2-byte sequences.
        if ($b0 < 0xC2 || $b0 > 0xF4) {
            throw JsonSyntaxException::at($pos, 'invalid UTF-8 start byte in string');
        }
        if ($b0 <= 0xDF) {
            // 2-byte sequence (C2..DF): one continuation byte.
            $this->assertUtf8Continuation($pos, 1);
            return 2;
        }
        // 3- or 4-byte sequence — read the first continuation byte to apply
        // lead-specific constraints.
        if ($pos + 1 >= $this->len) {
            throw JsonSyntaxException::at($pos + 1, 'truncated UTF-8 sequence in string');
        }
        $b1 = \ord($this->json[$pos + 1]);
        if ($b0 <= 0xEF) {
            // 3-byte sequence (E0..EF).
            if ($b0 === 0xE0 && ($b1 < 0xA0 || $b1 > 0xBF)) {
                // E0 must be followed by A0..BF to avoid an overlong encoding.
                throw JsonSyntaxException::at($pos, 'overlong UTF-8 sequence in string');
            }
            if ($b0 === 0xED && ($b1 < 0x80 || $b1 > 0x9F)) {
                // ED A0..BF would encode surrogate code points D800..DFFF,
                // which JSON carries only via paired \uD8XX\uDCXX escapes.
                throw JsonSyntaxException::at($pos, 'surrogate code point in UTF-8 sequence');
            }
            $this->assertUtf8Continuation($pos, 2);
            return 3;
        }
        // 4-byte sequence (F0..F4).
        if ($b0 === 0xF0 && ($b1 < 0x90 || $b1 > 0xBF)) {
            // F0 must be followed by 90..BF to avoid an overlong encoding.
            throw JsonSyntaxException::at($pos, 'overlong UTF-8 sequence in string');
        }
        if ($b0 === 0xF4 && ($b1 < 0x80 || $b1 > 0x8F)) {
            // F4 90..BF would encode code points beyond U+10FFFF.
            throw JsonSyntaxException::at($pos, 'out-of-range UTF-8 code point in string');
        }
        $this->assertUtf8Continuation($pos, 3);
        return 4;
    }

    /**
     * Verify $count continuation bytes (each 0x80..0xBF) follow $pos. Throws
     * on a truncated or out-of-range continuation byte.
     */
    private function assertUtf8Continuation(int $pos, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            if ($pos + $i >= $this->len) {
                throw JsonSyntaxException::at($pos + $i, 'truncated UTF-8 sequence in string');
            }
            $b = \ord($this->json[$pos + $i]);
            if ($b < 0x80 || $b > 0xBF) {
                throw JsonSyntaxException::at($pos + $i, 'invalid UTF-8 continuation byte in string');
            }
        }
    }

    private function parseNumber(int $depth): JsonScalar
    {
        $start = $this->pos;
        if ($this->json[$this->pos] === '-') {
            $this->pos++;
        }
        // Integer part.
        if ($this->pos >= $this->len) {
            throw JsonSyntaxException::at($this->pos, 'invalid number (no digits)');
        }
        $c = $this->json[$this->pos];
        if ($c === '0') {
            $this->pos++;
        } elseif ($c >= '1' && $c <= '9') {
            $this->pos++;
            $this->consumeDigits();
        } else {
            throw JsonSyntaxException::at($this->pos, 'invalid number (expected digit)');
        }
        // Fraction.
        if ($this->pos < $this->len && $this->json[$this->pos] === '.') {
            $this->pos++;
            if ($this->pos >= $this->len || !self::isDigit($this->json[$this->pos])) {
                throw JsonSyntaxException::at($this->pos, 'invalid fraction (expected digit after ".")');
            }
            $this->consumeDigits();
        }
        // Exponent.
        if ($this->pos < $this->len && ($this->json[$this->pos] === 'e' || $this->json[$this->pos] === 'E')) {
            $this->pos++;
            if ($this->pos < $this->len && ($this->json[$this->pos] === '+' || $this->json[$this->pos] === '-')) {
                $this->pos++;
            }
            if ($this->pos >= $this->len || !self::isDigit($this->json[$this->pos])) {
                throw JsonSyntaxException::at($this->pos, 'invalid exponent (expected digit)');
            }
            $this->consumeDigits();
        }
        return new JsonScalar($start, $this->pos, $depth, 'number');
    }

    private function consumeDigits(): void
    {
        while ($this->pos < $this->len && self::isDigit($this->json[$this->pos])) {
            $this->pos++;
        }
    }

    private function parseKeyword(string $expected, string $kind, int $depth): JsonScalar
    {
        $start = $this->pos;
        $n = \strlen($expected);
        if ($this->pos + $n > $this->len || \substr($this->json, $this->pos, $n) !== $expected) {
            throw JsonSyntaxException::at($this->pos, 'invalid literal (expected ' . $expected . ')');
        }
        $this->pos += $n;
        return new JsonScalar($start, $this->pos, $depth, $kind);
    }

    private function skipWs(): void
    {
        while ($this->pos < $this->len) {
            $c = $this->json[$this->pos];
            // RFC 8259: JSON ASCII whitespace is SP/HTAB/LF/CR only.
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
            } else {
                break;
            }
        }
    }

    private function bumpNode(): void
    {
        if ($this->nodeCount >= self::MAX_NODES) {
            throw new LimitExceededException(
                'JSON node count exceeds maximum of ' . self::MAX_NODES,
            );
        }
        $this->nodeCount++;
    }

    private static function isDigit(string $byte): bool
    {
        return Validate::isDigit($byte);
    }

    private static function isHex(string $byte): bool
    {
        return self::isDigit($byte)
            || ($byte >= 'a' && $byte <= 'f')
            || ($byte >= 'A' && $byte <= 'F');
    }
}
