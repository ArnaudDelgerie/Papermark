import { afterEach, describe, expect, it, vi } from 'vitest';
import { Schema } from '@milkdown/kit/prose/model';
import { EditorState } from '@milkdown/kit/prose/state';
import { DecorationSet, EditorView } from '@milkdown/kit/prose/view';
import { codeBlockNodeView, type CodeBlockViewOptions } from '../../assets/editor/code-block-view';

const schema = new Schema({
    nodes: {
        doc: { content: 'block+' },
        paragraph: { content: 'text*', group: 'block', toDOM: () => ['p', 0], parseDOM: [{ tag: 'p' }] },
        code_block: {
            content: 'text*',
            group: 'block',
            code: true,
            attrs: { language: { default: '' } },
            toDOM: () => ['pre', ['code', 0]],
            parseDOM: [{ tag: 'pre' }],
        },
        text: { group: 'inline' },
    },
});

const OPTIONS: CodeBlockViewOptions = { noLanguageLabel: 'Plain text', copyLabel: 'Copy code', onCopy: vi.fn() };

/** Mounts a bare ProseMirror view around a single code_block node, using our node view. */
function mount(text: string, language: string, options: CodeBlockViewOptions = OPTIONS): EditorView {
    const content = text === '' ? [] : [schema.text(text)];
    const doc = schema.node('doc', null, [schema.node('code_block', { language }, content)]);
    const place = document.createElement('div');
    document.body.append(place);

    return new EditorView(place, {
        state: EditorState.create({ schema, doc }),
        nodeViews: { code_block: codeBlockNodeView(options) },
    });
}

describe('codeBlockNodeView', () => {
    let view: EditorView | null = null;

    afterEach(() => {
        view?.destroy();
        view = null;
    });

    it('lists an empty entry labeled noLanguageLabel, then the languages, with the node value selected', () => {
        view = mount('const x = 1;', 'javascript');
        const select = view.dom.querySelector<HTMLSelectElement>('.code-block-language')!;
        const options = [...select.options];

        expect(options[0].value).toBe('');
        expect(options[0].textContent).toBe(OPTIONS.noLanguageLabel);
        expect(options.map((option) => option.value)).toContain('javascript');
        expect(select.value).toBe('javascript');
    });

    it('keeps a language outside the list selectable', () => {
        view = mount('a', 'brainfuck');
        const select = view.dom.querySelector<HTMLSelectElement>('.code-block-language')!;

        expect([...select.options].map((option) => option.value)).toContain('brainfuck');
        expect(select.value).toBe('brainfuck');
    });

    it('updates the node language attribute when the select changes', () => {
        view = mount('a', '');
        const select = view.dom.querySelector<HTMLSelectElement>('.code-block-language')!;

        select.value = 'python';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(view.state.doc.firstChild!.attrs.language).toBe('python');
    });

    it('calls onCopy with the block text on a copy click, and prevents the mousedown default', () => {
        const onCopy = vi.fn();
        view = mount('const x = 1;', 'javascript', { ...OPTIONS, onCopy });
        const copyButton = view.dom.querySelector<HTMLButtonElement>('.code-block-copy')!;

        const mousedown = new MouseEvent('mousedown', { bubbles: true, cancelable: true });
        copyButton.dispatchEvent(mousedown);
        expect(mousedown.defaultPrevented).toBe(true);

        copyButton.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(onCopy).toHaveBeenCalledWith('const x = 1;');
    });

    describe('update()', () => {
        it('refuses a node of another type, and redraws the list when the language changes', () => {
            const spec = codeBlockNodeView(OPTIONS);
            const place = document.createElement('div');
            const fakeView = { dispatch: vi.fn(), focus: vi.fn(), state: {} } as unknown as EditorView;
            const codeBlock = schema.node('code_block', { language: 'javascript' }, schema.text('a'));
            const nodeView = spec(codeBlock, fakeView, () => 0, [], DecorationSet.empty);
            place.append(nodeView.dom);

            const paragraph = schema.node('paragraph', null, schema.text('a'));
            expect(nodeView.update!(paragraph, [], DecorationSet.empty)).toBe(false);

            const select = nodeView.dom.querySelector<HTMLSelectElement>('.code-block-language')!;
            expect(select.value).toBe('javascript');

            const recolored = schema.node('code_block', { language: 'python' }, schema.text('a'));
            expect(nodeView.update!(recolored, [], DecorationSet.empty)).toBe(true);
            expect(select.value).toBe('python');
        });
    });

    describe('the toolbar', () => {
        it('is ignored by ProseMirror for events and mutations', () => {
            const spec = codeBlockNodeView(OPTIONS);
            const fakeView = { dispatch: vi.fn(), focus: vi.fn(), state: {} } as unknown as EditorView;
            const codeBlock = schema.node('code_block', { language: 'javascript' }, schema.text('a'));
            const nodeView = spec(codeBlock, fakeView, () => 0, [], DecorationSet.empty);
            const tools = nodeView.dom.querySelector('.code-block-tools')!;
            const select = tools.querySelector('select')!;
            const pre = nodeView.dom.querySelector('pre')!;

            expect(nodeView.stopEvent!({ target: select } as unknown as Event)).toBe(true);
            expect(nodeView.stopEvent!({ target: pre } as unknown as Event)).toBe(false);
            expect(nodeView.ignoreMutation!({ target: select } as never)).toBe(true);
            expect(nodeView.ignoreMutation!({ target: pre } as never)).toBe(false);
        });
    });
});
