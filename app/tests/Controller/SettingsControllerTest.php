<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Provider;
use App\Enum\Ai\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\EditorMode;
use App\Enum\Setting\ThemeMode;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private InMemorySecretStore $secretStore;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the kernel between requests so the keyring double survives.
        $this->client->disableReboot();

        $this->secretStore = new InMemorySecretStore(['anthropic' => 'existing-key']);
        static::getContainer()->set(SecretStoreInterface::class, $this->secretStore);

        // The AI needs a worker on top of a provider and its key.
        $stationContext = $this->createStub(StationContextInterface::class);
        $stationContext->method('isAsyncWorker')->willReturn(true);
        static::getContainer()->set(StationContextInterface::class, $stationContext);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (ProviderName::cases() as $name) {
            $entityManager->persist(new Provider($name));
        }
        $entityManager->flush();
    }

    public function testFrameContentHasOneBlockPerProviderAndNoPageAround(): void
    {
        $crawler = $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        // The content of the modal's frame, not a page — and no `src` on it,
        // or Turbo would see a frame that references itself.
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
        self::assertSelectorExists('turbo-frame#settings');
        self::assertNull($crawler->filter('turbo-frame#settings')->attr('src'));
        self::assertSelectorNotExists('h1');
        self::assertSelectorNotExists('a.settings-back');
        // A Turbo frame would otherwise capture the form's submit.
        self::assertSame('false', $crawler->filter('form.settings-form')->attr('data-turbo'));

        // One fieldset per provider, plus one each for default mode and updates.
        self::assertSelectorCount(5, 'fieldset.settings-fieldset');
        self::assertSelectorCount(3, '.settings-provider');
        self::assertSelectorCount(3, 'input[type="radio"][name="settings[selected]"]');

        // No buttons to save: every field saves by itself. Nor theme or language here.
        self::assertSelectorNotExists('button[type="submit"]');
        self::assertSelectorNotExists('input[name="settings[themeMode]"]');
        self::assertSelectorNotExists('input[name="settings[locale]"]');

        // Stateless token, checked by the request's origin: no double-submit on the form.
        self::assertSelectorExists('input[name="settings[_token]"]');
        self::assertSelectorNotExists('input[name="settings[_token]"][data-controller="csrf-protection"]');

        // Single is the default mode.
        self::assertSelectorCount(2, 'input[type="radio"][name="settings[defaultMode]"]');
        self::assertSelectorExists('input[type="radio"][name="settings[defaultMode]"][value="single"][checked]');
    }

    public function testKeyBlocksHaveBothDisplaysAndTheServerChoosesTheVisibleOne(): void
    {
        $crawler = $this->client->request('GET', '/settings');

        self::assertSelectorCount(3, '.settings-key');
        self::assertSelectorCount(3, '.settings-key-set');
        self::assertSelectorCount(3, '.settings-key-new');

        $anthropic = $crawler->filter('.settings-key[data-name="anthropic"]');
        self::assertNull($anthropic->filter('.settings-key-set')->attr('hidden'));
        self::assertNotNull($anthropic->filter('.settings-key-new')->attr('hidden'));
        self::assertSame('Key set', trim($anthropic->filter('.settings-key-status')->text()));

        $mistral = $crawler->filter('.settings-key[data-name="mistral"]');
        self::assertNotNull($mistral->filter('.settings-key-set')->attr('hidden'));
        self::assertNull($mistral->filter('.settings-key-new')->attr('hidden'));

        // The key field is not part of the form: no name, so no submitted value.
        self::assertSelectorNotExists('input[type="password"][name]');
    }

    public function testFrameIsTranslatedWithTheStoredLocale(): void
    {
        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();
        $setting->setLocale(AppLocale::Fr);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->client->request('GET', '/settings');

        self::assertSelectorTextContains('.settings-tab', 'Fournisseurs IA');
    }

    public function testSaveStoresSelectionModelsAndDefaultModeAndAnswersTheState(): void
    {
        $body = $this->save([
            'selected' => 'anthropic',
            'defaultMode' => 'dir',
            'providers' => [
                'mistral' => ['model' => 'mistral-large-latest'],
                'anthropic' => ['model' => 'claude-sonnet-4-5'],
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $body['action']);
        self::assertSame(['mode', 'file', 'dir', 'ai_enabled'], array_keys($body['state']));

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $providers = static::getContainer()->get(ProviderRepository::class)->findAllByName();
        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();

        self::assertSame('anthropic', $setting->getSelectedProvider()?->getName()->value);
        self::assertSame(EditorMode::Dir, $setting->getDefaultMode());
        self::assertSame('mistral-large-latest', $providers['mistral']->getModel());
        self::assertSame('claude-sonnet-4-5', $providers['anthropic']->getModel());
        self::assertNull($providers['openai']->getModel());
    }

    public function testSaveNeverTouchesTheKeyring(): void
    {
        $this->save(['providers' => ['mistral' => ['model' => 'mistral-large-latest']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('existing-key', $this->secretStore->get('anthropic'));
        self::assertNull($this->secretStore->get('mistral'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function realModelNames(): iterable
    {
        yield 'dashes and digits' => ['claude-opus-5'];
        yield 'colon and dot' => ['llama3.1:8b'];
        yield 'slash' => ['models/gemini-2.5-pro'];
    }

    #[DataProvider('realModelNames')]
    public function testSaveAcceptsRealModelNames(string $model): void
    {
        $this->save(['providers' => ['anthropic' => ['model' => $model]]]);

        self::assertResponseIsSuccessful();
    }

    public function testInvalidFormAnswers422WithErrorsAndNoErrorAndStoresNothing(): void
    {
        $body = $this->save(['providers' => ['anthropic' => ['model' => 'not a model!']]]);

        self::assertResponseStatusCodeSame(422);
        // No `error`: the client shows the errors itself, the master shows no toast.
        self::assertArrayNotHasKey('error', $body);
        self::assertArrayHasKey('state', $body);
        self::assertCount(1, $body['action']['errors']);
        self::assertSame('settings[providers][anthropic][model]', $body['action']['errors'][0]['field']);
        // The message names the field.
        self::assertStringContainsString('Anthropic', $body['action']['errors'][0]['message']);
        self::assertStringContainsString('Model', $body['action']['errors'][0]['message']);
        self::assertStringContainsString('characters that are not allowed', $body['action']['errors'][0]['message']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(ProviderRepository::class)->findAllByName()['anthropic']->getModel());
    }

    public function testModelLongerThanItsColumnIsRefused(): void
    {
        $body = $this->save(['providers' => ['anthropic' => ['model' => str_repeat('a', 256)]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('too long', $body['action']['errors'][0]['message']);
    }

    public function testSaveWithoutTheFormIsRefused(): void
    {
        $this->client->request('POST', '/settings', [], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(400);
    }

    public function testSaveRecomputesAiEnabled(): void
    {
        $body = $this->save(['selected' => 'anthropic']);
        self::assertTrue($body['state']['ai_enabled']);

        // The provider has no key: still off.
        $body = $this->save(['selected' => 'mistral']);
        self::assertFalse($body['state']['ai_enabled']);

        $body = $this->save([]);
        self::assertFalse($body['state']['ai_enabled']);
    }

    public function testChangingTheDefaultModeDoesNotSwitchTheModeShown(): void
    {
        // The session has no mode yet: it follows the default, single.
        $this->client->request('GET', '/editor');

        $body = $this->save(['defaultMode' => 'dir']);

        self::assertSame('single', $body['state']['mode']);
        $this->client->request('GET', '/editor/state');
        self::assertSame('single', json_decode((string) $this->client->getResponse()->getContent(), true)['state']['mode']);
    }

    public function testSetKeyStoresItInTheKeyringAndTurnsTheAiOn(): void
    {
        $this->save(['selected' => 'mistral']);
        self::assertFalse($this->editorAiEnabled());

        $this->client->request('POST', '/settings/provider/mistral/key', ['key' => ' mistral-key '], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['name' => 'mistral'], $body['action']);
        self::assertTrue($body['state']['ai_enabled']);
        self::assertSame('mistral-key', $this->secretStore->get('mistral'));
    }

    public function testSetKeyRefusesAnEmptyKeyAndAnInvalidToken(): void
    {
        $this->client->request('POST', '/settings/provider/mistral/key', ['key' => '  '], [], $this->tokenHeader());
        self::assertResponseStatusCodeSame(400);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('error', $body);
        self::assertArrayHasKey('state', $body);

        $this->client->request('POST', '/settings/provider/mistral/key', ['key' => 'k'], [], ['HTTP_X-CSRF-TOKEN' => 'invalid', 'HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->secretStore->get('mistral'));
    }

    public function testDeleteKeyRemovesItDeselectsTheProviderAndTurnsTheAiOff(): void
    {
        $this->save(['selected' => 'anthropic']);
        self::assertTrue($this->editorAiEnabled());

        $this->client->request('DELETE', '/settings/provider/anthropic/key', [], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['name' => 'anthropic'], $body['action']);
        self::assertFalse($body['state']['ai_enabled']);
        self::assertNull($this->secretStore->get('anthropic'));

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(SettingRepository::class)->getOrCreate()->getSelectedProvider());
    }

    public function testDeleteKeyKeepsSelectionWhenADifferentProviderIsSelected(): void
    {
        $this->save(['selected' => 'mistral']);

        $this->client->request('DELETE', '/settings/provider/anthropic/key', [], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();
        self::assertSame('mistral', $setting->getSelectedProvider()?->getName()->value);
    }

    public function testDeleteKeyRejectsInvalidCsrf(): void
    {
        $this->client->request('DELETE', '/settings/provider/anthropic/key', [], [], ['HTTP_X-CSRF-TOKEN' => 'invalid', 'HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('existing-key', $this->secretStore->get('anthropic'));
    }

    public function testThemeIsStored(): void
    {
        $this->client->request('POST', '/settings/theme', ['theme' => 'light'], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['theme' => 'light'], json_decode((string) $this->client->getResponse()->getContent(), true));

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(ThemeMode::Light, static::getContainer()->get(SettingRepository::class)->getOrCreate()->getThemeMode());
    }

    public function testThemeRefusesAnUnknownValueAndAnInvalidToken(): void
    {
        $this->client->request('POST', '/settings/theme', ['theme' => 'pink'], [], $this->tokenHeader());
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/settings/theme', ['theme' => 'light'], [], ['HTTP_X-CSRF-TOKEN' => 'invalid', 'HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testLocaleIsStoredThenRedirectsHome(): void
    {
        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => $this->token()], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(AppLocale::Fr, static::getContainer()->get(SettingRepository::class)->getOrCreate()->getLocale());
    }

    public function testLocaleRefusesAnUnknownValueAndAnInvalidToken(): void
    {
        $this->client->request('POST', '/settings/locale', ['locale' => 'xx', '_token' => $this->token()], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(400);

        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => 'invalid'], [], ['HTTP_ORIGIN' => 'http://localhost']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AppLocale::En, static::getContainer()->get(SettingRepository::class)->getOrCreate()->getLocale());
    }

    /**
     * Posts the settings form the way the client does: the rendered form's
     * token, plus the given fields.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function save(array $fields): array
    {
        $crawler = $this->client->request('GET', '/settings');
        $fields['_token'] = $crawler->filter('input[name="settings[_token]"]')->attr('value');
        $fields += ['defaultMode' => 'single'];

        $this->client->request('POST', '/settings', ['settings' => $fields], [], ['HTTP_ORIGIN' => 'http://localhost']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function editorAiEnabled(): bool
    {
        $this->client->request('GET', '/editor/state');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['state']['ai_enabled'];
    }

    private function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('settings')->getValue();
    }

    /**
     * @return array<string, string>
     */
    private function tokenHeader(): array
    {
        // The browser always sends the origin of a fetch; the token alone is not enough.
        return ['HTTP_X-CSRF-TOKEN' => $this->token(), 'HTTP_ORIGIN' => 'http://localhost'];
    }
}
