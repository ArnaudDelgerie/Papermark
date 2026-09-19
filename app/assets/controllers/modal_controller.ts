import { Controller } from '@hotwired/stimulus';

/**
 * Opens and closes a modal (Settings, Archive). A native <dialog> opened by
 * `showModal()`: Escape, the focus trap and the inert background come with
 * it. The frame inside it loads on the first opening and is never emptied.
 */
export default class extends Controller {
    static targets = ['dialog'];

    declare readonly dialogTarget: HTMLDialogElement;

    open(): void {
        if (!this.dialogTarget.open) {
            this.dialogTarget.showModal();
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
