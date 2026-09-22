import { Crepe } from '@milkdown/crepe';
import { ai as aiFeature, defaultAIIcon } from '@milkdown/crepe/feature/ai';
import { editorViewCtx, editorViewOptionsCtx } from '@milkdown/kit/core';
import { trailing } from '@milkdown/plugin-trailing';
import { prism } from '@milkdown/plugin-prism';
import { createCodeBlockView } from './code-block-view.js';

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
     * @param {import('../controllers/editor_controller').I18n} options.i18n The whole of Editor::getI18n(): every key required.
     * @param {(ctx: import('@milkdown/kit/ctx').Ctx) => void} options.onInsertImage
     * @param {(text: string) => void} options.onCopyCode Copy button of a code block.
     * @param {boolean} [options.aiEnabled]
     * @param {Function} [options.aiProvider] Required when aiEnabled is true.
     * @param {(error: Error) => void} [options.onAiError]
     * @returns {Promise<Crepe>}
     */
    static async create({ root, defaultValue = '', i18n, onInsertImage, onCopyCode, aiEnabled = false, aiProvider, onAiError }) {
        const t = i18n;

        const crepe = new Crepe({
            root,
            defaultValue,
            features: {
                [Crepe.Feature.Toolbar]: false,
                [Crepe.Feature.TopBar]: true,
                [Crepe.Feature.BlockEdit]: true,
                // Plain <pre> code blocks (see code-block-view.ts): CodeMirror's
                // lazy mount/teardown makes the page jump in WebKitGTK. LaTeX
                // requires CodeMirror, so it goes with it.
                [Crepe.Feature.CodeMirror]: false,
                [Crepe.Feature.Latex]: false,
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
            // Syntax colors on the plain <pre> code blocks, as decorations
            // (colors only, no layout change), see editor.css.
            editor.use(prism);
            editor.use(createCodeBlockView({
                noLanguageLabel: t.codeBlock.noLanguage,
                copyLabel: t.codeBlock.copy,
                onCopy: onCopyCode,
            }));
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

        return crepe;
    }
}
