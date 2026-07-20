<?php

// src/Transport/TransportOptions.php

namespace Yolorouter\Llmasking\Transport;

use Psr\Http\Message\RequestInterface;
use Yolorouter\Llmasking\MaskEvent;
use Yolorouter\Llmasking\RestoreEvent;

/**
 * Sealed, immutable description of one MaskingClient configuration mutation.
 *
 * Mirrors the EngineOption pattern: the constructor is private so no third
 * party can invent a new kind of mutation, and every instance is produced by a
 * static with* factory. The MaskingClient constructor replays the options in
 * order: WithPassthrough is idempotent, while a later WithMaskReport /
 * WithRestoreReport overwrites an earlier one (spec §9.7).
 *
 * The callables are stored verbatim and never invoked during construction;
 * only MaskingClient consumes them via the internal accessors below.
 */
final class TransportOptions
{
    private const KIND_PASSTHROUGH = 'passthrough';
    private const KIND_MASK_REPORT = 'mask_report';
    private const KIND_RESTORE_REPORT = 'restore_report';

    /**
     * @param string $kind one of the KIND_* constants
     * @param callable|null $payload factory argument (the report callable), or null
     */
    private function __construct(
        private readonly string $kind,
        private readonly mixed $payload = null,
    ) {
    }

    /**
     * Allow the supported processing path to forward the original request
     * untouched when the body is not seekable, gzip decoding fails, or the
     * JSON syntax cannot be parsed (spec §9.2). Idempotent — passing it
     * multiple times has the same effect as passing it once.
     *
     * This option never swallows recognizer, strategy, resource-limit, JSON
     * patch or callback errors; those still fail the request (spec §9.2).
     */
    public static function withPassthrough(): self
    {
        return new self(self::KIND_PASSTHROUGH);
    }

    /**
     * Register a mask-report callback fired after the request body has been
     * rewritten and before the inner client sends it (spec §9.7).
     *
     * The callable receives the masked Request snapshot (independent body
     * stream) and the list of MaskEvents that were committed to the outgoing
     * body. It is invoked at most once per request and only when at least one
     * event was produced. A callback that throws is wrapped as
     * RequestReportException and the request is not sent.
     *
     * @param callable(RequestInterface, list<MaskEvent>): void $callback
     */
    public static function withMaskReport(callable $callback): self
    {
        return new self(self::KIND_MASK_REPORT, $callback);
    }

    /**
     * Register a restore-report callback fired at most once per response
     * (spec §9.7).
     *
     * For JSON responses the callable receives the masked Request snapshot,
     * the list of RestoreEvents, a completeness flag, and the error (null on
     * success). On success with zero events the callback is not invoked.
     *
     * @param callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $callback
     */
    public static function withRestoreReport(callable $callback): self
    {
        return new self(self::KIND_RESTORE_REPORT, $callback);
    }

    /**
     * Whether this option enables passthrough mode.
     *
     * @internal Consumed only by MaskingClient.
     */
    public function isPassthrough(): bool
    {
        return $this->kind === self::KIND_PASSTHROUGH;
    }

    /**
     * Return the mask-report callable when this option is a WithMaskReport,
     * otherwise null.
     *
     * @internal Consumed only by MaskingClient.
     *
     * @return callable(RequestInterface, list<MaskEvent>): void|null
     */
    public function maskCallback(): ?callable
    {
        if ($this->kind !== self::KIND_MASK_REPORT) {
            return null;
        }
        /** @var callable(RequestInterface, list<MaskEvent>): void $payload */
        $payload = $this->payload;
        \assert(\is_callable($payload));
        return $payload;
    }

    /**
     * Return the restore-report callable when this option is a
     * WithRestoreReport, otherwise null.
     *
     * @internal Consumed only by MaskingClient.
     *
     * @return callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void|null
     */
    public function restoreCallback(): ?callable
    {
        if ($this->kind !== self::KIND_RESTORE_REPORT) {
            return null;
        }
        /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $payload */
        $payload = $this->payload;
        \assert(\is_callable($payload));
        return $payload;
    }
}
