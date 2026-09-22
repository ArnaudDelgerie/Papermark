import { Crepe } from '@milkdown/crepe';
import { ai as aiFeature, abortAICmd, defaultAIIcon, useAIInstructionTooltipAPI } from '@milkdown/crepe/feature/ai';
import type { AIProvider } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx, editorViewCtx, editorViewOptionsCtx } from '@milkdown/kit/core';
import { imageBlockSchema } from '@milkdown/kit/component/image-block';
import { clearDiffReviewCmd, diffPluginKey } from '@milkdown/kit/plugin/diff';
import { addBlockTypeCommand, clearTextInCurrentBlockCommand } from '@milkdown/kit/preset/commonmark';
import { streamingPluginKey } from '@milkdown/kit/plugin/streaming';
import { DOMSerializer } from '@milkdown/kit/prose/model';
import { trailing } from '@milkdown/plugin-trailing';
import { prism } from '@milkdown/plugin-prism';
import { replaceAll } from '@milkdown/utils';
import { createCodeBlockView } from './code-block-view';

/**
 * The editor's own texts: what Crepe needs directly (placeholder, link,
 * slash menu, code block toolbar) and the AI panel's, without
 * `requestFailed` — that one only concerns AiClient, kept outside this
 * class. `editor_controller.ts`'s `I18n` extends this with its own texts.
 */
export interface CrepeI18n {
    placeholder: string;
    link: { confirm: string; inputPlaceholder: string };
    slashMenu: {
        text: string;
        paragraph: string;
        h1: string;
        h2: string;
        h3: string;
        h4: string;
        h5: string;
        h6: string;
        quote: string;
        divider: string;
        list: string;
        bulletList: string;
        orderedList: string;
        taskList: string;
        advanced: string;
        image: string;
        code: string;
        table: string;
    };
    codeBlock: { noLanguage: string; copy: string };
    ai: {
        askAi: string;
        instructionPlaceholder: string;
        suggestionsHeader: string;
        sendAsPromptHeader: string;
        sendAsPrompt: string;
        submitButton: string;
        listbox: string;
    };
}

interface CrepeHostCallbacks {
    onInsertImage: () => void;
    onCopyCode: (text: string) => void;
    onAiError: (error: Error) => void;
}

interface CreateOptions {
    markdown?: string;
    aiEnabled: boolean;
    aiProvider?: AIProvider;
}

/**
 * Owns the Crepe instance and is the only class importing Milkdown: creation,
 * serial recreation around a change of AI, destruction (CODE_REVIEW_3,
 * decision 1). `AiClient`, the Tauri file picker and the document state
 * (path, dirty, revision) stay with `editor_controller.ts`.
 */
export default class CrepeHost {
    #root: Element;
    #i18n: CrepeI18n;
    #callbacks: CrepeHostCallbacks;

    #crepe: Crepe | null = null;
    #aiEnabled = false;
    #recreation: Promise<string | null> | null = null;
    #changeListener: ((markdown: string) => void) | null = null;

    constructor(root: Element, i18n: CrepeI18n, callbacks: CrepeHostCallbacks) {
        this.#root = root;
        this.#i18n = i18n;
        this.#callbacks = callbacks;
    }

    get created(): boolean {
        return this.#crepe !== null;
    }

    /** What Crepe was created with. */
    get aiEnabled(): boolean {
        return this.#aiEnabled;
    }

    async create({ markdown = '', aiEnabled, aiProvider }: CreateOptions): Promise<void> {
        this.#aiEnabled = aiEnabled;
        this.#crepe = await this.#build(markdown, aiEnabled, aiProvider);
    }

    /**
     * Recreates Crepe around the same markdown for a change of `aiEnabled`,
     * one at a time: a change met while one is already running waits for it,
     * then compares again — the last call wins. Does nothing, and resolves
     * with `null`, if `aiEnabled` already matches what Crepe was created
     * with, or the host has no Crepe. Otherwise resolves with the markdown
     * that was carried over.
     *
     * `provider` is called once Crepe is destroyed but before the rebuild:
     * the controller uses it to close the old AiClient and open the new one
     * in that order (CODE_REVIEW_3, lot 04).
     */
    recreate(aiEnabled: boolean, provider: () => AIProvider | undefined): Promise<string | null> {
        const run = async (): Promise<string | null> => {
            await this.#recreation;
            if (aiEnabled === this.#aiEnabled || this.#crepe === null) {
                return null;
            }

            return this.#recreateNow(aiEnabled, provider);
        };
        const pending = run();
        this.#recreation = pending.finally(() => {
            if (this.#recreation === pending) {
                this.#recreation = null;
            }
        });

        return pending;
    }

    /** The recreation in progress, if any — for a file load that must land in the new Crepe. */
    async whenIdle(): Promise<void> {
        await this.#recreation;
    }

    // FRT-11, lot Front éditeur: destroy() is not awaited; the host can be
    // torn down while it is still cleaning up.
    destroy(): void {
        // eslint-disable-next-line @typescript-eslint/no-floating-promises
        this.#crepe?.destroy();
        this.#crepe = null;
    }

    markdown(): string {
        return this.#crepe?.getMarkdown() ?? '';
    }

    /**
     * Another document, not an edit of this one: a fresh ProseMirror state
     * (flush), so undo cannot bring the previous file back. Without flush,
     * in the hub's WebKitGTK (no overflow-anchor), ProseMirror keeps a
     * reference node in place across the replacement and scrolls every
     * parent a few pixels down.
     */
    replace(markdown: string): void {
        if (this.#crepe === null) {
            return;
        }
        // IA-04, lot 03: first and unconditional — no path may replace the
        // document and leave a generation running on the old one.
        this.discardAi();
        this.#crepe.editor.action(replaceAll(markdown, true));
        this.#wrapScroll();
    }

    /** Rebound on every (re)creation: called with the markdown on every change. */
    onChange(listener: (markdown: string) => void): void {
        this.#changeListener = listener;
    }

    setEditable(editable: boolean): void {
        const prosemirror = this.#root.querySelector('.ProseMirror');
        prosemirror?.setAttribute('contenteditable', editable ? 'true' : 'false');
    }

    focus(): void {
        this.#root.querySelector<HTMLElement>('.ProseMirror')?.focus();
    }

    isAiBusy(): boolean {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return false;
        }
        const view = editor.ctx.get(editorViewCtx);
        const streaming = streamingPluginKey.getState(view.state);
        const diff = diffPluginKey.getState(view.state);
        if ((streaming?.active ?? false) || (diff?.active ?? false)) {
            return true;
        }

        // The Crepe session (instruct/abort), read in test mode: without a
        // dispatch, the command only says whether it would run (IA-04, lot 03).
        const commands = editor.ctx.get(commandsCtx);
        const sessionActive = commands.get(abortAICmd.key)({ keep: false })(view.state);

        return sessionActive;
    }

    // Aborting ends the provider's generator, whose finally tells the worker to stop.
    discardAi(): void {
        if (!this.#aiEnabled) {
            return;
        }

        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return;
        }

        editor.action((ctx) => {
            const commands = ctx.get(commandsCtx);
            commands.call(abortAICmd.key, { keep: false });
            if (diffPluginKey.getState(ctx.get(editorViewCtx).state)?.active) {
                commands.call(clearDiffReviewCmd.key);
            }
        });
    }

    /**
     * Picked paths are always absolute and never rewritten to relative,
     * whether the document is new or already open — see EDITOR_IMAGES.md.
     */
    insertImage(src: string): void {
        if (this.#crepe === null) {
            return;
        }
        this.#crepe.editor.action((ctx) => {
            const commands = ctx.get(commandsCtx);
            const imageBlock = imageBlockSchema.type(ctx);
            commands.call(clearTextInCurrentBlockCommand.key);
            commands.call(addBlockTypeCommand.key, {
                nodeType: imageBlock,
                attrs: { src },
            });
        });
    }

    /**
     * Prints a serialized copy of the document instead of the editor DOM:
     * schema toDOM output (plain h1/p/ul/pre/table/img), none of Crepe's
     * node views or controls. See styles/print.css. Mounting it, and
     * removing it, stays with the controller.
     */
    printCopy(): HTMLElement | null {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return null;
        }

        const { state } = editor.ctx.get(editorViewCtx);
        const copy = document.createElement('div');
        copy.className = 'print-copy document';
        copy.append(DOMSerializer.fromSchema(state.schema).serializeFragment(state.doc.content));

        return copy;
    }

    async #recreateNow(aiEnabled: boolean, provider: () => AIProvider | undefined): Promise<string> {
        const previous = this.#crepe!;
        const markdown = previous.getMarkdown();

        // A generation in progress dies with the Crepe it runs in.
        this.discardAi();

        this.#crepe = null;
        await previous.destroy();
        // Crepe leaves its empty container behind; the next one makes its own.
        this.#root.querySelectorAll(':scope > .milkdown').forEach((container) => container.remove());

        const aiProvider = provider();

        this.#aiEnabled = aiEnabled;
        this.#crepe = await this.#build(markdown, aiEnabled, aiProvider);

        return markdown;
    }

    async #build(markdown: string, aiEnabled: boolean, aiProvider?: AIProvider): Promise<Crepe> {
        const t = this.#i18n;
        const { onInsertImage, onCopyCode, onAiError } = this.#callbacks;

        const crepe = new Crepe({
            root: this.#root,
            defaultValue: markdown,
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
                            // TopBarItem has no label; the "more" menu only ever
                            // renders the icon (askAi is unused, as it always was).
                            more.addItem('ai', {
                                icon: defaultAIIcon,
                                active: () => false,
                                onRun: (ctx) => {
                                    const api = useAIInstructionTooltipAPI(ctx);
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

        this.#wrapScroll();

        crepe.on((listener) => {
            listener.markdownUpdated((_ctx, changed) => {
                this.#changeListener?.(changed);
            });
        });

        return crepe;
    }

    /**
     * Wraps .ProseMirror so the scrollable area extends past it, over the
     * surrounding padding/desk background too, not just the editable sheet.
     */
    #wrapScroll(): void {
        const prosemirror = this.#root.querySelector('.ProseMirror');
        if (!prosemirror || prosemirror.parentElement?.classList.contains('editor-content')) {
            return;
        }
        const content = document.createElement('div');
        content.className = 'editor-content';
        prosemirror.replaceWith(content);
        content.appendChild(prosemirror);
    }
}
