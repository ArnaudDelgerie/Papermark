<?php

declare(strict_types=1);

namespace App\File;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The directory open in dir mode, kept in the Symfony session so it survives
 * an app restart (see EDITOR_FOLDER_MODE.md).
 */
final class OpenDirectory
{
    private const SESSION_KEY = 'editor.open_directory';

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function get(): ?string
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY);
    }

    public function set(string $path): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $path);
    }
}
