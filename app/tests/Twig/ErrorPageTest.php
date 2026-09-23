<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * SET-03, lot 09: the error page is a safety net — it must render no matter
 * what failed, translated, with its own `lang` and a way back home. Shown
 * only with APP_DEBUG=0; in dev the debugger keeps its own page, so this
 * renders the template itself.
 */
final class ErrorPageTest extends KernelTestCase
{
    public function testItIsTranslatedAndCarriesTheLangAndARetryLink(): void
    {
        $html = $this->render('fr');

        $crawler = new Crawler($html);
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSame('Une erreur est survenue', $crawler->filter('h1')->text());
        self::assertSame('Papermark n\'a pas pu afficher cette page.', $crawler->filter('p')->text());
        self::assertSame('Réessayer', trim($crawler->filter('a')->text()));
        self::assertSame('/', $crawler->filter('a')->attr('href'));
        // On its own: nothing that depends on what may have failed (base
        // layout, assets, session).
        self::assertStringNotContainsString('data-controller', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testTheEnglishPageSaysSoInItsOwnLang(): void
    {
        $html = $this->render('en');

        $crawler = new Crawler($html);
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSame('Something went wrong', $crawler->filter('h1')->text());
        self::assertSame('Try again', trim($crawler->filter('a')->text()));
    }

    private function render(string $locale): string
    {
        self::bootKernel();

        $request = Request::create('http://localhost/');
        $request->setLocale($locale);
        self::getContainer()->get(RequestStack::class)->push($request);

        return self::getContainer()->get(Environment::class)->render('bundles/TwigBundle/Exception/error.html.twig');
    }
}
