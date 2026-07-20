<?php

// src/Internal/JsonPatch.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Raw-preserving JSON patcher.
 *
 * Given the original JSON bytes plus a list of non-overlapping
 * {span, replacement} entries, produces a new byte string where each span's
 * bytes are replaced by the JSON-encoded form of the entry's decoded
 * replacement. Unpatched spans (numbers, whitespace, key order, unknown
 * fields, non-target subtrees) are byte-for-byte preserved because the
 * patcher never rebuilds the tree — it only substitutes byte ranges.
 *
 * Replacement strings are encoded with JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES so non-ASCII text stays readable and forward
 * slashes don't get unnecessarily escaped (spec §9.3/§9.4).
 *
 * Entries are stitched into the output in a single FORWARD sweep (lowest byte
 * offset first): the body is sliced at each patch boundary and the encoded
 * replacement is appended between slices. Because we walk forward and never
 * re-copy earlier output, total work is O(N + P) (one byte copied per input
 * byte plus per-patch overhead) instead of O(P*N) for the previous
 * reverse-order per-patch full-copy (codex #15).
 *
 * @internal
 */
final class JsonPatch
{
    /**
     * Apply $patches to $json. Returns $json unchanged when $patches is empty.
     *
     * @param string $json                Original JSON bytes.
     * @param list<JsonPatchEntry> $patches Entries to apply (non-overlapping).
     * @return string                     Patched JSON bytes.
     */
    public static function apply(string $json, array $patches): string
    {
        if ($patches === []) {
            return $json;
        }

        // Sort ascending by start so the forward sweep visits offsets in order;
        // verify non-overlap and bounds before stitching.
        \usort($patches, static fn (JsonPatchEntry $a, JsonPatchEntry $b): int => $a->span->start <=> $b->span->start);

        $jsonLen = \strlen($json);
        $prevEnd = -1;
        foreach ($patches as $p) {
            if ($p->span->start < $prevEnd) {
                throw new \InvalidArgumentException('JsonPatch entries must not overlap');
            }
            $end = $p->span->start + $p->span->length;
            if ($end > $jsonLen) {
                throw new \InvalidArgumentException('JsonPatch entry extends past end of JSON');
            }
            $prevEnd = $end;
        }

        // Forward single-pass stitch (codex #15): accumulate untouched bytes
        // between patches and the encoded replacement for each patch's span
        // into an array of pieces, then implode. Each input byte is copied at
        // most once, so total work is O(N + P) rather than O(P*N) for the
        // prior reverse-order per-patch full-copy.
        $pieces = [];
        $cursor = 0;
        foreach ($patches as $p) {
            if ($p->span->start > $cursor) {
                $pieces[] = \substr($json, $cursor, $p->span->start - $cursor);
            } elseif ($p->span->start < $cursor) {
                // Defensive: overlap detection above should make this dead
                // code, but a negative-length substr would be unsafe.
                throw new \InvalidArgumentException('JsonPatch entries must not overlap');
            }
            $pieces[] = self::encodeStringContent($p->replacement);
            $cursor = $p->span->start + $p->span->length;
        }
        if ($cursor < $jsonLen) {
            $pieces[] = \substr($json, $cursor);
        }
        return \implode('', $pieces);
    }

    /**
     * Encode a decoded string as JSON string CONTENT (no surrounding quotes),
     * applying JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES semantics.
     *
     * The framing quotes of the original JSON string token are preserved by
     * the patcher's byte-range substitution; only the inner content is
     * re-encoded, so escapes for control characters, the double-quote, and
     * the backslash remain valid JSON.
     */
    public static function encodeStringContent(string $decoded): string
    {
        $encoded = \json_encode(
            $decoded,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        );
        // \json_encode always wraps the string in double quotes; strip both
        // so the encoded text slots between the preserved framing quotes.
        return \substr($encoded, 1, -1);
    }

    /**
     * Length-only counterpart of {@see encodeStringContent()} for the dry-run
     * budget check (spec §9.5): compute the JSON-escaped byte length WITHOUT
     * allocating the escaped string. The result is byte-for-byte equal to
     * strlen(encodeStringContent($decoded)) under
     * JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES: '"' and '\' double;
     * the short control escapes (\b \t \n \f \r) take 2 bytes, other controls
     * take 6 (\u00XX); every other byte — including UTF-8 multibyte sequences,
     * which UNESCAPED_UNICODE keeps verbatim — takes 1.
     */
    public static function encodedStringLength(string $decoded): int
    {
        $n = \strlen($decoded);
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $b = \ord($decoded[$i]);
            if ($b === 0x22 || $b === 0x5C) {
                $len += 2;
            } elseif ($b < 0x20) {
                $len += ($b === 0x08 || $b === 0x09 || $b === 0x0A || $b === 0x0C || $b === 0x0D) ? 2 : 6;
            } else {
                $len += 1;
            }
        }

        return $len;
    }
}
