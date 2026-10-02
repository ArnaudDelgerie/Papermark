export type ToastType = 'success' | 'error';

export function showToast(type: ToastType, message: string): void {
    window.dispatchEvent(new CustomEvent('toast:show', { detail: { type, message } }));
}
