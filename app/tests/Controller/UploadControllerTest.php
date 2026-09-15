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
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $this->configureImageFolder($client);
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

    public function testDeleteRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('DELETE', '/upload/image', [
            'url' => '/uploads/images/ab/test.png',
        ], [], [
            'HTTP_X-CSRF-TOKEN' => 'invalid',
        ]);

        self::assertResponseStatusCodeSame(403);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertSame('Invalid security token, please reload the page', $data['error']);
    }
}
