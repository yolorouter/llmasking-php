<?php

// src/Internal/ResponseWalker.php

namespace Yolorouter\Llmasking\Internal;

use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\RestoreEvent;
use Yolorouter\Llmasking\Session;

/**
 * Response-body free-text walker (spec §9.4 restore targets).
 *
 * Walks the tokenized JSON response body to find restore targets listed in
 * spec §9.4. For each target string, calls Session::restore() and stages a
 * {span, replacement} patch when the replacement differs from the decoded
 * original.
 *
 * Targets (spec §9.4):
 *  - choices[].message.content (string)
 *  - choices[].message.content[].text / .refusal
 *  - choices[].message.refusal
 *  - choices[].message.tool_calls[].function.arguments
 *  - choices[].message.tool_calls[].custom.input
 *  - choices[].message.function_call.arguments (legacy)
 *  - choices[].message.audio.transcript
 *
 * Non-target fields (id, model, finish_reason, usage, etc.) pass through
 * byte-for-byte. Missing/null/non-string targets are skipped (not rejected).
 * No API parameter validation (CLAUDE.md #1 rule).
 *
 * A cumulative projected-body budget (WalkerBudget, initialized from the
 * engine's MaxOutputBytes) is enforced on every staged patch so the walker
 * can never stage enough near-MaxOutputBytes replacements to push the final
 * patched body past the cap (spec §5.1; codex #15).
 *
 * Restore traversal order (spec §9.4):
 *  choices[] → message → content string / content[].text / content[].refusal →
 *  message.refusal → tool_calls[].function.arguments / custom.input →
 *  legacy function_call.arguments → audio.transcript.
 *
 * @internal
 */
final class ResponseWalker
{
    /** @var list<JsonPatchEntry> */
    private array $patches = [];

    /** @var list<RestoreEvent> */
    private array $events = [];

    /**
     * Response-level cumulative report counters (codex r3 / spec §9.5/§9.7):
     * Session::restore caps a single call; these bound the whole JSON body
     * across every target so a multi-target response cannot exceed
     * MaxRestoreEvents / MaxRestoreReportBytes in aggregate.
     */
    private int $restoreEventCount = 0;

    private int $restoreReportBytes = 0;

    private bool $collectEvents = false;

    /**
     * Cumulative projected-body byte counter enforced on every staged patch.
     * Lazily built in run() once the document body length and MaxOutputBytes
     * are both known.
     */
    private ?WalkerBudget $budget = null;

    private function __construct(
        private readonly JsonDocument $doc,
        private readonly Session $session,
    ) {
    }

    /**
     * Walk the response body, restore every §9.4 free-text target, and return
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
     * Same as walk(), but also returns the collected RestoreEvents for the
     * transport restore-report callback (spec §9.7). Event ordering follows
     * the spec §9.4 traversal order. The returned events carry per-string
     * offsets; the transport layer stamps transport semantics.
     *
     * @return array{0: list<JsonPatchEntry>, 1: list<RestoreEvent>}
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

        foreach ($this->membersByName($root, 'choices') as $choices) {
            if (!$choices instanceof JsonArray) {
                continue;
            }
            foreach ($choices->elements as $choice) {
                if (!$choice instanceof JsonObject) {
                    continue;
                }
                $this->visitChoice($choice);
            }
        }
    }

    /**
     * Visit a single choice object: find its message and process all §9.4
     * targets in spec order.
     */
    private function visitChoice(JsonObject $choice): void
    {
        foreach ($this->membersByName($choice, 'message') as $message) {
            if (!$message instanceof JsonObject) {
                continue;
            }
            $this->visitMessageContent($message);
            $this->processStringMember($message, 'refusal');
            $this->visitMessageToolCalls($message);
            $this->visitMessageFunctionCall($message);
            $this->visitMessageAudio($message);
        }
    }

    /**
     * Process message content: if it is a string, restore it directly; if it
     * is an array of parts, process each part's text and refusal strings.
     * Other shapes (null, number, etc.) are skipped.
     */
    private function visitMessageContent(JsonObject $message): void
    {
        foreach ($this->membersByName($message, 'content') as $content) {
            if ($content instanceof JsonString) {
                $this->restoreStringTarget($content);
            } elseif ($content instanceof JsonArray) {
                foreach ($content->elements as $part) {
                    if (!$part instanceof JsonObject) {
                        continue;
                    }
                    $this->processStringMember($part, 'text');
                    $this->processStringMember($part, 'refusal');
                }
            }
            // null / number / true / false / object: not a supported target — skip.
        }
    }

    /**
     * Process message tool_calls: for each tool_call object, restore
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
     * Process message audio.transcript.
     */
    private function visitMessageAudio(JsonObject $message): void
    {
        foreach ($this->membersByName($message, 'audio') as $audio) {
            if ($audio instanceof JsonObject) {
                $this->processStringMember($audio, 'transcript');
            }
        }
    }

    /**
     * Find all members named $name on $obj and restore each whose value is a
     * JSON string. Non-string values are skipped (not rejected).
     */
    private function processStringMember(JsonObject $obj, string $name): void
    {
        foreach ($this->membersByName($obj, $name) as $value) {
            if ($value instanceof JsonString) {
                $this->restoreStringTarget($value);
            }
        }
    }

    /**
     * Decode the string, restore it, and stage a patch when the restored text
     * differs from the decoded original. Each staged patch is charged against
     * the projected-body budget (codex #15) BEFORE being appended.
     *
     * RestoreEvent collection is decoupled from patch staging (codex #12): an
     * unresolved placeholder leaves the body unchanged (no patch) but still
     * produces a restored=false event that the transport must report. Gating
     * events on $result->text !== $decoded dropped those events.
     */
    private function restoreStringTarget(JsonString $value): void
    {
        $decoded = $this->decodeString($value);
        $result = $this->session->restore($decoded);
        if ($result->text !== $decoded) {
            $encodedLength = \strlen(JsonPatch::encodeStringContent($result->text));
            $this->budget?->stage($value->contentLength, $encodedLength);
            $this->patches[] = new JsonPatchEntry($value->contentSpan(), $result->text);
        }
        if ($this->collectEvents) {
            foreach ($result->events as $event) {
                $this->chargeReportEvent($event);
                $this->events[] = $event;
            }
        }
    }

    /**
     * Charge one RestoreEvent against the response-level cumulative report caps
     * (codex r3 / spec §9.5/§9.7) BEFORE appending it. walkWithEvents runs only
     * when a restore report is enabled, so the cap naturally applies only on
     * the report path; a breach throws LimitExceededException, which
     * MaskingClient converts into a terminal failed body.
     */
    private function chargeReportEvent(RestoreEvent $event): void
    {
        if ($this->restoreEventCount >= Engine::MAX_RESTORE_EVENTS) {
            throw new LimitExceededException('restore event count exceeds MaxRestoreEvents');
        }
        $evBytes = \strlen($event->entity) + \strlen($event->placeholder) + \strlen($event->source);
        if ($this->restoreReportBytes + $evBytes > Engine::MAX_RESTORE_REPORT_BYTES) {
            throw new LimitExceededException('restore report bytes exceed limit');
        }
        $this->restoreEventCount++;
        $this->restoreReportBytes += $evBytes;
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
