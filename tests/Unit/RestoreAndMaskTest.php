<?php

// tests/Unit/RestoreAndMaskTest.php

namespace Yolorouter\Llmasking\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Yolorouter\Llmasking\Engine;

final class RestoreAndMaskTest extends TestCase
{
    public function testRoundTrip(): void
    {
        $s = Engine::new()->newSession();
        $s->anonymize('我是张三，手机13800138000');
        $r = $s->restore('好的[PHONE_1]');
        self::assertSame('好的13800138000', $r->text);
        self::assertCount(1, $r->events);
        self::assertTrue($r->events[0]->restored);
        self::assertSame('PHONE', $r->events[0]->entity);
        self::assertSame('[PHONE_1]', $r->events[0]->placeholder);
    }

    public function testUnmatchedPlaceholderLeftAsIs(): void
    {
        $s = Engine::new()->newSession();
        $r = $s->restore('stray [PHONE_99]');
        self::assertSame('stray [PHONE_99]', $r->text);
        self::assertFalse($r->events[0]->restored);
    }

    public function testSecretRedactNotRestorable(): void
    {
        $s = Engine::new()->newSession();
        $masked = $s->anonymize('k=AKIAIOSFODNN7EXAMPLE')->text; // -> k=[CLOUDKEY_1]
        $r = $s->restore($masked);
        self::assertSame($masked, $r->text); // Redact never wrote a mapping
        self::assertFalse($r->events[0]->restored);
    }

    public function testMaskIsStatelessFromOne(): void
    {
        $e = Engine::new();
        self::assertSame('用户[PHONE_1]登录', $e->mask('用户13800138000登录'));
    }

    public function testMaskDoesNotPopulateSession(): void
    {
        $e = Engine::new();
        $e->mask('13800138000'); // throwaway session, no mapping retained
        $s = $e->newSession();
        $r = $s->restore('[PHONE_1]');
        self::assertSame('[PHONE_1]', $r->text);
        self::assertFalse($r->events[0]->restored);
    }

    public function testZeroPaddedPlaceholderResolves(): void
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000'); // -> [PHONE_1]
        $r = $s->restore('call [PHONE_01] now');
        self::assertSame('call 13800138000 now', $r->text);
    }

    public function testFullwidthBracketPlaceholderResolves(): void
    {
        $s = Engine::new()->newSession();
        $s->anonymize('13800138000');
        $r = $s->restore('好的【PHONE_1】');
        self::assertSame('好的13800138000', $r->text);
    }
}
