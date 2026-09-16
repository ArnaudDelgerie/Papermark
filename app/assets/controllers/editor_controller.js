import { Controller } from '@hotwired/stimulus';
import { abortAICmd } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx, editorViewCtx } from '@milkdown/kit/core';
import { imageBlockSchema } from '@milkdown/kit/component/image-block';
import { clearDiffReviewCmd, diffPluginKey } from '@milkdown/kit/plugin/diff';
import { addBlockTypeCommand, clearTextInCurrentBlockCommand } from '@milkdown/kit/preset/commonmark';
import { streamingPluginKey } from '@milkdown/kit/plugin/streaming';
import { DOMSerializer } from '@milkdown/kit/prose/model';
import { replaceAll } from '@milkdown/utils';
import EditorFactory from '../editor/editor-factory.js';

export default class extends Controller {
    static values = {
        fileCsrfToken: String,
        aiCsrfToken: String,
        urls: Object,
        aiConfig: {
            type: Object,
            default: { enabled: false },
        },
        readonly: { type: Boolean, default: false },
        // Defaults for the editor's own texts (placeholder, slash menu, AI panel)
        // live in EditorFactory; only controller-owned UI text falls back here.
        i18n: Object,
    };

    static targets = ['saveButton', 'saveAsButton', 'printButton', 'copyMarkdownButton', 'a4Button', 'toggleButton', 'dirtyIndicator'];

    #crepe = null;
    #currentPath = null;
    #savedRef = '';
    #isReadonly = false;
    #isA4 = true;
    #lastScrollTop = 0;
    #scrollTarget = null;
    #onScroll = null;
    #printCopy = null;
    #aiSource = null;
    #aiRequests = new Map();
    #onBeforePrint = () => this.#mountPrintCopy();
    #onAfterPrint = () => this.#removePrintCopy();
    #onGuardedClick = (event) => this.#guardLeave(event);
    #leaveConfirmed = false;

    async connect() {
        this.#isReadonly = this.readonlyValue;
        this.#isA4 = this.element.classList.contains('is-a4');
        this.#crepe = await EditorFactory.create({
            root: this.element,
            i18n: this.i18nValue,
            onInsertImage: (ctx) => this.#insertImageFromPicker(ctx),
            aiEnabled: this.#isAiEnabled(),
            aiProvider: this.#isAiEnabled() ? this.#createAIProvider() : undefined,
            // Crepe prefixes the message ("AI provider error: ..."); show the original one.
            onAiError: (error) => this.#toast('error', error.cause?.message ?? error.message),
        });

        this.#crepe.on((listener) => {
            listener.markdownUpdated((_ctx, markdown) => {
                this.#updateSaveButton(markdown);
                this.#updatePrintButton(markdown);
                this.#updateCopyMarkdownButton(markdown);
                this.#updateDirtyIndicator(markdown);
            });
        });

        // A new document is clean: capture the empty editor's markdown as the
        // reference so the indicator doesn't fire on the initial content.
        this.#savedRef = this.#crepe.getMarkdown();

        window.addEventListener('beforeprint', this.#onBeforePrint);
        window.addEventListener('afterprint', this.#onAfterPrint);
        // Capture phase: runs before the guarded element's own click handlers.
        this.element.addEventListener('click', this.#onGuardedClick, true);

        if (this.#isReadonly) {
            this.#applyReadonlyState();
        } else {
            this.#setupScrollHide();
        }

        this.#updateSaveButton('');
        this.#updatePrintButton('');
        this.#updateCopyMarkdownButton('');
        this.#updateDirtyIndicator(this.#savedRef);

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
        this.element.removeEventListener('click', this.#onGuardedClick, true);
        this.#removePrintCopy();
        this.#closeAiSource();
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
            const response = await fetch(this.urlsValue.open, {
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
            this.#savedRef = this.#crepe.getMarkdown();
            this.#updateSaveButton(this.#savedRef);
            this.#updateDirtyIndicator(this.#savedRef);
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
            const response = await fetch(this.urlsValue.save, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Save failed: ${response.status}`);
            }

            this.#savedRef = markdown;
            this.#updateDirtyIndicator(markdown);
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
            const response = await fetch(this.urlsValue.save, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
                body: formData,
            });

            if (!response.ok) {
                const data = await response.json().catch(() => ({}));
                throw new Error(data.error || `Save failed: ${response.status}`);
            }

            this.#currentPath = path;
            this.#savedRef = markdown;
            this.#updateSaveButton(markdown);
            this.#updateDirtyIndicator(markdown);
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
            const content = await this.#convertImageUrlsForCopy(markdown);
            await navigator.clipboard.writeText(content);
            this.#toast('success', this.i18nValue.toast?.copiedMarkdown ?? 'Markdown copied to clipboard');
        } catch (err) {
            console.error('Failed to copy markdown:', err);
            this.#toast('error', this.i18nValue.toast?.copyMarkdownFailed ?? 'Failed to copy markdown');
        }
    }

    /**
     * The editor only ever holds /file/image service URLs for local images
     * (see EDITOR_IMAGES.md); the server converts them back to their raw
     * path before the markdown leaves the editor, same as save().
     */
    async #convertImageUrlsForCopy(markdown) {
        const formData = new FormData();
        formData.append('content', markdown);

        const response = await fetch(this.urlsValue.copy, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.fileCsrfTokenValue },
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`Copy failed: ${response.status}`);
        }

        const { content } = await response.json();
        return content;
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

    /**
     * Picked paths are always absolute and never rewritten to relative,
     * whether the document is new or already open — see EDITOR_IMAGES.md.
     */
    async #insertImageFromPicker(ctx) {
        const path = await this.#pickPath('file');
        if (path === null) {
            return;
        }

        const commands = ctx.get(commandsCtx);
        const imageBlock = imageBlockSchema.type(ctx);
        commands.call(clearTextInCurrentBlockCommand.key);
        commands.call(addBlockTypeCommand.key, {
            nodeType: imageBlock,
            attrs: { src: `${this.urlsValue.image}?path=${encodeURIComponent(path)}` },
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

    #updateDirtyIndicator(markdown) {
        if (!this.hasDirtyIndicatorTarget) {
            return;
        }
        this.dirtyIndicatorTarget.hidden = !this.#isDirty(markdown);
    }

    #isDirty(markdown) {
        const current = markdown ?? this.#crepe?.getMarkdown() ?? '';
        return current !== this.#savedRef;
    }

    #restoreFocus() {
        const prosemirror = this.element.querySelector('.ProseMirror');
        if (prosemirror) {
            prosemirror.focus();
        }
    }

    #isAiInProgress() {
        const editor = this.#crepe?.editor;
        if (!editor || editor.status !== EditorStatus.Created) {
            return false;
        }
        const view = editor.ctx.get(editorViewCtx);
        const streaming = streamingPluginKey.getState(view.state);
        const diff = diffPluginKey.getState(view.state);
        return (streaming?.active ?? false) || (diff?.active ?? false);
    }

    #shouldConfirmLeave() {
        return this.#isDirty() || this.#isAiInProgress();
    }

    /**
     * Guards clicks on elements marked data-editor-leave-guard, links and JS
     * actions alike. The click is stopped before the element's own handlers or
     * navigation; once confirmed, any AI generation or review is discarded and
     * the click is replayed, skipping the guard.
     */
    #guardLeave(event) {
        const guarded = event.target.closest('[data-editor-leave-guard]');
        if (!guarded || this.#leaveConfirmed || !this.#shouldConfirmLeave()) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        this.#confirmLeave().then((confirmed) => {
            if (!confirmed) {
                this.#restoreFocus();
                return;
            }

            this.#discardAi();
            // click() dispatches synchronously, so the flag only covers the replay.
            this.#leaveConfirmed = true;
            try {
                guarded.click();
            } finally {
                this.#leaveConfirmed = false;
            }
        });
    }

    // The webview shows no native confirm(): the hub doesn't handle JS dialogs.
    #confirmLeave() {
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'editor-confirm-dialog';
            dialog.textContent = this.i18nValue.unsaved?.confirm ?? 'Continue and lose unsaved changes?';

            const actions = document.createElement('div');
            actions.className = 'editor-confirm-dialog-actions';

            const cancel = document.createElement('button');
            cancel.type = 'button';
            cancel.textContent = this.i18nValue.unsaved?.cancel ?? 'Cancel';
            cancel.addEventListener('click', () => dialog.close('cancel'));

            const cont = document.createElement('button');
            cont.type = 'button';
            cont.className = 'editor-confirm-continue';
            cont.textContent = this.i18nValue.unsaved?.continue ?? 'Continue';
            cont.addEventListener('click', () => dialog.close('continue'));

            // Escape closes it too, with an empty returnValue.
            dialog.addEventListener('close', () => {
                dialog.remove();
                resolve(dialog.returnValue === 'continue');
            });

            actions.append(cancel, cont);
            dialog.append(actions);
            document.body.append(dialog);
            dialog.showModal();
            cancel.focus();
        });
    }

    // Aborting ends the provider's generator, whose finally tells the worker to stop.
    #discardAi() {
        if (!this.#isAiEnabled()) {
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

    // The server enables AI only with a worker, a selected provider and its key.
    #isAiEnabled() {
        return this.aiConfigValue?.enabled === true;
    }

    /**
     * Creates the AIProvider that Crepe calls when the user triggers an AI action.
     *
     * The EventSource is opened per request: /ai/subscribe is called first (the
     * server decides whether to re-mint the cookie), then the connection waits
     * for `open` before the instruction is sent. The connection is closed in the
     * finally block, so no permanent subscription is kept. Crepe allows only one
     * generation at a time, so there is never a second connection. Each request
     * has its own id: the worker echoes it and events of other requests are ignored.
     */
    #createAIProvider() {
        return async function* aiProvider(context, signal) {
            let id = null;
            let finished = false;

            try {
                await this.#ensureAiSubscription();

                id = window.crypto.randomUUID();
                const request = this.#createAiRequest(id);

                const response = await this.#postAi(this.urlsValue.aiInstruct, {
                    id,
                    instruction: context.instruction,
                    document: context.document,
                    selection: context.selection,
                });

                if (!response.ok) {
                    finished = true;
                    const data = await response.json().catch(() => ({}));
                    throw new Error(data.error || (this.i18nValue.ai?.requestFailed ?? 'The AI request failed'));
                }

                while (true) {
                    const payload = await request.next(signal);
                    if (payload === null) {
                        return;
                    }

                    if (payload.type === 'chunk') {
                        yield payload.content;
                    } else if (payload.type === 'done') {
                        finished = true;
                        return;
                    } else if (payload.type === 'error') {
                        // A lost connection leaves the worker running: let finally abort it.
                        finished = !payload.connectionLost;
                        throw new Error(payload.error || (this.i18nValue.ai?.requestFailed ?? 'The AI request failed'));
                    }
                }
            } finally {
                if (id !== null) {
                    this.#aiRequests.delete(id);
                }
                this.#closeAiSource();
                // Aborted by the user or interrupted: stop the worker, which serves one request at a time.
                // keepalive: the abort must survive leaving the page right after a confirm.
                if (!finished && id !== null) {
                    this.#postAi(this.urlsValue.aiAbort, { id }, { keepalive: true }).catch((err) => console.error('Failed to abort AI request:', err));
                }
            }
        }.bind(this);
    }

    /**
     * Asks the server to ensure the subscriber cookie is fresh (it re-mints only
     * if needed), then opens the EventSource and resolves once the hub accepts it.
     */
    async #ensureAiSubscription() {
        const response = await this.#postAi(this.urlsValue.aiSubscribe, {});
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || (this.i18nValue.ai?.requestFailed ?? 'The AI request failed'));
        }

        await this.#openAiSource();
    }

    /**
     * Opens the EventSource on the session topic and resolves once the hub accepted it.
     */
    #openAiSource() {
        this.#closeAiSource();

        const url = new URL(this.aiConfigValue.mercureUrl);
        url.searchParams.append('topic', this.aiConfigValue.topic);
        const source = new EventSource(url, { withCredentials: true });
        this.#aiSource = source;

        source.addEventListener('message', (event) => {
            const payload = JSON.parse(event.data);
            this.#aiRequests.get(payload.id)?.push(payload);
        });

        return new Promise((resolve, reject) => {
            source.addEventListener('open', () => {
                resolve();
            }, { once: true });

            source.addEventListener('error', () => {
                // Transient errors reconnect on their own; only a closed source is lost.
                if (source.readyState !== EventSource.CLOSED) {
                    return;
                }
                // Ignore errors from a source we already replaced or closed.
                if (this.#aiSource !== source) {
                    return;
                }

                const error = this.i18nValue.ai?.requestFailed ?? 'The AI request failed';
                reject(new Error(error));
                this.#aiRequests.forEach((request) => request.push({ type: 'error', error, connectionLost: true }));
            });
        });
    }

    #closeAiSource() {
        this.#aiSource?.close();
        this.#aiSource = null;
    }

    /**
     * Queues the events of one request until the provider consumes them.
     */
    #createAiRequest(id) {
        const events = [];
        let wake = null;

        const request = {
            push: (payload) => {
                events.push(payload);
                wake?.();
            },
            next: async (signal) => {
                while (events.length === 0) {
                    if (signal?.aborted) {
                        return null;
                    }

                    await new Promise((resolve) => {
                        wake = resolve;
                        signal?.addEventListener('abort', resolve, { once: true });
                    });
                    signal?.removeEventListener('abort', wake);
                    wake = null;
                }

                return events.shift();
            },
        };

        this.#aiRequests.set(id, request);

        return request;
    }

    #postAi(url, body, options = {}) {
        return fetch(url, {
            ...options,
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.aiCsrfTokenValue,
            },
            body: JSON.stringify(body),
        });
    }
}
