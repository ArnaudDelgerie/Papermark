import type { Application } from '@hotwired/stimulus';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SettingsController from '../../assets/controllers/settings_controller';
import SettingsModalController from '../../assets/controllers/settings_modal_controller';
import { emit, on } from '../../assets/editor/events';
import { confirmDialog } from '../../assets/utils/confirm-dialog';
import { mount, unmount } from './stimulus';

vi.mock('../../assets/utils/confirm-dialog', () => ({ confirmDialog: vi.fn() }));

/** The frame's form as templates/settings/index.html.twig renders it: two providers, the first with a key. */
const FORM = `
<form class="settings-form" data-controller="settings" data-action="submit->settings#submit" data-settings-delay-value="300">
    <div data-settings-target="activity" hidden></div>
    <div data-settings-target="errors" hidden><ul data-settings-target="errorList"></ul></div>

    <fieldset>
        <legend class="settings-provider is-selected">
            <input type="radio" name="settings[selected]" value="anthropic" checked data-action="change->settings#autosave">
        </legend>
        <input type="text" name="settings[providers][anthropic][model]" value="claude-opus-5" data-action="input->settings#autosave">
        <div class="settings-key" data-settings-target="keyBlock" data-name="anthropic">
            <div class="settings-key-set">
                <button type="button" class="delete" data-action="click->settings#deleteKey" data-name="anthropic">Delete</button>
            </div>
            <div class="settings-key-new" hidden>
                <input type="password" data-settings-target="keyInput" data-name="anthropic" data-action="keydown.enter->settings#setKey">
                <button type="button" data-settings-target="keySave" data-name="anthropic" data-action="click->settings#setKey">Save key</button>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend class="settings-provider">
            <input type="radio" name="settings[selected]" value="mistral" data-action="change->settings#autosave">
        </legend>
        <input type="text" name="settings[providers][mistral][model]" value="" data-action="input->settings#autosave">
        <div class="settings-key" data-settings-target="keyBlock" data-name="mistral">
            <div class="settings-key-set" hidden>
                <button type="button" class="delete" data-action="click->settings#deleteKey" data-name="mistral">Delete</button>
            </div>
            <div class="settings-key-new">
                <input type="password" data-settings-target="keyInput" data-name="mistral" data-action="keydown.enter->settings#setKey">
                <button type="button" data-settings-target="keySave" data-name="mistral" data-action="click->settings#setKey">Save key</button>
            </div>
        </div>
    </fieldset>

    <input type="radio" name="settings[defaultMode]" value="single" checked data-action="change->settings#autosave">
    <input type="radio" name="settings[defaultMode]" value="dir" data-action="change->settings#autosave">
</form>`;

describe('the settings form', () => {
    let application: Application;
    let saves: FormData[];
    let keyRequests: Array<{ name: string; key: string }>;
    let deletions: string[];
    const unsubscribers: Array<() => void> = [];

    const $ = <E extends Element = HTMLElement>(selector: string): E => document.querySelector<E>(selector)!;
    const model = (name: string): HTMLInputElement => $<HTMLInputElement>(`input[name="settings[providers][${name}][model]"]`);
    const type = (input: HTMLInputElement, value: string): void => {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const click = (selector: string): void => {
        $(selector).dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    };
    const block = (name: string, part: 'set' | 'new'): HTMLElement => $(`.settings-key[data-name="${name}"] .settings-key-${part}`);

    beforeEach(async () => {
        saves = [];
        keyRequests = [];
        deletions = [];
        unsubscribers.push(
            on('editor:do-save_settings-requested', ({ action }) => saves.push(action.form)),
            on('editor:do-set_key-requested', ({ action }) => keyRequests.push(action)),
            on('editor:do-delete_key-requested', ({ action }) => deletions.push(action.name)),
        );
        // Mounting and unmounting wait on real timers.
        application = await mount(FORM, { settings: SettingsController });
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    });

    afterEach(async () => {
        vi.useRealTimers();
        unsubscribers.splice(0).forEach((unsubscribe) => unsubscribe());
        await unmount(application);
        vi.restoreAllMocks();
    });

    describe('saving by itself', () => {
        it('one timer for every field: only the last change goes out, with the whole form', async () => {
            type(model('anthropic'), 'claude-opus-5.1');
            await vi.advanceTimersByTimeAsync(200);
            type(model('mistral'), 'mistral-large');
            await vi.advanceTimersByTimeAsync(200);
            expect(saves).toHaveLength(0);

            await vi.advanceTimersByTimeAsync(100);

            expect(saves).toHaveLength(1);
            expect(saves[0].get('settings[providers][anthropic][model]')).toBe('claude-opus-5.1');
            expect(saves[0].get('settings[providers][mistral][model]')).toBe('mistral-large');
            expect(saves[0].get('settings[selected]')).toBe('anthropic');
            expect(saves[0].get('settings[defaultMode]')).toBe('single');
        });

        it('radios use the same delay as the text fields', async () => {
            const dir = $<HTMLInputElement>('input[value="dir"]');
            dir.checked = true;
            dir.dispatchEvent(new Event('change', { bubbles: true }));

            await vi.advanceTimersByTimeAsync(299);
            expect(saves).toHaveLength(0);
            await vi.advanceTimersByTimeAsync(1);

            expect(saves).toHaveLength(1);
            expect(saves[0].get('settings[defaultMode]')).toBe('dir');
        });

        it('the key field is not in the form and never triggers a save', async () => {
            const key = $<HTMLInputElement>('.settings-key[data-name="mistral"] input[type="password"]');
            key.value = 'sk-half-typed';
            key.dispatchEvent(new Event('input', { bubbles: true }));

            await vi.advanceTimersByTimeAsync(1000);

            expect(saves).toHaveLength(0);
            type(model('mistral'), 'm');
            await vi.advanceTimersByTimeAsync(300);
            expect([...saves[0].values()]).not.toContain('sk-half-typed');
        });

        it('Enter saves at once and does not post the form', async () => {
            type(model('anthropic'), 'x');

            const event = new Event('submit', { bubbles: true, cancelable: true });
            $('form').dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(saves).toHaveLength(1);
            await vi.advanceTimersByTimeAsync(1000);
            expect(saves).toHaveLength(1);
        });

        it('shows the activity while a save is in flight, until it is answered', async () => {
            type(model('anthropic'), 'x');
            await vi.advanceTimersByTimeAsync(300);
            expect($('[data-settings-target="activity"]').hidden).toBe(false);
            expect($('form').getAttribute('aria-busy')).toBe('true');

            emit('editor:do-save_settings-succeeded', { state: { mode: 'single', file: null, dir: null, readonly: false, ai_enabled: false }, action: {} });

            expect($('[data-settings-target="activity"]').hidden).toBe(true);
            expect($('form').getAttribute('aria-busy')).toBe('false');
        });

        it('follows the checked radio in the legends', async () => {
            const mistral = $<HTMLInputElement>('input[value="mistral"]');
            mistral.checked = true;
            mistral.dispatchEvent(new Event('change', { bubbles: true }));

            const legends = document.querySelectorAll('.settings-provider');
            expect(legends[0].classList.contains('is-selected')).toBe(false);
            expect(legends[1].classList.contains('is-selected')).toBe(true);
        });
    });

    describe('the error zone', () => {
        const state = { mode: 'single', file: null, dir: null, readonly: false, ai_enabled: false } as const;

        it('lists the messages of a refused save, and hides them once a save goes through', async () => {
            type(model('anthropic'), 'bad!');
            await vi.advanceTimersByTimeAsync(300);

            emit('editor:do-save_settings-failed', {
                state,
                action: {
                    form: new FormData(),
                    errors: [
                        { field: 'settings[providers][anthropic][model]', message: 'Anthropic · Model: not allowed' },
                        { field: 'settings[providers][mistral][model]', message: 'Mistral · Model: too long' },
                    ],
                },
            });

            expect($('[data-settings-target="errors"]').hidden).toBe(false);
            expect([...document.querySelectorAll('[data-settings-target="errorList"] li')].map((li) => li.textContent)).toEqual([
                'Anthropic · Model: not allowed',
                'Mistral · Model: too long',
            ]);
            expect($('[data-settings-target="activity"]').hidden).toBe(true);

            type(model('anthropic'), 'ok');
            await vi.advanceTimersByTimeAsync(300);
            emit('editor:do-save_settings-succeeded', { state, action: {} });

            expect($('[data-settings-target="errors"]').hidden).toBe(true);
            expect(document.querySelectorAll('[data-settings-target="errorList"] li')).toHaveLength(0);
        });

        it('a technical failure has no errors to show', async () => {
            emit('editor:do-save_settings-failed', { state, action: { form: new FormData(), errors: [] } });

            expect($('[data-settings-target="errors"]').hidden).toBe(true);
        });
    });

    describe('the key block', () => {
        const state = { mode: 'single', file: null, dir: null, readonly: false, ai_enabled: true } as const;

        it('shows the field when there is no key, and the status with delete when there is one', () => {
            expect(block('anthropic', 'set').hidden).toBe(false);
            expect(block('anthropic', 'new').hidden).toBe(true);
            expect(block('mistral', 'set').hidden).toBe(true);
            expect(block('mistral', 'new').hidden).toBe(false);
        });

        it('the save button sends the key; a set switches the block and empties the field', async () => {
            const input = $<HTMLInputElement>('.settings-key[data-name="mistral"] input[type="password"]');
            input.value = ' sk-mistral ';

            click('.settings-key[data-name="mistral"] [data-settings-target="keySave"]');

            expect(keyRequests).toEqual([{ name: 'mistral', key: 'sk-mistral' }]);
            // Not clicked twice while waiting.
            expect($<HTMLButtonElement>('.settings-key[data-name="mistral"] [data-settings-target="keySave"]').disabled).toBe(true);

            emit('editor:do-set_key-succeeded', { state, action: { name: 'mistral' } });

            expect(input.value).toBe('');
            expect(block('mistral', 'set').hidden).toBe(false);
            expect(block('mistral', 'new').hidden).toBe(true);
            expect(block('anthropic', 'set').hidden).toBe(false);
        });

        it('sends nothing for an empty key, and Enter in the field sends it', () => {
            click('.settings-key[data-name="mistral"] [data-settings-target="keySave"]');
            expect(keyRequests).toEqual([]);

            const input = $<HTMLInputElement>('.settings-key[data-name="mistral"] input[type="password"]');
            input.value = 'sk-enter';
            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true }));

            expect(keyRequests).toEqual([{ name: 'mistral', key: 'sk-enter' }]);
        });

        it('a refused key keeps what was typed and frees the button', () => {
            const input = $<HTMLInputElement>('.settings-key[data-name="mistral"] input[type="password"]');
            input.value = 'sk-mistral';
            click('.settings-key[data-name="mistral"] [data-settings-target="keySave"]');

            emit('editor:do-set_key-failed', { state, action: { name: 'mistral' } });

            expect(input.value).toBe('sk-mistral');
            expect($<HTMLButtonElement>('.settings-key[data-name="mistral"] [data-settings-target="keySave"]').disabled).toBe(false);
            expect(block('mistral', 'new').hidden).toBe(false);
        });

        it('delete asks first, then requests; the block switches on success and the provider is deselected', async () => {
            vi.mocked(confirmDialog).mockResolvedValueOnce(false).mockResolvedValueOnce(true);

            click('.settings-key[data-name="anthropic"] .delete');
            await vi.advanceTimersByTimeAsync(0);
            expect(deletions).toEqual([]);

            click('.settings-key[data-name="anthropic"] .delete');
            await vi.advanceTimersByTimeAsync(0);
            expect(deletions).toEqual(['anthropic']);
            // Nothing moves until the server agrees.
            expect(block('anthropic', 'set').hidden).toBe(false);

            emit('editor:do-delete_key-succeeded', { state: { ...state, ai_enabled: false }, action: { name: 'anthropic' } });

            expect(block('anthropic', 'set').hidden).toBe(true);
            expect(block('anthropic', 'new').hidden).toBe(false);
            expect($<HTMLInputElement>('input[value="anthropic"]').checked).toBe(false);
            expect(document.querySelectorAll('.settings-provider')[0].classList.contains('is-selected')).toBe(false);
        });

        it('deleting the key of another provider leaves the selection alone', () => {
            emit('editor:do-delete_key-succeeded', { state, action: { name: 'mistral' } });

            expect($<HTMLInputElement>('input[value="anthropic"]').checked).toBe(true);
        });
    });
});

describe('the settings modal', () => {
    let application: Application;
    const MODAL = `
    <div data-controller="settings-modal">
        <button type="button" data-action="click->settings-modal#open">Open</button>
        <dialog data-settings-modal-target="dialog" data-action="click->settings-modal#backdrop">
            <button type="button" class="close" data-action="click->settings-modal#close">Close</button>
            <p class="inside">Inside</p>
        </dialog>
    </div>`;

    beforeEach(async () => {
        // jsdom has no <dialog> behaviour.
        HTMLDialogElement.prototype.showModal = function showModal(this: HTMLDialogElement): void {
            this.setAttribute('open', '');
        };
        HTMLDialogElement.prototype.close = function close(this: HTMLDialogElement): void {
            this.removeAttribute('open');
        };
        application = await mount(MODAL, { 'settings-modal': SettingsModalController });
    });

    afterEach(async () => {
        await unmount(application);
    });

    const dialog = (): HTMLDialogElement => document.querySelector('dialog')!;
    const click = (selector: string): void => {
        document.querySelector(selector)!.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    };

    it('opens with the button, closes with the cross', () => {
        click('button:not(.close)');
        expect(dialog().open).toBe(true);

        click('.close');
        expect(dialog().open).toBe(false);
    });

    it('closes on a click on the backdrop, not inside', () => {
        click('button:not(.close)');

        click('.inside');
        expect(dialog().open).toBe(true);

        dialog().dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(dialog().open).toBe(false);
    });
});
