<?php

namespace App\Tests\Controller;

use App\Tests\Support\ConfiguresImageFolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class UploadControllerTest extends WebTestCase
{
    use ConfiguresImageFolder;

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(?string $imageFolder = null): array
    {
        $client = static::createClient();
        $this->configureImageFolder($client, $imageFolder ?? '/home/user/Pictures');
        $client->request('GET', '/');

        $csrfToken = $client->getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('upload')->getValue();

        return [$client, $csrfToken];
    }

    public function testUploadRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/upload/image', [], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid security token, please reload the page', $data['error']);
    }

    public function testUploadReturnsTranslatedErrorForNoFile(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $client->request('POST', '/upload/image', [], [], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('No file provided', $data['error']);
    }

    public function testUploadReturnsTranslatedErrorForNotAnImage(): void
    {
        [$client, $csrfToken] = $this->createClientWithCsrf();

        $tempPath = tempnam(sys_get_temp_dir(), 'test_');
        file_put_contents($tempPath, 'not an image');

        $file = new UploadedFile($tempPath, 'test.txt', 'text/plain', null, true);

        $client->request('POST', '/upload/image', [], ['file' => $file], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(415);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Only image files are supported', $data['error']);
    }

    public function testUploadWritesUnderClipboardSubfolderOfImageFolderAndReturnsServiceUrl(): void
    {
        $imageFolder = sys_get_temp_dir() . '/' . uniqid('images_', true);
        mkdir($imageFolder);
        [$client, $csrfToken] = $this->createClientWithCsrf($imageFolder);

        $tempPath = tempnam(sys_get_temp_dir(), 'test_') . '.png';
        file_put_contents($tempPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
        $file = new UploadedFile($tempPath, 'pasted.png', 'image/png', null, true);

        $client->request('POST', '/upload/image', [], ['file' => $file], [
            'HTTP_X-CSRF-TOKEN' => $csrfToken,
        ]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);

        self::assertStringStartsWith('/file/image?path=', $data['url']);
        parse_str(parse_url($data['url'], \PHP_URL_QUERY), $query);
        self::assertStringStartsWith($imageFolder . '/clipboard/', $query['path']);
        self::assertFileExists($query['path']);

        unlink($tempPath);
        unlink($query['path']);
        rmdir($imageFolder . '/clipboard');
        rmdir($imageFolder);
    }
}
