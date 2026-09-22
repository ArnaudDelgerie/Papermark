<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Trait\ContractAssertions;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class ArchiveControllerTest extends WebTestCase
{
    use ContractAssertions;

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/archive_controller_test_' . uniqid();
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

        // Single mode by default: the current file is the source preselected.
        $client->request('GET', '/editor');
        $fileToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)->getToken('papermark_app')->getValue();

        $path = $this->workDir . '/doc.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/editor/file', ['path' => $path], [], [
            'HTTP_X-CSRF-TOKEN' => $fileToken,
        ]);

        $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-export-initial-kind-value="file"', $content);
        self::assertStringContainsString('data-export-initial-path-value="' . $path . '"', $content);
    }

    public function testIndexPreselectsTheCurrentFolderInDirMode(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $crawler = $client->request('GET', '/editor');
        $token = (string) $crawler->filter('meta[name="csrf-token"]')->attr('content');

        $client->request('POST', '/editor/mode', ['mode' => 'dir'], [], ['HTTP_X-CSRF-TOKEN' => $token]);
        $client->request('POST', '/editor/dir', ['path' => $this->workDir], [], ['HTTP_X-CSRF-TOKEN' => $token]);

        $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-export-initial-kind-value="directory"', $content);
        self::assertStringContainsString('data-export-initial-directory-value="' . $this->workDir . '"', $content);
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
        // The import goes through the master: the block carries no route nor token.
        self::assertStringNotContainsString('data-import-run-url-value', $content);
        self::assertStringNotContainsString('data-import-csrf-token-value', $content);
    }

    public function testI18nAttributesMatchTheirContracts(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/archive');

        $export = $crawler->filter('section[data-controller="export"]');
        $this->assertMatchesContract('i18n/export', json_decode((string) $export->attr('data-export-i18n-value'), true));

        $import = $crawler->filter('section[data-controller="import"]');
        $this->assertMatchesContract('i18n/import', json_decode((string) $import->attr('data-import-i18n-value'), true));
    }

    public function testIndexIsTheContentOfTheModalFrame(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/archive');

        self::assertResponseIsSuccessful();
        // A frame that carries the URL rendering it would "reference itself".
        $frame = $crawler->filter('turbo-frame#archive');
        self::assertCount(1, $frame);
        self::assertNull($frame->attr('src'));
        // No layout: the frame is the whole response.
        self::assertStringNotContainsString('<html', (string) $client->getResponse()->getContent());
        // The leave guard sits on the import button, not on a link out of the page.
        self::assertCount(1, $frame->filter('button[data-import-target="importButton"][data-editor-leave-guard]'));
        self::assertCount(0, $frame->filter('a'));
    }

    public function testMasterCarriesTheImportRouteAndThePageCarriesTheToken(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/editor');

        $master = $crawler->filter('div[data-controller="editor-state"]');
        self::assertSame('/archive/import', json_decode((string) $master->attr('data-editor-state-urls-value'), true)['import']);
        self::assertNotEmpty($crawler->filter('meta[name="csrf-token"]')->attr('content'));
    }

    public function testExportRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/archive/export', [
            'source' => $this->workDir,
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testExportReturnsErrorForMissingSource(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/archive/export', [
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('source', $data['mappedErrors'][0]['field']);
    }

    public function testExportReturnsErrorForMissingTarget(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/archive/export', [
            'source' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('target', $data['mappedErrors'][0]['field']);
    }

    public function testExportReturns404ForMissingSource(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/archive/export', [
            'source' => $this->workDir . '/does_not_exist.md',
            'target' => $this->workDir . '/out',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testExportWritesZipAppendsExtensionAndReportsIssues(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = $this->workDir . '/doc.md';
        file_put_contents($docPath, '![alt](./missing.png)');

        $client->request('POST', '/archive/export', [
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
        $this->assertMatchesContract('archive-export', $data);

        $zip = new \ZipArchive();
        $zip->open($data['path']);
        self::assertSame('![alt](./missing.png)', $zip->getFromName('doc.md'));
        $zip->close();
    }

    public function testExportRejectsDirectoryExportWhenExtImgIsAFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        file_put_contents($this->workDir . '/doc.md', '# Hello');
        file_put_contents($this->workDir . '/ext_img', 'not a directory');

        $client->request('POST', '/archive/export', [
            'source' => $this->workDir,
            'target' => $this->workDir . '/archive',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['Export needs "ext_img" to be a folder in the source folder'], $data['genericErrors']);
    }

    public function testExportReturnsNotWritableForAReadOnlyTargetFolder(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = $this->workDir . '/doc.md';
        file_put_contents($docPath, '# Hello');
        $readOnlyDir = $this->workDir . '/locked';
        mkdir($readOnlyDir);
        chmod($readOnlyDir, 0o555);

        $client->request('POST', '/archive/export', [
            'source' => $docPath,
            'target' => $readOnlyDir . '/archive',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(403);

        chmod($readOnlyDir, 0o755);
    }

    public function testImportRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/archive/import', [
            'archive' => $this->workDir . '/archive.zip',
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportReturnsErrorForMissingArchive(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('POST', '/archive/import', [
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('archive', $data['mappedErrors'][0]['field']);
    }

    public function testImportReturnsErrorForMissingParentDir(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('POST', '/archive/import', [
            'archive' => $this->workDir . '/archive.zip',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('parentDir', $data['mappedErrors'][0]['field']);
    }

    public function testImportReturns404ForMissingArchive(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('POST', '/archive/import', [
            'archive' => $this->workDir . '/does_not_exist.zip',
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImportReturns404ForMissingParentDir(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->close();

        $client->request('POST', '/archive/import', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir . '/does_not_exist',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImportReturnsNotWritableForAReadOnlyParentDir(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->close();

        $readOnlyDir = $this->workDir . '/locked';
        mkdir($readOnlyDir);
        chmod($readOnlyDir, 0o555);

        $client->request('POST', '/archive/import', [
            'archive' => $zipPath,
            'parentDir' => $readOnlyDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(403);

        chmod($readOnlyDir, 0o755);
    }

    public function testImportRejectsNonZipFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $notAZip = $this->workDir . '/archive.zip';
        file_put_contents($notAZip, 'not a zip');

        $client->request('POST', '/archive/import', [
            'archive' => $notAZip,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['The selected file is not a valid zip archive'], $data['genericErrors']);
        // A refusal still says where the state is.
        self::assertSame('single', $data['state']['mode']);
        self::assertArrayNotHasKey('action', $data);
    }

    public function testImportExtractsArchiveAndReportsOpenTarget(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/notes.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('doc.md', '# Hello');
        $zip->addFromString('notes.pdf', 'ignored');
        $zip->close();

        $client->request('POST', '/archive/import', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame([
            'destination' => $this->workDir . '/notes',
            'openMode' => 'single',
            'ignoredEntries' => ['notes.pdf'],
        ], $data['action']);
        self::assertSame($this->workDir . '/notes/doc.md', $data['state']['file']);
        self::assertSame('single', $data['state']['mode']);
        self::assertFileExists($this->workDir . '/notes/doc.md');

        // The session holds the same state: the editor's own fetch returns that file.
        $client->request('GET', '/editor/state');
        self::assertSame($data['state'], json_decode((string) $client->getResponse()->getContent(), true)['state']);

        $client->request('GET', '/document');
        self::assertSame(
            ['path' => $this->workDir . '/notes/doc.md', 'content' => '# Hello', 'revision' => hash('xxh128', '# Hello')],
            json_decode((string) $client->getResponse()->getContent(), true),
        );
    }

    public function testImportOfDirectoryArchiveOpensDirMode(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $zipPath = $this->workDir . '/project.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('a.md', '# A');
        $zip->addFromString('b.md', '# B');
        $zip->close();

        $client->request('POST', '/archive/import', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertSame('dir', $data['action']['openMode']);
        self::assertSame($this->workDir . '/project', $data['action']['destination']);
        self::assertSame('dir', $data['state']['mode']);
        self::assertSame($this->workDir . '/project', $data['state']['dir']);
        self::assertNull($data['state']['file']);

        $client->request('GET', '/editor/state');
        self::assertSame($data['state'], json_decode((string) $client->getResponse()->getContent(), true)['state']);
    }

    public function testImportOfAnArchiveWithNothingToOpenLeavesTheStateAsItWas(): void
    {
        [$client, $csrfToken] = $this->createClientWithImportCsrf();

        $client->request('GET', '/editor/state');
        $before = json_decode((string) $client->getResponse()->getContent(), true)['state'];

        $zipPath = $this->workDir . '/pdfs.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE);
        $zip->addFromString('notes.pdf', 'ignored');
        $zip->close();

        $client->request('POST', '/archive/import', [
            'archive' => $zipPath,
            'parentDir' => $this->workDir,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertNull($data['action']['openMode']);
        self::assertSame(['notes.pdf'], $data['action']['ignoredEntries']);
        self::assertSame($before, $data['state']);
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $client->request('GET', '/archive');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('papermark_app')->getValue();

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
            ->getToken('papermark_app')->getValue();

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
