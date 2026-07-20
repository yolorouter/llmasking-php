<?php

// src/Internal/SegmentEmitter.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\RestoreEvent;

/**
 * Chunks a byte stream into at-most-SIZE (16 KiB) segments, attaching each
 * RestoreEvent to the segment that contains the LAST output byte of its
 * restored text (spec §9.6: an event spanning multiple segments attaches only
 * to the segment holding its final byte, so a downstream stream never commits
 * an event before its plaintext is fully delivered).
 *
 * The emitter never holds the whole output: it keeps only the un-cut tail
 * ($buf, < SIZE plus the current push) and not-yet-placed events. Callers push
 * output in delivery order (optionally with the events whose last byte lands
 * at the end of the pushed bytes), drain completed segments frequently, and
 * finish() to flush the tail.
 *
 * @internal
 */
final class SegmentEmitter
{
    /** Maximum bytes per produced segment (spec §9.5/§9.6: at most 16 KiB). */
    public const SIZE = 16 * 1024;

    private string $buf = '';

    /** Absolute byte offset of $buf[0] in the overall output. */
    private int $bufStartAbs = 0;

    /** Total bytes pushed so far. */
    private int $written = 0;

    /** @var list<array{0:int, 1:RestoreEvent}> (last-byte absolute offset, event) */
    private array $pending = [];

    /** @var list<array{0:string, 1:list<RestoreEvent>}> completed segments ready to drain */
    private array $ready = [];

    /**
     * Append $bytes to the output stream. Each event in $events has its last
     * output byte at the end of $bytes (absolute offset written-1 after the
     * append), so it will be placed in whichever segment contains that offset.
     *
     * @param list<RestoreEvent> $events
     */
    public function push(string $bytes, array $events = []): void
    {
        if ($bytes !== '') {
            $this->buf .= $bytes;
            $this->written += \strlen($bytes);
        }
        $lastOff = $this->written > 0 ? $this->written - 1 : 0;
        foreach ($events as $e) {
            $this->pending[] = [$lastOff, $e];
        }
        while (\strlen($this->buf) >= self::SIZE) {
            $this->cut();
        }
    }

    /**
     * Escape $decoded as JSON string content (JSON_UNESCAPED_UNICODE |
     * JSON_UNESCAPED_SLASHES, matching {@see \Yolorouter\Llmasking\Internal\JsonPatch::encodeStringContent()})
     * and push it WITHOUT ever materializing the whole escaped string (spec §9.5
     * incremental escaper). Yields each completed segment as soon as it forms,
     * so the caller drains incrementally and $ready never accumulates the whole
     * output (codex high #1). $events attach to the segment containing the
     * final escaped byte; the loop never flushes on the last input byte so the
     * final chunk always carries the events (codex med #1).
     *
     * @param list<RestoreEvent> $events
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    public function pushEscapedGen(string $decoded, array $events = []): \Generator
    {
        $n = \strlen($decoded);
        $buf = '';
        for ($i = 0; $i < $n; $i++) {
            $b = \ord($decoded[$i]);
            if ($b === 0x22) {
                $buf .= '\\"';
            } elseif ($b === 0x5C) {
                $buf .= '\\\\';
            } elseif ($b === 0x08) {
                $buf .= '\\b';
            } elseif ($b === 0x09) {
                $buf .= '\\t';
            } elseif ($b === 0x0A) {
                $buf .= '\\n';
            } elseif ($b === 0x0C) {
                $buf .= '\\f';
            } elseif ($b === 0x0D) {
                $buf .= '\\r';
            } elseif ($b < 0x20) {
                $buf .= \sprintf('\\u%04x', $b);
            } else {
                $buf .= $decoded[$i];
            }
            if ($i < $n - 1 && \strlen($buf) >= self::SIZE) {
                $this->push($buf);
                $buf = '';
                yield from $this->drainReady();
            }
        }
        if ($buf !== '') {
            $this->push($buf, $events);
        } elseif ($events !== []) {
            $this->push('', $events);
        }
        yield from $this->drainReady();
    }

    private function cut(): void
    {
        $end = $this->bufStartAbs + self::SIZE;
        $chunk = \substr($this->buf, 0, self::SIZE);
        $this->buf = \substr($this->buf, self::SIZE);
        $placed = [];
        $rest = [];
        foreach ($this->pending as $entry) {
            [$off, $e] = $entry;
            if ($off < $end) {
                $placed[] = $e;
            } else {
                $rest[] = $entry;
            }
        }
        $this->pending = $rest;
        $this->ready[] = [$chunk, $placed];
        $this->bufStartAbs = $end;
    }

    /**
     * Push arbitrary bytes in SIZE slices, yielding each completed segment as it
     * forms, so a large literal or passthrough blob never accumulates the whole
     * output in $ready and avoids O(n^2) re-copying (codex high #3). $events
     * (when given) attach atomically to the segment containing the FINAL byte:
     * the last slice is pushed in the same push() call as the events, so a cut
     * at an exact 16 KiB boundary cannot strand them on a later segment (codex
     * med).
     *
     * @param list<RestoreEvent> $events
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    public function pushBytesGen(string $bytes, array $events = []): \Generator
    {
        $n = \strlen($bytes);
        if ($n === 0) {
            if ($events !== []) {
                $this->push('', $events);
                yield from $this->drainReady();
            }

            return;
        }
        $off = 0;
        while ($off < $n) {
            $chunk = \substr($bytes, $off, self::SIZE);
            $off += \strlen($chunk);
            // Attach events on the final slice's push so they are placed by the
            // same cut that contains the last byte, never a later segment.
            if ($off >= $n && $events !== []) {
                $this->push($chunk, $events);
            } else {
                $this->push($chunk);
            }
            yield from $this->drainReady();
        }
    }

    /**
     * Take and clear the completed segments. Call after each push so the
     * ready list never accumulates the whole output.
     *
     * @return list<array{0:string, 1:list<RestoreEvent>}>
     */
    public function drainReady(): array
    {
        $r = $this->ready;
        $this->ready = [];

        return $r;
    }

    /**
     * Flush the remaining tail as a final segment (all still-pending events
     * attach to it). Returns [] when there is no tail and no pending event.
     *
     * @return list<array{0:string, 1:list<RestoreEvent>}>
     */
    public function finish(): array
    {
        if ($this->buf === '' && $this->pending === []) {
            return [];
        }
        $events = [];
        foreach ($this->pending as [$off, $e]) {
            $events[] = $e;
        }
        $seg = [$this->buf, $events];
        $this->buf = '';
        $this->pending = [];

        return [$seg];
    }
}
