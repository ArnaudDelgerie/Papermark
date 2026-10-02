import { showToast } from './toast';

export interface CopyToClipboardMessages {
    success: string;
    failure: string;
}

export async function copyToClipboard(text: string | Promise<string>, { success, failure }: CopyToClipboardMessages): Promise<void> {
    try {
        const content = await text;
        await navigator.clipboard.writeText(content);
        showToast('success', success);
    } catch (err) {
        console.error('Copy to the clipboard failed:', err);
        showToast('error', failure);
    }
}
