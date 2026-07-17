<?php

// src/Internal/Pcre.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Exception\{InvalidFindingException, LimitExceededException, RegexException};

/**
 * Scoped PCRE wrappers. Every preg_match in the library goes through here so
 * the policy "install a handler that throws RegexException, call, restore in
 * finally, and treat a false return as a failure" (spec section 8.2) lives in
 * exactly one place. Also owns the single raw-candidate cap constant
 * (spec section 4.4), referenced by ConflictResolver and the built-in
 * recognizers.
 */
final class Pcre
{
    /** Hard upper bound on raw candidate count per resolve() call (spec 4.4). */
    public const RAW_CANDIDATE_CAP = 3000;

    /**
     * Scoped preg_match: any PCRE failure (false return or emitted warning) is
     * converted to RegexException carrying the pattern; the error handler is
     * scoped and always restored in finally.
     *
     * @param array<mixed> $matches populated by preg_match
     */
    public static function match(string $pattern, string $subject, array &$matches = [], int $flags = 0, int $offset = 0): int
    {
        \set_error_handler(static function (int $severity, string $message) use ($pattern): bool {
            throw new RegexException($message . ' [pattern: ' . $pattern . ']');
        });
        try {
            /** @phpstan-ignore-next-line — $flags is a valid PREG_* constant supplied by the caller. */
            $result = \preg_match($pattern, $subject, $matches, $flags, $offset);
            if ($result === false) {
                throw new RegexException(\preg_last_error_msg() . ' [pattern: ' . $pattern . ']');
            }
            return $result;
        } finally {
            \restore_error_handler();
        }
    }

    /**
     * Incrementally enumerate matches with PREG_OFFSET_CAPTURE, stopping at the
     * raw cap.
     *
     * @return list<array{0:int, 1:int, 2:string}> list of [start, end, match] triples
     */
    public static function eachMatch(string $pattern, string $text): array
    {
        $out = [];
        $offset = 0;
        while (true) {
            $matches = [];
            $result = self::match($pattern, $text, $matches, \PREG_OFFSET_CAPTURE, $offset);
            if ($result !== 1) {
                break;
            }
            /** @var array{0:string, 1:int} $full */
            $full = $matches[0];
            $start = $full[1];
            $end = $start + \strlen($full[0]);
            if ($end === $start) {
                // Spec 4.4: a zero-length hit fails as InvalidFindingException
                // (never retried at the same offset, which would loop forever).
                throw new InvalidFindingException('zero-length match at offset ' . $start);
            }
            $out[] = [$start, $end, $full[0]];
            $offset = $end;
            // A resource-limit event is a LimitExceededException (not a
            // RegexException) so callers never have to sniff the message.
            self::assertRawCap(\count($out));
        }
        return $out;
    }

    /**
     * Throw LimitExceededException if a recognizer's raw-candidate count has
     * passed the shared cap (spec section 4.4). Single source for the cap
     * value, the comparison, and the message.
     */
    public static function assertRawCap(int $count): void
    {
        if ($count > self::RAW_CANDIDATE_CAP) {
            throw new LimitExceededException('raw candidate count exceeds ' . self::RAW_CANDIDATE_CAP);
        }
    }
}
