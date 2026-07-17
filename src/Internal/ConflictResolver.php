<?php

// src/Internal/ConflictResolver.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Finding;
use Yolorouter\Llmasking\Exception\{InvalidFindingException, LimitExceededException};

/**
 * Resolves overlapping raw candidates into a non-overlapping, leftmost-sorted
 * list of Findings. Mirrors Go's internal/conflict: a stable priority sort
 * (SECRET family wins; then longer span; then higher score; then lower
 * recognizer registration index) followed by a greedy insert that drops any
 * candidate overlapping an already-accepted span. All input candidates are
 * validated (span in range, score in [0,1], text matches the span, offsets sit
 * on UTF-8 code-point boundaries) before resolution begins. The raw-candidate
 * cap (3000) is owned by Pcre and referenced from here. The SECRET family set
 * is defined once on Reversibility and reused here so Engine and the resolver
 * cannot drift.
 */
final class ConflictResolver
{
    /** Hard upper bound on raw candidate count per resolve() call (spec 4.4). */
    public const RAW_CAP = Pcre::RAW_CANDIDATE_CAP;

    /**
     * Validate every candidate, then greedily select a non-overlapping subset
     * using the stable priority order described in the class docblock.
     *
     * @param list<Candidate> $candidates
     * @return list<Finding> non-overlapping, sorted by start offset
     */
    public static function resolve(array $candidates, string $text): array
    {
        if (\count($candidates) > self::RAW_CAP) {
            throw new LimitExceededException('raw candidate count exceeds ' . self::RAW_CAP);
        }
        foreach ($candidates as $c) {
            self::validate($c->finding, $text);
        }

        // Stable sort by priority DESC: SECRET first, then longer span, then
        // higher score, then lower recognizerIndex. The comparator returns 0
        // only when every tiebreaker ties (genuinely equal priority), and the
        // non-overlap check is order-independent for disjoint spans, so the
        // greedy walk below is deterministic.
        $arr = $candidates;
        \usort($arr, static function (Candidate $a, Candidate $b): int {
            $as = Reversibility::isSecret($a->finding->entity);
            $bs = Reversibility::isSecret($b->finding->entity);
            if ($as !== $bs) {
                return $as ? -1 : 1;
            }
            $la = $a->finding->end - $a->finding->start;
            $lb = $b->finding->end - $b->finding->start;
            if ($la !== $lb) {
                return $lb <=> $la; // longer span first
            }
            if ($a->finding->score !== $b->finding->score) {
                return $b->finding->score <=> $a->finding->score; // higher score first
            }
            return $a->recognizerIndex <=> $b->recognizerIndex; // earlier recognizer first
        });

        // Greedy insert into $accepted, kept sorted by start. A candidate is
        // accepted iff it overlaps neither neighbor. Overlap is checked on the
        // half-open spans [start, end): two spans [a0,a1) and [b0,b1) overlap
        // iff a0 < b1 && b0 < a1. Adjoining spans ([0,5) and [5,10)) do NOT.
        /** @var list<Candidate> $accepted */
        $accepted = []; // sorted by finding->start ascending
        foreach ($arr as $cand) {
            $pos = self::lowerBound($accepted, $cand->finding->start);
            $pred = $pos > 0 ? $accepted[$pos - 1] ?? null : null;
            if ($pred !== null && $pred->finding->end > $cand->finding->start) {
                continue; // overlaps the predecessor
            }
            $succ = $accepted[$pos] ?? null;
            if ($succ !== null && $succ->finding->start < $cand->finding->end) {
                continue; // overlaps the successor
            }
            \array_splice($accepted, $pos, 0, [$cand]);
        }

        return \array_map(static fn (Candidate $c): Finding => $c->finding, $accepted);
    }

    /**
     * Binary search for the leftmost index in $accepted (sorted by start)
     * whose finding->start is >= $start.
     *
     * @param list<Candidate> $accepted
     */
    private static function lowerBound(array $accepted, int $start): int
    {
        $lo = 0;
        $hi = \count($accepted);
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi) / 2);
            if ($accepted[$mid]->finding->start < $start) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Validate a single Finding against the source text. Throws on any span,
     * score, text, or UTF-8 boundary violation. This is the library's own
     * fail-closed check on its recognizer output; it is not API-parameter
     * validation.
     */
    private static function validate(Finding $f, string $text): void
    {
        $len = \strlen($text);
        if ($f->start < 0 || $f->end <= $f->start || $f->end > $len) {
            throw new InvalidFindingException(
                "invalid span [{$f->start},{$f->end}) for length {$len}",
            );
        }
        if (!Validate::scoreInUnitRange($f->score)) {
            throw new InvalidFindingException('score out of range [0,1]');
        }
        if ($f->text !== \substr($text, $f->start, $f->end - $f->start)) {
            throw new InvalidFindingException('text does not match span');
        }
        // UTF-8 code-point boundary check: start and end must not land inside a
        // multi-byte codepoint (i.e. on a 10xxxxxx continuation byte).
        self::assertBoundary($text, $f->start);
        self::assertBoundary($text, $f->end);
    }

    /**
     * Throw if $offset splits a UTF-8 codepoint. A leading byte (0xxxxxxx,
     * 11xxxxxx) or EOF is a valid boundary; a continuation byte (10xxxxxx) is
     * not. Offset 0 and offset == strlen are always valid.
     */
    private static function assertBoundary(string $text, int $offset): void
    {
        if ($offset === 0 || $offset === \strlen($text)) {
            return;
        }
        $byte = \ord($text[$offset]);
        if (($byte & 0xC0) === 0x80) {
            throw new InvalidFindingException("offset {$offset} splits a UTF-8 codepoint");
        }
    }
}
