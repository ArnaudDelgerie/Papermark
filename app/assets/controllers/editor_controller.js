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
import { CURRENT_DIR_UPDATED_EVENT } from '../utils/current-directory.js';
import { MODE_UPDATED_EVENT } from '../utils/editor-mode.js';
import { FILE_DELETED_EVENT, FILE_RENAMED_EVENT, OPEN_FILE_EVENT, dispatchFileSavedAs } from '../utils/editor-open.js';
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
        // Path + content remembered in session for this mode (see ModeSession),
        // embedded server-side so restoring it needs no extra round trip.
        initialPath: { type: String, default: '' },
        initialContent: { type: String, default: '' },
        // Defaults for the editor's own texts (placeholder, slash menu, AI panel)
        // live in EditorFactory; only controller-owned UI text falls back here.
        i18n: Object,
    };

    static targets = ['saveButton', 'saveAsButton', 'printButton', 'copyMarkdownButton', 'toggleButton', 'toggleLabel', 'dirtyIndicator', 'filePath'];

    #crepe = null;
    #currentPath = null;
    #savedRef = '';
    #isReadonly = false;
    #printCopy = null;
    #aiClient = null;
    #onBeforePrint = () => this.#mountPrintCopy();
    #onAfterPrint = () => this.#removePrintCopy();
    #onGuardedClick = (event) => this.#guardLeave(event);
    #onOpenFileRequested = (event) => this.#loadFile(event.detail.path);
    #onFileDeleted = (event) => this.#handleFileDeleted(event.detail.path);
    #onFileRenamed = (event) => this.#handleFileRenamed(event.detail.oldPath, event.detail.newPath);

    // Each mode remembers its own file, so a switch replaces what is open. The
    // content comes with the event: the server read it while recording the
    // mode, no second round trip (see EDITOR_REACTIVITY.md).
    // Changing folder drops the file dir mode remembered, server side, so the
    // editor has to let go of it too rather than keep a file the new tree
    // doesn't contain. A refused change carries no path and leaves it alone.
    #onCurrentDirUpdated = (event) => {
        if (event.detail.path !== null) {
            this.newFile();
        }
    };

    #onModeUpdated = (event) => {
        const { path, content } = event.detail;
        if (path === null) {
            this.newFile();

            return;
        }

        this.#applyLoadedFile(path, content);
    };
    #leaveConfirmed = false;

    async connect() {
        this.#isReadonly = this.readonlyValue;

        if (this.#isAiEnabled()) {
            this.#aiClient = new AiClient({
                csrfToken: this.aiCsrfTokenValue,
                urls: { subscribe: this.urlsValue.aiSubscribe, instruct: this.urlsValue.aiInstruct, abort: this.urlsValue.aiAbort },
                mercureUrl: this.aiConfigValue.mercureUrl,
                topic: this.aiConfigValue.topic,
                requestFailedMessage: this.i18nValue.ai?.requestFailed,
            });
        }

        this.#crepe = await this.#createCrepe();

        // A new document is clean: capture the empty editor's markdown as the
        // reference so the indicator doesn't fire on the initial content.
        this.#savedRef = this.#crepe.getMarkdown();

        window.addEventListener('beforeprint', this.#onBeforePrint);
        window.addEventListener('afterprint', this.#onAfterPrint);
        // Capture phase, on window: covers the sidebar (mode-single / mode-dir),
        // not just this element, since navigation there also drops unsaved work.
        window.addEventListener('click', this.#onGuardedClick, true);
        window.addEventListener(OPEN_FILE_EVENT, this.#onOpenFileRequested);
        window.addEventListener(MODE_UPDATED_EVENT, this.#onModeUpdated);
        window.addEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
        window.addEventListener(FILE_DELETED_EVENT, this.#onFileDeleted);
        window.addEventListener(FILE_RENAMED_EVENT, this.#onFileRenamed);
        if (this.#isReadonly) {
            this.#applyReadonlyState();
        }

        this.#updateSaveButton('');
        this.#updatePrintButton('');
        this.#updateCopyMarkdownButton('');
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();

        if (this.initialPathValue) {
            this.#applyLoadedFile(this.initialPathValue, this.initialContentValue);
        }
    }

    async #createCrepe(defaultValue = '') {
        const crepe = await EditorFactory.create({
            root: this.element,
            defaultValue,
            i18n: this.i18nValue,
            onInsertImage: (ctx) => this.#insertImageFromPicker(ctx),
            onCopyCode: (text) => this.#copyCode(text),
            aiEnabled: this.#isAiEnabled(),
            aiProvider: this.#aiClient?.createProvider(),
            // Crepe prefixes the message ("AI provider error: ..."); show the original one.
            onAiError: (error) => showToast('error', error.cause?.message ?? error.message),
        });

        // Wrap .ProseMirror so the scrollable area extends past it, over the
        // surrounding padding/desk background too, not just the editable sheet.
        const prosemirror = this.element.querySelector('.ProseMirror');
        if (prosemirror) {
            const content = document.createElement('div');
            content.className = 'editor-content';
            prosemirror.replaceWith(content);
            content.appendChild(prosemirror);
        }

        crepe.on((listener) => {
            listener.markdownUpdated((_ctx, markdown) => {
                this.#updateSaveButton(markdown);
                this.#updatePrintButton(markdown);
                this.#updateCopyMarkdownButton(markdown);
                this.#updateDirtyIndicator(markdown);
            });
        });

        return crepe;
    }

    toggleReadonly() {
        this.#isReadonly = !this.#isReadonly;
        this.#applyReadonlyState();
    }

    #applyReadonlyState() {
        const prosemirror = this.element.querySelector('.ProseMirror');
        if (prosemirror) {
            prosemirror.setAttribute('contenteditable', this.#isReadonly ? 'false' : 'true');
        }

        if (this.#isReadonly) {
            this.element.classList.add('is-readonly');
        } else {
            this.element.classList.remove('is-readonly');
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

        if (this.hasToggleLabelTarget) {
            this.toggleLabelTarget.textContent = this.#isReadonly
                ? this.i18nValue.toggle?.edit ?? 'Edit'
                : this.i18nValue.toggle?.readonly ?? 'Read only';
        }
    }

    disconnect() {
        window.removeEventListener('beforeprint', this.#onBeforePrint);
        window.removeEventListener('afterprint', this.#onAfterPrint);
        window.removeEventListener('click', this.#onGuardedClick, true);
        window.removeEventListener(OPEN_FILE_EVENT, this.#onOpenFileRequested);
        window.removeEventListener(MODE_UPDATED_EVENT, this.#onModeUpdated);
        window.removeEventListener(CURRENT_DIR_UPDATED_EVENT, this.#onCurrentDirUpdated);
        window.removeEventListener(FILE_DELETED_EVENT, this.#onFileDeleted);
        window.removeEventListener(FILE_RENAMED_EVENT, this.#onFileRenamed);
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
            this.#applyLoadedFile(path, content);
        } catch (err) {
            console.error('Failed to open file:', err);
            showToast('error', err.message || 'Failed to open file');
        }
    }

    #applyLoadedFile(path, content) {
        this.#currentPath = path;
        this.#crepe.editor.action(replaceAll(content));
        this.#savedRef = this.#crepe.getMarkdown();
        this.#updateSaveButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();
    }

    // The delete already carries its own "File deleted" toast (mode-single /
    // mode-dir): resetting here is silent, same as newFile().
    #handleFileDeleted(path) {
        if (path !== this.#currentPath) {
            return;
        }
        this.newFile();
    }

    #handleFileRenamed(oldPath, newPath) {
        if (oldPath !== this.#currentPath) {
            return;
        }
        this.#currentPath = newPath;
        this.#updateSaveButton(this.#crepe.getMarkdown());
        this.#updateFilePath();
    }

    newFile() {
        this.#crepe.editor.action(replaceAll(''));
        this.#currentPath = null;
        this.#savedRef = this.#crepe.getMarkdown();
        this.#updateSaveButton(this.#savedRef);
        this.#updatePrintButton(this.#savedRef);
        this.#updateCopyMarkdownButton(this.#savedRef);
        this.#updateDirtyIndicator(this.#savedRef);
        this.#updateFilePath();
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
            this.#updateFilePath();
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

    async #copyCode(text) {
        try {
            await navigator.clipboard.writeText(text);
            showToast('success', this.i18nValue.toast?.copiedCode ?? 'Code copied to clipboard');
        } catch (err) {
            console.error('Failed to copy code:', err);
            showToast('error', this.i18nValue.toast?.copyCodeFailed ?? 'Failed to copy code');
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

    #updateFilePath() {
        if (!this.hasFilePathTarget) {
            return;
        }
        const label = this.#currentPath ?? this.i18nValue.untitled ?? 'Untitled';
        this.filePathTarget.textContent = label;
        this.filePathTarget.title = label;
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
            question: this.i18nValue.unsaved?.confirm ?? 'Continue and lose unsaved changes?',
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
