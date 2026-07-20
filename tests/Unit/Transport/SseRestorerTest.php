<?php

// tests/Unit/Transport/SseRestorerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Session;
use Yolorouter\Llmasking\Transport\Exception\StreamRestoreException;
use Yolorouter\Llmasking\Transport\SseRestorer;
use Yolorouter\Llmasking\Transport\SseRestoreResult;

final class SseRestorerTest extends TestCase
{
    private function sessionWithPhone(): Session
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000');

        return $s;
    }

    private function sessionWithTwo(): Session
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000');
        $s->anonymize('alice@example.com');

        return $s;
    }

    // ---- Basic content ------------------------------------------------------

    public function testSingleChoiceContentStreamRestoresPlaceholder(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"call [PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"call 13800138000\"}}]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
        self::assertCount(1, $result->allEvents());
        self::assertTrue($result->allEvents()[0]->restored);
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    public function testEventWithoutDataIsForwardedAsIs(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = ": heartbeat\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
        self::assertSame([], $result->allEvents());
    }

    public function testNoChangeContentIsForwardedAsIs(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain text\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
        self::assertSame([], $result->allEvents());
    }

    // ---- Multi-choice -------------------------------------------------------

    public function testMultiChoiceStreamsRouteByIndex(): void
    {
        $s = $this->sessionWithTwo();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":["
            . "{\"index\":0,\"delta\":{\"content\":\"a [PHONE_1]\"}},"
            . "{\"index\":1,\"delta\":{\"content\":\"b [EMAIL_1]\"}}"
            . "]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":["
            . "{\"index\":0,\"delta\":{\"content\":\"a 13800138000\"}},"
            . "{\"index\":1,\"delta\":{\"content\":\"b alice@example.com\"}}"
            . "]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    public function testChoiceWithoutIndexRoutesByPosition(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // No index field on choices.
        $input = "data: {\"choices\":["
            . "{\"delta\":{\"content\":\"x [PHONE_1]\"}},"
            . "{\"delta\":{\"content\":\"y\"}}"
            . "]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":["
            . "{\"delta\":{\"content\":\"x 13800138000\"}},"
            . "{\"delta\":{\"content\":\"y\"}}"
            . "]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
    }

    // ---- Tool calls ---------------------------------------------------------

    public function testToolCallFunctionArgumentsRestored(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":"
            . "[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"f\",\"arguments\":\"{\\\"x\\\":\\\"[PHONE_1]\\\"}\"}}]"
            . "}}]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":"
            . "[{\"index\":0,\"id\":\"call_1\",\"function\":{\"name\":\"f\",\"arguments\":\"{\\\"x\\\":\\\"13800138000\\\"}\"}}]"
            . "}}]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    public function testToolCallCustomInputRestored(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"delta\":{\"tool_calls\":"
            . "[{\"id\":\"c1\",\"custom\":{\"name\":\"cust\",\"input\":\"p=[PHONE_1]\"}}]"
            . "}}]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":[{\"delta\":{\"tool_calls\":"
            . "[{\"id\":\"c1\",\"custom\":{\"name\":\"cust\",\"input\":\"p=13800138000\"}}]"
            . "}}]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
    }

    public function testLegacyFunctionCallArgumentsRestored(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"function_call\":{\"arguments\":\"[PHONE_1]\"}}}]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"function_call\":{\"arguments\":\"13800138000\"}}}]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
    }

    // ---- Refusal ------------------------------------------------------------

    public function testRefusalStreamRestored(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"refusal\":\"no [PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);

        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"refusal\":\"no 13800138000\"}}]}\n\n";
        self::assertSame($expected, $result->joinedBytes());
    }

    // ---- Split across chunks ------------------------------------------------

    public function testPlaceholderSplitAcrossChunksIsWithheldAndCompleted(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Split the placeholder across two write() calls but keep the event
        // intact in one piece (the event terminator arrives only at the end).
        $event = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHO"
            . "NE_1] y\"}}]}\n\n";
        $cut = (int) \strpos($event, 'NE_1]');
        $first = \substr($event, 0, $cut);
        $second = \substr($event, $cut);

        $r1 = $sr->write($first);
        // No complete event yet → no output.
        self::assertSame('', $r1->joinedBytes());

        $r2 = $sr->write($second);
        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x 13800138000 y\"}}]}\n\n";
        self::assertSame($expected, $r2->joinedBytes());
    }

    public function testPlaceholderSplitAcrossSseEventsWithheldTail(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $r1 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHO\"}}]}\n\n");
        // The placeholder is incomplete; the StreamRestorer withholds "[PHO".
        // Output content is "x " (the withheld portion is buffered internally).
        self::assertStringContainsString('"content":"x "', $r1->joinedBytes());

        $r2 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"NE_1]\"}}]}\n\n");
        self::assertStringContainsString('"content":"13800138000"', $r2->joinedBytes());
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    // ---- finish_reason flush ------------------------------------------------

    public function testFinishReasonFlushesChoiceContent(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Streaming content across two events; the second carries finish_reason.
        $r1 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hi [PHO\"}}]}\n\n");
        self::assertStringContainsString('"content":"hi "', $r1->joinedBytes());

        $r2 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"NE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");
        // A synthesized frame should precede the (emptied) terminal frame.
        self::assertStringContainsString('"content":"13800138000"', $r2->joinedBytes());
        // The terminal frame content should be emptied.
        self::assertStringContainsString('"content":""', $r2->joinedBytes());
        self::assertStringContainsString('"finish_reason":"stop"', $r2->joinedBytes());
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    public function testFinishReasonWithoutChangedContentIsForwardedAsIs(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    // ---- [DONE] flush -------------------------------------------------------

    public function testDoneMarkerTriggersBlanketFlushAndIsPreserved(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Withheld tail because the placeholder is incomplete across events.
        $r1 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHO\"}}]}\n\n");
        self::assertStringContainsString('"content":""', $r1->joinedBytes());

        $r2 = $sr->write("data: [DONE]\n\n");
        // The [DONE] must be forwarded intact after the blanket flush.
        self::assertStringContainsString('data: [DONE]' . "\n\n", $r2->joinedBytes());
        // The withheld "[PHO" is an incomplete placeholder — at blanket flush it
        // is emitted as literal text (no restoration), per the CLOSE-optional
        // lexer semantics that only complete a DIGITS state at EOF.
        self::assertStringContainsString('"content":"[PHO"', $r2->joinedBytes());
    }

    public function testEofFlushesUnterminatedEventAndBlanketFlushes(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $r1 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHO\"}}]}\n\n");
        $r2 = $sr->flush();
        // Unterminated "[PHO" is flushed as literal text (no closing bracket).
        self::assertStringContainsString('[PHO', $r2->joinedBytes());
    }

    // ---- Line ending preservation -------------------------------------------

    public function testCrlfLineEndingsPreservedOnForward(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain\"}}]}\r\n\r\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    public function testCrlfLineEndingsUsedOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"}}]}\r\n\r\n";
        $result = $sr->write($input);
        $expected = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"13800138000\"}}]}\r\n\r\n";
        self::assertSame($expected, $result->joinedBytes());
    }

    public function testStandaloneCrLineEndingsPreserved(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain\"}}]}\r\r";
        // During streaming a trailing CR could be the start of CRLF; the parser
        // must wait for one more byte. Only at EOF (flush) is the standalone CR
        // confirmed as the event delimiter.
        $r1 = $sr->write($input);
        self::assertSame('', $r1->joinedBytes());
        $r2 = $sr->flush();
        self::assertSame($input, $r2->joinedBytes());
    }

    // ---- Comment / id / event / retry lines ---------------------------------

    public function testCommentIdEventRetryLinesPreservedOnForward(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = ": comment\n"
            . "id: 42\n"
            . "event: delta\n"
            . "retry: 1000\n"
            . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    public function testCommentIdEventRetryLinesPreservedOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $input = ": keep\n"
            . "id: 42\n"
            . "event: delta\n"
            . "retry: 1000\n"
            . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);
        // Non-data lines are preserved verbatim; only the data line is rewritten.
        self::assertStringContainsString(": keep\n", $result->joinedBytes());
        self::assertStringContainsString("id: 42\n", $result->joinedBytes());
        self::assertStringContainsString("event: delta\n", $result->joinedBytes());
        self::assertStringContainsString("retry: 1000\n", $result->joinedBytes());
        self::assertStringContainsString('"content":"13800138000"', $result->joinedBytes());
        // Exactly one data: line in the output.
        self::assertSame(1, \substr_count($result->joinedBytes(), "data:"));
    }

    public function testMultipleDataLinesMergedIntoOne(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        // Two data lines — the JSON is the concatenation with \n. We craft it
        // so the joined data is a valid JSON document.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":\n"
            . "data: {\"content\":\"[PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertStringContainsString('"content":"13800138000"', $result->joinedBytes());
        self::assertSame(1, \substr_count($result->joinedBytes(), "data:"));
    }

    // ---- BOM ----------------------------------------------------------------

    public function testLeadingBomIsPreservedOnForward(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $bom = "\xEF\xBB\xBF";
        $input = $bom . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    public function testLeadingBomIsPreservedOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $bom = "\xEF\xBB\xBF";
        $input = $bom . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($bom, \substr($result->joinedBytes(), 0, 3));
        self::assertStringContainsString('"content":"13800138000"', $result->joinedBytes());
    }

    // ---- Invalid UTF-8 fail-closed ------------------------------------------

    public function testInvalidUtf8InRawStreamFailsClosed(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());

        try {
            $sr->write("data: {\"choices\":[{\"delta\":{\"content\":\"bad \xFF\"}}]}\n\n");
            self::fail('expected StreamRestoreException');
        } catch (\Throwable $e) {
            self::assertInstanceOf(StreamRestoreException::class, $e);
        }

        // Subsequent calls rethrow the same failure.
        try {
            $sr->write('x');
            self::fail('expected the same failure rethrown');
        } catch (\Throwable $e2) {
            self::assertTrue($sr->isFailed());
        }
    }

    public function testInvalidJsonDataFailsClosed(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());

        $this->expectException(StreamRestoreException::class);
        $sr->write("data: {not valid json\n\n");
    }

    // ---- Budget limits ------------------------------------------------------

    public function testTotalRawSseBudgetExceededFailsClosed(): void
    {
        // tiny MaxOutputBytes so the budgets are small and easy to exceed.
        $engine = Engine::new(EngineOption::withMaxOutputBytes(256));
        $session = $engine->newSession();
        $session->anonymize('13800138000');
        $sr = new SseRestorer($session);

        // totalRawSseBudget = max(256*4, 4MiB) = 4MiB — too big to hit here.
        // Instead hit singleRawEventBudget (= singleLineBudget = max(256+4096, 64KiB) = 64KiB).
        // Send one giant comment line longer than 64 KiB.
        $huge = ': ' . \str_repeat('x', 70 * 1024) . "\n\n";
        $this->expectException(StreamRestoreException::class);
        $sr->write($huge);
    }

    public function testMaxSseStreamsExceededFailsClosed(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // 257 distinct choice indices → 257 routes → exceeds the 256 cap.
        $chunks = [];
        for ($i = 0; $i < 257; $i++) {
            $chunks[] = "data: {\"choices\":[{\"index\":$i,\"delta\":{\"content\":\"c$i [PHONE_1]\"}}]}\n\n";
        }

        $thrown = null;
        foreach ($chunks as $chunk) {
            try {
                $sr->write($chunk);
            } catch (\Throwable $e) {
                $thrown = $e;
                break;
            }
        }
        self::assertNotNull($thrown);
        self::assertTrue($sr->isFailed());
    }

    // ---- Custom routing / re-creation ---------------------------------------

    public function testLateDataAfterFinishRecreatesRoute(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // First event: content + finish.
        $r1 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");
        self::assertStringContainsString('"content":"13800138000"', $r1->joinedBytes());

        // Late data: same choice, more content (new generation).
        $r2 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"more [PHONE_1]\"}}]}\n\n");
        self::assertStringContainsString('"content":"more 13800138000"', $r2->joinedBytes());
        self::assertSame('', $sr->flush()->joinedBytes());
    }

    public function testNullContentIsNotTouched(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":null}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    public function testNonTargetFieldsPreservedByteForByte(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());
        $input = "data: {\"id\":\"req_1\",\"model\":\"gpt-x\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"plain\",\"role\":\"assistant\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertSame($input, $result->joinedBytes());
    }

    public function testRoutingKeyByToolIdWhenNoIndex(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Two tool calls in separate frames, distinguished by id (no index).
        $r1 = $sr->write("data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"id\":\"c1\",\"function\":{\"name\":\"f\",\"arguments\":\"[PHO\"}}]}}]}\n\n");
        $r2 = $sr->write("data: {\"choices\":[{\"delta\":{\"tool_calls\":[{\"id\":\"c1\",\"function\":{\"name\":\"f\",\"arguments\":\"NE_1]\"}}]}}]}\n\n");
        self::assertStringContainsString('"arguments":"13800138000"', $r2->joinedBytes());
    }

    public function testUnknownFieldsInDeltaPreserved(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\",\"vendor_field\":\"keep\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertStringContainsString('"vendor_field":"keep"', $result->joinedBytes());
        self::assertStringContainsString('"content":"13800138000"', $result->joinedBytes());
    }

    // ---- Synthesized flush frame token reuse --------------------------------

    public function testFinishReasonSynthesizedFrameReusesChoiceIndexToken(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Withheld content across two events; finish_reason on the second.
        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHO\"}}]}\n\n");
        $r2 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"NE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");

        // The synthesized frame must carry the saved raw index token "0".
        self::assertStringContainsString('"index":0', $r2->joinedBytes());
        self::assertStringContainsString('"content":"13800138000"', $r2->joinedBytes());
        // Terminal frame content is emptied.
        self::assertStringContainsString('"content":""', $r2->joinedBytes());
    }

    public function testFinishReasonSynthesizedFrameOmitsMissingIndex(): void
    {
        // Position-routed choice has no index token; synthesized frame must
        // NOT invent a numeric index (spec §9.5).
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $r = $sr->write("data: {\"choices\":[{\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");
        // The synthesized frame should contain the restored content and the
        // finish_reason, but no "index" field on the choice.
        self::assertStringContainsString('"content":"13800138000"', $r->joinedBytes());
        // Extract the synthesized data line (first data: ... line).
        self::assertMatchesRegularExpression('/data: \{"choices":\[\{"delta":\{"content":"13800138000"\}\}\]\}/', $r->joinedBytes());
    }

    public function testFinishReasonToolCallSynthesizedFrameReusesTokens(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Tool call with index, id, and function.name; split across two events.
        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_x\",\"function\":{\"name\":\"get\",\"arguments\":\"[PHO\"}}]}}]}\n\n");
        $r = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":[{\"index\":0,\"id\":\"call_x\",\"function\":{\"name\":\"get\",\"arguments\":\"NE_1]\"}}]},\"finish_reason\":\"tool_calls\"}]}\n\n");

        // The synthesized frame must carry the saved raw tokens: tool index 0,
        // id "call_x", and function name "get".
        self::assertStringContainsString('"arguments":"13800138000"', $r->joinedBytes());
        self::assertStringContainsString('"id":"call_x"', $r->joinedBytes());
        self::assertStringContainsString('"name":"get"', $r->joinedBytes());
    }

    // ---- UTF-8 / escaping ---------------------------------------------------

    public function testNonAsciiUtf8ContentPreservedOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        // Content has non-ASCII UTF-8 + a placeholder; JSON_UNESCAPED_UNICODE
        // means the non-ASCII bytes are preserved verbatim in the rewrite.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"好的 [PHONE_1]\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertStringContainsString('好的 13800138000', $result->joinedBytes());
        // No \uXXXX escape for the CJK text.
        self::assertStringNotContainsString('\\u', $result->joinedBytes());
    }

    public function testControlCharacterInContentEscapedOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        // A literal tab inside the content string (already JSON-escaped as \t
        // on the wire). Rewriting must preserve the escape.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\\tafter\"}}]}\n\n";
        $result = $sr->write($input);
        self::assertStringContainsString('13800138000\\tafter', $result->joinedBytes());
    }

    // ---- Blanket flush ordering ---------------------------------------------

    public function testBlanketFlushEmitsInOrdinalOrder(): void
    {
        $s = $this->sessionWithTwo();
        $sr = new SseRestorer($s);

        // Create routes in order: choice 0 content, then choice 1 content.
        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"a [PHO\"}}]}\n\n");
        $sr->write("data: {\"choices\":[{\"index\":1,\"delta\":{\"content\":\"b [EMA\"}}]}\n\n");

        // EOF blanket flush must emit choice 0's tail before choice 1's tail.
        $r = $sr->flush();
        // The withheld fragments "[PHO" and "[EMA" are incomplete at flush →
        // emitted as literal text (no restoration). Choice 0 was created
        // before choice 1, so its frame must come first.
        $pos0 = \strpos($r->joinedBytes(), '"content":"[PHO"');
        $pos1 = \strpos($r->joinedBytes(), '"content":"[EMA"');
        self::assertNotFalse($pos0);
        self::assertNotFalse($pos1);
        self::assertLessThan($pos1, $pos0);
    }

    public function testIdempotentBlanketFlushAfterDone(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hi\"}}]}\n\n");
        $r1 = $sr->write("data: [DONE]\n\n");
        // Second flush is idempotent — produces nothing.
        $r2 = $sr->flush();
        self::assertSame('', $r2->joinedBytes());
    }

    // ---- Codex fix #7: finish_reason flush / global blanketFlushed bool ------

    public function testDoneThenLateDataThenEofFlushEmitsLateData(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // First generation: withheld fragment.
        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHO\"}}]}\n\n");
        // [DONE] triggers a blanket flush of the first generation.
        $r1 = $sr->write("data: [DONE]\n\n");
        self::assertStringContainsString('[PHO', $r1->joinedBytes());

        // Late data starts a NEW generation on the same routing key.
        $r2 = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"late [PHO\"}}]}\n\n");
        // "late " is emitted inline; "[PHO" is withheld by the new generation.
        self::assertStringContainsString('"content":"late "', $r2->joinedBytes());

        // EOF flush must still flush the new generation. The global
        // blanketFlushed flag must NOT suppress it (data-loss bug).
        $r3 = $sr->flush();
        self::assertStringContainsString('"content":"[PHO"', $r3->joinedBytes());
    }

    public function testTerminalVisitWithoutPlaceholderStillSynthesizesAndEmpties(): void
    {
        $sr = new SseRestorer($this->sessionWithPhone());

        // Content with NO placeholder plus finish_reason: per spec the
        // write()+flush() output is synthesized as a delta frame BEFORE the
        // emptied terminal frame, even when nothing changed.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"hello\"},\"finish_reason\":\"stop\"}]}\n\n";
        $r = $sr->write($input);
        // Synthesized frame carries the content.
        self::assertStringContainsString('"content":"hello"', $r->joinedBytes());
        // Terminal frame content is emptied.
        self::assertStringContainsString('"content":""', $r->joinedBytes());
        self::assertStringContainsString('"finish_reason":"stop"', $r->joinedBytes());
    }

    // ---- Codex fix #8: terminal visit write events --------------------------

    public function testTerminalVisitEmitsWriteRestoreEvents(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // A placeholder restored in the SAME frame as finish_reason: the
        // write() that performs the restore emits a RestoreEvent which must be
        // surfaced even though the visit is terminal.
        $r = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");
        self::assertStringContainsString('"content":"13800138000"', $r->joinedBytes());
        self::assertStringContainsString('"content":""', $r->joinedBytes());
        // The terminal path previously dropped visit["events"] — assert the
        // write() restore event is present.
        self::assertCount(1, $r->allEvents());
        self::assertTrue($r->allEvents()[0]->restored);
        self::assertSame('[PHONE_1]', $r->allEvents()[0]->placeholder);
    }

    // ---- Codex fix #9: route key collision ----------------------------------

    public function testDuplicateToolCallIndexInOneFrameDoesNotCrossContaminate(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Two tool calls in one delta sharing index 0 (malformed). The first
        // carries a split placeholder "[PHO", the second carries "NE_1]". With
        // a shared route (no per-frame disambiguation) the second write would
        // complete the first's placeholder and corrupt both. The per-frame
        // occurrence counter must keep them in separate routes.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"tool_calls\":["
            . "{\"index\":0,\"function\":{\"name\":\"f\",\"arguments\":\"[PHO\"}},"
            . "{\"index\":0,\"function\":{\"name\":\"g\",\"arguments\":\"NE_1]\"}}"
            . "]}}]}\n\n";
        $r = $sr->write($input);
        // No phone restoration should occur — the two restorers are separate.
        self::assertStringNotContainsString('13800138000', $r->joinedBytes());
        // First tool's withheld fragment produces empty output for this delta.
        self::assertStringContainsString('"arguments":""', $r->joinedBytes());
        // Second tool's "NE_1]" is literal text in its own restorer.
        self::assertStringContainsString('"arguments":"NE_1]"', $r->joinedBytes());
    }

    // ---- Codex fix #10: cumulative output budget ----------------------------

    public function testCumulativeOutputBudgetSharedAcrossRoutes(): void
    {
        // Tiny MaxOutputBytes so the shared cumulative cap is easy to exceed.
        $engine = Engine::new(EngineOption::withMaxOutputBytes(64));
        $session = $engine->newSession();
        $session->anonymize('13800138000');
        $sr = new SseRestorer($session);

        // Two routes in one response. Each restores ~52 bytes of plaintext,
        // under the 64-byte per-route cap, but together (104) they exceed the
        // shared cumulative MaxOutputBytes budget.
        $payload = \str_repeat('a', 40);
        $input = "data: {\"choices\":["
            . "{\"index\":0,\"delta\":{\"content\":\"$payload [PHONE_1]\"}},"
            . "{\"index\":1,\"delta\":{\"content\":\"$payload [PHONE_1]\"}}"
            . "]}\n\n";
        $this->expectException(StreamRestoreException::class);
        $sr->write($input);
    }

    // ---- Codex fix #11: incremental budget check ----------------------------

    public function testUnterminatedEventFailsOnPerEventBudgetBeforeTotalBudget(): void
    {
        // Small MaxOutputBytes shrinks singleRawEventBudget to 64 KiB.
        $engine = Engine::new(EngineOption::withMaxOutputBytes(256));
        $session = $engine->newSession();
        $session->anonymize('13800138000');
        $sr = new SseRestorer($session);

        // First chunk: a data line with NO event terminator, under 64 KiB.
        $sr->write("data: " . \str_repeat('x', 50 * 1024) . "\n");
        // Second chunk pushes the unterminated event past 64 KiB. The per-event
        // budget must fire here, not the 4 MiB total raw budget.
        $this->expectException(StreamRestoreException::class);
        $this->expectExceptionMessage('single raw SSE event');
        $sr->write(\str_repeat('y', 20 * 1024));
    }

    // ---- Codex fix #12: line-ending reuse + BOM dedup -----------------------

    public function testCrlfLineEndingsReusedInSynthesizedFlushFrames(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // Stream using CRLF; withhold across events; finish_reason triggers a
        // synthesized flush frame which must REUSE the observed CRLF style.
        $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHO\"}}]}\r\n\r\n");
        $r = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"NE_1]\"},\"finish_reason\":\"stop\"}]}\r\n\r\n");

        self::assertStringContainsString('"content":"13800138000"', $r->joinedBytes());
        // No bare "\n\n" boundary — both the synth frame and the rebuilt
        // terminal frame use CRLF, so every "\n" is preceded by "\r".
        self::assertSame(0, \substr_count($r->joinedBytes(), "\n\n"));
    }

    public function testBomNotDuplicatedWhenCommentPrecedesDataOnRewrite(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $bom = "\xEF\xBB\xBF";

        // BOM sits on the comment line; the data line follows. On rewrite the
        // BOM must appear exactly once (carried by the comment's raw bytes),
        // not duplicated onto the rewritten data line.
        $input = $bom . ": keep\ndata: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"}}]}\n\n";
        $r = $sr->write($input);
        self::assertSame(1, \substr_count($r->joinedBytes(), $bom));
        self::assertStringContainsString(": keep\n", $r->joinedBytes());
        self::assertStringContainsString('"content":"13800138000"', $r->joinedBytes());
    }

    // ---- 16 KiB segment queue (spec §9.5/§9.6, codex #2 Stage B) -----------

    /**
     * A rewritten event whose escaped output exceeds 16 KiB must be produced
     * as multiple at-most-16 KiB segments (spec §9.5/§9.6), reassembling to
     * the same bytes as a single string, with the events attached to a segment
     * no earlier than the event's last output byte.
     */
    public function testLargeRewrittenEventIsSegmentedAt16KiB(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // ~2000 placeholder occurrences -> restored content well over 16 KiB.
        $payload = \str_repeat('x [PHONE_1] ', 2000);
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"" . $payload . "\"}}]}\n\n";
        $result = $sr->write($input);

        // Every produced segment is at most 16 KiB (except possibly the final).
        $maxSeg = 0;
        foreach ($result->segments as $seg) {
            $maxSeg = \max($maxSeg, \strlen($seg[0]));
        }
        self::assertGreaterThan(1, \count($result->segments), 'large event spans multiple segments');
        self::assertLessThanOrEqual(16 * 1024, $maxSeg, 'no segment exceeds 16 KiB');

        // Reassembly matches a direct restore of the same content.
        self::assertSame(
            "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"" . \str_repeat('x 13800138000 ', 2000) . "\"}}]}\n\n",
            $result->joinedBytes(),
        );

        // 2000 restored-placeholder events, all delivered (attached to the last
        // segment in Stage B, but the count and order must be intact).
        $events = $result->allEvents();
        self::assertSame(2000, \count($events));
        foreach ($events as $ev) {
            self::assertTrue($ev->restored);
        }
    }

    /**
     * The dry-run encoded length (used for the budget check before emitting the
     * first byte) must be byte-for-byte equal to the actual escaped output
     * length across control characters, quotes, backslashes and multibyte text.
     */
    public function testEncodedStringLengthMatchesEncodeStringContent(): void
    {
        $cases = [
            '',
            'plain ascii',
            'with "quotes" and \\backslash and /slashes/',
            "tab\there\nnewline\rreturn\fform\bback \x00\x01\x1F\x7F",
            '中文测试 🚀 emoji naïve café',
            'mixed "中" \\文 \n tab /path',
            \str_repeat('"', 50),
            \str_repeat("\\", 50),
        ];
        foreach ($cases as $i => $case) {
            self::assertSame(
                \strlen(\Yolorouter\Llmasking\Internal\JsonPatch::encodeStringContent($case)),
                \Yolorouter\Llmasking\Internal\JsonPatch::encodedStringLength($case),
                "encodedLength mismatch for case $i",
            );
        }
    }

    /**
     * The lazy feed/pull API (spec §9.6) must yield at-most-16 KiB segments one
     * at a time without materializing the whole event: pulling a large event in
     * a tight loop reassembles to the same bytes as a single write, every
     * segment stays within 16 KiB, and all events are delivered.
     */
    public function testFeedPullYieldsSegmentQueueLazily(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $payload = \str_repeat('x [PHONE_1] ', 2000);
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"" . $payload . "\"}}]}\n\n";
        $sr->feed($input);

        $joined = '';
        $events = [];
        $maxSeg = 0;
        $segs = 0;
        while (null !== ($seg = $sr->pull())) {
            $segs++;
            $maxSeg = \max($maxSeg, \strlen($seg[0]));
            $joined .= $seg[0];
            foreach ($seg[1] as $ev) {
                $events[] = $ev;
            }
        }
        // Not yet at EOF: pull returned null because no complete event remains
        // and the stream is open. Finalize and drain.
        $sr->finishEof();
        while (null !== ($seg = $sr->pull())) {
            $maxSeg = \max($maxSeg, \strlen($seg[0]));
            $joined .= $seg[0];
            foreach ($seg[1] as $ev) {
                $events[] = $ev;
            }
        }

        self::assertGreaterThan(1, $segs, 'event split into multiple segments');
        self::assertLessThanOrEqual(16 * 1024, $maxSeg, 'no segment exceeds 16 KiB');
        self::assertSame(
            "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"" . \str_repeat('x 13800138000 ', 2000) . "\"}}]}\n\n",
            $joined,
        );
        self::assertSame(2000, \count($events));
        self::assertTrue($sr->isExhausted());
    }

    /**
     * A rewritten event whose JSON spans multiple `data:` lines must merge to a
     * single physical data line whose value re-parses as the same JSON object
     * (spec §9.5). The SSE "\n" joiner is insignificant JSON whitespace; a raw
     * LF left in the rewritten line would split it on the wire and corrupt the
     * downstream parse (codex stage-c #1).
     */
    public function testMultiDataLineRewriteMergesToOneParseableDataLine(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // JSON value split across two data: lines; the "\n" joiner sits at a
        // JSON whitespace position (between ':' and the value), so it parses.
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\ndata: \"[PHONE_1]\"}}]}\n\n";
        $out = $sr->write($input)->joinedBytes();

        // Exactly one physical data: line in the output (no embedded raw LF).
        self::assertSame(1, \substr_count($out, 'data:'));
        self::assertStringNotContainsString("}\ndata:", $out);

        // The data value re-parses to the restored JSON object.
        self::assertSame(
            ['choices' => [['index' => 0, 'delta' => ['content' => '13800138000']]]],
            \json_decode(\substr($out, 6), true),
        );
    }

    /**
     * Forwarded (no-data / [DONE]) events must also be emitted as at-most-16 KiB
     * segments, not one oversized blob (spec §9.6 / codex stage-c #2).
     */
    public function testPassthroughEventIsSegmentedAt16KiB(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // A large comment-only event (no data: field) terminated by a blank line.
        $line = ': ' . \str_repeat('x', 60) . "\n";
        $input = \str_repeat($line, 2000) . "\n"; // ~126 KiB, one event
        $sr->feed($input);
        $sr->finishEof();

        $joined = '';
        $maxSeg = 0;
        while (null !== ($seg = $sr->pull())) {
            $maxSeg = \max($maxSeg, \strlen($seg[0]));
            $joined .= $seg[0];
        }
        self::assertLessThanOrEqual(16 * 1024, $maxSeg, 'no passthrough segment exceeds 16 KiB');
        self::assertSame($input, $joined);
        self::assertNull($sr->pull());
    }

    /**
     * A choice whose `index` is the empty string `""` must still place the
     * restored content in the content field of the synthesized frame. The old
     * `""`-sentinel split hijacked the tail position with the empty identity
     * token (codex high #2); synthDelta constructs the template explicitly so
     * identity tokens cannot affect the tail position.
     */
    public function testSynthesizedFrameWithEmptyIndexTokenPreservesContent(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $r = $sr->write("data: {\"choices\":[{\"index\":\"\",\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n");
        $out = $r->joinedBytes();

        self::assertStringContainsString('"index":"",', $out);
        self::assertStringContainsString('"content":"13800138000"', $out);
        // The restored text must not be hijacked into the empty index field.
        self::assertStringNotContainsString('"index":"13800138000"', $out);
    }

    /**
     * A JSON string that spans multiple `data:` lines contains a raw LF and is
     * invalid JSON; it must fail closed (terminal), not be silently repaired by
     * the joiner-LF collapse and then restored (codex med #2).
     */
    public function testMultiDataLineBrokenInsideStringIsRejected(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        // content string "x\n[PHONE_1]" spans two data: lines -> raw LF inside
        // the JSON string -> invalid.
        $input = "data: {\"choices\":[{\"delta\":{\"content\":\"x\ndata: [PHONE_1]\"}}]}\n\n";

        try {
            $sr->write($input);
            self::fail('expected StreamRestoreException for a JSON string spanning data lines');
        } catch (StreamRestoreException $e) {
            self::assertTrue($sr->isFailed());
        }
    }

    /**
     * An unresolved (unmapped) placeholder in a delta must still be reported as
     * a restored=false event; the body is unchanged but the callback receives
     * the event (codex high: SSE analog of the JSON unresolved-event fix).
     */
    public function testUnresolvedPlaceholderInDeltaIsReportedRestoredFalse(): void
    {
        $s = $this->sessionWithPhone(); // maps only [PHONE_1]
        $sr = new SseRestorer($s);

        // [PHONE_99] is placeholder-shaped but unmapped.
        $r = $sr->write("data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHONE_99] y\"}}]}\n\n");
        $events = $r->allEvents();

        self::assertCount(1, $events);
        self::assertFalse($events[0]->restored);
        // Body unchanged (the unmapped placeholder is kept verbatim).
        self::assertSame(
            "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"x [PHONE_99] y\"}}]}\n\n",
            $r->joinedBytes(),
        );
    }

    /**
     * When the first event both carries a leading BOM and synthesizes a flush
     * frame (finish_reason), the BOM must remain the stream's first bytes — it
     * cannot be pushed after the synth frame (codex high #2).
     */
    public function testBomPrefixBeforeSynthFrameOnFirstEvent(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);
        $bom = "\xEF\xBB\xBF";

        $input = $bom . "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n";
        $out = $sr->write($input)->joinedBytes();

        // BOM is the very first 3 bytes, ahead of the synthesized frame.
        self::assertSame($bom, \substr($out, 0, 3));
        self::assertStringContainsString('"content":"13800138000"', $out);
        self::assertSame(1, \substr_count($out, $bom));
    }

    /**
     * An unresolved placeholder written with non-canonical JSON escapes (e.g.
     * [ / ] for [ ]) must keep its original escape spelling byte-exact
     * (no re-encoding to bare brackets) while still reporting restored=false
     * (codex med: unresolved patch is byte-exact, not a re-encoding no-op).
     */
    public function testUnresolvedPlaceholderPreservesNonCanonicalEscaping(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"\\u005bPHONE_99\\u005d\"}}]}\n\n";
        $r = $sr->write($input);

        self::assertCount(1, $r->allEvents());
        self::assertFalse($r->allEvents()[0]->restored);
        // Body byte-exact: the [/] escapes are preserved verbatim.
        self::assertSame($input, $r->joinedBytes());
    }

    /**
     * A synthesized frame whose identity tokens (choice/tool index/id/name) are
     * large must still be emitted in at-most-16 KiB segments — the synth prefix
     * is streamed via pushBytesGen, not pushed as one blob (codex high).
     */
    public function testSynthFrameWithLargeIdentityTokenIsSegmented(): void
    {
        $s = $this->sessionWithPhone();
        $sr = new SseRestorer($s);

        $huge = \str_repeat('x', 40000); // 40 KiB index token
        $input = "data: {\"choices\":[{\"index\":\"" . $huge . "\",\"delta\":{\"content\":\"[PHONE_1]\"},\"finish_reason\":\"stop\"}]}\n\n";
        $r = $sr->write($input);

        $maxSeg = 0;
        foreach ($r->segments as $seg) {
            $maxSeg = \max($maxSeg, \strlen($seg[0]));
        }
        self::assertLessThanOrEqual(16 * 1024, $maxSeg, 'no synth-frame segment exceeds 16 KiB');
        self::assertStringContainsString('"content":"13800138000"', $r->joinedBytes());
    }

    /**
     * Multiple placeholders in one content string must each attach their
     * RestoreEvent to the segment containing THAT placeholder's restored
     * plaintext (per-piece, spec §9.6), not all to the replacement's final
     * segment. With >16 KiB between them they land in different segments, so
     * the restored events spread across >=2 segments (codex med).
     */
    public function testMultiplePlaceholdersAttachEventsToOwnSegments(): void
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000'); // [PHONE_1]
        $s->anonymize('alice@example.com'); // [EMAIL_1]
        $sr = new SseRestorer($s);

        $content = \str_repeat('x', 20000) . '[PHONE_1]' . \str_repeat('y', 20000) . '[EMAIL_1]';
        $input = "data: {\"choices\":[{\"index\":0,\"delta\":{\"content\":\"" . $content . "\"}}]}\n\n";
        $r = $sr->write($input);

        $segsWithEvents = 0;
        $restored = 0;
        foreach ($r->segments as $seg) {
            if ($seg[1] !== []) {
                $segsWithEvents++;
                foreach ($seg[1] as $ev) {
                    if ($ev->restored) {
                        $restored++;
                    }
                }
            }
        }
        self::assertSame(2, $restored, 'both placeholders restored');
        self::assertGreaterThanOrEqual(2, $segsWithEvents, 'events spread per-piece across segments, not all on the last');
    }
}
