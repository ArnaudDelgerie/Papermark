<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FileControllerTest extends WebTestCase
{
    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        // The editor page renders the Editor component which generates CSRF
        // tokens, setting the stateless CSRF cookie in the response. The test
        // client stores it and sends it on subsequent requests.
        $client->request('GET', '/editor');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('papermark_app')->getValue();

        return [$client, $csrfToken];
    }

    public function testSaveReturnsTranslatedErrorForNoPath(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/save', [
            'content' => 'hello',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('No file path provided', $data['error']);
    }

    public function testImageServesFileAtAbsolutePath(): void
    {
        [$client] = $this->createClientWithCsrf();
        $path = $this->createTestImage();

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    /**
     * A third-party page holds no session cookie (SameSite=lax), so it never
     * rendered /editor and the route answers 404, whatever the path — the
     * SEC-04 existence oracle dies here.
     */
    public function testImageRefusesASessionThatNeverOpenedTheEditor(): void
    {
        $client = static::createClient();
        $path = $this->createTestImage();

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseStatusCodeSame(404);
        unlink($path);
    }

    public function testImageResolvesRelativePathAgainstTheCurrentFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();
        $path = $this->createTestImage();

        $doc = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($doc, '# Doc next to the image');
        $client->request('POST', '/editor/file', ['path' => $doc], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $client->request('GET', '/file/image', ['path' => basename($path)]);

        self::assertResponseIsSuccessful();

        unlink($doc);
    }

    /** No current file: nothing to resolve a relative path against. */
    public function testImageRefusesARelativePathWithoutACurrentFile(): void
    {
        [$client] = $this->createClientWithCsrf();

        $client->request('GET', '/file/image', ['path' => 'photo.png']);

        self::assertResponseStatusCodeSame(404);
    }

    /** The caller can't pick the anchor anymore: it's the session's file or nothing. */
    public function testImageIgnoresAnAnchorParameterFromTheCaller(): void
    {
        [$client] = $this->createClientWithCsrf();

        $client->request('GET', '/file/image', ['path' => 'photo.png', 'anchor' => '/etc']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImageReturns404WithoutPath(): void
    {
        [$client] = $this->createClientWithCsrf();

        $client->request('GET', '/file/image');

        self::assertResponseStatusCodeSame(404);
    }

    public function testImageReturns404ForMissingFile(): void
    {
        [$client] = $this->createClientWithCsrf();

        $client->request('GET', '/file/image', ['path' => '/tmp/this_image_does_not_exist_12345.png']);

        self::assertResponseStatusCodeSame(404);
    }

    /** SVG is a document with scripts, not an image (lot 02, SEC-05). */
    public function testImageReturns404ForSvg(): void
    {
        [$client] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.svg';
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseStatusCodeSame(404);

        unlink($path);
    }

    public function testImageReturns404ForUnsupportedExtension(): void
    {
        [$client] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
        file_put_contents($path, 'not an image');

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseStatusCodeSame(404);

        unlink($path);
    }

    public function testSaveConvertsServiceUrlsBackToRawPaths(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = tempnam(sys_get_temp_dir(), 'test_') . '.md';

        $client->request('POST', '/file/save', [
            'path' => $docPath,
            'content' => '![alt](/file/image?path=./photo.png)',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('![alt](./photo.png)', file_get_contents($docPath));

        unlink($docPath);
    }

    public function testSaveMakesTheSavedFileTheCurrentOne(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $opened = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($opened, '# Opened');
        $client->request('POST', '/editor/file', ['path' => $opened], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        // Save as: a reload must reopen the new file, not the one opened before.
        $savedAs = sys_get_temp_dir() . '/save_as_' . uniqid() . '.md';
        $client->request('POST', '/file/save', ['path' => $savedAs, 'content' => '# Saved as'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertSame($savedAs, $this->responseState($client)['file']);
        // The markdown never comes back; the revision does (lot 03).
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['path' => $savedAs, 'revision' => hash('xxh128', '# Saved as')], $data['action']);

        $client->request('GET', '/editor/file');
        self::assertSame(
            ['path' => $savedAs, 'content' => '# Saved as', 'revision' => hash('xxh128', '# Saved as')],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        unlink($opened);
        unlink($savedAs);
    }

    /**
     * FIL-03 (lot 03): a save carrying the revision it read answers 409 when
     * the file changed outside of Papermark, without touching anything. The
     * same save without a revision (Écraser) then writes.
     */
    public function testSaveRefusesAStaleRevisionAndWritesWithoutOne(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# On disk');
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        // The file changes outside of Papermark after the read.
        file_put_contents($path, "# Changed outside\n");

        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => '# Mine',
            'revision' => hash('xxh128', '# On disk'),
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('The file was modified outside of Papermark', $data['error']);
        self::assertSame("# Changed outside\n", file_get_contents($path));

        // Écraser: the same save without a revision, blind write.
        $client->request('POST', '/file/save', ['path' => $path, 'content' => '# Mine'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertSame('# Mine', file_get_contents($path));

        unlink($path);
    }

    /** The other 409: the file disappeared since the read. */
    public function testSaveRefusesTheRevisionOfAGoneFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# On disk');
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        unlink($path);

        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => '# Mine',
            'revision' => hash('xxh128', '# On disk'),
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(409);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('The file no longer exists on disk', $data['error']);
        self::assertFileDoesNotExist($path);
    }

    public function testSaveAnswersWithTheRenewedRevisionAndAcceptsItBack(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# On disk');
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => '# First',
            'revision' => hash('xxh128', '# On disk'),
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(hash('xxh128', '# First'), $data['action']['revision']);

        // The renewed revision is what the next save compares against.
        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => '# Second',
            'revision' => $data['action']['revision'],
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertSame('# Second', file_get_contents($path));

        unlink($path);
    }

    /** FIL-11 (lot 03): a content already on disk answers success without writing. */
    public function testSaveSkipsTheWriteWhenTheContentIsIdentical(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, "# Same\n");
        touch($path, 1000000000);
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => "# Same\n",
            'revision' => hash('xxh128', "# Same\n"),
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(hash('xxh128', "# Same\n"), $data['action']['revision']);
        clearstatcache();
        // No write: the file keeps its date, no other app sees it move.
        self::assertSame(1000000000, filemtime($path));
        self::assertSame("# Same\n", file_get_contents($path));

        unlink($path);
    }

    public function testSaveReappliesTheBomAndCrLfOfTheExistingFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        $disk = "\xEF\xBB\xBF# A\r\n# B\r\n";
        file_put_contents($path, $disk);
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $client->request('POST', '/file/save', [
            'path' => $path,
            'content' => "# A\n# B, edited\n",
            'revision' => hash('xxh128', $disk),
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        $expected = "\xEF\xBB\xBF# A\r\n# B, edited\r\n";
        self::assertSame($expected, file_get_contents($path));
        self::assertSame(hash('xxh128', $expected), json_decode((string) $client->getResponse()->getContent(), true)['action']['revision']);

        unlink($path);
    }

    /**
     * rename() only asks the folder: this control is what keeps a read-only
     * file unreplaceable now that the write is atomic (lot 03).
     */
    public function testSaveRefusesAReadOnlyFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Read only');
        chmod($path, 0444);

        $client->request('POST', '/file/save', ['path' => $path, 'content' => '# No'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('# Read only', file_get_contents($path));

        chmod($path, 0644);
        unlink($path);
    }

    public function testCopyConvertsServiceUrlsBackToRawPaths(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/copy', [
            'content' => '![alt](/file/image?path=/home/user/photo.png)',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('![alt](/home/user/photo.png)', $data['content']);
    }

    public function testCopyRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/file/copy', [
            'content' => '![alt](photo.png)',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteRemovesTheFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');

        $client->request('POST', '/file/delete', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertFileDoesNotExist($path);
        self::assertSame(['path' => $path], json_decode((string) $client->getResponse()->getContent(), true)['action']);
    }

    public function testDeleteReturns404ForMissingFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/delete', [
            'path' => '/tmp/this_file_does_not_exist_12345.md',
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(404);
        // A refusal still carries the state (S6).
        self::assertSame(['mode', 'file', 'dir', 'ai_enabled'], array_keys($this->responseState($client)));
    }

    public function testDeleteRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/file/delete', ['path' => '/tmp/test.md'], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeleteClearsSessionFileWhenItIsTheOneDeleted(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertSame($path, $this->responseState($client)['file']);

        $client->request('POST', '/file/delete', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->responseState($client)['file']);
    }

    public function testRenameChangesOnlyTheFileName(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $newName = basename($path, '.md') . '-renamed.md';
        $expectedPath = \dirname($path) . '/' . $newName;

        $client->request('POST', '/file/rename', ['path' => $path, 'name' => $newName], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(['oldPath' => $path, 'newPath' => $expectedPath], $data['action']);
        self::assertFileDoesNotExist($path);
        self::assertSame('# Hello', file_get_contents($expectedPath));

        unlink($expectedPath);
    }

    public function testRenameUpdatesSessionFileWhenItIsTheOneRenamed(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/editor/file', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $newName = basename($path, '.md') . '-renamed.md';
        $expectedPath = \dirname($path) . '/' . $newName;
        $client->request('POST', '/file/rename', ['path' => $path, 'name' => $newName], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertSame($expectedPath, $this->responseState($client)['file']);

        unlink($expectedPath);
    }

    public function testRenameRejectsANameContainingAPathSeparator(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');

        $client->request('POST', '/file/rename', ['path' => $path, 'name' => '../evil.md'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(400);
        self::assertFileExists($path);

        unlink($path);
    }

    public function testRenameReturnsConflictWhenTargetAlreadyExists(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $existingName = basename($path, '.md') . '-taken.md';
        $existingPath = \dirname($path) . '/' . $existingName;
        file_put_contents($existingPath, '# Taken');

        $client->request('POST', '/file/rename', ['path' => $path, 'name' => $existingName], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(409);

        unlink($path);
        unlink($existingPath);
    }

    public function testRenameRejectsUnsupportedExtension(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');

        $client->request('POST', '/file/rename', ['path' => $path, 'name' => 'renamed.exe'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(415);
        self::assertFileExists($path);

        unlink($path);
    }

    public function testRenameRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/file/rename', ['path' => '/tmp/test.md', 'name' => 'new.md'], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid security token, please reload the page', $data['error']);
        self::assertArrayHasKey('state', $data);
    }

    /**
     * The SEC-02 scenario: `innocent.md` in an open folder links to an
     * important file elsewhere. Writing through the link — save, delete,
     * rename — must be refused, the target left intact.
     *
     * @return array{0: string, 1: string} the link, the target
     */
    private function createSymlinkedMarkdown(): array
    {
        if (\PHP_OS_FAMILY !== 'Linux' && \PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Symbolic links are a Unix matter here.');
        }

        $target = tempnam(sys_get_temp_dir(), 'important_') . '.md';
        file_put_contents($target, '# Important');
        $link = tempnam(sys_get_temp_dir(), 'innocent_') . '.md';
        unlink($link);
        symlink($target, $link);

        return [$link, $target];
    }

    public function testSaveRefusesASymlinkAndLeavesTheTargetIntact(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        [$link, $target] = $this->createSymlinkedMarkdown();

        $client->request('POST', '/file/save', ['path' => $link, 'content' => '# Overwritten'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('# Important', file_get_contents($target));
        self::assertTrue(is_link($link));

        unlink($link);
        unlink($target);
    }

    public function testDeleteRefusesASymlinkAndLeavesTheTargetIntact(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        [$link, $target] = $this->createSymlinkedMarkdown();

        $client->request('POST', '/file/delete', ['path' => $link], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($target);
        self::assertSame('# Important', file_get_contents($target));

        unlink($link);
        unlink($target);
    }

    public function testRenameRefusesASymlinkAndLeavesTheTargetIntact(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        [$link, $target] = $this->createSymlinkedMarkdown();

        $client->request('POST', '/file/rename', ['path' => $link, 'name' => 'moved.md'], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($target);
        self::assertSame('# Important', file_get_contents($target));
        self::assertFileDoesNotExist(\dirname($link) . '/moved.md');

        unlink($link);
        unlink($target);
    }

    /**
     * A 1x1 transparent PNG, written under a real extension so it round-trips
     * through PathResolver's realpath() + File constraint.
     */
    private function createTestImage(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'test_') . '.png';
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));

        return $path;
    }

    /**
     * @return array{mode: string, file: ?string, dir: ?string}
     */
    private function responseState(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true)['state'];
    }
}
