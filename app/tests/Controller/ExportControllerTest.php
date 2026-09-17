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

        $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-export-initial-path-value="' . $path . '"', (string) $client->getResponse()->getContent());
    }

    public function testIndexRendersEmptyPrefillWithoutSession(): void
    {
        $client = static::createClient();

        $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('data-export-initial-path-value=""', (string) $client->getResponse()->getContent());
    }

    public function testIndexRendersBothArchiveBlocks(): void
    {
        $client = static::createClient();

        $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-controller="export"', $content);
        self::assertStringContainsString('data-controller="import"', $content);
        self::assertStringContainsString('data-import-run-url-value="/import/run"', $content);
        self::assertStringContainsString('data-import-home-url-value="/"', $content);
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

    public function testRunRejectsDirectoryExportWhenExtImgIsAFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        file_put_contents($this->workDir . '/doc.md', '# Hello');
        file_put_contents($this->workDir . '/ext_img', 'not a directory');

        $client->request('POST', '/export/run', [
            'source' => $this->workDir,
            'target' => $this->workDir . '/archive',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Export needs "ext_img" to be a folder in the source folder', $data['error']);
    }

    public function testImportRunRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/import/run', [
            'archive' => $this->workDir . '/archive.zip',
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportRunReturnsErrorForMissingArchive(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('POST', '/import/run', [
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testImportRunReturns404ForMissingArchive(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('POST', '/import/run', [
            'archive' => $this->workDir . '/does_not_exist.zip',
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImportRunReturnsNotWritableForMissingParentDir(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->close();

        $client->request('POST', '/import/run', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir . '/does_not_exist',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportRunRejectsNonZipFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $notAZip = $this->workDir . '/archive.zip';
        file_put_contents($notAZip, 'not a zip');

        $client->request('POST', '/import/run', [
            'archive' => $notAZip,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('The selected file is not a valid zip archive', $data['error']);
    }

    public function testImportRunExtractsArchiveAndReportsOpenTarget(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/notes.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->addFromString('notes.pdf', 'ignored');
        $zip->close();

        $client->request('POST', '/import/run', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame($this->workDir . '/notes', $data['destination']);
        self::assertSame('single', $data['openMode']);
        self::assertSame($this->workDir . '/notes/doc.md', $data['openPath']);
        self::assertSame(['notes.pdf'], $data['ignoredEntries']);
        self::assertFileExists($this->workDir . '/notes/doc.md');

        // app_home is what actually redirects to the editor once the session
        // points at the imported result (see ExportController::importRun()).
        $client->request('GET', '/');
        self::assertResponseRedirects('/editor/single');
        $client->followRedirect();
        self::assertStringContainsString('data-editor-initial-path-value="' . $this->workDir . '/notes/doc.md"', (string) $client->getResponse()->getContent());
    }

    public function testImportRunOfDirectoryArchiveOpensDirMode(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/project.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('a.md', '# A');
        $zip->addFromString('b.md', '# B');
        $zip->close();

        $client->request('POST', '/import/run', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('dir', $data['openMode']);
        self::assertSame($this->workDir . '/project', $data['openPath']);

        $client->request('GET', '/');
        self::assertResponseRedirects('/editor/dir');
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/archive');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('export')->getValue();

        return [$client, $csrfToken];
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithImportCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/archive');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('import')->getValue();

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
