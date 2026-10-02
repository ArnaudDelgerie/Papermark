import type { EditorState } from '../../assets/editor/events';
import type { FileEntryI18n } from '../../assets/editor/file-entries';
import type { ModeSingleI18n } from '../../assets/controllers/mode_single_controller';
import type { ModeDirI18n } from '../../assets/controllers/mode_dir_controller';
import type { CurrentDirectoryI18n } from '../../assets/controllers/current_directory_controller';
import editorStateI18n from '../contract/i18n/editor-state.json';
import fileEntriesI18n from '../contract/i18n/file-entries.json';
import modeSingleI18n from '../contract/i18n/mode-single.json';
import modeDirI18n from '../contract/i18n/mode-dir.json';
import currentDirectoryI18n from '../contract/i18n/current-directory.json';

export const INITIAL: EditorState = { mode: 'single', file: '/notes/a.md', dir: '/notes', readonly: false, ai_enabled: true, autosave: false, autosave_after_ai: false };

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
            refreshDir: '/editor/dir/refresh', open: '/editor/open', save: '/document/save', delete: '/document/delete', rename: '/document/rename',
            setKey: '/settings/provider/__name__/key', deleteKey: '/settings/provider/__name__/key', import: '/archive/import',
        })}"
        data-editor-state-i18n-value="${attr(editorStateI18n)}">${inner}</div>`;
}

export const FILE_ENTRY_I18N: FileEntryI18n = fileEntriesI18n;
export const MODE_SINGLE_I18N: ModeSingleI18n = modeSingleI18n;
export const MODE_DIR_I18N: ModeDirI18n = modeDirI18n;
export const CURRENT_DIRECTORY_I18N: CurrentDirectoryI18n = currentDirectoryI18n;

/** The left column, as the shell and its components render it. */
export function sidebarHtml(mode: EditorState['mode'] = 'single', dir: string | null = '/notes'): string {
    return `
    <nav class="mode-selector" data-controller="mode-switch">
        <button type="button" data-mode="single" class="mode-selector-link${mode === 'single' ? ' is-active' : ''}"
            aria-pressed="${mode === 'single' ? 'true' : 'false'}"
            data-mode-switch-target="link" data-action="mode-switch#change">Single</button>
        <button type="button" data-mode="dir" class="mode-selector-link${mode === 'dir' ? ' is-active' : ''}"
            aria-pressed="${mode === 'dir' ? 'true' : 'false'}"
            data-mode-switch-target="link" data-action="mode-switch#change">Dir</button>
    </nav>
    <div class="editor-sidebar-panel" data-controller="mode-single"
         data-mode-single-editor-state-outlet="#editor-state"
         data-mode-single-i18n-value="${attr(MODE_SINGLE_I18N)}"${mode === 'single' ? '' : ' hidden'}>
        <button type="button" data-mode-single-target="openButton" data-action="click->mode-single#openFile">Open</button>
        <div data-mode-single-target="loading">Loading</div>
        <ul class="mode-history" data-mode-single-target="list" hidden></ul>
        <p data-mode-single-target="empty" hidden>Empty</p>
    </div>
    <div class="editor-sidebar-panel" data-controller="mode-dir"
         data-mode-dir-editor-state-outlet="#editor-state"
         data-mode-dir-dir-value="${dir ?? ''}"
         data-mode-dir-i18n-value="${attr(MODE_DIR_I18N)}"${mode === 'dir' ? '' : ' hidden'}>
        <div class="current-directory" data-controller="current-directory"
             data-current-directory-i18n-value="${attr(CURRENT_DIRECTORY_I18N)}">
            <button type="button" data-current-directory-target="openButton"
                data-action="click->current-directory#change">Open folder</button>
            <p data-current-directory-target="path"${dir ? '' : ' hidden'}>${dir ?? ''}</p>
        </div>
        <div data-mode-dir-target="toolbar"${dir ? '' : ' hidden'}>
            <button type="button" data-mode-dir-target="refreshButton" data-action="click->mode-dir#refresh">Refresh</button>
        </div>
        <turbo-frame id="mode-dir-tree" data-mode-dir-target="treeFrame">
            <ul class="mode-tree">
                <li class="mode-tree-dir">
                    <details>
                        <summary class="mode-tree-dir-name"><span class="mode-tree-label">sub</span></summary>
                        <ul>
                            <li class="mode-tree-file">
                                <a href="#" data-action="click->mode-dir#openFile" data-path="/notes/sub/d.md">d.md</a>
                                <span class="mode-entry-actions">
                                    <button type="button" data-action="click->mode-dir#renameEntry" data-path="/notes/sub/d.md">R</button>
                                    <button type="button" data-action="click->mode-dir#deleteEntry" data-path="/notes/sub/d.md">D</button>
                                </span>
                            </li>
                        </ul>
                    </details>
                </li>
                <li class="mode-tree-file">
                    <a href="#" data-action="click->mode-dir#openFile" data-path="/notes/b.md">b.md</a>
                    <span class="mode-entry-actions">
                        <button type="button" data-action="click->mode-dir#renameEntry" data-path="/notes/b.md">R</button>
                        <button type="button" data-action="click->mode-dir#deleteEntry" data-path="/notes/b.md">D</button>
                    </span>
                </li>
            </ul>
        </turbo-frame>
    </div>`;
}
