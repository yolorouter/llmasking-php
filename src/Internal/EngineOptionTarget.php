<?php

// src/Internal/EngineOptionTarget.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{Recognizer, Region, Strategy};
use Yolorouter\Llmasking\Exception\InvalidConfigException;

/**
 * Mutable accumulator that EngineOption::applyTo() writes to while Engine::new()
 * replays the option list in order. Each setter performs the per-option
 * validation that depends on the current accumulator state (for example
 * WithStrategy rejects an entity type that is neither built-in nor registered
 * by an earlier WithEntityType). Engine::new() runs the remaining cross-option
 * validation (recognizer-name uniqueness, region filtering, derived limits)
 * after every option has been applied.
 *
 * @internal subject to change; only Engine and EngineOption depend on it.
 */
final class EngineOptionTarget
{
    private const ENTITY_NAME_MAX_BYTES = 64;

    /** @var list<Recognizer>|null null = use the built-in default recognizer set */
    public ?array $recognizers = null;

    /** @var list<Region>|null null = every Region (Universal + all geo) enabled */
    public ?array $regions = null;

    /**
     * @var list<string>|null null = WithKeywords not seen; non-null (including
     *                            the empty list from a zero-arg call) = seen
     */
    public ?array $keywords = null;

    /** @var array<string, Strategy> entity name => override strategy */
    public array $strategies = [];

    /** @var array<string, bool> built-in plus registered custom entity names */
    public array $knownEntities;

    public int $maxEntities = 10000;

    public int $maxSessionBytes = 10 * 1024 * 1024;

    public int $maxInputBytes = 1024 * 1024;

    public int $maxOutputBytes = 16 * 1024 * 1024;

    public function __construct()
    {
        // Seed the known-entity set with every built-in EntityType constant so
        // WithStrategy on a built-in type succeeds without WithEntityType.
        // Reflection derives the set from EntityType itself, so adding a
        // built-in entity no longer requires editing a parallel literal here.
        /** @var array<string, bool> $entities */
        $entities = \array_fill_keys(
            (new \ReflectionClass(\Yolorouter\Llmasking\EntityType::class))->getConstants(),
            true,
        );
        $this->knownEntities = $entities;
    }

    /**
     * Replace the recognizer list. Passing an empty list disables the built-in
     * defaults entirely. Recognizer-name validation runs later in Engine::new()
     * once the final list (after region filtering) is known.
     *
     * @param list<Recognizer> $recognizers
     */
    public function setRecognizers(array $recognizers): void
    {
        $this->recognizers = $recognizers;
    }

    /**
     * @param list<Region> $regions
     */
    public function setRegions(array $regions): void
    {
        // Universal is always active implicitly, so accepting it here would be
        // ambiguous; reject it so callers state only the geo regions they want.
        foreach ($regions as $r) {
            if ($r === Region::Universal) {
                throw new InvalidConfigException(
                    'WithRegions must not include Region::Universal (it is always enabled)',
                );
            }
        }
        $this->regions = $regions;
    }

    /**
     * Record the keyword list. Enforced ONCE-ONLY via $keywords !== null
     * (the zero-argument WithKeywords() call stores [] and is still non-null,
     * so a subsequent call is rejected without a dedicated flag).
     *
     * Per-keyword validation (non-empty / UTF-8 / duplicate / count / total and
     * per-keyword byte caps) is delegated to KeywordMatcher construction in
     * Engine::new(), which forwards the engine's FINAL MaxInputBytes as the
     * per-keyword cap. This avoids both re-validating here with a stale
     * MaxInputBytes (if WithMaxInputBytes comes after WithKeywords) and
     * duplicating KeywordMatcher's validation constants.
     *
     * @param list<string> $keywords raw keyword list from WithKeywords()
     */
    public function setKeywords(array $keywords): void
    {
        if ($this->keywords !== null) {
            throw new InvalidConfigException('WithKeywords may be called at most once');
        }
        $this->keywords = \array_values($keywords);
    }

    /**
     * Register a strategy override for $entity. The entity must already be
     * known (built-in or registered via an earlier WithEntityType) and must
     * not be a SECRET family entity paired with the reversible Placeholder
     * strategy.
     */
    public function setStrategy(string $entity, Strategy $strategy): void
    {
        if (!isset($this->knownEntities[$entity])) {
            throw new InvalidConfigException(
                'WithStrategy references unknown entity type "' . $entity
                . '"; register it with WithEntityType first',
            );
        }
        if (Reversibility::isSecret($entity) && Reversibility::isReversible($strategy)) {
            throw new InvalidConfigException(
                'SECRET family entity "' . $entity
                . '" cannot use the reversible Placeholder strategy',
            );
        }
        $this->strategies[$entity] = $strategy;
    }

    /**
     * Register a custom entity type name. Idempotent: a second call with the
     * same name is a no-op (still succeeds).
     */
    public function addEntityType(string $name): void
    {
        if (!\mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidConfigException('WithEntityType name is not valid UTF-8');
        }
        self::assertEntityNameFormat($name);
        if (\strlen($name) > self::ENTITY_NAME_MAX_BYTES) {
            throw new InvalidConfigException(
                'WithEntityType name exceeds ' . self::ENTITY_NAME_MAX_BYTES . ' bytes: ' . $name,
            );
        }
        if (isset($this->knownEntities[$name])) {
            return; // idempotent registration of a known (built-in or custom) name
        }
        $this->knownEntities[$name] = true;
    }

    public function setMaxEntities(int $n): void
    {
        $this->assertPositive($n, 'MaxEntities');
        $this->maxEntities = $n;
    }

    public function setMaxSessionBytes(int $n): void
    {
        $this->assertPositive($n, 'MaxSessionBytes');
        $this->maxSessionBytes = $n;
    }

    public function setMaxInputBytes(int $n): void
    {
        $this->assertPositive($n, 'MaxInputBytes');
        $this->maxInputBytes = $n;
    }

    public function setMaxOutputBytes(int $n): void
    {
        $this->assertPositive($n, 'MaxOutputBytes');
        $this->maxOutputBytes = $n;
    }

    private static function assertEntityNameFormat(string $name): void
    {
        // Validate ^[A-Z][A-Z0-9]*$ at the byte level. Entity names are ASCII
        // (the UTF-8 check above admits non-ASCII, but the format check below
        // rejects any byte outside [A-Z0-9]); a non-ASCII name therefore fails
        // here, which is the intended behavior for placeholder identifiers.
        $len = \strlen($name);
        if ($len === 0) {
            throw new InvalidConfigException('WithEntityType name must not be empty');
        }
        $first = $name[0];
        if ($first < 'A' || $first > 'Z') {
            throw new InvalidConfigException(
                'WithEntityType name must start with [A-Z]: ' . $name,
            );
        }
        for ($i = 1; $i < $len; $i++) {
            $c = $name[$i];
            $isUpper = $c >= 'A' && $c <= 'Z';
            $isDigit = $c >= '0' && $c <= '9';
            if (!$isUpper && !$isDigit) {
                throw new InvalidConfigException(
                    'WithEntityType name must match ^[A-Z][A-Z0-9]*$: ' . $name,
                );
            }
        }
    }

    private function assertPositive(int $n, string $label): void
    {
        if ($n <= 0) {
            throw new InvalidConfigException($label . ' must be positive');
        }
    }
}
