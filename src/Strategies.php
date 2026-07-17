<?php

// src/Strategies.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Internal\CanonicalStrategy;

/**
 * Static factories returning process-level singleton built-in strategies.
 * Placeholder and Redact share CanonicalStrategy and produce the same token
 * text; Placeholder is reversible (Session records a mapping), Redact is not.
 * MaskMiddle and Hash are non-reversible by construction.
 */
final class Strategies
{
    private static ?Strategy $maskMiddle = null;

    private static ?Strategy $hash = null;

    public static function placeholder(): Strategy
    {
        return CanonicalStrategy::placeholder();
    }

    public static function redact(): Strategy
    {
        return CanonicalStrategy::redact();
    }

    public static function maskMiddle(): Strategy
    {
        return self::$maskMiddle ??= new class () implements Strategy {
            public function apply(Finding $f, int $sequence): string
            {
                // Operate on Unicode codepoints via mbstring (no PCRE); spans
                // of 7 codepoints or fewer are fully masked so no plaintext
                // leaks at either edge.
                $len = \mb_strlen($f->text, 'UTF-8');
                if ($len <= 7) {
                    return \str_repeat('*', $len);
                }
                return \mb_substr($f->text, 0, 3, 'UTF-8')
                    . \str_repeat('*', $len - 7)
                    . \mb_substr($f->text, -4, null, 'UTF-8');
            }
        };
    }

    public static function hash(): Strategy
    {
        return self::$hash ??= new class () implements Strategy {
            public function apply(Finding $f, int $sequence): string
            {
                return \substr(\hash('sha256', $f->text), 0, 8);
            }
        };
    }
}
