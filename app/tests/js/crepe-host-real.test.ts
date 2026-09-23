import { afterEach, describe, expect, it, vi } from 'vitest';
import CrepeHost, { type CrepeI18n } from '../../assets/editor/crepe-host';
import EDITOR_I18N from '../contract/i18n/editor.json';

/**
 * FIL-01 (lot 05-markdown.md): unlike crepe-host.test.ts, Crepe is NOT
 * mocked here — this is the one place that runs the real Milkdown/ProseMirror
 * engine (feasible in jsdom) to prove editor.remove(remarkPreserveEmptyLinePlugin)
 * actually took effect, not just that it was called.
 *
 * FRT-02 and FRT-05 (lot 08) use the same place for the same reason: what an
 * image insertion does to the block's text, and what recreate() carries over,
 * only mean something against the real engine.
 */
const I18N: CrepeI18n = EDITOR_I18N;

function callbacks(): { onInsertImage: () => void; onCopyCode: (text: string) => void; onAiError: (error: Error) => void } {
    return { onInsertImage: vi.fn(), onCopyCode: vi.fn(), onAiError: vi.fn() };
}

/** A host mounted in the page, so the engine can read and write the selection. */
async function mountedHost(markdown: string): Promise<{ host: CrepeHost; root: HTMLElement; prosemirror: HTMLElement }> {
    const root = document.createElement('div');
    document.body.append(root);
    const host = new CrepeHost(root, I18N, callbacks());
    await host.create({ markdown, aiEnabled: false, aiProvider: undefined });
    const prosemirror = root.querySelector<HTMLElement>('.ProseMirror')!;

    return { host, root, prosemirror };
}

/**
 * Selects `from`..`to` in the first paragraph's text, the way a real drag
 * does: the view is focused first (ProseMirror ignores a selection it reads
 * on an unfocused view), then a DOM selection the engine reads back on
 * selectionchange, as it does in a browser.
 */
function select(prosemirror: HTMLElement, from: number, to = from): void {
    prosemirror.focus();
    const paragraph = prosemirror.querySelector('p')!;
    const text = paragraph.firstChild!;
    const range = document.createRange();
    range.setStart(text, from);
    range.setEnd(text, to);
    const selection = document.getSelection()!;
    selection.removeAllRanges();
    selection.addRange(range);
    document.dispatchEvent(new Event('selectionchange'));
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

describe('CrepeHost image insertions, with the real engine (FRT-02)', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('from the top bar, the paragraph keeps its text and gains the image', async () => {
        const { host, prosemirror } = await mountedHost('Hello');

        select(prosemirror, 5);
        host.insertImage('/document/image?path=%2Ffoo.png');

        // Crepe captions the block on its own ('1.00'): only the src is ours.
        const markdown = host.markdown();
        expect(markdown).toContain('Hello');
        expect(markdown).toContain('(/document/image?path=%2Ffoo.png)');
    });

    it('from the slash menu, the /image typed disappears', async () => {
        const { host, prosemirror } = await mountedHost('/image\n\nSecond');

        select(prosemirror, 6);
        host.insertImageFromSlashMenu('/document/image?path=%2Ffoo.png');

        // By lines: the URL contains '/image' as a substring, the typed
        // command was a whole line of its own.
        const markdown = host.markdown();
        expect(markdown.split('\n')).not.toContain('/image');
        expect(markdown).toContain('(/document/image?path=%2Ffoo.png)');
        expect(markdown).toContain('Second');
    });
});

describe('CrepeHost recreate(), with the real engine (FRT-05)', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('restores the selection it carried over', async () => {
        const { host, root, prosemirror } = await mountedHost('Hello');

        select(prosemirror, 2, 5);
        await host.recreate(true, () => undefined);

        // The rebuilt editor has its own .ProseMirror: the selection belongs
        // to it, not to the detached nodes the old DOM range still points at.
        const newProsemirror = root.querySelector<HTMLElement>('.ProseMirror')!;
        const selection = document.getSelection()!;
        expect(newProsemirror.contains(selection.anchorNode)).toBe(true);
        expect(selection.toString()).toBe('llo');
        expect(document.activeElement).toBe(newProsemirror);
    });
});
