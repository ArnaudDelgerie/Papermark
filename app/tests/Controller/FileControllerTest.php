<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FileControllerTest extends WebTestCase
{
    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        // The single-mode editor page renders the Editor component which
        // generates CSRF tokens, setting the stateless CSRF cookie in the
        // response. The test client stores it and sends it on subsequent requests.
        $client->request('GET', '/editor/single');
        $client->followRedirect();

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('file')->getValue();

        return [$client, $csrfToken];
    }

    public function testOpenReturnsTranslatedErrorForMissingFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/open', [
            'path' => '/tmp/this_file_does_not_exist_12345.md',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(404);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('File not found', $data['error']);
    }

    public function testOpenReturnsTranslatedErrorForUnsupportedType(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/open', [
            'path' => '/tmp/this_file_does_not_exist.exe',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(415);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Only Markdown and text files are supported', $data['error']);
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

    public function testOpenRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/file/open', [
            'path' => '/tmp/test.md',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid security token, please reload the page', $data['error']);
    }

    public function testImageServesFileAtAbsolutePath(): void
    {
        [$client] = $this->createClientWithCsrf();
        $path = $this->createTestImage();

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/png');
    }

    public function testImageResolvesRelativePathAgainstAnchor(): void
    {
        [$client] = $this->createClientWithCsrf();
        $path = $this->createTestImage();
        $anchor = \dirname($path) . '/document.md';

        $client->request('GET', '/file/image', ['path' => basename($path), 'anchor' => $anchor]);

        self::assertResponseIsSuccessful();
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

    public function testImageReturns404ForUnsupportedExtension(): void
    {
        [$client] = $this->createClientWithCsrf();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.txt';
        file_put_contents($path, 'not an image');

        $client->request('GET', '/file/image', ['path' => $path]);

        self::assertResponseStatusCodeSame(404);

        unlink($path);
    }

    public function testOpenRemembersTheFileForTheCurrentModeInSession(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');

        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertResponseIsSuccessful();

        self::assertSame($path, $client->getRequest()->getSession()->get('singleModeFile'));

        unlink($path);
    }

    public function testOpenClearsSessionFileWhenItIsTheOneThatFailedToOpen(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();
        $client->disableReboot();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        unlink($path);

        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertResponseStatusCodeSame(404);

        self::assertNull($client->getRequest()->getSession()->get('singleModeFile'));
    }

    public function testOpenConvertsLocalImagePathsToServiceUrls(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($docPath, '![alt](./photo.png)');

        $client->request('POST', '/file/open', [
            'path' => $docPath,
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame(
            '![alt](/file/image?path=./photo.png&anchor=' . $docPath . ')',
            $data['content'],
        );

        unlink($docPath);
    }

    public function testSaveConvertsServiceUrlsBackToRawPaths(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $docPath = tempnam(sys_get_temp_dir(), 'test_') . '.md';

        $client->request('POST', '/file/save', [
            'path' => $docPath,
            'content' => '![alt](/file/image?path=./photo.png&anchor=' . $docPath . ')',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('![alt](./photo.png)', file_get_contents($docPath));

        unlink($docPath);
    }

    public function testCopyConvertsServiceUrlsBackToRawPaths(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/copy', [
            'content' => '![alt](/file/image?path=/home/user/photo.png&anchor=/home/user/doc.md)',
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
    }

    public function testDeleteReturns404ForMissingFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/file/delete', [
            'path' => '/tmp/this_file_does_not_exist_12345.md',
        ], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseStatusCodeSame(404);
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
        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertSame($path, $client->getRequest()->getSession()->get('singleModeFile'));

        $client->request('POST', '/file/delete', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertNull($client->getRequest()->getSession()->get('singleModeFile'));
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
        self::assertSame($expectedPath, $data['path']);
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
        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        $newName = basename($path, '.md') . '-renamed.md';
        $expectedPath = \dirname($path) . '/' . $newName;
        $client->request('POST', '/file/rename', ['path' => $path, 'name' => $newName], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);

        self::assertResponseIsSuccessful();
        self::assertSame($expectedPath, $client->getRequest()->getSession()->get('singleModeFile'));

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
}
