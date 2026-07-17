<?php

// src/RestoreResult.php

namespace Yolorouter\Llmasking;

/** Immutable result of Session::restore: restored text plus the list of restore events. */
final class RestoreResult
{
    /**
     * @param list<RestoreEvent> $events
     */
    public function __construct(
        public readonly string $text,
        public readonly array $events,
    ) {
    }
}
