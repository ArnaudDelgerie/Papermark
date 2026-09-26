<?php

namespace App\Tests\Twig\Components;

use App\Service\Ai\AiTopicResolver;
use App\Entity\Provider;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\ThemeMode;
use App\Repository\SettingRepository;
use App\Tests\Double\InMemorySecretStore;
use App\Tests\Trait\ContractAssertions;
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
    use ContractAssertions;

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
        $toggle = $crawler->filter('button[data-action="click->editor#toggleReadonly"]');
        self::assertSame(1, $toggle->count());
        // Edit is shown when currently readonly.
        self::assertSame('Edit', trim($toggle->text()));
    }

    public function testI18nMatchesItsContract(): void
    {
        $html = $this->twig()->createTemplate("{{ component('editor') }}")->render([]);
        $editor = (new Crawler($html))->filter('div[data-controller="editor"]');

        $this->assertMatchesContract('i18n/editor', json_decode((string) $editor->attr('data-editor-i18n-value'), true));
    }

    public function testAiConfigAlwaysCarriesTheHubAndTopic(): void
    {
        // Whether the AI is on comes from the client state, not from here: it
        // can change without a reload, so the hub and topic are always given.
        $request = $this->configureAi(selected: null, secrets: []);

        $aiConfig = $this->renderAiConfig();

        self::assertArrayNotHasKey('enabled', $aiConfig);
        self::assertSame('http://localhost/.well-known/mercure', $aiConfig['mercureUrl']);
        // The topic is the session's one; the cookie is minted on demand by /ai/subscribe.
        self::assertSame(self::getContainer()->get(AiTopicResolver::class)->resolve($request), $aiConfig['topic']);
    }

    public function testFileBarGroupsAndAppButtons(): void
    {
        $this->configureAi(selected: null, secrets: []);
        $setting = self::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setThemeMode(ThemeMode::Light);
        $setting->setLocale(AppLocale::Fr);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $html = $this->twig()->createTemplate("{{ component('editor') }}")->render([]);
        $crawler = new Crawler($html);

        // Six groups around the file path, told apart by four dividers.
        self::assertSame(4, $crawler->filter('.editor-file-bar > .editor-filebar-divider')->count());

        $theme = $crawler->filter('button[data-controller="theme-switch"]');
        self::assertSame('light', $theme->attr('data-theme-switch-current-value'));
        self::assertSame(['auto', 'light', 'dark'], json_decode((string) $theme->attr('data-theme-switch-themes-value'), true));
        self::assertSame('/settings/theme', $theme->attr('data-theme-switch-url-value'));
        self::assertSame('Theme: Light', trim($theme->text()));

        // The languages come from the enum, the labels are translated.
        $locale = $crawler->filter('div[data-controller="locale-stepper"]');
        self::assertSame('fr', $locale->attr('data-locale-stepper-current-value'));
        self::assertSame(['en', 'fr'], json_decode((string) $locale->attr('data-locale-stepper-locales-value'), true));
        self::assertSame('FR', trim($locale->filter('[data-locale-stepper-target="code"]')->text()));
        self::assertSame('/settings/locale', $locale->filter('form')->attr('action'));
        // The hidden submit is what the leave guard intercepts.
        self::assertSame(1, $locale->filter('button[type="submit"][data-editor-leave-guard]')->count());

        // Archive, AI history and Settings open a modal instead of leaving: no leave
        // guard on the buttons, and a lazy frame each. They come in this order in the bar.
        $modals = $crawler->filter('[data-controller="modal"]');
        self::assertCount(3, $modals);
        foreach (['archive' => [0, '/archive'], 'ai-history' => [1, '/ai/history'], 'settings' => [2, '/settings']] as $id => [$index, $src]) {
            $modal = $modals->eq($index);
            self::assertSame(1, $modal->filter('button[data-action="click->modal#open"]:not([data-editor-leave-guard])')->count());
            $frame = $modal->filter('dialog turbo-frame#' . $id);
            self::assertSame('lazy', $frame->attr('loading'));
            self::assertSame($src, $frame->attr('src'));
        }
        self::assertSame(0, $crawler->filter('a[data-editor-leave-guard]')->count());
    }

    public function testThemeAndLocaleLabelsComeFromTheEnumsDomain(): void
    {
        // auto and en are the two values where the removed `components`
        // domain used to read differently from `enums`.
        $this->configureAi(selected: null, secrets: []);
        $setting = self::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setThemeMode(ThemeMode::Auto);
        $setting->setLocale(AppLocale::En);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $html = $this->twig()->createTemplate("{{ component('editor') }}")->render([]);
        $crawler = new Crawler($html);

        $theme = $crawler->filter('button[data-controller="theme-switch"]');
        self::assertSame('Theme: Automatic', trim($theme->text()));

        $locale = $crawler->filter('div[data-controller="locale-stepper"]');
        self::assertSame('English', json_decode((string) $locale->attr('data-locale-stepper-labels-value'), true)['en']);
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
        $selectedProvider = null;
        foreach (ProviderName::cases() as $name) {
            $provider = new Provider($name);
            $entityManager->persist($provider);
            if ($name === $selected) {
                $selectedProvider = $provider;
            }
        }

        $setting = self::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setSelectedProvider($selectedProvider);
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
