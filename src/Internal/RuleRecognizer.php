<?php

// src/Internal/RuleRecognizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{Finding, Recognizer, Region, RuleSpec};
use Yolorouter\Llmasking\Exception\InvalidFindingException;

/**
 * Recognizer driven declaratively by a RuleSpec. Tagged with a Region via
 * region() so Engine can apply WithRegions filtering; the Region is not part
 * of the public Recognizer interface (spec section 3.4), mirroring Go's
 * unexported regionTagged. Engine detects geo filtering via instanceof
 * RuleRecognizer. Pcre::eachMatch enforces the raw-candidate cap directly as
 * a LimitExceededException, so no message-sniffing reclassification is needed.
 */
final class RuleRecognizer implements Recognizer
{
    public function __construct(
        private readonly string $name,
        private readonly RuleSpec $spec,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function region(): Region
    {
        return $this->spec->region;
    }

    /**
     * @return list<Finding>
     */
    public function recognize(string $text): array
    {
        $matches = Pcre::eachMatch($this->spec->pattern->value(), $text);
        $findings = [];
        foreach ($matches as [$start, $end, $match]) {
            if ($this->spec->boundary && Boundary::hasAdjacentDigit($text, $start, $end)) {
                continue;
            }
            if ($this->spec->validate !== null) {
                // Spec 3.4: validate must be a pure, non-failing predicate whose
                // return is strictly bool. A non-bool result (or a thrown error)
                // fails the whole call closed instead of being silently coerced.
                $valid = ($this->spec->validate)($match);
                if (!\is_bool($valid)) {
                    throw new InvalidFindingException(
                        'recognizer "' . $this->name . '" validate returned a non-boolean',
                    );
                }
                if (!$valid) {
                    continue;
                }
            }
            $findings[] = new Finding($this->spec->entity, $start, $end, $this->spec->baseScore, $match);
        }
        return $findings;
    }
}
