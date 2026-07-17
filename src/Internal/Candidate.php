<?php

// src/Internal/Candidate.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Finding;

/**
 * Internal pairing of a Finding with the registration-order index of the
 * recognizer that produced it. Conflict resolution uses recognizerIndex as the
 * final stable tiebreaker so two equal-priority findings keep the recognizer
 * declaration order rather than an arbitrary order.
 */
final class Candidate
{
    public function __construct(
        public readonly Finding $finding,
        public readonly int $recognizerIndex,
    ) {
    }
}
