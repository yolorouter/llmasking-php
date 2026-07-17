<?php

// tests/Unit/Internal/PlaceholderLexerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Internal\PlaceholderLexer;

final class PlaceholderLexerTest extends TestCase
{
    public function testCanonicalBracketed(): void
    {
        $t = PlaceholderLexer::scan('x[PHONE_1]y', 5);
        self::assertCount(1, $t);
        self::assertSame('PHONE', $t[0]->entity);
        self::assertTrue($t[0]->valid);
        self::assertSame(1, $t[0]->seq);
        self::assertSame(1, $t[0]->start);
        self::assertSame(10, $t[0]->end);
    }

    public function testBareForm(): void
    {
        $t = PlaceholderLexer::scan('a PHONE_1 b', 5);
        self::assertCount(1, $t);
        self::assertSame('PHONE', $t[0]->entity);
        self::assertSame(1, $t[0]->seq);
        self::assertSame(2, $t[0]->start); // after "a "
    }

    public function testZeroPaddedValidWithinMaxSeqDigits(): void
    {
        $t = PlaceholderLexer::scan('[PHONE_01]', 5);
        self::assertCount(1, $t);
        self::assertTrue($t[0]->valid);
        self::assertSame(1, $t[0]->seq); // "01" parses to 1
    }

    public function testOverwideZeroPadIsInvalid(): void
    {
        $t = PlaceholderLexer::scan('[PHONE_000001]', 5); // width 6 > 5
        self::assertCount(1, $t);
        self::assertFalse($t[0]->valid);
        self::assertSame(0, $t[0]->seq); // never parsed into an int
    }

    public function testFullwidthBrackets(): void
    {
        $t = PlaceholderLexer::scan('【PHONE_1】', 5);
        self::assertCount(1, $t);
        self::assertSame('PHONE', $t[0]->entity);
        self::assertSame(1, $t[0]->seq);
    }

    public function testMixedWidthBracketsAreTokens(): void
    {
        // OPEN and CLOSE are independently optional; mismatched widths still match.
        self::assertCount(1, PlaceholderLexer::scan('[PHONE_1】', 5));
        self::assertCount(1, PlaceholderLexer::scan('【PHONE_1]', 5));
    }

    public function testUnmatchedOpenAtEofIsToken(): void
    {
        // "[PHONE_1" has no CLOSE; CLOSE is optional, so at EOF it is a token.
        $t = PlaceholderLexer::scan('[PHONE_1', 5);
        self::assertCount(1, $t);
        self::assertSame('PHONE', $t[0]->entity);
    }

    public function testUnderscoreWithoutDigitsIsLiteral(): void
    {
        self::assertSame([], PlaceholderLexer::scan('PHONE_ x', 5));
    }

    public function testFeedWithholdsIncompleteCandidate(): void
    {
        $lex = new PlaceholderLexer(5);
        // A partial candidate '[PHO' yields no token; the candidate is withheld
        // (committedOffset sits at its start — the '[' after the 6 literal bytes
        // of '好的').
        self::assertSame([], $lex->feed('好的[PHO', false));
        self::assertSame(6, $lex->committedOffset());
    }

    public function testFeedResolvesCandidateOnCompletion(): void
    {
        $lex = new PlaceholderLexer(5);
        self::assertSame([], $lex->feed('好的[PHO', false));
        $tokens = $lex->feed('NE_1]', false);
        self::assertCount(1, $tokens);
        self::assertSame('PHONE', $tokens[0]->entity);
        self::assertSame(6, $tokens[0]->start); // begins at the '['
    }

    public function testFinishFinalizesDigitsCandidateWithoutClose(): void
    {
        $lex = new PlaceholderLexer(5);
        $lex->feed('[PHONE_1', false);
        $tokens = $lex->finish(); // EOF: CLOSE optional -> a complete token
        self::assertCount(1, $tokens);
        self::assertSame('PHONE', $tokens[0]->entity);
    }
}
