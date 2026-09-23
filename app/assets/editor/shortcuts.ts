/**
 * Ctrl+S and Ctrl+N (FRT-10 + UX-03, lot 08): the only two shortcuts of the
 * lot. A single keydown on window, posed by editor_controller in connect()
 * and removed in disconnect(). The buttons do the work — a disabled one
 * ignores the click, and the leave guard on New asks about unsaved work —
 * so no state is duplicated here.
 */
export default class EditorShortcuts {
    readonly #buttons: { save: () => HTMLButtonElement | null; newFile: () => HTMLButtonElement | null };
    readonly #onKeyDown = (event: KeyboardEvent): void => this.#handle(event);

    constructor(buttons: { save: () => HTMLButtonElement | null; newFile: () => HTMLButtonElement | null }) {
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
        if (!(event.ctrlKey || event.metaKey) || event.altKey || event.shiftKey) {
            return;
        }

        // Caps Lock turns the key upper case; Shift is already ruled out.
        const key = event.key.toLowerCase();
        const button = key === 's'
            ? this.#buttons.save
            : key === 'n'
                ? this.#buttons.newFile
                : null;
        if (button === null) {
            return;
        }

        // The combination is ours even when the button is disabled: the
        // webview's own Ctrl+S (save page) must not take over.
        event.preventDefault();
        // Nothing while a modal is open: its inputs are the document now.
        if (document.querySelector('dialog[open]') !== null) {
            return;
        }

        button()?.click();
    }
}
