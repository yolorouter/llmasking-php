<?php

// src/StreamRestorer.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Exception\{InvalidUTF8Exception, LimitExceededException, StreamClosedException};
use Yolorouter\Llmasking\Internal\PlaceholderLexer;
use Yolorouter\Llmasking\Internal\PlaceholderToken;

/**
 * Incremental placeholder restorer for chunked responses (e.g. SSE). Bound to
 * one Session and one stream: write()/flush() must be called serially from a
 * single caller. Backed by a STATEFUL PlaceholderLexer fed one chunk per write,
 * so every byte is processed exactly once (O(n) over the whole stream — no
 * re-scan of a growing withheld buffer). UTF-8 is validated incrementally too;
 * an incomplete codepoint at EOF fails the stream closed.
 *
 * Any error (input/output budget, UTF-8, restore failure) puts the restorer in
 * a terminal failed state: every subsequent call rethrows the same exception
 * with no further side effects. A successful flush() also closes the restorer.
 */
final class StreamRestorer
{
    private PlaceholderLexer $lexer;

    /** Un-emitted input bytes; $input[0] sits at absolute offset $base. */
    private string $input = '';

    private int $base = 0;

    /** Trailing incomplete UTF-8 sequence carried from the previous write. */
    private string $utf8Partial = '';

    private ?\Throwable $failed = null;

    private int $outputLen = 0;

    private int $inputLen = 0;

    private int $eventCount = 0;

    private int $reportBytes = 0;

    public function __construct(public readonly Session $session)
    {
        $this->lexer = new PlaceholderLexer($session->engine->maxSeqDigits);
    }

    public function write(string $chunk): StreamRestoreResult
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        $this->inputLen += \strlen($chunk);
        if ($this->inputLen > $this->session->engine->maxInputBytes) {
            $this->abort(new LimitExceededException('cumulative stream input exceeds MaxInputBytes'));
        }
        $this->assertChunkUtf8($chunk);
        $tokens = $this->lexer->feed($chunk, false);
        $this->input .= $chunk;
        return $this->emit($tokens);
    }

    public function flush(): StreamRestoreResult
    {
        if ($this->failed !== null) {
            throw $this->failed;
        }
        // A carried UTF-8 partial at EOF is a truncated codepoint: fail closed.
        if ($this->utf8Partial !== '') {
            $this->abort(new InvalidUTF8Exception('stream ended inside a UTF-8 codepoint'));
        }
        $tokens = $this->lexer->finish();
        $result = $this->emit($tokens);
        $this->failed = new StreamClosedException('stream restorer has been flushed');
        return $result;
    }

    /**
     * Incremental UTF-8 validation: validate the COMPLETE portion of
     * ($utf8Partial . $chunk) and carry any trailing incomplete codepoint into
     * the next write. O(strlen(chunk)) per call, so O(n) over the stream.
     */
    private function assertChunkUtf8(string $chunk): void
    {
        $s = $this->utf8Partial . $chunk;
        $incomplete = self::trailingIncompleteLen($s);
        $check = $incomplete === 0 ? $s : \substr($s, 0, \strlen($s) - $incomplete);
        if ($check !== '' && !\mb_check_encoding($check, 'UTF-8')) {
            $this->abort(new InvalidUTF8Exception('stream chunk is not valid UTF-8'));
        }
        $this->utf8Partial = $incomplete === 0 ? '' : \substr($s, \strlen($s) - $incomplete);
    }

    /**
     * Reconstruct restored text from the tokens the lexer just completed plus
     * the trailing confirmed-literal, applying the cumulative event/report-byte
     * and output budgets. Any budget breach fails the restorer closed.
     *
     * @param list<PlaceholderToken> $tokens
     */
    private function emit(array $tokens): StreamRestoreResult
    {
        $maxOut = $this->session->engine->maxOutputBytes;
        $out = '';
        $events = [];
        $pieces = [];
        $curLit = '';
        $evCount = 0;
        $repBytes = 0;
        $emitPtr = 0;
        // Append one piece against the cumulative output budget BEFORE adding
        // it, so an oversized restored plaintext cannot blow up memory before
        // the check fires (a repeated short placeholder could expand hugely).
        $append = function (string $piece) use (&$out, $maxOut): void {
            if ($this->outputLen + \strlen($out) + \strlen($piece) > $maxOut) {
                $this->abort(new LimitExceededException('stream output exceeds MaxOutputBytes'));
            }
            $out .= $piece;
        };
        // Pieces (spec §9.6 per-event placement): a literal run accumulates into
        // the current literal piece; each placeholder closes the literal piece
        // and becomes its own piece carrying its event, so a downstream segment
        // stream attaches the event to the segment with this placeholder's last
        // byte instead of the whole replacement's end.
        foreach ($tokens as $t) {
            $startRel = $t->start - $this->base;
            $literal = \substr($this->input, $emitPtr, $startRel - $emitPtr);
            $append($literal);
            $curLit .= $literal;
            $raw = \substr($this->input, $startRel, $t->end - $t->start);
            $plaintext = $this->session->resolvePlaceholderToken($t);
            $pieceText = $plaintext ?? $raw;
            $append($pieceText);
            $emitPtr = $startRel + ($t->end - $t->start);

            if ($this->eventCount + $evCount >= Engine::MAX_RESTORE_EVENTS) {
                $this->abort(new LimitExceededException('cumulative stream restore events exceed MaxRestoreEvents'));
            }
            $eb = \strlen($t->entity) + \strlen($raw);
            if ($this->reportBytes + $repBytes + $eb > Engine::MAX_RESTORE_REPORT_BYTES) {
                $this->abort(new LimitExceededException('cumulative stream restore report bytes exceed limit'));
            }
            $event = new RestoreEvent($t->entity, 0, 0, $raw, $plaintext !== null);
            $events[] = $event;
            if ($curLit !== '') {
                $pieces[] = [$curLit, []];
                $curLit = '';
            }
            $pieces[] = [$pieceText, [$event]];
            $evCount++;
            $repBytes += $eb;
        }
        // Trailing confirmed literal, bounded by BOTH the lexer's committed
        // offset AND the UTF-8-validated prefix: bytes held in $utf8Partial
        // (an incomplete codepoint) must not be emitted until a later chunk
        // validates them or flush fails the stream.
        $validatedAbs = $this->inputLen - \strlen($this->utf8Partial);
        $committedAbs = \min($this->lexer->committedOffset(), $validatedAbs);
        $committedRel = $committedAbs - $this->base;
        $trail = \substr($this->input, $emitPtr, $committedRel - $emitPtr);
        $append($trail);
        $curLit .= $trail;
        $emitPtr = $committedRel;
        if ($curLit !== '') {
            $pieces[] = [$curLit, []];
        }

        // Commit: advance budgets, drop the emitted prefix from $input.
        $this->outputLen += \strlen($out);
        $this->eventCount += $evCount;
        $this->reportBytes += $repBytes;
        if ($emitPtr > 0) {
            $this->input = \substr($this->input, $emitPtr);
            $this->base += $emitPtr;
        }
        return new StreamRestoreResult($out, $events, $pieces);
    }

    /** Record $e as the terminal failure and rethrow it. */
    private function abort(\Throwable $e): never
    {
        $this->failed = $e;
        throw $e;
    }

    /**
     * Bytes currently retained (un-emitted input + any carried incomplete UTF-8
     * codepoint). Used by the SSE layer to charge the per-route withheld buffer
     * against the shared totalSseStateBytesBudget (spec §9.5 / codex high).
     */
    public function retainedBytes(): int
    {
        // Withheld input + incomplete UTF-8 + the lexer's partial-candidate
        // buffers (a long upper-case/digit run held in $entity/$digits/$held
        // on top of $input) — all charged to the SSE state budget (codex).
        return \strlen($this->input) + \strlen($this->utf8Partial) + $this->lexer->retainedBytes();
    }

    /** Length of a truncated UTF-8 sequence at the end of $s (0 if none). */
    private static function trailingIncompleteLen(string $s): int
    {
        $n = \strlen($s);
        for ($k = 1; $k <= 3 && $n - $k >= 0; $k++) {
            $c = \ord($s[$n - $k]);
            if ($c < 0x80) {
                return 0; // ASCII byte: the codepoint ending here is complete
            }
            if (($c & 0xC0) === 0xC0) { // lead byte
                $expected = $c >= 0xF0 ? 4 : ($c >= 0xE0 ? 3 : 2);
                return $k < $expected ? $k : 0;
            }
            // continuation byte: keep walking back
        }
        return 0;
    }
}
