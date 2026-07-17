<?php

// src/Internal/RegionTagged.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Region;

/**
 * Internal marker for recognizers that carry a geographic Region tag.
 * Implemented only by library-internal recognizers (RuleRecognizer,
 * MultiRecognizer, KeywordRecognizer); third-party Recognizer implementations
 * never implement it, so they are always treated as Universal — preserving
 * the encapsulation spec section 3.4 requires without a public marker interface.
 *
 * @internal
 */
interface RegionTagged
{
    public function region(): Region;
}
