<?php

// src/Transport/SseRestorer.php

namespace Yolorouter\Llmasking\Transport;

use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Exception\LlmaskingException;
use Yolorouter\Llmasking\Exception\StreamClosedException;
use Yolorouter\Llmasking\Internal\JsonArray;
use Yolorouter\Llmasking\Internal\JsonDocument;
use Yolorouter\Llmasking\Internal\JsonObject;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\JsonPatchEntry;
use Yolorouter\Llmasking\Internal\JsonScalar;
use Yolorouter\Llmasking\Internal\JsonString;
use Yolorouter\Llmasking\Internal\JsonTree;
use Yolorouter\Llmasking\Internal\SegmentEmitter;
use Yolorouter\Llmasking\Internal\JsonSyntaxException;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\Internal\JsonValue;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\RestoreEvent;
use Yolorouter\Llmasking\Session;
use Yolorouter\Llmasking\StreamRestorer;
use Yolorouter\Llmasking\StreamRestoreResult;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;

/**
 * SSE event-stream state machine (spec §9.5). Restores placeholders inside the
 * streaming response by parsing the SSE wire format by EVENT (blank-line
 * separated, never by isolated line), routing each delta target string
 * (content / refusal / tool function.arguments / tool custom.input / legacy
 * function_call.arguments) to a per-route {@see StreamRestorer} keyed by the
 * raw choice/tool routing tokens, and rewriting the event by raw JSON span +
 * re-encoding — never by splicing plaintext into wire bytes.
 *
 * Processing rules (all saturating arithmetic on budgets):
 *  - finish_reason present and non-null on a choice flushes every route of
 *    that choice; the write()+flush() output is synthesized as delta frames
 *    before the (emptied) terminal frame.
 *  - [DONE] and EOF perform an idempotent blanket flush in route-ordinal order.
 *  - Synthesized frames reuse the route's first-saved raw choice/tool index/
 *    id/name tokens; internal ordinal / array position is never written back
 *    as a numeric index.
 *  - Non-target fields and non-data lines (comment / id / event / retry) are
 *    preserved byte-for-byte; LF / CRLF / standalone-CR line endings and the
 *    final newline presence are preserved on forwarding.
 *  - A single leading UTF-8 BOM is preserved but does not participate in first
 *    field-name parsing.
 *  - Any error (UTF-8, JSON syntax, budget, restore) puts the restorer in a
 *    terminal failed state: every subsequent call rethrows the same exception.
 */
final class SseRestorer
{
    private const KIB = 1024;
    private const MIB = 1024 * 1024;
    private const BOM = "\xEF\xBB\xBF";
    private const MAX_SSE_STREAMS = 256;

    private const TARGET_CONTENT = 'content';
    private const TARGET_REFUSAL = 'refusal';
    private const TARGET_ARGUMENTS = 'arguments';
    private const TARGET_INPUT = 'input';
    private const TARGET_LEGACY = 'legacy_arguments';

    private string $buffer = '';

    /** Cursor: bytes at the front of $buffer already extracted as complete events. */
    private int $consumed = 0;

    private string $utf8Partial = '';

    private ?string $leadingBom = null;

    /** Whether the captured leading BOM has already been emitted as a stream prefix. */
    private bool $bomEmitted = false;

    private bool $firstEventSeen = false;

    /** @var array<string, SseRoute> */
    private array $routes = [];

    /** @var list<string> */
    private array $routeOrder = [];

    private int $nextOrdinal = 0;

    private int $streamCount = 0;

    /** @var array<string, array<string,?string>> */
    private array $routeIdentity = [];

    private ?\Throwable $failed = null;

    private bool $eof = false;

    /** Lazy pull state (spec §9.6 / codex #2 Stage C).
     * @var \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>|null
     */
    private ?\Generator $activeGen = null;

    private bool $activeGenStarted = false;

    private bool $eofTailDone = false;

    private bool $eofBlanketDone = false;

    private bool $exhausted = false;

    private readonly int $singleLineBudget;
    private readonly int $singleRawEventBudget;
    private readonly int $singleEventDataBudget;
    private readonly int $singleEmittedEventBudget;
    private readonly int $totalRawSseBudget;
    private readonly int $totalEmittedSseBudget;
    private readonly int $totalSseStateBytesBudget;

    private int $rawSseConsumed = 0;
    private int $emittedSseProduced = 0;
    private int $stateBytes = 0;

    /**
     * Cumulative restored plaintext byte count shared across ALL routes
     * (codex #10): prevents N routes each producing up to MaxOutputBytes.
     * Capped at $session->engine->maxOutputBytes.
     */
    private int $totalRestoredBytes = 0;

    /**
     * Response-level cumulative RestoreEvent count and report bytes shared
     * across ALL routes (codex #4 / spec §9.5: maxRestoreEvents=65536 and
     * maxRestoreReportBytes=16 MiB are response-level, not per-route). Each
     * StreamRestorer still caps itself as a backstop; this counter prevents N
     * routes from together spending N x the budget.
     */
    private int $totalRestoreEvents = 0;

    private int $totalRestoreReportBytes = 0;

    /** @var array<string, int> Per-frame route-key occurrence counter for disambiguation (codex #9). Reset each event. */
    private array $frameRouteOccurrence = [];

    /** @var array<string, int> Per-frame choice-key occurrence counter (codex #6). Reset each event. */
    private array $frameChoiceOccurrence = [];

    /** Line-ending style of the event currently being processed; saved onto new routes (codex #12). */
    private string $currentEventLineEnding = "\n";

    public function __construct(private readonly Session $session, private readonly bool $reportEnabled = true)
    {
        $maxOut = $session->engine->maxOutputBytes;
        $maxIn = $session->engine->maxInputBytes;
        $this->singleLineBudget = max(self::satAdd($maxOut, 4096), 64 * self::KIB);
        $this->singleRawEventBudget = $this->singleLineBudget;
        $this->singleEventDataBudget = $this->singleLineBudget;
        $this->singleEmittedEventBudget = self::satAdd($this->singleEventDataBudget, self::satMul($maxOut, 6));
        $this->totalRawSseBudget = max(self::satMul($maxOut, 4), 4 * self::MIB);
        $this->totalEmittedSseBudget = self::satAdd($this->totalRawSseBudget, self::satMul($maxOut, 6));
        $this->totalSseStateBytesBudget = max($maxIn, 4 * self::MIB);
    }

    /**
     * Synchronous whole-result API (tests / callers that drain the full result
     * at once). Feeds the chunk then drains every available segment. The
     * streaming path ({@see RestoringStream}) uses feed/pull directly so it
     * never materializes a whole event.
     */
    public function write(string $chunk): SseRestoreResult
    {
        $this->feed($chunk);

        return $this->drainAll();
    }

    /**
     * Mark EOF and synchronously drain every remaining segment (tests). The
     * streaming path uses finishEof()/pull() instead.
     */
    public function flush(): SseRestoreResult
    {
        $this->finishEof();

        return $this->drainAll();
    }

    /**
     * Append a chunk to the raw buffer, validate UTF-8 incrementally, and check
     * the cumulative raw-SSE budget. Does NOT process events (processing is
     * lazy, driven by pull()).
     */
    public function feed(string $chunk): void
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        if ($this->eof) {
            throw new StreamClosedException('SSE restorer has reached EOF');
        }

        $newRaw = self::satAdd($this->rawSseConsumed, \strlen($chunk));
        if ($newRaw > $this->totalRawSseBudget) {
            $this->fail(new StreamRestoreException('total raw SSE bytes exceed budget'));
        }
        $this->rawSseConsumed = $newRaw;

        $this->assertChunkUtf8($chunk);

        $this->buffer .= $chunk;
    }

    /**
     * Mark the stream at EOF. A trailing partial UTF-8 codepoint fails closed.
     */
    public function finishEof(): void
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        if ($this->eof) {
            return;
        }
        if ($this->utf8Partial !== '') {
            $this->fail(new StreamRestoreException('SSE stream ended inside a UTF-8 codepoint'));
        }
        $this->eof = true;
    }

    /**
     * Pull the next at-most-16 KiB output segment, or null when no segment is
     * readily available (need more input, or the stream is fully exhausted).
     *
     * @return array{0:string, 1:list<RestoreEvent>}|null
     */
    public function pull(): ?array
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        if ($this->exhausted) {
            return null;
        }

        // Drive the active event generator one segment at a time.
        if ($this->activeGen !== null) {
            if (!$this->activeGenStarted) {
                $this->activeGen->rewind();
                $this->activeGenStarted = true;
            }
            if ($this->activeGen->valid()) {
                $seg = $this->activeGen->current();
                $this->activeGen->next();

                return $seg;
            }
            $this->activeGen = null;
            $this->activeGenStarted = false;
        }

        // Start the next event's generator.
        $eventBytes = $this->extractNextEvent();
        if ($eventBytes !== null) {
            $this->activeGen = $this->processEventGen($eventBytes, false);
            $this->activeGenStarted = false;

            return $this->pull();
        }

        // No complete event available.
        if (!$this->eof) {
            // Codex #11: an unterminated event must not grow unbounded; the
            // unconsumed tail is the incomplete current event.
            if (\strlen($this->buffer) - $this->consumed > $this->singleRawEventBudget) {
                $this->fail(new StreamRestoreException('single raw SSE event exceeds budget'));
            }

            return null;
        }

        // EOF: process the trailing partial event, then blanket flush.
        if (!$this->eofTailDone && $this->consumed < \strlen($this->buffer)) {
            $tail = \substr($this->buffer, $this->consumed);
            $this->buffer = '';
            $this->consumed = 0;
            $this->eofTailDone = true;
            $this->activeGen = $this->processEventGen($tail, true);
            $this->activeGenStarted = false;

            return $this->pull();
        }
        if (!$this->eofBlanketDone) {
            $this->eofBlanketDone = true;
            $this->activeGen = $this->blanketFlushGen();
            $this->activeGenStarted = false;

            return $this->pull();
        }

        $this->exhausted = true;

        return null;
    }

    public function isExhausted(): bool
    {
        return $this->exhausted;
    }

    /**
     * Drain every currently available segment into one whole-result. Used by
     * the synchronous write/flush wrappers; the streaming path pulls instead.
     */
    private function drainAll(): SseRestoreResult
    {
        $segments = [];
        while (null !== ($seg = $this->pull())) {
            $segments[] = $seg;
        }

        return new SseRestoreResult($segments);
    }

    public function isFailed(): bool
    {
        return $this->failed !== null;
    }

    public function failure(): ?\Throwable
    {
        return $this->failed;
    }

    // ---- Buffer drain / event extraction ------------------------------------

    private function extractNextEvent(): ?string
    {
        // Cursor-based extraction (codex #3): scan forward from $consumed and
        // advance the cursor past each complete event WITHOUT truncating the
        // buffer on every event. The previous substr($buf, $afterEnding) per
        // event made a buffer of many short events O(n^2); now each byte is
        // scanned once and the consumed prefix is dropped periodically.
        $n = \strlen($this->buffer);
        $pos = $this->consumed;

        while (true) {
            $found = self::findLineFrom($this->buffer, $pos, $n);
            if ($found === null) {
                return null;
            }
            [$contentEnd, $afterEnding] = $found;
            $isEmpty = $contentEnd === $pos;

            if ($isEmpty) {
                $eventBytes = \substr($this->buffer, $this->consumed, $afterEnding - $this->consumed);
                $this->consumed = $afterEnding;
                $this->maybeCompact();

                return $eventBytes;
            }
            $pos = $afterEnding;
        }
    }

    /**
     * Drop the consumed prefix of $buffer once it is large enough, so a long
     * event stream cannot let the buffer grow without bound. Each byte is
     * copied out by a compact at most once, keeping total work O(n).
     */
    private function maybeCompact(): void
    {
        if ($this->consumed > 65536 && $this->consumed * 2 >= \strlen($this->buffer)) {
            $this->buffer = \substr($this->buffer, $this->consumed);
            $this->consumed = 0;
        }
    }

    /**
     * Locate the next SSE line ending at or after $pos.
     * Returns [$contentEnd, $afterEnding] or null if no complete line ending
     * is present. A trailing CR at the very end of the buffer is left
     * incomplete (it may be the first byte of CRLF) UNLESS $eof is true, in
     * which case a standalone trailing CR counts as a complete line ending
     * (spec §9.5: standalone CR is supported even at EOF — codex #8).
     *
     * @return array{0:int, 1:int}|null
     */
    private static function findLineFrom(string $buf, int $pos, int $n, bool $eof = false): ?array
    {
        for ($i = $pos; $i < $n; $i++) {
            $b = $buf[$i];
            if ($b === "\n") {
                return [$i, $i + 1];
            }
            if ($b === "\r") {
                if ($i + 1 < $n) {
                    return $buf[$i + 1] === "\n" ? [$i, $i + 2] : [$i, $i + 1];
                }

                return $eof ? [$i, $i + 1] : null;
            }
        }

        return null;
    }

    // ---- Per-event processing -----------------------------------------------

    /**
     * Yield raw bytes (e.g. a forwarded/passthrough event) as at-most-16 KiB
     * segments so every output path respects the segment cap (spec §9.6), not
     * just the rewrite path.
     *
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    private function chunked(string $bytes): \Generator
    {
        if ($bytes === '') {
            return;
        }
        $em = new SegmentEmitter();
        yield from $em->pushBytesGen($bytes);
        yield from $em->finish();
    }

    /**
     * Process one complete event, yielding at-most-16 KiB output segments one
     * at a time (spec §9.5/§9.6 / codex #2 Stage C). Each segment is
     * `array{0:string, 1:list<RestoreEvent>}`. RestoreEvents attach (per piece,
     * via the emitter) to the segment containing the last output byte of their
     * restored text. Yields lazily so the caller never materializes the whole
     * escaped event.
     *
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    private function processEventGen(string $eventBytes, bool $eof = false): \Generator
    {
        if (\strlen($eventBytes) > $this->singleRawEventBudget) {
            $this->fail(new StreamRestoreException('single raw SSE event exceeds budget'));
        }

        $lines = $this->parseEventLines($eventBytes, $eof);

        // BOM stream prefix: emit once as the very first output bytes, before
        // any synthesized frame or rebuilt line, so a leading synth frame cannot
        // push it mid-stream and corrupt the SSE wire (codex high #2).
        if (!$this->firstEventSeen && $this->leadingBom !== null && !$this->bomEmitted) {
            $this->bomEmitted = true;
            // Strip the BOM from the forwarded event bytes so passthrough paths
            // (which forward $eventBytes verbatim) do not re-emit it.
            if (\substr($eventBytes, 0, 3) === self::BOM) {
                $eventBytes = \substr($eventBytes, 3);
            }
            yield from $this->chunked($this->leadingBom);
        }

        $dataParts = [];
        $firstDataIdx = null;
        foreach ($lines as $idx => $line) {
            if (\strlen($line['raw']) > $this->singleLineBudget) {
                $this->fail(new StreamRestoreException('single SSE line exceeds budget'));
            }
            if ($line['field'] === 'data') {
                if ($firstDataIdx === null) {
                    $firstDataIdx = $idx;
                }
                $dataParts[] = $line['value'];
            }
        }

        if ($dataParts === []) {
            $this->firstEventSeen = true;

            yield from $this->chunked($eventBytes);

            return;
        }

        $joined = \implode("\n", $dataParts);
        if (\strlen($joined) > $this->singleEventDataBudget) {
            $this->fail(new StreamRestoreException('single event data exceeds budget'));
        }

        if ($joined === '[DONE]') {
            // The [DONE] marker event is forwarded verbatim after the blanket
            // flush frames.
            yield from $this->blanketFlushGen();
            $this->firstEventSeen = true;
            yield from $this->chunked($eventBytes);

            return;
        }

        try {
            $doc = JsonTokenizer::parse($joined);
        } catch (JsonSyntaxException $e) {
            $this->fail(new StreamRestoreException('SSE data JSON parse failed', 0, $e));
        } catch (LimitExceededException $e) {
            $this->fail(new StreamRestoreException('SSE data JSON resource limit', 0, $e));
        }

        $visits = [];
        $finishChoiceKeys = [];
        // Codex #9: per-frame occurrence counter disambiguates same-hint routes
        // within a single event; reset for each processEvent call.
        $this->frameRouteOccurrence = [];
        // Codex #6: per-frame choice-key occurrence counter, reset likewise.
        $this->frameChoiceOccurrence = [];
        // Codex #12: capture the observed line-ending style so newly created
        // routes can reuse it in synthesized flush frames.
        $this->currentEventLineEnding = $this->pickLineEnding($lines, $firstDataIdx);
        $this->walkChoices($doc->json, $doc->root, $visits, $finishChoiceKeys);

        $terminalKeys = [];
        foreach ($finishChoiceKeys as $k) {
            $terminalKeys[$k] = true;
        }

        /** @var list<array{0:JsonPatchEntry, 1:list<array{0:string, 1:list<RestoreEvent>}>, 2:list<RestoreEvent>, 3:bool}> $dataEntries data-line entries: (patch, resolved pieces, unresolved events, no-op flag) */
        $dataEntries = [];
        /** @var list<array{0:SseRoute, 1:string, 2:list<array{0:string, 1:list<RestoreEvent>}>}> $synthEntries synthesized frames as (route, tail, pieces) */
        $synthEntries = [];

        foreach ($visits as $visit) {
            $isTerminal = isset($terminalKeys[$visit['choiceKey']]);
            $route = $visit['route'];
            if ($isTerminal) {
                $writeText = $visit['writeText'];
                $writePieces = $visit['pieces'];
                $tail = '';
                $tailPieces = [];
                if (!$route->flushed) {
                    try {
                        $flushResult = $route->restorer->flush();
                        $tail = $flushResult->text;
                        $this->accrueRestoredOutput($tail);
                        $tailPieces = $this->ssePieces($flushResult);
                    } catch (StreamClosedException $e) {
                        // Already flushed.
                    } catch (LlmaskingException $e) {
                        // A non-StreamClosed flush failure must enter the sticky
                        // failed state; without this it escaped processEvent
                        // without setting $failed.
                        $this->fail($e instanceof StreamRestoreException
                            ? $e
                            : new StreamRestoreException('terminal flush failed: ' . $e->getMessage(), 0, $e));
                    }
                    $route->flushed = true;
                }
                $full = $writeText . $tail;
                // Spec §9.5: write()+flush() output is synthesized as a delta
                // frame BEFORE the emptied terminal frame (write pieces before
                // flush pieces — codex #7). Skip only when nothing to emit.
                if ($full !== '' || $visit['events'] !== []) {
                    $synthEntries[] = [$route, $full, \array_merge($writePieces, $tailPieces)];
                    // Empty-replacement patch drops the original terminal target
                    // content from the data line (events ride the synth frame).
                    $dataEntries[] = [new JsonPatchEntry($visit['span'], ''), [], [], false];
                }
            } else {
                // Stage the entry when the text changed OR when there are
                // events to report. An unresolved placeholder leaves the text
                // unchanged (writeText === decoded); mark it no-op so the
                // stitch keeps the original span bytes verbatim (no
                // re-encoding that could normalize \uXXXX / \/ escapes) while
                // still reporting the restored=false events. A resolved entry
                // carries the per-placeholder pieces.
                $isNoop = $visit['writeText'] === $visit['decoded'];
                if ($visit['events'] !== [] || !$isNoop) {
                    if ($isNoop) {
                        $dataEntries[] = [new JsonPatchEntry($visit['span'], $visit['writeText']), [], $visit['events'], true];
                    } else {
                        $dataEntries[] = [new JsonPatchEntry($visit['span'], $visit['writeText']), $visit['pieces'], [], false];
                    }
                }
            }
        }

        // Terminal choices: flush routes not visited this frame (withheld tail).
        foreach ($this->routeOrder as $routeKey) {
            $route = $this->routes[$routeKey] ?? null;
            if ($route === null || $route->flushed) {
                continue;
            }
            if (!isset($terminalKeys[$route->choiceKey])) {
                continue;
            }
            try {
                $flushResult = $route->restorer->flush();
            } catch (StreamClosedException $e) {
                continue;
            } catch (LlmaskingException $e) {
                // Sticky terminal failure, matching the in-visit flush above and
                // blanketFlush: a restore error here must not escape without
                // setting $failed.
                $this->fail($e instanceof StreamRestoreException
                    ? $e
                    : new StreamRestoreException('terminal flush failed: ' . $e->getMessage(), 0, $e));
            }
            $route->flushed = true;
            $this->accrueRestoredOutput($flushResult->text);
            if ($flushResult->text === '' && $flushResult->events === []) {
                continue;
            }
            $synthEntries[] = [$route, $flushResult->text, $this->ssePieces($flushResult)];
        }

        // Charge each route's withheld buffer against the shared state budget
        // after this frame's writes/flushes (spec §9.5 / codex high).
        $this->checkStateBudget();

        // Multi-data-line events: collapse the SSE "\n" joiners to spaces AFTER
        // parsing, so a JSON string spanning data lines (which would contain a
        // raw LF and be invalid) fails the parse instead of being silently
        // repaired (codex med #2). \n→space is offset-preserving, so spans
        // parsed from the "\n"-joined document stay valid for the forward
        // stitch, and the merged output is one physical data line (a raw LF in
        // it would split the line on the wire and corrupt the downstream parse).
        $joined = \str_replace("\n", ' ', $joined);

        $hasRewrite = $dataEntries !== [];
        $hasSynth = $synthEntries !== [];

        if (!$hasRewrite && !$hasSynth && $finishChoiceKeys === []) {
            $this->firstEventSeen = true;

            yield from $this->chunked($eventBytes);

            return;
        }

        $lineEnding = $this->pickLineEnding($lines, $firstDataIdx);

        // Dry-run (spec §9.5 / codex #2): compute the total emitted byte length
        // WITHOUT building the whole escaped data line, then atomically check
        // the per-event data/emitted budgets and the cumulative emitted budget
        // before producing the first output byte.
        $newJsonLen = \strlen($joined);
        if ($hasRewrite) {
            foreach ($dataEntries as [$entry, $_, $_, $isNoop]) {
                // No-op (unresolved) entries keep the original span bytes: zero delta.
                if (!$isNoop) {
                    $newJsonLen += JsonPatch::encodedStringLength($entry->replacement) - $entry->span->length;
                }
            }
            if ($newJsonLen > $this->singleEventDataBudget) {
                $this->fail(new StreamRestoreException('rewritten event data exceeds budget'));
            }
        }
        $totalEmitted = 0;
        foreach ($synthEntries as [$sRoute, $sTail, $_]) {
            $totalEmitted += $this->synthesizedFrameLength($sRoute, $sTail);
        }
        foreach ($lines as $idx => $line) {
            if ($line['field'] === 'data') {
                if ($idx === $firstDataIdx) {
                    $totalEmitted += 6 + $newJsonLen + \strlen($lineEnding);
                }
                // other data lines are merged into the single rewritten line
            } else {
                $totalEmitted += \strlen($line['raw']);
            }
        }
        if ($totalEmitted > $this->singleEmittedEventBudget) {
            $this->fail(new StreamRestoreException('single emitted SSE event exceeds budget'));
        }
        $newTotalEmitted = self::satAdd($this->emittedSseProduced, $totalEmitted);
        if ($newTotalEmitted > $this->totalEmittedSseBudget) {
            $this->fail(new StreamRestoreException('total emitted SSE bytes exceed budget'));
        }
        $this->emittedSseProduced = $newTotalEmitted;

        $this->firstEventSeen = true;

        // Incremental output (spec §9.5/§9.6 / codex #2): build via
        // SegmentEmitter and yield each at-most-16 KiB segment as soon as it is
        // ready, so the caller never materializes the whole escaped event.
        // Synthesized frames come first, then the rebuilt event (non-data lines
        // raw; the merged rewritten data line at the first data position, its
        // patched JSON stitched forward). Events attach per piece — pushed with
        // the events whose last output byte ends that push — so each event lands
        // in the segment containing its final byte (spec §9.6).
        $em = new SegmentEmitter();
        foreach ($synthEntries as [$sRoute, $sTail, $sPieces]) {
            yield from $this->pushSynthFrameGen($em, $sRoute, $sTail, $sPieces);
        }
        $sorted = $dataEntries;
        \usort($sorted, static fn (array $a, array $b): int => $a[0]->span->start <=> $b[0]->span->start);
        foreach ($lines as $idx => $line) {
            if ($line['field'] === 'data') {
                if ($idx === $firstDataIdx) {
                    $em->push('data: ');
                    yield from $em->drainReady();
                    $cursor = 0;
                    foreach ($sorted as [$entry, $pieces, $events, $isNoop]) {
                        if ($entry->span->start > $cursor) {
                            yield from $em->pushBytesGen(\substr($joined, $cursor, $entry->span->start - $cursor));
                        }
                        if ($isNoop) {
                            // Unresolved: emit the original span bytes verbatim
                            // (no re-encoding) and attach the event atomically to
                            // the segment containing the span's final byte (the
                            // events ride the final slice's push, so an exact
                            // 16 KiB boundary cannot strand them on a later seg).
                            yield from $em->pushBytesGen(
                                \substr($joined, $entry->span->start, $entry->span->length),
                                $events,
                            );
                        } else {
                            // Resolved (or terminal-empty): stream each piece so
                            // every event attaches to its own placeholder's final
                            // byte (spec §9.6). Terminal entries have no pieces.
                            foreach ($pieces as [$pText, $pEvents]) {
                                yield from $em->pushEscapedGen($pText, $pEvents);
                            }
                        }
                        $cursor = $entry->span->start + $entry->span->length;
                    }
                    if (!$hasRewrite) {
                        yield from $em->pushBytesGen($joined);
                    } elseif ($cursor < \strlen($joined)) {
                        yield from $em->pushBytesGen(\substr($joined, $cursor));
                    }
                    $em->push($lineEnding);
                    yield from $em->drainReady();
                }
            } else {
                yield from $em->pushBytesGen($line['raw']);
            }
        }
        yield from $em->finish();
    }

    /**
     * @param list<array{raw:string, content:string, ending:string, field:string|null, value:string|null}> $lines
     */
    private function pickLineEnding(array $lines, ?int $firstDataIdx): string
    {
        if ($firstDataIdx !== null && isset($lines[$firstDataIdx])) {
            $ending = $lines[$firstDataIdx]['ending'];
            if ($ending !== '') {
                return $ending;
            }
        }
        foreach ($lines as $line) {
            if ($line['ending'] !== '') {
                return $line['ending'];
            }
        }

        return "\n";
    }

    /**
     * @return list<array{raw:string, content:string, ending:string, field:string|null, value:string|null}>
     */
    private function parseEventLines(string $eventBytes, bool $eof = false): array
    {
        $lines = [];
        $n = \strlen($eventBytes);
        $pos = 0;

        $consumeBom = !$this->firstEventSeen && $n >= 3 && \substr($eventBytes, 0, 3) === self::BOM && $this->leadingBom === null;

        while ($pos < $n) {
            $found = self::findLineFrom($eventBytes, $pos, $n, $eof);
            if ($found === null) {
                $raw = \substr($eventBytes, $pos);
                $content = $raw;
                $ending = '';
                $pos = $n;
            } else {
                [$contentEnd, $afterEnding] = $found;
                $raw = \substr($eventBytes, $pos, $afterEnding - $pos);
                $content = \substr($eventBytes, $pos, $contentEnd - $pos);
                $ending = \substr($eventBytes, $contentEnd, $afterEnding - $contentEnd);
                $pos = $afterEnding;
            }

            $contentForParse = $content;
            if ($consumeBom && \substr($content, 0, 3) === self::BOM) {
                $this->leadingBom = self::BOM;
                // Strip the BOM from raw/content too: it is re-emitted once as a
                // stream prefix before any synthesized frame, so it must not also
                // ride the first line (codex high: BOM pushed mid-stream by a
                // leading synth frame otherwise corrupts the SSE wire).
                $raw = \substr($raw, 3);
                $content = \substr($content, 3);
                $contentForParse = $content;
            }
            $consumeBom = false;

            [$field, $value] = self::parseField($contentForParse);

            $lines[] = [
                'raw' => $raw,
                'content' => $content,
                'ending' => $ending,
                'field' => $field,
                'value' => $value,
            ];
        }

        return $lines;
    }

    /**
     * @return array{0:string|null, 1:string|null}
     */
    private static function parseField(string $content): array
    {
        if ($content === '') {
            return [null, null];
        }
        if ($content[0] === ':') {
            return [':', null];
        }
        $colon = \strpos($content, ':');
        if ($colon === false) {
            return [$content, ''];
        }
        $field = \substr($content, 0, $colon);
        $value = \substr($content, $colon + 1);
        if ($value !== '' && $value[0] === ' ') {
            $value = \substr($value, 1);
        }

        return [$field, $value];
    }

    // ---- JSON walk + routing ------------------------------------------------

    /**
     * @param list<array<string,mixed>> $visits
     * @param list<string> $finishChoiceKeys
     */
    private function walkChoices(string $json, JsonValue $root, array &$visits, array &$finishChoiceKeys): void
    {
        if (!$root instanceof JsonObject) {
            return;
        }
        foreach (self::membersByName($root, 'choices', $json) as $choices) {
            if (!$choices instanceof JsonArray) {
                continue;
            }
            $choicePos = 0;
            foreach ($choices->elements as $choice) {
                if (!$choice instanceof JsonObject) {
                    $choicePos++;
                    continue;
                }
                $this->visitChoice($json, $choice, $choicePos, $visits, $finishChoiceKeys);
                $choicePos++;
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $visits
     * @param list<string> $finishChoiceKeys
     */
    private function visitChoice(
        string $json,
        JsonObject $choice,
        int $choicePos,
        array &$visits,
        array &$finishChoiceKeys,
    ): void {
        [$choiceKey, $choiceIndexToken] = $this->choiceRouting($choice, $choicePos, $json);
        // Spec §9.5: a duplicate choice routing key within the same frame gets
        // an internal suffix so finishing one choice cannot flush another that
        // shares the same raw index token (codex #6). The suffix is the per-
        // frame monotonic occurrence number — NOT choicePos, which resets per
        // `choices` member and would re-collide across duplicate `choices`
        // arrays (codex r3). A `#N` tail cannot collide with another choice's
        // key: every raw index token is quote/bracket/word-framed or a JSON
        // number, and numbers cannot contain '#'. The suffix is never written
        // back to protocol fields.
        $choiceOccur = $this->frameChoiceOccurrence[$choiceKey] ?? 0;
        $this->frameChoiceOccurrence[$choiceKey] = $choiceOccur + 1;
        if ($choiceOccur > 0) {
            $choiceKey .= '#' . $choiceOccur;
        }

        $hasFinish = false;
        foreach (self::membersByName($choice, 'finish_reason', $json) as $fr) {
            if (!($fr instanceof JsonScalar && $fr->scalarKind === 'null')) {
                $hasFinish = true;
            }
        }
        if ($hasFinish) {
            $finishChoiceKeys[] = $choiceKey;
        }

        foreach (self::membersByName($choice, 'delta', $json) as $delta) {
            if (!$delta instanceof JsonObject) {
                continue;
            }
            $this->visitStringMember($json, $delta, 'content', self::TARGET_CONTENT, $choiceKey, $choiceIndexToken, null, null, null, null, null, $visits);
            $this->visitStringMember($json, $delta, 'refusal', self::TARGET_REFUSAL, $choiceKey, $choiceIndexToken, null, null, null, null, null, $visits);

            foreach (self::membersByName($delta, 'tool_calls', $json) as $toolCalls) {
                if (!$toolCalls instanceof JsonArray) {
                    continue;
                }
                $toolPos = 0;
                foreach ($toolCalls->elements as $toolCall) {
                    if (!$toolCall instanceof JsonObject) {
                        $toolPos++;
                        continue;
                    }
                    [$toolKey, $toolIndexToken, $toolIdToken, $fnNameToken, $custNameToken] = $this->toolRouting($toolCall, $toolPos, $json);

                    foreach (self::membersByName($toolCall, 'function', $json) as $fn) {
                        if ($fn instanceof JsonObject) {
                            $this->visitStringMember($json, $fn, 'arguments', self::TARGET_ARGUMENTS, $choiceKey, $choiceIndexToken, $toolKey, $toolIndexToken, $toolIdToken, $fnNameToken, null, $visits);
                        }
                    }
                    foreach (self::membersByName($toolCall, 'custom', $json) as $cust) {
                        if ($cust instanceof JsonObject) {
                            $this->visitStringMember($json, $cust, 'input', self::TARGET_INPUT, $choiceKey, $choiceIndexToken, $toolKey, $toolIndexToken, $toolIdToken, null, $custNameToken, $visits);
                        }
                    }
                    $toolPos++;
                }
            }

            foreach (self::membersByName($delta, 'function_call', $json) as $fc) {
                if ($fc instanceof JsonObject) {
                    $this->visitStringMember($json, $fc, 'arguments', self::TARGET_LEGACY, $choiceKey, $choiceIndexToken, null, null, null, null, null, $visits);
                }
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $visits
     */
    private function visitStringMember(
        string $json,
        JsonObject $container,
        string $name,
        string $target,
        string $choiceKey,
        ?string $choiceIndexToken,
        ?string $toolKey,
        ?string $toolIndexToken,
        ?string $toolIdToken,
        ?string $fnNameToken,
        ?string $custNameToken,
        array &$visits,
    ): void {
        foreach (self::membersByName($container, $name, $json) as $value) {
            if (!$value instanceof JsonString) {
                continue;
            }
            $decoded = self::decodeJsonString($value, $json);
            $routeKey = self::composeRouteKey($target, $choiceKey, $toolKey);
            // Codex #9: disambiguate same-hint visits within a single frame
            // (e.g. two tool_calls sharing one index in one delta). The first
            // occurrence keeps the base key so cross-frame streaming still
            // routes to the same generation; later occurrences get a suffix.
            $occurrence = $this->frameRouteOccurrence[$routeKey] ?? 0;
            $this->frameRouteOccurrence[$routeKey] = $occurrence + 1;
            if ($occurrence > 0) {
                $routeKey .= '#' . $occurrence;
            }
            $route = $this->getOrCreateRoute(
                $routeKey,
                $target,
                $choiceKey,
                $toolKey,
                $choiceIndexToken,
                $toolIndexToken,
                $toolIdToken,
                $fnNameToken,
                $custNameToken,
            );

            $events = [];
            $pieces = [];
            $writeText = '';
            try {
                $result = $route->restorer->write($decoded);
                $writeText = $result->text;
                // Codex #10: accrue against the shared cumulative budget so N
                // routes cannot each spend the full MaxOutputBytes.
                $this->accrueRestoredOutput($writeText);
                // SSE pieces (per-placeholder) so each event attaches to the
                // segment with its own placeholder's final byte (spec §9.6).
                $pieces = $this->ssePieces($result);
                foreach ($pieces as [$pText, $pEvents]) {
                    foreach ($pEvents as $ev) {
                        $events[] = $ev;
                    }
                }
            } catch (LlmaskingException $e) {
                $wrapped = $e instanceof StreamRestoreException ? $e : new StreamRestoreException('restore write failed: ' . $e->getMessage(), 0, $e);
                $this->fail($wrapped);
            }

            $visits[] = [
                'route' => $route,
                'span' => $value->contentSpan(),
                'decoded' => $decoded,
                'writeText' => $writeText,
                'events' => $events,
                'pieces' => $pieces,
                'choiceKey' => $choiceKey,
            ];
        }
    }

    /**
     * @return array{0:string, 1:?string}
     */
    private function choiceRouting(JsonObject $choice, int $position, string $json): array
    {
        foreach ($choice->members as $member) {
            if (self::decodeJsonString($member->key, $json) === 'index') {
                if (!self::isNull($member->value)) {
                    $tok = self::rawToken($member->value, $json);

                    return ['idx:' . $tok, $tok];
                }
            }
        }

        return ['pos:' . $position, null];
    }

    /**
     * @return array{0:string, 1:?string, 2:?string, 3:?string, 4:?string}
     */
    private function toolRouting(JsonObject $toolCall, int $position, string $json): array
    {
        $indexToken = $idToken = $fnNameToken = $custNameToken = null;

        foreach ($toolCall->members as $member) {
            $key = self::decodeJsonString($member->key, $json);
            if ($key === 'index' && $indexToken === null && !self::isNull($member->value)) {
                $indexToken = self::rawToken($member->value, $json);
            } elseif ($key === 'id' && $idToken === null && !self::isNull($member->value)) {
                $idToken = self::rawToken($member->value, $json);
            } elseif ($key === 'function' && $fnNameToken === null && $member->value instanceof JsonObject) {
                foreach ($member->value->members as $fm) {
                    if (self::decodeJsonString($fm->key, $json) === 'name' && !self::isNull($fm->value)) {
                        $fnNameToken = self::rawToken($fm->value, $json);
                        break;
                    }
                }
            } elseif ($key === 'custom' && $custNameToken === null && $member->value instanceof JsonObject) {
                foreach ($member->value->members as $cm) {
                    if (self::decodeJsonString($cm->key, $json) === 'name' && !self::isNull($cm->value)) {
                        $custNameToken = self::rawToken($cm->value, $json);
                        break;
                    }
                }
            }
        }

        if ($indexToken !== null) {
            return ['idx:' . $indexToken, $indexToken, $idToken, $fnNameToken, $custNameToken];
        }
        if ($idToken !== null) {
            return ['id:' . $idToken, $indexToken, $idToken, $fnNameToken, $custNameToken];
        }
        if ($fnNameToken !== null) {
            return ['fn:' . $fnNameToken, $indexToken, $idToken, $fnNameToken, $custNameToken];
        }
        if ($custNameToken !== null) {
            return ['cust:' . $custNameToken, $indexToken, $idToken, $fnNameToken, $custNameToken];
        }

        return ['pos:' . $position, $indexToken, $idToken, $fnNameToken, $custNameToken];
    }

    private function getOrCreateRoute(
        string $routeKey,
        string $target,
        string $choiceKey,
        ?string $toolKey,
        ?string $choiceIndexToken,
        ?string $toolIndexToken,
        ?string $toolIdToken,
        ?string $fnNameToken,
        ?string $custNameToken,
    ): SseRoute {
        if (isset($this->routes[$routeKey])) {
            $existing = $this->routes[$routeKey];
            if ($existing->flushed) {
                $this->streamCount++;
                $this->assertStreamCount();
                $identity = $this->routeIdentity[$routeKey] ?? [];
                $new = new SseRoute(
                    $routeKey,
                    $choiceKey,
                    $toolKey,
                    $existing->ordinal,
                    $target,
                    $this->session->streamRestorer(),
                    $identity['choiceIndex'] ?? null,
                    $identity['toolIndex'] ?? null,
                    $identity['toolId'] ?? null,
                    $identity['functionName'] ?? null,
                    $identity['customName'] ?? null,
                    false,
                    $existing->lineEnding ?? $this->currentEventLineEnding,
                );
                $this->routes[$routeKey] = $new;

                return $new;
            }

            return $existing;
        }

        $this->streamCount++;
        $this->assertStreamCount();
        $ordinal = $this->nextOrdinal++;
        $route = new SseRoute(
            $routeKey,
            $choiceKey,
            $toolKey,
            $ordinal,
            $target,
            $this->session->streamRestorer(),
            $choiceIndexToken,
            $toolIndexToken,
            $toolIdToken,
            $fnNameToken,
            $custNameToken,
            false,
            $this->currentEventLineEnding,
        );
        $this->routes[$routeKey] = $route;
        $this->routeOrder[] = $routeKey;
        $this->routeIdentity[$routeKey] = [
            'choiceIndex' => $choiceIndexToken,
            'toolIndex' => $toolIndexToken,
            'toolId' => $toolIdToken,
            'functionName' => $fnNameToken,
            'customName' => $custNameToken,
        ];
        // Spec §9.5: saved identity tokens share totalSseStateBytesBudget.
        $identityBytes = \strlen($routeKey);
        $identityBytes += $choiceIndexToken !== null ? \strlen($choiceIndexToken) : 0;
        $identityBytes += $toolIndexToken !== null ? \strlen($toolIndexToken) : 0;
        $identityBytes += $toolIdToken !== null ? \strlen($toolIdToken) : 0;
        $identityBytes += $fnNameToken !== null ? \strlen($fnNameToken) : 0;
        $identityBytes += $custNameToken !== null ? \strlen($custNameToken) : 0;
        $newState = self::satAdd($this->stateBytes, $identityBytes);
        if ($newState > $this->totalSseStateBytesBudget) {
            $this->fail(new StreamRestoreException('total SSE state bytes exceed budget'));
        }
        $this->stateBytes = $newState;

        return $route;
    }

    private function assertStreamCount(): void
    {
        if ($this->streamCount > self::MAX_SSE_STREAMS) {
            $this->fail(new StreamRestoreException('maxSseStreams exceeded (' . self::MAX_SSE_STREAMS . ')'));
        }
    }

    /**
     * Charge the per-route withheld buffers (StreamRestorer retained input +
     * incomplete UTF-8) against the shared totalSseStateBytesBudget, alongside
     * the already-counted identity tokens. A route whose placeholder lexer is
     * stuck (e.g. a long run of upper-case bytes never closing a placeholder)
     * can otherwise retain up to MaxInputBytes each, blowing the budget without
     * any check (spec §9.5 / codex high).
     */
    private function checkStateBudget(): void
    {
        $retained = 0;
        foreach ($this->routes as $route) {
            $retained += $route->restorer->retainedBytes();
        }
        if ($this->stateBytes + $retained > $this->totalSseStateBytesBudget) {
            $this->fail(new StreamRestoreException('total SSE state bytes (identity + withheld) exceed budget'));
        }
    }

    // ---- Blanket flush ------------------------------------------------------

    /**
     * Flush every still-open route in ordinal order, yielding the synthesized
     * frames as at-most-16 KiB segments (spec §9.5/§9.6).
     *
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    private function blanketFlushGen(): \Generator
    {
        // Codex #7: no global blanketFlushed flag — per-route `flushed` is the
        // sole flush state, so a new generation created after a [DONE] flush
        // is still flushed at EOF (the flag is reset when the route is
        // re-created in getOrCreateRoute).

        $em = new SegmentEmitter();
        foreach ($this->routeOrder as $routeKey) {
            $route = $this->routes[$routeKey] ?? null;
            if ($route === null || $route->flushed) {
                continue;
            }
            try {
                $result = $route->restorer->flush();
            } catch (StreamClosedException $e) {
                continue;
            } catch (LlmaskingException $e) {
                $wrapped = $e instanceof StreamRestoreException ? $e : new StreamRestoreException('blanket flush failed: ' . $e->getMessage(), 0, $e);
                $this->fail($wrapped);
            }
            $route->flushed = true;
            $this->accrueRestoredOutput($result->text);
            if ($result->text === '' && $result->events === []) {
                continue;
            }
            $framePieces = $this->ssePieces($result);
            $frameLen = $this->synthesizedFrameLength($route, $result->text);
            $newTotal = self::satAdd($this->emittedSseProduced, $frameLen);
            if ($newTotal > $this->totalEmittedSseBudget) {
                $this->fail(new StreamRestoreException('total emitted SSE bytes exceed budget'));
            }
            $this->emittedSseProduced = $newTotal;
            // Stream the frame (template + per-piece tail + suffix), attaching
            // each event to its own placeholder's final byte (spec §9.6).
            yield from $this->pushSynthFrameGen($em, $route, $result->text, $framePieces);
        }
        yield from $em->drainReady();
        yield from $em->finish();
    }

    /**
     * The delta JSON fragment surrounding the escaped tail as [before, after],
     * constructed explicitly per target kind. This is the single source of
     * truth for the synthesized frame structure (no content-sentinel split, so
     * an empty-string identity token cannot hijack the tail position — codex
     * high #2).
     *
     * @return array{0:string, 1:string}
     */
    private function synthDelta(SseRoute $route): array
    {
        if ($route->target === self::TARGET_CONTENT) {
            return ['"delta":{"content":"', '"}'];
        }
        if ($route->target === self::TARGET_REFUSAL) {
            return ['"delta":{"refusal":"', '"}'];
        }
        if ($route->target === self::TARGET_LEGACY) {
            return ['"delta":{"function_call":{"arguments":"', '"}}'];
        }

        $tool = '';
        if ($route->toolIndexToken !== null) {
            $tool .= '"index":' . $route->toolIndexToken . ',';
        }
        if ($route->toolIdToken !== null) {
            $tool .= '"id":' . $route->toolIdToken . ',';
        }
        if ($route->target === self::TARGET_ARGUMENTS) {
            $fn = $route->functionNameToken !== null ? '"name":' . $route->functionNameToken . ',' : '';

            return ['"delta":{"tool_calls":[{' . $tool . '"function":{' . $fn . '"arguments":"', '"}}]}'];
        }

        $cust = $route->customNameToken !== null ? '"name":' . $route->customNameToken . ',' : '';

        return ['"delta":{"tool_calls":[{' . $tool . '"custom":{' . $cust . '"input":"', '"}}]}'];
    }

    /**
     * The full frame JSON template around the escaped tail as [before, after]:
     * `{"choices":[{` + choice + delta-before ... delta-after + `}]}`.
     *
     * @return array{0:string, 1:string}
     */
    private function synthTemplate(SseRoute $route): array
    {
        $choice = $route->choiceIndexToken !== null ? '"index":' . $route->choiceIndexToken . ',' : '';
        [$deltaBefore, $deltaAfter] = $this->synthDelta($route);

        // after = deltaAfter + close-choice + close-choices-array + close-root.
        return ['{"choices":[{' . $choice . $deltaBefore, $deltaAfter . '}]}'];
    }

    /**
     * Dry-run byte length of a synthesized frame WITHOUT building it (spec §9.5
     * encoded-length check; codex #2). = 'data: ' + before + escaped(tail) +
     * after + ending + ending.
     */
    private function synthesizedFrameLength(SseRoute $route, string $tail): int
    {
        [$before, $after] = $this->synthTemplate($route);
        $ending = $route->lineEnding ?? "\n";

        return 6 + \strlen($before) + JsonPatch::encodedStringLength($tail) + \strlen($after) + 2 * \strlen($ending);
    }

    /**
     * Stream one synthesized frame into $em, yielding each at-most-16 KiB
     * segment as it forms: the template prefix, the tail streamed piece by
     * piece (each event attaches to its own placeholder's final byte), then the
     * suffix. Never materializes the whole frame (spec §9.5/§9.6). $tail (the
     * concatenation of the piece texts) is only used by the dry-run length
     * check.
     *
     * @param list<array{0:string, 1:list<RestoreEvent>}> $pieces
     * @return \Generator<int, array{0:string, 1:list<RestoreEvent>}, mixed, void>
     */
    private function pushSynthFrameGen(SegmentEmitter $em, SseRoute $route, string $tail, array $pieces): \Generator
    {
        [$before, $after] = $this->synthTemplate($route);
        $ending = $route->lineEnding ?? "\n";
        // 'data: ' is a tiny constant; $before can carry MiB-scale raw identity
        // tokens (choice/tool index/id/name), so stream it in slices (codex high).
        $em->push('data: ');
        yield from $em->drainReady();
        yield from $em->pushBytesGen($before);
        foreach ($pieces as [$pText, $pEvents]) {
            yield from $em->pushEscapedGen($pText, $pEvents);
        }
        yield from $em->pushBytesGen($after . $ending . $ending);
    }

    // ---- Helpers ------------------------------------------------------------

    /**
     * Codex #10: accumulate restored plaintext bytes against a single shared
     * budget (MaxOutputBytes) across all routes, so a multi-route response
     * cannot spend N x MaxOutputBytes. Checked BEFORE committing the bytes.
     */
    private function accrueRestoredOutput(string $text): void
    {
        if ($text === '') {
            return;
        }
        $len = \strlen($text);
        $newTotal = self::satAdd($this->totalRestoredBytes, $len);
        if ($newTotal > $this->session->engine->maxOutputBytes) {
            $this->fail(new StreamRestoreException('cumulative restored output exceeds MaxOutputBytes'));
        }
        $this->totalRestoredBytes = $newTotal;
    }

    /**
     * Convert a StreamRestoreResult's pieces into SSE transport pieces:
     * `list<array{0:string, 1:list<RestoreEvent>}>`, each event funneled
     * through {@see transportEvent()} (which enforces the report budget).
     *
     * @return list<array{0:string, 1:list<RestoreEvent>}>
     */
    private function ssePieces(StreamRestoreResult $r): array
    {
        $out = [];
        foreach ($r->pieces as [$pText, $pEvents]) {
            $tp = [];
            // With no restore report enabled, do not construct or carry events
            // at all: a long SSE response with many placeholders would else
            // accumulate millions of unused RestoreEvent objects in the stream's
            // committedEvents (codex high). Pieces carry text only then.
            if ($this->reportEnabled) {
                foreach ($pEvents as $se) {
                    $tp[] = $this->transportEvent($se);
                }
            }
            $out[] = [$pText, $tp];
        }

        return $out;
    }

    private function transportEvent(RestoreEvent $e): RestoreEvent
    {
        // Spec §9.5: the response-level report caps apply only while a restore
        // report is enabled; without a callback the events are still carried
        // for the (null) completion but not counted against the report budget.
        if ($this->reportEnabled) {
            $this->accrueReportEvent($e);
        }

        return new RestoreEvent($e->entity, 0, 0, $e->placeholder, $e->restored);
    }

    /**
     * Charge one RestoreEvent against the response-level cumulative caps
     * (codex #4 / spec §9.5) BEFORE emitting it, so a multi-route response
     * cannot exceed MaxRestoreEvents / MaxRestoreReportBytes in aggregate.
     * Every event funnels through transportEvent(), so this is the single
     * accrual site for all routes.
     */
    private function accrueReportEvent(RestoreEvent $e): void
    {
        if ($this->totalRestoreEvents >= Engine::MAX_RESTORE_EVENTS) {
            $this->fail(new StreamRestoreException('cumulative restore events exceed MaxRestoreEvents'));
        }
        $evBytes = \strlen($e->entity) + \strlen($e->placeholder) + \strlen($e->source);
        if ($this->totalRestoreReportBytes + $evBytes > Engine::MAX_RESTORE_REPORT_BYTES) {
            $this->fail(new StreamRestoreException('cumulative restore report bytes exceed limit'));
        }
        $this->totalRestoreEvents++;
        $this->totalRestoreReportBytes += $evBytes;
    }

    private static function decodeJsonString(JsonString $s, string $json): string
    {
        return JsonTree::decodeString($s, $json);
    }

    /**
     * @return list<JsonValue>
     */
    private static function membersByName(JsonObject $obj, string $name, string $json): array
    {
        return JsonTree::membersByName($obj, $name, $json);
    }

    private static function rawToken(JsonValue $v, string $json): string
    {
        return \substr($json, $v->start, $v->end - $v->start);
    }

    private static function isNull(JsonValue $v): bool
    {
        return $v instanceof JsonScalar && $v->scalarKind === 'null';
    }

    private static function composeRouteKey(string $target, string $choiceKey, ?string $toolKey): string
    {
        // Codex #9: length-prefix each tuple component so the concatenation
        // is unambiguous (a '|' inside a raw id/name token cannot splice into
        // a different target/choice/tool grouping).
        $parts = [$target, $choiceKey];
        if ($toolKey !== null) {
            $parts[] = $toolKey;
        }
        $out = '';
        foreach ($parts as $p) {
            $out .= \strlen($p) . ':' . $p . '|';
        }

        return $out;
    }

    private function assertChunkUtf8(string $chunk): void
    {
        $s = $this->utf8Partial . $chunk;
        $incomplete = self::trailingIncompleteLen($s);
        $check = $incomplete === 0 ? $s : \substr($s, 0, \strlen($s) - $incomplete);
        if ($check !== '' && !\mb_check_encoding($check, 'UTF-8')) {
            $this->fail(new StreamRestoreException('SSE chunk is not valid UTF-8'));
        }
        $this->utf8Partial = $incomplete === 0 ? '' : \substr($s, \strlen($s) - $incomplete);
    }

    private static function trailingIncompleteLen(string $s): int
    {
        $n = \strlen($s);
        for ($k = 1; $k <= 3 && $n - $k >= 0; $k++) {
            $c = \ord($s[$n - $k]);
            if ($c < 0x80) {
                return 0;
            }
            if (($c & 0xC0) === 0xC0) {
                $expected = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : 2);

                return $k < $expected ? $k : 0;
            }
        }

        return 0;
    }

    private static function satAdd(int $a, int $b): int
    {
        if ($b > 0 && $a > PHP_INT_MAX - $b) {
            return PHP_INT_MAX;
        }

        return $a + $b;
    }

    private static function satMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a > 0 && $b > 0 && $a > \intdiv(PHP_INT_MAX, $b)) {
            return PHP_INT_MAX;
        }

        return $a * $b;
    }

    private function fail(\Throwable $e): never
    {
        $this->failed = $e;
        throw $e;
    }
}
