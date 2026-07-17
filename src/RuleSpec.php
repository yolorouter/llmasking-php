<?php

// src/RuleSpec.php

namespace Yolorouter\Llmasking;

use Closure;

/**
 * Declarative description of one recognition rule: entity type, geographic
 * region, the compiled PCRE, an optional pure validation predicate on the
 * matched substring, a base score in [0,1], and whether ASCII-digit boundary
 * enforcement is active.
 */
final class RuleSpec
{
    /**
     * @param ?Closure(string): bool $validate pure predicate over the matched bytes
     */
    public function __construct(
        public readonly string $entity,
        public readonly Region $region,
        public readonly RegexPattern $pattern,
        public readonly ?Closure $validate,
        public readonly float $baseScore,
        public readonly bool $boundary,
    ) {
    }
}
