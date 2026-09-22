import { beforeEach, describe, expect, it, vi } from 'vitest';
import { Crepe } from '@milkdown/crepe';
import { abortAICmd, ai } from '@milkdown/crepe/feature/ai';
import type { AIProvider } from '@milkdown/crepe/feature/ai';
import { EditorStatus, commandsCtx } from '@milkdown/kit/core';
import { clearDiffReviewCmd, diffPluginKey } from '@milkdown/kit/plugin/diff';
import { streamingPluginKey } from '@milkdown/kit/plugin/streaming';
import { addBlockTypeCommand, clearTextInCurrentBlockCommand } from '@milkdown/kit/preset/commonmark';
import { Schema } from '@milkdown/kit/prose/model';
import CrepeHost, { type CrepeI18n } from '../../assets/editor/crepe-host';
import EDITOR_I18N from '../contract/i18n/editor.json';

// A fake Crepe, kept as close as the one editor.test.ts used to simulate
// (markdown in, markdown out, updates), extended to keep the configuration
// Crepe was constructed with, so the menu wiring can be inspected. `Feature`
// stays the real enum: only the class itself is faked.
vi.mock('@milkdown/crepe', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@milkdown/crepe')>();
    const FakeCrepe = vi.fn();
    (FakeCrepe as unknown as { Feature: unknown }).Feature = actual.Crepe.Feature;

    return { Crepe: FakeCrepe };
});
// `abortAICmd` stays real (only its `.key` is compared); the rest isn't
// exercised for real since `addFeature` on the fake Crepe never runs what
// it's given.
vi.mock('@milkdown/crepe/feature/ai', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@milkdown/crepe/feature/ai')>();

    return {
        ...actual,
        ai: vi.fn(),
        defaultAIIcon: 'ai-icon',
        useAIInstructionTooltipAPI: vi.fn(() => ({ show: vi.fn() })),
    };
});
vi.mock('@milkdown/kit/component/image-block', () => ({
    imageBlockSchema: { type: vi.fn(() => 'IMAGE_NODE_TYPE') },
}));
// The real replaceAll() returns a function that needs a real editor to run;
// the fake Crepe's editor.action() pattern-matches on this plain shape
// instead (same trick editor.test.ts used to use).
vi.mock('@milkdown/utils', () => ({ replaceAll: (markdown: string, flush = false) => ({ replaceAll: markdown, flush }) }));

const I18N: CrepeI18n = EDITOR_I18N;

/** prosemirror-state's PluginKey.key is real at runtime, not in its .d.ts. */
function keyOf(pluginKey: { getState: (state: never) => unknown }): string {
    return (pluginKey as unknown as { key: string }).key;
}

interface FakeCrepe {
    config: Record<string, unknown>;
    commands: { call: ReturnType<typeof vi.fn>; get: ReturnType<typeof vi.fn> };
    editor: {
        status: EditorStatus;
        ctx: { get: (key: unknown) => unknown };
        action: (command: unknown) => void;
        config: ReturnType<typeof vi.fn>;
    };
    viewState: unknown;
    flushes: boolean[];
    create: ReturnType<typeof vi.fn>;
    destroy: ReturnType<typeof vi.fn>;
    addFeature: ReturnType<typeof vi.fn>;
    getMarkdown: () => string;
    on: (register: (listener: { markdownUpdated: (fn: (ctx: unknown, markdown: string) => void) => void }) => void) => FakeCrepe;
}

/** Every `new Crepe(...)` the code under test makes, oldest first. */
let crepes: FakeCrepe[];

function fakeCrepe(config: Record<string, unknown>): FakeCrepe {
    let markdown = (config.defaultValue as string | undefined) ?? '';
    let viewState: unknown = {};
    const flushes: boolean[] = [];
    const listeners: Array<(ctx: unknown, markdown: string) => void> = [];
    const commands = { call: vi.fn(), get: vi.fn(() => () => () => false) };
    const ctx = { get: (key: unknown) => (key === commandsCtx ? commands : { state: viewState }) };
    const set = (value: string): void => {
        markdown = value;
        listeners.forEach((listener) => listener(null, value));
    };

    const fake: FakeCrepe = {
        config,
        commands,
        get viewState(): unknown {
            return viewState;
        },
        set viewState(value: unknown) {
            viewState = value;
        },
        flushes,
        editor: {
            status: EditorStatus.Created,
            ctx,
            action: (command: unknown) => {
                // replace() passes what the mocked replaceAll() returns;
                // discardAi()/insertImage() pass a plain function to run on the ctx.
                if (typeof command === 'function') {
                    (command as (ctx: unknown) => void)(ctx);
                } else if ((command as { replaceAll?: string } | undefined)?.replaceAll !== undefined) {
                    flushes.push((command as { flush?: boolean }).flush ?? false);
                    set((command as { replaceAll: string }).replaceAll);
                }
            },
            config: vi.fn(),
        },
        // Mounts the same shape as the real Crepe: a .milkdown container
        // around .ProseMirror, so #wrapScroll() and the cleanup before a
        // rebuild (":scope > .milkdown") have something real to work on.
        create: vi.fn(async () => {
            const container = document.createElement('div');
            container.className = 'milkdown';
            const prosemirror = document.createElement('div');
            prosemirror.className = 'ProseMirror';
            container.append(prosemirror);
            (config.root as Element).append(container);

            return fake.editor;
        }),
        destroy: vi.fn(() => Promise.resolve()),
        addFeature: vi.fn(),
        getMarkdown: () => markdown,
        on: (register) => {
            register({ markdownUpdated: (fn) => listeners.push(fn) });

            return fake;
        },
    };

    return fake;
}

function callbacks(overrides: Partial<{ onInsertImage: () => void; onCopyCode: (text: string) => void; onAiError: (error: Error) => void }> = {}): {
    onInsertImage: () => void;
    onCopyCode: (text: string) => void;
    onAiError: (error: Error) => void;
} {
    return { onInsertImage: vi.fn(), onCopyCode: vi.fn(), onAiError: vi.fn(), ...overrides };
}

interface FakeMenuItem {
    key: string;
    onRun?: unknown;
    [prop: string]: unknown;
}

/** Just enough of Crepe's GroupBuilder for buildMenu()/buildTopBar() to run against. */
function fakeGroupBuilder(initial: Record<string, FakeMenuItem[]> = {}): {
    getGroup: (key: string) => { group: { items: FakeMenuItem[] }; addItem: (key: string, item: Record<string, unknown>) => void };
} {
    const groups = new Map<string, { items: FakeMenuItem[] }>(
        Object.entries(initial).map(([key, items]) => [key, { items: items.map((item) => ({ ...item })) }]),
    );

    return {
        getGroup: (key: string) => {
            const group = groups.get(key) ?? { items: [] };
            groups.set(key, group);

            return {
                group,
                addItem: (itemKey: string, item: Record<string, unknown>) => {
                    group.items.push({ key: itemKey, ...item });
                },
            };
        },
    };
}

beforeEach(() => {
    crepes = [];
    // A regular function, not an arrow one: `new Crepe(...)` needs a
    // constructable implementation.
    vi.mocked(Crepe).mockImplementation(function (config: unknown) {
        const instance = fakeCrepe((config ?? {}) as Record<string, unknown>);
        crepes.push(instance);

        return instance as never;
    });
});

describe('CrepeHost', () => {
    it('create() passes the markdown to Crepe and wraps .ProseMirror in .editor-content', async () => {
        const root = document.createElement('div');
        const host = new CrepeHost(root, I18N, callbacks());

        await host.create({ markdown: '# Hello', aiEnabled: false, aiProvider: undefined });

        expect(crepes[0].config.defaultValue).toBe('# Hello');
        expect(host.markdown()).toBe('# Hello');
        const prosemirror = root.querySelector('.ProseMirror');
        expect(prosemirror).not.toBeNull();
        expect(prosemirror!.parentElement?.className).toBe('editor-content');
    });

    it('create() activates the AI feature only when aiEnabled, with the given provider', async () => {
        const provider = vi.fn() as unknown as AIProvider;

        const withoutAi = new CrepeHost(document.createElement('div'), I18N, callbacks());
        await withoutAi.create({ aiEnabled: false, aiProvider: provider });
        expect(crepes[0].addFeature).not.toHaveBeenCalledWith(ai, expect.anything());

        const withAi = new CrepeHost(document.createElement('div'), I18N, callbacks());
        await withAi.create({ aiEnabled: true, aiProvider: provider });
        expect(crepes[1].addFeature).toHaveBeenCalledWith(ai, expect.objectContaining({ provider }));
    });

    it('the slash menu\'s "Image" action calls onInsertImage', async () => {
        const onInsertImage = vi.fn();
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks({ onInsertImage }));
        await host.create({ aiEnabled: false, aiProvider: undefined });

        const featureConfigs = crepes[0].config.featureConfigs as Record<string, { buildMenu: (builder: ReturnType<typeof fakeGroupBuilder>) => void }>;
        const builder = fakeGroupBuilder({ advanced: [{ key: 'image', onRun: vi.fn() }] });

        featureConfigs[Crepe.Feature.BlockEdit].buildMenu(builder);

        expect(builder.getGroup('advanced').group.items.find((item) => item.key === 'image')?.onRun).toBe(onInsertImage);
    });

    it('the top bar\'s "Image" action calls onInsertImage, and its "more" group gets an AI entry only with AI', async () => {
        const onInsertImage = vi.fn();
        const menu = (): Record<string, FakeMenuItem[]> => ({
            insert: [{ key: 'image', onRun: vi.fn() }],
            formatting: [{ key: 'code' }],
            block: [{ key: 'math' }],
            more: [],
        });

        const withoutAi = new CrepeHost(document.createElement('div'), I18N, callbacks({ onInsertImage }));
        await withoutAi.create({ aiEnabled: false, aiProvider: undefined });
        const topBarWithoutAi = (crepes[0].config.featureConfigs as Record<string, { buildTopBar: (builder: ReturnType<typeof fakeGroupBuilder>) => void }>)[Crepe.Feature.TopBar];
        const builderWithoutAi = fakeGroupBuilder(menu());
        topBarWithoutAi.buildTopBar(builderWithoutAi);

        expect(builderWithoutAi.getGroup('insert').group.items.find((item) => item.key === 'image')?.onRun).toBe(onInsertImage);
        expect(builderWithoutAi.getGroup('more').group.items).toHaveLength(0);

        const withAi = new CrepeHost(document.createElement('div'), I18N, callbacks());
        await withAi.create({ aiEnabled: true, aiProvider: undefined });
        const topBarWithAi = (crepes[1].config.featureConfigs as Record<string, { buildTopBar: (builder: ReturnType<typeof fakeGroupBuilder>) => void }>)[Crepe.Feature.TopBar];
        const builderWithAi = fakeGroupBuilder(menu());
        topBarWithAi.buildTopBar(builderWithAi);

        expect(builderWithAi.getGroup('more').group.items.some((item) => item.key === 'ai')).toBe(true);
    });

    it('replace() abandons AI, replaces with flush, and restores the wrapper', async () => {
        const root = document.createElement('div');
        const host = new CrepeHost(root, I18N, callbacks());
        await host.create({ aiEnabled: true, aiProvider: undefined });
        const crepe = crepes[0];

        host.replace('# New');

        expect(crepe.commands.call).toHaveBeenCalledWith(abortAICmd.key, { keep: false });
        expect(crepe.getMarkdown()).toBe('# New');
        expect(crepe.flushes).toEqual([true]);
        expect(root.querySelector('.ProseMirror')?.parentElement?.className).toBe('editor-content');
    });

    it('replace() does nothing without a Crepe', () => {
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks());

        host.replace('# New');

        expect(crepes).toHaveLength(0);
    });

    it('onChange() survives a recreation', async () => {
        const root = document.createElement('div');
        const host = new CrepeHost(root, I18N, callbacks());
        await host.create({ aiEnabled: false, aiProvider: undefined });

        const seen: string[] = [];
        host.onChange((markdown) => seen.push(markdown));

        host.replace('# one');
        await host.recreate(true, () => undefined);
        host.replace('# two');

        expect(seen).toEqual(['# one', '# two']);
    });

    describe('recreate()', () => {
        it('does nothing, and resolves null, without a Crepe', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            const provider = vi.fn(() => undefined);

            await expect(host.recreate(true, provider)).resolves.toBeNull();
            expect(provider).not.toHaveBeenCalled();
        });

        it('does nothing, and resolves null, when aiEnabled already matches', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ aiEnabled: true, aiProvider: undefined });
            const provider = vi.fn(() => undefined);

            await expect(host.recreate(true, provider)).resolves.toBeNull();
            expect(provider).not.toHaveBeenCalled();
            expect(crepes).toHaveLength(1);
        });

        it('destroys, removes the empty .milkdown, calls provider() after destruction, recreates around the same markdown, and returns it', async () => {
            const root = document.createElement('div');
            const host = new CrepeHost(root, I18N, callbacks());
            await host.create({ markdown: '# A', aiEnabled: false, aiProvider: undefined });
            expect(root.querySelectorAll('.milkdown')).toHaveLength(1);

            const provider = vi.fn(() => undefined);
            const result = await host.recreate(true, provider);

            expect(result).toBe('# A');
            expect(host.markdown()).toBe('# A');
            expect(host.aiEnabled).toBe(true);
            expect(crepes[0].destroy).toHaveBeenCalled();
            expect(crepes[0].destroy.mock.invocationCallOrder[0]).toBeLessThan(provider.mock.invocationCallOrder[0]);
            // The old .milkdown is gone, the new Crepe mounted its own — exactly one.
            expect(root.querySelectorAll('.milkdown')).toHaveLength(1);
            expect(crepes).toHaveLength(2);
        });

        it('two calls in a row run one after another, the last one wins', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ markdown: '# A', aiEnabled: false, aiProvider: undefined });

            const first = host.recreate(true, () => undefined);
            const second = host.recreate(false, () => undefined);

            await expect(first).resolves.toBe('# A');
            await expect(second).resolves.toBe('# A');
            expect(host.aiEnabled).toBe(false);
            // The initial create(), then one real rebuild per call — neither skipped.
            expect(crepes).toHaveLength(3);
        });
    });

    it('whenIdle() waits for the recreation in progress', async () => {
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
        await host.create({ aiEnabled: false, aiProvider: undefined });

        let resolveDestroy!: () => void;
        crepes[0].destroy.mockImplementation(() => new Promise<void>((resolve) => { resolveDestroy = resolve; }));

        const pending = host.recreate(true, () => undefined);
        let idle = false;
        const idlePromise = host.whenIdle().then(() => { idle = true; });
        await Promise.resolve();
        await Promise.resolve();
        expect(idle).toBe(false);

        resolveDestroy();
        await pending;
        await idlePromise;
        expect(idle).toBe(true);
    });

    describe('discardAi()', () => {
        it('does nothing without AI', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ aiEnabled: false, aiProvider: undefined });

            host.discardAi();

            expect(crepes[0].commands.call).not.toHaveBeenCalled();
        });

        it('aborts the AI session, and clears an active diff review too', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ aiEnabled: true, aiProvider: undefined });
            const crepe = crepes[0];

            host.discardAi();
            expect(crepe.commands.call).toHaveBeenCalledWith(abortAICmd.key, { keep: false });
            expect(crepe.commands.call).not.toHaveBeenCalledWith(clearDiffReviewCmd.key);

            crepe.commands.call.mockClear();
            crepe.viewState = { [keyOf(diffPluginKey)]: { active: true } };
            host.discardAi();

            expect(crepe.commands.call).toHaveBeenCalledWith(clearDiffReviewCmd.key);
        });
    });

    it('isAiBusy() sees streaming, review, and the session (test mode)', async () => {
        const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
        await host.create({ aiEnabled: true, aiProvider: undefined });
        const crepe = crepes[0];

        expect(host.isAiBusy()).toBe(false);

        crepe.viewState = { [keyOf(streamingPluginKey)]: { active: true } };
        expect(host.isAiBusy()).toBe(true);

        crepe.viewState = { [keyOf(diffPluginKey)]: { active: true } };
        expect(host.isAiBusy()).toBe(true);

        // The Crepe session, read in test mode (IA-04, lot 03): without a
        // dispatch, the command only says whether it would run.
        crepe.viewState = {};
        crepe.commands.get.mockReturnValue(() => () => true);
        expect(host.isAiBusy()).toBe(true);
    });

    describe('insertImage()', () => {
        it('empties the block and adds an image block with src', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ aiEnabled: false, aiProvider: undefined });
            const crepe = crepes[0];

            host.insertImage('/document/image?path=%2Ffoo.png');

            expect(crepe.commands.call).toHaveBeenCalledWith(clearTextInCurrentBlockCommand.key);
            expect(crepe.commands.call).toHaveBeenCalledWith(addBlockTypeCommand.key, {
                nodeType: 'IMAGE_NODE_TYPE',
                attrs: { src: '/document/image?path=%2Ffoo.png' },
            });
        });

        it('does nothing without a Crepe', () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());

            host.insertImage('/x.png');

            expect(crepes).toHaveLength(0);
        });
    });

    describe('printCopy()', () => {
        const schema = new Schema({
            nodes: {
                doc: { content: 'block+' },
                paragraph: { content: 'text*', group: 'block', toDOM: () => ['p', 0], parseDOM: [{ tag: 'p' }] },
                text: { group: 'inline' },
            },
        });

        it('serializes the document', async () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());
            await host.create({ aiEnabled: false, aiProvider: undefined });
            const doc = schema.node('doc', null, [schema.node('paragraph', null, schema.text('Hello'))]);
            crepes[0].viewState = { schema, doc };

            const copy = host.printCopy();

            expect(copy).not.toBeNull();
            expect(copy!.className).toBe('print-copy document');
            expect(copy!.querySelector('p')?.textContent).toBe('Hello');
        });

        it('is null without a Crepe', () => {
            const host = new CrepeHost(document.createElement('div'), I18N, callbacks());

            expect(host.printCopy()).toBeNull();
        });
    });
});
