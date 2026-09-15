<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Ai\ProviderName;
use App\Entity\Provider;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        foreach (ProviderName::cases() as $name) {
            $entityManager->persist(new Provider($name));
        }
        $entityManager->flush();
    }

    public function testRendersOneBlockPerProvider(): void
    {
        $this->client->request('GET', '/settings');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Settings');
        self::assertSelectorExists('a.settings-back');
        // One fieldset for the images folder, plus one per provider.
        self::assertSelectorCount(4, 'fieldset.settings-fieldset');
        self::assertSelectorExists('input[name="settings[imageFolder]"]');
        self::assertSelectorCount(3, 'input[type="radio"][name="settings[selected]"]');

        // Only the provider with a stored key shows the badge.
        self::assertSelectorCount(1, '.settings-provider-badge');
        self::assertSelectorTextContains('.settings-provider-badge', 'Key set');

        // No double-submit on the form: once used in a session, Symfony would then
        // reject the editor's fetch requests, which rely on the origin check only.
        self::assertSelectorExists('input[name="settings[_token]"]');
        self::assertSelectorNotExists('input[name="settings[_token]"][data-controller="csrf-protection"]');
    }

    public function testSaveStoresSelectionAndModelInDatabaseAndKeyInKeyring(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save', [
            'settings[imageFolder]' => '/home/user/Pictures',
            'settings[selected]' => 'mistral',
            'settings[providers][mistral][model]' => 'mistral-large-latest',
            'settings[providers][mistral][apiKey]' => 'mistral-key',
            'settings[providers][anthropic][model]' => 'claude-sonnet-4-5',
        ], 'POST', ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/settings');

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $providers = static::getContainer()->get(ProviderRepository::class)->findAllByName();
        $setting = static::getContainer()->get(SettingRepository::class)->getOrCreate();

        self::assertSame('/home/user/Pictures', $setting->getImageFolder());
        self::assertSame('mistral', $setting->getSelectedProvider()?->getName()->value);
        self::assertSame('mistral-large-latest', $providers['mistral']->getModel());
        self::assertSame('claude-sonnet-4-5', $providers['anthropic']->getModel());
        self::assertNull($providers['openai']->getModel());

        self::assertSame('mistral-key', $this->secretStore->get('mistral'));
        // An empty key field keeps the stored key.
        self::assertSame('existing-key', $this->secretStore->get('anthropic'));
        self::assertNull($this->secretStore->get('openai'));
    }

    public function testSaveRequiresImageFolder(): void
    {
        $this->client->request('GET', '/settings');
        $this->client->submitForm('Save', [
            'settings[imageFolder]' => '',
        ], 'POST', ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Choose an images folder before continuing');
    }
}
