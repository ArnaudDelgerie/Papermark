<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Enum\Setting\AppLocale;
use App\EventListener\NavigationExceptionListener;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * SET-03, lot 09: a page navigation (the language form post, the webview's
 * own loads) never ends on raw JSON or an error page — the webview has no
 * address bar to leave with. The fetches of utils/http.ts keep their JSON
 * answers.
 */
final class NavigationExceptionListenerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
    }

    /** The webview's own posts: a refused token becomes a toast, not a JSON body. */
    public function testANavigationRefusingTheTokenIsRedirectedHomeWithAnErrorFlash(): void
    {
        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => 'invalid'], [], $this->navigate());

        self::assertResponseRedirects('/');
        self::assertSame(
            ['Invalid security token, please reload the page'],
            $this->errorFlashes($this->client->getRequest()),
        );
        // Nothing was stored.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(AppLocale::En, static::getContainer()->get(SettingRepository::class)->getOrCreate()->getLocale());
    }

    /** An unknown language: the toast says the field's own message. */
    public function testANavigationRefusedByValidationCarriesTheFieldMessage(): void
    {
        // The same refusal as a fetch first, to read the message it answers.
        $this->client->request('POST', '/settings/locale', ['locale' => 'xx', '_token' => $this->token()], [], $this->fetch());
        self::assertResponseStatusCodeSame(422);
        $message = json_decode((string) $this->client->getResponse()->getContent(), true)['mappedErrors'][0]['message'];

        $this->client->request('POST', '/settings/locale', ['locale' => 'xx', '_token' => $this->token()], [], $this->navigate());

        self::assertResponseRedirects('/');
        self::assertSame([$message], $this->errorFlashes($this->client->getRequest()));
    }

    /**
     * WebKitGTK sends no Sec-Fetch-Mode at all: the fallback criterion is
     * the absence of X-CSRF-TOKEN, which every fetch of utils/http.ts
     * carries (tranché à l'implémentation, lot 09).
     */
    public function testARequestWithNoFetchMarkersAtAllIsANavigation(): void
    {
        $this->client->request('POST', '/settings/locale', ['locale' => 'fr', '_token' => 'invalid'], [], ['HTTP_ORIGIN' => 'http://localhost']);

        self::assertResponseRedirects('/');
        self::assertSame(
            ['Invalid security token, please reload the page'],
            $this->errorFlashes($this->client->getRequest()),
        );
    }

    /** An `<img>` load is not a navigation: its 404 stays a 404. */
    public function testANoCorsRequestIsNotANavigation(): void
    {
        $this->client->request('GET', '/document/image', [], [], ['HTTP_SEC_FETCH_MODE' => 'no-cors']);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * No loop: home resolves to the editor page, so a failure of either is
     * the app being down — the error page says it, a redirect would bounce
     * between the two forever.
     */
    public function testAFailedNavigationOfTheHomeOrEditorPageIsNotRedirected(): void
    {
        $listener = static::getContainer()->get(NavigationExceptionListener::class);

        foreach (['app_home', 'app_editor'] as $route) {
            $event = $this->exceptionEvent($route);
            $listener->onKernelException($event);

            self::assertFalse($event->hasResponse(), $route);
        }

        // Any other route: the redirect is set and the propagation stops,
        // before the JSON builders and the firewall see the exception.
        $event = $this->exceptionEvent('app_settings_locale');
        $listener->onKernelException($event);

        self::assertTrue($event->hasResponse());
        self::assertSame('/', $event->getResponse()->headers->get('Location'));
        self::assertSame(['An unexpected error occurred'], $this->errorFlashes($event->getRequest()));
    }

    /**
     * The error flashes of a request, read through the session interface
     * (the same way the listener writes them).
     *
     * @return list<string>
     */
    private function errorFlashes(Request $request): array
    {
        $bag = $request->getSession()->getBag('flashes');
        self::assertInstanceOf(FlashBagInterface::class, $bag);

        return $bag->get('error');
    }

    /**
     * @return array<string, string>
     */
    private function navigate(): array
    {
        return ['HTTP_SEC_FETCH_MODE' => 'navigate', 'HTTP_ORIGIN' => 'http://localhost'];
    }

    /**
     * @return array<string, string>
     */
    private function fetch(): array
    {
        return ['HTTP_X_CSRF_TOKEN' => $this->token(), 'HTTP_ORIGIN' => 'http://localhost'];
    }

    private function exceptionEvent(string $route): ExceptionEvent
    {
        $request = Request::create('http://localhost/');
        $request->attributes->set('_route', $route);
        $request->headers->set('Sec-Fetch-Mode', 'navigate');
        $request->setSession(new Session(new MockArraySessionStorage()));

        return new ExceptionEvent(
            static::getContainer()->get('kernel'),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new \RuntimeException('the app is down'),
        );
    }

    private function token(): string
    {
        return static::getContainer()->get(CsrfTokenManagerInterface::class)->getToken('papermark_app')->getValue();
    }
}
