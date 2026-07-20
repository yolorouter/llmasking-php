<?php

// src/Transport/RestoringStream.php

namespace Yolorouter\Llmasking\Transport;

use Psr\Http\Message\StreamInterface;
use Yolorouter\Llmasking\RestoreEvent;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;

/**
 * PSR-7 StreamInterface (spec §9.6): a read-only, forward-only transform stream.
 *
 * Two modes:
 *
 *  - SSE mode ({@see forSse()}): wraps an upstream PSR-7 stream and an
 *    {@see SseRestorer}. read()/getContents() pull bytes from the upstream,
 *    feed them through the SseRestorer, and return the restored SSE wire bytes.
 *    Forward-only and not seekable. A single read()/getContents() call never
 *    materialises more than $materializeBudget bytes; larger responses must be
 *    drained by repeated read() calls. Restore failures surface as
 *    StreamRestoreException and put the stream in a terminal failed state.
 *
 *  - Failed-body mode ({@see forFailedBody()}): no upstream. read()/
 *    getContents() always raise StreamRestoreException; __toString() returns
 *    the empty string. Used when a JSON response restore cannot be performed
 *    (syntax error, budget breach, or callback failure) per spec §9.4.
 *
 * RestoreEvent completion semantics (spec §9.7): in SSE mode the optional
 * $completion callable is invoked exactly once when the stream reaches a
 * terminal state (clean EOF, caller early close, or failure), receiving the
 * list of RestoreEvents whose carrying output bytes were already fully
 * delivered to the caller, a $complete flag, and the failure cause (if any).
 * The caller-owned MaskingClient wraps this to inject its request snapshot.
 */
final class RestoringStream implements StreamInterface
{
    private const MODE_SSE = 0;
    private const MODE_FAILED = 1;

    private const CHUNK = 8192;

    /** Single-call aggregation cap; guaranteed >= 1 by the constructors. */
    private readonly int $materializeBudget;

    /**
     * Completion callback (SSE mode only). Invoked at most once.
     *
     * @var (callable(list<RestoreEvent>, bool, ?\Throwable): void)|null
     */
    private readonly mixed $completion;

    /** @var list<RestoreEvent> events whose carrying bytes were fully delivered */
    private array $committedEvents = [];

    /**
     * Pending segments: output the restorer produced but whose bytes have not
     * all been delivered yet. Each entry carries the events that may commit only
     * once the whole segment has been handed to the caller.
     *
     * @var list<array{remaining:int, events:list<RestoreEvent>}>
     */
    private array $segments = [];

    /** Restored bytes not yet handed to the caller. */
    private string $buffer = '';

    /** Total restored bytes delivered to the caller (returned by tell()). */
    private int $delivered = 0;

    private bool $closed = false;

    private bool $upstreamEof = false;

    private bool $restorerFlushed = false;

    /**
     * Set by pullMore() when the upstream read returned '' without reaching eof
     * (a non-blocking source). The read()/getContents() loops inspect this to
     * avoid a busy loop; consuming bytes that the restorer buffers internally
     * while awaiting an event delimiter is NOT a stall.
     */
    private bool $upstreamStalled = false;

    /** Saved terminal failure; rethrown by every subsequent read/getContents. */
    private ?\Throwable $failure = null;

    private bool $completionFired = false;

    /**
     * @param int $materializeBudget Single-call aggregation cap (clamped to >= 1).
     */
    private function __construct(
        private readonly int $mode,
        private ?SseRestorer $restorer,
        private ?StreamInterface $upstream,
        int $materializeBudget,
        ?callable $completion,
        ?\Throwable $presetFailure,
    ) {
        $this->materializeBudget = max(1, $materializeBudget);
        $this->completion = $completion;
        $this->failure = $presetFailure;
    }

    /**
     * SSE mode: drive $restorer from $upstream.
     *
     * @param int $materializeBudget Single-call aggregation cap (>= 1).
     * @param (callable(list<RestoreEvent>, bool, ?\Throwable): void)|null $completion
     */
    public static function forSse(
        SseRestorer $restorer,
        StreamInterface $upstream,
        int $materializeBudget,
        ?callable $completion = null,
    ): self {
        return new self(self::MODE_SSE, $restorer, $upstream, $materializeBudget, $completion, null);
    }

    /**
     * Failed-body mode: read/getContents always raise $e; __toString() returns
     * the empty string.
     */
    public static function forFailedBody(StreamRestoreException $e): self
    {
        return new self(self::MODE_FAILED, null, null, 1, null, $e);
    }

    // ---- StreamInterface ----------------------------------------------------

    public function __toString(): string
    {
        // PSR-7 forbids throwing. Any problem yields the empty string.
        try {
            if ($this->mode === self::MODE_FAILED) {
                return '';
            }
            // Cannot rewind a forward stream that has already delivered bytes or
            // that holds a buffered suffix; a mid-stream partial result must not
            // be disguised as a complete body.
            if ($this->closed || $this->failure !== null || $this->delivered > 0 || $this->buffer !== '') {
                return '';
            }
            while (!$this->isFullyDrained() && \strlen($this->buffer) < $this->materializeBudget) {
                $err = $this->pullMore();
                if ($err !== null) {
                    $this->handleFailure($err);

                    return '';
                }
                if ($this->upstreamStalled) {
                    break;
                }
            }
            if (!$this->isFullyDrained() || \strlen($this->buffer) > $this->materializeBudget) {
                // Exceeds the bounded materialisation: terminate the transform
                // and report incomplete. Never materialise a near-total body to
                // satisfy string coercion.
                $this->handleFailure(new StreamRestoreException(
                    '__toString materialisation exceeds materializeBudget',
                ));

                return '';
            }
            $out = $this->buffer;
            $n = \strlen($out);
            $this->buffer = '';
            $this->delivered += $n;
            $this->deliverBytes($n);
            $this->fireCompletion(true, null);

            return $out;
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->mode === self::MODE_SSE && !$this->completionFired) {
            $clean = $this->failure === null
                && $this->isFullyDrained()
                && $this->buffer === '';
            $this->fireCompletion($clean, $this->failure);
        }
        $this->releaseUpstream();
        $this->closed = true;
        $this->buffer = '';
        $this->segments = [];
    }

    /**
     * @return resource|null Always null: never expose the upstream resource.
     */
    public function detach()
    {
        if (!$this->closed) {
            if ($this->mode === self::MODE_SSE && !$this->completionFired) {
                $this->fireCompletion(false, $this->failure);
            }
            $this->releaseUpstream();
            $this->closed = true;
            $this->buffer = '';
            $this->segments = [];
        }

        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->delivered;
    }

    public function eof(): bool
    {
        if ($this->closed || $this->failure !== null) {
            return true;
        }

        return $this->isFullyDrained() && $this->buffer === '';
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('RestoringStream is not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('RestoringStream is not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('RestoringStream is not writable');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('length must be non-negative');
        }
        if ($this->closed) {
            throw new \RuntimeException('stream is closed');
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        if ($length === 0) {
            return '';
        }

        $cap = \min($length, $this->materializeBudget);
        while (\strlen($this->buffer) < $cap && !$this->isFullyDrained()) {
            $err = $this->pullMore();
            if ($err !== null) {
                $this->handleFailure($err);
                throw $err;
            }
            // pullMore sets $upstreamStalled only when the upstream returned ''
            // without eof (a non-blocking source): stop pulling in that case so
            // the caller can retry. Consuming bytes that the restorer buffers
            // internally while waiting for an event delimiter is normal progress.
            if ($this->upstreamStalled) {
                break;
            }
        }

        $take = \min($cap, \strlen($this->buffer));
        if ($take <= 0) {
            // Nothing to deliver right now. Only lock complete=true when the
            // stream is genuinely finished (upstream EOF + restorer flushed +
            // buffer empty). A non-blocking upstream that returned '' without
            // eof (upstreamStalled) may still produce more data: reporting
            // completion here would permanently drop later restore events
            // (codex r3).
            if ($this->isFullyDrained() && $this->buffer === '') {
                $this->fireCompletion(true, null);
            }

            return '';
        }
        $out = \substr($this->buffer, 0, $take);
        $this->buffer = \substr($this->buffer, $take);
        $this->delivered += $take;
        $this->deliverBytes($take);
        if ($this->isFullyDrained() && $this->buffer === '') {
            $this->fireCompletion(true, null);
        }

        return $out;
    }

    public function getContents(): string
    {
        if ($this->closed) {
            throw new \RuntimeException('stream is closed');
        }
        if ($this->failure !== null) {
            throw $this->failure;
        }
        while (!$this->isFullyDrained() && \strlen($this->buffer) < $this->materializeBudget) {
            $err = $this->pullMore();
            if ($err !== null) {
                $this->handleFailure($err);
                throw $err;
            }
            if ($this->upstreamStalled) {
                break;
            }
        }
        if (!$this->isFullyDrained() || \strlen($this->buffer) > $this->materializeBudget) {
            // Aggregation buffer would exceed materializeBudget. This is a
            // terminal failure: discard this call's undelivered output/events.
            $budgetError = new StreamRestoreException(
                'getContents aggregation exceeds materializeBudget',
            );
            $this->handleFailure($budgetError);
            throw $budgetError;
        }
        $out = $this->buffer;
        $n = \strlen($out);
        $this->buffer = '';
        $this->delivered += $n;
        $this->deliverBytes($n);
        $this->fireCompletion(true, null);

        return $out;
    }

    /**
     * @return array<string, mixed>|mixed|null
     */
    public function getMetadata(?string $key = null)
    {
        $meta = [
            'mode' => $this->mode === self::MODE_FAILED ? 'failed' : 'sse',
            'readable' => $this->isReadable(),
            'writable' => false,
            'seekable' => false,
            'eof' => $this->eof(),
            'tell' => $this->delivered,
        ];
        if ($key !== null) {
            return $meta[$key] ?? null;
        }

        return $meta;
    }

    public function __destruct()
    {
        // Best-effort release of the upstream resource. The destructor must not
        // be relied on for restore-report completeness (spec §9.6).
        try {
            if (!$this->closed) {
                $this->releaseUpstream();
                $this->closed = true;
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    // ---- Internals ----------------------------------------------------------

    private function isFullyDrained(): bool
    {
        return $this->upstreamEof && $this->restorerFlushed;
    }

    /**
     * Advance the transform by one step, appending at most one 16 KiB segment to
     * the buffer. Returns null on success/no-progress, or a Throwable on restore
     * failure (caller discards undelivered output and goes terminal). Pulling a
     * single segment per call (rather than write()/flush() the whole chunk)
     * keeps the retained buffer bounded to roughly one segment (spec §9.6).
     */
    private function pullMore(): ?\Throwable
    {
        if ($this->restorerFlushed || $this->restorer === null || $this->upstream === null) {
            return null;
        }

        // Pull one already-produced segment first (covers segments left pending
        // by a previous feed, and the lazy generator's incremental output).
        try {
            $seg = $this->restorer->pull();
        } catch (\Throwable $e) {
            return $e;
        }
        if ($seg !== null) {
            $this->appendResult($seg[0], $seg[1]);

            return null;
        }

        // No segment ready: feed more upstream, or finalize at EOF.
        if (!$this->upstreamEof) {
            $remaining = $this->materializeBudget - \strlen($this->buffer);
            $want = \min(self::CHUNK, max(1, $remaining));
            try {
                $chunk = $this->upstream->read($want);
            } catch (\Throwable $e) {
                return new StreamRestoreException('upstream read failed: ' . $e->getMessage(), 0, $e);
            }
            if ($chunk === '') {
                if ($this->upstream->eof()) {
                    $this->upstreamEof = true;
                    try {
                        $this->restorer->finishEof();
                    } catch (\Throwable $e) {
                        return $e;
                    }
                } else {
                    $this->upstreamStalled = true;

                    return null; // no progress yet
                }
            } else {
                $this->upstreamStalled = false;
                try {
                    $this->restorer->feed($chunk);
                } catch (\Throwable $e) {
                    return $e;
                }
            }

            return null;
        }

        // Upstream at EOF and the restorer produced nothing more: done.
        if ($this->restorer->isExhausted()) {
            $this->restorerFlushed = true;
        }

        return null;
    }

    /**
     * @param list<RestoreEvent> $events
     */
    private function appendResult(string $bytes, array $events): void
    {
        if ($bytes === '' && $events === []) {
            return;
        }
        $this->buffer .= $bytes;
        $this->segments[] = ['remaining' => \strlen($bytes), 'events' => $events];
    }

    /**
     * Record delivery of $n bytes from the front of the buffer; commit the
     * events of any segment whose bytes have now been fully delivered.
     */
    private function deliverBytes(int $n): void
    {
        while ($n > 0 && $this->segments !== []) {
            $seg = &$this->segments[0];
            if ($n < $seg['remaining']) {
                $seg['remaining'] -= $n;
                $n = 0;
            } else {
                $n -= $seg['remaining'];
                foreach ($seg['events'] as $ev) {
                    $this->committedEvents[] = $ev;
                }
                \array_shift($this->segments);
            }
            unset($seg);
        }
    }

    /**
     * Enter the terminal failed state: close the upstream, discard all
     * undelivered output and uncommitted events, save $e as the sticky failure,
     * and fire the completion callback with complete=false.
     */
    private function handleFailure(\Throwable $e): void
    {
        if ($this->failure !== null) {
            return;
        }
        $this->failure = $e;
        $this->buffer = '';
        $this->segments = [];
        $this->releaseUpstream();
        $this->fireCompletion(false, $e);
    }

    private function releaseUpstream(): void
    {
        if ($this->upstream !== null) {
            try {
                $this->upstream->close();
            } catch (\Throwable) {
                // best effort
            }
            $this->upstream = null;
        }
        $this->restorer = null;
    }

    private function fireCompletion(bool $complete, ?\Throwable $error): void
    {
        if ($this->completionFired || $this->completion === null || $this->mode !== self::MODE_SSE) {
            return;
        }
        $this->completionFired = true;
        try {
            ($this->completion)($this->committedEvents, $complete, $error);
        } catch (\Throwable) {
            // A failing callback must not escape the stream interface (spec
            // §9.7). It is observed as StreamRestoreException on the next read;
            // the stream is already terminal here, so swallow defensively.
        }
    }
}
