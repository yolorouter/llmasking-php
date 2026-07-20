<?php

// src/Internal/RequestWalker.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\MaskEvent;
use Yolorouter\Llmasking\Session;

/**
 * Request-body free-text walker (spec §9.3 path table).
 *
 * Walks the tokenized JSON request body to find ALL free-text string targets
 * listed in spec §9.3. Navigates the tokenizer tree by path (messages[0].
 * content, etc.); each target string value is passed to Session::anonymize(),
 * and a {span, replacement} patch is staged when the replacement differs from
 * the decoded original.
 *
 * Non-target fields (model, user, stop, temperature, role, name, etc.) are
 * not touched — they pass through byte-for-byte via the patcher. Missing,
 * null, or non-string targets are silently skipped (spec §9.3: missing/null/
 * non-string targets are not rejected). The walker performs NO API parameter
 * validation (CLAUDE.md #1 rule).
 *
 * A cumulative projected-body budget (WalkerBudget, initialized from the
 * engine's MaxOutputBytes) is enforced on every staged patch so the walker
 * can never stage enough near-MaxOutputBytes replacements to push the final
 * patched body past the cap (spec §5.1; codex #15).
 *
 * The anonymize order is fixed by spec §9.3:
 *  1. messages[] — content string / content[].text / content[].refusal /
 *     refusal / tool_calls[].function.arguments / tool_calls[].custom.input /
 *     legacy function_call.arguments
 *  2. tools[] — function.description / function.parameters (SchemaWalker) /
 *     custom.description
 *  3. functions[] — description / parameters (SchemaWalker)
 *  4. response_format.json_schema — description / schema (SchemaWalker)
 *  5. prediction — content string / content[].text
 *
 * @internal
 */
final class RequestWalker
{
    /** @var list<JsonPatchEntry> */
    private array $patches = [];

    /** @var list<MaskEvent> */
    private array $events = [];

    private bool $collectEvents = false;

    /**
     * Cumulative projected-body byte counter shared with SchemaWalker. Lazily
     * built in run() once the document body length and MaxOutputBytes are
     * both known.
     */
    private ?WalkerBudget $budget = null;

    private function __construct(
        private readonly JsonDocument $doc,
        private readonly Session $session,
    ) {
    }

    /**
     * Walk the request body, anonymize every §9.3 free-text target, and return
     * the staged patches (in spec traversal order).
     *
     * @return list<JsonPatchEntry>
     */
    public static function walk(JsonDocument $doc, Session $session): array
    {
        $walker = new self($doc, $session);
        $walker->run();
        return $walker->patches;
    }

    /**
     * Same as walk(), but also returns the collected MaskEvents for the
     * transport mask-report callback (spec §9.7). Event ordering follows the
     * spec §9.3 traversal order. The returned events still carry per-string
     * offsets relative to the decoded value; the transport layer is
     * responsible for stamping transport semantics (start=end=0, source).
     *
     * @return array{0: list<JsonPatchEntry>, 1: list<MaskEvent>}
     */
    public static function walkWithEvents(JsonDocument $doc, Session $session): array
    {
        $walker = new self($doc, $session);
        $walker->collectEvents = true;
        $walker->run();
        return [$walker->patches, $walker->events];
    }

    private function run(): void
    {
        $root = $this->doc->root;
        if (!$root instanceof JsonObject) {
            // Root is not a JSON object — no traversable top-level paths.
            return;
        }

        // Initialize the cumulative projected-body budget (codex #15) BEFORE
        // any patch is staged, so every staging site sees the same counter.
        $this->budget = new WalkerBudget(
            \strlen($this->doc->json),
            $this->session->engine->maxOutputBytes,
        );

        $this->visitMessages($root);
        $this->visitTools($root);
        $this->visitFunctions($root);
        $this->visitResponseFormat($root);
        $this->visitPrediction($root);
    }

    /**
     * 1. messages[] — for each message object: content (string or parts array),
     *    refusal, tool_calls[].function.arguments / custom.input, legacy
     *    function_call.arguments.
     */
    private function visitMessages(JsonObject $root): void
    {
        foreach ($this->membersByName($root, 'messages') as $messages) {
            if (!$messages instanceof JsonArray) {
                continue;
            }
            foreach ($messages->elements as $message) {
                if (!$message instanceof JsonObject) {
                    continue;
                }
                $this->visitMessageContent($message);
                $this->processStringMember($message, 'refusal');
                $this->visitMessageToolCalls($message);
                $this->visitMessageFunctionCall($message);
            }
        }
    }

    /**
     * Process message content: if it is a string, anonymize it directly; if it
     * is an array of parts, process each part's text and refusal strings.
     * Other shapes (null, number, etc.) are skipped.
     */
    private function visitMessageContent(JsonObject $message): void
    {
        foreach ($this->membersByName($message, 'content') as $content) {
            if ($content instanceof JsonString) {
                $this->anonymizeStringTarget($content);
            } elseif ($content instanceof JsonArray) {
                foreach ($content->elements as $part) {
                    if (!$part instanceof JsonObject) {
                        continue;
                    }
                    $this->processStringMember($part, 'text');
                    $this->processStringMember($part, 'refusal');
                }
            }
            // null / number / true / false / object: not a supported string target — skip.
        }
    }

    /**
     * Process message tool_calls: for each tool_call object, anonymize
     * function.arguments and custom.input.
     */
    private function visitMessageToolCalls(JsonObject $message): void
    {
        foreach ($this->membersByName($message, 'tool_calls') as $toolCalls) {
            if (!$toolCalls instanceof JsonArray) {
                continue;
            }
            foreach ($toolCalls->elements as $toolCall) {
                if (!$toolCall instanceof JsonObject) {
                    continue;
                }
                foreach ($this->membersByName($toolCall, 'function') as $function) {
                    if ($function instanceof JsonObject) {
                        $this->processStringMember($function, 'arguments');
                    }
                }
                foreach ($this->membersByName($toolCall, 'custom') as $custom) {
                    if ($custom instanceof JsonObject) {
                        $this->processStringMember($custom, 'input');
                    }
                }
            }
        }
    }

    /**
     * Process legacy message function_call.arguments.
     */
    private function visitMessageFunctionCall(JsonObject $message): void
    {
        foreach ($this->membersByName($message, 'function_call') as $fc) {
            if ($fc instanceof JsonObject) {
                $this->processStringMember($fc, 'arguments');
            }
        }
    }

    /**
     * 2. tools[] — for each tool object: function.description,
     *    function.parameters (SchemaWalker), custom.description.
     */
    private function visitTools(JsonObject $root): void
    {
        foreach ($this->membersByName($root, 'tools') as $tools) {
            if (!$tools instanceof JsonArray) {
                continue;
            }
            foreach ($tools->elements as $tool) {
                if (!$tool instanceof JsonObject) {
                    continue;
                }
                foreach ($this->membersByName($tool, 'function') as $function) {
                    if (!$function instanceof JsonObject) {
                        continue;
                    }
                    $this->processStringMember($function, 'description');
                    foreach ($this->membersByName($function, 'parameters') as $params) {
                        $this->walkSchema($params);
                    }
                }
                foreach ($this->membersByName($tool, 'custom') as $custom) {
                    if ($custom instanceof JsonObject) {
                        $this->processStringMember($custom, 'description');
                    }
                }
            }
        }
    }

    /**
     * 3. functions[] (legacy) — for each function object: description,
     *    parameters (SchemaWalker).
     */
    private function visitFunctions(JsonObject $root): void
    {
        foreach ($this->membersByName($root, 'functions') as $functions) {
            if (!$functions instanceof JsonArray) {
                continue;
            }
            foreach ($functions->elements as $function) {
                if (!$function instanceof JsonObject) {
                    continue;
                }
                $this->processStringMember($function, 'description');
                foreach ($this->membersByName($function, 'parameters') as $params) {
                    $this->walkSchema($params);
                }
            }
        }
    }

    /**
     * 4. response_format.json_schema — description, then schema (SchemaWalker).
     */
    private function visitResponseFormat(JsonObject $root): void
    {
        foreach ($this->membersByName($root, 'response_format') as $rf) {
            if (!$rf instanceof JsonObject) {
                continue;
            }
            foreach ($this->membersByName($rf, 'json_schema') as $js) {
                if (!$js instanceof JsonObject) {
                    continue;
                }
                $this->processStringMember($js, 'description');
                foreach ($this->membersByName($js, 'schema') as $schema) {
                    $this->walkSchema($schema);
                }
            }
        }
    }

    /**
     * 5. prediction — content string, or content[].text for each part.
     */
    private function visitPrediction(JsonObject $root): void
    {
        foreach ($this->membersByName($root, 'prediction') as $prediction) {
            if (!$prediction instanceof JsonObject) {
                continue;
            }
            foreach ($this->membersByName($prediction, 'content') as $content) {
                if ($content instanceof JsonString) {
                    $this->anonymizeStringTarget($content);
                } elseif ($content instanceof JsonArray) {
                    foreach ($content->elements as $part) {
                        if (!$part instanceof JsonObject) {
                            continue;
                        }
                        $this->processStringMember($part, 'text');
                    }
                }
            }
        }
    }

    /**
     * Delegate to SchemaWalker with anonymize as the processor. Resulting
     * patches are appended in schema traversal order, each charged against the
     * shared projected-body budget. When collectEvents is set, MaskEvents
     * produced inside the closure are collected for the transport mask report.
     */
    private function walkSchema(JsonValue $node): void
    {
        $session = $this->session;
        $patches = SchemaWalker::walk(
            $node,
            $this->doc->json,
            function (string $decoded) use ($session): string {
                $result = $session->anonymize($decoded);
                if ($this->collectEvents) {
                    foreach ($result->events as $event) {
                        $this->events[] = $event;
                    }
                }
                return $result->text;
            },
            $this->budget,
        );
        foreach ($patches as $patch) {
            $this->patches[] = $patch;
        }
    }

    /**
     * Find all members named $name on $obj and anonymize each whose value is a
     * JSON string. Non-string values are skipped (not rejected).
     */
    private function processStringMember(JsonObject $obj, string $name): void
    {
        foreach ($this->membersByName($obj, $name) as $value) {
            if ($value instanceof JsonString) {
                $this->anonymizeStringTarget($value);
            }
        }
    }

    /**
     * Decode the string, anonymize it, and stage a patch when the masked text
     * differs from the decoded original (spec §9.3: only actual replacements
     * generate patches). Each staged patch is charged against the projected-
     * body budget (codex #15) BEFORE being appended. MaskEvents are collected
     * when collectEvents is set.
     */
    private function anonymizeStringTarget(JsonString $value): void
    {
        $decoded = $this->decodeString($value);
        $result = $this->session->anonymize($decoded);
        if ($result->text !== $decoded) {
            $encodedLength = \strlen(JsonPatch::encodeStringContent($result->text));
            $this->budget?->stage($value->contentLength, $encodedLength);
            $this->patches[] = new JsonPatchEntry($value->contentSpan(), $result->text);
            if ($this->collectEvents) {
                foreach ($result->events as $event) {
                    $this->events[] = $event;
                }
            }
        }
    }

    /**
     * Find all member values whose decoded key equals $name, in raw member
     * order. Decoded comparison ensures escaped key forms match correctly.
     *
     * @return list<JsonValue>
     */
    private function membersByName(JsonObject $obj, string $name): array
    {
        $out = [];
        foreach ($obj->members as $member) {
            if ($this->decodeString($member->key) === $name) {
                $out[] = $member->value;
            }
        }
        return $out;
    }

    /**
     * Decode a JSON string token to its PHP string value. The tokenizer has
     * already validated the syntax, so json_decode cannot throw here.
     */
    private function decodeString(JsonString $s): string
    {
        $quoted = \substr($this->doc->json, $s->start, $s->end - $s->start);
        $result = \json_decode($quoted, false, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_string($result));

        return $result;
    }
}
