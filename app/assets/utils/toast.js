export function showToast(type, message) {
    window.dispatchEvent(new CustomEvent('toast:show', { detail: { type, message } }));
}
