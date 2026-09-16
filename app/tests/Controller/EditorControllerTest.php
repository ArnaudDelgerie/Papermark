<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\File\DirectoryTree;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class EditorControllerTest extends WebTestCase
{
    public function testSingleModeRendersEditorAndModeSingleSidebar(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/single');

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

    public function testDirModeRendersEditorAndModeDirSidebarWithNoDirectoryOpen(): void
    {
        $client = static::createClient();

        $client->request('GET', '/editor/dir');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();

        self::assertSame(1, $crawler->filter('div[data-controller="editor"]')->count());
        self::assertSame(1, $crawler->filter('div[data-controller="mode-dir"]')->count());
        self::assertSame('Folder', trim($crawler->filter('.mode-selector-link.is-active')->text()));
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());
    }

    /**
     * Submits the sidebar's own (hidden, JS-submitted) form, token included —
     * same as a real browser, and avoids CSRF token storage needing an active
     * session outside the request/response cycle.
     */
    private function openDirectory(KernelBrowser $client, string $root): void
    {
        $crawler = $client->request('GET', '/editor/dir');
        $form = $crawler->filter('form')->form(['path' => $root]);
        $client->submit($form);
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

        $this->openDirectory($client, $root);

        self::assertResponseRedirects('/editor/dir');
        $client->followRedirect();

        $crawler = $client->getCrawler();
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

        $this->openDirectory($client, $root);
        $client->followRedirect();

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

        $this->openDirectory($client, $root);
        $client->followRedirect();

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertSame(1, $crawler->filter('.mode-tree-error')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());

        $this->removeDirectory($root);
    }

    public function testDirOpenSilentlyIgnoresInvalidCsrf(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/editor/dir');

        $root = sys_get_temp_dir() . '/dir_mode_badcsrf_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.md', 'a');

        $form = $crawler->filter('form')->form(['path' => $root]);
        $form['_token'] = 'invalid';
        $client->submit($form);

        self::assertResponseRedirects('/editor/dir');
        $client->followRedirect();

        self::assertSame(1, $client->getCrawler()->filter('.mode-tree-empty')->count());

        $this->removeDirectory($root);
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
