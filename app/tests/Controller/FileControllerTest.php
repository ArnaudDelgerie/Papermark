<?php

namespace App\Tests\Controller;

use App\Tests\Support\ConfiguresImageFolder;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class FileControllerTest extends WebTestCase
{
    use ConfiguresImageFolder;

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: string}
     */
    private function createClientWithCsrf(): array
    {
        $client = static::createClient();
        $this->configureImageFolder($client);
        // The home page renders the Editor component which generates CSRF
        // tokens, setting the stateless CSRF cookie in the response. The
        // test client stores it and sends it on subsequent requests.
        $client->request('GET', '/');

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
}
