<?php

// tests/Unit/Internal/ResponseWalkerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\Internal\ResponseWalker;
use Yolorouter\Llmasking\Session;

/**
 * Coverage for the response-body restore walker (spec §9.4): it navigates the
 * tokenized JSON response to locate every listed free-text string target,
 * restores each via Session::restore(), and stages {span, replacement} patches
 * in the spec-mandated traversal order. Protocol fields and unknown subtrees
 * pass through untouched; missing/null/non-string targets are skipped.
 */
final class ResponseWalkerTest extends TestCase
{
    private Engine $engine;

    private Session $session;

    protected function setUp(): void
    {
        // Use email recognizer so placeholders are reversible via Session.
        $this->engine = Engine::new();
        $this->session = $this->engine->newSession();
    }

    /**
     * Walk the response JSON and return the list of patch replacements.
     *
     * @return list<string>
     */
    private function patchedReplacements(string $json): array
    {
        $doc = JsonTokenizer::parse($json);
        $patches = ResponseWalker::walk($doc, $this->session);
        $replacements = [];
        foreach ($patches as $p) {
            $replacements[] = $p->replacement;
        }
        return $replacements;
    }

    // ---- choices[].message.content (string) ----

    public function testMessageContentStringRestored(): void
    {
        $original = 'The answer is a@x.com end';
        $masked = $this->session->anonymize($original)->text;
        // $masked contains the placeholder; embed it directly as the content.
        $json = '{"choices":[{"message":{"role":"assistant","content":"' . $masked . '"}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"content":"The answer is a@x.com end"', $out);
        self::assertStringContainsString('"role":"assistant"', $out);
    }

    public function testMessageContentNonStringSkipped(): void
    {
        $this->session->anonymize('a@x.com');
        $json = '{"choices":[{"message":{"content":null}},'
            . '{"message":{"content":42}},{"message":{"content":["part"]}}]}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    // ---- choices[].message.content[].text / .refusal ----

    public function testContentPartsTextAndRefusalRestored(): void
    {
        $masked1 = $this->session->anonymize('first@x.com')->text;
        $masked2 = $this->session->anonymize('second@x.com')->text;
        $json = '{"choices":[{"message":{"content":['
            . '{"type":"text","text":"see ' . $masked1 . '"},'
            . '{"type":"refusal","refusal":"deny ' . $masked2 . '"}]}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"text":"see first@x.com"', $out);
        self::assertStringContainsString('"refusal":"deny second@x.com"', $out);
        self::assertStringContainsString('"type":"text"', $out);
    }

    // ---- choices[].message.refusal ----

    public function testMessageRefusalRestored(): void
    {
        $masked = $this->session->anonymize('refuse@x.com')->text;
        $json = '{"choices":[{"message":{"refusal":"cannot ' . $masked . '"}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"refusal":"cannot refuse@x.com"', $out);
    }

    // ---- choices[].message.tool_calls[].function.arguments / custom.input ----

    public function testToolCallFunctionArgumentsRestored(): void
    {
        $masked = $this->session->anonymize('tool@x.com')->text;
        $json = '{"choices":[{"message":{"tool_calls":[{"id":"call_1","type":"function",'
            . '"function":{"name":"get_weather","arguments":"q=' . $masked . '"}}]}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"arguments":"q=tool@x.com"', $out);
        self::assertStringContainsString('"id":"call_1"', $out);
        self::assertStringContainsString('"name":"get_weather"', $out);
    }

    public function testToolCallCustomInputRestored(): void
    {
        $masked = $this->session->anonymize('custom@x.com')->text;
        $json = '{"choices":[{"message":{"tool_calls":[{"custom":{"name":"c","input":"v=' . $masked . '"}}]}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"input":"v=custom@x.com"', $out);
    }

    // ---- choices[].message.function_call.arguments (legacy) ----

    public function testLegacyFunctionCallArgumentsRestored(): void
    {
        $masked = $this->session->anonymize('legacy@x.com')->text;
        $json = '{"choices":[{"message":{"function_call":{"name":"f","arguments":"x=' . $masked . '"}}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"arguments":"x=legacy@x.com"', $out);
    }

    // ---- choices[].message.audio.transcript ----

    public function testAudioTranscriptRestored(): void
    {
        $masked = $this->session->anonymize('audio@x.com')->text;
        $json = '{"choices":[{"message":{"audio":{"id":"audio_1","transcript":"say ' . $masked . '"}}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"transcript":"say audio@x.com"', $out);
        // Audio id is a protocol field — untouched.
        self::assertStringContainsString('"id":"audio_1"', $out);
    }

    // ---- protocol fields NOT touched ----

    public function testProtocolFieldsNotRestored(): void
    {
        // id, model, finish_reason, index, created, system_fingerprint, etc.
        $json = '{"id":"chatcmpl-123","model":"gpt-4","choices":[{"index":0,'
            . '"finish_reason":"stop","message":{"role":"assistant","content":"hi"}}]}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testRootNotObjectIsNoOp(): void
    {
        foreach (['[]', '"hello"', '42', 'true', 'null'] as $json) {
            $doc = JsonTokenizer::parse($json);
            $patches = ResponseWalker::walk($doc, $this->session);
            self::assertSame([], $patches, "root=$json");
        }
    }

    public function testChoicesNotArraySkipped(): void
    {
        $json = '{"choices":"not_an_array"}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testNonObjectChoiceSkipped(): void
    {
        $json = '{"choices":["not_object",42,null]}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    // ---- traversal order (spec §9.4) ----

    public function testTraversalOrderMatchesSpec(): void
    {
        // Use distinct emails so each gets a unique placeholder; verify the
        // restore order: content → content[].text/refusal → refusal →
        // tool_calls args → function_call args → audio transcript.
        $m1 = $this->session->anonymize('c1@t.test')->text;
        $m2 = $this->session->anonymize('c2@t.test')->text;
        $m3 = $this->session->anonymize('c3@t.test')->text;
        $m4 = $this->session->anonymize('c4@t.test')->text;
        $m5 = $this->session->anonymize('c5@t.test')->text;
        $m6 = $this->session->anonymize('c6@t.test')->text;
        $m7 = $this->session->anonymize('c7@t.test')->text;

        $json = '{"choices":[{"message":{'
            . '"content":[{"type":"text","text":"x ' . $m1 . '"},'
            . '{"type":"refusal","refusal":"y ' . $m2 . '"}],'
            . '"refusal":"r ' . $m3 . '",'
            . '"tool_calls":[{"function":{"arguments":"a ' . $m4 . '"},'
            . '"custom":{"input":"i ' . $m5 . '"}}],'
            . '"function_call":{"arguments":"fc ' . $m6 . '"},'
            . '"audio":{"transcript":"at ' . $m7 . '"}}}]}';

        $replacements = $this->patchedReplacements($json);

        self::assertSame(
            ['x c1@t.test', 'y c2@t.test', 'r c3@t.test', 'a c4@t.test', 'i c5@t.test', 'fc c6@t.test', 'at c7@t.test'],
            $replacements,
        );
    }

    // ---- no patch when nothing to restore ----

    public function testNoPatchWhenContentHasNoPlaceholders(): void
    {
        $json = '{"choices":[{"message":{"content":"plain text without placeholders"}}]}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    // ---- byte preservation ----

    public function testNonTargetBytesPreserved(): void
    {
        $masked = $this->session->anonymize('e@x.com')->text;
        $json = '{"id":"abc","choices":[{"index":0,"message":{"content":"see ' . $masked . '"}}]}';
        $doc = JsonTokenizer::parse($json);
        $out = JsonPatch::apply($json, ResponseWalker::walk($doc, $this->session));

        self::assertStringContainsString('"id":"abc"', $out);
        self::assertStringContainsString('"index":0', $out);
        self::assertStringContainsString('"content":"see e@x.com"', $out);
    }

    public function testPatchSpanPointsAtContentBytes(): void
    {
        $masked = $this->session->anonymize('e@x.com')->text;
        // Embed the masked placeholder into a content string.
        $content = 'val ' . $masked . ' end';
        $json = '{"choices":[{"message":{"content":"' . $content . '"}}]}';
        $doc = JsonTokenizer::parse($json);
        $patches = ResponseWalker::walk($doc, $this->session);

        self::assertCount(1, $patches);
        $patch = $patches[0];
        // The span must point at the content bytes between the framing quotes.
        $spanned = \substr($doc->json, $patch->span->start, $patch->span->length);
        self::assertSame($content, $spanned);
    }

    public function testUnknownResponseFieldsPassThrough(): void
    {
        $this->session->anonymize('e@x.com');
        // usage, system_fingerprint — not targets; any placeholder inside them
        // is NOT restored (data quality limitation per spec §9.4).
        $json = '{"choices":[{"message":{"content":"plain"}}],'
            . '"usage":{"prompt_tokens":"[KEYWORD_1]"}}';
        $patches = ResponseWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    // ---- cumulative projected-body budget (codex #15) ----

    public function testResponseWalkerRejectsLengthGrowingRestoreExceedingMaxOutputBytes(): void
    {
        // Restore maps a short placeholder back to a long original, growing
        // the body. With cap == body size, the growth must fail-closed inside
        // the walker (codex #15) rather than being staged for JsonPatch.
        $original = 'john.doe+tag@subdomain.example.com'; // 34 chars
        $masked = $this->session->anonymize($original)->text; // [EMAIL_1] — 9 chars
        $json = '{"choices":[{"message":{"content":"' . $masked . '"}}]}';
        $cap = \strlen($json);

        // Build a fresh engine/session so the cap can be set; the placeholder
        // format is deterministic so the masked form has the same length.
        $engine = Engine::new(EngineOption::withMaxOutputBytes($cap));
        $session = $engine->newSession();
        $masked2 = $session->anonymize($original)->text;
        self::assertSame(\strlen($masked), \strlen($masked2));
        $json2 = '{"choices":[{"message":{"content":"' . $masked2 . '"}}]}';
        $doc = JsonTokenizer::parse($json2);

        $this->expectException(LimitExceededException::class);
        ResponseWalker::walk($doc, $session);
    }

    public function testResponseWalkerStagesRestoreWhenUnderMaxOutputBytes(): void
    {
        // Same scenario as above but with enough headroom — the budget must
        // NOT fire on legal input.
        $original = 'john.doe+tag@subdomain.example.com';
        $masked = $this->session->anonymize($original)->text;
        $json = '{"choices":[{"message":{"content":"' . $masked . '"}}]}';
        $engine = Engine::new(
            EngineOption::withMaxOutputBytes(\strlen($json) + 200),
        );
        $session = $engine->newSession();
        $session->anonymize($original);
        $doc = JsonTokenizer::parse($json);

        $patches = ResponseWalker::walk($doc, $session);
        self::assertCount(1, $patches);
    }
}
