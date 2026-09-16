const darkMediaQuery = () => window.matchMedia('(prefers-color-scheme: dark)');

/**
 * Resolves the app's [data-theme] ("light" | "dark" | "auto", see
 * EDITOR_THEME.md) to the concrete mode currently in effect.
 */
export function isLightTheme() {
    const mode = document.documentElement.dataset.theme;
    if (mode === 'light') {
        return true;
    }
    if (mode === 'dark') {
        return false;
    }
    return !darkMediaQuery().matches;
}

export function isAutoTheme() {
    return document.documentElement.dataset.theme === 'auto';
}

/**
 * Runs `callback` whenever the OS color scheme changes while the app is in
 * "auto" mode. Returns an unsubscribe function; a no-op when not in "auto"
 * mode, so callers don't need to check isAutoTheme() themselves.
 */
export function watchAutoTheme(callback) {
    if (!isAutoTheme()) {
        return () => {};
    }

    const query = darkMediaQuery();
    query.addEventListener('change', callback);
    return () => query.removeEventListener('change', callback);
}
