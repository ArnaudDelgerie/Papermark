<?php

declare(strict_types=1);

namespace App\Tests\Service\Setting;

use App\Entity\Provider;
use App\Entity\Setting;
use App\Enum\ProviderName;
use App\Enum\Setting\AppLocale;
use App\Enum\Setting\EditorMode;
use App\Enum\Setting\ThemeMode;
use App\Exception\FormRefusedException;
use App\Form\SettingsType;
use App\Repository\ProviderRepository;
use App\Repository\SettingRepository;
use App\Service\Editor\EditorState;
use App\Service\Setting\SettingsUpdater;
use App\Tests\Double\InMemorySecretStore;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use ArnaudDelgerie\TFSAppBundle\StationContext\StationContextInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * SettingsUpdater (lot 08-settings-controller.md): the operation service
 * behind every settings write. Needs a real request in the RequestStack and
 * an async worker, both read by EditorState/AiAvailability for `ai_enabled`.
 */
final class SettingsUpdaterTest extends KernelTestCase
{
    private InMemorySecretStore $secretStore;
    private EditorState $editorState;
    private ProviderRepository $providers;
    private SettingRepository $settings;
    private SettingsUpdater $updater;

    protected function setUp(): void
    {
        self::bootKernel();

        $request = Request::create('http://localhost/');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get(RequestStack::class)->push($request);

        $this->secretStore = new InMemorySecretStore();
        self::getContainer()->set(SecretStoreInterface::class, $this->secretStore);

        $stationContext = $this->createStub(StationContextInterface::class);
        $stationContext->method('isAsyncWorker')->willReturn(true);
        self::getContainer()->set(StationContextInterface::class, $stationContext);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (ProviderName::cases() as $name) {
            $entityManager->persist(new Provider($name));
        }
        $entityManager->flush();

        $this->editorState = self::getContainer()->get(EditorState::class);
        $this->providers = self::getContainer()->get(ProviderRepository::class);
        $this->settings = self::getContainer()->get(SettingRepository::class);
        $this->updater = self::getContainer()->get(SettingsUpdater::class);
    }

    public function testSetKeyStoresItTrimmedAndRecomputesAiEnabled(): void
    {
        $this->selectProvider(ProviderName::Mistral);
        self::assertFalse($this->editorState->refreshAiEnabled());

        $this->updater->setKey(ProviderName::Mistral, '  mistral-key  ');

        self::assertSame('mistral-key', $this->secretStore->get('mistral'));
        self::assertTrue($this->editorState->isAiEnabled());
    }

    public function testDeleteKeyDeselectsTheProviderOnlyWhenItWasSelected(): void
    {
        $this->secretStore->set('mistral', 'mistral-key');
        $this->selectProvider(ProviderName::Mistral);

        $this->updater->deleteKey(ProviderName::Anthropic);

        self::assertSame('mistral', $this->currentSetting()->getSelectedProvider()?->getName()->value);

        $this->updater->deleteKey(ProviderName::Mistral);

        self::assertNull($this->currentSetting()->getSelectedProvider());
        self::assertNull($this->secretStore->get('mistral'));
        self::assertFalse($this->editorState->isAiEnabled());
    }

    public function testSetThemeStoresIt(): void
    {
        $this->updater->setTheme(ThemeMode::Light);

        self::assertSame(ThemeMode::Light, $this->currentSetting()->getThemeMode());
    }

    public function testSetLocaleStoresIt(): void
    {
        $this->updater->setLocale(AppLocale::Fr);

        self::assertSame(AppLocale::Fr, $this->currentSetting()->getLocale());
    }

    public function testSaveOfAnInvalidFormThrowsWithTheMappedErrorAndStoresNothing(): void
    {
        $form = $this->createSettingsForm();
        $form->submit([
            'providers' => ['anthropic' => ['model' => 'not a model!']],
            'defaultMode' => 'single',
        ]);

        try {
            $this->updater->save($form);
            self::fail('Expected FormRefusedException.');
        } catch (FormRefusedException $e) {
            self::assertSame([], $e->genericErrors);
            self::assertCount(1, $e->mappedErrors);
            self::assertSame('settings[providers][anthropic][model]', $e->mappedErrors[0]['field']);
        }

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull($this->providers->findAllByName()['anthropic']->getModel());
    }

    public function testSaveOfANotSubmittedFormThrowsWithAGenericMessage(): void
    {
        $form = $this->createSettingsForm();

        try {
            $this->updater->save($form);
            self::fail('Expected FormRefusedException.');
        } catch (FormRefusedException $e) {
            self::assertSame(['The action failed, please try again'], $e->genericErrors);
            self::assertSame([], $e->mappedErrors);
        }
    }

    private function selectProvider(ProviderName $name): void
    {
        $setting = $this->settings->getOrCreate();
        $setting->setSelectedProvider($this->providers->findAllByName()[$name->value]);
        $this->settings->update($setting);
    }

    private function currentSetting(): Setting
    {
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $this->settings->getOrCreate();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function createSettingsForm(): FormInterface
    {
        return self::getContainer()->get(FormFactoryInterface::class)->create(SettingsType::class, [
            'providers' => $this->providers->findAllByName(),
            'selected' => null,
            'defaultMode' => EditorMode::Single,
        ], [
            'csrf_protection' => false,
        ]);
    }
}
