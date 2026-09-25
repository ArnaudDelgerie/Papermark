/**
 * CODE_REVIEW_3, lot 1: every JSON example under tests/contract/ is assigned
 * to the TS type it stands for. tsc fails if an example doesn't satisfy its
 * type; at runtime this only checks the import itself succeeded.
 */
import { describe, expect, it } from 'vitest';
import type { StateResponse } from '../../assets/controllers/editor_state_controller';
import type { FileResponse, I18n as EditorI18n } from '../../assets/controllers/editor_controller';
import type { ExportResponse, I18n as ExportI18n } from '../../assets/controllers/export_controller';
import type { I18n as ImportI18n } from '../../assets/controllers/import_controller';
import type { ModeSingleI18n } from '../../assets/controllers/mode_single_controller';
import type { ModeDirI18n } from '../../assets/controllers/mode_dir_controller';
import type { CurrentDirectoryI18n } from '../../assets/controllers/current_directory_controller';
import type { OpenWithI18n } from '../../assets/controllers/open_with_controller';
import type { I18n as ThemeSwitchI18n } from '../../assets/controllers/theme_switch_controller';
import type { EditorState } from '../../assets/editor/events';
import type { FileEntryI18n } from '../../assets/editor/file-entries';
import editorStateExample from '../contract/editor-state.json';
import stateSuccessExample from '../contract/state-success.json';
import stateErrorExample from '../contract/state-error.json';
import documentExample from '../contract/document.json';
import documentNoneExample from '../contract/document-none.json';
import archiveExportExample from '../contract/archive-export.json';
import editorI18nExample from '../contract/i18n/editor.json';
import editorStateI18nExample from '../contract/i18n/editor-state.json';
import themeSwitchI18nExample from '../contract/i18n/theme-switch.json';
import fileEntriesI18nExample from '../contract/i18n/file-entries.json';
import exportI18nExample from '../contract/i18n/export.json';
import importI18nExample from '../contract/i18n/import.json';
import modeSingleI18nExample from '../contract/i18n/mode-single.json';
import modeDirI18nExample from '../contract/i18n/mode-dir.json';
import currentDirectoryI18nExample from '../contract/i18n/current-directory.json';
import openWithI18nExample from '../contract/i18n/open-with.json';

/**
 * Only the key shape of T matters here (PHPUnit's ContractAssertions checks
 * the same thing, and never values either): every leaf loosens to `unknown`,
 * so a literal-typed field (EditorMode, …) never fails an example that is
 * otherwise complete — only a missing key, at any depth, still does.
 */
type Shape<T> = T extends (infer U)[] ? Shape<U>[] : T extends object ? { [K in keyof T]: Shape<T[K]> } : unknown;

describe('the contract examples satisfy their TS type', () => {
    it('imports every example', () => {
        const editorState: Shape<Omit<EditorState, 'readonly'>> = editorStateExample;
        const stateSuccess: Shape<StateResponse> = stateSuccessExample;
        const stateError: Shape<StateResponse> = stateErrorExample;
        const document: Shape<FileResponse> = documentExample;
        const documentNone: Shape<FileResponse> = documentNoneExample;
        const archiveExport: Shape<ExportResponse> = archiveExportExample;
        const editorI18n: Shape<EditorI18n> = editorI18nExample;
        const editorStateI18n: Shape<{ failed: string }> = editorStateI18nExample;
        const themeSwitchI18n: Shape<ThemeSwitchI18n> = themeSwitchI18nExample;
        const fileEntriesI18n: Shape<FileEntryI18n> = fileEntriesI18nExample;
        const exportI18n: Shape<ExportI18n> = exportI18nExample;
        const importI18n: Shape<ImportI18n> = importI18nExample;
        const modeSingleI18n: Shape<ModeSingleI18n> = modeSingleI18nExample;
        const modeDirI18n: Shape<ModeDirI18n> = modeDirI18nExample;
        const currentDirectoryI18n: Shape<CurrentDirectoryI18n> = currentDirectoryI18nExample;
        const openWithI18n: Shape<OpenWithI18n> = openWithI18nExample;

        expect([
            editorState,
            stateSuccess,
            stateError,
            document,
            documentNone,
            archiveExport,
            editorI18n,
            editorStateI18n,
            themeSwitchI18n,
            fileEntriesI18n,
            exportI18n,
            importI18n,
            modeSingleI18n,
            modeDirI18n,
            currentDirectoryI18n,
            openWithI18n,
        ]).toHaveLength(16);
    });
});
