<?php

declare(strict_types=1);

namespace LiveUX\FileManagerBundle\Tests\Unit\Security;

use LiveUX\FileManagerBundle\Security\SvgSanitizer;
use PHPUnit\Framework\TestCase;

final class SvgSanitizerTest extends TestCase
{
    public function testStripsXssVectorsAndKeepsSafeSvg(): void
    {
        $sanitizer = new SvgSanitizer();
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><foreignObject><body>bad</body></foreignObject><a href="javascript:alert(3)"><rect width="10" height="10" onclick="evil()"/></a></svg>';

        $clean = $sanitizer->sanitize($dirty);

        self::assertStringContainsString('<svg', $clean);
        self::assertStringContainsString('<rect', $clean);
        self::assertStringNotContainsString('<script', $clean);
        self::assertStringNotContainsString('onload', $clean);
        self::assertStringNotContainsString('onclick', $clean);
        self::assertStringNotContainsString('javascript:', $clean);
        self::assertStringNotContainsString('foreignObject', $clean);
    }

    public function testRejectsNonSvgXml(): void
    {
        $sanitizer = new SvgSanitizer();

        self::assertSame('', $sanitizer->sanitize('<html><body><script>alert(1)</script></body></html>'));
    }
}
