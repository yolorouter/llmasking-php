<?php

// tests/Unit/Internal/Rules/SecretRulesTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal\Rules;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\EntityType;
use Yolorouter\Llmasking\Exception\{LimitExceededException, RegexException};
use Yolorouter\Llmasking\Internal\Rules\SecretRules;
use Yolorouter\Llmasking\Recognizers;

final class SecretRulesTest extends TestCase
{
    public function testCloudKey(): void
    {
        $r = SecretRules::cloudKey();
        self::assertSame('AKIAIOSFODNN7EXAMPLE', $r->recognize('k=AKIAIOSFODNN7EXAMPLE')[0]->text);
    }

    public function testRsaPrivateKeyBlock(): void
    {
        $r = SecretRules::privateKey();
        $text = "-----BEGIN RSA PRIVATE KEY-----\nAAA\n-----END RSA PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(1, $hits);
        self::assertSame($text, $hits[0]->text);
    }

    public function testEntropyPassword(): void
    {
        $r = SecretRules::entropyPassword();
        self::assertCount(1, $r->recognize('password: kQ7$mZ2!xR9p'));
        self::assertSame([], $r->recognize('password: 1234')); // low entropy, too short
    }

    public function testSecretBundlesAllSubs(): void
    {
        $r = Recognizers::secret();
        self::assertCount(1, $r->recognize('k=AKIAIOSFODNN7EXAMPLE'));
    }

    // --- Seven-family PEM scanner (spec section 10.2) ---

    public function testSevenFamilyScannerEachFamilyProducesFinding(): void
    {
        $families = [
            'PGP PRIVATE KEY BLOCK',
            'RSA PRIVATE KEY',
            'EC PRIVATE KEY',
            'OPENSSH PRIVATE KEY',
            'DSA PRIVATE KEY',
            'ENCRYPTED PRIVATE KEY',
            'PRIVATE KEY',
        ];
        $r = SecretRules::privateKey();
        foreach ($families as $family) {
            $text = "-----BEGIN {$family}-----\nAAA\n-----END {$family}-----";
            $hits = $r->recognize($text);
            self::assertCount(1, $hits, "family: {$family}");
            self::assertSame($text, $hits[0]->text, "family: {$family}");
            self::assertSame(EntityType::PRIVATEKEY, $hits[0]->entity, "family: {$family}");
        }
    }

    public function testMismatchedFenceYieldsNoFinding(): void
    {
        $r = SecretRules::privateKey();
        // BEGIN RSA ... END EC: no same-family END for RSA, no BEGIN for EC.
        $text = "-----BEGIN RSA PRIVATE KEY-----\nAAA\n-----END EC PRIVATE KEY-----";
        self::assertSame([], $r->recognize($text));
    }

    public function testIncompleteBeginDoesNotBlockLaterCompleteBlock(): void
    {
        $r = SecretRules::privateKey();
        // First BEGIN has no matching END; a later complete block must survive.
        $text = "-----BEGIN RSA PRIVATE KEY-----\ntruncated\n-----BEGIN OPENSSH PRIVATE KEY-----\nBBB\n-----END OPENSSH PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(1, $hits);
        self::assertStringContainsString('OPENSSH PRIVATE KEY', $hits[0]->text);
    }

    public function testTwoCompleteBlocksBothEmitted(): void
    {
        $r = SecretRules::privateKey();
        $text = "-----BEGIN RSA PRIVATE KEY-----\nAAA\n-----END RSA PRIVATE KEY-----\n-----BEGIN EC PRIVATE KEY-----\nBBB\n-----END EC PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(2, $hits);
        self::assertStringContainsString('RSA PRIVATE KEY', $hits[0]->text);
        self::assertStringContainsString('EC PRIVATE KEY', $hits[1]->text);
    }

    public function testPrivateKeyNoHeadersYieldsNothing(): void
    {
        $r = SecretRules::privateKey();
        self::assertSame([], $r->recognize('plain text with no PEM blocks at all'));
    }

    // --- Entropy password variants ---

    public function testQuotedEntropyValue(): void
    {
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('password: "kQ7$mZ2!xR9p"');
        self::assertCount(1, $hits);
        self::assertSame('"kQ7$mZ2!xR9p"', $hits[0]->text);
        self::assertSame(EntityType::SECRET, $hits[0]->entity);
    }

    public function testEntropyRejectsLowEntropyLongString(): void
    {
        // 20 bytes but only 2 distinct symbols -> entropy 1.0 < 3.0.
        $r = SecretRules::entropyPassword();
        self::assertSame([], $r->recognize('secret: abababababababababab'));
    }

    public function testEntropyNoKeywordYieldsNothing(): void
    {
        // High-entropy token but no context keyword.
        $r = SecretRules::entropyPassword();
        self::assertSame([], $r->recognize('kQ7$mZ2!xR9p'));
    }

    public function testEntropyKeywordEmbeddedInWordDoesNotFire(): void
    {
        // 'monkey:' must not trigger the 'key' keyword (letter precedes 'key').
        $r = SecretRules::entropyPassword();
        self::assertSame([], $r->recognize('monkey: kQ7$mZ2!xR9p'));
    }

    // --- JWT / GitToken / CloudKey variants ---

    public function testJwtShape(): void
    {
        $r = SecretRules::jwt();
        $token = 'eyJhbGci.eyJzdWI.SflKxwRJSMeKKF2QT4f';
        $hits = $r->recognize('token ' . $token . ' end');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
        self::assertSame(EntityType::JWT, $hits[0]->entity);
    }

    public function testGitTokenGhp(): void
    {
        $r = SecretRules::gitToken();
        $token = 'ghp_' . str_repeat('a', 36);
        $hits = $r->recognize('value ' . $token . ' end');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testGitTokenGlpatWithDashes(): void
    {
        $r = SecretRules::gitToken();
        $token = 'glpat-1234567890abcdefghij';
        $hits = $r->recognize(' ' . $token . ' ');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testCloudKeyLtaiVariant(): void
    {
        $r = SecretRules::cloudKey();
        $token = 'LTAI' . str_repeat('z', 16);
        $hits = $r->recognize(' ' . $token . ' ');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testCloudKeyAkidVariant(): void
    {
        $r = SecretRules::cloudKey();
        $token = 'AKID' . str_repeat('z', 16);
        $hits = $r->recognize(' ' . $token . ' ');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testCloudKeyBoundaryRejectsEmbedded(): void
    {
        $r = SecretRules::cloudKey();
        // Preceded by a letter -> lookbehind fails, no match.
        self::assertSame([], $r->recognize('xAKIAIOSFODNN7EXAMPLE'));
    }

    // --- Recognizers facade singleton caching (spec section 3.4) ---

    public function testRecognizersCachesSingletons(): void
    {
        self::assertSame(Recognizers::secret(), Recognizers::secret());
        self::assertSame(Recognizers::email(), Recognizers::email());
    }

    // ===== Blocking-finding coverage (spec section 10.2 + plan Step 4) =====

    // --- glpat- byte-conditional boundary (spec section 8.3) ---
    // glpat- body is [0-9A-Za-z_-]{20}: when the last body byte is '-', the
    // trailing boundary must mirror Go \b semantics, not a uniform (?![A-Za-z0-9_]).

    public function testGitTokenGlpatDashTerminatedAtEofRejected(): void
    {
        // Go \b: a '-'-terminated token at EOF has no boundary (non-word|void).
        $r = SecretRules::gitToken();
        $token = 'glpat-' . str_repeat('a', 19) . '-'; // body ends with '-'
        self::assertSame([], $r->recognize($token));
    }

    public function testGitTokenGlpatDashTerminatedBeforeSpaceRejected(): void
    {
        // Non-word '-' followed by non-word space -> no \b boundary.
        $r = SecretRules::gitToken();
        $token = 'glpat-' . str_repeat('a', 19) . '-';
        self::assertSame([], $r->recognize(' ' . $token . ' '));
    }

    public function testGitTokenGlpatDashTerminatedBeforeWordCharAccepted(): void
    {
        // Non-word '-' followed by word char -> IS a \b boundary.
        // The token is still the 20-byte body; the following word char is not
        // consumed.
        $r = SecretRules::gitToken();
        $body = str_repeat('a', 19) . '-';
        $token = 'glpat-' . $body;
        $hits = $r->recognize(' ' . $token . 'x');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testGitTokenGlpatWordTerminatedAtEofAccepted(): void
    {
        // Word-terminated token at EOF: boundary between word and void.
        $r = SecretRules::gitToken();
        $token = 'glpat-' . str_repeat('a', 20); // body ends with word byte
        $hits = $r->recognize($token);
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testGitTokenGlpatWordTerminatedBeforeSpaceAccepted(): void
    {
        $r = SecretRules::gitToken();
        $token = 'glpat-' . str_repeat('a', 20);
        $hits = $r->recognize(' ' . $token . ' ');
        self::assertCount(1, $hits);
        self::assertSame($token, $hits[0]->text);
    }

    public function testGitTokenGlpatWordTerminatedBeforeWordCharRejected(): void
    {
        // Word followed by word -> no boundary.
        $r = SecretRules::gitToken();
        $token = 'glpat-' . str_repeat('a', 20);
        self::assertSame([], $r->recognize(' ' . $token . 'x'));
    }

    // --- 1 MiB PEM input + performance regression (plan Step 4 / spec 10.2) ---

    public function testPrivateKeyOneMiBInputDetectedAndFast(): void
    {
        $r = SecretRules::privateKey();
        // Build a PEM block whose body is close to 1 MiB.
        $body = str_repeat('A', 1024 * 1024);
        $text = "-----BEGIN RSA PRIVATE KEY-----\n" . $body . "\n-----END RSA PRIVATE KEY-----";
        $start = \microtime(true);
        $hits = $r->recognize($text);
        $elapsed = \microtime(true) - $start;
        self::assertCount(1, $hits);
        self::assertSame($text, $hits[0]->text);
        // Deterministic linear scanner must process ~1 MiB well under 200 ms.
        self::assertLessThan(0.2, $elapsed, "PEM scanner took {$elapsed}s on 1 MiB input");
    }

    // --- Nested / overlapping BEGIN (spec section 4.2) ---

    public function testPrivateKeyNestedBeginOuterSpansInner(): void
    {
        $r = SecretRules::privateKey();
        // Outer RSA BEGIN wraps an inner EC BEGIN/END pair. Leftmost-first +
        // nearest same-family END means the RSA finding spans the whole input;
        // the inner EC BEGIN is consumed inside it (non-overlapping).
        $text = "-----BEGIN RSA PRIVATE KEY-----\n"
            . "-----BEGIN EC PRIVATE KEY-----\nBBB\n-----END EC PRIVATE KEY-----\n"
            . "-----END RSA PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(1, $hits);
        self::assertSame($text, $hits[0]->text);
    }

    public function testPrivateKeyOverlappingSameFamilySecondBeginInsideFirstFinding(): void
    {
        $r = SecretRules::privateKey();
        // Two RSA BEGINs before the RSA END: leftmost BEGIN pairs with nearest
        // same-family END, spanning the duplicate header.
        $text = "-----BEGIN RSA PRIVATE KEY-----\n"
            . "-----BEGIN RSA PRIVATE KEY-----\nAAA\n"
            . "-----END RSA PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(1, $hits);
        self::assertSame($text, $hits[0]->text);
    }

    // --- Cross-family END between BEGIN and its matching END (spec 4.2) ---

    public function testPrivateKeyCrossFamilyEndDoesNotTerminateOtherFamily(): void
    {
        $r = SecretRules::privateKey();
        // RSA BEGIN ... EC END (wrong family, ignored as content) ... RSA END.
        // The scanner finds the nearest same-family (RSA) END, which sits after
        // the cross-family EC END. The finding spans everything.
        $text = "-----BEGIN RSA PRIVATE KEY-----\nAAA\n"
            . "-----END EC PRIVATE KEY-----\n"
            . "-----END RSA PRIVATE KEY-----";
        $hits = $r->recognize($text);
        self::assertCount(1, $hits);
        self::assertSame($text, $hits[0]->text);
    }

    // --- Entropy keyword completeness (spec section 4.2) ---

    public function testEntropyApikeyKeyword(): void
    {
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('apikey: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame('kQ7$mZ2!xR9p', $hits[0]->text);
    }

    public function testEntropyApiKeyUnderscoreKeyword(): void
    {
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('api_key: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame('kQ7$mZ2!xR9p', $hits[0]->text);
    }

    public function testEntropyApiKeyDashKeyword(): void
    {
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('api-key: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame('kQ7$mZ2!xR9p', $hits[0]->text);
    }

    public function testEntropyCompoundSuffix(): void
    {
        // spec 4.2: optional [A-Z0-9_]* composite suffix after keyword.
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('password_ID: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame('kQ7$mZ2!xR9p', $hits[0]->text);

        $hits2 = $r->recognize('API_KEY_TOKEN: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits2);
        self::assertSame('kQ7$mZ2!xR9p', $hits2[0]->text);

        // Lowercase suffix on its own is not an uppercase composite suffix; the
        // keyword still matches, but 'passwordID' uses the [A-Z0-9_]* branch only
        // for uppercase. 'passwords:' has trailing lowercase 's' which is not a
        // valid suffix or separator, so it must not fire.
        self::assertSame([], $r->recognize('passwords: kQ7$mZ2!xR9p'));
    }

    public function testEntropyJsonQuotedKey(): void
    {
        // JSON-style quoted key: optional key right quote after composite suffix.
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('"api_key": kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame('kQ7$mZ2!xR9p', $hits[0]->text);
    }

    public function testEntropyScoreIsSpec07(): void
    {
        // spec section 4.2 table row 5 mandates score 0.7 for SECRET entropy.
        $r = SecretRules::entropyPassword();
        $hits = $r->recognize('password: kQ7$mZ2!xR9p');
        self::assertCount(1, $hits);
        self::assertSame(0.7, $hits[0]->score);
    }

    // --- Very-long secret (plan Step 4 explicitly requires this) ---

    public function testEntropyVeryLongSecret(): void
    {
        $r = SecretRules::entropyPassword();
        // 10,000-byte high-entropy value: the recognizer must handle long bare
        // values without truncation or backtracking blowup.
        $value = str_repeat('kQ7$mZ2!xR9p', 834); // ~10,008 bytes, high entropy
        $hits = $r->recognize('password: ' . $value);
        self::assertCount(1, $hits);
        self::assertSame($value, $hits[0]->text);
        // Verify Finding::text equals substr(input, start, end-start).
        $input = 'password: ' . $value;
        self::assertSame(
            \substr($input, $hits[0]->start, $hits[0]->end - $hits[0]->start),
            $hits[0]->text,
        );
    }

    // --- NBSP / fullwidth-space / vertical-tab entropy boundary ---
    // spec section 8: \s expands to ASCII [ \t\n\f\r] only. NBSP (U+00A0),
    // fullwidth space (U+3000), and vertical tab (0x0B) are NOT in that set,
    // so a bare value continues through them rather than splitting.

    public function testEntropyNbspFullwidthVtabDoNotSplitBareValue(): void
    {
        $r = SecretRules::entropyPassword();

        // NBSP (U+00A0 = \xC2\xA0) is NOT in the ASCII whitespace set
        // [ \t\n\f\r], so the bare value must continue through it. The finding
        // text must include the NBSP bytes rather than splitting at them.
        $nbspValue = "kQ7\$mZ2!\xC2\xA0xR9p";
        $hits = $r->recognize('password: ' . $nbspValue);
        self::assertCount(1, $hits);
        self::assertSame($nbspValue, $hits[0]->text);

        // Fullwidth space (U+3000 = \xE3\x80\x80) likewise is not ASCII \s.
        $fwsValue = "kQ7\$mZ2!\xE3\x80\x80xR9p";
        $hits2 = $r->recognize('password: ' . $fwsValue);
        self::assertCount(1, $hits2);
        self::assertSame($fwsValue, $hits2[0]->text);

        // Vertical tab (0x0B) is not in the expanded \s set either.
        $vtValue = "kQ7\$mZ2!\x0BxR9p";
        $hits3 = $r->recognize('password: ' . $vtValue);
        self::assertCount(1, $hits3);
        self::assertSame($vtValue, $hits3[0]->text);

        // Sanity: ASCII space DOES split the bare value.
        $spHits = $r->recognize('password: kQ7$mZ2!x def');
        self::assertCount(1, $spHits);
        self::assertSame('kQ7$mZ2!x', $spHits[0]->text);
    }

    // --- preg_match===false fail-closed injection ---
    // Force preg_match to return false by zeroing pcre.backtrack_limit; the
    // recognizer must surface this as RegexException, never silently swallow it.

    public function testEntropyFailClosedOnPregMatchFalse(): void
    {
        $r = SecretRules::entropyPassword();
        $orig = \ini_get('pcre.backtrack_limit');
        try {
            \ini_set('pcre.backtrack_limit', '0');
            $this->expectException(RegexException::class);
            $r->recognize('password: kQ7$mZ2!xR9p');
        } finally {
            \ini_set('pcre.backtrack_limit', $orig !== false ? $orig : '1000000');
        }
    }

    // --- Internal 3000-candidate cap (spec section 4.4) ---
    // Library built-in recognizers must enforce the local cap internally via
    // incremental enumeration, not only at the driver level.

    public function testPrivateKeyRawCandidateCapThrows(): void
    {
        $r = SecretRules::privateKey();
        // 3001 BEGIN headers (each incomplete, no END) force the scanner past
        // the 3000-candidate cap.
        $one = "-----BEGIN RSA PRIVATE KEY-----\n";
        $text = str_repeat($one, 3001);
        $this->expectException(LimitExceededException::class);
        $r->recognize($text);
    }

    public function testPrivateKeyJustUnderCapSucceeds(): void
    {
        $r = SecretRules::privateKey();
        // 3000 incomplete BEGIN headers: at the cap but not over it.
        $one = "-----BEGIN RSA PRIVATE KEY-----\n";
        $text = str_repeat($one, 3000);
        // No exception thrown; returns empty (all incomplete).
        self::assertSame([], $r->recognize($text));
    }

    public function testEntropyRawCandidateCapThrows(): void
    {
        $r = SecretRules::entropyPassword();
        // 3001 keyword-value pairs, each above the entropy threshold.
        $value = 'kQ7$mZ2!xR9p';
        $pair = "password: {$value}\n";
        $text = str_repeat($pair, 3001);
        $this->expectException(LimitExceededException::class);
        $r->recognize($text);
    }

    public function testEntropyJustUnderCapSucceeds(): void
    {
        $r = SecretRules::entropyPassword();
        $value = 'kQ7$mZ2!xR9p';
        $pair = "password: {$value}\n";
        $text = str_repeat($pair, 3000);
        $hits = $r->recognize($text);
        // 3000 findings, no exception.
        self::assertCount(3000, $hits);
    }

    // --- Performance / backtracking regression (plan Step 4) ---

    public function testEntropyScannerNoCatastrophicBacktracking(): void
    {
        $r = SecretRules::entropyPassword();
        // Long input with many keyword-shaped prefixes that do NOT form a full
        // match (keyword followed by space, no separator): must complete in
        // roughly linear time without catastrophic backtracking.
        $noise = str_repeat("password - not-a-match ", 4000);
        $text = $noise . 'password: kQ7$mZ2!xR9p';
        $start = \microtime(true);
        $hits = $r->recognize($text);
        $elapsed = \microtime(true) - $start;
        // The trailing keyword-value pair yields exactly one finding.
        self::assertGreaterThanOrEqual(1, \count($hits));
        self::assertLessThan(0.5, $elapsed, "entropy scanner took {$elapsed}s on noisy input");
    }
}
