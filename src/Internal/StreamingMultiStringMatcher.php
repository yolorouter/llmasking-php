<?php

// src/Internal/StreamingMultiStringMatcher.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Streaming adapter bound to wikimedia/aho-corasick 2.0.0. Drives the
 * upstream automaton byte-by-byte via the public nextState() and reads the
 * protected $outputs / $searchKeywords to emit matches incrementally, so
 * callers can apply a bounded-window reduction (LeftMostLongest) without
 * ever calling searchIn() (which materializes every overlapping hit and
 * OOMs on a 1 MiB single-character-repeat subject under a 128M limit).
 *
 * Each yielded step covers exactly one byte end position; if multiple
 * patterns end at the same byte offset they all appear in that step. The
 * bounded-window algorithm in KeywordMatcher relies on this same-end
 * grouping to never finalize a start too early.
 *
 * Upstream stores pure-digit keywords under coerced int array keys, so the
 * yielded keyword is always cast back to string with (string) before being
 * returned to the caller.
 *
 * @internal Bound to the protected layout ($outputs, $searchKeywords) of
 *           wikimedia/aho-corasick 2.0.0; upgrading the dependency requires
 *           re-verifying those fields' shape and the search loop in
 *           MultiStringMatcher::searchIn().
 */
final class StreamingMultiStringMatcher extends \AhoCorasick\MultiStringMatcher
{
    /**
     * Yield [scannedEnd, list<[start, keyword]>] for each byte position that
     * has at least one pattern ending there. scannedEnd is the exclusive end
     * offset (byte index + 1). Same-end matches are batched into one step.
     *
     * The generator yields nothing for an empty subject or when no pattern
     * ends at the current byte. It never calls searchIn().
     *
     * @return \Generator<int, array{0: int, 1: list<array{0: int, 1: string}>}, mixed, void>
     */
    public function iterateMatchSteps(string $text): \Generator
    {
        if ($text === '' || $this->searchKeywords === []) {
            return;
        }
        $state = 0;
        $length = \strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $state = $this->nextState($state, $text[$i]);
            if (!$this->outputs[$state]) {
                continue;
            }
            $hits = [];
            foreach ($this->outputs[$state] as $match) {
                // Upstream coerces pure-digit array keys to int; the length
                // lookup works either way because $searchKeywords was built
                // with the same coerced key. (string) restores the literal
                // keyword bytes the caller configured. Cast the length to int
                // because the parent's protected $searchKeywords is typed as
                // plain array (mixed values) in upstream 2.0.0.
                $keyword = (string) $match;
                $kwLen = (int) $this->searchKeywords[$match];
                $hits[] = [$i - $kwLen + 1, $keyword];
            }
            yield [$i + 1, $hits];
        }
    }
}
