import { Controller } from '@hotwired/stimulus';
import { dispatchModeChangeRequest, dispatchModeUpdated } from '../utils/editor-mode.js';
import { showToast } from '../utils/toast.js';

/**
 * Owns which mode is showing. Both columns are already in the page, so the
 * switch happens on the spot and the round trip to the server only settles
 * what the editor should display (see EDITOR_REACTIVITY.md).
 */
export default class extends Controller {
    static values = { url: String, csrfToken: String, mode: String, i18n: Object };

    static targets = ['link'];

    async change(event) {
        event.preventDefault();

        const { mode } = event.currentTarget.dataset;
        if (mode === this.modeValue) {
            return;
        }

        const previous = this.modeValue;
        this.#show(mode);

        try {
            const { mode: current, path, content } = await this.#setMode(mode);
            dispatchModeUpdated(current, path, content);
        } catch (err) {
            console.error('Failed to switch mode:', err);
            showToast('error', err.message || this.i18nValue.failed || 'Could not switch mode');
            // The editor was never touched, so putting the columns and the nav
            // back on the previous mode is all there is to undo.
            this.#show(previous);
        }
    }

    #show(mode) {
        this.modeValue = mode;
        for (const link of this.linkTargets) {
            link.classList.toggle('is-active', link.dataset.mode === mode);
        }

        dispatchModeChangeRequest(mode);
    }

    async #setMode(mode) {
        const body = new FormData();
        body.append('mode', mode);

        const response = await fetch(this.urlValue, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body,
        });

        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `Could not switch mode: ${response.status}`);
        }

        return response.json();
    }
}
