/**
 * The one document's unsaved text, under a single sessionStorage key: it
 * survives a page reload, not the app closing (lot 04-brouillon.md, FIL-04 —
 * a durable draft across restarts was ruled out there). `path` and
 * `revision` are null for an untitled document.
 */
export interface Draft {
    path: string | null;
    markdown: string;
    revision: string | null;
}

const STORAGE_KEY = 'editor.draft';

/**
 * Whatever is stored, or null if there is nothing, it can't be parsed, or it
 * isn't shaped like a Draft — an unreadable entry is dropped rather than
 * left to come back on the next read.
 */
export function readDraft(): Draft | null {
    try {
        const raw = sessionStorage.getItem(STORAGE_KEY);
        if (raw === null) {
            return null;
        }
        const parsed: unknown = JSON.parse(raw);
        if (isDraft(parsed)) {
            return parsed;
        }
    } catch {
        // Invalid JSON, or sessionStorage unavailable: no draft either way.
    }
    clearDraft();

    return null;
}

export function writeDraft(draft: Draft): void {
    try {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
    } catch {
        // sessionStorage unavailable (private mode, quota…): the draft just won't persist.
    }
}

export function clearDraft(): void {
    try {
        sessionStorage.removeItem(STORAGE_KEY);
    } catch {
        // ditto
    }
}

function isDraft(value: unknown): value is Draft {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const { path, markdown, revision } = value as Record<string, unknown>;

    return (path === null || typeof path === 'string')
        && typeof markdown === 'string'
        && (revision === null || typeof revision === 'string');
}
