import { Crepe } from '@milkdown/crepe';
import { ai as aiFeature, defaultAIIcon } from '@milkdown/crepe/feature/ai';
import { editorViewCtx, editorViewOptionsCtx } from '@milkdown/kit/core';
import { trailing } from '@milkdown/plugin-trailing';
import { oneDark } from '@codemirror/theme-one-dark';
import { crepeLightTheme } from './codemirror-light-theme.js';
import { isLightTheme } from '../utils/theme.js';

const DEFAULT_I18N = {
    placeholder: 'Start writing…',
    link: { confirm: 'Confirm', inputPlaceholder: 'Paste link…' },
    slashMenu: {
        text: 'Text',
        paragraph: 'Text',
        h1: 'Heading 1',
        h2: 'Heading 2',
        h3: 'Heading 3',
        h4: 'Heading 4',
        h5: 'Heading 5',
        h6: 'Heading 6',
        quote: 'Quote',
        divider: 'Divider',
        list: 'List',
        bulletList: 'Bullet List',
        orderedList: 'Ordered List',
        taskList: 'Task List',
        advanced: 'Advanced',
        image: 'Image',
        code: 'Code',
        table: 'Table',
    },
    ai: {
        askAi: 'Ask AI',
        instructionPlaceholder: 'Tell AI what to do with the selection…',
        suggestionsHeader: 'SUGGESTIONS',
        sendAsPromptHeader: 'SEND AS PROMPT',
        sendAsPrompt: 'Ask AI:',
        submitButton: 'Send prompt',
        listbox: 'AI suggestions',
    },
};

/**
 * Crepe's heading (paragraph/H1-H6) dropdown is `position: absolute; top:
 * 100%` under its button, inside the top bar row we scroll horizontally
 * instead of letting it wrap (`overflow: auto hidden` in editor.css) — so it
 * gets clipped under that row instead of showing over the document.
 * `position: fixed` on the dropdown escapes that clipping on a
 * spec-compliant engine, but this app's target webview (WebKitGTK) still
 * clips a fixed descendant of an ancestor that is *actively* scrolling
 * (only once the top bar's content overflows and needs the horizontal
 * scroll it was added for — hence it looked fixed at full window width but
 * broke again at a narrower one). Toggling the row's own overflow to
 * `visible` while the dropdown is open sidesteps that engine quirk entirely
 * and needs no positioning math, at the cost of the row briefly allowing
 * overflow (invisible in practice: the user's attention is on the open
 * dropdown, and `flex-wrap: nowrap` still stops it from wrapping).
 */
function attachHeadingDropdownOverflowFix(root) {
    const topBar = root.querySelector('.milkdown-top-bar');
    if (!topBar) return;

    new MutationObserver(() => {
        const isOpen = !!topBar.querySelector('.top-bar-heading-dropdown');
        topBar.style.overflow = isOpen ? 'visible' : '';
    }).observe(topBar, { childList: true, subtree: true });
}

/**
 * Builds and creates a Crepe editor instance, isolating the milkdown/crepe
 * setup (features, feature configs, i18n defaults) from the Stimulus
 * controller. Image insertion and the AI feature are still driven by the
 * controller: it owns the hub file picker and the AI provider/CSRF/URL
 * wiring, so their behavior is passed in rather than reimplemented here.
 */
export default class EditorFactory {
    /**
     * @param {Object} options
     * @param {Element} options.root
     * @param {string} [options.defaultValue]
     * @param {Object} [options.i18n] Partial translations; missing keys fall back to DEFAULT_I18N.
     * @param {(ctx: import('@milkdown/kit/ctx').Ctx) => void} options.onInsertImage
     * @param {boolean} [options.aiEnabled]
     * @param {Function} [options.aiProvider] Required when aiEnabled is true.
     * @param {(error: Error) => void} [options.onAiError]
     * @returns {Promise<Crepe>}
     */
    static async create({ root, defaultValue = '', i18n = {}, onInsertImage, aiEnabled = false, aiProvider, onAiError }) {
        const t = {
            placeholder: i18n.placeholder ?? DEFAULT_I18N.placeholder,
            link: {
                confirm: i18n.link?.confirm ?? DEFAULT_I18N.link.confirm,
                inputPlaceholder: i18n.link?.inputPlaceholder ?? DEFAULT_I18N.link.inputPlaceholder,
            },
            slashMenu: {
                text: i18n.slashMenu?.text ?? DEFAULT_I18N.slashMenu.text,
                paragraph: i18n.slashMenu?.paragraph ?? DEFAULT_I18N.slashMenu.paragraph,
                h1: i18n.slashMenu?.h1 ?? DEFAULT_I18N.slashMenu.h1,
                h2: i18n.slashMenu?.h2 ?? DEFAULT_I18N.slashMenu.h2,
                h3: i18n.slashMenu?.h3 ?? DEFAULT_I18N.slashMenu.h3,
                h4: i18n.slashMenu?.h4 ?? DEFAULT_I18N.slashMenu.h4,
                h5: i18n.slashMenu?.h5 ?? DEFAULT_I18N.slashMenu.h5,
                h6: i18n.slashMenu?.h6 ?? DEFAULT_I18N.slashMenu.h6,
                quote: i18n.slashMenu?.quote ?? DEFAULT_I18N.slashMenu.quote,
                divider: i18n.slashMenu?.divider ?? DEFAULT_I18N.slashMenu.divider,
                list: i18n.slashMenu?.list ?? DEFAULT_I18N.slashMenu.list,
                bulletList: i18n.slashMenu?.bulletList ?? DEFAULT_I18N.slashMenu.bulletList,
                orderedList: i18n.slashMenu?.orderedList ?? DEFAULT_I18N.slashMenu.orderedList,
                taskList: i18n.slashMenu?.taskList ?? DEFAULT_I18N.slashMenu.taskList,
                advanced: i18n.slashMenu?.advanced ?? DEFAULT_I18N.slashMenu.advanced,
                image: i18n.slashMenu?.image ?? DEFAULT_I18N.slashMenu.image,
                code: i18n.slashMenu?.code ?? DEFAULT_I18N.slashMenu.code,
                table: i18n.slashMenu?.table ?? DEFAULT_I18N.slashMenu.table,
            },
            ai: {
                askAi: i18n.ai?.askAi ?? DEFAULT_I18N.ai.askAi,
                instructionPlaceholder: i18n.ai?.instructionPlaceholder ?? DEFAULT_I18N.ai.instructionPlaceholder,
                suggestionsHeader: i18n.ai?.suggestionsHeader ?? DEFAULT_I18N.ai.suggestionsHeader,
                sendAsPromptHeader: i18n.ai?.sendAsPromptHeader ?? DEFAULT_I18N.ai.sendAsPromptHeader,
                sendAsPrompt: i18n.ai?.sendAsPrompt ?? DEFAULT_I18N.ai.sendAsPrompt,
                submitButton: i18n.ai?.submitButton ?? DEFAULT_I18N.ai.submitButton,
                listbox: i18n.ai?.listbox ?? DEFAULT_I18N.ai.listbox,
            },
        };

        const crepe = new Crepe({
            root,
            defaultValue,
            features: {
                [Crepe.Feature.Toolbar]: false,
                [Crepe.Feature.TopBar]: true,
                [Crepe.Feature.BlockEdit]: true,
            },
            featureConfigs: {
                [Crepe.Feature.Cursor]: {
                    virtual: false,
                },
                [Crepe.Feature.Placeholder]: {
                    text: t.placeholder,
                    mode: 'doc',
                },
                [Crepe.Feature.LinkTooltip]: {
                    confirmButton: t.link.confirm,
                    inputPlaceholder: t.link.inputPlaceholder,
                },
                [Crepe.Feature.BlockEdit]: {
                    textGroup: {
                        label: t.slashMenu.text,
                        text: { label: t.slashMenu.paragraph },
                        h1: { label: t.slashMenu.h1 },
                        h2: { label: t.slashMenu.h2 },
                        h3: { label: t.slashMenu.h3 },
                        h4: { label: t.slashMenu.h4 },
                        h5: { label: t.slashMenu.h5 },
                        h6: { label: t.slashMenu.h6 },
                        quote: { label: t.slashMenu.quote },
                        divider: { label: t.slashMenu.divider },
                    },
                    listGroup: {
                        label: t.slashMenu.list,
                        bulletList: { label: t.slashMenu.bulletList },
                        orderedList: { label: t.slashMenu.orderedList },
                        taskList: { label: t.slashMenu.taskList },
                    },
                    advancedGroup: {
                        label: t.slashMenu.advanced,
                        image: { label: t.slashMenu.image },
                        codeBlock: { label: t.slashMenu.code },
                        table: { label: t.slashMenu.table },
                    },
                    // Replaces the default "Image" action (which opens Crepe's own
                    // upload/placeholder UI) with the hub's file picker, see EDITOR_IMAGES.md.
                    buildMenu: (builder) => {
                        const advanced = builder.getGroup('advanced');
                        const imageItem = advanced.group.items.find((item) => item.key === 'image');
                        if (imageItem) {
                            imageItem.onRun = onInsertImage;
                        }
                    },
                },
                [Crepe.Feature.CodeMirror]: {
                    // Crepe defaults this to oneDark regardless of the app's
                    // theme (see EDITOR_THEME.md); pick explicitly instead.
                    theme: isLightTheme() ? crepeLightTheme : oneDark,
                },
                [Crepe.Feature.TopBar]: {
                    buildTopBar: (builder) => {
                        // Same replacement as the slash menu's "Image" entry: the hub's
                        // file picker instead of Crepe's own upload/placeholder UI.
                        const insert = builder.getGroup('insert');
                        const imageItem = insert.group.items.find((item) => item.key === 'image');
                        if (imageItem) {
                            imageItem.onRun = onInsertImage;
                        }

                        const formatting = builder.getGroup('formatting');
                        const codeItem = formatting.group.items.find(
                            (item) => item.key === 'code'
                        );
                        formatting.group.items = formatting.group.items.filter(
                            (item) => item.key !== 'code'
                        );

                        const block = builder.getGroup('block');
                        block.group.items = block.group.items.filter(
                            (item) => item.key !== 'math'
                        );
                        if (codeItem) {
                            block.group.items.unshift(codeItem);
                        }

                        if (aiEnabled) {
                            const more = builder.getGroup('more');
                            more.addItem('ai', {
                                icon: defaultAIIcon,
                                label: t.ai.askAi,
                                active: () => false,
                                onRun: (ctx) => {
                                    const api = ctx.get('aiInstructionTooltipAPI');
                                    const view = ctx.get(editorViewCtx);
                                    const { from, to } = view.state.selection;
                                    api.show(from, to);
                                },
                            });
                        }
                    },
                },
            },
        });

        crepe.addFeature((editor) => {
            editor.use(trailing);
        });

        if (aiEnabled) {
            crepe.addFeature(aiFeature, {
                provider: aiProvider,
                instructionPlaceholder: t.ai.instructionPlaceholder,
                suggestionsHeaderLabel: t.ai.suggestionsHeader,
                sendAsPromptHeaderLabel: t.ai.sendAsPromptHeader,
                sendAsPromptLabel: t.ai.sendAsPrompt,
                submitButtonLabel: t.ai.submitButton,
                listboxLabel: t.ai.listbox,
                // Crepe prefixes the message ("AI provider error: ..."); show the original one.
                onError: onAiError,
            });
        }

        // Shared typography with the print copy, see styles/document.css.
        crepe.editor.config((ctx) => {
            ctx.update(editorViewOptionsCtx, (prev) => ({
                ...prev,
                attributes: { class: 'document' },
            }));
        });

        await crepe.create();

        attachHeadingDropdownOverflowFix(root);

        return crepe;
    }
}
