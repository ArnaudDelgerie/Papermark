import { Controller } from '@hotwired/stimulus';

const DEFAULT_TIMEOUT = 4000;

export default class extends Controller {
    static values = { messages: Array };

    #onShow = null;

    connect() {
        this.#onShow = (event) => this.#render(event.detail);
        window.addEventListener('toast:show', this.#onShow);

        // Flashes from the last redirect (Symfony's addFlash, see base.html.twig):
        // rendered directly, not redispatched through toast:show, which is
        // reserved for runtime JS errors.
        for (const { type, message } of this.messagesValue) {
            this.#render({ type, message });
        }
    }

    disconnect() {
        window.removeEventListener('toast:show', this.#onShow);
    }

    #render({ type = 'success', message, timeout = DEFAULT_TIMEOUT }) {
        const toast = document.createElement('div');
        toast.className = `toast toast--${type}`;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.textContent = message;

        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'toast-close';
        close.setAttribute('aria-label', 'Close');
        close.textContent = '\u00d7';
        close.addEventListener('click', () => this.#dismiss(toast));

        toast.append(close);
        this.element.append(toast);

        requestAnimationFrame(() => toast.classList.add('toast--visible'));

        if (timeout > 0) {
            setTimeout(() => this.#dismiss(toast), timeout);
        }
    }

    #dismiss(toast) {
        toast.classList.remove('toast--visible');
        toast.addEventListener('transitionend', () => toast.remove(), { once: true });
    }
}
