import { describe, expect, it, vi } from 'vitest';
import CrepeHost, { type CrepeI18n } from '../../assets/editor/crepe-host';
import EDITOR_I18N from '../contract/i18n/editor.json';

/**
 * FIL-01 (lot 05-markdown.md): unlike crepe-host.test.ts, Crepe is NOT
 * mocked here — this is the one place that runs the real Milkdown/ProseMirror
 * engine (feasible in jsdom) to prove editor.remove(remarkPreserveEmptyLinePlugin)
 * actually took effect, not just that it was called.
 */
const I18N: CrepeI18n = EDITOR_I18N;

function callbacks(): { onInsertImage: () => void; onCopyCode: (text: string) => void; onAiError: (error: Error) => void } {
    return { onInsertImage: vi.fn(), onCopyCode: vi.fn(), onAiError: vi.fn() };
}

describe('CrepeHost with the real Crepe engine', () => {
    it('a <br> the user wrote survives open then save intact', async () => {
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks());

        await host.create({ markdown: 'a<br>b\n\nSecond paragraph', aiEnabled: false, aiProvider: undefined });

        expect(host.markdown()).toBe('a<br>b\n\nSecond paragraph\n');
    });

    it('an empty non-final paragraph is no longer serialized as <br />', async () => {
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks());

        await host.create({ markdown: 'A\n\n\nB', aiEnabled: false, aiProvider: undefined });

        // Accepted consequence (FIL-01): the extra blank line itself isn't
        // kept, only the spurious <br /> it used to produce is gone.
        expect(host.markdown()).toBe('A\n\nB\n');
    });
});
