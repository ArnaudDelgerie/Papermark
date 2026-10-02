import { Controller } from '@hotwired/stimulus';
import type { ToastType } from '../utils/toast';

/** UX-16, lot 10: successes leave on their own, errors stay until closed. */
const SUCCESS_TIMEOUT = 4000;

interface Toast {
    type?: ToastType;
    message: string;
    timeout?: number;
}

/** `modal:opened` / `modal:closed`, from modal_controller. */
interface ModalEvent extends CustomEvent {
    detail: { dialog: HTMLDialogElement };
}

export default class extends Controller<HTMLElement> {
    static values = { messages: Array, closeLabel: String };

    declare readonly messagesValue: Toast[];
    // The toast's close button label — always the server's (SET-05, lot 09).
    declare readonly closeLabelValue: string;

    /**
     * Where the next toast lands: this controller's own element, or the
     * zone of the open modal (UX-06, lot 10). Kept current by the modal
     * events, never read back from the DOM.
     */
    #zone: HTMLElement = this.element;

    #onShow = (event: Event): void => this.#render((event as CustomEvent<Toast>).detail);

    #onModalOpened = (event: Event): void => {
        const { dialog } = (event as ModalEvent).detail;
        // A toast already in the main zone would sit under the modal's
        // inert backdrop: it goes, accepted (a toast in a modal goes with
        // the modal for the same reason).
        this.element.replaceChildren();
        this.#zone = dialog.querySelector('.toast-container') ?? this.element;
    };

    #onModalClosed = (event: Event): void => {
        // The modal's toasts go with it: an error has no timer, and would
        // otherwise still be there at the next opening.
        (event as ModalEvent).detail.dialog.querySelector('.toast-container')?.replaceChildren();
        this.#zone = this.element;
    };

    connect(): void {
        window.addEventListener('toast:show', this.#onShow);
        window.addEventListener('modal:opened', this.#onModalOpened);
        window.addEventListener('modal:closed', this.#onModalClosed);

        // Flashes from the last redirect (Symfony's addFlash, see base.html.twig):
        // rendered directly, not redispatched through toast:show, which is
        // reserved for runtime JS errors.
        for (const { type, message } of this.messagesValue) {
            this.#render({ type, message });
        }
    }

    disconnect(): void {
        window.removeEventListener('toast:show', this.#onShow);
        window.removeEventListener('modal:opened', this.#onModalOpened);
        window.removeEventListener('modal:closed', this.#onModalClosed);
    }

    #render({ type = 'success', message, timeout }: Toast): void {
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.textContent = message;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', this.closeLabelValue);
        close.textContent = '\u00d7';
        close.addEventListener('click', () => this.#dismiss(toast));

        toast.append(close);
        this.#zone.append(toast);

        requestAnimationFrame(() => toast.classList.add('toast--visible'));

        // An explicit timeout overrides the type's default; 0 means none.
        const delay = timeout ?? (type === 'error' ? 0 : SUCCESS_TIMEOUT);
        if (delay > 0) {
            this.#armTimeout(toast, delay);
        }
    }

    /**
     * UX-16, lot 10: the timer only runs while the toast is neither
     * hovered nor focused, so a message being read or reached outlives its
     * count. Enter and focus can both fire: paused is idempotent.
     */
    #armTimeout(toast: HTMLElement, delay: number): void {
        const dismiss = (): void => this.#dismiss(toast);
        let timer = window.setTimeout(dismiss, delay);
        let remaining = delay;
        let startedAt = performance.now();
        let paused = false;

        const pause = (): void => {
            if (paused) {
                return;
            }
            paused = true;
            window.clearTimeout(timer);
            remaining -= performance.now() - startedAt;
        };
        const resume = (): void => {
            if (!paused) {
                return;
            }
            paused = false;
            startedAt = performance.now();
            timer = window.setTimeout(dismiss, Math.max(remaining, 0));
        };

        toast.addEventListener('mouseenter', pause);
        toast.addEventListener('mouseleave', resume);
        toast.addEventListener('focusin', pause);
        toast.addEventListener('focusout', resume);
    }

    #dismiss(toast: HTMLElement): void {
        toast.classList.remove('toast--visible');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }
}
