<?php

// src/Internal/JsonSyntaxException.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Exception\LlmaskingException;

/**
 * Thrown by {@see JsonTokenizer} when input is not well-formed JSON.
 *
 * Deliberately NOT ext-json's \JsonException: the tokenizer never calls
 * json_decode, so the failure is the library's own controlled syntax error.
 * Per spec §9 this is a fail-closed condition for the library's own parsing
 * (the request-side MaskingClient wraps it as InvalidRequestException); it
 * is never used to validate API parameter semantics — unknown fields, wrong
 * types, duplicate keys, etc. all pass through byte-for-byte.
 */
final class JsonSyntaxException extends \RuntimeException implements LlmaskingException
{
    public static function at(int $byteOffset, string $reason): self
    {
        return new self('JSON syntax error at byte ' . $byteOffset . ': ' . $reason);
    }
}
