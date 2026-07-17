<?php

// src/Internal/Rules/UniversalRules.php

namespace Yolorouter\Llmasking\Internal\Rules;

use Yolorouter\Llmasking\{EntityType, Region, RegexPattern, RuleSpec};
use Yolorouter\Llmasking\Internal\{RuleRecognizer, Validate};

/**
 * Built-in universal (Region::Universal) recognizers shared by every engine
 * configuration. Patterns follow spec section 8: ASCII character classes,
 * explicit [0-9] over \d, and \s expanded to the ASCII whitespace set
 * [ \t\n\f\r]. Each factory returns a fresh RuleRecognizer instance.
 */
final class UniversalRules
{
    public static function email(): RuleRecognizer
    {
        return new RuleRecognizer('email', new RuleSpec(
            EntityType::EMAIL,
            Region::Universal,
            RegexPattern::compile('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/'),
            null,
            0.9,
            false,
        ));
    }

    public static function bankCard(): RuleRecognizer
    {
        return new RuleRecognizer('bank_card', new RuleSpec(
            EntityType::BANKCARD,
            Region::Universal,
            RegexPattern::compile('/[0-9]{13,19}/'),
            Validate::luhn(...),
            0.9,
            true,
        ));
    }

    public static function ip(): RuleRecognizer
    {
        // Preserve Go RE2 lexical behavior: '00' matches, '001' does not.
        $seg = '(?:25[0-5]|2[0-4][0-9]|1?[0-9]?[0-9])';
        return new RuleRecognizer('ip', new RuleSpec(
            EntityType::IP,
            Region::Universal,
            RegexPattern::compile('/(?<![A-Za-z0-9_])' . $seg . '(?:\.' . $seg . '){3}(?![A-Za-z0-9_])/'),
            null,
            0.7,
            false,
        ));
    }

    public static function url(): RuleRecognizer
    {
        // Go \s equals ASCII [ \t\n\f\r]; expand it rather than relying on
        // PCRE's Unicode-aware whitespace class.
        return new RuleRecognizer('url', new RuleSpec(
            EntityType::URL,
            Region::Universal,
            RegexPattern::compile('/https?:\/\/[^\x20\x09\x0a\x0c\x0d]+/'),
            null,
            0.8,
            false,
        ));
    }

    public static function intlPhone(): RuleRecognizer
    {
        return new RuleRecognizer('intl_phone', new RuleSpec(
            EntityType::PHONE,
            Region::Universal,
            RegexPattern::compile('/\+[1-9][0-9]{5,14}/'),
            null,
            0.8,
            true,
        ));
    }
}
