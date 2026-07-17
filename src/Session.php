<?php

// src/Session.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Exception\{InvalidUTF8Exception, LimitExceededException};
use Yolorouter\Llmasking\Internal\{ConflictResolver, PlaceholderLexer, RecognizerDriver, Reversibility};

/**
 * One masking session. Owns the reversible placeholder<->plaintext mapping
 * accumulated across anonymize() calls and enforces per-session limits
 * (MaxEntities, MaxSessionBytes). anonymize() is two-phase: a staging phase
 * computes every placeholder, the output, and every resource precheck without
 * touching session state, and a commit phase applies them atomically, so any
 * exception raised mid-call (UTF-8, input/output size, entity count, session
 * bytes, recognizer or strategy failure) leaves the session state exactly
 * where it was before the call (spec section 5.4).
 */
final class Session
{
    /** @var array<string, string> placeholder => plaintext */
    private array $byPlaceholder = [];

    /** @var array<string, array<string, string>> entity => (NUL-prefixed plaintext => placeholder) */
    private array $byPlaintext = [];

    /** @var array<string, int> entity => next sequence number */
    private array $seqCounters = [];

    private int $entityCount = 0;

    private int $sessionBytes = 0;

    public function __construct(public readonly Engine $engine)
    {
    }

    public function anonymize(string $text): AnonymizeResult
    {
        return $this->anonymizeCore($text, true);
    }

    /**
     * Backing for Engine::mask(): same recognition + strategy pipeline, but no
     * mapping is written, no per-session limit is enforced, sequence numbers
     * start at 1, and no events are produced. Reversibility (and therefore the
     * reversible dedup path) is gated off, matching Go's Engine.Mask().
     */
    public function maskText(string $text): string
    {
        return $this->anonymizeCore($text, false)->text;
    }

    /**
     * Shared anonymize implementation. $trackState toggles whether resolved
     * findings are counted toward / written into this session's mapping and
     * limits (anonymize = true) or processed purely for output (mask = false).
     */
    private function anonymizeCore(string $text, bool $trackState): AnonymizeResult
    {
        if (!\mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidUTF8Exception('input is not valid UTF-8');
        }
        if (\strlen($text) > $this->engine->maxInputBytes) {
            throw new LimitExceededException('input exceeds MaxInputBytes');
        }

        $candidates = RecognizerDriver::recognize(
            $this->engine->recognizers,
            $text,
            $this->engine->knownEntities,
        );
        $resolved = ConflictResolver::resolve($candidates, $text);

        if ($trackState && $this->entityCount + \count($resolved) > $this->engine->maxEntities) {
            throw new LimitExceededException('entity count would exceed MaxEntities');
        }

        // Phase 1 — staging: compute every placeholder and resource delta
        // WITHOUT touching session state. A NUL prefix on the plaintext key
        // prevents a purely-numeric plaintext (e.g. '123') colliding via PHP's
        // integer array-key coercion.
        /** @var list<array{0:Finding, 1:string, 2:bool, 3:bool}> $pendings */
        $pendings = [];
        $seqDelta = [];
        $bytesDelta = 0;
        /** @var array<string, array<string, string>> $pendingByPlaintext */
        $pendingByPlaintext = [];
        foreach ($resolved as $finding) {
            $strategy = $this->engine->strategyFor($finding->entity);
            // Reversibility needs BOTH a reversible strategy AND persistent
            // state. mask() runs trackState=false, so it never dedups or writes
            // the map — every finding gets its own sequence (matches Go Mask()).
            $reversible = $trackState && Reversibility::isReversible($strategy);
            $placeholder = '';
            $isNew = true;
            if ($reversible) {
                $key = "\0" . $finding->text;
                if (isset($this->byPlaintext[$finding->entity][$key])) {
                    $placeholder = $this->byPlaintext[$finding->entity][$key];
                    $isNew = false;
                } elseif (isset($pendingByPlaintext[$finding->entity][$key])) {
                    $placeholder = $pendingByPlaintext[$finding->entity][$key];
                    $isNew = false;
                }
            }
            if ($isNew) {
                $seq = ($this->seqCounters[$finding->entity] ?? 0)
                    + ($seqDelta[$finding->entity] ?? 0) + 1;
                $placeholder = $strategy->apply($finding, $seq);
                // Spec 5.2 (PHP hardening): a Strategy output that is not valid
                // UTF-8 fails the whole call atomically — phase 1, before commit.
                if (!\mb_check_encoding($placeholder, 'UTF-8')) {
                    throw new InvalidUTF8Exception(
                        'strategy produced invalid UTF-8 output for entity "' . $finding->entity . '"',
                    );
                }
                $seqDelta[$finding->entity] = ($seqDelta[$finding->entity] ?? 0) + 1;
                if ($reversible) {
                    $bytesDelta += \strlen($finding->text);
                    $pendingByPlaintext[$finding->entity]["\0" . $finding->text] = $placeholder;
                }
            }
            $pendings[] = [$finding, $placeholder, $reversible, $isNew];
        }

        if ($trackState && $this->sessionBytes + $bytesDelta > $this->engine->maxSessionBytes) {
            throw new LimitExceededException('session plaintext bytes would exceed MaxSessionBytes');
        }

        // Phase 1 (cont.) — MaxOutputBytes precheck, computed WITHOUT building
        // the output string first. The output is $text with each finding span
        // replaced by its placeholder, so its length is strlen(text) minus the
        // replaced spans plus the replacement lengths. Checking this before
        // materializing $out means an oversized custom Strategy replacement
        // fails atomically (spec 5.1/5.4) instead of blowing up memory first.
        $outputBytes = \strlen($text);
        foreach ($pendings as [$finding, $placeholder]) {
            $outputBytes += \strlen($placeholder) - ($finding->end - $finding->start);
        }
        if ($outputBytes > $this->engine->maxOutputBytes) {
            throw new LimitExceededException('anonymize output exceeds MaxOutputBytes');
        }

        // Build the masked output and (for the stateful path) events.
        $out = '';
        $events = [];
        $last = 0;
        foreach ($pendings as [$finding, $placeholder, $reversible]) {
            $out .= \substr($text, $last, $finding->start - $last) . $placeholder;
            $last = $finding->end;
            if ($trackState) {
                $events[] = new MaskEvent(
                    $finding->entity,
                    $finding->start,
                    $finding->end,
                    $finding->score,
                    $placeholder,
                    $reversible,
                );
            }
        }
        $out .= \substr($text, $last);

        // Phase 2 — commit (atomic, stateful path only). Every prior check has
        // passed, so this cannot throw; session state ends up either fully
        // updated or, on the mask path, untouched.
        if ($trackState) {
            foreach ($pendings as [$finding, $placeholder, $reversible, $isNew]) {
                if ($reversible && $isNew) {
                    $this->byPlaintext[$finding->entity]["\0" . $finding->text] = $placeholder;
                    $this->byPlaceholder[$placeholder] = $finding->text;
                }
            }
            foreach ($seqDelta as $entity => $delta) {
                $this->seqCounters[$entity] = ($this->seqCounters[$entity] ?? 0) + $delta;
            }
            $this->entityCount += \count($resolved);
            $this->sessionBytes += $bytesDelta;
        }

        return new AnonymizeResult($out, $events);
    }

    /**
     * Replace every placeholder-shaped token in $text (in any tolerated form)
     * with its original plaintext from this session's mapping. Tokens with no
     * mapping (e.g. SECRET Redact tokens, or placeholders from a different
     * session) are left as-is and reported restored=false.
     */
    /**
     * Resolve one placeholder token against this session's reversible mapping.
     * Returns the plaintext if the token is well-formed and mapped, null
     * otherwise. Internal helper shared by restore() and StreamRestorer.
     */
    public function resolvePlaceholderToken(\Yolorouter\Llmasking\Internal\PlaceholderToken $token): ?string
    {
        if (!$token->valid) {
            return null;
        }
        return $this->byPlaceholder['[' . $token->entity . '_' . $token->seq . ']'] ?? null;
    }

    public function restore(string $text): RestoreResult
    {
        if (!\mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidUTF8Exception('input is not valid UTF-8');
        }
        $engine = $this->engine;
        if (\strlen($text) > $engine->maxInputBytes) {
            throw new LimitExceededException('input exceeds MaxInputBytes');
        }

        $out = '';
        $outputLen = 0;
        $append = function (string $chunk) use (&$out, &$outputLen, $engine): void {
            if ($outputLen + \strlen($chunk) > $engine->maxOutputBytes) {
                throw new LimitExceededException('restore output exceeds MaxOutputBytes');
            }
            $out .= $chunk;
            $outputLen += \strlen($chunk);
        };

        $events = [];
        $eventCount = 0;
        $reportBytes = 0;
        $last = 0;
        // Tokenize incrementally (spec section 6.2: do NOT materialize the full
        // token list before the event/report caps can fire). Feed the text in
        // fixed chunks to the stateful lexer; each chunk's token batch is small,
        // and every cap is checked before its event is appended.
        $lexer = new PlaceholderLexer($engine->maxSeqDigits);
        $n = \strlen($text);
        $pos = 0;
        while ($pos < $n) {
            $chunk = \substr($text, $pos, 65536);
            $pos += \strlen($chunk);
            foreach ($lexer->feed($chunk, $pos >= $n) as $token) {
                $append(\substr($text, $last, $token->start - $last));

                $raw = \substr($text, $token->start, $token->end - $token->start);
                $plaintext = $this->resolvePlaceholderToken($token);

                if ($plaintext !== null) {
                    $append($plaintext);
                    $restored = true;
                } else {
                    $append($raw);
                    $restored = false;
                }

                // Fixed caps (spec 5.1): checked BEFORE appending each event.
                if ($eventCount >= Engine::MAX_RESTORE_EVENTS) {
                    throw new LimitExceededException('restore event count exceeds MaxRestoreEvents');
                }
                $evBytes = \strlen($token->entity) + \strlen($raw);
                if ($reportBytes + $evBytes > Engine::MAX_RESTORE_REPORT_BYTES) {
                    throw new LimitExceededException('restore report bytes exceed limit');
                }
                $events[] = new RestoreEvent($token->entity, $token->start, $token->end, $raw, $restored);
                $eventCount++;
                $reportBytes += $evBytes;
                $last = $token->end;
            }
        }
        $append(\substr($text, $last));

        return new RestoreResult($out, $events);
    }

    /**
     * Return a streaming restorer bound to this session for placeholder lookup.
     * Each StreamRestorer withholds its own tail buffer; multiple may bind to
     * one session (restore only reads the mapping).
     */
    public function streamRestorer(): StreamRestorer
    {
        return new StreamRestorer($this);
    }
}
