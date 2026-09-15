<?php

namespace App\Tests\Twig\Components;

use App\Ai\AiTopicResolver;
use App\Ai\ProviderName;
use App\Entity\Provider;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Twig\Environment;

final class EditorComponentTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    // Fetched per render: Twig initializes the services the AI tests replace.
    private function twig(): Environment
    {
        return self::getContainer()->get(Environment::class);
    }

    public function testNumericHeightGetsPxSuffix(): void
    {
        $html = $this->twig()->createTemplate("{{ component('editor', { height: 400 }) }}")->render([]);

        self::assertStringContainsString('height: 400px;', $html);
        self::assertStringNotContainsString('data-editor-readonly-value', $html);
        // Edit is the toggle label when readonly is false.
        self::assertStringContainsString('Read only', $html);
    }

    public function testUnitHeightIsPassedThrough(): void
    {
        $html = $this->twig()->createTemplate("{{ component('editor', { height: '60vh' }) }}")->render([]);

        self::assertStringContainsString('height: 60vh;', $html);
    }

    public function testReadonlySetsDataAttributeAndToggleLabel(): void
    {
        $html = $this->twig()->createTemplate("{{ component('editor', { readonly: true }) }}")->render([]);

        self::assertStringContainsString('data-editor-readonly-value="true"', $html);

        $crawler = new Crawler($html);
        $toggle = $crawler->filter('button[data-editor-target="toggleButton"]');
        self::assertSame(1, $toggle->count());
        // Edit is shown when currently readonly.
        self::assertSame('Edit', trim($toggle->text()));
    }

    public function testAiDisabledWithoutSelectedProvider(): void
    {
        $this->configureAi(selected: null, secrets: ['anthropic' => 'key']);

        self::assertFalse($this->renderAiConfig()['enabled']);
    }

    public function testAiDisabledWithoutKeyForSelectedProvider(): void
    {
        $this->configureAi(selected: ProviderName::Anthropic, secrets: ['openai' => 'key']);

        self::assertFalse($this->renderAiConfig()['enabled']);
    }

    public function testAiEnabledWithSelectedProviderAndKey(): void
    {
        $request = $this->configureAi(selected: ProviderName::Anthropic, secrets: ['anthropic' => 'key']);

        $aiConfig = $this->renderAiConfig();

        self::assertTrue($aiConfig['enabled']);
        self::assertSame('http://localhost/.well-known/mercure', $aiConfig['mercureUrl']);
        // The topic is the session's one; the cookie is minted on demand by /ai/subscribe.
        self::assertSame(self::getContainer()->get(AiTopicResolver::class)->resolve($request), $aiConfig['topic']);
    }

    /**
     * @param array<string, string> $secrets
     */
    private function configureAi(?ProviderName $selected, array $secrets): Request
    {
        // Same host as the Mercure hub.
        $request = Request::create('http://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $stationContext = $this->createStub(StationContextInterface::class);
        $stationContext->method('isAsyncWorker')->willReturn(true);
        self::getContainer()->set(StationContextInterface::class, $stationContext);
        self::getContainer()->set(SecretStoreInterface::class, new InMemorySecretStore($secrets));

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (ProviderName::cases() as $name) {
            $provider = new Provider($name);
            $provider->setSelected($name === $selected);
            $entityManager->persist($provider);
        }
        $entityManager->flush();

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderAiConfig(): array
    {
        $html = $this->twig()->createTemplate("{{ component('editor') }}")->render([]);
        $editor = (new Crawler($html))->filter('div[data-controller="editor"]');

        return json_decode((string) $editor->attr('data-editor-ai-config-value'), true);
    }
}
