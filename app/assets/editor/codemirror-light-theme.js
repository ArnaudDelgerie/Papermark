import { EditorView } from '@codemirror/view';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags } from '@lezer/highlight';

/**
 * Light companion to the `oneDark` theme Crepe uses by default for its code
 * blocks (`@codemirror/theme-one-dark`, see EDITOR_THEME.md): oneDark
 * hardcodes its own colors, independent of the app's crepe tokens, so the
 * chrome here follows --crepe-color-* instead while the syntax palette is a
 * fixed light set (One Light-derived).
 */
const mono1 = '#383a42', mono2 = '#696c77', mono3 = '#a0a1a7',
    cyan = '#0184bc', blue = '#4078f2', purple = '#a626a4',
    green = '#50a14f', red = '#e45649', orange = '#986801',
    selection = '#e5e5e6', cursor = '#526fff';

const lightTheme = EditorView.theme({
    '&': {
        color: mono1,
        backgroundColor: 'var(--crepe-color-surface)',
    },
    '.cm-content': { caretColor: cursor },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: cursor },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection': { backgroundColor: selection },
    '.cm-panels': { backgroundColor: 'var(--crepe-color-surface-low)', color: mono1 },
    '.cm-panels.cm-panels-top': { borderBottom: '2px solid var(--crepe-color-outline)' },
    '.cm-panels.cm-panels-bottom': { borderTop: '2px solid var(--crepe-color-outline)' },
    '.cm-searchMatch': { backgroundColor: '#72a1ff59', outline: '1px solid #457dff' },
    '.cm-searchMatch.cm-searchMatch-selected': { backgroundColor: '#6199ff2f' },
    '.cm-activeLine': { backgroundColor: '#00000008' },
    '.cm-selectionMatch': { backgroundColor: '#a8ffb44d' },
    '&.cm-focused .cm-matchingBracket, &.cm-focused .cm-nonmatchingBracket': { backgroundColor: '#bad0f847' },
    '.cm-gutters': { backgroundColor: 'var(--crepe-color-surface)', color: mono3, border: 'none' },
    '.cm-activeLineGutter': { backgroundColor: '#00000008' },
    '.cm-foldPlaceholder': { backgroundColor: 'transparent', border: 'none', color: mono2 },
    '.cm-tooltip': { border: '1px solid var(--crepe-color-outline)', backgroundColor: 'var(--crepe-color-surface-low)' },
    '.cm-tooltip .cm-tooltip-arrow:before': { borderTopColor: 'transparent', borderBottomColor: 'transparent' },
    '.cm-tooltip .cm-tooltip-arrow:after': { borderTopColor: 'var(--crepe-color-surface-low)', borderBottomColor: 'var(--crepe-color-surface-low)' },
    '.cm-tooltip-autocomplete': { '& > ul > li[aria-selected]': { backgroundColor: selection, color: mono1 } },
}, { dark: false });

const lightHighlightStyle = HighlightStyle.define([
    { tag: tags.keyword, color: purple },
    { tag: [tags.name, tags.deleted, tags.character, tags.propertyName, tags.macroName], color: red },
    { tag: [tags.function(tags.variableName), tags.labelName], color: blue },
    { tag: [tags.color, tags.constant(tags.name), tags.standard(tags.name)], color: orange },
    { tag: [tags.definition(tags.name), tags.separator], color: mono1 },
    { tag: [tags.typeName, tags.className, tags.number, tags.changed, tags.annotation, tags.modifier, tags.self, tags.namespace], color: orange },
    { tag: [tags.operator, tags.operatorKeyword, tags.url, tags.escape, tags.regexp, tags.link, tags.special(tags.string)], color: cyan },
    { tag: [tags.meta, tags.comment], color: mono3 },
    { tag: tags.strong, fontWeight: 'bold' },
    { tag: tags.emphasis, fontStyle: 'italic' },
    { tag: tags.strikethrough, textDecoration: 'line-through' },
    { tag: tags.link, color: mono3, textDecoration: 'underline' },
    { tag: tags.heading, fontWeight: 'bold', color: red },
    { tag: [tags.atom, tags.bool, tags.special(tags.variableName)], color: orange },
    { tag: [tags.processingInstruction, tags.string, tags.inserted], color: green },
    { tag: tags.invalid, color: '#ff0000' },
]);

export const crepeLightTheme = [lightTheme, syntaxHighlighting(lightHighlightStyle)];
