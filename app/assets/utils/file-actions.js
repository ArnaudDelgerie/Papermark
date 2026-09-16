// Fetch-based delete/rename, shared by mode-single and mode-dir — same
// pattern as the editor's own file actions (see EDITOR_FIX.md #5).
export async function deleteFile(url, csrfToken, path) {
    const body = new FormData();
    body.append('path', path);

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body,
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || `Delete failed: ${response.status}`);
    }
}

// Resolves to the new full path.
export async function renameFile(url, csrfToken, path, name) {
    const body = new FormData();
    body.append('path', path);
    body.append('name', name);

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body,
    });

    if (!response.ok) {
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || `Rename failed: ${response.status}`);
    }

    const { path: newPath } = await response.json();

    return newPath;
}
