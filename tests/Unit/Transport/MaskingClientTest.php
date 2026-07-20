<?php

// tests/Unit/Transport/MaskingClientTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Transport\Exception\InvalidRequestException;
use Yolorouter\Llmasking\Transport\Exception\RequestReportException;
use Yolorouter\Llmasking\Transport\MaskingClient;
use Yolorouter\Llmasking\Transport\TransportOptions;

/**
 * In-memory PSR-7 StreamInterface double backed by a string.
 */
final class TestStream implements StreamInterface
{
    private int $pos = 0;

    public function __construct(
        private string $content = '',
        private bool $seekable = true,
    ) {
    }

    public function __toString(): string
    {
        return $this->content;
    }

    public function close(): void
    {
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return \strlen($this->content);
    }

    public function tell(): int
    {
        return $this->pos;
    }

    public function eof(): bool
    {
        return $this->pos >= \strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return $this->seekable;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('not seekable');
        }
        if ($whence === \SEEK_SET) {
            $this->pos = $offset;
        } elseif ($whence === \SEEK_CUR) {
            $this->pos += $offset;
        } elseif ($whence === \SEEK_END) {
            $this->pos = \strlen($this->content) + $offset;
        }
    }

    public function rewind(): void
    {
        if (!$this->seekable) {
            throw new \RuntimeException('not seekable');
        }
        $this->pos = 0;
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
        return true;
    }

    public function read(int $length): string
    {
        if ($length <= 0 || $this->pos >= \strlen($this->content)) {
            return '';
        }
        $data = \substr($this->content, $this->pos, $length);
        $this->pos += \strlen($data);

        return $data;
    }

    public function getContents(): string
    {
        $remaining = \substr($this->content, $this->pos);
        $this->pos = \strlen($this->content);

        return $remaining;
    }

    public function getMetadata(?string $key = null)
    {
        return $key !== null ? null : [];
    }
}

/**
 * Minimal UriInterface double — MaskingClient never inspects the URI path.
 */
final class TestUri implements UriInterface
{
    public function __construct(private string $path = '/')
    {
    }

    public function __toString(): string
    {
        return $this->path;
    }

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
        return $this->path;
    }

    public function getQuery(): string
    {
        return '';
    }

    public function getFragment(): string
    {
        return '';
    }

    public function withScheme(string $scheme): UriInterface
    {
        return $this;
    }

    public function withUserInfo(string $user, ?string $password = null): UriInterface
    {
        return $this;
    }

    public function withHost(string $host): UriInterface
    {
        return $this;
    }

    public function withPort(?int $port): UriInterface
    {
        return $this;
    }

    public function withPath(string $path): UriInterface
    {
        $clone = clone $this;
        $clone->path = $path;

        return $clone;
    }

    public function withQuery(string $query): UriInterface
    {
        return $this;
    }

    public function withFragment(string $fragment): UriInterface
    {
        return $this;
    }
}

/**
 * Minimal RequestInterface double with a case-insensitive header bag and
 * immutable with* mutators (clone semantics).
 */
final class TestRequest implements RequestInterface
{
    /** @var array<string, list<string>> lower-case header name => values */
    private array $headers = [];

    private string $requestTarget = '/';

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private string $method = 'POST',
        private StreamInterface $body = new TestStream(),
        private UriInterface $uri = new TestUri(),
        array $headers = [],
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[\strtolower($name)] = \is_array($value) ? \array_values($value) : [$value];
        }
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
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[\strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): RequestInterface
    {
        $clone = clone $this;
        $clone->headers[\strtolower($name)] = \is_array($value) ? \array_values($value) : [$value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): RequestInterface
    {
        $clone = clone $this;
        $key = \strtolower($name);
        $existing = $clone->headers[$key] ?? [];
        $clone->headers[$key] = \array_merge($existing, \is_array($value) ? \array_values($value) : [$value]);

        return $clone;
    }

    public function withoutHeader(string $name): RequestInterface
    {
        $clone = clone $this;
        unset($clone->headers[\strtolower($name)]);

        return $clone;
    }

    public function getBody(): StreamInterface
    {
        return $this->body;
    }

    public function withBody(StreamInterface $body): RequestInterface
    {
        $clone = clone $this;
        $clone->body = $body;

        return $clone;
    }

    public function getRequestTarget(): string
    {
        return $this->requestTarget;
    }

    public function withRequestTarget(string $requestTarget): RequestInterface
    {
        $clone = clone $this;
        $clone->requestTarget = $requestTarget;

        return $clone;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function withMethod(string $method): RequestInterface
    {
        $clone = clone $this;
        $clone->method = $method;

        return $clone;
    }

    public function getUri(): UriInterface
    {
        return $this->uri;
    }

    public function withUri(UriInterface $uri, bool $preserveHost = false): RequestInterface
    {
        $clone = clone $this;
        $clone->uri = $uri;

        return $clone;
    }
}

/**
 * Minimal ResponseInterface double mirroring TestRequest's header bag.
 */
final class TestResponse implements ResponseInterface
{
    /** @var array<string, list<string>> */
    private array $headers = [];

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        private int $status = 200,
        private StreamInterface $body = new TestStream(),
        array $headers = [],
        private string $reason = '',
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[\strtolower($name)] = \is_array($value) ? \array_values($value) : [$value];
        }
    }

    public function getProtocolVersion(): string
    {
        return '1.1';
    }

    public function withProtocolVersion(string $version): ResponseInterface
    {
        return $this;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[\strtolower($name)]);
    }

    public function getHeader(string $name): array
    {
        return $this->headers[\strtolower($name)] ?? [];
    }

    public function getHeaderLine(string $name): string
    {
        return \implode(', ', $this->getHeader($name));
    }

    public function withHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $clone->headers[\strtolower($name)] = \is_array($value) ? \array_values($value) : [$value];

        return $clone;
    }

    public function withAddedHeader(string $name, $value): ResponseInterface
    {
        $clone = clone $this;
        $key = \strtolower($name);
        $existing = $clone->headers[$key] ?? [];
        $clone->headers[$key] = \array_merge($existing, \is_array($value) ? \array_values($value) : [$value]);

        return $clone;
    }

    public function withoutHeader(string $name): ResponseInterface
    {
        $clone = clone $this;
        unset($clone->headers[\strtolower($name)]);

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

    public function getStatusCode(): int
    {
        return $this->status;
    }

    public function withStatus(int $code, string $reasonPhrase = ''): ResponseInterface
    {
        $clone = clone $this;
        $clone->status = $code;
        $clone->reason = $reasonPhrase;

        return $clone;
    }

    public function getReasonPhrase(): string
    {
        return $this->reason;
    }
}

/**
 * PSR-18 ClientInterface double that captures the outgoing request and returns
 * a caller-configured Response.
 */
final class CapturingClient implements ClientInterface
{
    public ?RequestInterface $captured = null;

    public function __construct(private readonly ResponseInterface $response)
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->captured = $request;

        return $this->response;
    }
}

/**
 * StreamFactoryInterface double backed by TestStream.
 */
final class TestStreamFactory implements StreamFactoryInterface
{
    public function createStream(string $content = ''): StreamInterface
    {
        return new TestStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        throw new \RuntimeException('not supported');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        throw new \RuntimeException('not supported');
    }
}

/**
 * Integration coverage for the PSR-18 MaskingClient (spec §9.1–§9.4).
 *
 * Verifies that free-text targets are anonymised on the outgoing request,
 * placeholders are restored on the matching JSON response, protocol/unknown
 * fields pass through byte-for-byte, gzip bodies are decoded before masking,
 * large integers keep their lexical form, and unsupported methods / media
 * types / empty bodies bypass processing entirely.
 */
final class MaskingClientTest extends TestCase
{
    private function makeEngine(): Engine
    {
        // Keyword "TARGET" → deterministic [KEYWORD_n] placeholders.
        return Engine::new(EngineOption::withKeywords('TARGET'));
    }

    private function makeFactory(): TestStreamFactory
    {
        return new TestStreamFactory();
    }

    /** @param array<string, string|list<string>> $headers */
    private function makeRequest(
        string $body,
        array $headers = [],
        string $method = 'POST',
        bool $seekable = true,
    ): TestRequest {
        return new TestRequest(
            $method,
            new TestStream($body, $seekable),
            new TestUri(),
            $headers,
        );
    }

    /** @param array<string, string|list<string>> $headers */
    private function makeResponse(string $body, array $headers = [], int $status = 200): TestResponse
    {
        return new TestResponse($status, new TestStream($body), $headers);
    }

    private function bodyString(RequestInterface|ResponseInterface $msg): string
    {
        $body = $msg->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    // ---- happy path: anonymize + restore round trip ------------------------

    public function testAnonymizeAndRestoreRoundTrip(): void
    {
        $engine = $this->makeEngine();
        $requestBody = '{"messages":[{"role":"user","content":"call TARGET now"}]}';

        // Inner client receives the masked request and replies with a JSON body
        // that contains the placeholder → MaskingClient must restore it.
        $responseBody = '{"choices":[{"message":{"content":"got [KEYWORD_1] thanks"}}]}';

        $responder = $this->makeResponse($responseBody, ['Content-Type' => 'application/json']);
        $inner = new CapturingClient($responder);
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest($requestBody, ['Content-Type' => 'application/json']);
        $response = $client->sendRequest($request);

        // Request side: TARGET masked, protocol role untouched.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        $sentBody = $this->bodyString($captured);
        self::assertStringContainsString('"content":"call [KEYWORD_1] now"', $sentBody);
        self::assertStringContainsString('"role":"user"', $sentBody);

        // Response side: placeholder restored to the original plaintext.
        $restored = $this->bodyString($response);
        self::assertStringContainsString('"content":"got TARGET thanks"', $restored);
    }

    // ---- non-JSON / non-POST passthrough -----------------------------------

    public function testNonPostMethodPassthrough(): void
    {
        $engine = $this->makeEngine();
        $responseBody = '{"ok":1}';
        $inner = new CapturingClient($this->makeResponse($responseBody, ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{"content":"TARGET"}', ['Content-Type' => 'application/json'], 'GET');
        $response = $client->sendRequest($request);

        // Request forwarded untouched; response returned untouched.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame('GET', $captured->getMethod());
        self::assertSame('{"content":"TARGET"}', $this->bodyString($captured));
        self::assertSame($responseBody, $this->bodyString($response));
    }

    public function testNonJsonContentTypePassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('', ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{"content":"TARGET"}', ['Content-Type' => 'text/plain']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        // Body untouched: no masking performed.
        self::assertSame('{"content":"TARGET"}', $this->bodyString($captured));
    }

    public function testApplicationJsonWithCharsetIsSupported(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient(
            $this->makeResponse('{"choices":[]}', ['Content-Type' => 'application/json']),
        );
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            '{"messages":[{"content":"TARGET"}]}',
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertStringContainsString('[KEYWORD_1]', $this->bodyString($captured));
    }

    // ---- unknown / protocol fields preserved ------------------------------

    public function testUnknownTopLevelFieldsPreserved(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $body = '{"messages":[{"content":"TARGET"}],"custom_vendor":{"secret":"AKIA-XYZ"}}';
        $request = $this->makeRequest($body, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        $sent = $this->bodyString($captured);
        // Only the target content was masked; vendor field untouched.
        self::assertStringContainsString('"content":"[KEYWORD_1]"', $sent);
        self::assertStringContainsString('"secret":"AKIA-XYZ"', $sent);
    }

    public function testProtocolFieldsPreservedByteForByte(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $body = '{"model":"gpt-4","temperature":0.7,"user":"12345","messages":[{"role":"user","content":"hi TARGET"}]}';
        $request = $this->makeRequest($body, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        $sent = $this->bodyString($captured);
        self::assertStringContainsString('"model":"gpt-4"', $sent);
        self::assertStringContainsString('"temperature":0.7', $sent);
        self::assertStringContainsString('"user":"12345"', $sent);
        self::assertStringContainsString('"role":"user"', $sent);
        self::assertStringContainsString('"content":"hi [KEYWORD_1]"', $sent);
    }

    // ---- gzip --------------------------------------------------------------

    public function testGzipRequestDecodedAndSentPlain(): void
    {
        $engine = $this->makeEngine();
        $plain = '{"messages":[{"content":"call TARGET"}]}';
        $compressed = \gzencode($plain);
        self::assertNotFalse($compressed);

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            $compressed,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        // Content-Encoding removed; body is decompressed + masked.
        self::assertSame([], $captured->getHeader('Content-Encoding'));
        self::assertStringContainsString('"content":"call [KEYWORD_1]"', $this->bodyString($captured));
    }

    public function testGzipRequestNoPatchesKeepsOriginalWire(): void
    {
        $engine = $this->makeEngine();
        $plain = '{"model":"gpt-4"}'; // No targets → no patches.
        $compressed = \gzencode($plain);
        self::assertNotFalse($compressed);

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            $compressed,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        // Original gzip wire + Content-Encoding preserved (spec §9.3).
        self::assertSame(['gzip'], $captured->getHeader('Content-Encoding'));
        self::assertSame($compressed, $this->bodyString($captured));
    }

    public function testCorruptGzipFailsClosedWithoutPassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('{}'));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            'not gzip at all',
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest($request);
    }

    public function testCorruptGzipPassthroughWhenConfigured(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine, TransportOptions::withPassthrough());

        $request = $this->makeRequest(
            'not gzip at all',
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );
        $response = $client->sendRequest($request);

        // Original wire + headers forwarded; response untouched.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame('not gzip at all', $this->bodyString($captured));
        self::assertSame(['gzip'], $captured->getHeader('Content-Encoding'));
        // Response untouched (no Session created).
        self::assertSame('{}', $this->bodyString($response));
    }

    public function testGzipMultiMemberRequestDecodedAndSentPlain(): void
    {
        // Concatenated gzip members (RFC 1952 §2.2) must all be inflated; the
        // previous single-inflater implementation silently dropped every member
        // after the first.
        $engine = $this->makeEngine();
        $part1 = '{"messages":';
        $part2 = '[{"content":"call TARGET"}]}';
        $compressed = \gzencode($part1) . \gzencode($part2);
        self::assertNotFalse($compressed);

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            $compressed,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        // Content-Encoding dropped; both members decoded + target masked.
        self::assertSame([], $captured->getHeader('Content-Encoding'));
        self::assertSame(
            '{"messages":[{"content":"call [KEYWORD_1]"}]}',
            $this->bodyString($captured),
        );
    }

    public function testGzipTrailingGarbageFailsClosedWithoutPassthrough(): void
    {
        // Bytes after a complete gzip member that are not themselves a valid gzip
        // member must be rejected rather than silently ignored.
        $engine = $this->makeEngine();
        $compressed = \gzencode('{"messages":[{"content":"TARGET"}]}') . 'TRAILING-GARBAGE';
        $inner = new CapturingClient($this->makeResponse('{}'));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            $compressed,
            ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'],
        );

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest($request);
    }

    // ---- large-integer preservation ---------------------------------------

    public function testLargeIntegerLexicalPreservation(): void
    {
        $engine = $this->makeEngine();
        $big = '123456789012345678901234567890';
        $body = '{"id":' . $big . ',"messages":[{"content":"TARGET"}]}';

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest($body, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        $sent = $this->bodyString($captured);
        // Large integer preserved byte-for-byte (not converted to float).
        self::assertStringContainsString('{"id":' . $big . ',', $sent);
        self::assertStringContainsString('"content":"[KEYWORD_1]"', $sent);
    }

    // ---- empty / whitespace body bypass -----------------------------------

    public function testEmptyBodyPassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('', ['Content-Type' => 'application/json']);
        $response = $client->sendRequest($request);

        // Empty body forwarded untouched; response not restored.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame('', $this->bodyString($captured));
        self::assertSame('{}', $this->bodyString($response));
    }

    public function testWhitespaceOnlyBodyPassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient(
            $this->makeResponse('  \n  ', ['Content-Type' => 'application/json']),
        );
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $whitespace = "  \t\n\r ";
        $request = $this->makeRequest($whitespace, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame($whitespace, $this->bodyString($captured));
    }

    // ---- JSON syntax / fail-closed ----------------------------------------

    public function testInvalidJsonFailsClosedWithoutPassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{not valid json', ['Content-Type' => 'application/json']);

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest($request);
    }

    public function testInvalidJsonPassthroughWhenConfigured(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('ok', ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine, TransportOptions::withPassthrough());

        $bad = '{not valid json';
        $request = $this->makeRequest($bad, ['Content-Type' => 'application/json']);
        $response = $client->sendRequest($request);

        // Original body forwarded untouched; response untouched.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame($bad, $this->bodyString($captured));
        self::assertSame('ok', $this->bodyString($response));
    }

    // ---- non-seekable body -------------------------------------------------

    public function testNonSeekableBodyFailsClosedWithoutPassthrough(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest(
            '{"messages":[{"content":"TARGET"}]}',
            ['Content-Type' => 'application/json'],
            'POST',
            false,
        );

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest($request);
    }

    public function testNonSeekableBodyPassthroughWhenConfigured(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse('ok', ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine, TransportOptions::withPassthrough());

        $body = '{"messages":[{"content":"TARGET"}]}';
        $request = $this->makeRequest($body, ['Content-Type' => 'application/json'], 'POST', false);
        $response = $client->sendRequest($request);

        // Original untouched object forwarded; response untouched.
        $captured = $inner->captured;
        self::assertNotNull($captured);
        self::assertSame($body, $this->bodyString($captured));
        self::assertSame('ok', $this->bodyString($response));
    }

    // ---- response passthrough ---------------------------------------------

    public function testNonJsonResponsePassthrough(): void
    {
        $engine = $this->makeEngine();
        // Even though the request was processed (Session created), the response
        // Content-Type is text/plain → returned untouched, placeholders NOT
        // restored.
        $responseBody = '{"content":"[KEYWORD_1] unreplaced"}';
        $inner = new CapturingClient($this->makeResponse($responseBody, ['Content-Type' => 'text/plain']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{"messages":[{"content":"TARGET"}]}', ['Content-Type' => 'application/json']);
        $response = $client->sendRequest($request);

        self::assertSame($responseBody, $this->bodyString($response));
    }

    public function testNoTargetsSendsOriginalRequestBody(): void
    {
        $engine = $this->makeEngine();
        $body = '{"model":"gpt-4","temperature":0.5}';

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest($body, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        // No patches → original body bytes forwarded unchanged.
        self::assertSame($body, $this->bodyString($captured));
    }

    // ---- header rewrite ----------------------------------------------------

    public function testContentLengthUpdatedOnRewrite(): void
    {
        $engine = $this->makeEngine();
        $body = '{"messages":[{"content":"TARGET"}]}';

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest($body, ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        $captured = $inner->captured;
        self::assertNotNull($captured);
        $sentBody = $this->bodyString($captured);
        // Content-Length must match the masked body byte count.
        $expectedLength = (string) \strlen($sentBody);
        self::assertSame($expectedLength, $captured->getHeaderLine('Content-Length'));
    }

    public function testIntegrityHeaderBlocksRewrite(): void
    {
        $engine = $this->makeEngine();
        $body = '{"messages":[{"content":"TARGET"}]}';

        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest($body, [
            'Content-Type' => 'application/json',
            'Content-MD5' => 'abc123',
        ]);

        $this->expectException(InvalidRequestException::class);
        $client->sendRequest($request);
    }

    // ---- report callbacks --------------------------------------------------

    public function testMaskReportCallbackFiresOnPatches(): void
    {
        $engine = $this->makeEngine();
        $fired = false;
        $receivedEvents = [];

        $callback = function (RequestInterface $req, array $events) use (&$fired, &$receivedEvents): void {
            $fired = true;
            $receivedEvents = $events;
        };

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient(
            $inner,
            $this->makeFactory(),
            $engine,
            TransportOptions::withMaskReport($callback),
        );

        $request = $this->makeRequest('{"messages":[{"content":"TARGET"}]}', ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        self::assertTrue($fired);
        self::assertCount(1, $receivedEvents);
        self::assertSame('KEYWORD', $receivedEvents[0]->entity);
        self::assertSame('[KEYWORD_1]', $receivedEvents[0]->replacement);
    }

    public function testMaskReportCallbackNotFiredOnNoPatches(): void
    {
        $engine = $this->makeEngine();
        $fired = false;
        $callback = function (RequestInterface $req, array $events) use (&$fired): void {
            $fired = true;
        };

        $inner = new CapturingClient($this->makeResponse('{}', ['Content-Type' => 'application/json']));
        $client = new MaskingClient(
            $inner,
            $this->makeFactory(),
            $engine,
            TransportOptions::withMaskReport($callback),
        );

        $request = $this->makeRequest('{"model":"gpt-4"}', ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        self::assertFalse($fired);
    }

    public function testMaskReportCallbackExceptionWrappedAsRequestReport(): void
    {
        $engine = $this->makeEngine();
        $callback = function (RequestInterface $req, array $events): void {
            throw new \RuntimeException('callback boom');
        };

        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient(
            $inner,
            $this->makeFactory(),
            $engine,
            TransportOptions::withMaskReport($callback),
        );

        $request = $this->makeRequest('{"messages":[{"content":"TARGET"}]}', ['Content-Type' => 'application/json']);

        $this->expectException(RequestReportException::class);
        $client->sendRequest($request);
    }

    public function testRestoreReportCallbackFiresOnRestore(): void
    {
        $engine = $this->makeEngine();
        $fired = false;
        $receivedEvents = [];
        $complete = null;

        $callback = function (
            RequestInterface $req,
            array $events,
            bool $isComplete,
            ?\Throwable $error,
        ) use (&$fired, &$receivedEvents, &$complete): void {
            $fired = true;
            $receivedEvents = $events;
            $complete = $isComplete;
        };

        $responseBody = '{"choices":[{"message":{"content":"echo [KEYWORD_1]"}}]}';
        $inner = new CapturingClient($this->makeResponse($responseBody, ['Content-Type' => 'application/json']));
        $client = new MaskingClient(
            $inner,
            $this->makeFactory(),
            $engine,
            TransportOptions::withRestoreReport($callback),
        );

        $request = $this->makeRequest('{"messages":[{"content":"TARGET"}]}', ['Content-Type' => 'application/json']);
        $client->sendRequest($request);

        self::assertTrue($fired);
        self::assertTrue($complete);
        self::assertCount(1, $receivedEvents);
        self::assertTrue($receivedEvents[0]->restored);
    }

    // ---- exception semantics ----------------------------------------------

    public function testInvalidRequestExceptionCarriesRequest(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{bad', ['Content-Type' => 'application/json']);

        try {
            $client->sendRequest($request);
            self::fail('expected InvalidRequestException');
        } catch (InvalidRequestException $e) {
            self::assertSame($request, $e->getRequest());
            self::assertStringNotContainsString('{bad', $e->getMessage());
        }
    }

    public function testInvalidRequestExceptionImplementsPsr18(): void
    {
        $engine = $this->makeEngine();
        $inner = new CapturingClient($this->makeResponse(''));
        $client = new MaskingClient($inner, $this->makeFactory(), $engine);

        $request = $this->makeRequest('{bad', ['Content-Type' => 'application/json']);

        try {
            $client->sendRequest($request);
            self::fail('expected exception');
        } catch (InvalidRequestException $e) {
            self::assertInstanceOf(
                \Psr\Http\Client\RequestExceptionInterface::class,
                $e,
            );
        }
    }
}
