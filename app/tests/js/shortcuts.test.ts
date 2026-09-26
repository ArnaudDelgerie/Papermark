import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import EditorShortcuts from '../../assets/editor/shortcuts';

/**
 * Each shortcut clicks its button (EDITOR_SHORTCUTS.md): the assertions are
 * on the click and on preventDefault — the combination is ours even when
 * the button is disabled or absent.
 */
describe('EditorShortcuts', () => {
    let buttons: { [name: string]: HTMLButtonElement };
    let clicks: { [button: string]: number };
    let shortcuts: EditorShortcuts;

    function keydown(key: string, { shift = false, alt = false, ctrl = true }: { shift?: boolean; alt?: boolean; ctrl?: boolean } = {}): void {
        window.dispatchEvent(new KeyboardEvent('keydown', { key, cancelable: true, ctrlKey: ctrl, shiftKey: shift, altKey: alt }));
    }

    function presses(): number {
        return Object.values(clicks).reduce((sum, count) => sum + count, 0);
    }

    beforeEach(() => {
        buttons = {};
        clicks = {};
        const getter = (name: string) => {
            const button = document.createElement('button');
            button.addEventListener('click', () => (clicks[name] = (clicks[name] ?? 0) + 1));
            document.body.append(button);
            buttons[name] = button;

            return () => button;
        };
        shortcuts = new EditorShortcuts({
            save: getter('save'),
            saveAs: getter('saveAs'),
            newFile: getter('newFile'),
            print: getter('print'),
            open: getter('open'),
            switchMode: getter('switchMode'),
        });
        shortcuts.listen();
    });

    afterEach(() => {
        shortcuts.stop();
        Object.values(buttons).forEach((button) => button.remove());
    });

    it('clicks each button for its combination', () => {
        keydown('s');
        keydown('S', { shift: true });
        keydown('n');
        keydown('p');
        keydown('o');
        keydown('m');

        expect(clicks).toEqual({ save: 1, saveAs: 1, newFile: 1, print: 1, open: 1, switchMode: 1 });
    });

    it('prevents the default of a mapped combination', () => {
        const event = new KeyboardEvent('keydown', { key: 'p', cancelable: true, ctrlKey: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
    });

    it('leaves an unmapped combination to the webview', () => {
        keydown('x');
        keydown('p', { shift: true });
        keydown('s', { alt: true });
        keydown('s', { ctrl: false });

        expect(presses()).toBe(0);
    });

    it('claims the combination of a disabled button without clicking it', () => {
        buttons['save']!.disabled = true;

        const event = new KeyboardEvent('keydown', { key: 's', cancelable: true, ctrlKey: true });
        window.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(clicks['save']).toBeUndefined();
    });

    it('claims the combination of an absent button without crashing', () => {
        shortcuts.stop();
        const absent = new EditorShortcuts({
            save: () => null,
            saveAs: () => null,
            newFile: () => null,
            print: () => null,
            open: () => null,
            switchMode: () => null,
        });
        absent.listen();

        try {
            const event = new KeyboardEvent('keydown', { key: 'o', cancelable: true, ctrlKey: true });
            window.dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
        } finally {
            absent.stop();
        }
    });

    it('clicks nothing while a modal is open, but keeps the combination', () => {
        const dialog = document.createElement('dialog');
        dialog.setAttribute('open', '');
        document.body.append(dialog);

        try {
            const event = new KeyboardEvent('keydown', { key: 's', cancelable: true, ctrlKey: true });
            window.dispatchEvent(event);

            expect(event.defaultPrevented).toBe(true);
            expect(presses()).toBe(0);
        } finally {
            dialog.remove();
        }
    });

    it('stop() removes the listener', () => {
        shortcuts.stop();
        keydown('s');

        expect(presses()).toBe(0);
    });
});
