<?php

// tests/Unit/EngineOptionTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{Engine, EngineOption, EntityType, Recognizer, Region, Recognizers, Strategies};
use Yolorouter\Llmasking\Exception\InvalidConfigException;

final class EngineOptionTest extends TestCase
{
    public function testDefaultEngineLoadsAllBuiltins(): void
    {
        $e = Engine::new();
        $s = $e->newSession();
        $r = $s->anonymize('13800138000');
        self::assertSame('[PHONE_1]', $r->text);
    }

    public function testSecretCannotTakePlaceholder(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withStrategy(EntityType::CLOUDKEY, Strategies::placeholder()));
    }

    public function testCustomEntityMustRegisterBeforeStrategy(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withStrategy('ORDERID', Strategies::placeholder()));
    }

    public function testWithRegionsUsOnlyKeepsUniversalPlusUs(): void
    {
        $e = Engine::new(EngineOption::withRegions(Region::US));
        $r = $e->newSession()->anonymize('SSN 123-45-6789, 13800138000');
        // CN phone (13800138000) is not masked: only Universal + US recognizers
        // are active. The US SSN recognizer masks the digit pattern. Note: the
        // plan's literal assertion swapped "SSN " and "[SSN_1] " — corrected
        // here to the only behavior consistent with the configured recognizers.
        self::assertSame('SSN [SSN_1], 13800138000', $r->text);
    }

    public function testZeroArgWithRecognizersDisablesDefaults(): void
    {
        $e = Engine::new(EngineOption::withRecognizers());
        self::assertSame('13800138000', $e->newSession()->anonymize('13800138000')->text);
    }

    // --- Step 4 boundary supplement: spec §10.1 -----------------------------
    // The tests below cover the validation paths the Step 1 prose requires:
    // Option override order, custom-entity registration order (positive),
    // duplicate-entity idempotence, Universal region rejection, recognizer
    // name (empty / duplicate / >128B / invalid UTF-8 / reserved "keyword"),
    // entity-name format / length / UTF-8, and non-positive max*.

    public function testOptionOverrideOrderLastStrategyWins(): void
    {
        // A later WithStrategy for the same entity overrides an earlier one.
        $e = Engine::new(
            EngineOption::withStrategy(EntityType::PHONE, Strategies::redact()),
            EngineOption::withStrategy(EntityType::PHONE, Strategies::placeholder()),
        );
        self::assertSame(Strategies::placeholder(), $e->strategyFor(EntityType::PHONE));
    }

    public function testOptionOverrideOrderLastMaxEntitiesWins(): void
    {
        // A later WithMaxEntities overrides an earlier one; the final value is
        // the last one supplied.
        $e = Engine::new(
            EngineOption::withMaxEntities(5),
            EngineOption::withMaxEntities(42),
        );
        self::assertSame(42, $e->maxEntities);
    }

    public function testWithEntityTypeThenWithStrategySucceeds(): void
    {
        // Custom-entity registration order: registering the type before the
        // strategy override must succeed (negative case is covered by
        // testCustomEntityMustRegisterBeforeStrategy above).
        $e = Engine::new(
            EngineOption::withEntityType('ORDERID'),
            EngineOption::withStrategy('ORDERID', Strategies::placeholder()),
        );
        self::assertSame(Strategies::placeholder(), $e->strategyFor('ORDERID'));
        self::assertArrayHasKey('ORDERID', $e->knownEntities);
    }

    public function testWithEntityTypeIsIdempotent(): void
    {
        // Registering the same custom entity twice is a no-op, not an error.
        $e = Engine::new(
            EngineOption::withEntityType('FOO'),
            EngineOption::withEntityType('FOO'),
        );
        self::assertArrayHasKey('FOO', $e->knownEntities);
    }

    public function testWithRegionsRejectsUniversal(): void
    {
        // Universal is always enabled implicitly, so WithRegions must reject it.
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withRegions(Region::Universal));
    }

    public function testRecognizerEmptyNameRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withRecognizers(self::recognizerWithName('')));
    }

    public function testRecognizerDuplicateNameRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withRecognizers(
            self::recognizerWithName('dup'),
            self::recognizerWithName('dup'),
        ));
    }

    public function testRecognizerNameExceeding128BytesRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // 129 bytes of ASCII exceeds the 128-byte recognizer-name cap.
        Engine::new(EngineOption::withRecognizers(self::recognizerWithName(\str_repeat('A', 129))));
    }

    public function testRecognizerInvalidUtf8NameRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // "\xff" is a lone byte that is not valid UTF-8.
        Engine::new(EngineOption::withRecognizers(self::recognizerWithName("\xff")));
    }

    public function testRecognizerReservedKeywordNameRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // "keyword" is reserved for the future keyword AC pipeline.
        Engine::new(EngineOption::withRecognizers(self::recognizerWithName('keyword')));
    }

    public function testWithEntityTypeEmptyNameRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withEntityType(''));
    }

    public function testWithEntityTypeLowercaseFormatRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // Entity names must match ^[A-Z][A-Z0-9]*$; lowercase is rejected.
        Engine::new(EngineOption::withEntityType('orderId'));
    }

    public function testWithEntityTypeDigitFirstRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // The first character must be [A-Z]; a leading digit is rejected.
        Engine::new(EngineOption::withEntityType('1FOO'));
    }

    public function testWithEntityTypeExceeding64BytesRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        // 65 bytes of ASCII exceeds the 64-byte entity-name cap.
        Engine::new(EngineOption::withEntityType(\str_repeat('A', 65)));
    }

    public function testWithEntityTypeInvalidUtf8Rejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withEntityType("\xff"));
    }

    public function testWithMaxEntitiesNonPositiveRejected(): void
    {
        $this->expectException(InvalidConfigException::class);
        Engine::new(EngineOption::withMaxEntities(0));
    }

    /**
     * Build a minimal Recognizer whose name() returns the given bytes, so the
     * recognizer-name validation paths in Engine::new() can be exercised
     * independently of any concrete built-in recognizer.
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
