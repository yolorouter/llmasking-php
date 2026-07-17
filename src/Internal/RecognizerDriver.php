<?php

// src/Internal/RecognizerDriver.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{Finding, Recognizer, Region};
use Yolorouter\Llmasking\Exception\{InvalidFindingException, LimitExceededException, UnknownEntityTypeException};

/**
 * Serial recognition driver. Runs each Recognizer in declaration order against
 * the input text, tags every emitted Finding with its recognizer's registration
 * index (the stable tiebreaker used by ConflictResolver), and rejects any
 * Finding whose entity is not in the engine's known-entity set. The local raw
 * cap (ConflictResolver::RAW_CAP) is enforced incrementally so a runaway
 * recognizer cannot exhaust memory before ConflictResolver runs.
 */
final class RecognizerDriver
{
    /**
     * Run every recognizer serially, accumulating Candidates.
     *
     * @param list<Recognizer> $recognizers   registration-ordered list
     * @param array<string,bool> $knownEntities entity names the engine accepts
     * @return list<Candidate>
     */
    public static function recognize(array $recognizers, string $text, array $knownEntities): array
    {
        $candidates = [];
        // Accumulate and cap FIRST. Spec 4.4: the raw-candidate cap has
        // error-priority over the per-finding entity-registration check, so a
        // recognizer that returns both too many findings AND an unknown entity
        // surfaces as LimitExceededException (not UnknownEntityTypeException).
        foreach ($recognizers as $index => $r) {
            $findings = $r->recognize($text);
            // Spec 4.3: a recognizer return must be a list of Finding. Reject an
            // associative/sparse array or a non-Finding item as a controlled
            // library exception instead of letting a TypeError escape later from
            // Candidate's typed constructor.
            if (!\array_is_list($findings)) {
                throw new InvalidFindingException('recognizer #' . $index . ' returned a non-list array');
            }
            foreach ($findings as $f) {
                if (!$f instanceof Finding) {
                    throw new InvalidFindingException('recognizer #' . $index . ' returned a non-Finding item');
                }
                // Spec 4.4: stop at the cap DURING accumulation (not after the
                // whole list), so the library never materializes far more than
                // RAW_CAP Candidate objects from a runaway recognizer.
                if (\count($candidates) >= ConflictResolver::RAW_CAP) {
                    throw new LimitExceededException(
                        'raw candidate count exceeds ' . ConflictResolver::RAW_CAP,
                    );
                }
                $candidates[] = new Candidate($f, $index);
            }
        }
        // Entity-registration (a 4.3 check) on ALL raw findings, after the cap.
        // Spec 4.3: an unregistered entity fails even if it would later be
        // displaced by a higher-priority candidate.
        foreach ($candidates as $c) {
            if (!isset($knownEntities[$c->finding->entity])) {
                // Sanitized message: never echo the untrusted entity value (a
                // custom recognizer could put arbitrary text there, which would
                // land in default exception logging). Report only the recognizer
                // index and the entity-name byte length.
                throw new UnknownEntityTypeException(
                    'unregistered entity type from recognizer #' . $c->recognizerIndex
                    . ' (entity name length ' . \strlen($c->finding->entity) . ' bytes)',
                );
            }
        }
        return $candidates;
    }

    /**
     * Return the Region tag of $r, or null for custom (Universal) recognizers.
     * Engine uses this to apply WithRegions filtering: a recognizer whose
     * Region is not Universal and not in the enabled set is dropped from the
     * pipeline. RuleRecognizer and MultiRecognizer expose region(); any other
     * Recognizer implementation is treated as Universal.
     */
    public static function regionOf(Recognizer $r): ?Region
    {
        if ($r instanceof RegionTagged) {
            return $r->region();
        }
        return null; // custom recognizers are Universal
    }
}
