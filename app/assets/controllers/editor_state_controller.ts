import { Controller } from '@hotwired/stimulus';
import {
    type ActionName,
    type Anomaly,
    type EditorState,
    type FailureOf,
    type RequestOf,
    type ResultOf,
    type SettingsError,
    emit,
    failed,
    on,
    requested,
    succeeded,
} from '../editor/events';
import { showToast } from '../utils/toast';

interface Urls {
    state: string;
    mode: string;
    file: string;
    dir: string;
    refreshDir: string;
    save: string;
    delete: string;
    rename: string;
    settings: string;
    /** With a `__name__` placeholder (a `{name}` would come out URL-encoded): the provider. */
    setKey: string;
    deleteKey: string;
    import: string;
}

interface Tokens {
    mode: string;
    file: string;
    dir: string;
    settings: string;
    import: string;
}

interface Route {
    url: keyof Urls;
    method: 'POST' | 'DELETE';
    token: keyof Tokens;
}

const ROUTES: Record<ActionName, Route> = {
    'nav-switch_mode': { url: 'mode', method: 'POST', token: 'mode' },
    'nav-change_dir': { url: 'dir', method: 'POST', token: 'dir' },
    'nav-change_file': { url: 'file', method: 'POST', token: 'file' },
    'nav-new_file': { url: 'file', method: 'DELETE', token: 'file' },
    'nav-refresh_dir': { url: 'refreshDir', method: 'POST', token: 'dir' },
    'do-save': { url: 'save', method: 'POST', token: 'file' },
    'do-save_as': { url: 'save', method: 'POST', token: 'file' },
    'do-delete': { url: 'delete', method: 'POST', token: 'file' },
    'do-rename': { url: 'rename', method: 'POST', token: 'file' },
    'do-save_settings': { url: 'settings', method: 'POST', token: 'settings' },
    'do-set_key': { url: 'setKey', method: 'POST', token: 'settings' },
    'do-delete_key': { url: 'deleteKey', method: 'DELETE', token: 'settings' },
    'do-import': { url: 'import', method: 'POST', token: 'import' },
};

/** Every state route answers this, error included (S6). */
interface StateResponse {
    state?: Partial<EditorState>;
    action?: ResultOf<ActionName> & { errors?: SettingsError[] };
    error?: string;
}

/**
 * The master of the client state (EDITOR_REACTIVITY.md, S3-S8). It holds the
 * store and makes every write: it listens to each `…-requested`, calls the
 * route, merges the answer into the store, then emits `…-succeeded` or
 * `…-failed`. The store only ever changes from a server answer (S6).
 *
 * Children read the initial state through their `editor-state` outlet
 * (`this.editorStateOutlet.state`); after that, the events carry it.
 */
export default class extends Controller {
    static values = { state: Object, urls: Object, tokens: Object, i18n: Object };

    declare readonly stateValue: EditorState;
    declare readonly urlsValue: Urls;
    declare readonly tokensValue: Tokens;
    declare readonly i18nValue: { failed: string };

    #state!: EditorState;
    #inFlight = new Map<ActionName, AbortController>();
    #unsubscribers: Array<() => void> = [];

    get state(): Readonly<EditorState> {
        return this.#state;
    }

    initialize(): void {
        this.#state = { ...this.stateValue };
    }

    connect(): void {
        for (const name of Object.keys(ROUTES) as ActionName[]) {
            this.#unsubscribers.push(on(requested(name), ({ action }) => void this.#write(name, action)));
        }
        this.#unsubscribers.push(on('editor:state-anomaly-reported', ({ anomaly }) => void this.#resync(anomaly)));
    }

    disconnect(): void {
        this.#unsubscribers.forEach((unsubscribe) => unsubscribe());
        this.#unsubscribers = [];
        this.#inFlight.forEach((controller) => controller.abort());
        this.#inFlight.clear();
    }

    /**
     * `nav` actions: the last one wins, per action — the one still in flight
     * is dropped without any event, the next answer concludes (S8). `do`
     * actions are never dropped.
     */
    async #write(name: ActionName, action: RequestOf<ActionName>): Promise<void> {
        const route = ROUTES[name];
        const abort = new AbortController();
        if (name.startsWith('nav-')) {
            this.#inFlight.get(name)?.abort();
            this.#inFlight.set(name, abort);
        }

        let response: Response;
        let data: StateResponse | null;
        try {
            response = await fetch(this.#url(route, action), {
                method: route.method,
                headers: { 'X-CSRF-TOKEN': this.tokensValue[route.token] },
                body: route.method === 'POST' ? toFormData(action) : undefined,
                signal: abort.signal,
            });
            data = await response.json().catch(() => null);
        } catch (error) {
            if (abort.signal.aborted) {
                return;
            }
            console.error(`Request ${name} failed:`, error);
            this.#fail(name, action, null);

            return;
        } finally {
            if (this.#inFlight.get(name) === abort) {
                this.#inFlight.delete(name);
            }
        }

        if (abort.signal.aborted) {
            return;
        }

        if (data?.state) {
            this.#merge(data.state);
        }

        // `name` is the union of all actions here, so TypeScript can't pair
        // it with its payload: the pairing is typed where children emit and
        // listen, the master only relays what the route answered.
        if (response.ok && data?.state && data.action) {
            emit(succeeded(name), { state: this.#state, action: data.action } as never);

            return;
        }

        // A refusal that comes with the state and an action but no `error`
        // (an invalid settings form, 422) is for its asker to show, not a toast.
        // So is a save conflict: the editor asks Enregistrer sous or Écraser
        // (lot 03) — the dialog replaces the toast.
        const saveConflict = response.status === 409 && (name === 'do-save' || name === 'do-save_as');
        const quiet = saveConflict
            || (data?.state !== undefined && data.action !== undefined && data.error === undefined);
        this.#fail(name, action, data?.error ?? null, quiet, data?.action?.errors, response.status);
    }

    #fail(
        name: ActionName,
        action: RequestOf<ActionName>,
        message: string | null,
        quiet = false,
        errors?: SettingsError[],
        status?: number,
    ): void {
        if (!quiet) {
            showToast('error', message ?? this.i18nValue.failed);
        }
        const extras: Record<string, unknown> = {};
        if (name === 'do-save_settings') {
            extras.errors = errors ?? [];
        }
        if ((name === 'do-save' || name === 'do-save_as') && status !== undefined) {
            // What the editor's conflict dialog needs: the status that says
            // 409, and the server's own words for what happened.
            extras.status = status;
            extras.message = message;
        }
        emit(failed(name), { state: this.#state, action: { ...withoutSecrets(action), ...extras } });
    }

    /** `__name__` is the provider of a key action. */
    #url(route: Route, action: RequestOf<ActionName>): string {
        const url = this.urlsValue[route.url];

        return 'name' in action ? url.replace('__name__', encodeURIComponent(action.name)) : url;
    }

    /**
     * The read that found something gone already corrected the session; this
     * re-reads it so the store follows. Without an answer, the stale keys are
     * dropped locally — the server did the same.
     */
    async #resync(anomaly: Anomaly): Promise<void> {
        try {
            const response = await fetch(this.urlsValue.state);
            const data: StateResponse = await response.json();
            if (!response.ok || !data.state) {
                throw new Error(`HTTP ${response.status}`);
            }
            this.#merge(data.state);
        } catch (error) {
            console.error('Could not re-read the editor state:', error);
            this.#merge(Object.fromEntries(Object.keys(anomaly).map((key) => [key, null])));
        }

        emit('editor:state-resynced', { state: this.#state, anomaly });
    }

    /** Keys the answer lacks keep their value (`readonly`). */
    #merge(state: Partial<EditorState>): void {
        this.#state = { ...this.#state, ...state };
    }
}

function toFormData(action: object): FormData {
    // The settings form is sent as it is.
    if ('form' in action && action.form instanceof FormData) {
        return action.form;
    }

    const body = new FormData();
    for (const [key, value] of Object.entries(action)) {
        body.append(key, String(value));
    }

    return body;
}

function withoutSecrets<A extends ActionName>(action: RequestOf<A>): Omit<FailureOf<A>, 'errors'> {
    const copy: Record<string, unknown> = { ...action };
    delete copy.content;
    delete copy.key;

    return copy as Omit<FailureOf<A>, 'errors'>;
}
