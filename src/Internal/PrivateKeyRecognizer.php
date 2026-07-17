<?php

// src/Internal/PrivateKeyRecognizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{EntityType, Finding, Recognizer, Region};

/**
 * Deterministic BEGIN/END byte scanner for PEM private-key blocks. Matches the
 * seven standard PEM private-key fences without relying on PCRE cross-block
 * matching: the scanner finds the leftmost BEGIN header, pairs it with the
 * nearest same-family END footer, emits one Finding spanning [headerStart,
 * endOfFooter), and advances the cursor past it. An incomplete BEGIN (no
 * matching END) is skipped by advancing past that header so later complete
 * blocks still survive. Blocks are non-overlapping and leftmost-first.
 *
 * findNextBegin() locates the next header with ONE strpos on the shared
 * '-----BEGIN ' prefix (then a bounded family check), so the whole pass is
 * O(n) in the input rather than O(families × n) per block. Enforces the local
 * 3000-candidate cap per spec section 4.4.
 */
final class PrivateKeyRecognizer implements Recognizer
{
    /**
     * Recognized PEM private-key fence families, longest/most-specific first so
     * findNextBegin's family check never confuses a specific family header with
     * the generic "PRIVATE KEY" header.
     */
    private const FENCES = [
        'PGP PRIVATE KEY BLOCK',
        'RSA PRIVATE KEY',
        'EC PRIVATE KEY',
        'OPENSSH PRIVATE KEY',
        'DSA PRIVATE KEY',
        'ENCRYPTED PRIVATE KEY',
        'PRIVATE KEY',
    ];

    private const BEGIN_PREFIX = '-----BEGIN ';

    private const END_PREFIX = '-----END ';

    public function name(): string
    {
        return 'private_key';
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
        $findings = [];
        $cursor = 0;
        $len = \strlen($text);
        $rawCount = 0;
        // Once a family is proven to have no END footer at/after a position, no
        // later BEGIN of that family can match either (the text is fixed) — so
        // we cache that fact instead of re-scanning to EOF for every incomplete
        // BEGIN, keeping the whole pass O(n) rather than O(families × n).
        $noEndAfter = [];
        while ($cursor < $len) {
            $beginAt = $this->findNextBegin($text, $cursor);
            if ($beginAt === null) {
                break;
            }
            // Spec section 4.4: library built-in recognizers must enforce the
            // local 3000-candidate cap internally via incremental enumeration.
            $rawCount++;
            Pcre::assertRawCap($rawCount);
            [$family, $beginHeaderStart] = $beginAt;
            $end = null;
            if (!(isset($noEndAfter[$family]) && $beginHeaderStart >= $noEndAfter[$family])) {
                $end = $this->findSameFamilyEnd($text, $beginHeaderStart, $family);
                if ($end === null) {
                    $noEndAfter[$family] = $beginHeaderStart;
                }
            }
            if ($end === null) {
                // Incomplete BEGIN with no matching END: advance past this header
                // so a later, complete block at a higher offset is still detected.
                $cursor = $beginHeaderStart + \strlen(self::BEGIN_PREFIX . $family . '-----');
                continue;
            }
            $findings[] = new Finding(
                EntityType::PRIVATEKEY,
                $beginHeaderStart,
                $end,
                1.0,
                \substr($text, $beginHeaderStart, $end - $beginHeaderStart),
            );
            $cursor = $end;
        }
        return $findings;
    }

    /**
     * Find the leftmost '-----BEGIN <family>-----' header at or after $from.
     * Uses a single strpos on the shared '-----BEGIN ' prefix per gap, then a
     * bounded family check, so the scan stays linear across many headers.
     *
     * @return array{string,int}|null tuple of [family, headerStart]
     */
    private function findNextBegin(string $text, int $from): ?array
    {
        $prefixLen = \strlen(self::BEGIN_PREFIX);
        $pos = $from;
        while (true) {
            $pos = \strpos($text, self::BEGIN_PREFIX, $pos);
            if ($pos === false) {
                return null;
            }
            // Bounded slice after the prefix: long enough for the longest fence
            // ('PGP PRIVATE KEY BLOCK-----') plus margin, avoiding a full-suffix substr.
            $candidate = \substr($text, $pos + $prefixLen, 32);
            foreach (self::FENCES as $family) {
                if (\str_starts_with($candidate, $family . '-----')) {
                    return [$family, $pos];
                }
            }
            // '-----BEGIN ' not followed by a known private-key family (e.g. a
            // certificate): advance past this prefix and keep scanning.
            $pos += $prefixLen;
        }
    }

    /**
     * Find the byte offset one past the nearest same-family END footer at or
     * after $beginStart, or null if none exists.
     */
    private function findSameFamilyEnd(string $text, int $beginStart, string $family): ?int
    {
        $footer = self::END_PREFIX . $family . '-----';
        $pos = \strpos($text, $footer, $beginStart);
        if ($pos === false) {
            return null;
        }
        return $pos + \strlen($footer);
    }
}
