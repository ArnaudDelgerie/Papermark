import { afterEach, describe, expect, it, vi } from 'vitest';
import { type Draft, clearDraft, readDraft, writeDraft } from '../../assets/editor/draft';

const STORAGE_KEY = 'editor.draft';

describe('the draft (lot 04-brouillon.md)', () => {
    afterEach(() => {
        sessionStorage.clear();
        vi.restoreAllMocks();
    });

    it('reads nothing when there is none', () => {
        expect(readDraft()).toBeNull();
    });

    it('round-trips what was written, path and revision included', () => {
        const draft: Draft = { path: '/notes/a.md', markdown: '# A, edited', revision: 'r0' };
        writeDraft(draft);

        expect(readDraft()).toEqual(draft);
    });

    it('round-trips an untitled draft, path and revision null', () => {
        const draft: Draft = { path: null, markdown: 'scratch', revision: null };
        writeDraft(draft);

        expect(readDraft()).toEqual(draft);
    });

    it('clearDraft() removes it', () => {
        writeDraft({ path: '/notes/a.md', markdown: 'x', revision: 'r0' });
        clearDraft();

        expect(readDraft()).toBeNull();
    });

    it('drops invalid JSON, and the entry with it', () => {
        sessionStorage.setItem(STORAGE_KEY, '{not json');

        expect(readDraft()).toBeNull();
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('drops a value that isn\'t shaped like a Draft, and the entry with it', () => {
        sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ path: '/notes/a.md' }));

        expect(readDraft()).toBeNull();
        expect(sessionStorage.getItem(STORAGE_KEY)).toBeNull();
    });

    it('read, write and clear all no-op when sessionStorage throws', () => {
        vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
            throw new Error('unavailable');
        });
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new Error('unavailable');
        });
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new Error('unavailable');
        });

        expect(() => writeDraft({ path: null, markdown: 'x', revision: null })).not.toThrow();
        expect(readDraft()).toBeNull();
        expect(() => clearDraft()).not.toThrow();
    });
});
