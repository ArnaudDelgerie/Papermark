<?php

declare(strict_types=1);

namespace App\Editor;

use App\Ai\AiAvailability;
use App\Repository\SettingRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * What the editor is showing, kept in the Symfony session so it survives a
 * reload or an app restart: the mode, the current folder, and one current
 * file shared by both modes. Changing mode or folder drops the file, so a
 * switch always starts from an empty editor. Every route that writes it
 * returns toArray(), and the front broadcasts that (see EDITOR_REACTIVITY.md).
 *
 * `ai_enabled` is part of it too: computed once from the settings and the
 * keyring, then kept until an action that can change it (saving the
 * settings, setting or deleting a key) asks for it again.
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
    private const AI_ENABLED = 'editor.ai_enabled';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SettingRepository $settings,
        private readonly AiAvailability $aiAvailability,
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

    /**
     * Records the mode now shown, so a later change of the default mode in
     * the settings doesn't switch what the editor shows.
     */
    public function keepMode(): void
    {
        $this->requestStack->getSession()->set(self::MODE, $this->getMode()->value);
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
     * Computed when the session has none yet.
     */
    public function isAiEnabled(): bool
    {
        $value = $this->requestStack->getSession()->get(self::AI_ENABLED);

        return \is_bool($value) ? $value : $this->refreshAiEnabled();
    }

    /**
     * Recomputes it in full (selected provider, key, worker) and keeps it.
     */
    public function refreshAiEnabled(): bool
    {
        $enabled = $this->aiAvailability->isEnabled();
        $this->requestStack->getSession()->set(self::AI_ENABLED, $enabled);

        return $enabled;
    }

    /**
     * @return array{mode: string, file: ?string, dir: ?string, ai_enabled: bool}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->getMode()->value,
            'file' => $this->getFile(),
            'dir' => $this->getDir(),
            'ai_enabled' => $this->isAiEnabled(),
        ];
    }
}
