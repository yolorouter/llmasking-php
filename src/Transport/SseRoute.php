<?php

// src/Transport/SseRoute.php

namespace Yolorouter\Llmasking\Transport;

use Yolorouter\Llmasking\StreamRestorer;

/**
 * Internal per-route state for {@see SseRestorer}. One route corresponds to a
 * single logical stream identified by its (target kind, choice routing key,
 * optional tool routing key) tuple. Each route owns one StreamRestorer
 * generation; when the route is flushed (finish_reason / blanket flush) and
 * later data arrives for the same routing key, a new generation is created.
 *
 * The raw choice/tool index / id / function.name / custom.name tokens saved
 * on first creation are reused verbatim by synthesized flush frames: internal
 * ordinals or array positions are NEVER written back as numeric indices
 * (spec §9.5).
 *
 * @internal
 */
final class SseRoute
{
    public function __construct(
        public readonly string $routeKey,
        public readonly string $choiceKey,
        public readonly ?string $toolKey,
        public readonly int $ordinal,
        public readonly string $target,
        public StreamRestorer $restorer,
        public ?string $choiceIndexToken = null,
        public ?string $toolIndexToken = null,
        public ?string $toolIdToken = null,
        public ?string $functionNameToken = null,
        public ?string $customNameToken = null,
        public bool $flushed = false,
        /** Observed SSE line-ending style ("\n" / "\r\n" / "\r"); reused by synthesized flush frames. */
        public ?string $lineEnding = null,
    ) {
    }
}
