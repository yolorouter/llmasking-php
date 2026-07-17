<?php

// src/Internal/Rules/CnRules.php

namespace Yolorouter\Llmasking\Internal\Rules;

use Yolorouter\Llmasking\{EntityType, Region, RegexPattern, RuleSpec};
use Yolorouter\Llmasking\Internal\{RuleRecognizer, Validate};

/**
 * Built-in China (Region::CN) recognizers. Patterns follow spec section 8:
 * ASCII character classes, explicit [0-9] over \d. The ID-card rule pairs a
 * structural PCRE with the ISO 7064 MOD 11-2 checksum predicate. Each factory
 * returns a fresh RuleRecognizer instance.
 */
final class CnRules
{
    public static function chinaPhone(): RuleRecognizer
    {
        return new RuleRecognizer('china_phone', new RuleSpec(
            EntityType::PHONE,
            Region::CN,
            RegexPattern::compile('/1[3-9][0-9]{9}/'),
            null,
            0.7,
            true,
        ));
    }

    public static function chinaIdCard(): RuleRecognizer
    {
        return new RuleRecognizer('china_idcard', new RuleSpec(
            EntityType::IDCARD,
            Region::CN,
            RegexPattern::compile('/[1-9][0-9]{16}[0-9Xx]/'),
            Validate::chinaIdChecksum(...),
            0.95,
            true,
        ));
    }

    public static function landline(): RuleRecognizer
    {
        return new RuleRecognizer('china_landline', new RuleSpec(
            EntityType::LANDLINE,
            Region::CN,
            RegexPattern::compile('/0[0-9]{2,3}-[0-9]{7,8}/'),
            null,
            0.7,
            true,
        ));
    }
}
