<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Provider;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\EditorMode;
use App\Enum\Setting\ThemeMode;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Tests\Double\InMemorySecretStore;
use App\Tests\Double\StationContextDouble;
use App\Tests\Trait\ContractAssertions;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class SettingsControllerTest extends WebTestCase
{
    use ContractAssertions;

    private KernelBrowser $client;
    private InMemorySecretStore $secretStore;
    private StationContextDouble $stationContext;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep the kernel between requests so the keyring double survives.
        $this->client->disableReboot();

        $this->secretStore = new InMemorySecretStore(['anthropic' => 'existing-key']);
        static::getContainer()->set(SecretStoreInterface::class, $this->secretStore);

        // All three hub capabilities present by default (HUB-06, lot 09):
        // no banner leaks into the tests that don't care about them. The
        // probes stay flippable: the container cannot replace an
        // initialized service, but it re-reads the properties each request.
        $this->stationContext = new StationContextDouble();
        static::getContainer()->set(StationContextInterface::class, $this->stationContext);

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
        $this->assertMatchesContract('editor-state', $body['state']);

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

    public function testInvalidFormAnswersItsOwnRefusalFormAndStoresNothing(): void
    {
        $body = $this->save(['providers' => ['anthropic' => ['model' => 'not a model!']]]);

        self::assertResponseStatusCodeSame(422);
        // A field error: mapped, not a toast — the client shows it itself.
        self::assertSame([], $body['genericErrors']);
        self::assertArrayHasKey('state', $body);
        self::assertCount(1, $body['mappedErrors']);
        self::assertSame('settings[providers][anthropic][model]', $body['mappedErrors'][0]['field']);
        // The message names the field.
        self::assertStringContainsString('Anthropic', $body['mappedErrors'][0]['message']);
        self::assertStringContainsString('Model', $body['mappedErrors'][0]['message']);
        self::assertStringContainsString('characters that are not allowed', $body['mappedErrors'][0]['message']);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(ProviderRepository::class)->findAllByName()['anthropic']->getModel());
    }

    public function testModelLongerThanItsColumnIsRefused(): void
    {
        $body = $this->save(['providers' => ['anthropic' => ['model' => str_repeat('a', 256)]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('too long', $body['mappedErrors'][0]['message']);
    }

    public function testSaveWithoutTheFormIsRefused(): void
    {
        // The X-CSRF-TOKEN header marks the request as a fetch: without it,
        // a refused form post is a navigation and the exception listener
        // redirects instead of answering JSON (SET-03, lot 09).
        $this->client->request('POST', '/settings', [], [], ['HTTP_X_CSRF_TOKEN' => 'fetch', 'HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['The action failed, please try again'], $body['genericErrors']);
        self::assertSame([], $body['mappedErrors']);
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

        $this->client->request('POST', '/settings/provider/mistral/key', ['_password' => ' mistral-key '], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame(['name' => 'mistral'], $body['action']);
        self::assertTrue($body['state']['ai_enabled']);
        self::assertSame('mistral-key', $this->secretStore->get('mistral'));
    }

    public function testSetKeyRefusesAnEmptyKeyAndAnInvalidToken(): void
    {
        $this->client->request('POST', '/settings/provider/mistral/key', ['_password' => '  '], [], $this->tokenHeader());
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame([], $body['genericErrors']);
        self::assertCount(1, $body['mappedErrors']);
        // The field the profiler masks (SEC-01, lot 09).
        self::assertSame('_password', $body['mappedErrors'][0]['field']);
        self::assertSame('No API key provided', $body['mappedErrors'][0]['message']);
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
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('theme', $body['mappedErrors'][0]['field']);

        $this->client->request('POST', '/settings/theme', [], [], $this->tokenHeader());
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('theme', $body['mappedErrors'][0]['field']);

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
        // The X-CSRF-TOKEN header marks these as fetches, so they keep their
        // JSON answers; the navigation behaviour is NavigationExceptionListenerTest's.
        $fetch = ['HTTP_X_CSRF_TOKEN' => 'fetch', 'HTTP_ORIGIN' => 'http://localhost'];

        $this->client->request('POST', '/settings/locale', ['locale' => 'xx', '_token' => $this->token()], [], $fetch);
        self::assertResponseStatusCodeSame(422);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('locale', $body['mappedErrors'][0]['field']);

        $this->client->request('POST', '/settings/locale', ['_token' => $this->token()], [], $fetch);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => 'invalid'], [], $fetch);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(AppLocale::En, static::getContainer()->get(SettingRepository::class)->getOrCreate()->getLocale());
    }

    /**
     * SEC-01, lot 09: the key travels under `_password`, the one field the
     * profiler masks. It must not survive anywhere in the profile, while
     * still reaching the store.
     */
    public function testTheKeyReachesTheStoreButNotTheProfilerProfile(): void
    {
        $this->client->enableProfiler();

        $this->client->request('POST', '/settings/provider/mistral/key', ['_password' => 'sk-live-mistral-987654'], [], $this->tokenHeader());

        self::assertResponseIsSuccessful();
        self::assertSame('sk-live-mistral-987654', $this->secretStore->get('mistral'));

        $profile = $this->client->getProfile();
        self::assertNotNull($profile);
        // The request collector masks the field, it does not drop it. The
        // bag survives storage as a cloned Data; offset access unwraps it.
        $requestCollector = $profile->getCollector('request');
        self::assertInstanceOf(RequestDataCollector::class, $requestCollector);
        self::assertSame('******', $requestCollector->__serialize()['data']['request_request']['_password']);
        // And no collector kept the value: the serialized profile (what the
        // profiler writes to var/cache/*/profiler/) does not contain it.
        // A collector that cannot be re-serialized (the form one keeps a
        // cloned Data) holds no request body anyway.
        foreach ($profile->getCollectors() as $collector) {
            try {
                $serialized = serialize($collector);
            } catch (\Throwable) {
                continue;
            }
            self::assertStringNotContainsString('sk-live-mistral-987654', $serialized, $collector->getName());
        }
    }

    /**
     * SET-08, lot 09: the page declares the stored locale, and everything
     * the server renders follows it.
     */
    public function testThePageCarriesTheHtmlLangOfTheStoredLocale(): void
    {
        $this->client->followRedirects(true);
        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => $this->token()], [], ['HTTP_ORIGIN' => 'http://localhost']);

        // The redirect chain ends on the editor page.
        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        // A text the server renders follows the locale too (base.html.twig).
        self::assertSame('Fermer', $crawler->filter('[data-toast-close-label-value]')->attr('data-toast-close-label-value'));
    }

    /** HUB-06, lot 09: one banner per capability the session lacks, nothing more. */
    public function testNoBannerWhenEveryCapabilityIsPresent(): void
    {
        $this->client->request('GET', '/settings');

        self::assertSelectorCount(0, 'p.settings-capability');
    }

    public function testAMissingWorkerShowsItsBannerButLeavesTheKeyFieldsActive(): void
    {
        $this->stationContext->worker = false;

        $crawler = $this->client->request('GET', '/settings');

        self::assertSelectorCount(1, 'p.settings-capability');
        self::assertSame('AI cannot run in this session.', trim($crawler->filter('p.settings-capability')->text()));
        self::assertSelectorCount(0, 'input[data-settings-target="keyInput"][disabled]');
        self::assertSelectorCount(0, 'button.settings-key-save[disabled]');
    }

    /** Without the bridge, saving a key cannot work, so it cannot be tried either. */
    public function testAMissingBridgeShowsItsBannerAndDisablesTheKeyFields(): void
    {
        $this->secretStore->available = false;

        $crawler = $this->client->request('GET', '/settings');

        self::assertSelectorCount(1, 'p.settings-capability');
        self::assertSame('API keys cannot be saved in this session: the fields are disabled.', trim($crawler->filter('p.settings-capability')->text()));
        self::assertSelectorCount(3, 'input[data-settings-target="keyInput"][disabled]');
        self::assertSelectorCount(3, 'button.settings-key-save[disabled]');
        self::assertSelectorCount(3, 'button.settings-delete-key-btn[disabled]');
    }

    public function testAMissingKeyringShowsItsWarningButLeavesTheKeyFieldsActive(): void
    {
        $this->stationContext->keyring = false;

        $crawler = $this->client->request('GET', '/settings');

        self::assertSelectorCount(1, 'p.settings-capability');
        self::assertSame('The system keyring is unavailable: the Hub will store keys in plain text on disk.', trim($crawler->filter('p.settings-capability')->text()));
        self::assertSelectorCount(0, 'input[data-settings-target="keyInput"][disabled]');
        self::assertSelectorCount(0, 'button.settings-key-save[disabled]');
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

        $this->client->request('POST', '/settings', ['settings' => $fields], [], ['HTTP_X_CSRF_TOKEN' => 'fetch', 'HTTP_ORIGIN' => 'http://localhost']);

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function editorAiEnabled(): bool
    {
        $this->client->request('GET', '/editor/state');

        return json_decode((string) $this->client->getResponse()->getContent(), true)['state']['ai_enabled'];
    }

    private function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('papermark_app')->getValue();
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
