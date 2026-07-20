<?php

// src/Transport/MaskingClient.php

namespace Yolorouter\Llmasking\Transport;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\MaskEvent;
use Yolorouter\Llmasking\RestoreEvent;
use Yolorouter\Llmasking\Session;
use Yolorouter\Llmasking\Transport\Exception\InvalidRequestException;
use Yolorouter\Llmasking\Transport\Exception\RequestReportException;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;
use Yolorouter\Llmasking\Internal\JsonDocument;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\ProcessedRequest;
use Yolorouter\Llmasking\Internal\JsonSyntaxException;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\Internal\RequestWalker;
use Yolorouter\Llmasking\Internal\ResponseWalker;

/**
 * PSR-18 decorator that masks free-text strings in supported outgoing requests
 * and restores placeholders in the matching JSON responses, all via the same
 * per-request {@see Session} (spec §9.1–§9.4).
 *
 * Supported processing path (spec §9.2):
 *   - Method POST AND Content-Type media type exactly application/json.
 *   - Content-Encoding empty, identity or gzip.
 *
 * Any other method / media type / encoding is a normal passthrough: the
 * original request is handed to the inner client untouched, and the response is
 * returned untouched (no Session is created, no response body is read).
 *
 * Fail-closed applies only to the library's own read/parse/mask/restore and
 * resource-budget failures; it is NEVER used to validate API parameter
 * semantics (CLAUDE.md rule #1). Unsupported method/media/encoding is normal
 * bypass, not an exception.
 */
final class MaskingClient implements ClientInterface
{
    /**
     * Fixed cap on the accumulated entity+replacement+source bytes of the
     * transport MaskEvents (spec §5.1). Exceeding it before send fails the
     * whole request.
     */
    private const MAX_MASK_REPORT_BYTES = 16 << 20;

    /**
     * Header names that, if present on a request whose body is about to be
     * rewritten, force a fail-closed InvalidRequestException: silently dropping
     * or invalidating a body digest / signature is not permitted (spec §9.2).
     */
    private const INTEGRITY_HEADERS = [
        'Content-MD5',
        'Digest',
        'Content-Digest',
        'Signature',
        'Signature-Input',
    ];

    /**
     * Response-side body digest / signature headers. When the response body is
     * actually rewritten (JSON patches or SSE transform), the digest can no
     * longer be verified, so per spec §9.4 the response degrades to a failed
     * body rather than silently carrying a stale signature. The set matches the
     * request-side {@see INTEGRITY_HEADERS}; one constant covers both.
     */

    /**
     * Representation headers removed from a failed-body response (spec §9.4):
     * they no longer describe the replacement body.
     */
    private const FAILED_BODY_REPR_HEADERS = [
        'Content-Type',
        'Content-Length',
        'Content-Encoding',
        'Transfer-Encoding',
        'Content-Range',
        'ETag',
        'Content-MD5',
        'Digest',
        'Content-Digest',
        'Signature',
        'Signature-Input',
    ];

    /**
     * Per-call input slice fed to the gzip inflater. Kept small so the decoded
     * budget is re-checked before a single inflate_add return value can
     * materialise an outsized plaintext (spec §9.2 compression-bomb defence).
     */
    private const GUNZIP_PIECE = 4096;

    private readonly bool $passthrough;

    /** @var callable(RequestInterface, list<MaskEvent>): void|null */
    private readonly mixed $maskCallback;

    /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void|null */
    private readonly mixed $restoreCallback;

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly Engine $engine,
        TransportOptions ...$opts,
    ) {
        $passthrough = false;
        $maskCallback = null;
        $restoreCallback = null;
        foreach ($opts as $opt) {
            if ($opt->isPassthrough()) {
                $passthrough = true;
            } elseif ($opt->maskCallback() !== null) {
                $maskCallback = $opt->maskCallback();
            } elseif ($opt->restoreCallback() !== null) {
                $restoreCallback = $opt->restoreCallback();
            }
        }
        $this->passthrough = $passthrough;
        $this->maskCallback = $maskCallback;
        $this->restoreCallback = $restoreCallback;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $processed = $this->processRequest($request);

        if ($processed->session === null) {
            // Normal bypass / passthrough: forward the request unchanged, and
            // return the response completely untouched (spec §9.1: no Session
            // is retained, response is not read or restored).
            return $this->inner->sendRequest($processed->outgoingRequest);
        }

        // Build the restore-report snapshot BEFORE sending so a stream-factory
        // failure is observable before the POST fires (no post-send escape that
        // would lose a successful response and risk a retry's duplicate side
        // effect) (codex med).
        $snapshot = $this->restoreCallback !== null
            ? $this->snapshotRequest($processed->outgoingRequest, $processed->outgoingBodyBytes)
            : null;

        $response = $this->inner->sendRequest($processed->outgoingRequest);

        return $this->processResponse(
            $response,
            $processed->session,
            $processed->outgoingRequest,
            $processed->outgoingBodyBytes,
            $snapshot,
        );
    }

    /**
     * Entry point for request-side processing. Returns the outgoing request to
     * forward and, when masking actually happened, the per-request Session that
     * must be used to restore the matching response.
     */
    private function processRequest(RequestInterface $request): ProcessedRequest
    {
        // 1. Method gate: only POST enters the supported path.
        if (\strcasecmp($request->getMethod(), 'POST') !== 0) {
            return new ProcessedRequest($request, null, '');
        }

        // 2. Content-Type gate: exact application/json (parameters allowed,
        //    type/subtype case-insensitive per RFC). Multiple conflicting
        //    Content-Type values cannot be determined → passthrough.
        if (!$this->isExactApplicationJson($request)) {
            return new ProcessedRequest($request, null, '');
        }

        // 3. Content-Encoding gate: empty/identity/gzip supported; combined or
        //    unknown encodings are passthrough.
        $encoding = $this->parseContentEncoding($request);
        if ($encoding === null) {
            return new ProcessedRequest($request, null, '');
        }

        // 4. Supported path requires a seekable body so we can rewind after the
        //    bounded read (spec §9.2). Non-seekable bodies: passthrough when
        //    WithPassthrough is set, fail-closed otherwise.
        $body = $request->getBody();
        if (!$body->isSeekable()) {
            if ($this->passthrough) {
                return new ProcessedRequest($request, null, '');
            }
            throw new InvalidRequestException($request, 'request body is not seekable');
        }

        // 5. Rewind + bounded read (MaxInputBytes + 1 so an off-by-one detects
        //    the oversize case).
        $maxInput = $this->engine->maxInputBytes;
        try {
            // The initial rewind sits inside the same try so a rewind failure
            // (PSR-7 allows it to throw) becomes the fail-closed result too.
            $body->rewind();
            $rawBody = $this->readBounded($body, $maxInput + 1);
        } catch (\Throwable $e) {
            // A stall / read failure is NOT a normal bypass: fail closed so the
            // body is never forwarded unmasked (spec §9.2 / codex high). The
            // best-effort rewind must not mask the original failure.
            try {
                $body->rewind();
            } catch (\Throwable) {
                // best effort
            }
            throw new InvalidRequestException($request, 'request body read stalled or failed', 0, $e);
        }
        if (\strlen($rawBody) > $maxInput) {
            self::rewindBestEffort($body);
            // Resource-limit failure is NOT covered by WithPassthrough (spec §9.2).
            throw new InvalidRequestException(
                $request,
                'request body exceeds MaxInputBytes (' . $maxInput . ')',
            );
        }

        // 6. gzip decompression (bounded, spec §9.2). The incremental inflater
        //    enforces the decoded budget without first materialising an
        //    unbounded plaintext.
        if ($encoding === 'gzip') {
            try {
                $decoded = $this->gunzipBounded($rawBody, $maxInput);
            } catch (LimitExceededException $e) {
                self::rewindBestEffort($body);
                throw new InvalidRequestException(
                    $request,
                    'gzip decoded body exceeds MaxInputBytes',
                    0,
                    $e,
                );
            } catch (\RuntimeException $e) {
                // gzip format/corruption error. WithPassthrough may forward the
                // original compressed wire untouched; otherwise fail-closed.
                if ($this->passthrough) {
                    $this->rewindForForward($body, $request, "cannot restore request body for forwarding");
                    return new ProcessedRequest($request, null, '');
                }
                self::rewindBestEffort($body);
                throw new InvalidRequestException($request, 'gzip decode failed', 0, $e);
            }
        } else {
            $decoded = $rawBody;
        }

        // 7. Empty / JSON-whitespace-only body is a "no maskable content"
        //    bypass (spec §9.2). Original wire/header preserved; the response
        //    is not restored.
        if ($this->isJsonWhitespace($decoded)) {
            $this->rewindForForward($body, $request, "cannot restore request body for forwarding");
            return new ProcessedRequest($request, null, '');
        }

        // 8. Tokenize JSON (spec §9.2). Resource-limit errors are always
        //    fail-closed; JSON syntax errors are fail-closed unless
        //    WithPassthrough is set.
        try {
            $doc = JsonTokenizer::parse($decoded);
        } catch (LimitExceededException $e) {
            self::rewindBestEffort($body);
            throw new InvalidRequestException(
                $request,
                'JSON tokenizer resource limit exceeded',
                0,
                $e,
            );
        } catch (JsonSyntaxException $e) {
            if ($this->passthrough) {
                $this->rewindForForward($body, $request, "cannot restore request body for forwarding");
                return new ProcessedRequest($request, null, '');
            }
            self::rewindBestEffort($body);
            throw new InvalidRequestException($request, 'invalid JSON syntax', 0, $e);
        }

        // 9. Per-request Session + walk targets (spec §9.3). Collect events
        //    only when a mask report callback is configured.
        $session = $this->engine->newSession();
        $maskEvents = [];
        try {
            if ($this->maskCallback !== null) {
                [$patches, $maskEvents] = RequestWalker::walkWithEvents($doc, $session);
            } else {
                $patches = RequestWalker::walk($doc, $session);
            }

            // 10. Apply patches (reverse-order span replacement, spec §9.3).
            if ($patches !== []) {
                $newBody = JsonPatch::apply($decoded, $patches);
            } else {
                $newBody = $decoded;
            }
        } catch (\Throwable $e) {
            self::rewindBestEffort($body);
            throw new InvalidRequestException(
                $request,
                'masking failed during request processing',
                0,
                $e,
            );
        }

        // 11. Final request body budget (spec §5.1: anonymize/mask/final
        //     request output bounded by MaxOutputBytes).
        if (\strlen($newBody) > $this->engine->maxOutputBytes) {
            self::rewindBestEffort($body);
            throw new InvalidRequestException(
                $request,
                'masked request body exceeds MaxOutputBytes',
            );
        }

        // 12. If the body actually changed, rewrite the request: new body
        //     stream, Content-Length, drop Content-Encoding (when gzip) and
        //     Transfer-Encoding. When nothing changed, forward the ORIGINAL
        //     wire bytes (gzip wire is preserved as-is per spec §9.3).
        $bodyChanged = $patches !== [];
        if (!$bodyChanged) {
            $this->rewindForForward($body, $request, "cannot restore request body for forwarding");
            return new ProcessedRequest($request, $session, $rawBody);
        }

        // Integrity / signature headers would be invalidated by the rewrite.
        if ($this->hasIntegrityHeaders($request)) {
            self::rewindBestEffort($body);
            throw new InvalidRequestException(
                $request,
                'cannot rewrite body: request carries integrity/signature headers',
            );
        }

        $outgoingRequest = $request
            ->withBody($this->streamFactory->createStream($newBody))
            ->withHeader('Content-Length', (string) \strlen($newBody))
            ->withoutHeader('Transfer-Encoding');
        if ($encoding === 'gzip') {
            $outgoingRequest = $outgoingRequest->withoutHeader('Content-Encoding');
        }

        // 13. Mask report callback (spec §9.7). Fires only when patches
        //     exist. Receives an independent body snapshot.
        if ($this->maskCallback !== null) {
            $transportEvents = $this->toTransportMaskEvents($maskEvents);
            $this->enforceMaskReportBudget($transportEvents, $outgoingRequest);
            if ($transportEvents !== []) {
                $snapshot = $this->snapshotRequest($outgoingRequest, $newBody);
                /** @var callable(RequestInterface, list<MaskEvent>): void $cb */
                $cb = $this->maskCallback;
                try {
                    $cb($snapshot, $transportEvents);
                } catch (\Throwable $e) {
                    throw new RequestReportException(
                        'mask report callback failed',
                        0,
                        $e,
                    );
                }
            }
        }

        return new ProcessedRequest($outgoingRequest, $session, $newBody);
    }

    /**
     * Restore a JSON or SSE response (spec §9.4–§9.5) using the Session created
     * during the request phase. Non-JSON / non-SSE / non-identity responses are
     * returned untouched.
     */
    private function processResponse(
        ResponseInterface $response,
        Session $session,
        RequestInterface $maskedRequest,
        string $outgoingBodyBytes,
        ?RequestInterface $snapshot,
    ): ResponseInterface {
        // Content-Encoding must be empty/identity (spec §9.4: non-identity v1
        // passthrough; avoid corrupting compressed bytes).
        $encoding = $this->parseContentEncoding($response);
        if ($encoding !== 'identity') {
            return $response;
        }

        // SSE response path (spec §9.5): exact text/event-stream drives an
        // SseRestorer through a forward-only RestoringStream body.
        if ($this->isExactTextEventStream($response)) {
            return $this->processSseResponse($response, $session, $maskedRequest, $outgoingBodyBytes, $snapshot);
        }

        if (!$this->isExactApplicationJson($response)) {
            return $response;
        }

        // Partial content cannot be safely restored (spec §9.4).
        $respBody = $response->getBody();
        if ($response->getStatusCode() === 206 || $response->hasHeader('Content-Range')) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('partial content (206 / Content-Range) cannot be restored'),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        $maxOutput = $this->engine->maxOutputBytes;
        // Spec §9.4: any failure while reading the response body (rewind/read)
        // must degrade to a failed body; it must not escape sendRequest().
        try {
            if ($respBody->isSeekable()) {
                $respBody->rewind();
            }
            $rawBody = $this->readBounded($respBody, $maxOutput + 1);
        } catch (\Throwable $e) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('response body read failed', 0, $e),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }
        if (\strlen($rawBody) > $maxOutput) {
            // Body beyond the read budget: a restore budget failure degrades to
            // a failed body (spec §9.4).
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('response body exceeds MaxOutputBytes'),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        if ($this->isJsonWhitespace($rawBody)) {
            // Empty / JSON-whitespace-only body has nothing to restore: return a
            // raw-equivalent body and keep headers (spec §9.4).
            return $this->withRawBody($response, $respBody, $rawBody);
        }

        try {
            $doc = JsonTokenizer::parse($rawBody);
        } catch (LimitExceededException | JsonSyntaxException $e) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('response JSON parse failed', 0, $e),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        $restoreEvents = [];
        try {
            if ($this->restoreCallback !== null) {
                [$patches, $restoreEvents] = ResponseWalker::walkWithEvents($doc, $session);
            } else {
                $patches = ResponseWalker::walk($doc, $session);
            }
        } catch (\Yolorouter\Llmasking\Exception\LlmaskingException $e) {
            $wrapped = $e instanceof StreamRestoreException
                ? $e
                : new StreamRestoreException('restore failed: ' . $e->getMessage(), 0, $e);

            return $this->withFailedBody(
                $response,
                $respBody,
                $wrapped,
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        if ($patches === []) {
            // No body change, but unresolved-placeholder events (restored=false)
            // may still exist and must reach the restore callback (spec §9.7:
            // success with events fires complete=true; codex #12).
            $rawResponse = $this->withRawBody($response, $respBody, $rawBody);
            if ($this->restoreCallback !== null && $restoreEvents !== [] && $snapshot !== null) {
                $transportEvents = $this->toTransportRestoreEvents($restoreEvents);
                /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $cb */
                $cb = $this->restoreCallback;
                try {
                    $cb($snapshot, $transportEvents, true, null);
                } catch (\Throwable $e) {
                    return $this->withFailedBody(
                        $rawResponse,
                        $rawResponse->getBody(),
                        new StreamRestoreException('restore report callback failed', 0, $e),
                        $maskedRequest,
                        $outgoingBodyBytes,
                        true,
                    );
                }
            }

            return $rawResponse;
        }

        // Integrity / signature headers would be invalidated by the rewrite
        // (spec §9.4): degrade to a failed body instead of carrying a stale
        // signature on a "successful" response.
        if ($this->hasResponseIntegrityHeaders($response)) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('cannot rewrite body: response carries integrity/signature headers'),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        // Spec §9.4: the body transform (patch apply + factory + header rewrite)
        // must degrade to a failed body on any failure rather than escape
        // sendRequest().
        try {
            $newBody = JsonPatch::apply($rawBody, $patches);
            if (\strlen($newBody) > $maxOutput) {
                return $this->withFailedBody(
                    $response,
                    $respBody,
                    new StreamRestoreException('restored response body exceeds MaxOutputBytes'),
                    $maskedRequest,
                    $outgoingBodyBytes,
                );
            }

            $newResponse = $response
                ->withBody($this->streamFactory->createStream($newBody))
                ->withHeader('Content-Length', (string) \strlen($newBody))
                ->withoutHeader('Transfer-Encoding')
                ->withoutHeader('ETag');
        } catch (\Throwable $e) {
            return $this->withFailedBody(
                $response,
                $respBody,
                $e instanceof StreamRestoreException
                    ? $e
                    : new StreamRestoreException('response body transform failed', 0, $e),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        // The consumed original body has been replaced: close it so it is not
        // left exposed at EOF (spec §9.4: must not return a body sitting at EOF,
        // and the replaced upstream must be released).
        try {
            $respBody->close();
        } catch (\Throwable) {
            // best effort
        }

        if ($this->restoreCallback !== null && $restoreEvents !== [] && $snapshot !== null) {
            $transportEvents = $this->toTransportRestoreEvents($restoreEvents);
            /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $cb */
            $cb = $this->restoreCallback;
            try {
                $cb($snapshot, $transportEvents, true, null);
            } catch (\Throwable $e) {
                // Spec §9.7: a callback failure on JSON converts the response
                // into a terminal failed body (no ClientException escapes).
                return $this->withFailedBody(
                    $newResponse,
                    $newResponse->getBody(),
                    new StreamRestoreException('restore report callback failed', 0, $e),
                    $maskedRequest,
                    $outgoingBodyBytes,
                    true,
                );
            }
        }

        return $newResponse;
    }

    /**
     * SSE response path (spec §9.5): wrap the upstream body in an
     * {@see SseRestorer} driven through a forward-only {@see RestoringStream}.
     * Partial content and response integrity headers degrade to a failed body.
     */
    private function processSseResponse(
        ResponseInterface $response,
        Session $session,
        RequestInterface $maskedRequest,
        string $outgoingBodyBytes,
        ?RequestInterface $snapshot,
    ): ResponseInterface {
        $respBody = $response->getBody();

        // The SSE path must read from the body's start: a seekable body whose
        // cursor is past 0 (a compliant inner client may return one) would
        // silently drop prefix events. Rewind seekable bodies; a rewind failure
        // degrades to a failed body (spec §9.4 / codex med).
        if ($respBody->isSeekable()) {
            try {
                $respBody->rewind();
            } catch (\Throwable $e) {
                return $this->withFailedBody(
                    $response,
                    $respBody,
                    new StreamRestoreException('SSE response body rewind failed', 0, $e),
                    $maskedRequest,
                    $outgoingBodyBytes,
                );
            }
        }

        if ($response->getStatusCode() === 206 || $response->hasHeader('Content-Range')) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('partial SSE content (206 / Content-Range) cannot be restored'),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        if ($this->hasResponseIntegrityHeaders($response)) {
            return $this->withFailedBody(
                $response,
                $respBody,
                new StreamRestoreException('cannot restore SSE: response carries integrity/signature headers'),
                $maskedRequest,
                $outgoingBodyBytes,
            );
        }

        $maxOut = $this->engine->maxOutputBytes;
        // materializeBudget = min(singleLineBudget, 16 MiB) (spec §9.6), where
        // singleLineBudget = max(maxOutputBytes + 4096, 64 KiB) (spec §9.5).
        $singleLineBudget = max($maxOut + 4096, 64 * 1024);
        $materializeBudget = min($singleLineBudget, 16 << 20);

        $restorer = new SseRestorer($session, $this->restoreCallback !== null);

        $completion = null;
        if ($this->restoreCallback !== null && $snapshot !== null) {
            /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $cb */
            $cb = $this->restoreCallback;
            $completion = static function (array $events, bool $complete, ?\Throwable $error) use ($snapshot, $cb): void {
                // Spec §9.7: success with zero events does not invoke the
                // callback; a failure invokes it even with zero events.
                if ($events === [] && $complete && $error === null) {
                    return;
                }
                try {
                    $cb($snapshot, $events, $complete, $error);
                } catch (\Throwable) {
                    // Spec §9.7: a failing SSE callback surfaces as a
                    // StreamRestoreException on the next read. The
                    // RestoringStream is already terminal here, so the
                    // exception is absorbed to keep the PSR-7 contract.
                }
            };
        }

        $stream = RestoringStream::forSse($restorer, $respBody, $materializeBudget, $completion);

        // The restored SSE length is unknown up front (placeholders expand);
        // drop the now-inaccurate length/transfer/tag headers, keep Content-Type.
        return $response
            ->withBody($stream)
            ->withoutHeader('Content-Length')
            ->withoutHeader('Transfer-Encoding')
            ->withoutHeader('ETag');
    }

    /**
     * Build a terminal failed-body response (spec §9.4): the body becomes a
     * {@see RestoringStream::forFailedBody()} whose read()/getContents() raise
     * the saved StreamRestoreException; representation/integrity headers that no
     * longer describe the body are stripped; and the restore report callback
     * fires once with complete=false, events=[], error=$error.
     */
    private function withFailedBody(
        ResponseInterface $response,
        StreamInterface $originalBody,
        StreamRestoreException $error,
        RequestInterface $maskedRequest,
        string $outgoingBodyBytes,
        bool $suppressCallback = false,
    ): ResponseInterface {
        // Close the consumed original body so it is not left exposed at EOF
        // (spec §9.4: must not return a body sitting at EOF).
        try {
            $originalBody->close();
        } catch (\Throwable) {
            // best effort
        }

        // Restore report fires BEFORE the failed body is constructed, so a
        // callback exception becomes the body's terminal error (spec §9.7:
        // callback failure converts the body; the caller's read() must see it).
        // Skipped when $suppressCallback is true (the caller IS the callback-
        // failure handler — no recursion).
        $finalError = $error;
        if (!$suppressCallback && $this->restoreCallback !== null) {
            // Build the snapshot, but never let a stream-factory failure escape
            // after the inner POST has already fired (it would lose the response
            // and risk a retry's duplicate side effect). If the snapshot cannot
            // be built, skip the failure report and still return the failed body.
            try {
                $snapshot = $this->snapshotRequest($maskedRequest, $outgoingBodyBytes);
            } catch (\Throwable) {
                $snapshot = null;
            }
            if ($snapshot !== null) {
                /** @var callable(RequestInterface, list<RestoreEvent>, bool, ?\Throwable): void $cb */
                $cb = $this->restoreCallback;
                try {
                    $cb($snapshot, [], false, $error);
                } catch (\Throwable $cbErr) {
                    $finalError = new StreamRestoreException(
                        'restore report callback failed',
                        0,
                        $cbErr,
                    );
                }
            }
        }

        $failed = RestoringStream::forFailedBody($finalError);
        $new = $response->withBody($failed);
        foreach (self::FAILED_BODY_REPR_HEADERS as $header) {
            $new = $new->withoutHeader($header);
        }

        return $new;
    }

    // ---- Header / encoding parsing ----------------------------------------

    /**
     * Exact application/json check (spec §9.2). Parameters such as charset are
     * allowed; type/subtype comparison is case-insensitive. Multiple
     * conflicting Content-Type header lines cannot be resolved → false.
     */
    private function isExactApplicationJson(MessageInterface $msg): bool
    {
        return $this->isExactMediaType($msg, 'application/json');
    }

    /**
     * Exact text/event-stream check (spec §9.5). Same single-value / parameter
     * rules as {@see isExactApplicationJson()}.
     */
    private function isExactTextEventStream(MessageInterface $msg): bool
    {
        return $this->isExactMediaType($msg, 'text/event-stream');
    }

    /**
     * Shared exact-media-type check: a single Content-Type header line whose
     * type/subtype (case-insensitive, ignoring parameters such as charset) equals
     * $expected. Multiple conflicting Content-Type values cannot be resolved →
     * false.
     */
    private function isExactMediaType(MessageInterface $msg, string $expected): bool
    {
        $values = $msg->getHeader('Content-Type');
        if (\count($values) !== 1) {
            return false;
        }
        $parts = \explode(';', $values[0], 2);
        $mediaType = \trim($parts[0]);

        return \strtolower($mediaType) === $expected;
    }

    /**
     * Whether the response carries a body digest / signature header that would
     * be invalidated by a body rewrite (spec §9.4).
     */
    private function hasResponseIntegrityHeaders(ResponseInterface $response): bool
    {
        foreach (self::INTEGRITY_HEADERS as $header) {
            if ($response->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise Content-Encoding (spec §9.2).
     *
     * Returns 'identity' when no real encoding is present, 'gzip' for a single
     * gzip token, or null for any unsupported/ambiguous (combined, unknown, or
     * multi-valued) encoding. A null result tells the caller to use normal
     * passthrough.
     */
    private function parseContentEncoding(MessageInterface $msg): ?string
    {
        $values = $msg->getHeader('Content-Encoding');
        // Normalise across multiple header lines and comma-separated tokens.
        $tokens = [];
        foreach ($values as $value) {
            foreach (\explode(',', $value) as $part) {
                $part = \trim($part);
                if ($part !== '') {
                    $tokens[] = $part;
                }
            }
        }
        if ($tokens === []) {
            return 'identity';
        }
        if (\count($tokens) > 1) {
            return null;
        }
        $encoding = \strtolower($tokens[0]);
        if ($encoding === 'identity') {
            return 'identity';
        }
        if ($encoding === 'gzip') {
            return 'gzip';
        }

        return null;
    }

    private function hasIntegrityHeaders(RequestInterface $request): bool
    {
        foreach (self::INTEGRITY_HEADERS as $header) {
            if ($request->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    // ---- Body / gzip helpers -----------------------------------------------

    /**
     * Best-effort rewind before FAILING: a rewind exception here must never mask
     * the original error (PSR-7 allows rewind() to throw). The caller throws
     * its own InvalidRequestException right after (codex med).
     */
    private static function rewindBestEffort(StreamInterface $body): void
    {
        try {
            $body->rewind();
        } catch (\Throwable) {
            // best effort; the original failure is what matters
        }
    }

    /**
     * Rewind before FORWARDING / returning the original body. If the body
     * cannot be restored to its start, forwarding it would emit a consumed /
     * partial body, so degrade to fail-closed (spec §9.2 / codex med).
     */
    private function rewindForForward(StreamInterface $body, RequestInterface $request, string $reason): void
    {
        try {
            $body->rewind();
        } catch (\Throwable $e) {
            throw new InvalidRequestException($request, $reason, 0, $e);
        }
    }

    private function readBounded(StreamInterface $stream, int $limit): string
    {
        $data = '';
        // Track eof() in a local so the read()-may-flip-eof semantics stay
        // visible to the analyser (PSR-7 lets a non-blocking stream return ''
        // before eof). Treating such a stall as end-of-body would silently
        // truncate the read and, on the request side, bypass masking; fail
        // closed instead (codex high).
        $atEof = $stream->eof();
        while (!$atEof && \strlen($data) < $limit) {
            $remaining = $limit - \strlen($data);
            $chunk = $stream->read(\min(65536, $remaining));
            if ($chunk === '') {
                $atEof = $stream->eof();
                if (!$atEof) {
                    throw new \RuntimeException('stream returned an empty read before EOF');
                }
                break;
            }
            $data .= $chunk;
            $atEof = $stream->eof();
        }

        return $data;
    }

    private function isJsonWhitespace(string $s): bool
    {
        if ($s === '') {
            return true;
        }

        return \strspn($s, "\x20\x09\x0a\x0d") === \strlen($s);
    }

    /**
     * Bounded gzip decompression using the incremental inflater so the output
     * budget is enforced chunk-by-chunk, never materialising an unbounded
     * plaintext (spec §9.2: must not unbounded-inflate via gzdecode() before
     * checking length).
     *
     * Supports concatenated gzip members (RFC 1952 §2.2): each member is driven
     * by its own inflate context, and the inflater is retired as soon as
     * ZLIB_STREAM_END is observed. Bytes left over after the final member that
     * are not themselves a valid gzip member are rejected rather than silently
     * ignored, so trailing garbage cannot smuggle data past the decoder.
     *
     * @throws LimitExceededException when decoded output exceeds $maxOutput.
     * @throws \RuntimeException       on gzip header / data corruption.
     */
    private function gunzipBounded(string $data, int $maxOutput): string
    {
        $output = '';
        $dataLen = \strlen($data);
        $offset = 0;
        // One iteration per gzip member. Empty input yields empty output, which
        // the caller handles as a no-maskable-content bypass.
        while ($offset < $dataLen) {
            $memberStart = $offset;
            $context = $this->startGzipInflater();
            $memberOut = '';
            $streamEnded = false;
            // Feed the member in small pieces so the output budget is checked
            // before a large concat can materialise a compression-bomb payload.
            while ($offset < $dataLen && !$streamEnded) {
                $chunk = \substr($data, $offset, self::GUNZIP_PIECE);
                $piece = $this->inflateAddScoped($context, $chunk);
                $this->assertGzipBudget($output, $memberOut, $piece, $maxOutput);
                $memberOut .= $piece;
                $offset += \strlen($chunk);
                if (\inflate_get_status($context) === \ZLIB_STREAM_END) {
                    $streamEnded = true;
                    // inflate_add may have consumed fewer bytes than the chunk
                    // we offered; reseat the cursor on the real member boundary
                    // so the next member starts exactly after this one.
                    $offset = $memberStart + \inflate_get_read_len($context);
                }
            }
            if (!$streamEnded) {
                // Input exhausted before the member self-terminated: flush it.
                $piece = $this->inflateAddScoped($context, '', \ZLIB_FINISH);
                $this->assertGzipBudget($output, $memberOut, $piece, $maxOutput);
                $memberOut .= $piece;
                if (\inflate_get_status($context) !== \ZLIB_STREAM_END) {
                    throw new \RuntimeException('gzip: incomplete member');
                }
            }
            $output .= $memberOut;
        }

        return $output;
    }

    /**
     * Create a gzip inflate context under a scoped error handler so a zlib
     * warning surfaces as a RuntimeException instead of relying on @ suppression.
     */
    private function startGzipInflater(): \InflateContext
    {
        try {
            \set_error_handler(self::gzipErrorHandler(...));
            $context = \inflate_init(\ZLIB_ENCODING_GZIP);
        } finally {
            \restore_error_handler();
        }
        if ($context === false) {
            throw new \RuntimeException('gzip: invalid header');
        }

        return $context;
    }

    /**
     * Feed $chunk to $context under a scoped error handler; zlib warnings become
     * RuntimeException. Returns the inflated bytes for this step.
     */
    private function inflateAddScoped(\InflateContext $context, string $chunk, int $flags = 0): string
    {
        try {
            \set_error_handler(self::gzipErrorHandler(...));
            $piece = \inflate_add($context, $chunk, $flags);
        } finally {
            \restore_error_handler();
        }
        if ($piece === false) {
            throw new \RuntimeException('gzip: decode error');
        }

        return $piece;
    }

    /**
     * Reject the accumulated output before concat once it would exceed the
     * decoded budget (spec §9.2 compression-bomb defence: check projected size
     * BEFORE appending the freshly inflated piece).
     */
    private function assertGzipBudget(string $output, string $memberOut, string $piece, int $maxOutput): void
    {
        if (\strlen($output) + \strlen($memberOut) + \strlen($piece) > $maxOutput) {
            throw new LimitExceededException('gzip decoded output exceeds budget');
        }
    }

    /**
     * Scoped error handler for the zlib calls: convert E_WARNING into a
     * RuntimeException so failures propagate as normal exceptions rather than
     * being silenced with @.
     */
    private static function gzipErrorHandler(int $errno, string $errstr): bool
    {
        throw new \RuntimeException('gzip: ' . $errstr);
    }

    // ---- Report-event / snapshot helpers ----------------------------------

    /**
     * Transport MaskEvents always carry start=end=0; source is stamped with the
     * field path (spec §3.5). Source path tracking is a refinement — events
     * currently arrive with empty source from the walker.
     *
     * @param list<MaskEvent> $events
     * @return list<MaskEvent>
     */
    private function toTransportMaskEvents(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = new MaskEvent(
                $event->entity,
                0,
                0,
                $event->score,
                $event->replacement,
                $event->reversible,
                $event->source,
            );
        }

        return $out;
    }

    /**
     * Transport RestoreEvents always carry start=end=0 (spec §3.5).
     *
     * @param list<RestoreEvent> $events
     * @return list<RestoreEvent>
     */
    private function toTransportRestoreEvents(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            $out[] = new RestoreEvent(
                $event->entity,
                0,
                0,
                $event->placeholder,
                $event->restored,
                $event->source,
            );
        }

        return $out;
    }

    /**
     * Enforce the fixed MaxMaskReportBytes budget before the report is fired
     * (spec §9.3). Exceeding it fails the request before send.
     *
     * @param list<MaskEvent> $events
     */
    private function enforceMaskReportBudget(array $events, RequestInterface $request): void
    {
        $bytes = 0;
        foreach ($events as $event) {
            $bytes += \strlen($event->entity)
                + \strlen($event->replacement)
                + \strlen($event->source);
        }
        if ($bytes > self::MAX_MASK_REPORT_BYTES) {
            throw new InvalidRequestException(
                $request,
                'mask report bytes exceed MaxMaskReportBytes (' . self::MAX_MASK_REPORT_BYTES . ')',
            );
        }
    }

    /**
     * Create an independent body-snapshot of a request for a report callback,
     * so the callback cannot race with the inner client over a mutable stream.
     */
    private function snapshotRequest(RequestInterface $request, string $bodyBytes): RequestInterface
    {
        return $request->withBody($this->streamFactory->createStream($bodyBytes));
    }

    /**
     * Return a Response whose body exposes the original raw bytes after the
     * read pass. Rewinds the original stream when it is reliably seekable;
     * otherwise builds a fresh stream from the captured bytes (spec §9.4: never
     * return a body sitting at EOF).
     */
    private function withRawBody(ResponseInterface $response, StreamInterface $body, string $raw): ResponseInterface
    {
        if ($body->isSeekable()) {
            try {
                $body->rewind();

                return $response;
            } catch (\Throwable) {
                // rewind failed: fall back to a fresh stream from the captured
                // bytes (spec §9.4: never return a body sitting at EOF).
            }
        }

        return $response->withBody($this->streamFactory->createStream($raw));
    }
}
