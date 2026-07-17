<?php

// src/Engine.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Exception\InvalidConfigException;
use Yolorouter\Llmasking\Internal\{EngineOptionTarget, RecognizerDriver, Reversibility};

/**
 * Immutable, stateless, shareable masking engine. Constructed once from a list
 * of EngineOption mutations via Engine::new(); each call to newSession() yields
 * an independent Session that owns per-call reversible mapping state.
 */
final class Engine
{
    private const RECOGNIZER_NAME_MAX_BYTES = 128;

    private const RESERVED_NAME_KEYWORD = 'keyword';

    /** Fixed (spec 5.1): max RestoreEvents produced by one restore() call. */
    public const MAX_RESTORE_EVENTS = 65536;

    /** Fixed (spec 5.1): max total entity+placeholder bytes in restore events. */
    public const MAX_RESTORE_REPORT_BYTES = 16 << 20;

    /**
     * @param list<Recognizer> $recognizers    region-filtered, registration-ordered
     * @param array<string, Strategy> $strategies entity name => override strategy
     * @param array<string, bool> $knownEntities built-in plus custom entity names
     */
    private function __construct(
        public readonly array $recognizers,
        public readonly array $strategies,
        public readonly array $knownEntities,
        public readonly int $maxEntities,
        public readonly int $maxSessionBytes,
        public readonly int $maxInputBytes,
        public readonly int $maxOutputBytes,
        public readonly int $maxSeqDigits,
    ) {
    }

    /**
     * Build an engine by replaying the given options in order onto a mutable
     * accumulator, validating as we go. Throws InvalidConfigException on any
     * bad input (unknown entity in WithStrategy, SECRET entity paired with
     * Placeholder, malformed custom entity name, non-positive limit, Universal
     * passed to WithRegions, duplicate or reserved recognizer name).
     */
    public static function new(EngineOption ...$options): self
    {
        $target = new EngineOptionTarget();
        foreach ($options as $option) {
            $option->applyTo($target);
        }

        $recognizers = self::resolveRecognizers($target);
        self::validateRecognizerNames($recognizers);

        // Sequence numbers range 1..MaxEntities, so their decimal width is the
        // width of MaxEntities itself; the placeholder lexer uses this to
        // reject over-wide zero-padded sequence numbers at restore time.
        $maxSeqDigits = \strlen((string) $target->maxEntities);

        return new self(
            $recognizers,
            $target->strategies,
            $target->knownEntities,
            $target->maxEntities,
            $target->maxSessionBytes,
            $target->maxInputBytes,
            $target->maxOutputBytes,
            $maxSeqDigits,
        );
    }

    public function newSession(): Session
    {
        return new Session($this);
    }

    /**
     * Reject reconstruction via unserialize(): PHP does not call the (private)
     * constructor for it, so a deserialized Engine would bypass Engine::new()
     * validation (e.g. a SECRET entity paired with the reversible Placeholder).
     * Use Engine::new() to build instances.
     */
    public function __wakeup(): void
    {
        throw new InvalidConfigException('Engine cannot be unserialized; construct it via Engine::new()');
    }

    public function strategyFor(string $entity): Strategy
    {
        if (isset($this->strategies[$entity])) {
            return $this->strategies[$entity];
        }
        // SECRET family defaults to non-reversible Redact; everything else
        // defaults to reversible Placeholder.
        return Reversibility::isSecret($entity) ? Strategies::redact() : Strategies::placeholder();
    }

    /**
     * Stateless one-shot masking for logging / data export: no Session state is
     * retained, no reversible mapping is written, sequence numbers start at 1
     * each call, and the result can never be restored. Runs the same recognition
     * + strategy pipeline as anonymize() against a throwaway per-call Session.
     */
    public function mask(string $text): string
    {
        return (new Session($this))->maskText($text);
    }

    /**
     * Resolve the final, region-filtered recognizer list. WithRecognizers
     * (including the zero-argument form) overrides the built-in defaults
     * entirely; either way the SAME region filter applies — a geo-tagged
     * recognizer (default or custom RuleRecognizer/MultiRecognizer) whose
     * Region is not enabled is dropped, matching Go. Custom recognizers with no
     * region tag are always Universal and never filtered.
     *
     * @param EngineOptionTarget $target
     * @return list<Recognizer>
     */
    private static function resolveRecognizers(EngineOptionTarget $target): array
    {
        $list = $target->recognizers ?? self::defaultRecognizers();
        if ($target->regions === null) {
            return $list;
        }

        $enabled = [];
        foreach ($target->regions as $r) {
            $enabled[$r->value] = true;
        }
        $out = [];
        foreach ($list as $recognizer) {
            $region = RecognizerDriver::regionOf($recognizer);
            if ($region === null || $region === Region::Universal) {
                $out[] = $recognizer;
                continue;
            }
            if (isset($enabled[$region->value])) {
                $out[] = $recognizer;
            }
        }
        return $out;
    }

    /**
     * Built-in recognizer set in Go's declaration order: universal patterns
     * first, then the SECRET bundle, then geo-tagged rules (CN before US).
     *
     * @return list<Recognizer>
     */
    private static function defaultRecognizers(): array
    {
        return [
            Recognizers::email(),
            Recognizers::bankCard(),
            Recognizers::ip(),
            Recognizers::url(),
            Recognizers::intlPhone(),
            Recognizers::secret(),
            Recognizers::chinaPhone(),
            Recognizers::chinaIdCard(),
            Recognizers::landline(),
            Recognizers::usSsn(),
            Recognizers::usPhone(),
        ];
    }

    /**
     * Validate recognizer names: non-empty, valid UTF-8, at most 128 bytes,
     * not the reserved "keyword" name, and unique within the list.
     *
     * @param list<Recognizer> $recognizers
     */
    private static function validateRecognizerNames(array $recognizers): void
    {
        $seen = [];
        foreach ($recognizers as $recognizer) {
            $name = $recognizer->name();
            if ($name === '') {
                throw new InvalidConfigException('recognizer name must not be empty');
            }
            if (!\mb_check_encoding($name, 'UTF-8')) {
                throw new InvalidConfigException('recognizer name must be valid UTF-8');
            }
            if (\strlen($name) > self::RECOGNIZER_NAME_MAX_BYTES) {
                throw new InvalidConfigException(
                    'recognizer name exceeds ' . self::RECOGNIZER_NAME_MAX_BYTES . ' bytes: ' . $name,
                );
            }
            if ($name === self::RESERVED_NAME_KEYWORD) {
                // Reserved for the future keyword AC pipeline (Plan 2); reject
                // it now so a custom recognizer cannot collide with it later.
                throw new InvalidConfigException(
                    'recognizer name "' . self::RESERVED_NAME_KEYWORD . '" is reserved',
                );
            }
            if (isset($seen[$name])) {
                throw new InvalidConfigException('duplicate recognizer name: ' . $name);
            }
            $seen[$name] = true;
        }
    }

}
