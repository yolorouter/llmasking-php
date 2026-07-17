<?php

// tests/Unit/Internal/KeywordMatcherTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Exception\InvalidConfigException;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Internal\KeywordMatch;
use Yolorouter\Llmasking\Internal\KeywordMatcher;
use Yolorouter\Llmasking\Internal\StreamingMultiStringMatcher;

/**
 * Plan 2 test matrix: StreamingMultiStringMatcher contract (hit positions,
 * same-end batching, pure-digit upstream key handling), KeywordMatcher
 * LeftMostLongest semantics, resource caps (3000 results / 3000 pending
 * starts / 1 MiB high-repeat must not memory-exhaust under the 128M
 * phpunit memory_limit), and construction validation (3000/3001 count,
 * 64 KiB total-bytes boundary, per-keyword MaxInputBytes, empty/dup/
 * invalid-UTF-8 keywords).
 */
final class KeywordMatcherTest extends TestCase
{
    // ------------------------------------------------------------------
    // StreamingMultiStringMatcher: iterateMatchSteps contract
    // ------------------------------------------------------------------

    public function testIterateMatchStepsYieldsScannedEndAndStartOffset(): void
    {
        // Upstream docstring example: 'hell' at offset 15, 'ore' at offset 34.
        // 'hell' (4 bytes) ends at 19; 'ore' (3 bytes) ends at 37.
        $m = new StreamingMultiStringMatcher(['ore', 'hell']);
        $steps = \iterator_to_array($m->iterateMatchSteps('She sells sea shells by the sea shore.'), false);

        $byEnd = [];
        foreach ($steps as [$end, $hits]) {
            $byEnd[$end] = $hits;
        }
        self::assertArrayHasKey(19, $byEnd);
        self::assertSame([15, 'hell'], $byEnd[19][0]);
        self::assertArrayHasKey(37, $byEnd);
        self::assertSame([34, 'ore'], $byEnd[37][0]);
    }

    public function testIterateMatchStepsBatchesSameEndHits(): void
    {
        // 'a' and 'ba' both end at byte 3 of 'aba' (a at [2,3), ba at [1,3)).
        $m = new StreamingMultiStringMatcher(['a', 'ba']);
        $steps = \iterator_to_array($m->iterateMatchSteps('aba'), false);

        $hitsAtEnd3 = null;
        foreach ($steps as [$end, $hits]) {
            if ($end === 3) {
                $hitsAtEnd3 = $hits;
                break;
            }
        }
        self::assertNotNull($hitsAtEnd3);
        self::assertCount(2, $hitsAtEnd3);
    }

    public function testIterateMatchStepsHandlesPureDigitKeywordKeys(): void
    {
        // wikimedia/aho-corasick 2.0.0 coerces pure-digit array keys to int;
        // the adapter must cast back to string and still report correct bytes.
        $m = new StreamingMultiStringMatcher(['0', '123']);
        $steps = \iterator_to_array($m->iterateMatchSteps('0 123'), false);

        $hitsByStart = [];
        foreach ($steps as [$_, $hits]) {
            foreach ($hits as [$start, $kw]) {
                $hitsByStart[$start] = $kw;
            }
        }
        self::assertSame('0', $hitsByStart[0]);
        self::assertSame('123', $hitsByStart[2]);
    }

    public function testIterateMatchStepsEmptyInputYieldsNothing(): void
    {
        $m = new StreamingMultiStringMatcher(['a']);
        self::assertSame([], \iterator_to_array($m->iterateMatchSteps(''), false));
    }

    // ------------------------------------------------------------------
    // KeywordMatcher: LeftMostLongest semantics (spec section 7.1)
    // ------------------------------------------------------------------

    public function testNestedShanghaiReturnsOnlyLongest(): void
    {
        // Spec 7.1: ['上海','上海银行'] against '上海银行' must return only
        // '上海银行' (the longer pattern), not the nested shorter '上海'.
        $m = new KeywordMatcher(['上海', '上海银行']);
        $r = $m->findLeftmostLongest('上海银行');

        self::assertCount(1, $r);
        self::assertSame(1, $r[0]->patternIndex); // '上海银行' is index 1
        self::assertSame(0, $r[0]->start);
        // '上海银行' = 4 Han chars x 3 bytes = 12 bytes
        self::assertSame(12, $r[0]->end);
    }

    public function testRepeatedPositionsReturnSeparateMatches(): void
    {
        // 'ab' against 'ababab' -> 3 non-overlapping matches at 0, 2, 4.
        $m = new KeywordMatcher(['ab']);
        $r = $m->findLeftmostLongest('ababab');

        self::assertCount(3, $r);
        self::assertSame([0, 2, 4], \array_map(static fn (KeywordMatch $x): int => $x->start, $r));
        self::assertSame([2, 4, 6], \array_map(static fn (KeywordMatch $x): int => $x->end, $r));
    }

    public function testSameEndMultiHitLeftmostLongestNonOverlapping(): void
    {
        // ['a','ba'] against 'aba': hits end at byte 1 ('a' at [0,1)) and at
        // byte 3 ('ba' at [1,3) and 'a' at [2,3)). LeftMostLongest selects
        // [0,1) then continues from 1 and selects [1,3). Result = two matches.
        $m = new KeywordMatcher(['a', 'ba']);
        $r = $m->findLeftmostLongest('aba');

        self::assertCount(2, $r);
        self::assertSame(0, $r[0]->start);
        self::assertSame(1, $r[0]->end);
        self::assertSame(1, $r[1]->start);
        self::assertSame(3, $r[1]->end);
    }

    public function testPureDigitKeywordsPreserveTextAndIndex(): void
    {
        $m = new KeywordMatcher(['0', '123']);
        $r = $m->findLeftmostLongest('0 123');

        self::assertCount(2, $r);
        self::assertSame(0, $r[0]->patternIndex); // '0'
        self::assertSame(0, $r[0]->start);
        self::assertSame(1, $r[0]->end);
        self::assertSame(1, $r[1]->patternIndex); // '123'
        self::assertSame(2, $r[1]->start);
        self::assertSame(5, $r[1]->end);
    }

    public function testUtf8ByteOffsetsAreByteNotCodepoint(): void
    {
        // 'x上海': 'x' is byte 0; '上海' occupies bytes 1..6 (3 bytes per Han char).
        $m = new KeywordMatcher(['上海']);
        $r = $m->findLeftmostLongest('x上海');

        self::assertCount(1, $r);
        self::assertSame(1, $r[0]->start);
        self::assertSame(7, $r[0]->end); // 1 + 6 bytes
    }

    public function testFindLeftmostLongestEmptyInputReturnsEmpty(): void
    {
        self::assertSame([], (new KeywordMatcher(['a']))->findLeftmostLongest(''));
    }

    public function testFindLeftmostLongestNoMatchesReturnsEmpty(): void
    {
        self::assertSame([], (new KeywordMatcher(['cat']))->findLeftmostLongest('hello world'));
    }

    public function testNestedKeywordAcrossGapSelectsLeftmostLongestPerSpan(): void
    {
        // '上海' matches both the standalone occurrence and inside '上海银行';
        // leftmost-longest must keep the longer '上海银行' span and the standalone.
        $m = new KeywordMatcher(['上海', '上海银行']);
        $r = $m->findLeftmostLongest('x上海银行y上海z');

        self::assertCount(2, $r);
        // First: '上海银行' at bytes 1..13
        self::assertSame(1, $r[0]->start);
        self::assertSame(13, $r[0]->end);
        self::assertSame(1, $r[0]->patternIndex);
        // Second: '上海' at bytes 14..20 ('y' = byte 13, then '上海' = 14..20)
        self::assertSame(14, $r[1]->start);
        self::assertSame(20, $r[1]->end);
        self::assertSame(0, $r[1]->patternIndex);
    }

    // ------------------------------------------------------------------
    // KeywordMatcher: resource caps
    // ------------------------------------------------------------------

    public function test1MiBHighRepeatThrowsLimitBeforeMemoryExhaustion(): void
    {
        // 1 MiB of 'a' against ['a','aa'] would naively materialize ~1M
        // overlapping matches; the bounded-window adapter must throw
        // LimitExceededException long before exhausting the 128M phpunit limit.
        $m = new KeywordMatcher(['a', 'aa']);
        $this->expectException(LimitExceededException::class);
        $m->findLeftmostLongest(\str_repeat('a', 1024 * 1024));
    }

    public function testSelectedCountCapThrowsLimitExceeded(): void
    {
        // 'ab' against 'ababab...' yields one accepted result per 2 bytes.
        // 3001 repetitions -> 3001 selected results -> cap fires.
        $m = new KeywordMatcher(['ab']);
        $this->expectException(LimitExceededException::class);
        $m->findLeftmostLongest(\str_repeat('ab', 3001));
    }

    public function testPendingStartsCapThrowsLimitExceeded(): void
    {
        // 'a' against 'aaa...' yields one hit per byte; the long co-keyword
        // keeps the finalize window open so pending starts accumulate past 3000.
        $m = new KeywordMatcher(['a', \str_repeat('x', 7000)]);
        $this->expectException(LimitExceededException::class);
        $m->findLeftmostLongest(\str_repeat('a', 3001));
    }

    // ------------------------------------------------------------------
    // KeywordMatcher: construction validation
    // ------------------------------------------------------------------

    public function testEmptyKeywordListRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher([]);
    }

    public function testEmptyKeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher(['a', '']);
    }

    public function testDuplicateKeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher(['a', 'a']);
    }

    public function testInvalidUtf8KeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher(["\xff"]);
    }

    public function test3000KeywordsConstructionSucceeds(): void
    {
        $patterns = \array_map(static fn (int $i): string => 'k' . $i, \range(0, 2999));
        $m = new KeywordMatcher($patterns);
        // Smoke: construction did not throw and the matcher is usable.
        self::assertSame([], $m->findLeftmostLongest(''));
    }

    public function test3001KeywordsConstructionFails(): void
    {
        $patterns = \array_map(static fn (int $i): string => 'k' . $i, \range(0, 3000));
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher($patterns);
    }

    public function test64KiBTotalBytesSucceeds(): void
    {
        // Single keyword whose length equals MAX_TOTAL_KEYWORD_BYTES exactly.
        $m = new KeywordMatcher([\str_repeat('a', 65536)]);
        self::assertSame([], $m->findLeftmostLongest(''));
    }

    public function test64KiBPlusOneTotalBytesFails(): void
    {
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher([\str_repeat('a', 65537)]);
    }

    public function testKeywordExceedingMaxInputBytesFails(): void
    {
        // Custom MaxInputBytes=4; 5-byte keyword must be rejected per-keyword.
        $this->expectException(InvalidConfigException::class);
        new KeywordMatcher(['abcde'], 4);
    }

    public function testDefaultMaxInputBytesAllowsLargeKeywordUnder1MiB(): void
    {
        // A 100 KiB keyword is under the default 1 MiB MaxInputBytes; it must
        // be accepted (so long as total bytes stay <= 64 KiB, this requires a
        // custom raised total-bytes cap, which we expose via the engine only;
        // here we verify the per-keyword cap does not fire under the default).
        // 65536 bytes is also the 64 KiB total boundary, so use exactly that.
        $m = new KeywordMatcher([\str_repeat('a', 65536)]);
        self::assertSame([], $m->findLeftmostLongest(''));
    }
}
