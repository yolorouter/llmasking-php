<?php

// src/Internal/Rules/SecretRules.php

namespace Yolorouter\Llmasking\Internal\Rules;

use Yolorouter\Llmasking\{EntityType, Recognizer, Region, RegexPattern, RuleSpec};
use Yolorouter\Llmasking\Internal\{EntropyPasswordRecognizer, PrivateKeyRecognizer, RuleRecognizer};

/**
 * Built-in SECRET-family recognizers (spec sections 4.2/4.3/8.3). CloudKey, Jwt,
 * and GitToken are PCRE-driven RuleRecognizers. PrivateKey uses a deterministic
 * BEGIN/END byte scanner (no cross-block PCRE). EntropyPassword uses a
 * keyword-anchored PCRE plus a Shannon-entropy gate. Every factory returns a
 * fresh recognizer instance; the public Recognizers facade caches singletons.
 */
final class SecretRules
{
    public static function cloudKey(): RuleRecognizer
    {
        return new RuleRecognizer('cloud_key', new RuleSpec(
            EntityType::CLOUDKEY,
            Region::Universal,
            RegexPattern::compile('/(?<![A-Za-z0-9_])(?:AKIA[0-9A-Z]{16}|LTAI[0-9A-Za-z]{12,20}|AKID[0-9A-Za-z]{12,20})(?![A-Za-z0-9_])/'),
            null,
            0.95,
            false,
        ));
    }

    public static function jwt(): RuleRecognizer
    {
        return new RuleRecognizer('jwt', new RuleSpec(
            EntityType::JWT,
            Region::Universal,
            RegexPattern::compile('/eyJ[A-Za-z0-9_-]+\.eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/'),
            null,
            0.95,
            false,
        ));
    }

    public static function gitToken(): RuleRecognizer
    {
        // glpat- body uses [0-9A-Za-z_-]{20} so the last byte may be '-' (a
        // non-word byte). A uniform trailing (?![A-Za-z0-9_]) would be INVERTED
        // vs Go \b semantics for that case: a '-'-terminated token at EOF or
        // before a non-word byte would wrongly match, and before a word byte
        // would wrongly fail (spec section 8.3 forbids the uniform form). The
        // trailing boundary is therefore byte-conditional, mirroring Go \b:
        //   - if the last consumed byte is a word byte [A-Za-z0-9_], the next
        //     byte must be non-word or absent: (?<=[A-Za-z0-9_])(?![A-Za-z0-9_])
        //   - if the last consumed byte is '-', the next byte must be a word
        //     byte: (?<=-)(?=[A-Za-z0-9_])
        // ghp_/gho_ bodies are [0-9A-Za-z]{36} (always word-terminated), so only
        // the first alternative ever fires for them; glpat- picks the right one.
        return new RuleRecognizer('git_token', new RuleSpec(
            EntityType::GITTOKEN,
            Region::Universal,
            RegexPattern::compile('/(?<![A-Za-z0-9_])(?:ghp_[0-9A-Za-z]{36}|gho_[0-9A-Za-z]{36}|glpat-[0-9A-Za-z_-]{20})(?:(?<=[A-Za-z0-9_])(?![A-Za-z0-9_])|(?<=-)(?=[A-Za-z0-9_]))/'),
            null,
            0.95,
            false,
        ));
    }

    public static function privateKey(): Recognizer
    {
        return new PrivateKeyRecognizer();
    }

    public static function entropyPassword(): Recognizer
    {
        return new EntropyPasswordRecognizer();
    }
}
