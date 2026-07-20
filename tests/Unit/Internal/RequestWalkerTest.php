<?php

// tests/Unit/Internal/RequestWalkerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Engine;
use Yolorouter\Llmasking\EngineOption;
use Yolorouter\Llmasking\Exception\LimitExceededException;
use Yolorouter\Llmasking\Internal\JsonPatch;
use Yolorouter\Llmasking\Internal\JsonTokenizer;
use Yolorouter\Llmasking\Internal\RequestWalker;
use Yolorouter\Llmasking\Session;

/**
 * Coverage for the request-body free-text walker (spec §9.3 path table): it
 * navigates the tokenized JSON to locate every listed free-text string target,
 * anonymizes each via Session::anonymize(), and stages {span, replacement}
 * patches in the spec-mandated traversal order. Protocol fields and unknown
 * subtrees pass through untouched; missing/null/non-string targets are skipped.
 */
final class RequestWalkerTest extends TestCase
{
    private Engine $engine;

    private Session $session;

    protected function setUp(): void
    {
        // Keyword "TARGET" → masked to [KEYWORD_n]; deterministic for path testing.
        $this->engine = Engine::new(EngineOption::withKeywords('TARGET'));
        $this->session = $this->engine->newSession();
    }

    /**
     * Walk the JSON, apply patches, and return the patched JSON.
     */
    private function walkAndPatch(string $json): string
    {
        $doc = JsonTokenizer::parse($json);
        $patches = RequestWalker::walk($doc, $this->session);

        return JsonPatch::apply($json, $patches);
    }

    /**
     * Walk the JSON and return the list of decoded values that were sent to
     * anonymize (via inspecting the resulting patches' replacements).
     *
     * @return list<string>
     */
    private function patchedReplacements(string $json): array
    {
        $doc = JsonTokenizer::parse($json);
        $patches = RequestWalker::walk($doc, $this->session);
        $replacements = [];
        foreach ($patches as $p) {
            $replacements[] = $p->replacement;
        }
        return $replacements;
    }

    // ---- messages[].content (string) ----

    public function testMessageContentStringAnonymized(): void
    {
        $json = '{"messages":[{"role":"user","content":"call TARGET now"}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"content":"call [KEYWORD_1] now"', $out);
        // Protocol field role untouched.
        self::assertStringContainsString('"role":"user"', $out);
    }

    public function testMessageContentNonStringSkipped(): void
    {
        // content is an array (multipart) — the string path is not applicable;
        // the array path is handled separately. null/number are also skipped.
        $json = '{"messages":[{"content":null},{"content":42},{"content":["part"]}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches, 'non-string content must not produce patches');
    }

    // ---- messages[].content[].text / .refusal ----

    public function testMessageContentPartsTextAndRefusalAnonymized(): void
    {
        $json = '{"messages":[{"content":[{"type":"text","text":"see TARGET"},'
            . '{"type":"refusal","refusal":"deny TARGET"}]}]}';
        $out = $this->walkAndPatch($json);

        // Same keyword "TARGET" deduplicates to the same [KEYWORD_1] in both
        // fields — Session shares the (KEYWORD, "TARGET") mapping.
        self::assertStringContainsString('"text":"see [KEYWORD_1]"', $out);
        self::assertStringContainsString('"refusal":"deny [KEYWORD_1]"', $out);
        // Protocol sibling "type" untouched.
        self::assertStringContainsString('"type":"text"', $out);
        self::assertStringContainsString('"type":"refusal"', $out);
    }

    public function testContentPartWithoutTextOrRefusalSkipped(): void
    {
        // Image part has no text/refusal strings — nothing to process.
        $json = '{"messages":[{"content":[{"type":"image_url","image_url":{"url":"data:TARGET"}}]}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    // ---- messages[].refusal ----

    public function testMessageRefusalAnonymized(): void
    {
        $json = '{"messages":[{"refusal":"cannot TARGET"}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"refusal":"cannot [KEYWORD_1]"', $out);
    }

    // ---- messages[].tool_calls[].function.arguments / custom.input ----

    public function testToolCallFunctionArgumentsAnonymized(): void
    {
        $json = '{"messages":[{"tool_calls":[{"id":"call_1","type":"function",'
            . '"function":{"name":"get_weather","arguments":"city=TARGET"}}]}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"arguments":"city=[KEYWORD_1]"', $out);
        // Protocol fields untouched.
        self::assertStringContainsString('"id":"call_1"', $out);
        self::assertStringContainsString('"name":"get_weather"', $out);
    }

    public function testToolCallCustomInputAnonymized(): void
    {
        $json = '{"messages":[{"tool_calls":[{"custom":{"name":"my_tool","input":"data=TARGET"}}]}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"input":"data=[KEYWORD_1]"', $out);
    }

    // ---- messages[].function_call.arguments (legacy) ----

    public function testLegacyFunctionCallArgumentsAnonymized(): void
    {
        $json = '{"messages":[{"function_call":{"name":"get_weather","arguments":"q=TARGET"}}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"arguments":"q=[KEYWORD_1]"', $out);
    }

    // ---- tools[].function.description / .parameters / custom.description ----

    public function testToolFunctionDescriptionAnonymized(): void
    {
        $json = '{"tools":[{"type":"function","function":{"name":"f","description":"tool TARGET"}}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"description":"tool [KEYWORD_1]"', $out);
    }

    public function testToolFunctionParametersSchemaDescriptionAnonymized(): void
    {
        $json = '{"tools":[{"type":"function","function":{"name":"f","parameters":'
            . '{"type":"object","properties":{"city":{"description":"the TARGET city"}}}}}]}';
        $out = $this->walkAndPatch($json);

        // SchemaWalker finds properties.city.description.
        self::assertStringContainsString('"description":"the [KEYWORD_1] city"', $out);
    }

    public function testToolCustomDescriptionAnonymized(): void
    {
        $json = '{"tools":[{"type":"custom","custom":{"name":"c","description":"custom TARGET"}}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"description":"custom [KEYWORD_1]"', $out);
    }

    // ---- functions[].description / .parameters ----

    public function testFunctionsDescriptionAnonymized(): void
    {
        $json = '{"functions":[{"name":"f","description":"legacy TARGET"}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"description":"legacy [KEYWORD_1]"', $out);
    }

    public function testFunctionsParametersSchemaTitleAnonymized(): void
    {
        $json = '{"functions":[{"name":"f","parameters":{"title":"TITLE TARGET"}}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"title":"TITLE [KEYWORD_1]"', $out);
    }

    // ---- response_format.json_schema ----

    public function testResponseFormatJsonSchemaDescriptionAnonymized(): void
    {
        $json = '{"response_format":{"type":"json_schema","json_schema":'
            . '{"name":"my_schema","description":"format TARGET"}}}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"description":"format [KEYWORD_1]"', $out);
    }

    public function testResponseFormatJsonSchemaSchemaNodeAnonymized(): void
    {
        $json = '{"response_format":{"type":"json_schema","json_schema":'
            . '{"name":"s","schema":{"description":"schema TARGET"}}}}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"description":"schema [KEYWORD_1]"', $out);
    }

    // ---- prediction.content (string) / content[].text ----

    public function testPredictionContentStringAnonymized(): void
    {
        $json = '{"prediction":{"content":"predict TARGET"}}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"content":"predict [KEYWORD_1]"', $out);
    }

    public function testPredictionContentPartsTextAnonymized(): void
    {
        $json = '{"prediction":{"content":[{"type":"text","text":"pred TARGET"}]}}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"text":"pred [KEYWORD_1]"', $out);
    }

    // ---- protocol fields NOT touched ----

    public function testProtocolFieldsNotAnonymized(): void
    {
        // model, user, stop, temperature, role, name, tool_choice — none are targets.
        $json = '{"model":"TARGET","user":"TARGET","stop":"TARGET","temperature":0.7,'
            . '"tool_choice":"TARGET","messages":[{"role":"TARGET","name":"TARGET","content":"hi"}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches, 'protocol fields must not be anonymized');
    }

    public function testNonExistentTopLevelFieldsAreNoOp(): void
    {
        $json = '{"model":"gpt","stream":true}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testRootNotObjectIsNoOp(): void
    {
        foreach (['[]', '"hello"', '42', 'true', 'null'] as $json) {
            $doc = JsonTokenizer::parse($json);
            $patches = RequestWalker::walk($doc, $this->session);
            self::assertSame([], $patches, "root=$json");
        }
    }

    // ---- traversal order (spec §9.3) ----

    public function testTraversalOrderMatchesSpec(): void
    {
        // Use DISTINCT email addresses in each target so the Session assigns
        // unique [EMAIL_n] placeholders in traversal order.
        $json = '{"messages":[{"content":"m@t1.test","tool_calls":[{"function":{"arguments":"a@t2.test"},'
            . '"custom":{"input":"c@t3.test"}}],"function_call":{"arguments":"fc@t4.test"},'
            . '"refusal":"r@t5.test"}],'
            . '"tools":[{"function":{"description":"td@t6.test","parameters":{"description":"tp@t7.test"}}},'
            . '{"custom":{"description":"cd@t8.test"}}],'
            . '"functions":[{"description":"fd@t9.test","parameters":{"description":"fp@t10.test"}}],'
            . '"response_format":{"json_schema":{"description":"rfd@t11.test","schema":'
            . '{"description":"rfs@t12.test"}}},'
            . '"prediction":{"content":"p@t13.test"}}';
        $replacements = $this->patchedReplacements($json);

        // Each replacement contains [EMAIL_n] where n reflects traversal order.
        $expected = [
            '[EMAIL_1]',  // messages[0].content
            '[EMAIL_2]',  // messages[0].refusal
            '[EMAIL_3]',  // messages[0].tool_calls[0].function.arguments
            '[EMAIL_4]',  // messages[0].tool_calls[0].custom.input
            '[EMAIL_5]',  // messages[0].function_call.arguments
            '[EMAIL_6]',  // tools[0].function.description
            '[EMAIL_7]',  // tools[0].function.parameters.description
            '[EMAIL_8]',  // tools[1].custom.description
            '[EMAIL_9]',  // functions[0].description
            '[EMAIL_10]', // functions[0].parameters.description
            '[EMAIL_11]', // response_format.json_schema.description
            '[EMAIL_12]', // response_format.json_schema.schema.description
            '[EMAIL_13]', // prediction.content
        ];
        self::assertSame($expected, $replacements);
    }

    // ---- duplicate keys / edge cases ----

    public function testDuplicateMessagesEachProcessed(): void
    {
        $json = '{"messages":[{"content":"first TARGET"},{"content":"second TARGET"}]}';
        $out = $this->walkAndPatch($json);

        // Same keyword "TARGET" deduplicates — both produce [KEYWORD_1], but
        // both ARE patched (different spans).
        self::assertStringContainsString('"content":"first [KEYWORD_1]"', $out);
        self::assertStringContainsString('"content":"second [KEYWORD_1]"', $out);
    }

    public function testDuplicateContentKeyBothProcessed(): void
    {
        // Duplicate "content" key — both occurrences processed in raw order.
        $json = '{"messages":[{"content":"a TARGET","content":"b TARGET"}]}';
        $out = $this->walkAndPatch($json);

        self::assertStringContainsString('"content":"a [KEYWORD_1]"', $out);
        self::assertStringContainsString('"content":"b [KEYWORD_1]"', $out);
    }

    public function testNoPatchWhenNoPiiFound(): void
    {
        // No keyword present — anonymize returns unchanged; no patches.
        $json = '{"messages":[{"content":"just normal text"}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->engine->newSession());

        self::assertSame([], $patches);
    }

    public function testNonObjectMessageSkipped(): void
    {
        // messages[] contains a non-object element — skipped (not rejected).
        $json = '{"messages":["not_an_object",42,null]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testMessagesNotArraySkipped(): void
    {
        $json = '{"messages":"not_an_array"}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testUnknownFieldsPassThrough(): void
    {
        // Vendor extension fields are NOT targets.
        $json = '{"custom_field":"TARGET","x-header":"TARGET","messages":[{"x":"TARGET","content":"hi"}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testContentArrayWithNonObjectPartSkipped(): void
    {
        $json = '{"messages":[{"content":["string_part",42,null]}]}';
        $patches = RequestWalker::walk(JsonTokenizer::parse($json), $this->session);

        self::assertSame([], $patches);
    }

    public function testFullBodyPreservesNonTargetBytes(): void
    {
        $json = '{"model":"gpt-4","n":42, "messages":[{"role":"user","content":"email TARGET here"}]}';
        $out = $this->walkAndPatch($json);

        // Whitespace, number, model all preserved byte-for-byte.
        self::assertStringContainsString('"model":"gpt-4"', $out);
        self::assertStringContainsString('"n":42, "messages"', $out);
        self::assertStringContainsString('"content":"email [KEYWORD_1] here"', $out);

        // Verify the integer survived without float coercion.
        $decoded = \json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        self::assertSame(42, $decoded['n']);
    }

    public function testPatchSpanPointsAtContentBytes(): void
    {
        $json = '{"messages":[{"content":"TARGET"}]}';
        $doc = JsonTokenizer::parse($json);
        $patches = RequestWalker::walk($doc, $this->session);

        self::assertCount(1, $patches);
        $patch = $patches[0];
        $spanned = \substr($doc->json, $patch->span->start, $patch->span->length);
        self::assertSame('TARGET', $spanned);
    }

    // ---- cumulative projected-body budget (codex #15) ----

    public function testWalkerRejectsLengthGrowingPatchExceedingMaxOutputBytes(): void
    {
        // Body sits exactly at the cap; the keyword replacement ("TARGET" →
        // "[KEYWORD_1]") grows the body by 5 bytes, which must fail-closed
        // inside the walker rather than being staged for JsonPatch to apply.
        $json = '{"messages":[{"content":"x TARGET y"}]}';
        $cap = \strlen($json);
        $engine = Engine::new(
            EngineOption::withKeywords('TARGET'),
            EngineOption::withMaxOutputBytes($cap),
        );
        $session = $engine->newSession();
        $doc = JsonTokenizer::parse($json);

        $this->expectException(LimitExceededException::class);
        RequestWalker::walk($doc, $session);
    }

    public function testWalkerStagesAllPatchesWhenUnderMaxOutputBytes(): void
    {
        // Same body, but with enough headroom for two length-growing patches —
        // the budget must NOT fire on legal input.
        $json = '{"messages":[{"content":"x TARGET y"},{"content":"x TARGET z"}]}';
        // Need headroom for 2 patches × 5 bytes growth + a safety margin.
        $engine = Engine::new(
            EngineOption::withKeywords('TARGET'),
            EngineOption::withMaxOutputBytes(\strlen($json) + 100),
        );
        $session = $engine->newSession();
        $doc = JsonTokenizer::parse($json);

        $patches = RequestWalker::walk($doc, $session);
        self::assertCount(2, $patches);
    }

    public function testWalkerBudgetChargesSchemaWalkerPatches(): void
    {
        // SchemaWalker shares the budget with the calling RequestWalker
        // (codex #15). Drive a tool description + a nested schema description
        // with a cap that admits only one growing patch.
        $json = '{"tools":[{"function":{"description":"x TARGET y",'
            . '"parameters":{"description":"x TARGET z"}}}]}';
        $cap = \strlen($json);
        $engine = Engine::new(
            EngineOption::withKeywords('TARGET'),
            EngineOption::withMaxOutputBytes($cap),
        );
        $session = $engine->newSession();
        $doc = JsonTokenizer::parse($json);

        $this->expectException(LimitExceededException::class);
        RequestWalker::walk($doc, $session);
    }
}
