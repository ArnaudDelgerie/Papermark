import { Controller } from '@hotwired/stimulus';
import { showToast } from '../utils/toast';

/**
 * The theme button of the file bar (EDITOR_SETTINGS.md). Every click applies
 * the next theme at once, on `<html data-theme>`: Crepe's theme is CSS on
 * that attribute, nothing else has to react. Saving is deferred: only the
 * last value goes to the server, after a short delay without a click. If it
 * fails, the last saved theme comes back. The theme is not part of the
 * editor state, so this doesn't go through the master.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['label'];

    static values = {
        current: String,
        themes: Array,
        labels: Object,
        url: String,
        token: String,
        delay: { type: Number, default: 600 },
        i18n: Object,
    };

    declare readonly hasLabelTarget: boolean;
    declare readonly labelTarget: HTMLElement;
    declare currentValue: string;
    declare readonly themesValue: string[];
    declare readonly labelsValue: Record<string, string>;
    declare readonly urlValue: string;
    declare readonly tokenValue: string;
    declare readonly delayValue: number;
    declare readonly i18nValue: { label?: string; failed?: string };

    #saved = '';
    #timer: ReturnType<typeof setTimeout> | undefined;

    connect(): void {
        this.#saved = this.currentValue;
    }

    disconnect(): void {
        clearTimeout(this.#timer);
    }

    next(): void {
        const themes = this.themesValue;
        const index = themes.indexOf(this.currentValue);
        this.#show(themes[(index + 1) % themes.length]);

        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => void this.#save(), this.delayValue);
    }

    #show(theme: string): void {
        this.currentValue = theme;
        document.documentElement.dataset.theme = theme;
        this.element.dataset.value = theme;

        const label = (this.i18nValue.label ?? 'Theme: {theme}').replace('{theme}', this.labelsValue[theme] ?? theme);
        this.element.title = label;
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = label;
        }
    }

    async #save(): Promise<void> {
        this.#timer = undefined;
        const theme = this.currentValue;
        const body = new FormData();
        body.append('theme', theme);

        try {
            const response = await fetch(this.urlValue, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.tokenValue }, body });
            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.genericErrors?.[0] || `Theme save failed: ${response.status}`);
            }
            this.#saved = theme;
        } catch (error) {
            console.error('Could not save the theme:', error);
            showToast('error', this.i18nValue.failed ?? 'The action failed, please try again');
            // Unless a later click has already moved on.
            if (this.#timer === undefined) {
                this.#show(this.#saved);
            }
        }
    }
}
