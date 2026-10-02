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
 * Mirror of App\Service\EditorState::toArray(), copied by hand: nothing checks
 * they stay aligned. `readonly` is not sent by the routes; the master fills it
 * at hydration and keeps it across merges.
 */
export interface EditorState {
    mode: EditorMode;
    file: string | null;
    dir: string | null;
    readonly: boolean;
    ai_enabled: boolean;
    /** Saves after a delay without typing (EDITOR_AUTOSAVE.md), like the one below. */
    autosave: boolean;
    autosave_after_ai: boolean;
}

/** What each action asks for, as the child emits it. */
interface Requests {
    'nav-switch_mode': { mode: EditorMode };
    'nav-change_dir': { path: string };
    'nav-change_file': { path: string };
    'nav-new_file': Record<string, never>;
    'nav-refresh_dir': Record<string, never>;
    /** The path to open, folder or file alike: the server looks and answers `openMode`. */
    'nav-open_path': { path: string };
    /**
     * `revision` is what GET /editor/file gave with the content: save()
     * refuses to write when the file changed since (409). Absent means
     * Save as, or Écraser after a conflict — no control (lot 03).
     */
    'do-save': { path: string; content: string; revision?: string };
    'do-save_as': { path: string; content: string };
    'do-delete': { path: string };
    'do-rename': { path: string; name: string };
    /** The whole settings form, as the modal's controller reads it, with its own `action`. */
    'do-save_settings': { form: FormData; url: string };
    /**
     * The API key travels under `_password` (SEC-01, lot 09): the name the
     * server's request collector masks, so it never lands in the profiler.
     */
    'do-set_key': { name: string; _password: string };
    'do-delete_key': { name: string };
    /** The archive and the folder it is extracted into, both picked by the import block. */
    'do-import': { archive: string; parentDir: string };
}

/** What the server confirms, paths normalized. Never file content. */
interface Results {
    'nav-switch_mode': { mode: EditorMode };
    'nav-change_dir': { path: string };
    'nav-change_file': { path: string };
    'nav-new_file': Record<string, never>;
    'nav-refresh_dir': Record<string, never>;
    /**
     * `openMode` says what the path turned out to be: the state ended up on
     * a file (`single`) or on a folder (`dir`). Never `null`, unlike the
     * import: an open that succeeded always opened something.
     */
    'nav-open_path': { path: string; openMode: EditorMode };
    /** The renewed revision of what was written, even when nothing was. */
    'do-save':{ path: string; revision: string };
    'do-save_as': { path: string; revision: string };
    'do-delete': { path: string };
    'do-rename': { oldPath: string; newPath: string };
    'do-save_settings': Record<string, never>;
    'do-set_key': { name: string };
    'do-delete_key': { name: string };
    /**
     * `openMode` says what the archive gave to open: the state ended up on a
     * file (`single`), on a folder (`dir`), or was left as it was (`null`).
     */
    'do-import': { destination: string; openMode: EditorMode | null; ignoredEntries: string[] };
}

/** One message of the settings form the server refused, naming its field. */
export interface SettingsError {
    field: string;
    message: string;
}

/**
 * What a failure carries besides what was asked. An invalid settings form
 * answers 422 with its errors and no `error`, so the master shows no toast:
 * the modal shows them. A save answered by the server carries its status
 * and message: 409 is a conflict, and the editor asks Enregistrer sous or
 * Écraser instead of the master's toast. Empty for a technical failure
 * (that one has a toast).
 */
interface SaveFailure {
    status?: number;
    message?: string | null;
}

interface FailureExtras {
    'do-save': SaveFailure;
    'do-save_as': SaveFailure;
    'do-save_settings': { errors: SettingsError[] };
}

export type ActionName = keyof Requests;
export type NavAction = Extract<ActionName, `nav-${string}`>;

export type RequestOf<A extends ActionName> = Requests[A];
export type ResultOf<A extends ActionName> = Results[A];
/**
 * A failure may come without any server answer, so it can only repeat what
 * was asked — minus the markdown and the API key, which never travel back.
 */
export type FailureOf<A extends ActionName> = Omit<Requests[A], 'content' | '_password'> &
    (A extends keyof FailureExtras ? FailureExtras[A] : unknown);

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
    /**
     * The editor emits it once per connection, when its initial document is
     * settled — draft dialog included. `free` says whether opening something
     * now would lose work (the leave guard's own question, lot 4b: the
     * cold-start open). Later loads never repeat it.
     */
    'editor:ready': { free: boolean };
    /**
     * A synchronous query, answered by the editor: whether it holds unsaved
     * changes for that exact path. The delete confirmation asks before
     * showing its single dialog, so it can say both things at once (lot 03).
     * The emitter reads `unsaved` back once the event returned.
     */
    'editor:unsaved-file-query': { path: string; unsaved: boolean };
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
