<?php

declare(strict_types=1);

namespace App\Tests\Manifest;

use PHPUnit\Framework\TestCase;

/**
 * What the hub reads before the app exists: the manifest's icon and cold-start
 * page (UX-08 and the splash page, lot 10), and the contract §8 rule that the
 * splash page may load nothing external.
 */
final class TfsappConfigTest extends TestCase
{
    private const PROJECT_DIR = __DIR__ . '/../..';

    public function testTheManifestDeclaresAnIconThatExistsAtTheRoot(): void
    {
        $manifest = $this->manifest();

        self::assertSame('icon.png', $manifest['icon_path']);
        // A square 1024×1024 PNG, what the hub wants as its source icon.
        [$width, $height, $type] = getimagesize(self::PROJECT_DIR . '/' . $manifest['icon_path']) ?: [0, 0, 0];
        self::assertSame([1024, 1024, IMAGETYPE_PNG], [$width, $height, $type]);
    }

    public function testTheManifestDeclaresASplashPageThatExistsAtTheRoot(): void
    {
        $manifest = $this->manifest();

        self::assertSame('splash.html', $manifest['splash_path']);
        self::assertFileExists(self::PROJECT_DIR . '/' . $manifest['splash_path']);
    }

    public function testTheManifestKeepsItsFallbackSplashColours(): void
    {
        $manifest = $this->manifest();

        // For when the page is missing or unreadable: the host's own page,
        // recoloured (contract §8).
        self::assertArrayHasKey('splash_bg', $manifest);
        self::assertArrayHasKey('splash_text', $manifest);
    }

    public function testTheSplashPageLoadsNothingExternal(): void
    {
        $splash = (string) file_get_contents(self::PROJECT_DIR . '/splash.html');

        // CSP `default-src 'none'`: every src and href must be inline (none)
        // or data:. The style and the SVG already are.
        preg_match_all('/(?:src|href)="([^"]*)"/', $splash, $matches);
        self::assertNotFalse($matches);
        $external = array_filter($matches[1], static fn (string $url): bool => !str_starts_with($url, 'data:'));
        self::assertSame([], $external);

        // Both themes are in it (the page follows the system only), and the
        // loading animation is cut under reduced motion.
        self::assertStringContainsString('prefers-color-scheme: light', $splash);
        self::assertStringContainsString('prefers-reduced-motion: reduce', $splash);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $decoded = json_decode((string) file_get_contents(self::PROJECT_DIR . '/tfsapp.config.json'), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
