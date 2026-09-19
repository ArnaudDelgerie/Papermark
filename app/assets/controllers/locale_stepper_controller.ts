import { Controller } from '@hotwired/stimulus';

/**
 * The language button of the file bar (EDITOR_SETTINGS.md). Each click aims
 * at the next language, shown at once (its code), and restarts a timer drawn
 * as a ring filling around the button; the aim is only taken into account
 * when no click has come for `delay`. Back on the current language, or
 * Escape, cancels: no timer, no reload. Taking it into account is a native
 * post that ends on a full reload, so the server renders everything
 * translated — behind the editor's leave guard, through the hidden submit
 * button of the form, which the guard intercepts like any other click.
 *
 * The languages come from the server; this works for any number of them.
 */
export default class extends Controller<HTMLElement> {
    static targets = ['button', 'code', 'ring', 'form', 'input', 'submit'];

    static values = {
        current: String,
        locales: Array,
        labels: Object,
        label: String,
        delay: { type: Number, default: 2000 },
    };

    declare readonly buttonTarget: HTMLButtonElement;
    declare readonly codeTarget: HTMLElement;
    declare readonly ringTarget: SVGElement;
    declare readonly inputTarget: HTMLInputElement;
    declare readonly submitTarget: HTMLButtonElement;
    declare readonly currentValue: string;
    declare readonly localesValue: string[];
    declare readonly labelsValue: Record<string, string>;
    declare readonly labelValue: string;
    declare readonly delayValue: number;

    #pending: string | null = null;
    #timer: ReturnType<typeof setTimeout> | undefined;

    disconnect(): void {
        clearTimeout(this.#timer);
    }

    step(): void {
        const locales = this.localesValue;
        const from = this.#pending ?? this.currentValue;
        const next = locales[(locales.indexOf(from) + 1) % locales.length];

        if (next === this.currentValue) {
            this.cancel();

            return;
        }

        this.#pending = next;
        this.#show(next);
        this.#restartRing();
        clearTimeout(this.#timer);
        this.#timer = setTimeout(() => this.#commit(), this.delayValue);
    }

    cancel(): void {
        clearTimeout(this.#timer);
        this.#timer = undefined;
        this.#pending = null;
        this.#show(this.currentValue);
        this.buttonTarget.classList.remove('is-pending');
    }

    keydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && this.#pending !== null) {
            event.preventDefault();
            this.cancel();
        }
    }

    #commit(): void {
        const locale = this.#pending;
        this.#timer = undefined;
        if (locale === null) {
            return;
        }

        this.inputTarget.value = locale;
        // The guard stops the click when there is unsaved work, and replays it
        // once confirmed: the form is then posted from there. Until then the
        // button shows the current language again — the page reloads if the
        // switch goes through.
        const proceeded = this.submitTarget.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        if (!proceeded) {
            this.cancel();
        }
    }

    #show(locale: string): void {
        this.codeTarget.textContent = locale.toUpperCase();
        const label = this.labelValue.replace('{language}', this.labelsValue[locale] ?? locale);
        this.buttonTarget.setAttribute('aria-label', label);
        this.buttonTarget.title = label;
    }

    /** From empty again: width to zero without transition, a reflow, then the transition. */
    #restartRing(): void {
        const ring = this.ringTarget;
        this.buttonTarget.classList.add('is-pending');
        ring.style.transition = 'none';
        ring.style.strokeDashoffset = '1';
        void ring.getBoundingClientRect();
        ring.style.transition = `stroke-dashoffset ${this.delayValue}ms linear`;
        ring.style.strokeDashoffset = '0';
    }
}
