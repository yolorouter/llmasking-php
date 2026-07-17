<?php

// src/AnonymizeResult.php

namespace Yolorouter\Llmasking;

/** Immutable result of Session::anonymize: masked text plus the list of mask events. */
final class AnonymizeResult
{
    /**
     * @param list<MaskEvent> $events
     */
    public function __construct(
        public readonly string $text,
        public readonly array $events,
    ) {
    }
}
