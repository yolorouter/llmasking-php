<?php

// tests/Unit/Internal/ConflictResolverTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{EntityType, Finding};
use Yolorouter\Llmasking\Internal\{Candidate, ConflictResolver};
use Yolorouter\Llmasking\Exception\{InvalidFindingException, LimitExceededException};

final class ConflictResolverTest extends TestCase
{
    public function testSecretWinsOverLongerOverlap(): void
    {
        // Input is 40 x's. URL covers [0,40); CLOUDKEY covers [10,30) which is
        // 20 x's (the span content). SECRET priority beats URL's longer span.
        $text = str_repeat('x', 40);
        $url = new Candidate(new Finding(EntityType::URL, 0, 40, 0.8, str_repeat('x', 40)), 0);
        $key = new Candidate(new Finding(EntityType::CLOUDKEY, 10, 30, 0.95, str_repeat('x', 20)), 1);
        $out = ConflictResolver::resolve([$url, $key], $text);
        self::assertSame(EntityType::CLOUDKEY, $out[0]->entity);
    }

    /**
     * Spec section 4.5 regression: a candidate displaced at one span by a
     * higher-priority winner must still survive at a disjoint span. EMAIL at
     * [5,15) is displaced by the longer URL at [0,20); EMAIL at [25,35) is
     * disjoint from URL and must survive.
     */
    public function testDisplacedCandidateSurvivesElsewhere(): void
    {
        $text = str_repeat('x', 40);
        $url = new Candidate(new Finding(EntityType::URL, 0, 20, 0.8, str_repeat('x', 20)), 0);
        $emailDisplaced = new Candidate(new Finding(EntityType::EMAIL, 5, 15, 0.9, str_repeat('x', 10)), 1);
        $emailSurvivor = new Candidate(new Finding(EntityType::EMAIL, 25, 35, 0.9, str_repeat('x', 10)), 1);

        $out = ConflictResolver::resolve([$url, $emailDisplaced, $emailSurvivor], $text);

        // Two accepted findings: URL at [0,20) and EMAIL at [25,35); the EMAIL
        // at [5,15) is dropped because it overlaps the longer URL.
        self::assertCount(2, $out);
        self::assertSame(EntityType::URL, $out[0]->entity);
        self::assertSame(0, $out[0]->start);
        self::assertSame(20, $out[0]->end);
        self::assertSame(EntityType::EMAIL, $out[1]->entity);
        self::assertSame(25, $out[1]->start);
        self::assertSame(35, $out[1]->end);
    }

    public function testInvalidFindingThrows(): void
    {
        $bad = new Candidate(new Finding('PHONE', 0, 3, 0.5, 'xxx'), 0); // text mismatch vs 'abc'
        $this->expectException(InvalidFindingException::class);
        ConflictResolver::resolve([$bad], 'abc');
    }

    public function testRawCandidateCap(): void
    {
        $text = str_repeat('a', 1);
        $cands = [];
        for ($i = 0; $i < 3001; $i++) {
            $cands[] = new Candidate(new Finding('X', 0, 1, 0.5, 'a'), $i);
        }
        $this->expectException(LimitExceededException::class);
        ConflictResolver::resolve($cands, 'a');
    }

    /**
     * Spec section 4.5 regression: when equal-priority candidates overlap
     * exactly (three non-SECRET entities, same span, same score), the one from
     * the earliest-declared recognizer (lowest recognizerIndex) always wins,
     * regardless of the order in which candidates are fed to resolve().
     *
     * The three candidates use distinct entity types (URL/EMAIL/IP) so the
     * winner is observable through Finding::$entity; they tie on every
     * priority dimension except recognizerIndex, so that tiebreaker alone
     * decides. Shuffle the input order across 256 trials and assert that the
     * winner is invariant.
     */
    public function testEqualPriorityStableOrderUnderRandomShuffle(): void
    {
        // Pre-seed for reproducibility.
        \mt_srand(20260717);

        $text = str_repeat('a', 10);
        $span = str_repeat('a', 10);
        $a = new Candidate(new Finding(EntityType::URL, 0, 10, 0.7, $span), 0);
        $b = new Candidate(new Finding(EntityType::EMAIL, 0, 10, 0.7, $span), 1);
        $c = new Candidate(new Finding(EntityType::IP, 0, 10, 0.7, $span), 2);

        for ($trial = 0; $trial < 256; $trial++) {
            $input = [$a, $b, $c];
            \shuffle($input);
            $out = ConflictResolver::resolve($input, $text);

            // Exactly one survives (all three overlap at the same span).
            self::assertCount(1, $out, 'trial ' . $trial);
            // The lowest recognizerIndex (URL, recognizerIndex 0) wins,
            // independent of input order.
            self::assertSame(EntityType::URL, $out[0]->entity, 'trial ' . $trial);
        }
    }

    /**
     * Half-open span semantics: [0,5) and [5,10) adjoin but do not overlap,
     * so both must be accepted.
     */
    public function testAdjoiningSpansBothAccepted(): void
    {
        $text = str_repeat('a', 10);
        $left = new Candidate(new Finding(EntityType::PHONE, 0, 5, 0.7, 'aaaaa'), 0);
        $right = new Candidate(new Finding(EntityType::EMAIL, 5, 10, 0.7, 'aaaaa'), 1);

        $out = ConflictResolver::resolve([$left, $right], $text);

        self::assertCount(2, $out);
        self::assertSame(0, $out[0]->start);
        self::assertSame(5, $out[1]->start);
    }

    /**
     * Score out of [0,1], NaN, and Inf are all rejected as InvalidFinding.
     *
     * @dataProvider invalidScoreProvider
     */
    public function testInvalidScoreRejected(float $score): void
    {
        $bad = new Candidate(new Finding('PHONE', 0, 1, $score, 'a'), 0);
        $this->expectException(InvalidFindingException::class);
        ConflictResolver::resolve([$bad], 'a');
    }

    /** @return array<string, array{0:float}> */
    public static function invalidScoreProvider(): array
    {
        return [
            'negative' => [-0.1],
            'over one' => [1.1],
            'nan' => [\NAN],
            'inf' => [\INF],
        ];
    }

    /**
     * A UTF-8 multibyte sequence split mid-codepoint is rejected: the boundary
     * check enforces that start/end land on codepoint boundaries.
     */
    public function testUtf8BoundaryViolationRejected(): void
    {
        // '中' is 3 bytes: 0xE4 0xB8 0xAD. Offset 1 splits the codepoint.
        $text = '中';
        $bad = new Candidate(new Finding('PHONE', 1, 3, 0.7, "\xb8\xad"), 0);
        $this->expectException(InvalidFindingException::class);
        ConflictResolver::resolve([$bad], $text);
    }
}
