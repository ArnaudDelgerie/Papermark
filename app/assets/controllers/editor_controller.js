import { Controller } from '@hotwired/stimulus';
import { Crepe } from '@milkdown/crepe';
import { ai as aiFeature, defaultAIIcon } from '@milkdown/crepe/feature/ai';
import { EditorStatus, editorViewCtx, editorViewOptionsCtx } from '@milkdown/kit/core';
import { DOMSerializer } from '@milkdown/kit/prose/model';
import { trailing } from '@milkdown/plugin-trailing';
import { replaceAll } from '@milkdown/utils';

export default class extends Controller {
    static values = {
        csrfToken: String,
        fileCsrfToken: String,
        aiCsrfToken: String,
        aiConfig: {
            type: Object,
            default: { enabled: false },
        },
        readonly: { type: Boolean, default: false },
        i18n: {
            type: Object,
            default: {
                placeholder: 'Start writing…',
                link: { confirm: 'Confirm', inputPlaceholder: 'Paste link…' },
                toggle: { edit: 'Edit', readonly: 'Read only' },
                a4: 'A4',
                full_width: 'Full width',
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
                toast: {
                    saved: 'File saved',
                    savedAs: 'File saved as {name}',
                    opened: 'File opened',
                    copiedMarkdown: 'Markdown copied to clipboard',
                },
            },
        },
    };

    static targets = ['saveButton', 'saveAsButton', 'printButton', 'copyMarkdownButton', 'a4Button', 'toggleButton'];

    #crepe = null;
    #currentPath = null;
    #isReadonly = false;
    #isA4 = true;
    #lastScrollTop = 0;
    #scrollTarget = null;
    #onScroll = null;
    #printCopy = null;
    #onBeforePrint = () => this.#mountPrintCopy();
    #onAfterPrint = () => this.#removePrintCopy();

    connect() {
        this.#isReadonly = this.readonlyValue;
        this.#isA4 = this.element.classList.contains('is-a4');
        this.#crepe = new Crepe({
            root: this.element,
            defaultValue: '',
            features: {
                [Crepe.Feature.Toolbar]: false,
                [Crepe.Feature.TopBar]: true,
                [Crepe.Feature.BlockEdit]: true,
            },
            featureConfigs: {
                [Crepe.Feature.Cursor]: {
                    virtual: false,
                },
                [Crepe.Feature.ImageBlock]: {
                    onUpload: (file) => this.#uploadFile(file),
                    blockUploadPlaceholderText: '',
                    inlineUploadPlaceholderText: '',
                },
                [Crepe.Feature.Placeholder]: {
                    text: this.i18nValue.placeholder,
                    mode: 'doc',
                },
                [Crepe.Feature.LinkTooltip]: {
                    confirmButton: this.i18nValue.link.confirm,
                    inputPlaceholder: this.i18nValue.link.inputPlaceholder,
                },
                [Crepe.Feature.BlockEdit]: {
                    textGroup: {
                        label: this.i18nValue.slashMenu.text,
                        text: { label: this.i18nValue.slashMenu.paragraph },
                        h1: { label: this.i18nValue.slashMenu.h1 },
                        h2: { label: this.i18nValue.slashMenu.h2 },
                        h3: { label: this.i18nValue.slashMenu.h3 },
                        h4: { label: this.i18nValue.slashMenu.h4 },
                        h5: { label: this.i18nValue.slashMenu.h5 },
                        h6: { label: this.i18nValue.slashMenu.h6 },
                        quote: { label: this.i18nValue.slashMenu.quote },
                        divider: { label: this.i18nValue.slashMenu.divider },
                    },
                    listGroup: {
                        label: this.i18nValue.slashMenu.list,
                        bulletList: { label: this.i18nValue.slashMenu.bulletList },
                        orderedList: { label: this.i18nValue.slashMenu.orderedList },
                        taskList: { label: this.i18nValue.slashMenu.taskList },
                    },
                    advancedGroup: {
                        label: this.i18nValue.slashMenu.advanced,
                        image: { label: this.i18nValue.slashMenu.image },
                        codeBlock: { label: this.i18nValue.slashMenu.code },
                        table: { label: this.i18nValue.slashMenu.table },
                    },
                },
                [Crepe.Feature.TopBar]: {
                    buildTopBar: (builder) => {
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

                        if (this.#isAiEnabled()) {
                            const more = builder.getGroup('more');
                            more.addItem('ai', {
                                icon: defaultAIIcon,
                                label: 'Ask AI',
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

        this.#crepe.addFeature((editor) => {
            editor.use(trailing);
        });

        if (this.#isAiEnabled()) {
            this.#crepe.addFeature(aiFeature, {
                provider: this.#createAIProvider(),
                // Crepe prefixes the message ("AI provider error: ..."); show the original one.
                onError: (error) => this.#toast('error', error.cause?.message ?? error.message),
            });
        }

        this.#crepe.on((listener) => {
            listener.markdownUpdated((_ctx, markdown, prevMarkdown) => {
                const removed = this.#diffImageUrls(prevMarkdown, markdown);
                removed.forEach((url) => this.#deleteImage(url));
                this.#updateSaveButton(markdown);
                this.#updatePrintButton(markdown);
                this.#updateCopyMarkdownButton(markdown);
            });
        });

        // Shared typography with the print copy, see styles/document.css.
        this.#crepe.editor.config((ctx) => {
            ctx.update(editorViewOptionsCtx, (prev) => ({
                ...prev,
                attributes: { class: 'document' },
            }));
        });

        this.#crepe.create().then(() => this.#relocateAiTooltip());

        window.addEventListener('beforeprint', this.#onBeforePrint);
        window.addEventListener('afterprint', this.#onAfterPrint);

        if (this.#isReadonly) {
            this.#applyReadonlyState();
        } else {
            this.#setupScrollHide();
        }

        this.#updateSaveButton('');
        this.#updatePrintButton('');
        this.#updateCopyMarkdownButton('');

        if (this.hasA4ButtonTarget) {
            this.a4ButtonTarget.textContent = this.#isA4
                ? this.i18nValue.full_width ?? 'Full width'
                : this.i18nValue.a4 ?? 'A4';
        }
    }

    toggleReadonly() {
        this.#isReadonly = !this.#isReadonly;
        this.#applyReadonlyState();
    }

    toggleA4() {
        this.#isA4 = !this.#isA4;
        this.element.classList.toggle('is-a4', this.#isA4);

        if (this.hasA4ButtonTarget) {
            this.a4ButtonTarget.textContent = this.#isA4
                ? this.i18nValue.full_width ?? 'Full width'
                : this.i18nValue.a4 ?? 'A4';
        }
    }

    #applyReadonlyState() {
        const prosemirror = this.element.querySelector('.ProseMirror');
        if (prosemirror) {
            prosemirror.setAttribute('contenteditable', this.#isReadonly ? 'false' : 'true');
        }

        if (this.#isReadonly) {
            this.element.classList.add('is-readonly');
            this.element.classList.remove('is-toolbar-hidden');
            if (this.#scrollTarget && this.#onScroll) {
                this.#scrollTarget.removeEventListener('scroll', this.#onScroll);
            }
        } else {
            this.element.classList.remove('is-readonly');
            this.#setupScrollHide();
        }

        if (this.#isReadonly) {
            if (this.hasSaveButtonTarget) {
                this.saveButtonTarget.disabled = true;
            }
            if (this.hasSaveAsButtonTarget) {
                this.saveAsButtonTarget.disabled = true;
            }
        } else {
            this.#updateSaveButton(this.#crepe?.getMarkdown());
        }

        if (this.hasToggleButtonTarget) {
            this.toggleButtonTarget.textContent = this.#isReadonly
                ? this.i18nValue.toggle?.edit ?? 'Edit'
                : this.i18nValue.toggle?.readonly ?? 'Read only';
        }
    }

    disconnect() {
        if (this.#scrollTarget && this.#onScroll) {
            this.#scrollTarget.removeEventListener('scroll', this.#onScroll);
        }
        window.removeEventListener('beforeprint', this.#onBeforePrint);
        window.removeEventListener('afterprint', this.#onAfterPrint);
        this.#removePrintCopy();
        this.#crepe?.destroy();
        this.#crepe = null;
    }

    async openFile() {
        const path = await this.#pickPath('file');
        if (path === null) {
            return;
        }

        const formData = new FormData();
        formData.append('path', path);

        try {
            const response = await fetch('/file/open', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Open failed: ${response.status}`);
            }

            const { content } = await response.json();
            this.#currentPath = path;
            this.#crepe.editor.action(replaceAll(content));
            this.#updateSaveButton(this.#crepe.getMarkdown());
            this.#toast('success', this.i18nValue.toast?.opened ?? 'File opened');
        } catch (err) {
            console.error('Failed to open file:', err);
            this.#toast('error', err.message || 'Failed to open file');
        }
    }

    async saveFile() {
        if (this.#currentPath === null) {
            return;
        }

        const markdown = this.#crepe.getMarkdown();

        const formData = new FormData();
        formData.append('path', this.#currentPath);
        formData.append('content', markdown);

        try {
            const response = await fetch('/file/save', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Save failed: ${response.status}`);
            }

            this.#toast('success', this.i18nValue.toast?.saved ?? 'File saved');
        } catch (err) {
            console.error('Failed to save file:', err);
            this.#toast('error', err.message || 'Failed to save file');
        }
    }

    async saveFileAs() {
        const defaultName = this.#currentPath !== null
            ? this.#currentPath.split('/').pop()
            : 'untitled.md';
        const path = await this.#savePath(defaultName);
        if (path === null) {
            return;
        }

        const markdown = this.#crepe.getMarkdown();

        const formData = new FormData();
        formData.append('path', path);
        formData.append('content', markdown);

        try {
            const response = await fetch('/file/save', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Save failed: ${response.status}`);
            }

            this.#currentPath = path;
            this.#updateSaveButton(markdown);
            const name = path.split('/').pop();
            const template = this.i18nValue.toast?.savedAs ?? 'File saved as {name}';
            this.#toast('success', template.replace('{name}', name));
        } catch (err) {
            console.error('Failed to save file:', err);
            this.#toast('error', err.message || 'Failed to save file');
        }
    }

    printFile() {
        // beforeprint also mounts it; mounting here too doesn't rely on the
        // webview firing that event for a scripted print.
        this.#mountPrintCopy();
        window.print();
    }

    async copyMarkdown() {
        const markdown = this.#crepe?.getMarkdown() ?? '';
        try {
            await navigator.clipboard.writeText(markdown);
            this.#toast('success', this.i18nValue.toast?.copiedMarkdown ?? 'Markdown copied to clipboard');
        } catch (err) {
            console.error('Failed to copy markdown:', err);
            this.#toast('error', 'Failed to copy markdown');
        }
    }

    /**
     * Prints a serialized copy of the document instead of the editor DOM:
     * schema toDOM output (plain h1/p/ul/pre/table/img), none of Crepe's
     * node views or controls. See styles/print.css.
     */
    #mountPrintCopy() {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return;
        }

        this.#removePrintCopy();

        const { state } = editor.ctx.get(editorViewCtx);
        const copy = document.createElement('div');
        copy.className = 'print-copy document';
        copy.append(DOMSerializer.fromSchema(state.schema).serializeFragment(state.doc.content));
        document.body.append(copy);
        this.#printCopy = copy;
    }

    #removePrintCopy() {
        this.#printCopy?.remove();
        this.#printCopy = null;
    }

    #toast(type, message) {
        window.dispatchEvent(new CustomEvent('toast:show', { detail: { type, message } }));
    }

    #setupScrollHide() {
        this.#onScroll = () => {
            if (!this.#scrollTarget) {
                return;
            }
            const scrollTop = this.#scrollTarget.scrollTop;
            if (scrollTop <= 0) {
                this.element.classList.remove('is-toolbar-hidden');
            } else if (scrollTop > this.#lastScrollTop + 4) {
                this.element.classList.add('is-toolbar-hidden');
            } else if (scrollTop < this.#lastScrollTop - 4) {
                this.element.classList.remove('is-toolbar-hidden');
            }
            this.#lastScrollTop = scrollTop;
        };

        requestAnimationFrame(() => {
            this.#scrollTarget = this.element.querySelector('.ProseMirror');
            if (this.#scrollTarget) {
                this.#scrollTarget.addEventListener('scroll', this.#onScroll);
            }
        });
    }

    #pickPath(kind) {
        const tauri = window.__TAURI__;
        if (!tauri?.core?.invoke) {
            console.warn('Tauri IPC is not available — file picker requires the TFSApp hub.');
            return Promise.resolve(null);
        }

        return tauri.core.invoke('pick_path', { kind });
    }

    #savePath(fileName = 'untitled.md') {
        const tauri = window.__TAURI__;
        if (!tauri?.core?.invoke) {
            console.warn('Tauri IPC is not available — save dialog requires the TFSApp hub.');
            return Promise.resolve(null);
        }

        return tauri.core.invoke('save_path', {
            filters: [
                { name: 'Markdown', extensions: ['md'] },
                { name: 'Text', extensions: ['txt'] },
            ],
            fileName,
        });
    }

    #updateSaveButton(markdown) {
        const hasContent = markdown !== undefined ? markdown.trim() !== '' : false;
        if (this.hasSaveButtonTarget) {
            this.saveButtonTarget.disabled = !hasContent || this.#currentPath === null;
        }
        if (this.hasSaveAsButtonTarget) {
            this.saveAsButtonTarget.disabled = !hasContent;
        }
    }

    #updatePrintButton(markdown) {
        if (this.hasPrintButtonTarget) {
            this.printButtonTarget.disabled = markdown.trim() === '';
        }
    }

    #updateCopyMarkdownButton(markdown) {
        if (this.hasCopyMarkdownButtonTarget) {
            this.copyMarkdownButtonTarget.disabled = markdown.trim() === '';
        }
    }

    async #uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await fetch('/upload/image', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Upload failed: ${response.status}`);
            }

            const { url } = await response.json();
            return url;
        } catch (err) {
            this.#toast('error', err.message || 'Upload failed');
            throw err;
        }
    }

    #deleteImage(url) {
        const formData = new FormData();
        formData.append('url', url);

        fetch('/upload/image', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body: formData,
        }).catch(() => {});
    }

    #extractImageUrls(markdown) {
        const urls = new Set();
        const regex = /\/uploads\/images\/[0-9a-f]{2}\/[0-9a-f]+\.[a-z]+/g;
        let match;
        while ((match = regex.exec(markdown)) !== null) {
            urls.add(match[0]);
        }
        return urls;
    }

    #diffImageUrls(prevMarkdown, markdown) {
        const prev = this.#extractImageUrls(prevMarkdown);
        const current = this.#extractImageUrls(markdown);
        const removed = [];
        for (const url of prev) {
            if (!current.has(url)) {
                removed.push(url);
            }
        }
        return removed;
    }

    // The server enables AI only with a worker, a selected provider and its key.
    #isAiEnabled() {
        return this.aiConfigValue?.enabled === true;
    }

    /**
     * Moves the AI instruction tooltip from the editor's scroll container
     * to document.body so Floating UI's shift() middleware uses the viewport
     * as its boundary instead of the .milkdown overflow:hidden container.
     * Without this the tooltip can appear under the TopBar or off-screen.
     *
     * The `milkdown` class is added to the relocated element so the Crepe
     * theme's `.milkdown .ai-instruction-*` descendant selectors still apply.
     * The `.milkdown-ai-instruction`-level styles are overridden in
     * ai-tooltip.css since those selectors require a .milkdown ancestor.
     */
    #relocateAiTooltip() {
        if (!this.#isAiEnabled()) {
            return;
        }
        const tooltip = this.element.querySelector('.milkdown-ai-instruction');
        if (!tooltip) {
            return;
        }
        tooltip.classList.add('milkdown');
        document.body.append(tooltip);

        // Floating UI's flip() can switch placement to "top" when the
        // selection covers the whole document, and shift() (without padding)
        // doesn't fully correct the resulting negative top. Clamp it so the
        // tooltip never escapes the viewport.
        new MutationObserver(() => {
            if (tooltip.dataset.show !== 'true') {
                return;
            }
            const top = parseFloat(tooltip.style.top);
            if (!isNaN(top) && top < 8) {
                tooltip.style.top = '8px';
            }
        }).observe(tooltip, { attributes: true, attributeFilter: ['style'] });
    }

    /**
     * Creates the AIProvider that Crepe calls when the user triggers an AI action.
     *
     * The provider posts the instruction to our Symfony endpoint, which dispatches
     * a Messenger message and returns a Mercure topic. It then opens an EventSource
     * on that topic and yields streamed chunks until "done" or "error".
     */
    #createAIProvider() {
        return async function* aiProvider(context, signal) {
            const response = await fetch('/ai/instruct', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.aiCsrfTokenValue,
                },
                body: JSON.stringify({
                    instruction: context.instruction,
                    document: context.document,
                    selection: context.selection,
                }),
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `AI request failed: ${response.status}`);
            }

            const { topic } = await response.json();

            // The Mercure hub URL comes from MERCURE_PUBLIC_URL, injected by the hub.
            const mercureUrl = this.#mercureSubscribeUrl(topic);

            yield* this.#streamFromMercure(mercureUrl, signal);
        }.bind(this);
    }

    #mercureSubscribeUrl(topic) {
        // The hub mounts Mercure at /.well-known/mercure on the app's own origin.
        const base = window.location.origin + '/.well-known/mercure';
        return base + '?topic=' + encodeURIComponent(topic);
    }

    async *#streamFromMercure(url, signal) {
        const eventSource = new EventSource(url, { withCredentials: true });

        try {
            while (true) {
                if (signal?.aborted) {
                    eventSource.close();
                    return;
                }

                const event = await this.#nextMercureEvent(eventSource, signal);
                if (event === null) {
                    return;
                }

                const payload = JSON.parse(event.data);
                if (payload.type === 'chunk') {
                    yield payload.content;
                } else if (payload.type === 'done') {
                    eventSource.close();
                    return;
                } else if (payload.type === 'error') {
                    eventSource.close();
                    throw new Error(payload.error || 'AI stream error');
                }
            }
        } finally {
            eventSource.close();
        }
    }

    #nextMercureEvent(eventSource, signal) {
        return new Promise((resolve, reject) => {
            if (signal?.aborted) {
                resolve(null);
                return;
            }

            const onMessage = (event) => {
                cleanup();
                resolve(event);
            };

            const onError = () => {
                cleanup();
                // EventSource auto-reconnects; treat an error during abort as clean exit.
                if (signal?.aborted) {
                    resolve(null);
                } else {
                    reject(new Error('Mercure connection lost'));
                }
            };

            const onAbort = () => {
                cleanup();
                resolve(null);
            };

            function cleanup() {
                eventSource.removeEventListener('message', onMessage);
                eventSource.removeEventListener('error', onError);
                signal?.removeEventListener('abort', onAbort);
            }

            eventSource.addEventListener('message', onMessage);
            eventSource.addEventListener('error', onError);
            signal?.addEventListener('abort', onAbort);
        });
    }
}
