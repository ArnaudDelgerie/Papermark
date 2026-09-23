import { confirmDialog } from '../utils/confirm-dialog';

export interface LeaveGuardI18n {
    confirm: string;
    cancel: string;
    continue: string;
}

interface LeaveGuardCallbacks {
    shouldConfirm: () => boolean;
    onLeave: () => void;
    onStay: () => void;
}

/**
 * Guards clicks on elements marked data-editor-leave-guard, links and JS
 * actions alike. The click is stopped before the element's own handlers or
 * navigation; once confirmed, the click is replayed, skipping the guard.
 */
export default class LeaveGuard {
    #i18n: LeaveGuardI18n;
    #callbacks: LeaveGuardCallbacks;
    #confirmed = false;
    #onClick = (event: MouseEvent): void => this.#guardLeave(event);

    constructor(i18n: LeaveGuardI18n, callbacks: LeaveGuardCallbacks) {
        this.#i18n = i18n;
        this.#callbacks = callbacks;
    }

    /** Capture phase, on window: covers the sidebar too, not just the editor element. */
    listen(): void {
        window.addEventListener('click', this.#onClick, true);
    }

    stop(): void {
        window.removeEventListener('click', this.#onClick, true);
    }

    #guardLeave(event: MouseEvent): void {
        const guarded = (event.target as Element | null)?.closest<HTMLElement>('[data-editor-leave-guard]');
        if (!guarded || this.#confirmed || !this.#callbacks.shouldConfirm()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        void confirmDialog({
            question: this.#i18n.confirm,
            cancelLabel: this.#i18n.cancel,
            continueLabel: this.#i18n.continue,
        }).then((confirmed) => {
            if (!confirmed) {
                this.#callbacks.onStay();

                return;
            }

            this.#callbacks.onLeave();
            // click() dispatches synchronously, so the flag only covers the replay.
            this.#confirmed = true;
            try {
                guarded.click();
            } finally {
                this.#confirmed = false;
            }
        });
    }
}
