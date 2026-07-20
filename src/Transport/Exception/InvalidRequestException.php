<?php

// src/Transport/Exception/InvalidRequestException.php

namespace Yolorouter\Llmasking\Transport\Exception;

use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Thrown when the MaskingClient has entered the supported processing path
 * (POST + exact application/json) but cannot safely read, parse, mask or
 * satisfy a resource budget for the request body before it is sent
 * (spec §3.6 / §9.2).
 *
 * Implements PSR-18 {@see RequestExceptionInterface}; {@see getRequest()}
 * returns a request snapshot whose body is an independent raw copy when the
 * body was fully captured, or the rewound original seekable stream when it was
 * not. The decorator never proactively closes the body of the exception
 * request.
 *
 * This exception is NOT used for unsupported method / media type /
 * Content-Encoding — those are normal passthrough. It is also never used to
 * validate API parameter semantics (spec §9.3 / CLAUDE.md rule #1).
 */
final class InvalidRequestException extends \RuntimeException implements RequestExceptionInterface, \Yolorouter\Llmasking\Exception\LlmaskingException
{
    /**
     * @param RequestInterface $request the original (or equivalent) request
     * @param string           $message fixed-path diagnostic only; never embeds raw body text
     * @param \Throwable|null  $previous underlying library exception, if any
     */
    public function __construct(
        private readonly RequestInterface $request,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
