import { Controller } from '@hotwired/stimulus';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { showToast } from '../utils/toast.js';
import { checkForUpdate as checkForUpdateIpc } from '../utils/tauri.js';

/**
 * Deleting an API key is fetch-based, like the rest of this app's write
 * actions — on success the page reloads so the removed badge and possible
 * provider deselection come from a fresh server render, along with the
 * "API key deleted" flash (see EDITOR_FIX.md).
 */
export default class extends Controller {
    static targets = ['updateResult', 'tab', 'panel'];

    static values = {
        csrfToken: String,
        deleteLabel: String,
        confirmMessage: String,
        confirmQuestion: String,
        updateAvailable: String,
        upToDate: String,
        updateCheckFailed: String,
        updateCheckUnavailable: String,
    };

    selectTab(event) {
        this.showTab(event.currentTarget.dataset.tab);
    }

    /**
     * Left/Right/Home/End move focus between tabs (WAI-ARIA tabs pattern);
     * activating a tab always follows focus.
     */
    onTabKeydown(event) {
        const moves = { ArrowLeft: -1, ArrowRight: 1 };
        if (!(event.key in moves) && event.key !== 'Home' && event.key !== 'End') {
            return;
        }
        event.preventDefault();

        const tabs = this.tabTargets;
        const currentIndex = tabs.indexOf(event.target);
        let nextIndex;
        if (event.key === 'Home') {
            nextIndex = 0;
        } else if (event.key === 'End') {
            nextIndex = tabs.length - 1;
        } else {
            nextIndex = (currentIndex + moves[event.key] + tabs.length) % tabs.length;
        }

        tabs[nextIndex].focus();
        this.showTab(tabs[nextIndex].dataset.tab);
    }

    showTab(name) {
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });

        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tab !== name;
        });
    }

    async deleteKey(event) {
        event.preventDefault();
        const { url } = event.currentTarget.dataset;

        const confirmed = await confirmDialog({
            message: this.confirmMessageValue,
            question: this.confirmQuestionValue,
            continueLabel: this.deleteLabelValue,
        });
        if (!confirmed) {
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Delete failed: ${response.status}`);
            }

            window.location.reload();
        } catch (err) {
            console.error('Failed to delete API key:', err);
            showToast('error', err.message || 'Failed to delete API key');
        }
    }

    /**
     * Manual, on-click only (CONTRACT.md §7: the check is pull, never push —
     * no automatic or startup check on this side either).
     */
    async checkForUpdate() {
        const result = await checkForUpdateIpc();

        if (result.status === 'ok') {
            this.updateResultTarget.textContent = result.update_available
                ? this.updateAvailableValue.replace('%version%', result.latest)
                : this.upToDateValue;
            return;
        }

        if (result.reason === 'local_source') {
            showToast('error', this.updateCheckUnavailableValue);
            return;
        }

        showToast('error', this.updateCheckFailedValue);
    }
}
