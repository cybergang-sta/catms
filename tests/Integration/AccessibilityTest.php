<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * NFR-UX-01 and the installable client. These checks read the shipped files.
 * A satisfaction score is a study with people, so it is not asserted here.
 */
final class AccessibilityTest extends TestCase
{
    public function testTheShellIsAMobileInstallableDocument(): void
    {
        $root = dirname(__DIR__, 2);
        $html = (string) file_get_contents($root . '/public/index.html');
        $css = (string) file_get_contents($root . '/public/assets/css/app.css');
        $manifest = json_decode((string) file_get_contents($root . '/public/manifest.webmanifest'), true);
        $router = (string) file_get_contents($root . '/public/router.php');

        self::assertStringContainsString('lang="en"', $html);
        self::assertStringContainsString('name="viewport"', $html);
        self::assertStringContainsString('rel="manifest"', $html);
        self::assertStringContainsString('class="skip"', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertDoesNotMatchRegularExpression('/fonts\.googleapis|fonts\.gstatic/i', $html . $css);

        self::assertIsArray($manifest);
        self::assertSame('standalone', $manifest['display']);
        self::assertStringContainsString('min-height: 44px', $css);
        self::assertStringContainsString('prefers-reduced-motion', $css);
        self::assertStringContainsString("default-src 'self'", $router);
        self::assertFileExists($root . '/public/service-worker.js');
    }
}
