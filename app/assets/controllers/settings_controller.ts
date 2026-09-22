import { Controller } from '@hotwired/stimulus';
import { type SettingsError, emit, on, requested, failed, succeeded } from '../editor/events';
import { confirmDialog } from '../utils/confirm-dialog';
import { checkForUpdate as checkForUpdateIpc } from '../utils/tauri';
import { showToast } from '../utils/toast';

/**
 * The settings form, in the modal's frame (EDITOR_SETTINGS.md). Every field
 * saves the whole form by itself: `settings#autosave` restarts one timer,
 * shared by all the fields, and at its end the form goes to the master as
 * `do-save_settings`. The API keys are outside of the form, each with its own
 * actions (`do-set_key`, `do-delete_key`), and this controller follows their
 * answers. Nothing here calls the server itself.
 */
export default class extends Controller<HTMLFormElement> {
    static targets = ['updateResult', 'tab', 'panel', 'errors', 'errorList', 'activity', 'keyBlock', 'keyInput', 'keySave'];

    declare readonly updateResultTarget: HTMLElement;
    declare readonly tabTargets: HTMLElement[];
    declare readonly panelTargets: HTMLElement[];
    declare readonly hasErrorsTarget: boolean;
    declare readonly errorsTarget: HTMLElement;
    declare readonly errorListTarget: HTMLElement;
    declare readonly hasActivityTarget: boolean;
    declare readonly activityTarget: HTMLElement;
    declare readonly keyBlockTargets: HTMLElement[];
    declare readonly keyInputTargets: HTMLInputElement[];
    declare readonly keySaveTargets: HTMLButtonElement[];

    static values = {
        // The same for every field, radios included.
        delay: { type: Number, default: 600 },
        deleteLabel: String,
        confirmMessage: String,
        confirmQuestion: String,
        updateAvailable: String,
        upToDate: String,
        updateCheckFailed: String,
        updateCheckUnavailable: String,
    };

    declare readonly delayValue: number;
    declare readonly deleteLabelValue: string;
    declare readonly confirmMessageValue: string;
    declare readonly confirmQuestionValue: string;
    declare readonly updateAvailableValue: string;
    declare readonly upToDateValue: string;
    declare readonly updateCheckFailedValue: string;
    declare readonly updateCheckUnavailableValue: string;

    #timer: ReturnType<typeof setTimeout> | undefined;
    // Saves asked and not answered yet.
    #saving = 0;
    #unsubscribers: Array<() => void> = [];

    connect(): void {
        this.#unsubscribers = [
            on(succeeded('do-save_settings'), () => {
                this.#saved();
                this.#showErrors([]);
            }),
            on(failed('do-save_settings'), ({ action }) => {
                this.#saved();
                this.#showErrors(action.errors);
            }),
            on(succeeded('do-set_key'), ({ action }) => {
                const input = this.#keyInput(action.name);
                if (input) {
                    input.value = '';
                }
                this.#keySaving(action.name, false);
                this.#showKey(action.name, true);
            }),
            on(failed('do-set_key'), ({ action }) => this.#keySaving(action.name, false)),
            on(succeeded('do-delete_key'), ({ action }) => {
                this.#showKey(action.name, false);
                // The server deselects the provider whose key it lost.
                const radio = this.#providerRadios().find((candidate) => candidate.value === action.name);
                if (radio?.checked) {
                    radio.checked = false;
                    this.#syncSelection();
                }
            }),
        ];
    }

    disconnect(): void {
        clearTimeout(this.#timer);
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
    }

    /** Restarts the timer shared by all the fields. */
    autosave(): void {
        this.#syncSelection();
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#save(), this.delayValue);
    }

    /** Enter in a field: saves now instead of leaving the page for a post. */
    submit(event: Event): void {
        event.preventDefault();
        clearTimeout(this.#timer);
        this.#save();
    }

    #save(): void {
        this.#timer = undefined;
        this.#saving++;
        this.#busy();
        emit(requested('do-save_settings'), { action: { form: new FormData(this.element), url: this.element.getAttribute('action') ?? '' } });
    }

    #saved(): void {
        this.#saving = Math.max(0, this.#saving - 1);
        this.#busy();
    }

    /** The activity indicator of the form. */
    #busy(): void {
        const busy = this.#saving > 0;
        this.element.setAttribute('aria-busy', String(busy));
        if (this.hasActivityTarget) {
            this.activityTarget.hidden = !busy;
        }
    }

    #showErrors(errors: SettingsError[]): void {
        if (!this.hasErrorsTarget) {
            return;
        }
        this.errorListTarget.replaceChildren(
            ...errors.map(({ message }) => {
                const item = document.createElement('li');
                item.textContent = message;

                return item;
            }),
        );
        this.errorsTarget.hidden = errors.length === 0;
    }

    /** The provider legends show which radio is checked. */
    #syncSelection(): void {
        this.#providerRadios().forEach((radio) => {
            radio.closest('.settings-provider')?.classList.toggle('is-selected', radio.checked);
        });
    }

    #providerRadios(): HTMLInputElement[] {
        return [...this.element.querySelectorAll<HTMLInputElement>('input[type="radio"][name$="[selected]"]')];
    }

    // API keys --------------------------------------------------------------

    setKey(event: Event): void {
        event.preventDefault();
        const name = (event.currentTarget as HTMLElement).dataset.name ?? '';
        const key = this.#keyInput(name)?.value.trim() ?? '';
        if (key === '') {
            return;
        }

        this.#keySaving(name, true);
        emit(requested('do-set_key'), { action: { name, key } });
    }

    async deleteKey(event: Event): Promise<void> {
        event.preventDefault();
        const name = (event.currentTarget as HTMLElement).dataset.name ?? '';

        const confirmed = await confirmDialog({
            message: this.confirmMessageValue,
            question: this.confirmQuestionValue,
            continueLabel: this.deleteLabelValue,
        });
        if (confirmed) {
            emit(requested('do-delete_key'), { action: { name } });
        }
    }

    #keyInput(name: string): HTMLInputElement | undefined {
        return this.keyInputTargets.find((input) => input.dataset.name === name);
    }

    #keySaving(name: string, saving: boolean): void {
        const button = this.keySaveTargets.find((candidate) => candidate.dataset.name === name);
        if (button) {
            button.disabled = saving;
        }
    }

    /** Both displays are in the markup: the server chose one, this switches. */
    #showKey(name: string, hasKey: boolean): void {
        const block = this.keyBlockTargets.find((candidate) => candidate.dataset.name === name);
        block?.querySelector<HTMLElement>('.settings-key-set')?.toggleAttribute('hidden', !hasKey);
        block?.querySelector<HTMLElement>('.settings-key-new')?.toggleAttribute('hidden', hasKey);
    }

    // Tabs ------------------------------------------------------------------

    selectTab(event: Event): void {
        this.showTab((event.currentTarget as HTMLElement).dataset.tab);
    }

    /**
     * Left/Right/Home/End move focus between tabs (WAI-ARIA tabs pattern);
     * activating a tab always follows focus.
     */
    onTabKeydown(event: KeyboardEvent): void {
        const moves: Record<string, number> = { ArrowLeft: -1, ArrowRight: 1 };
        if (!(event.key in moves) && event.key !== 'Home' && event.key !== 'End') {
            return;
        }
        event.preventDefault();

        const tabs = this.tabTargets;
        const currentIndex = tabs.indexOf(event.target as HTMLElement);
        let nextIndex: number;
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

    showTab(name: string | undefined): void {
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.tab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
        });

        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.tab !== name;
        });
    }

    /**
     * Manual, on-click only (CONTRACT.md §7: the check is pull, never push —
     * no automatic or startup check on this side either).
     */
    async checkForUpdate(): Promise<void> {
        const result = await checkForUpdateIpc();

        if (result.status === 'ok') {
            this.updateResultTarget.textContent = result.update_available
                ? this.updateAvailableValue.replace('%version%', String(result.latest))
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
