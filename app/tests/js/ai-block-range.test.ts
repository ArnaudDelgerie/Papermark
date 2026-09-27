import { afterEach, describe, expect, it } from 'vitest';
import { Editor, commandsCtx, defaultValueCtx, editorViewCtx, rootCtx } from '@milkdown/kit/core';
import { commonmark } from '@milkdown/kit/preset/commonmark';
import { endStreamingCmd, pushChunkCmd, startStreamingCmd, streaming } from '@milkdown/kit/plugin/streaming';
import { TextSelection } from '@milkdown/kit/prose/state';
import { getMarkdown } from '@milkdown/utils';
import { aiBlockRange } from '../../assets/editor/ai-block-range';

/**
 * An AI answer replacing a selection, against the real streaming plugin:
 * what Crepe's runAICmd does (start on the selection, push, end), without
 * a Crepe AI session, which Vitest cannot drive.
 */
async function editor(markdown: string, withPlugin: boolean): Promise<Editor> {
    const root = document.createElement('div');
    document.body.append(root);
    const built = Editor.make()
        .config((ctx) => {
            ctx.set(rootCtx, root);
            ctx.set(defaultValueCtx, markdown);
        })
        .use(commonmark)
        .use(streaming);
    if (withPlugin) {
        built.use(aiBlockRange);
    }

    return built.create();
}

/** Selects the text of the `index`-th top-level block, start to end — as a drag over it does. */
function selectBlockText(ed: Editor, index: number, lastIndex = index): void {
    const view = ed.ctx.get(editorViewCtx);
    const { doc } = view.state;
    let from = 0;
    let to = 0;
    doc.forEach((node, offset, i) => {
        if (i === index) {
            from = offset + 1;
        }
        if (i === lastIndex) {
            to = offset + 1 + node.content.size;
        }
    });
    view.dispatch(view.state.tr.setSelection(TextSelection.create(doc, from, to)));
}

function answer(ed: Editor, text: string): string {
    const commands = ed.ctx.get(commandsCtx);
    commands.call(startStreamingCmd.key, { insertAt: 'selection' });
    commands.call(pushChunkCmd.key, text);
    commands.call(endStreamingCmd.key);

    return ed.action(getMarkdown()).trim();
}

describe('aiBlockRange', () => {
    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('without it, a translated heading lands inside the old one as "## ..."', async () => {
        const ed = await editor('## Titre\n\nTexte', false);
        selectBlockText(ed, 0, 1);

        expect(answer(ed, '## Title\n\nText')).toBe('## ## Title\n\nText');
    });

    it('a translated heading replaces the old one as a heading', async () => {
        const ed = await editor('Avant\n\n## Titre\n\nTexte\n\nAprès', true);
        selectBlockText(ed, 1, 2);

        expect(answer(ed, '## Title\n\nText')).toBe('Avant\n\n## Title\n\nText\n\nAprès');
    });

    it('a single selected heading keeps its level', async () => {
        const ed = await editor('## Titre\n\nTexte', true);
        selectBlockText(ed, 0);

        expect(answer(ed, '## Title')).toBe('## Title\n\nTexte');
    });

    it('a selection inside a paragraph still merges inline', async () => {
        const ed = await editor('Un mot ici', true);
        const view = ed.ctx.get(editorViewCtx);
        view.dispatch(view.state.tr.setSelection(TextSelection.create(view.state.doc, 4, 7)));

        expect(answer(ed, 'word')).toBe('Un word ici');
    });
});
