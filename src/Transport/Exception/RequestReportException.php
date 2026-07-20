<?php

// src/Transport/Exception/RequestReportException.php

namespace Yolorouter\Llmasking\Transport\Exception;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * Thrown when a mask-report callback (TransportOptions::withMaskReport) raises
 * while the masked request has been built but has not yet been sent
 * (spec §3.6 / §9.7). The request is not forwarded to the inner client.
 *
 * Implements PSR-18 {@see ClientExceptionInterface}; the original callback
 * exception is chained as $previous. Network or inner-client failures are not
 * wrapped by this type — they propagate as the inner client raises them.
 */
final class RequestReportException extends \RuntimeException implements ClientExceptionInterface, \Yolorouter\Llmasking\Exception\LlmaskingException
{
}
