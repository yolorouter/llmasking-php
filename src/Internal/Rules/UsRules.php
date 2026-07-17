<?php

// src/Internal/Rules/UsRules.php

namespace Yolorouter\Llmasking\Internal\Rules;

use Yolorouter\Llmasking\{EntityType, Region, RegexPattern, RuleSpec};
use Yolorouter\Llmasking\Internal\{RuleRecognizer, Validate};

/**
 * Built-in United States (Region::US) recognizers. Patterns follow spec
 * section 8: ASCII character classes, explicit [0-9] over \d. The SSN rule
 * pairs a structural PCRE with the SSA validity predicate (rejecting area
 * 000/666/9xx, group 00, serial 0000). Each factory returns a fresh
 * RuleRecognizer instance.
 */
final class UsRules
{
    public static function usSsn(): RuleRecognizer
    {
        return new RuleRecognizer('us_ssn', new RuleSpec(
            EntityType::SSN,
            Region::US,
            RegexPattern::compile('/[0-9]{3}-[0-9]{2}-[0-9]{4}/'),
            Validate::ssnValid(...),
            0.85,
            true,
        ));
    }

    public static function usPhone(): RuleRecognizer
    {
        return new RuleRecognizer('us_phone', new RuleSpec(
            EntityType::PHONE,
            Region::US,
            RegexPattern::compile('/\([0-9]{3}\) [0-9]{3}-[0-9]{4}/'),
            null,
            0.7,
            true,
        ));
    }
}
