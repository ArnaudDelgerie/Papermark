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

        // Both CSRF tokens are present.
        self::assertNotEmpty($editor->attr('data-editor-csrf-token-value'));
        self::assertNotEmpty($editor->attr('data-editor-file-csrf-token-value'));

        // The file bar buttons are present.
        self::assertSame(6, $editor->filter('button.editor-filebar-btn')->count());
        self::assertSame(
            'Save as',
            $editor->filter('button[data-editor-target="saveAsButton"]')->text()
        );
    }

}
