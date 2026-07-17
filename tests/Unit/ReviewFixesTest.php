<?php

// tests/Unit/ReviewFixesTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{Engine, EngineOption, EntityType, Finding, Recognizer, RegexPattern, Region, RuleSpec, Strategies, Strategy, Recognizers};
use Yolorouter\Llmasking\Exception\{InvalidConfigException, InvalidFindingException, InvalidUTF8Exception, LimitExceededException};
use Yolorouter\Llmasking\Internal\{Candidate, ConflictResolver, Reversibility};

/**
 * Regression tests for the findings from the adversarial Plan 1 review. Each
 * test targets one confirmed defect; together they pin the fixed behavior.
 */
final class ReviewFixesTest extends TestCase
{
    // #1 — Session::anonymize checks MaxOutputBytes BEFORE committing state.
    public function testAnonymizeMaxOutputBytesFailsAtomically(): void
    {
        // Budget large enough that restore() of a single placeholder fits, but
        // small enough that the anonymize output (21 bytes) exceeds it — so a
        // pre-commit check throws atomically while a post-commit "bug" would
        // have written the mapping (observable via restore).
        $engine = Engine::new(EngineOption::withMaxOutputBytes(16));
        $session = $engine->newSession();
        try {
            // Output "用户[PHONE_1]登录" (21 bytes) exceeds the 16-byte budget.
            $session->anonymize('用户13800138000登录');
            self::fail('expected LimitExceededException');
        } catch (LimitExceededException $e) {
        }
        // The commit must NOT have happened: [PHONE_1] is unresolved.
        $restored = $session->restore('[PHONE_1]');
        self::assertFalse($restored->events[0]->restored);
    }

    // #7 — a custom Strategy returning invalid UTF-8 fails the call atomically.
    public function testStrategyInvalidUtf8OutputFailsAtomically(): void
    {
        $bad = new class () implements Strategy {
            public function apply(Finding $f, int $sequence): string
            {
                return "\xFF";
            }
        };
        $engine = Engine::new(
            EngineOption::withEntityType('FOO'),
            EngineOption::withStrategy('FOO', $bad),
            EngineOption::withRecognizers(Recognizers::rule(
                'foo',
                new RuleSpec('FOO', Region::Universal, RegexPattern::compile('/FOO/'), null, 0.9, false),
            )),
        );
        $this->expectException(InvalidUTF8Exception::class);
        $engine->newSession()->anonymize('aFOOb');
    }

    // #3 — maskMiddle uses mbstring (no PCRE); the prior preg_split false
    // path is eliminated by design. Masking output is covered by StrategiesTest.

    // #4 — entropy separator accepts newline/CR/FF (Go \s = [ \t\n\f\r]).
    public function testEntropySeparatorAcceptsNewline(): void
    {
        $session = Engine::new()->newSession();
        $masked = $session->anonymize("password\n=kQ7\$mZ2!xR9p")->text;
        self::assertStringContainsString('[SECRET_1]', $masked);
    }

    // #5 — entropy matches letter-prefixed keywords (no spurious lookbehind).
    public function testEntropyAcceptsLetterPrefixedKeyword(): void
    {
        $session = Engine::new()->newSession();
        $masked = $session->anonymize('xpassword=kQ7mZ2xR9p')->text;
        self::assertStringContainsString('[SECRET_1]', $masked);
    }

    // #6 — WithRegions filters a custom recognizer list, not only the defaults.
    public function testWithRegionsFiltersCustomRecognizerList(): void
    {
        $engine = Engine::new(
            EngineOption::withRecognizers(Recognizers::usPhone()),
            EngineOption::withRegions(Region::CN),
        );
        // usPhone is US-tagged; under Region::CN it must be filtered out.
        $masked = $engine->newSession()->anonymize('call (555) 123-4567 now')->text;
        self::assertSame('call (555) 123-4567 now', $masked);
    }

    // #8 — MaxRestoreEvents is a fixed constant and not configurable.
    public function testMaxRestoreEventsIsFixed(): void
    {
        self::assertSame(65536, Engine::MAX_RESTORE_EVENTS);
        self::assertSame(16 * 1024 * 1024, Engine::MAX_RESTORE_REPORT_BYTES);
    }

    // #11 — the raw-candidate cap has error priority over unknown-entity.
    public function testRawCandidateCapPriorityOverUnknownEntity(): void
    {
        $recognizer = new class () implements Recognizer {
            public function name(): string
            {
                return 'many';
            }

            public function recognize(string $text): array
            {
                $out = [];
                for ($i = 0; $i < 3001; $i++) {
                    $out[] = new Finding('UNKNOWNENTITY', $i, $i + 1, 0.5, 'x');
                }
                return $out;
            }
        };
        $engine = Engine::new(EngineOption::withRecognizers($recognizer));
        $this->expectException(LimitExceededException::class);
        $engine->newSession()->anonymize(str_repeat('x', 3001));
    }

    // #12 — only the built-in Placeholder singleton is reversible.
    public function testOnlyPlaceholderSingletonIsReversible(): void
    {
        self::assertTrue(Reversibility::isReversible(Strategies::placeholder()));
        self::assertFalse(Reversibility::isReversible(Strategies::redact()));
        $mimic = new class () implements Strategy {
            public function apply(Finding $f, int $sequence): string
            {
                return '[' . $f->entity . '_' . $sequence . ']';
            }
        };
        self::assertFalse(Reversibility::isReversible($mimic));
    }

    // #13 — a zero-length regex hit throws InvalidFindingException (spec 4.4).
    public function testZeroLengthMatchThrowsInvalidFindingException(): void
    {
        $rule = Recognizers::rule(
            'zlw',
            new RuleSpec('PHONE', Region::Universal, RegexPattern::compile('/x*/'), null, 0.5, false),
        );
        $this->expectException(InvalidFindingException::class);
        $rule->recognize('abc');
    }

    // #14 — Recognizers::rule() validates name and baseScore up front.
    public function testRuleFactoryRejectsEmptyName(): void
    {
        $this->expectException(InvalidConfigException::class);
        Recognizers::rule(
            '',
            new RuleSpec('PHONE', Region::Universal, RegexPattern::compile('/x/'), null, 0.5, false),
        );
    }

    public function testRuleFactoryRejectsNanBaseScore(): void
    {
        $this->expectException(InvalidConfigException::class);
        Recognizers::rule(
            'nan',
            new RuleSpec('PHONE', Region::Universal, RegexPattern::compile('/x/'), null, NAN, false),
        );
    }

    // #9 — StreamRestorer enforces cumulative MaxRestoreEvents across writes.
    public function testStreamRestorerCumulativeEventCap(): void
    {
        $session = Engine::new()->newSession();
        $restorer = $session->streamRestorer();
        // 33000 unresolved tokens per write: under the 65536 per-call cap, but
        // 33000 + 33000 > 65536 cumulative must abort on the second write.
        $chunk = str_repeat('[PHONE_99] ', 33000);
        $restorer->write($chunk);
        $this->expectException(LimitExceededException::class);
        $restorer->write($chunk);
    }

    // #2 + #10 — chunked restore equals one-shot restore across many inputs and
    // every byte split, including a fullwidth CLOSE bracket split mid-token.
    /** @return array<string, array{0: string}> */
    public static function streamEquivalenceInputs(): array
    {
        return [
            'ascii placeholder' => ['好的，联系[PHONE_1]马上'],
            'fullwidth close' => ['PHONE_1】'],
            'eof no close' => ['[PHONE_1'],
            'bare token' => ['a PHONE_1 b'],
        ];
    }

    /**
     * @dataProvider streamEquivalenceInputs
     */
    public function testStreamEquivalenceMatchesOneShot(string $input): void
    {
        $session = Engine::new()->newSession();
        $session->anonymize('13800138000');
        $expected = $session->restore($input)->text;

        $len = \strlen($input);
        for ($split = 1; $split <= $len; $split++) {
            $restorer = $session->streamRestorer();
            $out = '';
            for ($i = 0; $i < $len; $i += $split) {
                $out .= $restorer->write(\substr($input, $i, $split))->text;
            }
            $out .= $restorer->flush()->text;
            self::assertSame($expected, $out, "input={$input} byte-split={$split}");
        }
    }

    // #15 — the spec 4.5 regression: a wide Finding displaced by a SECRET must
    // not take down a disjoint Finding inside its span.
    public function testSecretPriorityDisplacedCandidateSurvivesElsewhere(): void
    {
        $text = str_repeat('a', 30);
        $secret = new Candidate(new Finding(EntityType::CLOUDKEY, 10, 20, 0.95, str_repeat('a', 10)), 0);
        $url = new Candidate(new Finding(EntityType::URL, 0, 30, 0.8, str_repeat('a', 30)), 1);
        $email = new Candidate(new Finding(EntityType::EMAIL, 22, 28, 0.9, str_repeat('a', 6)), 2);

        $resolved = ConflictResolver::resolve([$url, $secret, $email], $text);
        $entities = array_map(static fn (Finding $f) => $f->entity, $resolved);

        // SECRET[10,20) wins over URL[0,30); EMAIL[22,28) (disjoint) survives.
        self::assertSame([EntityType::CLOUDKEY, EntityType::EMAIL], $entities);
    }

    // #16 — every SECRET-family member rejects the Placeholder strategy.
    /** @return array<string, array{0: string}> */
    public static function secretFamily(): array
    {
        return [
            'CLOUDKEY' => [EntityType::CLOUDKEY],
            'PRIVATEKEY' => [EntityType::PRIVATEKEY],
            'JWT' => [EntityType::JWT],
            'GITTOKEN' => [EntityType::GITTOKEN],
            'SECRET' => [EntityType::SECRET],
        ];
    }

    /**
     * @dataProvider secretFamily
     */
    public function testSecretFamilyRejectsPlaceholder(string $entity): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withStrategy($entity, Strategies::placeholder()));
    }
}
