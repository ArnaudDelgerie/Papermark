/**
 * The hub's close guards, frontend namespace (CONTRACT.md §7 `close_guard`,
 * lot 02 — gardes de fermeture): one guard per thing a window close would
 * lose. The hub asks its own native confirmation before closing a guarded
 * window; the guard is an identifier, never a message, and the dialog is
 * the hub's — nothing here is translated, nothing here is shown.
 *
 * The context proves the document: every committed load of the window's
 * main frame gets a fresh opaque one, fetched once per JS document (a
 * Turbo visit does not reload the document, the context stays valid) and
 * presented back with every register/remove. A hub without the
 * `close_guard` group refuses the context invoke: that is "no guard",
 * never a failure — the whole module degrades to doing nothing, without
 * a toast and without an exception towards the caller.
 */

/** The module's one context, or `null` once the hub refused it. */
let context: Promise<string | null> | null = null;
/** The refusal was warned once; further guards ask for nothing at all. */
let contextWarned = false;

function fetchContext(): Promise<string | null> {
    if (context !== null) {
        return context;
    }

    const core = window.__TAURI__?.core;
    if (!core?.invoke) {
        // Outside the hub there is nothing to ask: no guard, no warning —
        // a plain browser session is not a degraded hub.
        return Promise.resolve(null);
    }

    context = core.invoke<{ context: string } | string>('close_guard_context')
        .then((answer) => {
            const value = typeof answer === 'string' ? answer : answer?.context;
            if (typeof value !== 'string' || value === '') {
                throw new Error('The hub answered without a context.');
            }

            return value;
        })
        .catch(() => {
            if (!contextWarned) {
                contextWarned = true;
                console.warn('close_guard_context was refused — close guards are disabled for this document.');
            }

            return null;
        });

    return context;
}

/**
 * One guard, one id, one owner. `set()` is called with the current
 * "closing would lose something" state on every change of it; only a
 * transition (the value it last settled on differs) reaches the hub, so a
 * keystroke never costs an invoke and a repeated value never doubles one.
 *
 * The calls are queued on a promise chain, so a `remove` can never
 * overtake a `register` still in flight; the state the queue converges
 * on is that of the last `set()`, whatever changed while a call was in
 * flight. Every refusal is a warning in the console and nothing else:
 * the local state follows the wanted value and nothing is retried —
 * the guard is a protection in addition, its absence never blocks
 * anything.
 */
export default class CloseGuard {
    readonly #id: string;
    // What the hub was last told, or settled on without being told.
    #active = false;
    // What the last set() asked for.
    #wanted = false;
    #queue: Promise<void> = Promise.resolve();

    constructor(id: string) {
        this.#id = id;
    }

    set(active: boolean): void {
        if (active === this.#wanted) {
            return;
        }
        this.#wanted = active;
        this.#queue = this.#queue.then(() => this.#sync());
    }

    async #sync(): Promise<void> {
        // A later set() already corrected the wanted value while this
        // turn waited in the queue: there is no transition left to make.
        if (this.#active === this.#wanted) {
            return;
        }

        const core = window.__TAURI__?.core;
        if (!core?.invoke) {
            // No hub: the state settles on what was wanted, nothing else.
            this.#active = this.#wanted;

            return;
        }

        const document = await fetchContext();
        if (document === null) {
            // Refused context, or no context at all: same settling.
            this.#active = this.#wanted;

            return;
        }
        if (this.#active === this.#wanted) {
            // A set() that landed while the context was in flight made
            // this turn pointless — the queue holds a turn for that state.
            return;
        }

        // Frozen before the await below: a set() that lands while the
        // invoke is in flight must not make this turn settle on a state
        // it never asked the hub for.
        const target = this.#wanted;
        const command = target ? 'close_guard_register' : 'close_guard_remove';
        try {
            await core.invoke<null>(command, { context: document, id: this.#id });
        } catch (error) {
            console.warn(`${command} failed:`, error);
        }
        // The hub's answer, or its refusal: either way this state is
        // settled — the next transition starts from it.
        this.#active = target;
    }
}
