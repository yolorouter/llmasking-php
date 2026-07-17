<?php

// src/RegexPattern.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Internal\Pcre;

/**
 * Immutable wrapper over a fully-delimited PCRE. compile() probe-compiles the
 * pattern once via the shared scoped Pcre::match wrapper (which converts any
 * delimiter/compile error or PCRE warning into RegexException and restores its
 * handler), then stores the pattern string for runtime use.
 */
final class RegexPattern
{
    private function __construct(private readonly string $pattern)
    {
    }

    public static function compile(string $delimitedPcre): self
    {
        // Probe-compile on an empty subject; Pcre::match raises RegexException
        // on any compile error or PCRE warning.
        Pcre::match($delimitedPcre, '');
        return new self($delimitedPcre);
    }

    public function value(): string
    {
        return $this->pattern;
    }
}
