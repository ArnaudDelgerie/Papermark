<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testEditorComponentRendersOnHome(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseIsSuccessful();

        $crawler = $client->getCrawler();

        // The Twig Component renders the Stimulus controller root.
        $editor = $crawler->filter('div[data-controller="editor"]');
        self::assertSame(1, $editor->count(), 'the editor component is rendered once');

        // A4 mode and height are applied as static markup.
        self::assertStringContainsString('editor is-a4', $editor->attr('class') ?? '');
        self::assertStringContainsString('height: 100%;', $editor->attr('style') ?? '');

        // The JS i18n object is populated server-side from translations.
        $i18n = json_decode((string) $editor->attr('data-editor-i18n-value'), true);
        self::assertSame('Start writing…', $i18n['placeholder']);
        self::assertSame('Heading 1', $i18n['slashMenu']['h1']);
        self::assertSame('Full width', $i18n['full_width']);
        self::assertSame('File saved', $i18n['toast']['saved']);
        self::assertSame('Markdown copied to clipboard', $i18n['toast']['copiedMarkdown']);

        // Both CSRF tokens are present.
        self::assertNotEmpty($editor->attr('data-editor-csrf-token-value'));
        self::assertNotEmpty($editor->attr('data-editor-file-csrf-token-value'));
        self::assertNotEmpty($editor->attr('data-editor-ai-csrf-token-value'));

        // The AI config is exposed to the front-end, decided server-side.
        $aiConfig = json_decode((string) $editor->attr('data-editor-ai-config-value'), true);
        self::assertSame(['enabled' => false], $aiConfig);

        // The file bar buttons are present.
        self::assertSame(7, $editor->filter('button.editor-filebar-btn')->count());

        // The settings link is present in the file bar.
        $settingsLink = $editor->filter('a.editor-settings-btn');
        self::assertSame(1, $settingsLink->count());
        self::assertSame('Settings', trim($settingsLink->text()));
        self::assertSame(
            'Save as',
            $editor->filter('button[data-editor-target="saveAsButton"]')->text()
        );
        self::assertSame(
            'Copy as Markdown',
            $editor->filter('button[data-editor-target="copyMarkdownButton"]')->text()
        );

        // The toast container is rendered in the base layout.
        self::assertSame(1, $crawler->filter('div[data-controller="toast"]')->count());
    }

}
