<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ExportControllerTest extends WebTestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/export_controller_test_' . uniqid();
        mkdir($this->workDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);

        parent::tearDown();
    }

    public function testIndexPrefillsFromSession(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        // /editor/single sets the current mode, without which /file/open has
        // no mode to remember the file under (see FileController::open()).
        $client->request('GET', '/editor/single');
        $fileToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('file')->getValue();

        $path = $this->workDir . '/doc.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/file/open', ['path' => $path], [], [
            'HTTP_X-CSRF-TOKEN' => $fileToken,
        ]);

        $client->request('GET', '/export');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-export-initial-path-value="' . $path . '"', (string) $client->getResponse()->getContent());
    }

    public function testIndexRendersEmptyPrefillWithoutSession(): void
    {
        $client = static::createClient();

        $client->request('GET', '/export');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-export-initial-path-value=""', (string) $client->getResponse()->getContent());
    }

    public function testRunRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/export/run', [
            'source' => $this->workDir,
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testRunReturnsErrorForMissingSource(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/export/run', [
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testRunReturns404ForMissingSource(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/export/run', [
            'source' => $this->workDir . '/does_not_exist.md',
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRunWritesZipAppendsExtensionAndReportsIssues(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = $this->workDir . '/doc.md';
        file_put_contents($docPath, '![alt](./missing.png)');

        $client->request('POST', '/export/run', [
            'source' => $docPath,
            'target' => $this->workDir . '/archive',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($this->workDir . '/archive.zip', $data['path']);
        self::assertFileExists($data['path']);
        self::assertCount(1, $data['issues']);
        self::assertSame('not_found', $data['issues'][0]['reason']);
        self::assertSame('./missing.png', $data['issues'][0]['originalTarget']);

        $zip = new \ZipArchive();
        $zip->open($data['path']);
        self::assertSame('![alt](./missing.png)', $zip->getFromName('doc.md'));
        $zip->close();
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/export');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('export')->getValue();

        return [$client, $csrfToken];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
