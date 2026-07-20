<?php

// src/Internal/ProcessedRequest.php

namespace Yolorouter\Llmasking\Internal;

use Psr\Http\Message\RequestInterface;
use Yolorouter\Llmasking\Session;

/**
 * Immutable result of the request-side processing phase inside MaskingClient.
 *
 * $session is null when the request was normal-bypassed (unsupported method,
 * media type, encoding, empty body, or a WithPassthrough passthrough). In that
 * case $outgoingRequest is the untouched original request and the response must
 * be returned untouched (spec §9.1). When non-null, the same Session must be
 * used to restore the matching response.
 *
 * $outgoingBodyBytes captures the exact bytes the inner client will see, so a
 * restore-report callback can be handed an independent request snapshot without
 * racing the inner client for the live body stream.
 *
 * @internal
 */
final class ProcessedRequest
{
    public function __construct(
        public readonly RequestInterface $outgoingRequest,
        public readonly ?Session $session,
        public readonly string $outgoingBodyBytes,
    ) {
    }
}
