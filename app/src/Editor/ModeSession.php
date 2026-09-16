<?php

declare(strict_types=1);

namespace App\Editor;

use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Which editor mode is current, and which file is associated with each mode —
 * kept in the Symfony session so app_home can route back to them on the next
 * launch. Only the path is stored, never its content or any scroll/cursor
 * position (see EDITOR_FIX.md).
 */
final class ModeSession
{
    private const CURRENT_MODE = 'currentMode';

    private const FILE_KEYS = [
        EditorMode::Single->value => 'singleModeFile',
        EditorMode::Dir->value => 'dirModeFile',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getCurrentMode(): ?EditorMode
    {
        $value = $this->requestStack->getSession()->get(self::CURRENT_MODE);

        return \is_string($value) ? EditorMode::tryFrom($value) : null;
    }

    public function setCurrentMode(EditorMode $mode): void
    {
        $this->requestStack->getSession()->set(self::CURRENT_MODE, $mode->value);
    }

    public function getFile(EditorMode $mode): ?string
    {
        $value = $this->requestStack->getSession()->get(self::FILE_KEYS[$mode->value]);

        return \is_string($value) ? $value : null;
    }

    public function setFile(EditorMode $mode, ?string $path): void
    {
        if ($path === null) {
            $this->requestStack->getSession()->remove(self::FILE_KEYS[$mode->value]);

            return;
        }

        $this->requestStack->getSession()->set(self::FILE_KEYS[$mode->value], $path);
    }
}
