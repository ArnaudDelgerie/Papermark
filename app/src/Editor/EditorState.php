<?php

declare(strict_types=1);

namespace App\Editor;

use App\Repository\SettingRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the editor is showing, kept in the Symfony session so it survives a
 * reload or an app restart: the mode, the current folder, and one current
 * file shared by both modes. Changing mode or folder drops the file, so a
 * switch always starts from an empty editor. Every route that writes it
 * returns toArray(), and the front broadcasts that (see EDITOR_REACTIVITY.md).
 *
 * Only paths are stored, never content or any scroll/cursor position.
 */
final class EditorState
{
    // Mode and folder keep the keys ModeSession and OpenDirectory used, so an
    // existing session keeps both across the upgrade.
    private const MODE = 'currentMode';
    private const FILE = 'editor.file';
    private const DIR = 'editor.open_directory';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * Falls back on the default mode from the settings until one is recorded.
     */
    public function getMode(): EditorMode
    {
        $value = $this->requestStack->getSession()->get(self::MODE);
        $mode = \is_string($value) ? EditorMode::tryFrom($value) : null;

        return $mode ?? $this->settings->getOrCreate()->getDefaultMode();
    }

    public function setMode(EditorMode $mode): void
    {
        $this->requestStack->getSession()->set(self::MODE, $mode->value);
        $this->setFile(null);
    }

    public function getFile(): ?string
    {
        $value = $this->requestStack->getSession()->get(self::FILE);

        return \is_string($value) ? $value : null;
    }

    public function setFile(?string $path): void
    {
        if ($path === null) {
            $this->requestStack->getSession()->remove(self::FILE);

            return;
        }

        $this->requestStack->getSession()->set(self::FILE, $path);
    }

    public function getDir(): ?string
    {
        $value = $this->requestStack->getSession()->get(self::DIR);

        return \is_string($value) ? $value : null;
    }

    public function setDir(string $path): void
    {
        $this->requestStack->getSession()->set(self::DIR, $path);
        $this->setFile(null);
    }

    /**
     * @return array{mode: string, file: ?string, dir: ?string}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->getMode()->value,
            'file' => $this->getFile(),
            'dir' => $this->getDir(),
        ];
    }
}
