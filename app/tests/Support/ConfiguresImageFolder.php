<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Home (/) redirects to /settings until the images folder is set (see
 * SettingsGuardListener). Tests that only care about what's on the home page
 * use this to get past the guard.
 */
trait ConfiguresImageFolder
{
    private function configureImageFolder(KernelBrowser $client, string $imageFolder = '/home/user/Pictures'): void
    {
        $client->getContainer()->get(SettingRepository::class)->getOrCreate()->setImageFolder($imageFolder);
        $client->getContainer()->get(EntityManagerInterface::class)->flush();
    }
}
