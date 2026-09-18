/**
 * The contract of the editor's state system (EDITOR_REACTIVITY.md, S1-S8):
 * the client-side state, every event name with its payload, and the only
 * two functions allowed to fire or listen to them. A misspelled name or an
 * incomplete payload is a compile error.
 *
 * Events are named `editor:{nav|do}-{action}-{requested|succeeded|failed}`.
 * `…-requested` carries what a child asks for; `…-succeeded` and `…-failed`
 * carry the state and the action as the server normalized it (realpath).
 * The name says what happened, the payload says where the state ends up:
 * never deduce the action from a state difference.
 */

export type EditorMode = 'single' | 'dir';

/**
 * Mirror of App\Editor\EditorState::toArray(), copied by hand: nothing checks
 * they stay aligned. `readonly` and `ai_enabled` are not sent by the routes
 * yet; the master fills them at hydration and keeps them across merges.
 */
export interface EditorState {
    mode: EditorMode;
    file: string | null;
    dir: string | null;
    readonly: boolean;
    ai_enabled: boolean;
}

/** What each action asks for, as the child emits it. */
interface Requests {
    'nav-switch_mode': { mode: EditorMode };
    'nav-change_dir': { path: string };
    'nav-change_file': { path: string };
    'nav-new_file': Record<string, never>;
    'do-save': { path: string; content: string };
    'do-save_as': { path: string; content: string };
    'do-delete': { path: string };
    'do-rename': { path: string; name: string };
}

/** What the server confirms, paths normalized. Never file content. */
interface Results {
    'nav-switch_mode': { mode: EditorMode };
    'nav-change_dir': { path: string };
    'nav-change_file': { path: string };
    'nav-new_file': Record<string, never>;
    'do-save': { path: string };
    'do-save_as': { path: string };
    'do-delete': { path: string };
    'do-rename': { oldPath: string; newPath: string };
}

export type ActionName = keyof Requests;
export type NavAction = Extract<ActionName, `nav-${string}`>;

export type RequestOf<A extends ActionName> = Requests[A];
export type ResultOf<A extends ActionName> = Results[A];
/**
 * A failure may come without any server answer, so it can only repeat what
 * was asked — minus the markdown, which never travels back.
 */
export type FailureOf<A extends ActionName> = Omit<Requests[A], 'content'>;

/**
 * The old values of the state properties a read found stale (the new state
 * only says `null`): `{file: '/gone.md'}`.
 */
export type Anomaly = Partial<Record<'file' | 'dir', string>>;

export type EditorEvents = {
    [A in ActionName as `editor:${A}-requested`]: { action: RequestOf<A> };
} & {
    [A in ActionName as `editor:${A}-succeeded`]: { state: EditorState; action: ResultOf<A> };
} & {
    [A in ActionName as `editor:${A}-failed`]: { state: EditorState; action: FailureOf<A> };
} & {
    /** A child's read found the state pointing at something gone. */
    'editor:state-anomaly-reported': { anomaly: Anomaly };
    /** The master re-read the state after an anomaly. */
    'editor:state-resynced': { state: EditorState; anomaly: Anomaly };
};

export type EventName = keyof EditorEvents;

export function requested<A extends ActionName>(action: A): `editor:${A}-requested` {
    return `editor:${action}-requested`;
}

export function succeeded<A extends ActionName>(action: A): `editor:${A}-succeeded` {
    return `editor:${action}-succeeded`;
}

export function failed<A extends ActionName>(action: A): `editor:${A}-failed` {
    return `editor:${action}-failed`;
}

export function emit<K extends EventName>(name: K, detail: EditorEvents[K]): void {
    window.dispatchEvent(new CustomEvent(name, { detail }));
}

/** Returns the function that unsubscribes. */
export function on<K extends EventName>(name: K, handler: (detail: EditorEvents[K]) => void): () => void {
    const listener = (event: Event): void => handler((event as CustomEvent<EditorEvents[K]>).detail);
    window.addEventListener(name, listener);

    return () => window.removeEventListener(name, listener);
}
