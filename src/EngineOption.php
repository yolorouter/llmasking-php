<?php

// src/EngineOption.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Internal\EngineOptionTarget;

/**
 * Sealed, immutable description of one Engine configuration mutation.
 *
 * Every instance is produced by a static with* factory; the constructor is
 * private so no third party can invent a new kind of mutation. Each instance
 * carries a kind tag plus the arguments to replay, and applyTo() replays that
 * single mutation onto an EngineOptionTarget in the order Engine::new()
 * received the options. All cross-option validation lives in Engine::new();
 * EngineOption itself only ferries the arguments.
 */
final class EngineOption
{
    private const KIND_RECOGNIZERS = 'recognizers';
    private const KIND_REGIONS = 'regions';
    private const KIND_STRATEGY = 'strategy';
    private const KIND_ENTITY_TYPE = 'entity_type';
    private const KIND_MAX_ENTITIES = 'max_entities';
    private const KIND_MAX_SESSION_BYTES = 'max_session_bytes';
    private const KIND_MAX_INPUT_BYTES = 'max_input_bytes';
    private const KIND_MAX_OUTPUT_BYTES = 'max_output_bytes';

    /**
     * @param string $kind one of the KIND_* constants
     * @param mixed $payload factory arguments, structured per kind
     */
    private function __construct(
        private readonly string $kind,
        private readonly mixed $payload,
    ) {
    }

    /**
     * Replace the recognizer list. Zero arguments disables the built-in
     * defaults, yielding an engine that emits input unchanged.
     */
    public static function withRecognizers(Recognizer ...$recognizers): self
    {
        return new self(self::KIND_RECOGNIZERS, \array_values($recognizers));
    }

    /**
     * Restrict geo-tagged recognizers to the listed regions. Universal is
     * always enabled and must not appear here.
     */
    public static function withRegions(Region ...$regions): self
    {
        return new self(self::KIND_REGIONS, \array_values($regions));
    }

    /**
     * Override the strategy applied to a known entity type. SECRET family
     * entities reject Placeholder.
     */
    public static function withStrategy(string $entity, Strategy $strategy): self
    {
        return new self(self::KIND_STRATEGY, [$entity, $strategy]);
    }

    /** Register a custom entity type name. Idempotent. */
    public static function withEntityType(string $name): self
    {
        return new self(self::KIND_ENTITY_TYPE, $name);
    }

    public static function withMaxEntities(int $n): self
    {
        return new self(self::KIND_MAX_ENTITIES, $n);
    }

    public static function withMaxSessionBytes(int $n): self
    {
        return new self(self::KIND_MAX_SESSION_BYTES, $n);
    }

    public static function withMaxInputBytes(int $n): self
    {
        return new self(self::KIND_MAX_INPUT_BYTES, $n);
    }

    public static function withMaxOutputBytes(int $n): self
    {
        return new self(self::KIND_MAX_OUTPUT_BYTES, $n);
    }

    /**
     * Replay this single mutation onto the accumulator. Per-option validation
     * that depends on the current accumulator state runs inside the matching
     * EngineOptionTarget setter.
     */
    public function applyTo(EngineOptionTarget $target): void
    {
        switch ($this->kind) {
            case self::KIND_RECOGNIZERS:
                /** @var list<Recognizer> $payload */
                $payload = $this->payload;
                $target->setRecognizers($payload);
                break;
            case self::KIND_REGIONS:
                /** @var list<Region> $payload */
                $payload = $this->payload;
                $target->setRegions($payload);
                break;
            case self::KIND_STRATEGY:
                /** @var array{0:string, 1:Strategy} $payload */
                $payload = $this->payload;
                $target->setStrategy($payload[0], $payload[1]);
                break;
            case self::KIND_ENTITY_TYPE:
                /** @var string $payload */
                $payload = $this->payload;
                $target->addEntityType($payload);
                break;
            case self::KIND_MAX_ENTITIES:
                /** @var int $payload */
                $payload = $this->payload;
                $target->setMaxEntities($payload);
                break;
            case self::KIND_MAX_SESSION_BYTES:
                /** @var int $payload */
                $payload = $this->payload;
                $target->setMaxSessionBytes($payload);
                break;
            case self::KIND_MAX_INPUT_BYTES:
                /** @var int $payload */
                $payload = $this->payload;
                $target->setMaxInputBytes($payload);
                break;
            case self::KIND_MAX_OUTPUT_BYTES:
                /** @var int $payload */
                $payload = $this->payload;
                $target->setMaxOutputBytes($payload);
                break;
            default:
                throw new \LogicException('Unknown EngineOption kind: ' . $this->kind);
        }
    }
}
