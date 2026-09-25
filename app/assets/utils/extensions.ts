/**
 * What the file pickers offer, mirroring the server's own lists: the picker
 * only narrows the dialog, the server stays the sole judge of what it
 * accepts — keep both sides in step.
 *
 * Documents: the DocumentExtension enum (src/Enum/DocumentExtension.php).
 * Images: DocumentStore::IMAGE_EXTENSIONS and
 * MarkdownReferenceScanner::IMAGE_EXTENSIONS.
 */
export const DOCUMENT_EXTENSIONS = ['md', 'markdown', 'txt'] as const;

export const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif'] as const;
