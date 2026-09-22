import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import LocaleStepperController from '../../assets/controllers/locale_stepper_controller';
import ThemeSwitchController from '../../assets/controllers/theme_switch_controller';
import { attr } from './fixtures';
import { jsonResponse, mount, unmount } from './stimulus';

const toasts: Array<{ type: string; message: string }> = [];
const onToast = (event: Event): number => toasts.push((event as CustomEvent).detail);

describe('the theme button', () => {
    let application: Application;
    let fetchMock: ReturnType<typeof vi.fn>;

    const button = (): HTMLButtonElement => document.querySelector('button')!;
    const html = (): string => document.documentElement.dataset.theme ?? '';
    const click = (): void => {
        button().dispatchEvent(new MouseEvent('click', { bubbles: true }));
    };

    async function start(current = 'auto'): Promise<void> {
        document.documentElement.dataset.theme = current;
        application = await mount(
            `<button type="button" data-controller="theme-switch" data-action="click->theme-switch#next" data-value="${current}"
                data-theme-switch-current-value="${current}"
                data-theme-switch-labels-value="${attr({ auto: 'Auto', light: 'Light', dark: 'Dark' })}"
                data-theme-switch-url-value="/settings/theme" data-theme-switch-token-value="tk-settings"
                data-theme-switch-delay-value="300"
                data-theme-switch-i18n-value="${attr({ label: 'Theme: {theme}', failed: 'Failed' })}">
                <span data-theme-switch-target="label">Theme: ${current}</span>
            </button>`,
            { 'theme-switch': ThemeSwitchController },
        );
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    }

    beforeEach(() => {
        fetchMock = vi.fn().mockResolvedValue(jsonResponse({ theme: 'light' }));
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(console, 'error').mockImplementation(() => {});
        toasts.length = 0;
        window.addEventListener('toast:show', onToast);
    });

    afterEach(async () => {
        vi.useRealTimers();
        await unmount(application);
        window.removeEventListener('toast:show', onToast);
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('goes round auto, light, dark, auto, applying each value at once', async () => {
        await start('auto');

        click();
        expect(html()).toBe('light');
        expect(button().dataset.value).toBe('light');
        expect(button().title).toBe('Theme: Light');

        click();
        expect(html()).toBe('dark');
        expect(button().dataset.value).toBe('dark');

        click();
        expect(html()).toBe('auto');
        expect(document.querySelector('[data-theme-switch-target="label"]')!.textContent).toBe('Theme: Auto');
        // Nothing saved yet: no click has been left alone long enough.
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('saves only the last value, after a delay without a click', async () => {
        await start('auto');

        click();
        await vi.advanceTimersByTimeAsync(200);
        click();
        await vi.advanceTimersByTimeAsync(299);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/settings/theme');
        expect(init.method).toBe('POST');
        expect(init.headers).toEqual({ 'X-CSRF-TOKEN': 'tk-settings' });
        expect((init.body as FormData).get('theme')).toBe('dark');
        expect(toasts).toEqual([]);
    });

    it('goes back to the last saved theme, with a toast, when the save fails', async () => {
        await start('auto');
        fetchMock.mockResolvedValue(jsonResponse({ genericErrors: ['Nope'] }, 500));

        click();
        click();
        expect(html()).toBe('dark');
        await vi.advanceTimersByTimeAsync(300);

        expect(html()).toBe('auto');
        expect(button().dataset.value).toBe('auto');
        expect(toasts).toEqual([{ type: 'error', message: 'Failed' }]);
    });

    it('a saved theme becomes the one to come back to', async () => {
        await start('auto');

        click();
        await vi.advanceTimersByTimeAsync(300);
        fetchMock.mockRejectedValue(new TypeError('Network down'));
        click();
        await vi.advanceTimersByTimeAsync(300);

        expect(html()).toBe('light');
    });
});

describe('the language button', () => {
    let application: Application;
    let posts: string[];
    // What the editor's leave guard does with a click on the hidden submit.
    let guarded: boolean;
    const guard = (event: Event): void => {
        if (guarded && (event.target as Element).closest('[data-editor-leave-guard]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    };
    const onSubmit = (event: Event): void => {
        event.preventDefault();
        posts.push((document.querySelector('input[name="locale"]') as HTMLInputElement).value);
    };

    const button = (): HTMLButtonElement => document.querySelector('.locale-stepper-btn')!;
    const code = (): string => document.querySelector('[data-locale-stepper-target="code"]')!.textContent!;
    const click = (): void => {
        button().dispatchEvent(new MouseEvent('click', { bubbles: true }));
    };
    const escape = (): void => {
        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
    };

    async function start(locales: string[], current: string): Promise<void> {
        application = await mount(
            `<div class="locale-stepper" data-controller="locale-stepper"
                data-locale-stepper-current-value="${current}"
                data-locale-stepper-locales-value="${attr(locales)}"
                data-locale-stepper-labels-value="${attr({ en: 'English', fr: 'Français', de: 'Deutsch', es: 'Español' })}"
                data-locale-stepper-label-value="Language: {language}"
                data-locale-stepper-delay-value="2000"
                data-action="keydown@window->locale-stepper#keydown">
                <button type="button" class="locale-stepper-btn" data-locale-stepper-target="button" data-action="click->locale-stepper#step">
                    <svg><rect data-locale-stepper-target="ring"></rect></svg>
                    <span data-locale-stepper-target="code">${current.toUpperCase()}</span>
                </button>
                <form method="post" action="/settings/locale" data-locale-stepper-target="form">
                    <input type="hidden" name="locale" value="${current}" data-locale-stepper-target="input">
                    <button type="submit" data-locale-stepper-target="submit" data-editor-leave-guard></button>
                </form>
            </div>`,
            { 'locale-stepper': LocaleStepperController },
        );
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    }

    beforeEach(() => {
        posts = [];
        guarded = false;
        window.addEventListener('click', guard, true);
        document.addEventListener('submit', onSubmit);
    });

    afterEach(async () => {
        vi.useRealTimers();
        await unmount(application);
        window.removeEventListener('click', guard, true);
        document.removeEventListener('submit', onSubmit);
    });

    it('shows the aimed language at once and announces it, without posting yet', async () => {
        await start(['en', 'fr'], 'en');

        click();

        expect(code()).toBe('FR');
        expect(button().getAttribute('aria-label')).toBe('Language: Français');
        expect(button().classList.contains('is-pending')).toBe(true);
        await vi.advanceTimersByTimeAsync(1999);
        expect(posts).toEqual([]);
    });

    it('posts the aimed language once no click has come for the delay', async () => {
        await start(['en', 'fr'], 'en');

        click();
        await vi.advanceTimersByTimeAsync(2000);

        expect(posts).toEqual(['fr']);
    });

    it('goes back to the current language: no timer, no post', async () => {
        await start(['en', 'fr'], 'en');

        click();
        click();

        expect(code()).toBe('EN');
        expect(button().getAttribute('aria-label')).toBe('Language: English');
        expect(button().classList.contains('is-pending')).toBe(false);
        await vi.advanceTimersByTimeAsync(5000);
        expect(posts).toEqual([]);
    });

    it('Escape cancels', async () => {
        await start(['en', 'fr'], 'en');

        click();
        escape();

        expect(code()).toBe('EN');
        await vi.advanceTimersByTimeAsync(5000);
        expect(posts).toEqual([]);
    });

    it('Escape with nothing aimed does nothing', async () => {
        await start(['en', 'fr'], 'en');

        const event = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
    });

    it('works for any number of languages: each click restarts the timer on the next one', async () => {
        await start(['en', 'fr', 'de', 'es'], 'en');

        click();
        await vi.advanceTimersByTimeAsync(1500);
        click();
        await vi.advanceTimersByTimeAsync(1500);
        click();
        expect(code()).toBe('ES');
        await vi.advanceTimersByTimeAsync(1999);
        expect(posts).toEqual([]);
        await vi.advanceTimersByTimeAsync(1);

        expect(posts).toEqual(['es']);
    });

    it('cycling all the way round to the current language cancels', async () => {
        await start(['en', 'fr', 'de'], 'fr');

        click();
        click();
        expect(code()).toBe('EN');
        click();

        expect(code()).toBe('FR');
        await vi.advanceTimersByTimeAsync(5000);
        expect(posts).toEqual([]);
    });

    it('the leave guard gets to stop it: the button shows the current language again and nothing is posted', async () => {
        await start(['en', 'fr'], 'en');
        guarded = true;

        click();
        await vi.advanceTimersByTimeAsync(2000);

        expect(posts).toEqual([]);
        expect(code()).toBe('EN');
        // Once confirmed, the guard replays the click on the submit button.
        guarded = false;
        (document.querySelector('[data-editor-leave-guard]') as HTMLButtonElement).click();
        expect(posts).toEqual(['fr']);
    });
});
