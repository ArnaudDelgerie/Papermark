import { Controller } from '@hotwired/stimulus';
import { Crepe } from '@milkdown/crepe';

export default class extends Controller {
    static values = {
        csrfToken: String,
        placeholder: { type: String, default: 'Start writing…' },
    };

    #crepe = null;

    connect() {
        this.#crepe = new Crepe({
            root: this.element,
            defaultValue: '',
            features: {
                [Crepe.Feature.Toolbar]: false,
                [Crepe.Feature.TopBar]: true,
            },
            featureConfigs: {
                [Crepe.Feature.Cursor]: {
                    virtual: false,
                },
                [Crepe.Feature.ImageBlock]: {
                    onUpload: (file) => this.#uploadFile(file),
                    blockUploadPlaceholderText: '',
                    inlineUploadPlaceholderText: '',
                },
                [Crepe.Feature.Placeholder]: {
                    text: this.placeholderValue,
                    mode: 'doc',
                },
                [Crepe.Feature.TopBar]: {
                    buildTopBar: (builder) => {
                        const formatting = builder.getGroup('formatting');
                        const codeItem = formatting.group.items.find(
                            (item) => item.key === 'code'
                        );
                        formatting.group.items = formatting.group.items.filter(
                            (item) => item.key !== 'code'
                        );

                        const block = builder.getGroup('block');
                        block.group.items = block.group.items.filter(
                            (item) => item.key !== 'math'
                        );
                        if (codeItem) {
                            block.group.items.unshift(codeItem);
                        }
                    },
                },
            },
        });

        this.#crepe.on((listener) => {
            listener.markdownUpdated((_ctx, markdown, prevMarkdown) => {
                const removed = this.#diffImageUrls(prevMarkdown, markdown);
                removed.forEach((url) => this.#deleteImage(url));
            });
        });

        this.#crepe.create();
    }

    disconnect() {
        this.#crepe?.destroy();
        this.#crepe = null;
    }

    #uploadFile(file) {
        const formData = new FormData();
        formData.append('file', file);

        return fetch('/upload/image', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body: formData,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`Upload failed: ${response.status}`);
                }
                return response.json();
            })
            .then((data) => data.url);
    }

    #deleteImage(url) {
        const formData = new FormData();
        formData.append('url', url);

        fetch('/upload/image', {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': this.csrfTokenValue },
            body: formData,
        }).catch(() => {});
    }

    #extractImageUrls(markdown) {
        const urls = new Set();
        const regex = /\/uploads\/images\/[0-9a-f]{2}\/[0-9a-f]+\.[a-z]+/g;
        let match;
        while ((match = regex.exec(markdown)) !== null) {
            urls.add(match[0]);
        }
        return urls;
    }

    #diffImageUrls(prevMarkdown, markdown) {
        const prev = this.#extractImageUrls(prevMarkdown);
        const current = this.#extractImageUrls(markdown);
        const removed = [];
        for (const url of prev) {
            if (!current.has(url)) {
                removed.push(url);
            }
        }
        return removed;
    }
}
