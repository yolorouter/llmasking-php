<?php

// src/Internal/KeywordRecognizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{EntityType, Finding, Recognizer, Region};

/**
 * Recognizer backed by the bounded-window KeywordMatcher (spec section 7).
 * Emits one Finding per non-overlapping LeftMostLongest match with entity
 * EntityType::KEYWORD and score 1.0. Registered under the reserved name
 * "keyword"; Engine appends it after the built-in / custom recognizer list so
 * the region filter (which applies to RuleRecognizer / MultiRecognizer only)
 * never drops it. The region() method returns Universal for documentation and
 * for any future code path that consults it directly.
 *
 * @internal
 */
final class KeywordRecognizer implements Recognizer, RegionTagged
{
    /** The reserved recognizer name for keyword matching (single source). */
    public const NAME = 'keyword';

    private readonly KeywordMatcher $matcher;

    /**
     * @param KeywordMatcher $matcher the validated keyword matcher (Engine
     *                                builds it so this class is a pure adapter,
     *                                not a factory that owns validation)
     */
    public function __construct(KeywordMatcher $matcher)
    {
        $this->matcher = $matcher;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function region(): Region
    {
        return Region::Universal;
    }

    /**
     * @return list<Finding>
     */
    public function recognize(string $text): array
    {
        $findings = [];
        foreach ($this->matcher->findLeftmostLongest($text) as $m) {
            $findings[] = new Finding(
                EntityType::KEYWORD,
                $m->start,
                $m->end,
                1.0,
                // The matched bytes are byte-identical to the configured pattern
                // (the AC match IS the pattern), so return it by COW refcount
                // bump instead of substr()-copying out of $text.
                $this->matcher->pattern($m->patternIndex),
            );
        }
        return $findings;
    }
}
