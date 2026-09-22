<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Enum\Setting\EditorMode;
use App\Service\Directory\DirectoryTree;
use App\Repository\SettingRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class EditorControllerTest extends WebTestCase
{
    /** CSRF token, read from the rendered page */
    private string $token;

    public function testEditorRendersTheShellWithBothColumns(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/editor');

        self::assertResponseIsSuccessful();

        self::assertSame(1, $crawler->filter('div[data-controller="editor"]')->count());
        self::assertSame('Single file', trim($crawler->filter('.mode-selector-link.is-active')->text()));

        // The /file/image gate depends on it (lot 02, SEC-04): written out,
        // not left to the framework's default.
        $options = static::getContainer()->getParameter('session.storage.options');
        self::assertSame('lax', $options['cookie_samesite'] ?? null);

        // Both columns are in the page, the inactive one hidden.
        self::assertNull($crawler->filter('div[data-controller="mode-single"]')->attr('hidden'));
        self::assertNotNull($crawler->filter('div[data-controller="mode-dir"]')->attr('hidden'));

        // New replaces the old Open button in the editor's own file bar.
        // The file bar's buttons: file, content out, display, archive, then theme, language and settings.
        self::assertSame(10, $crawler->filter('div[data-controller="editor"] button.editor-filebar-btn')->count());
        self::assertSame('New', trim($crawler->filter('button[data-action="click->editor#newFile"]')->text()));

        // Open (file) lives in the sidebar.
        self::assertSame('Open', trim($crawler->filter('button[data-action="click->mode-single#openFile"]')->text()));

        // Only the tree is behind a frame; Open folder stays in the column.
        self::assertSame('/editor/dir', $crawler->filter('turbo-frame#mode-dir-tree')->attr('src'));
    }

    public function testEditorEmbedsNoFileEvenWhenOneIsCurrent(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Hello');
        $this->post($client, '/editor/file', ['path' => $path]);

        // The editor fetches the current file itself, through GET /document.
        $crawler = $client->request('GET', '/editor');

        $editor = $crawler->filter('div[data-controller="editor"]');
        self::assertStringNotContainsString('# Hello', $editor->outerHtml());
        self::assertSame('/document', json_decode((string) $editor->attr('data-editor-urls-value'), true)['file']);

        unlink($path);
    }

    public function testEditorHydratesTheClientStateWithReadonlyAndAiEnabled(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Hello');
        $this->post($client, '/editor/file', ['path' => $path]);

        $crawler = $client->request('GET', '/editor');

        $master = $crawler->filter('div[data-controller="editor-state"]');
        self::assertSame(
            ['mode' => 'single', 'file' => realpath($path), 'dir' => null, 'ai_enabled' => false, 'readonly' => false],
            json_decode((string) $master->attr('data-editor-state-state-value'), true),
        );
        $urls = json_decode((string) $master->attr('data-editor-state-urls-value'), true);
        self::assertSame('/editor/state', $urls['state']);
        // The settings form carries its own `action`; the master no longer holds it (lot 04).
        self::assertArrayNotHasKey('settings', $urls);
        self::assertSame('/settings/provider/__name__/key', $urls['setKey']);
        self::assertSame('/settings/provider/__name__/key', $urls['deleteKey']);
        self::assertNotEmpty($master->attr('data-editor-state-token-value'));
        // The editor and both columns are inside the master's element.
        self::assertSame(1, $master->filter('div[data-controller="editor"]')->count());
        self::assertSame(1, $master->filter('div[data-controller="mode-dir"]')->count());

        unlink($path);
    }

    public function testGetStateReturnsTheSessionState(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Hello');
        $this->post($client, '/editor/file', ['path' => $path]);

        $client->request('GET', '/editor/state');

        self::assertResponseIsSuccessful();
        self::assertSame(['mode' => 'single', 'file' => realpath($path), 'dir' => null, 'ai_enabled' => false], $this->responseState($client));

        unlink($path);
    }

    public function testFlashMessagesAreEmbeddedAsInitialToastValues(): void
    {
        $client = static::createClient();
        $client->disableReboot();

        $client->request('GET', '/editor');
        $session = $client->getRequest()->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            self::fail('Session does not support flash messages.');
        }
        $session->getFlashBag()->add('success', 'Test message');
        $session->save();

        $crawler = $client->request('GET', '/editor');

        $toast = $crawler->filter('div[data-controller="toast"]');
        self::assertSame(
            [['type' => 'success', 'message' => 'Test message']],
            json_decode((string) $toast->attr('data-toast-messages-value'), true),
        );

        // Flashes are one-time: gone on the next render.
        $crawler = $client->request('GET', '/editor');
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

        self::assertSame('dir', json_decode((string) $crawler->filter('div[data-controller="editor-state"]')->attr('data-editor-state-state-value'), true)['mode']);
        self::assertNull($crawler->filter('div[data-controller="mode-dir"]')->attr('hidden'));
    }

    public function testEditorPrefersTheModeRememberedInSessionOverTheDefault(): void
    {
        $client = $this->createClientWithTokens();

        // The default stays Single; recording dir mode once should still win.
        $this->post($client, '/editor/mode', ['mode' => 'dir']);

        $crawler = $client->request('GET', '/editor');

        self::assertSame('dir', json_decode((string) $crawler->filter('div[data-controller="editor-state"]')->attr('data-editor-state-state-value'), true)['mode']);
    }

    public function testSetModeReturnsTheStateWithoutTheCurrentFile(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Dropped');
        $this->post($client, '/editor/file', ['path' => $path]);

        $this->post($client, '/editor/mode', ['mode' => 'dir']);

        self::assertResponseIsSuccessful();
        self::assertSame(['mode' => 'dir', 'file' => null, 'dir' => null, 'ai_enabled' => false], $this->responseState($client));
        self::assertSame(['mode' => 'dir'], $this->responseData($client)['action']);

        // Switching back doesn't bring the file back: one file, not one per mode.
        $this->post($client, '/editor/mode', ['mode' => 'single']);
        self::assertNull($this->responseState($client)['file']);

        unlink($path);
    }

    public function testSetModeRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/editor/mode', ['mode' => 'single'], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSetModeRejectsAnUnknownMode(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/mode', ['mode' => 'nope']);

        self::assertResponseStatusCodeSame(422);
        // A refusal still carries the state (S6).
        self::assertSame('single', $this->responseState($client)['mode']);
        self::assertSame('mode', $this->responseData($client)['mappedErrors'][0]['field']);
    }

    public function testSetFileMakesTheFileCurrentAndGetFileReadsIt(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Hello');
        $this->post($client, '/editor/file', ['path' => $path]);

        self::assertResponseIsSuccessful();
        // No content in the answer: the editor fetches it on the update.
        self::assertSame(
            ['state' => ['mode' => 'single', 'file' => $path, 'dir' => null, 'ai_enabled' => false], 'action' => ['path' => $path]],
            $this->responseData($client),
        );

        $client->request('GET', '/document');
        self::assertSame(
            ['path' => $path, 'content' => '# Hello', 'revision' => hash('xxh128', '# Hello')],
            $this->responseData($client),
        );

        unlink($path);
    }

    /**
     * FIL-09 (lot 03): a non-UTF-8 file is refused, not converted — and the
     * state doesn't change, so the file shown before keeps opening after a
     * reload instead of falling into a 500 loop.
     */
    public function testSetFileRefusesANonUtf8FileAndKeepsTheState(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Hello');
        $this->post($client, '/editor/file', ['path' => $path]);

        // Windows-1252 "é" is not UTF-8.
        $bad = sys_get_temp_dir() . '/editor_state_' . uniqid() . '.md';
        file_put_contents($bad, "Coucou \xE9\xE8.txt");

        $this->post($client, '/editor/file', ['path' => $bad]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['The file is not UTF-8 encoded; only UTF-8 files can be opened'], $this->responseData($client)['genericErrors']);
        self::assertSame($path, $this->responseState($client)['file']);

        unlink($path);
        unlink($bad);
    }

    /** A UTF-8 BOM is valid UTF-8: these files pass (lot 03). */
    public function testSetAndGetFileAcceptAFileWithABom(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile("\xEF\xBB\xBF# With BOM");
        $this->post($client, '/editor/file', ['path' => $path]);

        self::assertResponseIsSuccessful();
        $client->request('GET', '/document');
        $data = $this->responseData($client);
        self::assertSame("\xEF\xBB\xBF# With BOM", $data['content']);
        self::assertSame(hash('xxh128', "\xEF\xBB\xBF# With BOM"), $data['revision']);

        unlink($path);
    }

    /**
     * Reading a linked .md is a normal use; it opens as its target (lot 02,
     * "Liens symboliques : lecture oui").
     */
    public function testSetFileOpensASymlinkedFileByItsTarget(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux' && \PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('Symbolic links are a Unix matter here.');
        }

        $client = $this->createClientWithTokens();

        $target = $this->createFile('# Target');
        $link = sys_get_temp_dir() . '/editor_state_link_' . uniqid() . '.md';
        symlink($target, $link);

        $this->post($client, '/editor/file', ['path' => $link]);

        self::assertResponseIsSuccessful();
        self::assertSame(realpath($target), $this->responseState($client)['file']);

        $client->request('GET', '/document');
        self::assertSame('# Target', $this->responseData($client)['content']);

        unlink($link);
        unlink($target);
    }

    public function testSetFileReturnsTranslatedErrorForMissingFile(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/file', ['path' => '/tmp/this_file_does_not_exist_12345.md']);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Path not found'], $this->responseData($client)['genericErrors']);
    }

    public function testSetFileReturnsTranslatedErrorForUnsupportedType(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/file', ['path' => '/tmp/this_file_does_not_exist.exe']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'path', 'message' => 'Only Markdown and text files are supported']],
            $this->responseData($client)['mappedErrors'],
        );
    }

    public function testSetFileReturnsMappedErrorForNoPath(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/file', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(
            [['field' => 'path', 'message' => 'No file path provided']],
            $this->responseData($client)['mappedErrors'],
        );
    }

    public function testSetFileRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/editor/file', ['path' => '/tmp/test.md'], [], ['HTTP_X-CSRF-TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['Invalid security token, please reload the page'], $this->responseData($client)['genericErrors']);
    }

    public function testSetFileClearsTheCurrentFileOnlyWhenItIsTheOneFound(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Kept');
        $this->post($client, '/editor/file', ['path' => $path]);

        // An unrelated bad path leaves the current file alone…
        $this->post($client, '/editor/file', ['path' => '/tmp/this_file_does_not_exist_12345.md']);
        $client->request('GET', '/document');
        self::assertSame($path, $this->responseData($client)['path']);

        // …the current file found gone is dropped.
        unlink($path);
        $this->post($client, '/editor/file', ['path' => $path]);
        self::assertResponseStatusCodeSame(404);
        // The refusal carries the state as corrected.
        self::assertNull($this->responseState($client)['file']);
        $client->request('GET', '/document');
        self::assertNull($this->responseData($client)['path']);
    }

    public function testClearFileLeavesNoCurrentFile(): void
    {
        $client = $this->createClientWithTokens();

        $path = $this->createFile('# Previous');
        $this->post($client, '/editor/file', ['path' => $path]);

        // New: a reload must not bring the previous file back.
        $client->request('DELETE', '/editor/file', [], [], ['HTTP_X_CSRF_TOKEN' => $this->token]);

        self::assertResponseIsSuccessful();
        self::assertSame(['mode' => 'single', 'file' => null, 'dir' => null, 'ai_enabled' => false], $this->responseState($client));
        // An empty action is still an object, like every other one.
        self::assertStringContainsString('"action":{}', (string) $client->getResponse()->getContent());

        $client->request('GET', '/document');
        self::assertSame(['path' => null, 'content' => null], $this->responseData($client));

        unlink($path);
    }

    public function testClearFileRejectsInvalidCsrf(): void
    {
        $client = static::createClient();

        $client->request('DELETE', '/editor/file', [], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testSetDirReturnsTheStateWithoutTheCurrentFile(): void
    {
        $client = $this->createClientWithTokens();

        $root = sys_get_temp_dir() . '/dir_mode_forget_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/kept.md', '# Kept');

        $this->post($client, '/editor/file', ['path' => $root . '/kept.md']);
        $this->post($client, '/editor/dir', ['path' => $root]);

        self::assertResponseIsSuccessful();
        self::assertSame(['mode' => 'single', 'file' => null, 'dir' => $root, 'ai_enabled' => false], $this->responseState($client));
        self::assertSame(['path' => $root], $this->responseData($client)['action']);

        $this->removeDirectory($root);
    }

    public function testSetDirRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $root = sys_get_temp_dir() . '/dir_mode_badcsrf_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/a.md', 'a');

        $client->request('POST', '/editor/dir', ['path' => $root], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);

        $crawler = $client->request('GET', '/editor/dir');
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());

        $this->removeDirectory($root);
    }

    public function testSetDirRejectsAPathThatIsNotADirectory(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/dir', ['path' => '/nope/nope']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testSetDirReturnsMappedErrorForABlankPath(): void
    {
        $client = $this->createClientWithTokens();

        $this->post($client, '/editor/dir', ['path' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('path', $this->responseData($client)['mappedErrors'][0]['field']);
    }

    /**
     * A folder deleted from outside: the tree says it disappeared — not
     * "no folder open" — and the state, session included, forgets it (lot
     * 02, FIL-10). The current file stays: it may well live elsewhere.
     */
    public function testGoneDirIsDroppedFromTheStateAndReportedByTheTree(): void
    {
        $client = $this->createClientWithTokens();

        $root = sys_get_temp_dir() . '/dir_mode_gone_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/note.md', '# Note');
        $this->post($client, '/editor/dir', ['path' => $root]);
        $outside = $this->createFile('# Outside');
        $this->post($client, '/editor/file', ['path' => $outside]);

        $this->removeDirectory($root);
        $crawler = $client->request('GET', '/editor/dir');

        $gone = $crawler->filter('.mode-tree-gone');
        self::assertSame(1, $gone->count());
        self::assertSame($root, $gone->attr('data-dir-gone'));
        self::assertSame(0, $crawler->filter('.mode-tree-empty')->count());

        $client->request('GET', '/editor/state');
        self::assertNull($this->responseState($client)['dir']);
        // The current file survives the folder's disappearance.
        self::assertSame($outside, $this->responseState($client)['file']);

        // The next render says "no folder open", the anomaly is one-time.
        $crawler = $client->request('GET', '/editor/dir');
        self::assertSame(0, $crawler->filter('.mode-tree-gone')->count());
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());

        unlink($outside);
    }

    public function testGetDirIsEmptyWithNoCurrentFolder(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/editor/dir');

        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('turbo-frame#mode-dir-tree')->count());
        self::assertSame(1, $crawler->filter('.mode-tree-empty')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());
    }

    public function testGetDirRendersTreeFilteredToMarkdownFilesOnly(): void
    {
        $client = $this->createClientWithTokens();

        $root = sys_get_temp_dir() . '/dir_mode_' . uniqid();
        mkdir($root);
        mkdir($root . '/notes');
        mkdir($root . '/.hidden');
        mkdir($root . '/assets_only');
        file_put_contents($root . '/readme.md', '# Readme');
        file_put_contents($root . '/readme.pdf', 'not a document');
        file_put_contents($root . '/notes/note.md', '# Note');
        file_put_contents($root . '/.hidden/secret.md', '# Secret');
        file_put_contents($root . '/assets_only/logo.png', 'not markdown');

        $this->post($client, '/editor/dir', ['path' => $root]);

        $crawler = $client->request('GET', '/editor/dir');
        $tree = $crawler->filter('.mode-tree');
        self::assertSame(1, $tree->count());

        self::assertSame(1, $tree->filter('a[data-path="' . $root . '/readme.md"]')->count());
        self::assertSame(0, $tree->filter('a[data-path="' . $root . '/readme.pdf"]')->count());

        // Hidden directory with a .md file is included.
        self::assertSame(1, $tree->filter('a[data-path="' . $root . '/.hidden/secret.md"]')->count());

        // A directory with no .md anywhere doesn't appear; one that does, does.
        $dirNames = $tree->filter('.mode-tree-dir-name')->each(static fn ($node) => trim($node->text()));
        self::assertNotContains('assets_only', $dirNames);
        self::assertContains('notes', $dirNames);

        $this->removeDirectory($root);
    }

    public function testGetDirKeepsItsWalkUntilTheTreeIsRefreshed(): void
    {
        $client = $this->createClientWithTokens();

        $root = sys_get_temp_dir() . '/dir_mode_refresh_' . uniqid();
        mkdir($root);
        file_put_contents($root . '/first.md', '# First');

        $this->post($client, '/editor/dir', ['path' => $root]);
        $client->request('GET', '/editor/dir');

        // A file written outside the app: the cached walk doesn't know it.
        file_put_contents($root . '/second.md', '# Second');

        $crawler = $client->request('GET', '/editor/dir');
        self::assertSame(0, $crawler->filter('a[data-path="' . $root . '/second.md"]')->count());

        $this->post($client, '/editor/dir/refresh', []);
        self::assertResponseIsSuccessful();
        self::assertSame(['mode' => 'single', 'file' => null, 'dir' => $root, 'ai_enabled' => false], $this->responseState($client));
        self::assertSame([], $this->responseData($client)['action']);

        $crawler = $client->request('GET', '/editor/dir');
        self::assertSame(1, $crawler->filter('a[data-path="' . $root . '/second.md"]')->count());

        $this->removeDirectory($root);
    }

    public function testInAppWritesUpdateTheTreeWithoutARefresh(): void
    {
        $client = $this->createClientWithTokens();

        $root = sys_get_temp_dir() . '/dir_mode_writes_' . uniqid();
        mkdir($root);
        mkdir($root . '/empty_yet');
        mkdir($root . '/only_one');
        file_put_contents($root . '/kept.md', '# Kept');
        file_put_contents($root . '/old.md', '# Old');
        file_put_contents($root . '/only_one/last.md', '# Last');

        $this->post($client, '/editor/dir', ['path' => $root]);
        $client->request('GET', '/editor/dir');

        $this->post($client, '/document/save', ['path' => $root . '/empty_yet/new.md', 'content' => '# New']);
        // .txt is a document too (DocumentExtension): it gets listed like any other.
        $this->post($client, '/document/save', ['path' => $root . '/notes.txt', 'content' => 'a text note']);
        $this->post($client, '/document/delete', ['path' => $root . '/only_one/last.md']);
        $this->post($client, '/document/rename', ['path' => $root . '/old.md', 'name' => 'renamed.md']);

        $crawler = $client->request('GET', '/editor/dir');
        $paths = $crawler->filter('.mode-tree a[data-path]')->each(static fn ($node) => $node->attr('data-path'));
        sort($paths);
        self::assertSame([
            $root . '/empty_yet/new.md',
            $root . '/kept.md',
            $root . '/notes.txt',
            $root . '/renamed.md',
        ], $paths);

        // A folder left without any .md goes, like after a walk.
        $dirNames = $crawler->filter('.mode-tree-dir-name')->each(static fn ($node) => trim($node->text()));
        self::assertSame(['empty_yet'], $dirNames);

        $this->removeDirectory($root);
    }

    public function testRefreshDirRejectsAnInvalidCsrfToken(): void
    {
        $client = static::createClient();

        $client->request('POST', '/editor/dir/refresh', [], [], ['HTTP_X_CSRF_TOKEN' => 'invalid']);

        self::assertResponseStatusCodeSame(403);
        self::assertArrayHasKey('state', $this->responseData($client));
    }

    public function testGetDirShowsErrorWhenTraversalCapIsExceeded(): void
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

        $this->readTokens($client);
        $this->post($client, '/editor/dir', ['path' => $root]);

        $crawler = $client->request('GET', '/editor/dir');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $crawler->filter('.mode-tree-error')->count());
        self::assertSame(0, $crawler->filter('.mode-tree')->count());

        $this->removeDirectory($root);
    }

    private function createClientWithTokens(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->readTokens($client);

        return $client;
    }

    /**
     * Same calls the page makes: a fetch carrying the token in the header,
     * read from the rendered page — CSRF token storage needs an active
     * session, so it can't be generated outside a request.
     */
    private function readTokens(KernelBrowser $client): void
    {
        $crawler = $client->request('GET', '/editor');

        // The master (editor-state) holds them all: it makes every write.
        $this->token = (string) $crawler->filter('div[data-controller="editor-state"]')->attr('data-editor-state-token-value');
    }

    /**
     * @param array<string, string> $fields
     */
    private function post(KernelBrowser $client, string $uri, array $fields): void
    {
        $client->request('POST', $uri, $fields, [], ['HTTP_X_CSRF_TOKEN' => $this->token]);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true);
    }

    /**
     * @return array{mode: string, file: ?string, dir: ?string}
     */
    private function responseState(KernelBrowser $client): array
    {
        return $this->responseData($client)['state'];
    }

    private function createFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/editor_state_' . uniqid() . '.md';
        file_put_contents($path, $content);

        return $path;
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
