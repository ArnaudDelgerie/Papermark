import { Controller } from '@hotwired/stimulus';

/**
 * Opens and closes a modal (Settings, Archive). A native <dialog> opened by
 * `showModal()`: Escape, the focus trap and the inert background come with
 * it. The frame inside it loads on the first opening and is never emptied.
 *
 * UX-06, lot 10: every opening and closing is announced on window —
 * `modal:opened` after showModal(), `modal:closed` from the dialog's own
 * close event, which fires whatever closed it (the button, the backdrop,
 * Escape). Both carry the <dialog> so toast_controller knows where to
 * publish without hunting for dialog[open].
 */
export default class extends Controller {
    static targets = ['dialog'];

    declare readonly dialogTarget: HTMLDialogElement;

    /** Bound so disconnect() can remove it; see connect(). */
    readonly #onClose = (): void => {
        window.dispatchEvent(new CustomEvent('modal:closed', { detail: { dialog: this.dialogTarget } }));
    };

    connect(): void {
        this.dialogTarget.addEventListener('close', this.#onClose);
    }

    disconnect(): void {
        this.dialogTarget.removeEventListener('close', this.#onClose);
    }

    open(): void {
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
            window.dispatchEvent(new CustomEvent('modal:opened', { detail: { dialog: this.dialogTarget } }));
        }
    }

    close(): void {
        this.dialogTarget.close();
    }

    /** The dialog has no padding, so a click on itself is one on the backdrop. */
    backdrop(event: MouseEvent): void {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
