<?php

// src/Recognizers.php

namespace Yolorouter\Llmasking;

use Yolorouter\Llmasking\Exception\InvalidConfigException;
use Yolorouter\Llmasking\Internal\{MultiRecognizer, RuleRecognizer, Validate};
use Yolorouter\Llmasking\Internal\Rules\{CnRules, SecretRules, UniversalRules, UsRules};

/**
 * Static factories returning process-level singleton built-in recognizers
 * (spec section 3.4). Each built-in factory caches its instance in a private
 * static property so repeated calls return the same object. The rule() factory
 * builds a fresh custom RuleRecognizer per call (it accepts user input).
 */
final class Recognizers
{
    /** @var array<string, Recognizer> */
    private static array $singletons = [];

    public static function email(): Recognizer
    {
        return self::$singletons['email'] ??= UniversalRules::email();
    }

    public static function bankCard(): Recognizer
    {
        return self::$singletons['bank_card'] ??= UniversalRules::bankCard();
    }

    public static function ip(): Recognizer
    {
        return self::$singletons['ip'] ??= UniversalRules::ip();
    }

    public static function url(): Recognizer
    {
        return self::$singletons['url'] ??= UniversalRules::url();
    }

    public static function intlPhone(): Recognizer
    {
        return self::$singletons['intl_phone'] ??= UniversalRules::intlPhone();
    }

    public static function chinaPhone(): Recognizer
    {
        return self::$singletons['china_phone'] ??= CnRules::chinaPhone();
    }

    public static function chinaIdCard(): Recognizer
    {
        return self::$singletons['china_idcard'] ??= CnRules::chinaIdCard();
    }

    public static function landline(): Recognizer
    {
        return self::$singletons['china_landline'] ??= CnRules::landline();
    }

    public static function usSsn(): Recognizer
    {
        return self::$singletons['us_ssn'] ??= UsRules::usSsn();
    }

    public static function usPhone(): Recognizer
    {
        return self::$singletons['us_phone'] ??= UsRules::usPhone();
    }

    public static function secret(): Recognizer
    {
        return self::$singletons['secret'] ??= new MultiRecognizer('secret', [
            SecretRules::cloudKey(),
            SecretRules::privateKey(),
            SecretRules::jwt(),
            SecretRules::gitToken(),
            SecretRules::entropyPassword(),
        ]);
    }

    /**
     * Build a custom RuleRecognizer. Validates the recognizer name (non-empty,
     * valid UTF-8) and the RuleSpec base score (finite, in [0,1]) up front so a
     * bad configuration fails at construction with InvalidConfigException
     * rather than surfacing late during recognition (spec 3.4: PHP must reject
     * a NaN baseScore that Go accepts).
     */
    public static function rule(string $name, RuleSpec $spec): Recognizer
    {
        if ($name === '' || !\mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidConfigException('recognizer name must be non-empty valid UTF-8');
        }
        if (!Validate::scoreInUnitRange($spec->baseScore)) {
            throw new InvalidConfigException('RuleSpec baseScore must be a finite number in [0,1]');
        }
        return new RuleRecognizer($name, $spec);
    }
}
