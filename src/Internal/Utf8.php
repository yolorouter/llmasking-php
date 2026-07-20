<?php

// src/Internal/Utf8.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Shared incremental UTF-8 helpers used by the per-chunk validators in
 * StreamRestorer and SseRestorer (both carry an incomplete trailing codepoint
 * across chunk boundaries and validate the complete prefix of each chunk).
 *
 * @internal
 */
final class Utf8
{
    /**
     * Length of a truncated UTF-8 sequence at the end of $s (0 if none).
     * Walks back at most 3 bytes looking for a lead byte; returns the number of
     * trailing bytes that form an incomplete codepoint (to be withheld until a
     * later chunk completes or rejects it).
     */
    public static function trailingIncompleteLen(string $s): int
    {
        $n = \strlen($s);
        for ($k = 1; $k <= 3 && $n - $k >= 0; $k++) {
            $c = \ord($s[$n - $k]);
            if ($c < 0x80) {
                return 0; // ASCII byte: the codepoint ending here is complete
            }
            if (($c & 0xC0) === 0xC0) { // lead byte
                $expected = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : 2);

                return $k < $expected ? $k : 0;
            }
            // continuation byte: keep walking back
        }

        return 0;
    }
}
