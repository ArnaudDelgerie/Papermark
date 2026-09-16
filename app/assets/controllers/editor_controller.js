import { Controller } from '@hotwired/stimulus';
import { abortAICmd } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx, editorViewCtx } from '@milkdown/kit/core';
import { imageBlockSchema } from '@milkdown/kit/component/image-block';
import { clearDiffReviewCmd, diffPluginKey } from '@milkdown/kit/plugin/diff';
import { addBlockTypeCommand, clearTextInCurrentBlockCommand } from '@milkdown/kit/preset/commonmark';
import { streamingPluginKey } from '@milkdown/kit/plugin/streaming';
import { DOMSerializer } from '@milkdown/kit/prose/model';
import { replaceAll } from '@milkdown/utils';
import AiClient from '../editor/ai-client.js';
import EditorFactory from '../editor/editor-factory.js';
import { confirmDialog } from '../utils/confirm-dialog.js';
import { OPEN_FILE_EVENT, dispatchFileSavedAs } from '../utils/editor-open.js';
import { pickPath, savePath } from '../utils/tauri.js';
import { showToast } from '../utils/toast.js';

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
        // Set from the dir-mode session directory; empty in single mode.
        directory: { type: String, default: '' },
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
    #aiClient = null;
    #onBeforePrint = () => this.#mountPrintCopy();
    #onAfterPrint = () => this.#removePrintCopy();
    #onGuardedClick = (event) => this.#guardLeave(event);
    #onOpenFileRequested = (event) => this.#loadFile(event.detail.path);
    #leaveConfirmed = false;

    async connect() {
        this.#isReadonly = this.readonlyValue;
        this.#isA4 = this.element.classList.contains('is-a4');

        if (this.#isAiEnabled()) {
            this.#aiClient = new AiClient({
                csrfToken: this.aiCsrfTokenValue,
                urls: { subscribe: this.urlsValue.aiSubscribe, instruct: this.urlsValue.aiInstruct, abort: this.urlsValue.aiAbort },
                mercureUrl: this.aiConfigValue.mercureUrl,
                topic: this.aiConfigValue.topic,
                requestFailedMessage: this.i18nValue.ai?.requestFailed,
            });
        }

        this.#crepe = await EditorFactory.create({
            root: this.element,
            i18n: this.i18nValue,
            onInsertImage: (ctx) => this.#insertImageFromPicker(ctx),
            aiEnabled: this.#isAiEnabled(),
            aiProvider: this.#aiClient?.createProvider(),
            // Crepe prefixes the message ("AI provider error: ..."); show the original one.
            onAiError: (error) => showToast('error', error.cause?.message ?? error.message),
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
        // Capture phase, on window: covers the sidebar (mode-single / mode-dir),
        // not just this element, since navigation there also drops unsaved work.
        window.addEventListener('click', this.#onGuardedClick, true);
        window.addEventListener(OPEN_FILE_EVENT, this.#onOpenFileRequested);

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
        window.removeEventListener('click', this.#onGuardedClick, true);
        window.removeEventListener(OPEN_FILE_EVENT, this.#onOpenFileRequested);
        this.#removePrintCopy();
        this.#aiClient?.close();
        this.#crepe?.destroy();
        this.#crepe = null;
    }

    // The path comes from the sidebar (Open, a history entry or a tree file),
    // already past the leave guard by the time this event fires.
    async #loadFile(path) {
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
            showToast('success', this.i18nValue.toast?.opened ?? 'File opened');
        } catch (err) {
            console.error('Failed to open file:', err);
            showToast('error', err.message || 'Failed to open file');
        }
    }

    newFile() {
        this.#crepe.editor.action(replaceAll(''));
        this.#currentPath = null;
        this.#savedRef = this.#crepe.getMarkdown();
        this.#updateSaveButton(this.#savedRef);
        this.#updatePrintButton(this.#savedRef);
        this.#updateCopyMarkdownButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
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
            showToast('success', this.i18nValue.toast?.saved ?? 'File saved');
        } catch (err) {
            console.error('Failed to save file:', err);
            showToast('error', err.message || 'Failed to save file');
        }
    }

    async saveFileAs() {
        const defaultName = this.#currentPath !== null
            ? this.#currentPath.split('/').pop()
            : 'untitled.md';
        // In dir mode, the save dialog opens in the current directory without
        // constraining where the file actually gets saved (see EDITOR_FOLDER_MODE.md).
        const path = await savePath(defaultName, this.directoryValue || undefined);
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
            showToast('success', template.replace('{name}', name));
            // Harmless no-op outside dir mode: nothing listens for it.
            dispatchFileSavedAs(path);
        } catch (err) {
            console.error('Failed to save file:', err);
            showToast('error', err.message || 'Failed to save file');
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
            showToast('success', this.i18nValue.toast?.copiedMarkdown ?? 'Markdown copied to clipboard');
        } catch (err) {
            console.error('Failed to copy markdown:', err);
            showToast('error', this.i18nValue.toast?.copyMarkdownFailed ?? 'Failed to copy markdown');
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
        const path = await pickPath('file');
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

        confirmDialog({
            message: this.i18nValue.unsaved?.confirm ?? 'Continue and lose unsaved changes?',
            cancelLabel: this.i18nValue.unsaved?.cancel ?? 'Cancel',
            continueLabel: this.i18nValue.unsaved?.continue ?? 'Continue',
        }).then((confirmed) => {
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

}
