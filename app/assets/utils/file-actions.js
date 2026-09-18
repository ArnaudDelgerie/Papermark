// Fetch-based actions on a file, shared by mode-single and mode-dir. Each one
// resolves to the server's reply, {state} plus what the action adds; a failure
// throws an Error carrying the HTTP status (see EDITOR_REACTIVITY.md).
async function post(url, csrfToken, fields, failure) {
    const body = new FormData();
    for (const [name, value] of Object.entries(fields)) {
        body.append(name, value);
    }

    const response = await fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': csrfToken },
        body,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.error || `${failure}: ${response.status}`);
        error.status = response.status;
        throw error;
    }

    return data;
}

// Makes the file the current one: POST /editor/file.
export function setCurrentFile(url, csrfToken, path) {
    return post(url, csrfToken, { path }, 'Open failed');
}

export function deleteFile(url, csrfToken, path) {
    return post(url, csrfToken, { path }, 'Delete failed');
}

// Also resolves to `path`, the new full path.
export function renameFile(url, csrfToken, path, name) {
    return post(url, csrfToken, { path, name }, 'Rename failed');
}
