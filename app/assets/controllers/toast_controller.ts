import { Controller } from '@hotwired/stimulus';
import type { ToastType } from '../utils/toast';

const DEFAULT_TIMEOUT = 4000;

interface Toast {
    type?: ToastType;
    message: string;
    timeout?: number;
}

export default class extends Controller<HTMLElement> {
    static values = { messages: Array, closeLabel: String };

    declare readonly messagesValue: Toast[];
    // The toast's close button label — always the server's (SET-05, lot 09).
    declare readonly closeLabelValue: string;

    #onShow = (event: Event): void => this.#render((event as CustomEvent<Toast>).detail);

    connect(): void {
        window.addEventListener('toast:show', this.#onShow);

        // Flashes from the last redirect (Symfony's addFlash, see base.html.twig):
        // rendered directly, not redispatched through toast:show, which is
        // reserved for runtime JS errors.
        for (const { type, message } of this.messagesValue) {
            this.#render({ type, message });
        }
    }

    disconnect(): void {
        window.removeEventListener('toast:show', this.#onShow);
    }

    #render({ type = 'success', message, timeout = DEFAULT_TIMEOUT }: Toast): void {
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
        this.element.append(toast);

        requestAnimationFrame(() => toast.classList.add('toast--visible'));

        if (timeout > 0) {
            setTimeout(() => this.#dismiss(toast), timeout);
        }
    }

    #dismiss(toast: HTMLElement): void {
        toast.classList.remove('toast--visible');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }
}
