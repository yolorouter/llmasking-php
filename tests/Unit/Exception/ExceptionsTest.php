<?php

// tests/Unit/Exception/ExceptionsTest.php

namespace Yolorouter\Llmasking\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Exception\{LlmaskingException, InvalidConfigException, RegexException};

final class ExceptionsTest extends TestCase
{
    public function testAllImplementMarkerAndRuntimeException(): void
    {
        $e = new InvalidConfigException('bad');
        self::assertInstanceOf(\RuntimeException::class, $e);
        self::assertInstanceOf(LlmaskingException::class, $e);
    }

    public function testRegexExceptionCarriesPcreMessage(): void
    {
        $e = new RegexException('bad pattern');
        self::assertStringContainsString('bad pattern', $e->getMessage());
        self::assertInstanceOf(LlmaskingException::class, $e);
    }
}
