<?php

// playground/server.php — PHP router for the built-in server.
//
// Run:  php -S 127.0.0.1:8787 playground/server.php
//
// Routes:
//   GET  /                  → serve index.html (web UI)
//   POST /api/test          → non-streaming mask → LLM → restore
//   POST /api/test/stream   → SSE streaming mask → LLM stream → restore

require __DIR__ . '/../vendor/autoload.php';

use Yolorouter\Llmasking\Engine;

// ---- Constants ---------------------------------------------------------------

const PROTOCOL_OPENAI    = 'openai';
const PROTOCOL_ANTHROPIC = 'anthropic';
const PROTOCOL_GEMINI    = 'gemini';

const ANTHROPIC_API_VERSION      = '2023-06-01';
const ANTHROPIC_DEFAULT_MAX_TOKENS = 1024;
const MAX_RESPONSE_BYTES = 10 << 20; // 10 MiB
const MAX_STREAM_BYTES   = 8 << 20;  // 8 MiB combined raw+restored

// ---- Router ------------------------------------------------------------------

$engine = Engine::new();

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '/index.html') {
    serveIndex();
} elseif ($path === '/api/test' && $method === 'POST') {
    handleTest($engine);
} elseif ($path === '/api/test/stream' && $method === 'POST') {
    handleTestStream($engine);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
}

// ---- Static index ------------------------------------------------------------

function serveIndex(): void
{
    $file = __DIR__ . '/index.html';
    if (!is_file($file)) {
        http_response_code(500);
        echo 'index.html missing';
        return;
    }
    header('Content-Type: text/html; charset=utf-8');
    readfile($file);
}

// ---- Non-streaming endpoint --------------------------------------------------

function handleTest(Engine $engine): void
{
    header('Content-Type: application/json');

    $req = decodeRequestBody();
    if ($req === null) return;

    $session = $engine->newSession();
    $anon = $session->anonymize($req['message']);

    $raw = callLLM($req['protocol'], $req['baseUrl'], $req['apiKey'], $req['model'], $anon->text);
    if ($raw instanceof LlmError) {
        echo json_encode([
            'maskedInput' => $anon->text,
            'maskedEvents' => maskEventsToJson($anon->events),
            'error' => $raw->message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    $restore = $session->restore($raw);
    $unmatched = [];
    foreach ($restore->events as $ev) {
        if (!$ev->restored) $unmatched[] = restoreEventToJson($ev);
    }

    echo json_encode([
        'maskedInput' => $anon->text,
        'maskedEvents' => maskEventsToJson($anon->events),
        'rawLlmResponse' => $raw,
        'restoredResponse' => $restore->text,
        'unmatched' => $unmatched,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// ---- SSE streaming endpoint --------------------------------------------------

function handleTestStream(Engine $engine): void
{
    // Turn off output buffering so each SSE event reaches the browser
    // immediately (php -S buffers by default).
    while (ob_get_level() > 0) ob_end_clean();

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');

    $req = decodeRequestBody();
    if ($req === null) return;

    $session = $engine->newSession();
    $anon = $session->anonymize($req['message']);

    sendEvent('masked', ['text' => $anon->text, 'events' => maskEventsToJson($anon->events)]);

    // Open a streaming curl connection to the LLM and process its SSE stream
    // incrementally through StreamRestorer.
    $sr = $session->streamRestorer();
    $raw = '';
    $restored = '';
    $unmatched = [];

    $result = callLLMStreaming(
        $req['protocol'], $req['baseUrl'], $req['apiKey'], $req['model'],
        $anon->text,
        function (string $chunk) use (
            $req, $sr, &$raw, &$restored, &$unmatched
        ): ?string {
            static $buffer = '';
            $buffer .= $chunk;

            while (true) {
                $pos = strpos($buffer, "\n\n");
                if ($pos === false) break;

                $frame = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);

                $data = '';
                foreach (explode("\n", $frame) as $line) {
                    if (str_starts_with($line, 'data: ')) {
                        $data = substr($line, 6);
                    } elseif (str_starts_with($line, 'data:')) {
                        $data = substr($line, 5);
                    }
                }
                if ($data === '') continue;

                [$delta, $done, $err] = nextDelta($data, $req['protocol']);
                if ($err !== null) return $err;
                if ($done) return '__DONE__';
                if ($delta === '') continue;

                $raw .= $delta;

                $res = $sr->write($delta);
                foreach ($res->events as $ev) {
                    if (!$ev->restored) $unmatched[] = restoreEventToJson($ev);
                }

                $restored .= $res->text;

                if (strlen($raw) + strlen($restored) > MAX_STREAM_BYTES) {
                    return 'stream exceeds the playground\'s byte limit';
                }

                sendEvent('chunk', [
                    'raw' => $delta,
                    'restored' => $res->text,
                ]);
            }
            return null;
        },
    );

    if ($result instanceof LlmError) {
        sendEvent('error', $result->message);
        return;
    }
    if (is_string($result) && $result !== '__DONE__') {
        sendEvent('error', $result);
        return;
    }

    // Flush the StreamRestorer's withheld tail.
    $tail = $sr->flush();
    foreach ($tail->events as $ev) {
        if (!$ev->restored) $unmatched[] = restoreEventToJson($ev);
    }
    $restored .= $tail->text;

    sendEvent('done', [
        'restored' => $tail->text,
        'unmatched' => $unmatched,
    ]);
}

// ---- LLM protocol: request building -----------------------------------------

/**
 * Build the protocol-specific HTTP request (URL, headers, body) for a given
 * LLM API. Returns [url, headers, body].
 *
 * @return array{0:string, 1:array<string,string>, 2:string}
 */
function buildLlmRequest(string $protocol, string $base, string $key, string $model, string $content, bool $stream): array
{
    $base = rtrim($base, '/');

    if ($protocol === PROTOCOL_ANTHROPIC) {
        $payload = [
            'model' => $model,
            'max_tokens' => ANTHROPIC_DEFAULT_MAX_TOKENS,
            'messages' => [['role' => 'user', 'content' => $content]],
        ];
        if ($stream) $payload['stream'] = true;
        return [
            $base . '/messages',
            [
                'Content-Type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: ' . ANTHROPIC_API_VERSION,
            ],
            json_encode($payload),
        ];
    }

    if ($protocol === PROTOCOL_GEMINI) {
        $body = json_encode([
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $content]]],
            ],
        ]);
        $action = $stream ? 'streamGenerateContent?alt=sse' : 'generateContent';
        return [
            $base . '/models/' . $model . ':' . $action,
            [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $key,
            ],
            $body,
        ];
    }

    // OpenAI (default)
    $payload = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $content]],
    ];
    if ($stream) $payload['stream'] = true;
    return [
        $base . '/chat/completions',
        [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
        ],
        json_encode($payload),
    ];
}

// ---- LLM call (non-streaming) -----------------------------------------------

function callLLM(string $proto, string $base, string $key, string $model, string $content): string|LlmError
{
    [$url, $headers, $body] = buildLlmRequest($proto, $base, $key, $model, $content, false);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HEADER => false,
    ]);
    $resp = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return new LlmError('LLM request failed: ' . ($err ?: 'curl error'));
    }
    if (strlen($resp) > MAX_RESPONSE_BYTES) {
        return new LlmError('LLM response exceeds the playground\'s ' . MAX_RESPONSE_BYTES . '-byte limit');
    }
    if ($status >= 300) {
        return new LlmError('LLM returned status ' . $status . ': ' . substr($resp, 0, 500));
    }

    return parseLlmResponse($resp, $proto);
}

/**
 * Extract the assistant's text from a non-streaming LLM response (still
 * containing placeholders — Restore is applied by the caller).
 */
function parseLlmResponse(string $body, string $proto): string|LlmError
{
    $decoded = json_decode($body, true);

    if ($proto === PROTOCOL_ANTHROPIC) {
        // content[] where type == "text"
        if (isset($decoded['error']['message'])) {
            return new LlmError('LLM error: ' . $decoded['error']['message']);
        }
        $text = '';
        foreach ($decoded['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        return $text !== '' ? $text : new LlmError('LLM response had no text content (body: ' . substr($body, 0, 500) . ')');
    }

    if ($proto === PROTOCOL_GEMINI) {
        if (isset($decoded['error']['message'])) {
            return new LlmError('LLM error: ' . $decoded['error']['message']);
        }
        $text = '';
        foreach ($decoded['candidates'] ?? [] as $cand) {
            foreach ($cand['content']['parts'] ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }
        }
        return $text !== '' ? $text : new LlmError('LLM response had no text content (body: ' . substr($body, 0, 500) . ')');
    }

    // OpenAI
    if (isset($decoded['error']['message'])) {
        return new LlmError('LLM error: ' . $decoded['error']['message']);
    }
    $text = $decoded['choices'][0]['message']['content'] ?? '';
    return $text !== '' ? $text : new LlmError('LLM response had no text content (body: ' . substr($body, 0, 500) . ')');
}

// ---- LLM streaming call ------------------------------------------------------

/**
 * Call the LLM with stream=true and process each SSE chunk through the
 * $callback. The callback receives raw SSE wire bytes and may return:
 *   null      → continue reading
 *   '__DONE__' → the LLM signalled completion ([DONE] or message_stop)
 *   string    → an error message → abort
 *
 * Returns null on success, '__DONE__' on clean stop, or LlmError on failure.
 */
function callLLMStreaming(string $proto, string $base, string $key, string $model, string $content, callable $callback): null|string|LlmError
{
    [$url, $headers, $body] = buildLlmRequest($proto, $base, $key, $model, $content, true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 0, // no overall timeout for streaming
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HEADER => false,
        CURLOPT_WRITEFUNCTION => function ($ch, string $data) use ($callback): int {
            $result = $callback($data);
            if ($result === null) return strlen($data); // continue
            // The callback returned a string → abort the transfer.
            // Stash the result for the caller via a global (curl has no
            // clean out-param for WRITEFUNCTION abort).
            $GLOBALS['llm_stream_result'] = $result;
            return -1; // signal curl to abort CURLE_WRITE_ERROR
        },
    ]);

    $GLOBALS['llm_stream_result'] = null;
    curl_exec($ch);
    $errno = curl_errno($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    // Check the stashed callback result first (callback aborted via return -1).
    $stashed = $GLOBALS['llm_stream_result'];
    if ($stashed !== null) {
        if ($stashed === '__DONE__') return '__DONE__';
        return new LlmError($stashed);
    }

    // CURLE_WRITE_ERROR (23) is expected when the callback aborts. Any other
    // curl error is a real failure.
    if ($errno && $errno !== 23) {
        return new LlmError('LLM streaming request failed: ' . $err);
    }
    if ($status >= 300) {
        return new LlmError('LLM returned status ' . $status);
    }
    return null;
}

// ---- Streaming delta extraction ----------------------------------------------

/**
 * Extract the text delta from one SSE data payload.
 *
 * @return array{0:string, 1:bool, 2:?string} [delta, done, error]
 */
function nextDelta(string $payload, string $proto): array
{
    $decoded = json_decode($payload, true);

    if ($proto === PROTOCOL_ANTHROPIC) {
        if (!is_array($decoded)) return ['', false, null]; // not valid JSON — skip
        $type = $decoded['type'] ?? '';
        if ($type === 'message_stop') return ['', true, null];
        if ($type === 'error') {
            $msg = $decoded['error']['message'] ?? $decoded['error']['type'] ?? 'upstream error';
            return ['', false, $msg];
        }
        // content_block_delta with delta.type == "text_delta"
        if (($decoded['delta']['type'] ?? '') === 'text_delta') {
            return [$decoded['delta']['text'] ?? '', false, null];
        }
        return ['', false, null]; // other event types (message_start, ping, etc.)
    }

    if ($proto === PROTOCOL_GEMINI) {
        if (!is_array($decoded)) return ['', false, null];
        if (isset($decoded['error']['message'])) {
            return ['', false, 'LLM stream error: ' . $decoded['error']['message']];
        }
        $text = '';
        foreach ($decoded['candidates'] ?? [] as $cand) {
            foreach ($cand['content']['parts'] ?? [] as $part) {
                $text .= $part['text'] ?? '';
            }
        }
        return [$text, false, null];
    }

    // OpenAI
    if ($payload === '[DONE]') return ['', true, null];
    if (!is_array($decoded)) return ['', false, null];
    $delta = $decoded['choices'][0]['delta'] ?? [];
    if (!empty($delta['content'])) return [$delta['content'], false, null];
    if (!empty($delta['refusal'])) return [$delta['refusal'], false, null];
    // tool_calls delta
    foreach ($delta['tool_calls'] ?? [] as $tc) {
        if (!empty($tc['function']['arguments'])) {
            return [$tc['function']['arguments'], false, null];
        }
    }
    return ['', false, null];
}

// ---- Helpers -----------------------------------------------------------------

class LlmError
{
    public function __construct(public string $message) {}
}

/**
 * Decode the JSON request body from the browser.
 */
function decodeRequestBody(): ?array
{
    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        echo json_encode(['error' => 'invalid request body']);
        return null;
    }
    $req['protocol'] ??= PROTOCOL_OPENAI;
    if (!in_array($req['protocol'], [PROTOCOL_OPENAI, PROTOCOL_ANTHROPIC, PROTOCOL_GEMINI], true)) {
        echo json_encode(['error' => 'unsupported protocol "' . $req['protocol'] . '"']);
        return null;
    }
    foreach (['baseUrl', 'apiKey', 'model', 'message'] as $field) {
        if (empty($req[$field])) {
            echo json_encode(['error' => $field . ' is required']);
            return null;
        }
    }
    return $req;
}

/**
 * Send one SSE event to the browser and flush immediately.
 *
 * @param mixed $data
 */
function sendEvent(string $event, $data): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    flush();
}

/**
 * Convert MaskEvent objects to the JSON shape the browser expects.
 */
function maskEventsToJson(array $events): array
{
    $out = [];
    foreach ($events as $ev) {
        $out[] = [
            'entity' => $ev->entity,
            'start' => $ev->start,
            'end' => $ev->end,
            'score' => $ev->score,
            'replacement' => $ev->replacement,
            'reversible' => $ev->reversible,
        ];
    }
    return $out;
}

/**
 * Convert RestoreEvent objects to the JSON shape the browser expects.
 */
function restoreEventToJson($ev): array
{
    return [
        'entity' => $ev->entity,
        'start' => $ev->start,
        'end' => $ev->end,
        'placeholder' => $ev->placeholder,
        'restored' => $ev->restored,
    ];
}
