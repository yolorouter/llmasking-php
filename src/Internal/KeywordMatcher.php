<?php

// src/Internal/KeywordMatcher.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Exception\{InvalidConfigException, LimitExceededException};

/**
 * Keyword recognizer adapter over wikimedia/aho-corasick 2.0.0. Validates the
 * keyword set up front (non-empty, valid UTF-8, no byte-level duplicates,
 * count / total-bytes / per-pattern caps) BEFORE constructing the upstream
 * automaton, then exposes findLeftmostLongest() which streams matches through
 * a bounded-window LeftMostLongest reduction.
 *
 * @internal
 */
final class KeywordMatcher
{
    public const MAX_KEYWORD_COUNT = 3000;
    public const MAX_TOTAL_KEYWORD_BYTES = 64 * 1024;
    public const MAX_PENDING_STARTS = 3000;
    public const MAX_RESULTS = 3000;
    public const DEFAULT_MAX_INPUT_BYTES = 1024 * 1024;

    /** @var array<string, int> patternKey(keyword) => patternIndex */
    private array $patternIndexMap;

    /** @var list<string> patternIndex => keyword string */
    private array $patternsByIndex;

    private int $maxPatternBytes;

    private StreamingMultiStringMatcher $matcher;

    /**
     * @param list<string> $patterns
     * @param int          $maxInputBytes per-keyword byte cap (defaults to 1 MiB)
     *
     * @throws InvalidConfigException on any validation failure
     */
    public function __construct(array $patterns, int $maxInputBytes = self::DEFAULT_MAX_INPUT_BYTES)
    {
        if ($patterns === []) {
            throw new InvalidConfigException('keywords list must not be empty');
        }
        if (\count($patterns) > self::MAX_KEYWORD_COUNT) {
            throw new InvalidConfigException(
                'keyword count ' . \count($patterns) . ' exceeds ' . self::MAX_KEYWORD_COUNT,
            );
        }
        if ($maxInputBytes <= 0) {
            throw new InvalidConfigException('maxInputBytes must be positive');
        }

        // Single pass: validate AND build patternIndexMap / patternsByIndex /
        // maxPatternBytes, so we never walk the list twice.
        $this->patternIndexMap = [];
        $this->patternsByIndex = [];
        $this->maxPatternBytes = 0;
        $totalBytes = 0;
        /** @var array<string, true> $seen patternKey(keyword) => true */
        $seen = [];
        foreach (\array_values($patterns) as $idx => $kw) {
            if (!\is_string($kw)) {
                throw new InvalidConfigException('keyword at index ' . $idx . ' is not a string');
            }
            if ($kw === '') {
                throw new InvalidConfigException('keyword at index ' . $idx . ' is empty');
            }
            if (!\mb_check_encoding($kw, 'UTF-8')) {
                throw new InvalidConfigException('keyword at index ' . $idx . ' is not valid UTF-8');
            }
            $key = self::patternKey($kw);
            if (isset($seen[$key])) {
                throw new InvalidConfigException('duplicate keyword at index ' . $idx);
            }
            $seen[$key] = true;
            $len = \strlen($kw);
            if ($len > $maxInputBytes) {
                throw new InvalidConfigException(
                    'keyword at index ' . $idx . ' length ' . $len
                    . ' exceeds maxInputBytes ' . $maxInputBytes,
                );
            }
            $totalBytes += $len;
            if ($totalBytes > self::MAX_TOTAL_KEYWORD_BYTES) {
                throw new InvalidConfigException(
                    'total keyword bytes ' . $totalBytes
                    . ' exceed ' . self::MAX_TOTAL_KEYWORD_BYTES,
                );
            }
            $this->patternIndexMap[$key] = $idx;
            $this->patternsByIndex[$idx] = $kw;
            if ($len > $this->maxPatternBytes) {
                $this->maxPatternBytes = $len;
            }
        }

        $this->matcher = new StreamingMultiStringMatcher($patterns);
    }

    /** NUL-prefixed key that defeats PHP's numeric-string array-key coercion. */
    private static function patternKey(string $keyword): string
    {
        return "\0" . $keyword;
    }

    /**
     * Return the configured pattern string for $patternIndex. PHP strings are
     * copy-on-write, so this is a refcount bump (no byte copy) — cheaper than
     * substr() of the matched text, and byte-identical (the AC match IS the
     * pattern bytes).
     */
    public function pattern(int $patternIndex): string
    {
        return $this->patternsByIndex[$patternIndex];
    }

    /**
     * Return non-overlapping LeftMostLongest matches in $text as a list of
     * KeywordMatch (sorted by byte start ascending).
     *
     * @return list<KeywordMatch>
     *
     * @throws LimitExceededException when selected results exceed MAX_RESULTS
     *                                or simultaneously-pending starts exceed
     *                                MAX_PENDING_STARTS.
     */
    public function findLeftmostLongest(string $text): array
    {
        if ($text === '') {
            return [];
        }

        /** @var array<int, array{0: int, 1: int}> $longestAtStart start => [end, patternIndex] */
        $longestAtStart = [];
        /** @var \SplMinHeap<int> $heap indexed min-heap of pending starts */
        $heap = new \SplMinHeap();
        /** @var list<KeywordMatch> $results */
        $results = [];
        $selectedEnd = 0;

        foreach ($this->matcher->iterateMatchSteps($text) as [$scannedEnd, $hits]) {
            foreach ($hits as [$start, $kw]) {
                $end = $start + \strlen($kw);
                $existing = $longestAtStart[$start] ?? null;
                if ($existing === null) {
                    $longestAtStart[$start] = [$end, $this->patternIndexMap[self::patternKey($kw)]];
                    $heap->insert($start);
                } elseif ($end > $existing[0]) {
                    $longestAtStart[$start] = [$end, $this->patternIndexMap[self::patternKey($kw)]];
                }
            }

            // Finalize: once the scan is past earliestStart + maxPatternBytes,
            // the earliest pending start can no longer gain a longer candidate.
            while (!$heap->isEmpty()) {
                $earliest = (int) $heap->top();
                if ($scannedEnd < $earliest + $this->maxPatternBytes) {
                    break;
                }
                $heap->extract();
                self::commitStart($earliest, $longestAtStart, $results, $selectedEnd);
            }

            if (\count($longestAtStart) > self::MAX_PENDING_STARTS) {
                throw new LimitExceededException(
                    'keyword pending starts exceed ' . self::MAX_PENDING_STARTS,
                );
            }
        }

        // EOF: drain remaining pending starts in ascending heap order.
        while (!$heap->isEmpty()) {
            self::commitStart((int) $heap->extract(), $longestAtStart, $results, $selectedEnd);
        }

        return $results;
    }

    /**
     * Commit or discard one finalized pending start: apply the non-overlap rule
     * ($start >= $selectedEnd) and the MAX_RESULTS cap. Shared by the in-loop
     * finalize and the EOF drain so the two paths can never diverge.
     *
     * @param array<int, array{0: int, 1: int}> $longestAtStart
     * @param list<KeywordMatch>                $results
     */
    private static function commitStart(int $start, array &$longestAtStart, array &$results, int &$selectedEnd): void
    {
        [$end, $pIdx] = $longestAtStart[$start];
        unset($longestAtStart[$start]);
        if ($start >= $selectedEnd) {
            $results[] = new KeywordMatch($pIdx, $start, $end);
            $selectedEnd = $end;
        }
        if (\count($results) > self::MAX_RESULTS) {
            throw new LimitExceededException(
                'keyword selected results exceed ' . self::MAX_RESULTS,
            );
        }
    }
}
