<?php

// tests/Unit/Internal/RuleRecognizerTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Internal;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\{EntityType, Region, Finding, RegexPattern, RuleSpec};
use Yolorouter\Llmasking\Internal\{RuleRecognizer, Boundary};

final class RuleRecognizerTest extends TestCase
{
    public function testBoundaryRejectsAdjacentDigit(): void
    {
        // '13800138000' embedded in a longer digit run is rejected by boundary.
        // Each candidate below is adjacent to a trailing digit, so both are rejected.
        $rec = new RuleRecognizer('china_phone', new RuleSpec(
            EntityType::PHONE,
            Region::CN,
            RegexPattern::compile('/1[3-9][0-9]{9}/'),
            null,
            0.7,
            true,
        ));
        self::assertSame([], $rec->recognize('138001380000 and 138001380000'));
        // The lone 13800138000 (bounded by spaces) survives.
        $only = array_values(array_filter($rec->recognize('a 13800138000 b'), fn (Finding $f) => $f->text === '13800138000'));
        self::assertCount(1, $only);
    }

    public function testValidatePredicateDropsInvalid(): void
    {
        $rec = new RuleRecognizer('ssn', new RuleSpec(
            EntityType::SSN,
            Region::US,
            RegexPattern::compile('/[0-9]{3}-[0-9]{2}-[0-9]{4}/'),
            \Yolorouter\Llmasking\Internal\Validate::ssnValid(...),
            0.85,
            true,
        ));
        // '000-12-3456' fails SSN validity -> dropped; '123-45-6789' survives.
        $hits = $rec->recognize('000-12-3456 123-45-6789');
        self::assertCount(1, $hits);
        self::assertSame('123-45-6789', $hits[0]->text);
    }
}
