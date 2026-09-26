/**
 * Ctrl+S, Ctrl+Shift+S, Ctrl+N, Ctrl+P, Ctrl+O and Ctrl+M (FRT-10 + UX-03,
 * lot 08, then EDITOR_SHORTCUTS.md). A single keydown on window, posed by
 * editor_controller in connect() and removed in disconnect(). The buttons
 * do the work — a disabled one ignores the click, and the leave guard on
 * New or on a mode switch asks about unsaved work — so no state is
 * duplicated here.
 */
type ButtonGetter = () => HTMLButtonElement | null;

type ShortcutButtons = {
    save: ButtonGetter;
    saveAs: ButtonGetter;
    newFile: ButtonGetter;
    print: ButtonGetter;
    open: ButtonGetter;
    switchMode: ButtonGetter;
};

export default class EditorShortcuts {
    readonly #buttons: ShortcutButtons;
    readonly #onKeyDown = (event: KeyboardEvent): void => this.#handle(event);

    constructor(buttons: ShortcutButtons) {
        this.#buttons = buttons;
    }

    listen(): void {
        window.addEventListener('keydown', this.#onKeyDown);
    }

    stop(): void {
        window.removeEventListener('keydown', this.#onKeyDown);
    }

    #handle(event: KeyboardEvent): void {
        // Cmd comes free where it exists; the hub runs under Linux.
        if (!(event.ctrlKey || event.metaKey) || event.altKey) {
            return;
        }

        const button = this.#button(event);
        if (button === null) {
            return;
        }

        // The combination is ours even when the button is disabled: the
        // webview's own Ctrl+S (save page) or Ctrl+P must not take over.
        event.preventDefault();
        // Nothing while a modal is open: its inputs are the document now.
        if (document.querySelector('dialog[open]') !== null) {
            return;
        }

        button()?.click();
    }

    #button(event: KeyboardEvent): ButtonGetter | null {
        // Caps Lock and Shift both turn the key upper case.
        const key = event.key.toLowerCase();

        if (event.shiftKey) {
            // Ctrl+Shift+S: Save as. No other Shift combination is ours.
            return key === 's' ? this.#buttons.saveAs : null;
        }

        switch (key) {
            case 's':
                return this.#buttons.save;
            case 'n':
                return this.#buttons.newFile;
            case 'p':
                return this.#buttons.print;
            case 'o':
                return this.#buttons.open;
            case 'm':
                return this.#buttons.switchMode;
            default:
                return null;
        }
    }
}
