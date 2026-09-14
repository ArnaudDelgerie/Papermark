import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['csrfToken', 'providerRadio'];

    async submit(event) {
        event.preventDefault();

        const formData = new FormData(this.element);
        const csrfToken = this.csrfTokenTarget.value;

        try {
            const response = await fetch('/settings/ai', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken },
                body: formData,
            });

            if (response.ok) {
                window.location.reload();
            } else {
                const data = await response.json().catch(() => ({}));
                this.#toast('error', data.error || 'Save failed');
            }
        } catch (err) {
            this.#toast('error', err.message || 'Save failed');
        }
    }

    #toast(type, message) {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type, message } }));
    }
}
