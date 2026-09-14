<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ai\ApiKeyResolver;
use App\Ai\ProviderName;
use App\Form\SettingsType;
use App\Repository\ProviderRepository;
use ArnaudDelgerie\TFSAppBundle\Bridge\Exception\BridgeException;
use ArnaudDelgerie\TFSAppBundle\Bridge\SecretStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly SecretStoreInterface $secretStore,
        private readonly ApiKeyResolver $apiKeyResolver,
        private readonly ProviderRepository $providers,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/settings', name: 'app_settings', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(SettingsType::class, [
            'providers' => $this->providers->findAllByName(),
            'selected' => $this->providers->findSelected()?->getName(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $selected = $form->get('selected')->getData();

            try {
                foreach ($form->get('providers') as $providerForm) {
                    $provider = $providerForm->getData();
                    $provider->setSelected($provider->getName() === $selected);

                    $apiKey = $providerForm->get('apiKey')->getData();
                    if (\is_string($apiKey) && $apiKey !== '') {
                        $this->secretStore->set($provider->getName()->value, $apiKey);
                    }
                }

                $this->entityManager->flush();

                return $this->redirectToRoute('app_settings');
            } catch (BridgeException) {
                $form->addError(new FormError(
                    $this->translator->trans('components.editor.error.save_failed', [], 'components'),
                ));
            }
        }

        $hasKey = [];
        foreach (ProviderName::cases() as $name) {
            $hasKey[$name->value] = $this->apiKeyResolver->resolve($name) !== null;
        }

        return $this->render('settings/index.html.twig', [
            'form' => $form,
            'has_key' => $hasKey,
        ]);
    }
}
