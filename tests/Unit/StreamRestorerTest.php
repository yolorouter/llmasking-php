<?php

// tests/Unit/StreamRestorerTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Exception\StreamClosedException;

final class StreamRestorerTest extends TestCase
{
    public function testPlaceholderSplitAcrossChunks(): void
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000');
        $sr = $s->streamRestorer();
        $out = $sr->write('好的[PHO')->text;
        $out .= $sr->write('NE_1]马上')->text;
        $out .= $sr->flush()->text;
        self::assertSame('好的13800138000马上', $out);
    }

    public function testEveryByteSplitEqualsOneShot(): void
    {
        $text = '好的，联系[PHONE_1]马上';
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000');
        $expected = $s->restore($text)->text;

        $len = \strlen($text);
        for ($split = 1; $split <= $len; $split++) {
            $sr = $s->streamRestorer();
            $out = '';
            for ($i = 0; $i < $len; $i += $split) {
                $out .= $sr->write(\substr($text, $i, $split))->text;
            }
            $out .= $sr->flush()->text;
            self::assertSame($expected, $out, "byte-split=$split");
        }
    }

    public function testTerminalFailureRethrowsSameException(): void
    {
        $e = Engine::new(EngineOption::withMaxInputBytes(4));
        $sr = $e->newSession()->streamRestorer();
        try {
            $sr->write('12345'); // cumulative 5 > 4
            self::fail('expected LimitExceededException');
        } catch (\Throwable $ex) {
        }
        try {
            $sr->write('x');
            self::fail('expected the same exception rethrown');
        } catch (\Throwable $ex2) {
            self::assertSame($ex, $ex2);
        }
    }

    public function testWriteAfterFlushThrowsStreamClosed(): void
    {
        $sr = Engine::new()->newSession()->streamRestorer();
        $sr->write('hello');
        $sr->flush();
        $this->expectException(StreamClosedException::class);
        $sr->write('more');
    }

    public function testWriteWithholdsIncompleteUtf8UntilValidated(): void
    {
        $sr = Engine::new()->newSession()->streamRestorer();
        // "\xF0" is a 4-byte UTF-8 lead byte; on its own it is an incomplete
        // codepoint and must NOT leave the restorer as output.
        $out = $sr->write("\xF0")->text;
        self::assertSame('', $out);
        // Completing the codepoint validates it and emits the full character.
        $out .= $sr->write("\x90\x80\x80")->text;
        $out .= $sr->flush()->text;
        self::assertSame("\xF0\x90\x80\x80", $out);
    }
}
