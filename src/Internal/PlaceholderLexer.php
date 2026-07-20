<?php

// src/Internal/PlaceholderLexer.php

namespace Yolorouter\Llmasking\Internal;

/**
 * Linear byte-coded placeholder lexer, shared by Session::restore (one-shot)
 * and StreamRestorer (incremental). It is STATEFUL: Session::restore feeds the
 * whole text at once; StreamRestorer feeds one chunk per write() and the DFA
 * state carries over, so every byte is processed exactly once (O(n) over the
 * whole stream — no re-scan of a growing withheld buffer).
 *
 * Grammar (OPEN and CLOSE are each independently optional, never required to
 * pair, so an unmatched bracket is part of the token, not literal text):
 *
 *   token  := OPEN? ENTITY "_" DIGITS+ CLOSE?
 *   OPEN   := "[" | "\xE3\x80\x90"   ('【')
 *   CLOSE  := "]" | "\xE3\x80\x91"   ('】')
 *   ENTITY := [A-Z][A-Z0-9]*
 *   DIGITS := [0-9]+
 *
 * A multibyte OPEN/CLOSE or UTF-8 codepoint split across a feed boundary is
 * held in $held and decided once its remaining bytes arrive; at EOF (atEof)
 * nothing is held, and a DIGITS candidate with no CLOSE finalizes as a token
 * (CLOSE is optional).
 */
final class PlaceholderLexer
{
    private const S_LITERAL = 0;
    private const S_AFTER_OPEN = 1;
    private const S_ENTITY = 2;
    private const S_UNDERSCORE = 3;
    private const S_DIGITS = 4;

    private int $state = self::S_LITERAL;

    /** Absolute offset where the current in-progress candidate began. */
    private int $candStart = 0;

    private string $entity = '';

    private string $digits = '';

    /** Absolute offset of the next byte to consume. */
    private int $offset = 0;

    /** Bytes held for a multibyte lookahead split across a feed boundary. */
    private string $held = '';

    public function __construct(private readonly int $maxSeqDigits)
    {
    }

    /**
     * One-shot scan over a complete (valid UTF-8) string. A DIGITS candidate
     * reaching EOF with no CLOSE is finalized as a complete token.
     *
     * @return list<PlaceholderToken>
     */
    public static function scan(string $text, int $maxSeqDigits): array
    {
        $lex = new self($maxSeqDigits);

        return $lex->feed($text, true);
    }

    /**
     * Feed bytes and return the tokens completed by them. The in-progress
     * candidate and any partial trailing multibyte stay in state for the next
     * feed. At $atEof, finalize: a DIGITS candidate completes (CLOSE optional).
     *
     * @return list<PlaceholderToken>
     */
    public function feed(string $chunk, bool $atEof): array
    {
        $bytes = $this->held . $chunk;
        $this->held = '';
        $n = \strlen($bytes);
        $tokens = [];
        $i = 0;
        while ($i < $n) {
            $b = $bytes[$i];
            // A leading 0xE3 with too few bytes to decide (a fullwidth bracket
            // or another multibyte) is withheld regardless of state, so a split
            // bracket matches one-shot; state-specific \xE3 handling only runs
            // when all 3 bytes are present or at EOF.
            if ($b === "\xE3" && $i + 2 >= $n && !$atEof) {
                $this->held = \substr($bytes, $i);
                break;
            }
            if ($this->state === self::S_LITERAL) {
                if ($b === '[') {
                    $this->state = self::S_AFTER_OPEN;
                    $this->candStart = $this->offset;
                    $this->entity = '';
                    $i++;
                    $this->offset++;
                } elseif ($b === "\xE3" && $i + 2 < $n && $bytes[$i + 1] === "\x80" && $bytes[$i + 2] === "\x90") {
                    $this->state = self::S_AFTER_OPEN;
                    $this->candStart = $this->offset;
                    $this->entity = '';
                    $i += 3;
                    $this->offset += 3;
                } elseif ($b >= 'A' && $b <= 'Z') {
                    $this->state = self::S_ENTITY;
                    $this->candStart = $this->offset;
                    $this->entity = $b;
                    $i++;
                    $this->offset++;
                } else {
                    $i++;
                    $this->offset++; // literal byte
                }
            } elseif ($this->state === self::S_AFTER_OPEN) {
                if ($b >= 'A' && $b <= 'Z') {
                    $this->state = self::S_ENTITY;
                    $this->entity = $b;
                    $i++;
                    $this->offset++;
                } else {
                    // The OPEN was literal; reprocess $b from LITERAL.
                    $this->resetCandidate();
                }
            } elseif ($this->state === self::S_ENTITY) {
                if (($b >= 'A' && $b <= 'Z') || ($b >= '0' && $b <= '9')) {
                    $this->entity .= $b;
                    $i++;
                    $this->offset++;
                } elseif ($b === '_') {
                    $this->state = self::S_UNDERSCORE;
                    $i++;
                    $this->offset++;
                } else {
                    $this->resetCandidate(); // reprocess $b
                }
            } elseif ($this->state === self::S_UNDERSCORE) {
                if ($b >= '0' && $b <= '9') {
                    $this->state = self::S_DIGITS;
                    $this->digits = $b;
                    $i++;
                    $this->offset++;
                } else {
                    $this->resetCandidate(); // reprocess $b
                }
            } else { // self::S_DIGITS
                if ($b >= '0' && $b <= '9') {
                    $this->digits .= $b;
                    $i++;
                    $this->offset++;
                } elseif ($b === ']') {
                    $tokens[] = $this->makeToken($this->candStart, $this->offset + 1);
                    $this->resetCandidate();
                    $i++;
                    $this->offset++;
                } elseif ($b === "\xE3" && $i + 2 < $n && $bytes[$i + 1] === "\x80" && $bytes[$i + 2] === "\x91") {
                    $tokens[] = $this->makeToken($this->candStart, $this->offset + 3);
                    $this->resetCandidate();
                    $i += 3;
                    $this->offset += 3;
                } else {
                    // No CLOSE: token ends at the digits; reprocess $b.
                    $tokens[] = $this->makeToken($this->candStart, $this->offset);
                    $this->resetCandidate();
                }
            }
        }

        if ($atEof) {
            if ($this->state === self::S_DIGITS) {
                $tokens[] = $this->makeToken($this->candStart, $this->offset);
            }
            $this->resetCandidate();
            $this->held = '';
        }
        return $tokens;
    }

    /**
     * Finalize the stream (CLOSE-optional EOF semantics).
     *
     * @return list<PlaceholderToken>
     */
    public function finish(): array
    {
        return $this->feed('', true);
    }

    /**
     * Absolute offset up to which input is finally decided (committed as
     * literal or part of a completed token). Bytes at/after this point are
     * still in the in-progress candidate and must be withheld by the caller.
     */
    public function committedOffset(): int
    {
        return $this->state === self::S_LITERAL ? $this->offset : $this->candStart;
    }

    /**
     * Bytes held in the partial-candidate buffers ($entity, $digits, $held).
     * A long run of upper-case or digit bytes (e.g. a placeholder entity that
     * never closes) accumulates here on top of the caller's withheld input, so
     * the SSE state budget must charge both to bound memory (spec §9.5 / codex).
     */
    public function retainedBytes(): int
    {
        return \strlen($this->entity) + \strlen($this->digits) + \strlen($this->held);
    }

    private function resetCandidate(): void
    {
        $this->state = self::S_LITERAL;
        $this->entity = '';
        $this->digits = '';
    }

    private function makeToken(int $start, int $end): PlaceholderToken
    {
        $seqRaw = $this->digits;
        $valid = self::seqValid($seqRaw, $this->maxSeqDigits);

        return new PlaceholderToken(
            $start,
            $end,
            $this->entity,
            $seqRaw,
            $valid ? (int) $seqRaw : 0,
            $valid,
        );
    }

    /** A digit run is usable only if its width is within the max-seq width and
     * its decimal value fits in a native int (compared as a string, never via
     * intval() saturation which would silently collapse over-long runs). */
    private static function seqValid(string $seqRaw, int $maxSeqDigits): bool
    {
        $width = \strlen($seqRaw);
        if ($width === 0 || $width > $maxSeqDigits) {
            return false;
        }
        $intMaxWidth = \strlen((string) PHP_INT_MAX);
        if ($width < $intMaxWidth) {
            return true;
        }
        if ($width > $intMaxWidth) {
            return false;
        }
        return \strcmp($seqRaw, (string) PHP_INT_MAX) <= 0;
    }
}
