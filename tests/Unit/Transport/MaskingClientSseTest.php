<?php

// tests/Unit/Transport/MaskingClientSseTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;
use Yolorouter\Llmasking\Transport\MaskingClient;
use Yolorouter\Llmasking\Transport\TransportOptions;

/**
 * End-to-end coverage for the Plan 4 MaskingClient wiring: SSE responses are
 * restored through a RestoringStream body, and JSON failure / partial-content /
 * integrity-header cases degrade to a terminal failed-body RestoringStream
 * (spec §9.4–§9.5).
 */
final class MaskingClientSseTest extends TestCase
{
    private function engine(): Engine
    {
        return Engine::new(EngineOption::withKeywords('TARGET'));
    }

    /**
     * @return array{0: MaskingClient, 1: ClientInterface}
     */
    private function clientReturning(ResponseInterface $response, ?Engine $engine = null): array
    {
        $inner = new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $factory = new class () implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return new ReadableStream($content);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }
        };
        $client = new MaskingClient($inner, $factory, $engine ?? $this->engine());

        return [$client, $inner];
    }

    private function jsonRequest(string $body = '{"messages":[{"content":"TARGET"}]}'): RequestInterface
    {
        return new SimpleRequest($body);
    }

    // ---- SSE response path --------------------------------------------------

    public function testSseResponseIsRestoredThroughRead(): void
    {
        // A plain SSE frame (no placeholder) is forwarded byte-for-byte through
        // the RestoringStream body; the wiring assertion is that sendRequest()
        // returns a readable transform stream whose drain reproduces the wire.
        $wire = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello world\"}}]}\n\n";
        $response = new SimpleResponse(new ReadableStream($wire), ['Content-Type' => 'text/event-stream']);
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        $body = $resp->getBody();
        $out = '';
        while (true) {
            $piece = $body->read(64);
            if ($piece === '') {
                break;
            }
            $out .= $piece;
        }

        self::assertSame($wire, $out);
        self::assertTrue($body->eof());
    }

    public function testSseResponseStripsLengthAndTransferHeaders(): void
    {
        $wire = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hi\"}}]}\n\n";
        $response = new SimpleResponse(
            new ReadableStream($wire),
            [
                'Content-Type' => 'text/event-stream',
                'Content-Length' => (string) strlen($wire),
                'Transfer-Encoding' => 'chunked',
                'ETag' => '"abc"',
            ],
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        self::assertSame('text/event-stream', $resp->getHeaderLine('Content-Type'));
        self::assertFalse($resp->hasHeader('Content-Length'));
        self::assertFalse($resp->hasHeader('Transfer-Encoding'));
        self::assertFalse($resp->hasHeader('ETag'));
    }

    public function testSseResponseWithContentRangeDegradesToFailedBody(): void
    {
        $response = new SimpleResponse(
            new ReadableStream("data: x\n\n"),
            ['Content-Type' => 'text/event-stream', 'Content-Range' => 'bytes 0-10/100'],
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        self::assertFalse($resp->hasHeader('Content-Range'));
        self::assertFalse($resp->hasHeader('Content-Type'));
        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->read(8);
    }

    // ---- JSON failed-body ---------------------------------------------------

    public function testJsonSyntaxErrorDegradesToFailedBody(): void
    {
        $response = new SimpleResponse(
            new ReadableStream('{not json'),
            [
                'Content-Type' => 'application/json',
                'Content-Length' => '8',
                'ETag' => '"v1"',
            ],
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        // Representation headers stripped.
        self::assertFalse($resp->hasHeader('Content-Type'));
        self::assertFalse($resp->hasHeader('Content-Length'));
        self::assertFalse($resp->hasHeader('ETag'));
        // __toString safe-empty; read throws.
        self::assertSame('', (string) $resp->getBody());
        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->read(4);
    }

    public function testJson206DegradesToFailedBody(): void
    {
        $response = new SimpleResponse(
            new ReadableStream('{}'),
            ['Content-Type' => 'application/json'],
            206,
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        self::assertSame(206, $resp->getStatusCode());
        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->getContents();
    }

    public function testJsonResponseIntegrityHeaderOnRewriteDegradesToFailedBody(): void
    {
        // Request masks TARGET -> [KEYWORD_1]; response carries it back inside a
        // §9.4 restore target (choices[].message.content), so the body WOULD be
        // rewritten — but the response carries a Digest header that cannot be
        // preserved, so the response degrades to a failed body.
        $response = new SimpleResponse(
            new ReadableStream('{"choices":[{"message":{"content":"[KEYWORD_1]"}}]}'),
            [
                'Content-Type' => 'application/json',
                'Digest' => 'sha256=abc',
            ],
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        self::assertFalse($resp->hasHeader('Digest'));
        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->read(4);
    }

    public function testFailedBodyRestoreCallbackFiresIncomplete(): void
    {
        $fired = [];
        $callback = function (RequestInterface $req, array $events, bool $complete, ?\Throwable $e) use (&$fired): void {
            $fired[] = ['events' => $events, 'complete' => $complete, 'error' => $e];
        };

        $inner = new class (new SimpleResponse(new ReadableStream('{bad'), ['Content-Type' => 'application/json'])) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $factory = new class () implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return new ReadableStream($content);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }
        };
        $client = new MaskingClient($inner, $factory, $this->engine(), TransportOptions::withRestoreReport($callback));

        $client->sendRequest($this->jsonRequest());

        self::assertCount(1, $fired);
        self::assertFalse($fired[0]['complete']);
        self::assertSame([], $fired[0]['events']);
        self::assertInstanceOf(StreamRestoreException::class, $fired[0]['error']);
    }

    // ---- response transform exception containment (spec §9.4) --------------
    //
    // Any failure while reading or rebuilding the response body must degrade to
    // a terminal failed RestoringStream instead of escaping sendRequest().

    public function testResponseBodyRewindFailureDegradesToFailedBody(): void
    {
        // Seekable body whose rewind() throws: the read phase of processResponse
        // must catch it and return a failed body rather than letting the
        // exception escape sendRequest().
        $response = new SimpleResponse(
            new RewindThrowingStream('{"choices":[]}'),
            ['Content-Type' => 'application/json'],
        );
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->read(4);
    }

    public function testResponseFactoryFailureDegradesToFailedBody(): void
    {
        // The rebuilt-body phase calls StreamFactoryInterface::createStream(); a
        // factory failure there must degrade to a failed body, not escape.
        $inner = new class (new SimpleResponse(
            new ReadableStream('{"choices":[{"message":{"content":"[KEYWORD_1]"}}]}'),
            ['Content-Type' => 'application/json'],
        )) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $factory = new class () implements StreamFactoryInterface {
            public int $calls = 0;

            public function createStream(string $content = ''): StreamInterface
            {
                // The first createStream call rewrites the masked request body;
                // the second rebuilds the restored response body and must be the
                // one that degrades to a failed body instead of escaping.
                if (++$this->calls >= 2) {
                    throw new \RuntimeException('factory down');
                }

                return new ReadableStream($content);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                throw new \RuntimeException('not supported');
            }
        };
        $client = new MaskingClient($inner, $factory, $this->engine());

        $resp = $client->sendRequest($this->jsonRequest());

        $this->expectException(StreamRestoreException::class);
        $resp->getBody()->read(4);
    }

    public function testConsumedResponseBodyClosedOnSuccessfulRestore(): void
    {
        // After a successful restore that replaces the body, the consumed
        // original response body must be closed (spec §9.4: never leave a body
        // sitting at EOF; the replaced stream must be released).
        $spy = new CloseSpyStream(new ReadableStream('{"choices":[{"message":{"content":"[KEYWORD_1]"}}]}'));
        $response = new SimpleResponse($spy, ['Content-Type' => 'application/json']);
        [$client] = $this->clientReturning($response);

        $resp = $client->sendRequest($this->jsonRequest());

        // Restore actually happened (body carries the restored plaintext).
        $body = $resp->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }
        self::assertStringContainsString('TARGET', $body->getContents());
        // The original upstream body was closed exactly once.
        self::assertSame(1, $spy->closeCount);
    }
}

// ---- Minimal PSR-7 doubles -------------------------------------------------

final class ReadableStream implements StreamInterface
{
    private string $data;
    private int $pos = 0;
    private bool $eof = false;
    private bool $closed = false;

    public function __construct(string $data)
    {
        $this->data = $data;
    }

    public function read(int $length): string
    {
        if ($this->closed || $this->eof) {
            return '';
        }
        $remaining = \strlen($this->data) - $this->pos;
        if ($remaining <= 0) {
            $this->eof = true;

            return '';
        }
        $take = \min($length, $remaining);
        $out = \substr($this->data, $this->pos, $take);
        $this->pos += $take;
        if ($this->pos >= \strlen($this->data)) {
            $this->eof = true;
        }

        return $out;
    }

    public function eof(): bool
    {
        return $this->eof;
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
        return true;
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
        if ($whence === \SEEK_SET) {
            $this->pos = $offset;
        }
        $this->eof = $this->pos >= \strlen($this->data);
    }

    public function rewind(): void
    {
        $this->pos = 0;
        $this->eof = false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function getContents(): string
    {
        $out = \substr($this->data, $this->pos);
        $this->pos = \strlen($this->data);
        $this->eof = true;

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

/**
 * Seekable string-backed stream whose rewind() always throws. Used to verify
 * that failures during the response-body read phase degrade to a failed body.
 */
final class RewindThrowingStream implements StreamInterface
{
    private ReadableStream $inner;

    public function __construct(string $data)
    {
        $this->inner = new ReadableStream($data);
    }

    public function close(): void
    {
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        // Report seekable so processResponse attempts rewind(), which then throws.
        return true;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        throw new \RuntimeException('rewind broken');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('not writable');
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}

/**
 * StreamInterface wrapper that counts close() calls so tests can assert the
 * transport releases a consumed upstream body after replacing it.
 */
final class CloseSpyStream implements StreamInterface
{
    public int $closeCount = 0;

    public function __construct(private readonly StreamInterface $inner)
    {
    }

    public function close(): void
    {
        ++$this->closeCount;
        $this->inner->close();
    }

    public function detach()
    {
        return $this->inner->detach();
    }

    public function getSize(): ?int
    {
        return $this->inner->getSize();
    }

    public function tell(): int
    {
        return $this->inner->tell();
    }

    public function eof(): bool
    {
        return $this->inner->eof();
    }

    public function isSeekable(): bool
    {
        return $this->inner->isSeekable();
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        $this->inner->seek($offset, $whence);
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function isWritable(): bool
    {
        return $this->inner->isWritable();
    }

    public function write(string $string): int
    {
        return $this->inner->write($string);
    }

    public function isReadable(): bool
    {
        return $this->inner->isReadable();
    }

    public function read(int $length): string
    {
        return $this->inner->read($length);
    }

    public function getContents(): string
    {
        return $this->inner->getContents();
    }

    public function getMetadata(?string $key = null)
    {
        return $this->inner->getMetadata($key);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}

final class SimpleResponse implements ResponseInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private StreamInterface $body,
        private array $headers = [],
        private int $status = 200,
        private string $reason = '',
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->status = $code;
        $clone->reason = $reasonPhrase;

        return $clone;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    /** @return array<string, list<string>> */
    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $value) {
            $out[$name] = [$value];
        }

        return $out;
    }

    public function hasHeader(string $name): bool
    {
        foreach (\array_keys($this->headers) as $existing) {
            if (\strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getHeader(string $name): array
    {
        foreach ($this->headers as $existing => $value) {
            if (\strcasecmp($existing, $name) === 0) {
                return [$value];
            }
        }

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $clone->setHeader($name, $value);

        return $clone;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        return $this->withHeader($name, $value);
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        foreach (\array_keys($clone->headers) as $existing) {
            if (\strcasecmp($existing, $name) === 0) {
                unset($clone->headers[$existing]);
            }
        }

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): ResponseInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    /** @param string|list<string> $value */
    private function setHeader(string $name, $value): void
    {
        // Replace any existing same-name (case-insensitive) header.
        foreach (\array_keys($this->headers) as $existing) {
            if (\strcasecmp($existing, $name) === 0) {
                unset($this->headers[$existing]);
            }
        }
        $this->headers[$name] = \is_array($value) ? \implode(', ', $value) : (string) $value;
    }
}

final class SimpleRequest implements RequestInterface
{
    /** @param array<string, string> $headers */
    public function __construct(
        private string $body = '',
        private array $headers = ['Content-Type' => 'application/json'],
        private string $method = 'POST',
    ) {
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        return $this;
    }

    public function getUri(): \Psr\Http\Message\UriInterface
    {
        return new class () implements \Psr\Http\Message\UriInterface {
            public function getScheme(): string
            {
                return '';
            }

            public function getAuthority(): string
            {
                return '';
            }

            public function getUserInfo(): string
            {
                return '';
            }

            public function getHost(): string
            {
                return '';
            }

            public function getPort(): ?int
            {
                return null;
            }

            public function getPath(): string
            {
                return '';
            }

            public function getQuery(): string
            {
                return '';
            }

            public function getFragment(): string
            {
                return '';
            }

            public function withScheme(string $scheme): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withUserInfo(string $user, ?string $password = null): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withHost(string $host): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withPort(?int $port): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withPath(string $path): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withQuery(string $query): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function withFragment(string $fragment): \Psr\Http\Message\UriInterface
            {
                return $this;
            }

            public function __toString(): string
            {
                return '';
            }
        };
    }

    public function withUri(\Psr\Http\Message\UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        return $this;
    }

    public function getRequestTarget(): string
    {
        return '/';
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        return $this;
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): RequestInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        $out = [];
        foreach ($this->headers as $name => $value) {
            $out[$name] = [$value];
        }

        return $out;
    }

    public function hasHeader(string $name): bool
    {
        foreach (\array_keys($this->headers) as $existing) {
            if (\strcasecmp($existing, $name) === 0) {
                return true;
            }
        }

        return false;
    }

    public function getHeader(string $name): array
    {
        foreach ($this->headers as $existing => $value) {
            if (\strcasecmp($existing, $name) === 0) {
                return [$value];
            }
        }

        return [];
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    public function withAddedHeader(string $name, $value): RequestInterface
    {
        return $this;
    }

    public function withoutHeader(string $name): RequestInterface
    {
        return $this;
    }

    public function getBody(): StreamInterface
    {
        return new ReadableStream($this->body);
    }

    public function withBody(StreamInterface $body): RequestInterface
    {
        return $this;
    }
}
