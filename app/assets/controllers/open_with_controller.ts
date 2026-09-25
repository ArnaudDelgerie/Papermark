import { Controller } from '@hotwired/stimulus';
import { emit, on } from '../editor/events';
import { basename } from '../editor/file-entries';
import { type OpenRequest, ackOpenRequest, readPendingOpenRequests, subscribeOpenFiles } from '../utils/open-files';
import { showPath } from '../utils/path-label';

/**
 * The box's own texts (components.open_with, lot 4b): the question names the
 * proposed path, the ignored mention counts the paths the box does not
 * offer — both rendered by the controller, so the server cannot pick the
 * plural form.
 */
export interface OpenWithI18n {
    question: string;
    ignored: { count_one: string; count_other: string };
}

/**
 * The « Ouvrir avec » box (lot 4b): what the hub delivers while the app runs
 * waits here for a click — nothing opens by itself — and what it delivered at
 * the cold start opens directly if the editor is free, which counts as an
 * acceptance. One request is shown at a time: a newer one replaces it, the
 * replaced one is dismissed. The wire itself lives in utils/open-files.ts;
 * the opening is the master's `nav-open_path` (lot 4a).
 *
 * Startup subscribes first and reads after: a request landing in between
 * rings the bell, which would be missed the other way around. After that,
 * reads happen only on the bell — never on a poll — one at a time: a bell
 * that rings during a read collapses into one more read at the end.
 */
export default class extends Controller<HTMLElement> {
    static values = {
        i18n: Object,
    };

    static targets = ['question', 'path', 'ignored'];

    declare readonly i18nValue: OpenWithI18n;

    declare readonly hasQuestionTarget: boolean;
    declare readonly questionTarget: HTMLElement;
    declare readonly hasPathTarget: boolean;
    declare readonly pathTarget: HTMLElement;
    declare readonly hasIgnoredTarget: boolean;
    declare readonly ignoredTarget: HTMLElement;

    /** The request currently shown, the only one the buttons act on. */
    #shown: OpenRequest | null = null;
    /** editor:ready's answer: the first read waits for the editor, none after. */
    #ready!: Promise<{ free: boolean }>;
    #resolveReady!: (answer: { free: boolean }) => void;
    #offReady: (() => void) | null = null;
    #unsubscribe: (() => void) | null = null;
    /** Whether the first read cycle ran: only it can open without a click. */
    #initialDone = false;
    // One read at a time: a bell during a read asks for one more, at the end.
    #running = false;
    #again = false;
    // Bumped by disconnect(): an await that returns to a torn-down controller
    // stops there, and unsubscribes whatever it got.
    #generation = 0;

    connect(): void {
        void this.#connect();
    }

    async #connect(): Promise<void> {
        // Synchronously, before any await: Stimulus connects the editor in
        // the same pass, and its ready event only comes after awaits.
        this.#ready = new Promise((resolve) => (this.#resolveReady = resolve));
        this.#offReady = on('editor:ready', ({ free }) => this.#resolveReady({ free }));

        const generation = this.#generation;
        const unsubscribe = await subscribeOpenFiles(() => void this.#drain());
        if (this.#generation !== generation) {
            unsubscribe?.();
            this.#offReady?.();
            this.#offReady = null;

            return;
        }
        if (unsubscribe === null) {
            // Outside the hub: no bell will ever ring, the box stays hidden.
            return;
        }
        this.#unsubscribe = unsubscribe;

        void this.#drain();
    }

    disconnect(): void {
        this.#generation++;
        this.#offReady?.();
        this.#offReady = null;
        this.#unsubscribe?.();
        this.#unsubscribe = null;
        this.#shown = null;
    }

    /**
     * One read cycle, serialized (`#running` / `#again`): the first waits for
     * editor:ready, then reads the pending requests and shows — or, at the
     * cold start with a free editor, opens — the newest. The shown request is
     * re-read untouched: only a decision retires it.
     */
    async #drain(): Promise<void> {
        if (this.#running) {
            this.#again = true;

            return;
        }
        this.#running = true;

        try {
            const generation = this.#generation;
            const { free } = await this.#ready;
            if (this.#generation !== generation) {
                return;
            }

            // The request shown when the read starts: a click during the read
            // retires it, but the answer may still list it — it must not come
            // back in the box.
            const shownId = this.#shown?.id;
            const requests = await readPendingOpenRequests();
            if (this.#generation !== generation) {
                return;
            }

            // Whatever comes next ends the first cycle, empty read included.
            const firstCycle = !this.#initialDone;
            this.#initialDone = true;

            const fresh = requests.filter((request) => request.id !== shownId);
            if (fresh.length === 0) {
                return;
            }

            const latest = fresh[fresh.length - 1];
            if (firstCycle && free) {
                // The cold start's direct open counts as an acceptance: ack
                // every request read, then open the newest's first path.
                await Promise.all(requests.map((request) => ackOpenRequest(request.id)));
                emit('editor:nav-open_path-requested', { action: { path: latest.paths[0] } });

                return;
            }

            // A newer request replaces the shown one, which is dismissed; a
            // request nobody looks at is dismissed the same way.
            const replaced = this.#shown?.id;
            this.#shown = null;
            await Promise.all([
                ...(replaced === undefined ? [] : [ackOpenRequest(replaced)]),
                ...fresh.slice(0, -1).map((request) => ackOpenRequest(request.id)),
            ]);
            this.#show(latest);
        } finally {
            this.#running = false;
            if (this.#again) {
                this.#again = false;
                void this.#drain();
            }
        }
    }

    /** Fills the box for one request: its first path's name, path, and the count of the ones not offered. */
    #show(request: OpenRequest): void {
        this.#shown = request;
        const path = request.paths[0] ?? '';
        if (this.hasQuestionTarget) {
            this.questionTarget.textContent = this.i18nValue.question.replace('{name}', basename(path));
        }
        if (this.hasPathTarget) {
            showPath(this.pathTarget, path);
        }
        const ignored = request.paths.length - 1;
        if (this.hasIgnoredTarget) {
            if (ignored > 0) {
                const form = ignored === 1 ? this.i18nValue.ignored.count_one : this.i18nValue.ignored.count_other;
                this.ignoredTarget.textContent = form.replace('{count}', String(ignored));
                this.ignoredTarget.hidden = false;
            } else {
                this.ignoredTarget.hidden = true;
            }
        }
        this.element.hidden = false;
    }

    /**
     * « Ouvrir », already past the leave guard (the button carries
     * data-editor-leave-guard, the guard replays the click once confirmed).
     * The ack comes first: a reload between the two loses at worst the
     * request, it never reopens it.
     */
    async accept(): Promise<void> {
        const request = this.#shown;
        if (request === null) {
            return;
        }
        this.#shown = null;
        this.element.hidden = true;
        await ackOpenRequest(request.id);
        emit('editor:nav-open_path-requested', { action: { path: request.paths[0] } });
    }

    /** « Ignorer »: the request is retired, nothing opens. */
    dismiss(): void {
        const request = this.#shown;
        if (request === null) {
            return;
        }
        this.#shown = null;
        this.element.hidden = true;
        void ackOpenRequest(request.id);
    }
}
