import { streamingPluginKey, type StreamingAction } from '@milkdown/kit/plugin/streaming';
import type { Node } from '@milkdown/kit/prose/model';
import { Plugin } from '@milkdown/kit/prose/state';
import { $prose } from '@milkdown/utils';

/**
 * The top-level blocks `from`..`to` covers whole, or null. A selection
 * starting at the start of a heading still sits *inside* it, so the streaming
 * plugin merges the answer's first line into that heading as plain text: a
 * translated "## Title" reads "## Title" inside the old h2. Moved to the
 * blocks' boundaries, the answer is parsed as markdown blocks instead.
 */
export function blockRange(doc: Node, from: number, to: number): { from: number; to: number } | null {
    if (from === to) {
        return null;
    }
    const $from = doc.resolve(from);
    const $to = doc.resolve(to);
    // Top-level text blocks only: nested ones (list items, quotes) keep
    // their structure, and a code block takes its answer as plain text.
    if ($from.depth !== 1 || $to.depth !== 1) {
        return null;
    }
    if (!$from.parent.isTextblock || !$to.parent.isTextblock || $from.parent.type.spec.code || $to.parent.type.spec.code) {
        return null;
    }
    if ($from.parentOffset !== 0 || $to.parentOffset !== $to.parent.content.size) {
        return null;
    }

    return { from: $from.before(1), to: $to.after(1) };
}

/**
 * Restarts a streaming session whose range covers whole blocks at their
 * boundaries (see blockRange()). The selection itself is left as is: only
 * where the answer lands changes.
 */
export const aiBlockRange = $prose(() => new Plugin({
    appendTransaction(transactions, _oldState, state) {
        for (const tr of transactions) {
            const action = tr.getMeta(streamingPluginKey) as StreamingAction | undefined;
            if (action?.type !== 'start' || action.insertPos === undefined || action.insertEndPos === undefined) {
                continue;
            }
            const range = blockRange(action.originalDoc, action.insertPos, action.insertEndPos);
            if (range) {
                return state.tr.setMeta(streamingPluginKey, { ...action, insertPos: range.from, insertEndPos: range.to });
            }
        }

        return null;
    },
}));
