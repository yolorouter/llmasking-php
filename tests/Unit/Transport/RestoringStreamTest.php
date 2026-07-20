<?php

// tests/Unit/Transport/RestoringStreamTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\RestoreEvent;
use Yolorouter\Llmasking\Session;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;
use Yolorouter\Llmasking\Transport\RestoringStream;
use Yolorouter\Llmasking\Transport\SseRestorer;

/**
 * Unit coverage for the PSR-7 RestoringStream (spec §9.6): forward-only
 * read-only transform stream that drives an {@see SseRestorer} from an upstream
 * response body, plus the terminal failed-body mode used when JSON restore
 * cannot be performed.
 */
final class RestoringStreamTest extends TestCase
{
    private function sessionWithPhone(): Session
    {
        $s = Engine::new(EngineOption::withKeywords('TARGET'))->newSession();
        $s->anonymize('TARGET');

        return $s;
    }

    private function upstream(string $data, int $maxChunk = 8192): StringStream
    {
        return new StringStream($data, $maxChunk);
    }

    /**
     * Drain $stream to completion, returning every byte read. Loops on read()
     * returning the empty string (which RestoringStream produces only at clean
     * EOF for the in-memory upstreams used here) rather than on eof(), so PHPStan
     * does not flag a tracked-property "always false" narrowing.
     */
    private function drain(RestoringStream $stream, int $chunk = 8192): string
    {
        $out = '';
        while (true) {
            $piece = $stream->read($chunk);
            if ($piece === '') {
                break;
            }
            $out .= $piece;
        }

        return $out;
    }

    /**
     * Build an SSE-mode RestoringStream over $data with a generous budget so the
     * budget cap never interferes with functional tests.
     */
    private function sseStream(string $data, int $materializeBudget = 1 << 20, int $maxChunk = 8192): RestoringStream
    {
        $session = $this->sessionWithPhone();
        $restorer = new SseRestorer($session);

        return RestoringStream::forSse($restorer, $this->upstream($data, $maxChunk), $materializeBudget);
    }

    // ---- Invariants ---------------------------------------------------------

    public function testStaticInvariantsHold(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
        self::assertTrue($stream->isReadable());
        self::assertSame(0, $stream->tell());
        self::assertFalse($stream->eof());
    }

    public function testWriteSeekRewindThrowRuntimeException(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $this->expectException(\RuntimeException::class);
        $stream->write('x');
    }

    public function testSeekThrowsRuntimeException(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $this->expectException(\RuntimeException::class);
        $stream->seek(0);
    }

    public function testRewindThrowsRuntimeException(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $this->expectException(\RuntimeException::class);
        $stream->rewind();
    }

    // ---- read(0) / negative -------------------------------------------------

    public function testReadZeroReturnsEmptyStringWithoutAdvancing(): void
    {
        $payload = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x\"}}]}\n\n";
        $stream = $this->sseStream($payload);

        self::assertSame('', $stream->read(0));
        self::assertSame(0, $stream->tell());
        self::assertFalse($stream->eof());
    }

    public function testReadNegativeLengthThrows(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $this->expectException(\InvalidArgumentException::class);
        $stream->read(-1);
    }

    // ---- tell / eof ---------------------------------------------------------

    public function testTellReportsDeliveredRestoredBytes(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"ab TARGET\"}}]}\n\n";
        $stream = $this->sseStream($input);

        $first = $stream->read(2);
        self::assertSame(2, $stream->tell());
        $second = $stream->read(3);
        self::assertSame(2 + strlen($second), $stream->tell());
        // Drain the rest.
        while (!$stream->eof()) {
            $stream->read(4096);
        }
        // tell() equals total bytes delivered to the caller.
        self::assertSame(strlen($first) + strlen($second), 2 + strlen($second));
    }

    public function testEofTrueOnlyAfterUpstreamDrainedAndBufferEmpty(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello\"}}]}\n\n";
        $stream = $this->sseStream($input);

        // Pull one byte: not at EOF.
        $stream->read(1);
        self::assertFalse($stream->eof());

        // Drain to completion.
        $this->drain($stream);
        self::assertTrue($stream->eof());
        self::assertSame('', $stream->read(8));
    }

    // ---- close / detach -----------------------------------------------------

    public function testIsReadableFalseAfterClose(): void
    {
        $stream = $this->sseStream("data: hi\n\n");
        self::assertTrue($stream->isReadable());

        $stream->close();
        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->eof());
    }

    public function testCloseIsIdempotent(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $stream->close();
        // Second and third close must not throw.
        $stream->close();
        $stream->close();

        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->eof());
    }

    public function testReadAfterCloseThrowsRuntimeException(): void
    {
        $stream = $this->sseStream("data: hi\n\n");
        $stream->close();

        $this->expectException(\RuntimeException::class);
        $stream->read(4);
    }

    public function testTellPreservedAfterClose(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello\"}}]}\n\n";
        $stream = $this->sseStream($input);
        $stream->read(3);
        $told = $stream->tell();
        $stream->close();

        self::assertSame($told, $stream->tell());
    }

    public function testDetachReturnsNullAndCloses(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $result = $stream->detach();

        self::assertNull($result);
        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->eof());
    }

    // ---- read past EOF / large length / partial -----------------------------

    public function testReadPastEofReturnsEmptyString(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hi\"}}]}\n\n";
        $stream = $this->sseStream($input);

        $this->drain($stream);
        self::assertSame('', $stream->read(16));
        self::assertSame('', $stream->read(16));
    }

    public function testLargeLengthTruncatedToMaterializeBudget(): void
    {
        // Three frames; each restores to ~60 bytes (total >> 64-byte budget).
        $frame = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello TARGET world\"}}]}\n\n";
        $input = str_repeat($frame, 3);

        $stream = $this->sseStream($input, 64);

        $chunk = $stream->read(\PHP_INT_MAX);

        // read() must NOT materialise the whole stream in one call.
        self::assertLessThanOrEqual(64, strlen($chunk));
        self::assertGreaterThan(0, strlen($chunk));
        // Remaining bytes are still available via further reads.
        self::assertFalse($stream->eof());
    }

    public function testPartialReadsReassembleFullRestoredOutput(): void
    {
        // Plain frame (no placeholder): byte-wise partial reads must reassemble
        // the exact forwarded wire.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"call TARGET now\"}}]}\n\n";

        $session = $this->sessionWithPhone();
        $restorer = new SseRestorer($session);
        $stream = RestoringStream::forSse($restorer, $this->upstream($input, 4), 1 << 20);

        $assembled = $this->drain($stream, 3);

        self::assertSame($input, $assembled);
    }

    public function testRestorationFlowsThroughRead(): void
    {
        // Use ONE session to both learn the placeholder token and resolve it:
        // anonymize('TARGET') writes the [KEYWORD_n] -> TARGET mapping, which
        // the SseRestorer (bound to the same session) then restores.
        $session = Engine::new(EngineOption::withKeywords('TARGET'))->newSession();
        $token = $session->anonymize('TARGET')->text;
        self::assertNotSame('TARGET', $token);

        $wire = 'data: {"choices":[{"index":0,"delta":{"content":"go ' . $token . ' now"}}]}' . "\n\n";

        $restorer = new SseRestorer($session);
        $stream = RestoringStream::forSse($restorer, $this->upstream($wire), 1 << 20);

        $out = $this->drain($stream);

        self::assertStringContainsString('go TARGET now', $out);
        self::assertStringNotContainsString($token, $out);
    }

    // ---- getContents / materializeBudget ------------------------------------

    public function testGetContentsReturnsFullOutputWhenUnderBudget(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $stream = $this->sseStream($input, 1 << 20);

        $out = $stream->getContents();

        self::assertSame($input, $out);
        self::assertTrue($stream->eof());
    }

    public function testGetContentsExceedingMaterializeBudgetThrowsStreamRestoreException(): void
    {
        $frame = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello hello hello\"}}]}\n\n";
        $input = str_repeat($frame, 4); // comfortably > 16-byte budget

        $stream = $this->sseStream($input, 16);

        $this->expectException(StreamRestoreException::class);
        $stream->getContents();
    }

    public function testGetContentsFailureIsTerminal(): void
    {
        $frame = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello hello hello\"}}]}\n\n";
        $input = str_repeat($frame, 4);
        $stream = $this->sseStream($input, 16);

        try {
            $stream->getContents();
            self::fail('expected StreamRestoreException');
        } catch (StreamRestoreException $e) {
            $first = $e;
        }

        // Subsequent read/getContents rethrow the same failure; eof is true.
        self::assertTrue($stream->eof());
        try {
            $stream->read(4);
            self::fail('expected rethrow');
        } catch (StreamRestoreException $e) {
            self::assertSame($first, $e);
        }
    }

    // ---- __toString ---------------------------------------------------------

    public function testToStringDrainsFreshStreamUnderBudget(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $stream = $this->sseStream($input, 1 << 20);

        $string = (string) $stream;

        self::assertSame($input, $string);
        self::assertTrue($stream->eof());
    }

    public function testToStringOverBudgetReturnsEmptyAndFailsStream(): void
    {
        $frame = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello hello hello\"}}]}\n\n";
        $input = str_repeat($frame, 4);
        $stream = $this->sseStream($input, 16);

        self::assertSame('', (string) $stream);
        // __toString must not throw, but the stream is now terminal-failed.
        self::assertTrue($stream->eof());
        $this->expectException(StreamRestoreException::class);
        $stream->read(4);
    }

    public function testToStringOnPartiallyConsumedStreamReturnsEmpty(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $stream = $this->sseStream($input, 1 << 20);
        $stream->read(3);

        self::assertSame('', (string) $stream);
    }

    // ---- Failed-body mode ---------------------------------------------------

    public function testFailedBodyReadThrowsStreamRestoreException(): void
    {
        $err = new StreamRestoreException('json restore failed');
        $stream = RestoringStream::forFailedBody($err);

        $this->expectException(StreamRestoreException::class);
        $stream->read(8);
    }

    public function testFailedBodyGetContentsThrows(): void
    {
        $stream = RestoringStream::forFailedBody(new StreamRestoreException('nope'));

        $this->expectException(StreamRestoreException::class);
        $stream->getContents();
    }

    public function testFailedBodyToStringReturnsEmptyString(): void
    {
        $stream = RestoringStream::forFailedBody(new StreamRestoreException('nope'));

        self::assertSame('', (string) $stream);
    }

    public function testFailedBodyInvariants(): void
    {
        $stream = RestoringStream::forFailedBody(new StreamRestoreException('nope'));

        self::assertTrue($stream->eof());
        self::assertFalse($stream->isWritable());
        self::assertFalse($stream->isSeekable());
        self::assertNull($stream->getSize());
        self::assertSame(0, $stream->tell());
        // Failure state before explicit close still reports capability=true.
        self::assertTrue($stream->isReadable());
    }

    public function testFailedBodyReadRethrowsSameExceptionInstance(): void
    {
        $err = new StreamRestoreException('sticky');
        $stream = RestoringStream::forFailedBody($err);

        try {
            $stream->read(2);
            self::fail('expected');
        } catch (StreamRestoreException $e) {
            self::assertSame($err, $e);
        }
        try {
            $stream->read(2);
            self::fail('expected');
        } catch (StreamRestoreException $e) {
            self::assertSame($err, $e);
        }
    }

    // ---- getMetadata --------------------------------------------------------

    public function testGetMetadataDoesNotExposeResource(): void
    {
        $stream = $this->sseStream("data: hi\n\n");

        $all = $stream->getMetadata();
        self::assertIsArray($all);
        // No resource-typed values exposed.
        foreach ($all as $value) {
            self::assertIsNotResource($value);
        }
        self::assertFalse($stream->getMetadata('seekable'));
        self::assertNull($stream->getMetadata('nonexistent_key'));
    }

    // ---- Completion callback (spec §9.7 SSE) --------------------------------

    public function testCompletionCallbackFiresTrueOnCleanDrain(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $calls = [];
        $session = $this->sessionWithPhone();
        $restorer = new SseRestorer($session);
        $stream = RestoringStream::forSse(
            $restorer,
            $this->upstream($input),
            1 << 20,
            function (array $events, bool $complete, ?\Throwable $e) use (&$calls): void {
                $calls[] = ['events' => $events, 'complete' => $complete, 'error' => $e];
            },
        );

        $this->drain($stream);

        self::assertCount(1, $calls);
        self::assertTrue($calls[0]['complete']);
        self::assertNull($calls[0]['error']);
    }

    public function testCompletionCallbackFiresFalseOnEarlyClose(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $calls = [];
        $session = $this->sessionWithPhone();
        $restorer = new SseRestorer($session);
        $stream = RestoringStream::forSse(
            $restorer,
            $this->upstream($input),
            1 << 20,
            function (array $events, bool $complete, ?\Throwable $e) use (&$calls): void {
                $calls[] = ['events' => $events, 'complete' => $complete, 'error' => $e];
            },
        );

        // Read partially then close early.
        $stream->read(2);
        $stream->close();

        self::assertCount(1, $calls);
        self::assertFalse($calls[0]['complete']);
    }

    public function testCompletionCallbackFiresOnceOnly(): void
    {
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"short\"}}]}\n\n";
        $calls = [];
        $session = $this->sessionWithPhone();
        $restorer = new SseRestorer($session);
        $stream = RestoringStream::forSse(
            $restorer,
            $this->upstream($input),
            1 << 20,
            function (array $events, bool $complete, ?\Throwable $e) use (&$calls): void {
                $calls[] = true;
            },
        );

        $this->drain($stream);
        $stream->close(); // already fired
        $stream->close();

        self::assertCount(1, $calls);
    }
}

/**
 * Minimal readable, forward-only PSR-7 stream used to feed RestoringStream in
 * tests. $maxChunk forces partial reads so the aggregation logic is exercised.
 */
final class StringStream implements StreamInterface
{
    private string $data;
    private int $pos = 0;
    private bool $eofFlag = false;
    private bool $closed = false;
    private int $maxChunk;

    public function __construct(string $data, int $maxChunk = 8192)
    {
        $this->data = $data;
        $this->maxChunk = max(1, $maxChunk);
    }

    public function read(int $length): string
    {
        if ($this->closed || $this->eofFlag) {
            return '';
        }
        $remaining = \strlen($this->data) - $this->pos;
        if ($remaining <= 0) {
            $this->eofFlag = true;

            return '';
        }
        $take = min([$length, $remaining, $this->maxChunk]);
        if ($take <= 0) {
            return '';
        }
        $out = \substr($this->data, $this->pos, $take);
        $this->pos += $take;
        if ($this->pos >= \strlen($this->data)) {
            $this->eofFlag = true;
        }

        return $out;
    }

    public function eof(): bool
    {
        return $this->eofFlag;
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function getSize(): ?int
    {
        return \strlen($this->data);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        $this->closed = true;

        return null;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('not seekable');
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function getContents(): string
    {
        $out = \substr($this->data, $this->pos);
        $this->pos = \strlen($this->data);
        $this->eofFlag = true;

        return $out;
    }

    public function getMetadata(?string $key = null)
    {
        return null;
    }

    public function __toString(): string
    {
        return $this->data;
    }
}
