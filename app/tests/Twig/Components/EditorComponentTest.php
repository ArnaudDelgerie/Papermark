<?php

namespace App\Tests\Twig\Components;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Twig\Environment;

final class EditorComponentTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get(Environment::class);
    }

    public function testNumericHeightGetsPxSuffix(): void
    {
        $html = $this->twig->createTemplate("{{ component('editor', { height: 400 }) }}")->render([]);

        self::assertStringContainsString('height: 400px;', $html);
        self::assertStringNotContainsString('data-editor-readonly-value', $html);
        // Edit is the toggle label when readonly is false.
        self::assertStringContainsString('Read only', $html);
    }

    public function testUnitHeightIsPassedThrough(): void
    {
        $html = $this->twig->createTemplate("{{ component('editor', { height: '60vh' }) }}")->render([]);

        self::assertStringContainsString('height: 60vh;', $html);
    }

    public function testReadonlySetsDataAttributeAndToggleLabel(): void
    {
        $html = $this->twig->createTemplate("{{ component('editor', { readonly: true }) }}")->render([]);

        self::assertStringContainsString('data-editor-readonly-value="true"', $html);

        $crawler = new Crawler($html);
        $toggle = $crawler->filter('button[data-editor-target="toggleButton"]');
        self::assertSame(1, $toggle->count());
        // Edit is shown when currently readonly.
        self::assertSame('Edit', trim($toggle->text()));
    }
}
