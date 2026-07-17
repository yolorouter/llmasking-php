<?php

// tests/Unit/EngineOptionKeywordsTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{Engine, EngineOption, EntityType, Recognizer, Region, Recognizers};
use Yolorouter\Llmasking\Exception\InvalidConfigException;

/**
 * Plan 2 task 3 wiring tests: EngineOption::withKeywords() option plumbing,
 * once-only enforcement, zero-arg legality, per-keyword validation rejection,
 * reserved "keyword" name collision protection, and end-to-end anonymize
 * integration through the assembled KeywordRecognizer.
 */
final class EngineOptionKeywordsTest extends TestCase
{
    // ------------------------------------------------------------------
    // withKeywords option plumbing
    // ------------------------------------------------------------------

    public function testWithKeywordsCalledTwiceRejected(): void
    {
        // The independent keywordsSeen flag enforces once-only regardless of
        // array contents; a second call must fail closed even if the arrays
        // differ (spec section 3.2 intentionally tightens Go's loose behavior).
        $this->expectException(InvalidConfigException::class);
        Engine::new(
            EngineOption::withKeywords('a'),
            EngineOption::withKeywords('b'),
        );
    }

    public function testZeroArgWithKeywordsIsLegal(): void
    {
        // Zero-arg means "mark keywords as configured"; it must NOT throw.
        // With no keywords supplied, no KeywordRecognizer is appended, so the
        // engine behaves as the default (built-in recognizers only).
        $e = Engine::new(EngineOption::withKeywords());
        self::assertSame('[PHONE_1]', $e->newSession()->anonymize('13800138000')->text);
    }

    public function testZeroArgWithKeywordsFollowedBySecondCallStillRejected(): void
    {
        // keywordsSeen is set by the zero-arg call, so a later non-empty
        // withKeywords() must still be rejected.
        $this->expectException(InvalidConfigException::class);
        Engine::new(
            EngineOption::withKeywords(),
            EngineOption::withKeywords('a'),
        );
    }

    // ------------------------------------------------------------------
    // Per-keyword validation (delegated to KeywordRecognizer construction)
    // ------------------------------------------------------------------

    public function testEmptyKeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withKeywords(''));
    }

    public function testDuplicateKeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withKeywords('a', 'a'));
    }

    public function testInvalidUtf8KeywordRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withKeywords("\xff"));
    }

    public function testTooManyKeywordsRejected(): void
    {
        // 3001 keywords exceeds MAX_KEYWORD_COUNT (3000).
        $patterns = \array_map(static fn (int $i): string => 'k' . $i, \range(0, 3000));
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withKeywords(...$patterns));
    }

    public function test3000KeywordsAccepted(): void
    {
        // Exactly 3000 keywords is the boundary; construction must succeed.
        $patterns = \array_map(static fn (int $i): string => 'k' . $i, \range(0, 2999));
        $e = Engine::new(EngineOption::withKeywords(...$patterns));
        // The keyword recognizer is appended; the engine is usable.
        self::assertSame('hello', $e->newSession()->anonymize('hello')->text);
    }

    public function testKeywordExceedingMaxInputBytesRejected(): void
    {
        // Lower MaxInputBytes so a short keyword exceeds it; the per-keyword
        // cap must fire at Engine construction.
        $this->expectException(InvalidConfigException::class);
        Engine::new(
            EngineOption::withMaxInputBytes(3),
            EngineOption::withKeywords('abcd'),
        );
    }

    // ------------------------------------------------------------------
    // Reserved "keyword" name still rejects CUSTOM recognizers
    // ------------------------------------------------------------------

    public function testCustomRecognizerNamedKeywordStillRejected(): void
    {
        // The reserved-name check must still reject a CUSTOM recognizer named
        // "keyword"; only the real KeywordRecognizer may use that name.
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withRecognizers(self::recognizerWithName('keyword')));
    }

    // ------------------------------------------------------------------
    // Integration: end-to-end anonymize through KeywordRecognizer
    // ------------------------------------------------------------------

    public function testKeywordAnonymizeProducesPlaceholder(): void
    {
        // Spec test matrix: 曼哈顿计划 in 这是曼哈顿计划的进展 -> [KEYWORD_1].
        $e = Engine::new(EngineOption::withKeywords('曼哈顿计划'));
        $r = $e->newSession()->anonymize('这是曼哈顿计划的进展');
        self::assertSame('这是[KEYWORD_1]的进展', $r->text);
    }

    public function testSameKeywordReusesPlaceholderAcrossCalls(): void
    {
        // Reversible Placeholder dedup: the same (KEYWORD, plaintext) mapping
        // is reused across anonymize calls on the same Session.
        $e = Engine::new(EngineOption::withKeywords('曼哈顿计划'));
        $s = $e->newSession();
        $r1 = $s->anonymize('这是曼哈顿计划的进展');
        $r2 = $s->anonymize('曼哈顿计划再次出现');
        self::assertSame('这是[KEYWORD_1]的进展', $r1->text);
        self::assertSame('[KEYWORD_1]再次出现', $r2->text);
    }

    public function testKeywordAppendedAfterBuiltins(): void
    {
        // The keyword recognizer is appended after the built-ins; a keyword
        // match still resolves when no built-in recognizer claims the span.
        $e = Engine::new(EngineOption::withKeywords('secret-project'));
        $recognizers = $e->recognizers;
        self::assertNotSame([], $recognizers);
        $last = $recognizers[\count($recognizers) - 1];
        self::assertSame('keyword', $last->name());
    }

    public function testKeywordWithZeroArgRecognizersYieldsOnlyKeywordRecognizer(): void
    {
        // withRecognizers() disables defaults; withKeywords() appends the
        // keyword recognizer. The result is an engine that only matches keywords.
        $e = Engine::new(
            EngineOption::withRecognizers(),
            EngineOption::withKeywords('曼哈顿计划'),
        );
        // A phone number is NOT masked (no built-in phone recognizer); the
        // keyword IS masked.
        $r = $e->newSession()->anonymize('13800138000 与 曼哈顿计划');
        self::assertSame('13800138000 与 [KEYWORD_1]', $r->text);
    }

    public function testKeywordLeftmostLongestThroughEngine(): void
    {
        // Nested keywords: '上海' and '上海银行'. Against '上海银行', the longer
        // pattern wins (LeftMostLongest), producing one KEYWORD placeholder.
        $e = Engine::new(EngineOption::withKeywords('上海', '上海银行'));
        $r = $e->newSession()->anonymize('在上海银行办理');
        self::assertSame('在[KEYWORD_1]办理', $r->text);
    }

    public function testKeywordEntityUsesDefaultPlaceholderStrategy(): void
    {
        // KEYWORD is not in the SECRET family, so it defaults to the reversible
        // Placeholder strategy and the plaintext can be restored.
        $e = Engine::new(EngineOption::withKeywords('曼哈顿计划'));
        $s = $e->newSession();
        $masked = $s->anonymize('这是曼哈顿计划的进展')->text;
        $restored = $s->restore($masked)->text;
        self::assertSame('这是[KEYWORD_1]的进展', $masked);
        self::assertSame('这是曼哈顿计划的进展', $restored);
    }

    /**
     * Build a minimal Recognizer whose name() returns the given bytes, so the
     * reserved-name validation path can be exercised independently.
     */
    private static function recognizerWithName(string $name): Recognizer
    {
        return new class ($name) implements Recognizer {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function recognize(string $text): array
            {
                return [];
            }
        };
    }
}
