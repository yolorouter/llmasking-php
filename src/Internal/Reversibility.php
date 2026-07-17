<?php

// src/Internal/Reversibility.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{EntityType, Strategy};

/**
 * Single source of truth for strategy reversibility and the SECRET family.
 *
 * A strategy is reversible iff it IS the built-in Placeholder singleton. This
 * is an identity check (===), not instanceof and not the shape of the output:
 * a custom Strategy that happens to emit "[PHONE_1]" is never reversible, and
 * because CanonicalStrategy's constructor is private no caller can forge the
 * placeholder singleton.
 *
 * The SECRET set lives here so ConflictResolver (priority sort) and Engine
 * (strategy assignment + Placeholder rejection) share one definition.
 */
final class Reversibility
{
    /**
     * SECRET family entity types. These always win conflict resolution against
     * any non-SECRET candidate regardless of length or score, default to the
     * non-reversible Redact strategy, and can never be configured to use the
     * reversible Placeholder strategy.
     */
    public const SECRET = [
        EntityType::CLOUDKEY => 1,
        EntityType::PRIVATEKEY => 1,
        EntityType::JWT => 1,
        EntityType::GITTOKEN => 1,
        EntityType::SECRET => 1,
    ];

    public static function isReversible(Strategy $s): bool
    {
        return $s === CanonicalStrategy::placeholder();
    }

    public static function isSecret(string $entity): bool
    {
        return isset(self::SECRET[$entity]);
    }
}
