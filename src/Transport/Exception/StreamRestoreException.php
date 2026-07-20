<?php

// src/Transport/Exception/StreamRestoreException.php

namespace Yolorouter\Llmasking\Transport\Exception;

/**
 * Raised by the transport RestoringStream (Plan 4 SSE / failed JSON body
 * materialisation) when the restore pipeline fails after the Response has
 * already been returned from {@see \Yolorouter\Llmasking\Transport\MaskingClient::sendRequest()}.
 *
 * The class is defined now so the transport exception surface is complete, but
 * the PSR-7 RestoringStream that throws it from read()/getContents() is added
 * in Plan 4 together with SSE support (spec §3.6 / §9.4 / §9.6). It is NOT a
 * ClientExceptionInterface — the response has already been successfully
 * delivered at the PSR-18 level; only its body cannot be restored.
 */
final class StreamRestoreException extends \RuntimeException implements \Yolorouter\Llmasking\Exception\LlmaskingException
{
}
