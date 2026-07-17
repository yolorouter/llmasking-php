<?php

// src/Internal/Boundary.php

namespace Yolorouter\Llmasking\Internal;

/**
 * ASCII-only boundary helpers. "Boundary" here means reject a candidate when
 * an ASCII digit is immediately adjacent on either side, which prevents
 * matching a phone-shaped substring that is really part of a longer number.
 */
final class Boundary
{
    public static function hasAdjacentDigit(string $text, int $start, int $end): bool
    {
        if ($start > 0 && Validate::isDigit($text[$start - 1])) {
            return true;
        }
        if ($end < \strlen($text) && Validate::isDigit($text[$end])) {
            return true;
        }
        return false;
    }
}
