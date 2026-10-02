import { $view } from '@milkdown/kit/utils';
import { codeBlockSchema } from '@milkdown/kit/preset/commonmark';
import type { NodeViewConstructor } from '@milkdown/kit/prose/view';

// Canonical names of refractor's common set, the one @milkdown/plugin-prism
// highlights (aliases such as "js" still highlight, they're just not listed).
const LANGUAGES = [
    'bash', 'c', 'cpp', 'csharp', 'css', 'diff', 'go', 'ini', 'java',
    'javascript', 'json', 'kotlin', 'less', 'lua', 'makefile', 'markdown',
    'markup', 'objectivec', 'perl', 'php', 'python', 'r', 'regex', 'ruby',
    'rust', 'sass', 'scss', 'sql', 'swift', 'typescript', 'yaml',
];

export interface CodeBlockViewOptions {
    noLanguageLabel: string;
    copyLabel: string;
    onCopy: (text: string) => void;
}

/**
 * Code block node view for the plain <pre> code blocks (Crepe's CodeMirror
 * feature is off, see crepe-host.ts). Same <pre><code> as the schema's
 * toDOM, with the code left to ProseMirror, plus a language <select> and a
 * copy button laid over the block's top border so they don't change the
 * block's height.
 */
export function codeBlockNodeView({ noLanguageLabel, copyLabel, onCopy }: CodeBlockViewOptions): NodeViewConstructor {
    return (initialNode, view, getPos) => {
        let node = initialNode;

        const dom = document.createElement('div');
        dom.className = 'code-block';

        const tools = document.createElement('div');
        tools.className = 'code-block-tools';
        tools.contentEditable = 'false';

        const select = document.createElement('select');
        select.className = 'code-block-language';

        const copy = document.createElement('button');
        copy.type = 'button';
        copy.className = 'code-block-copy';
        copy.title = copyLabel;
        copy.setAttribute('aria-label', copyLabel);
        const icon = document.createElement('span');
        icon.className = 'editor-filebar-icon editor-filebar-icon--copy';
        icon.setAttribute('aria-hidden', 'true');
        copy.append(icon);

        tools.append(select, copy);

        const pre = document.createElement('pre');
        const code = document.createElement('code');
        pre.append(code);
        dom.append(tools, pre);

        const render = (): void => {
            const language = (node.attrs.language as string | undefined) ?? '';
            pre.dataset.language = language;

            // Keep a language outside the list (an alias, or one prism doesn't
            // know) selectable so it isn't lost on display.
            const names = ['', ...LANGUAGES];
            if (!names.includes(language)) {
                names.push(language);
            }
            select.replaceChildren(...names.map((name) => {
                const option = document.createElement('option');
                option.value = name;
                option.textContent = name || noLanguageLabel;
                return option;
            }));
            select.value = language;
        };
        render();

        select.addEventListener('change', () => {
            const pos = getPos();
            if (pos === undefined) {
                return;
            }
            view.dispatch(view.state.tr.setNodeAttribute(pos, 'language', select.value));
            view.focus();
        });

        // Keeps the editor's selection where it is.
        copy.addEventListener('mousedown', (event) => event.preventDefault());
        copy.addEventListener('click', () => onCopy(node.textContent));

        return {
            dom,
            contentDOM: code,
            update(updated) {
                if (updated.type !== node.type) {
                    return false;
                }
                const languageChanged = updated.attrs.language !== node.attrs.language;
                node = updated;
                if (languageChanged) {
                    render();
                }
                return true;
            },
            stopEvent: (event) => tools.contains(event.target as Node | null),
            ignoreMutation: (mutation) => tools.contains(mutation.target),
        };
    };
}

/** Wraps {@link codeBlockNodeView} as the $view Milkdown plugin crepe-host.ts registers. */
export function createCodeBlockView(options: CodeBlockViewOptions) {
    return $view(codeBlockSchema.node, () => codeBlockNodeView(options));
}
