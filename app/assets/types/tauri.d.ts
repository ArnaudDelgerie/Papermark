// The hub injects `window.__TAURI__` into the webview; outside the hub it is
// absent. Only what utils/tauri uses is declared (CONTRACT.md, TFSApp hub).
interface Window {
    __TAURI__?: {
        core?: {
            invoke<T = unknown>(command: string, args?: Record<string, unknown>): Promise<T>;
        };
        webviewWindow?: {
            getCurrentWebviewWindow(): {
                listen(event: string, handler: () => void): Promise<() => void>;
            };
        };
    };
}
