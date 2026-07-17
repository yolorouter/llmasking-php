<?php

// src/Internal/EntropyPasswordRecognizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{EntityType, Finding, Recognizer, Region};

/**
 * Heuristic high-entropy secret recognizer (spec sections 4.2/4.3). Locates a
 * context keyword (api[_-]?key/secret/password/passwd/pwd) optionally extended
 * by an uppercase composite suffix (e.g. "_ID", "_TOKEN") and an optional
 * closing quote, followed by a separator and a quoted or bare value, then
 * admits the value only when its Shannon entropy computed over raw bytes is
 * >= 3.0 bits/byte (which also implies a minimum of 8 distinct characters).
 * Findings carry entity SECRET and span the value token (including surrounding
 * quotes when quoted). A case-insensitive substring pre-filter skips inputs
 * that contain no keyword at all.
 */
final class EntropyPasswordRecognizer implements Recognizer
{
    private const KEYWORDS = ['secret', 'password', 'passwd', 'pwd', 'key'];

    /**
     * Anchor: keyword (matching the Go baseline, which applies no leading
     * boundary assert), an optional uppercase composite suffix [A-Z0-9_]*
     * (spec section 4.2, e.g. "password_ID", "API_KEY_TOKEN"), an optional
     * closing quote for JSON-style keys ("key": value), optional ASCII
     * whitespace, a separator (":=" or ":" or "="), optional ASCII whitespace,
     * then a value that is either a double-quoted string, a single-quoted
     * string, or a bare non-whitespace/non-quote run. The ASCII whitespace
     * class ([ \t\n\r\f]) is expanded explicitly per the global constraint so
     * multiline/CRLF configs match the Go baseline. Only the keyword
     * alternation is case-insensitive (inline (?i:...)); the composite suffix
     * is uppercase only per the spec, so the overall pattern carries no /i flag.
     */
    private const PATTERN = '/(?i:api[_-]?key|secret|password|passwd|pwd)[A-Z0-9_]*["\']?[ \t\n\r\f]*(?::=|[:=])[ \t\n\r\f]*("[^"]*"|\'[^\']*\'|[^ \t\n\r\f"\']+)/';

    private const ENTROPY_THRESHOLD = 3.0;

    public function name(): string
    {
        return 'entropy_password';
    }

    public function region(): Region
    {
        return Region::Universal;
    }

    /**
     * @return list<Finding>
     */
    public function recognize(string $text): array
    {
        if ($this->hasNoKeyword($text)) {
            return [];
        }

        $findings = [];
        $pattern = self::PATTERN;
        $offset = 0;
        $len = \strlen($text);
        $rawCount = 0;
        $m = [];
        while ($offset <= $len) {
            $result = Pcre::match($pattern, $text, $m, \PREG_OFFSET_CAPTURE, $offset);
            if ($result !== 1) {
                break;
            }

            // Spec section 4.4: library built-in recognizers must enforce the
            // local 3000-candidate cap internally via incremental enumeration.
            $rawCount++;
            Pcre::assertRawCap($rawCount);

            /** @var array{0:string, 1:int} $groupOne */
            $groupOne = $m[1];
            $valueFull = $groupOne[0];
            $valueStart = $groupOne[1];
            $valueEnd = $valueStart + \strlen($valueFull);
            $inner = $this->stripQuotes($valueFull);
            if (Validate::shannonEntropy($inner) >= self::ENTROPY_THRESHOLD) {
                $findings[] = new Finding(
                    EntityType::SECRET,
                    $valueStart,
                    $valueEnd,
                    0.7,
                    $valueFull,
                );
            }

            // Advance past this match to make progress; guard against zero-width.
            /** @var array{0:string, 1:int} $groupZero */
            $groupZero = $m[0];
            $fullEnd = $groupZero[1] + \strlen($groupZero[0]);
            if ($fullEnd <= $offset) {
                $fullEnd = $offset + 1;
            }
            $offset = $fullEnd;
        }
        return $findings;
    }

    private function hasNoKeyword(string $text): bool
    {
        foreach (self::KEYWORDS as $kw) {
            if (\stripos($text, $kw) !== false) {
                return false;
            }
        }
        return true;
    }

    private function stripQuotes(string $s): string
    {
        $n = \strlen($s);
        if ($n >= 2) {
            $first = $s[0];
            if (($first === '"' || $first === "'") && $s[$n - 1] === $first) {
                return \substr($s, 1, $n - 2);
            }
        }
        return $s;
    }
}
