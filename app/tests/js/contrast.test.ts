/// <reference types="node" />
import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/* UX-11, lot 10: the field outlines and the active mode state must reach 3:1
   against what they sit on, in both themes. jsdom runs no cascade, so the
   ratios are computed here from the tokens themselves. The suite runs from
   app/. */

const tokens = readFileSync('assets/styles/tokens.css', 'utf8');
const sidebar = readFileSync('assets/styles/sidebar.css', 'utf8');
const settings = readFileSync('assets/styles/settings.css', 'utf8');
const editor = readFileSync('assets/styles/editor.css', 'utf8');

function luminance(hex: string): number {
    const channel = (value: number): number => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
    const [r, g, b] = [0, 2, 4].map((i) => channel(Number.parseInt(hex.slice(i + 1, i + 3), 16) / 255));

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function ratio(a: string, b: string): number {
    const [la, lb] = [luminance(a), luminance(b)].sort((x, y) => y - x);

    return (la + 0.05) / (lb + 0.05);
}

/** The custom properties one theme block (or the auto-dark media block) sets. */
function block(name: string): Record<string, string> {
    const match = tokens.match(new RegExp(String.raw`:root\[data-theme="${name}"\]\s*\{([^}]*)\}`));
    const body = match?.[1] ?? /prefers-color-scheme: dark\)\s*\{\s*:root\[data-theme="auto"\]\s*\{([^}]*)\}/.exec(tokens)?.[1] ?? '';
    const vars: Record<string, string> = {};

    for (const [, key, value] of body.matchAll(/(--[\w-]+):\s*(#[0-9a-fA-F]{3,6})/g)) {
        vars[key] = value.length === 4 ? `#${[...value.slice(1)].map((c) => c + c).join('')}` : value;
    }

    return vars;
}

describe('the field and mode-selector outlines (UX-11, lot 10)', () => {
    it('declares a border-strong at 3:1 or more against the page, in every theme', () => {
        for (const name of ['dark', 'light', 'auto', 'auto-dark']) {
            const theme = block(name);
            expect(theme['--color-border-strong'], name).toBeDefined();
            expect(theme['--color-page'], name).toBeDefined();
            expect(ratio(theme['--color-border-strong'], theme['--color-page']), name).toBeGreaterThanOrEqual(3);
        }
    });

    it('marks the active mode with the accent, itself at 3:1 or more in both themes', () => {
        const rule = sidebar.match(/\.mode-selector-link\.is-active \{[^}]*\}/)![0];
        expect(rule).toContain('--color-accent');

        for (const name of ['dark', 'light', 'auto', 'auto-dark']) {
            const theme = block(name);
            expect(ratio(theme['--color-accent'], theme['--color-page']), name).toBeGreaterThanOrEqual(3);
        }
    });

    it('outlines the settings fields and the rename field with border-strong', () => {
        const field = settings.match(/\.settings-fieldset > div input \{[^}]*\}/)![0];
        expect(field).toContain('var(--color-border-strong)');

        const rename = editor.match(/\.editor-confirm-dialog-input \{[^}]*\}/)![0];
        expect(rename).toContain('var(--color-border-strong)');
    });
});
