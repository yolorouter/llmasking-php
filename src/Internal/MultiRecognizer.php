<?php

// src/Internal/MultiRecognizer.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\{Finding, Recognizer, Region};

/**
 * Bundles several recognizers under one name. recognize() concatenates each
 * sub-recognizer's findings in sub-declaration order, enforcing the same local
 * raw-candidate cap (spec section 4.4) incrementally so a bundle of many
 * high-match sub-recognizers cannot materialize far past the cap before the
 * driver's per-recognizer check runs. Tagged Universal via region() so Engine
 * geo-filtering (which checks instanceof MultiRecognizer) treats the whole
 * bundle as Universal and never drops it.
 */
final class MultiRecognizer implements Recognizer, RegionTagged
{
    /**
     * @param list<Recognizer> $subs
     */
    public function __construct(
        private readonly string $name,
        private readonly array $subs,
    ) {
    }

    public function name(): string
    {
        return $this->name;
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
        $all = [];
        foreach ($this->subs as $sub) {
            foreach ($sub->recognize($text) as $f) {
                $all[] = $f;
                Pcre::assertRawCap(\count($all));
            }
        }
        return $all;
    }
}
