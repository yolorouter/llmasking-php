<?php

// src/Internal/CanonicalStrategy.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{Finding, Strategy};

/**
 * Backs the Placeholder and Redact built-in strategies. Both render the
 * canonical "[ENTITY_n]" token; they differ only in whether the Session should
 * record a reversible mapping. The constructor is private and the two instances
 * are distinct process-level singletons, so Reversibility identifies the one
 * reversible strategy by reference equality (=== CanonicalStrategy::placeholder())
 * rather than by a field any caller could forge.
 */
final class CanonicalStrategy implements Strategy
{
    private static ?CanonicalStrategy $placeholder = null;

    private static ?CanonicalStrategy $redact = null;

    private function __construct()
    {
    }

    public static function placeholder(): self
    {
        return self::$placeholder ??= new self();
    }

    public static function redact(): self
    {
        return self::$redact ??= new self();
    }

    public function apply(Finding $f, int $sequence): string
    {
        return '[' . $f->entity . '_' . $sequence . ']';
    }
}
