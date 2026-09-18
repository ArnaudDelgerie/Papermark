import type { EditorState } from '../../assets/editor/events';

export const INITIAL: EditorState = { mode: 'single', file: '/notes/a.md', dir: '/notes', readonly: false, ai_enabled: true };

/** A Stimulus value attribute: JSON, HTML-escaped. */
export function attr(value: unknown): string {
    return JSON.stringify(value).replace(/"/g, '&quot;');
}

/** The master's element as templates/editor/page.html.twig renders it, around `inner`. */
export function masterHtml(state: EditorState = INITIAL, inner = ''): string {
    return `<div id="editor-state" data-controller="editor-state"
        data-editor-state-state-value="${attr(state)}"
        data-editor-state-urls-value="${attr({
            state: '/editor/state', mode: '/editor/mode', file: '/editor/file', dir: '/editor/dir',
            save: '/file/save', delete: '/file/delete', rename: '/file/rename',
        })}"
        data-editor-state-tokens-value="${attr({ mode: 'tk-mode', file: 'tk-file', dir: 'tk-dir' })}"
        data-editor-state-i18n-value="${attr({ failed: 'Generic failure' })}">${inner}</div>`;
}

export const FILE_ENTRY_I18N = {
    rename: 'Rename',
    delete: 'Delete',
    renamePrompt: 'New name for {name}',
    deleteConfirmMessage: 'This cannot be undone.',
    deleteConfirmQuestion: 'Delete {name}?',
    deleted: 'File deleted',
    renamed: 'File renamed',
};

/** The left column, as the shell and its components render it. */
export function sidebarHtml(mode: EditorState['mode'] = 'single', dir: string | null = '/notes'): string {
    return `
    <nav class="mode-selector" data-controller="mode-switch">
        <button type="button" data-mode="single" class="mode-selector-link${mode === 'single' ? ' is-active' : ''}"
            data-mode-switch-target="link" data-action="mode-switch#change">Single</button>
        <button type="button" data-mode="dir" class="mode-selector-link${mode === 'dir' ? ' is-active' : ''}"
            data-mode-switch-target="link" data-action="mode-switch#change">Dir</button>
    </nav>
    <div class="editor-sidebar-panel" data-controller="mode-single"
         data-mode-single-i18n-value="${attr(FILE_ENTRY_I18N)}"${mode === 'single' ? '' : ' hidden'}>
        <button type="button" data-action="click->mode-single#openFile">Open</button>
        <div data-mode-single-target="loading">Loading</div>
        <ul class="mode-history" data-mode-single-target="list" hidden></ul>
        <p data-mode-single-target="empty" hidden>Empty</p>
    </div>
    <div class="editor-sidebar-panel" data-controller="mode-dir"
         data-mode-dir-i18n-value="${attr(FILE_ENTRY_I18N)}"${mode === 'dir' ? '' : ' hidden'}>
        <div class="current-directory" data-controller="current-directory">
            <button type="button" data-current-directory-target="openButton"
                data-action="click->current-directory#change">Open folder</button>
            <p data-current-directory-target="path"${dir ? '' : ' hidden'}>${dir ?? ''}</p>
        </div>
        <turbo-frame id="mode-dir-tree" data-mode-dir-target="treeFrame">
            <ul class="mode-tree">
                <li><a href="#" data-action="click->mode-dir#openFile" data-path="/notes/b.md">b.md</a>
                    <button type="button" data-action="click->mode-dir#renameEntry" data-path="/notes/b.md">R</button>
                    <button type="button" data-action="click->mode-dir#deleteEntry" data-path="/notes/b.md">D</button>
                </li>
            </ul>
        </turbo-frame>
    </div>
    <template id="sidebar-loading"><p class="sidebar-loading">Loading…</p></template>`;
}
