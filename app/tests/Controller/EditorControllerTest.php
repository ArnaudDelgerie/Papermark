<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Editor\EditorMode;
use App\File\DirectoryTree;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class EditorControllerTest extends WebTestCase
{
    public function testSingleModeRendersEditorAndModeSingleSidebar(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/single');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        self::assertSame(1, $crawler->filter('div[data-controller="editor"]')->count());
        self::assertSame(1, $crawler->filter('div[data-controller="mode-single"]')->count());
        self::assertSame(1, $crawler->filter('.mode-selector-link.is-active')->count());
        self::assertSame('Single file', trim($crawler->filter('.mode-selector-link.is-active')->text()));

        // New replaces the old Open button in the editor's own file bar.
        self::assertSame(6, $crawler->filter('div[data-controller="editor"] button.editor-filebar-btn')->count());
        self::assertSame('New', trim($crawler->filter('button[data-action="click->editor#newFile"]')->text()));

        // Open (file) lives in the sidebar now.
        self::assertSame('Open', trim($crawler->filter('button[data-action="click->mode-single#openFile"]')->text()));
    }

    public function testSingleModeEmbedsSessionRememberedFileOnNextRender(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/editor/single');
        $client->followRedirect();
        $csrfToken = static::getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('file')->getValue();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');

        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/editor/single');
        $crawler = $client->followRedirect();

        $editor = $crawler->filter('div[data-controller="editor"]');
        self::assertSame($path, $editor->attr('data-editor-initial-path-value'));
        self::assertSame('# Hello', $editor->attr('data-editor-initial-content-value'));

        unlink($path);
    }

    public function testSingleModeSilentlyDropsStaleSessionFile(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/editor/single');
        $client->followRedirect();
        $csrfToken = static::getContainer()->get(CsrfTokenManagerInterface::class)
            ->getToken('file')->getValue();

        $path = tempnam(sys_get_temp_dir(), 'test_') . '.md';
        file_put_contents($path, '# Hello');
        $client->request('POST', '/file/open', ['path' => $path], [], ['HTTP_X-CSRF-TOKEN' => $csrfToken]);
        unlink($path);

        $client->request('GET', '/editor/single');
        $crawler = $client->followRedirect();

        $editor = $crawler->filter('div[data-controller="editor"]');
        self::assertNull($editor->attr('data-editor-initial-path-value'));
    }

    public function testFlashMessagesAreEmbeddedAsInitialToastValues(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/editor/single');
        $client->followRedirect();
        $session = $client->getRequest()->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            self::fail('Session does not support flash messages.');
        }
        $session->getFlashBag()->add('success', 'Test message');
        $session->save();

        $client->request('GET', '/editor/single');
        $crawler = $client->followRedirect();

        $toast = $crawler->filter('div[data-controller="toast"]');
        self::assertSame(
            [['type' => 'success', 'message' => 'Test message']],
            json_decode((string) $toast->attr('data-toast-messages-value'), true),
        );

        // Flashes are one-time: gone on the next render.
        $client->request('GET', '/editor/single');
        $crawler = $client->followRedirect();
        self::assertSame(
            [],
            json_decode((string) $crawler->filter('div[data-controller="toast"]')->attr('data-toast-messages-value'), true),
        );
    }

    public function testEditorUsesTheDefaultModeWhenTheSessionHasNone(): void
    {
        $client = static::createClient();

        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setDefaultMode(EditorMode::Dir);
        static::getContainer()->get('doctrine.orm.entity_manager')->flush();

        $crawler = $client->request('GET', '/editor');

        self::assertSame('dir', $crawler->filter('nav[data-controller="mode-switch"]')->attr('data-mode-switch-mode-value'));
    }

    public function testEditorPrefersTheModeRememberedInSessionOverTheDefault(): void
    {
        $client = static::createClient();

        // The default stays Single; recording dir mode once should still win.
        $client->request('GET', '/editor/dir');
        $client->followRedirect();

        $crawler = $client->request('GET', '/editor');

        self::assertSame('dir', $crawler->filter('nav[data-controller="mode-switch"]')->attr('data-mode-switch-mode-value'));
    }

    public function testSetModeRecordsTheModeAndReturnsItsRememberedFile(): void
    {
        $client = static::createClient();

        $file = sys_get_temp_dir() . '/mode_switch_' . uniqid() . '.md';
        file_put_contents($file, '# Remembered');

        // Open the file in single mode so the session associates the two.
        $client->request('GET', '/editor/single');
        $crawler = $client->followRedirect();
        $fileToken = $crawler->filter('div[data-controller="mode-single"]')->attr('data-mode-single-file-csrf-token-value');
        $client->request('POST', '/file/open', ['path' => $file], [], ['HTTP_X_CSRF_TOKEN' => $fileToken]);

        $client->request('GET', '/editor/dir');
        $crawler = $client->followRedirect();
        $token = $crawler->filter('nav[data-controller="mode-switch"]')->attr('data-mode-switch-csrf-token-value');

        $client->request('POST', '/editor/mode', ['mode' => 'single'], [], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['mode' => 'single', 'path' => $file, 'content' => '# Remembered'],
            json_decode((string) $client->getResponse()->getContent(), true),
        );

        unlink($file);
    }

    public function testSetModeRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/editor/mode', ['mode' => 'single'], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSetModeRejectsAnUnknownMode(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/dir');
        $crawler = $client->followRedirect();
        $token = $crawler->filter('nav[data-controller="mode-switch"]')->attr('data-mode-switch-csrf-token-value');

        $client->request('POST', '/editor/mode', ['mode' => 'nope'], [], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testDirModeRendersTheShellWithTheEditorAndTheModeDirFrame(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/dir');
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        self::assertSame(1, $crawler->filter('div[data-controller="editor"]')->count());
        self::assertSame('Folder', trim($crawler->filter('.mode-selector-link.is-active')->text()));
        // Both columns are in the page; the dir one arrives through its frame.
        self::assertSame(1, $crawler->filter('div[data-controller="mode-single"]')->count());
        self::assertSame(1, $crawler->filter('div[data-controller="mode-dir"]')->count());
        // Only the tree is behind a frame; Open folder stays in the column.
        self::assertSame('/dir/tree', $crawler->filter('turbo-frame#mode-dir-tree')->attr('src'));
    }

    public function testDirTreeFrameIsEmptyWithNoDirectoryOpen(): void
    {
        $client = static::createClient();

        $client->request('GET', '/dir/tree');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        self::assertSame(1, $crawler->filter('turbo-frame#mode-dir-tree')->count());
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());
    }

    /**
     * Same call the sidebar makes: a fetch carrying the 'dir' token in the
     * header, read from the rendered column — CSRF token storage needs an
     * active session, so it can't be generated outside a request.
     */
    private function setCurrentDirectory(KernelBrowser $client, string $root): void
    {
        $client->request('GET', '/editor/dir');
        $crawler = $client->followRedirect();
        $token = $crawler->filter('div[data-controller="current-directory"]')->attr('data-current-directory-csrf-token-value');

        $client->request('POST', '/dir/current', ['path' => $root], [], ['HTTP_X_CSRF_TOKEN' => $token]);
    }

    public function testDirModeRendersTreeFilteredToMarkdownFilesOnly(): void
    {
        $client = static::createClient();

        $root = sys_get_temp_dir() . '/dir_mode_' . uniqid();
        mkdir($root);
        mkdir($root . '/notes');
        mkdir($root . '/.hidden');
        mkdir($root . '/assets_only');
        file_put_contents($root . '/readme.md', '# Readme');
        file_put_contents($root . '/readme.txt', 'not markdown');
        file_put_contents($root . '/notes/note.md', '# Note');
        file_put_contents($root . '/.hidden/secret.md', '# Secret');
        file_put_contents($root . '/assets_only/logo.png', 'not markdown');

        $this->setCurrentDirectory($client, $root);

        self::assertResponseIsSuccessful();
        self::assertSame($root, json_decode((string) $client->getResponse()->getContent(), true)['open_directory']);

        $crawler = $client->request('GET', '/dir/tree');
        $tree = $crawler->filter('.mode-tree');
        self::assertSame(1, $tree->count());

        self::assertSame(1, $tree->filter('a[data-path="' . $root . '/readme.md"]')->count());
        self::assertSame(0, $tree->filter('a[data-path="' . $root . '/readme.txt"]')->count());

        // Hidden directory with a .md file is included.
        self::assertSame(1, $tree->filter('a[data-path="' . $root . '/.hidden/secret.md"]')->count());

        // A directory with no .md anywhere doesn't appear; one that does, does.
        $dirNames = $tree->filter('.mode-tree-dir-name')->each(static fn ($node) => trim($node->text()));
        self::assertNotContains('assets_only', $dirNames);
        self::assertContains('notes', $dirNames);

        $this->removeDirectory($root);
    }

    public function testDirTreeRouteReflectsFilesAddedAfterTheDirectoryWasOpened(): void
    {
        $client = static::createClient();

        $root = sys_get_temp_dir() . '/dir_mode_refresh_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/first.md', '# First');

        $this->setCurrentDirectory($client, $root);

        // Simulates a Save as into the open directory landing on disk after the page rendered.
        file_put_contents($root . '/second.md', '# Second');

        $client->request('GET', '/dir/tree');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertSame(1, $crawler->filter('a[data-path="' . $root . '/second.md"]')->count());

        $this->removeDirectory($root);
    }

    public function testDirModeShowsErrorWhenTraversalCapIsExceeded(): void
    {
        $client = static::createClient();
        // Keep the container across requests, and override before the
        // service is first resolved (a Twig component render can't replace
        // an already-initialized service).
        $client->disableReboot();
        static::getContainer()->set(DirectoryTree::class, new DirectoryTree(maxItems: 1));

        $root = sys_get_temp_dir() . '/dir_mode_cap_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.md', 'a');
        file_put_contents($root . '/b.md', 'b');
        file_put_contents($root . '/c.md', 'c');

        $this->setCurrentDirectory($client, $root);

        $crawler = $client->request('GET', '/dir/tree');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.mode-tree-error')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());

        $this->removeDirectory($root);
    }

    public function testDirSetCurrentRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $root = sys_get_temp_dir() . '/dir_mode_badcsrf_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.md', 'a');

        $client->request('POST', '/dir/current', ['path' => $root], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/dir/tree');
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());

        $this->removeDirectory($root);
    }

    public function testDirSetCurrentRejectsAPathThatIsNotADirectory(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/dir');
        $crawler = $client->followRedirect();
        $token = $crawler->filter('div[data-controller="current-directory"]')->attr('data-current-directory-csrf-token-value');

        $client->request('POST', '/dir/current', ['path' => '/nope/nope'], [], ['HTTP_X_CSRF_TOKEN' => $token]);

        self::assertResponseStatusCodeSame(404);
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $itemPath = $path . '/' . $item;
            is_dir($itemPath) ? $this->removeDirectory($itemPath) : unlink($itemPath);
        }
        rmdir($path);
    }
}
