import { Controller } from '@hotwired/stimulus';
import { Crepe } from '@milkdown/crepe';
import { trailing } from '@milkdown/plugin-trailing';

export default class extends Controller {
    static values = {
        csrfToken: String,
        i18n: {
            type: Object,
            default: {
                placeholder: 'Start writing…',
                link: { confirm: 'Confirm', inputPlaceholder: 'Paste link…' },
                slashMenu: {
                    text: 'Text',
                    paragraph: 'Text',
                    h1: 'Heading 1',
                    h2: 'Heading 2',
                    h3: 'Heading 3',
                    h4: 'Heading 4',
                    h5: 'Heading 5',
                    h6: 'Heading 6',
                    quote: 'Quote',
                    divider: 'Divider',
                    list: 'List',
                    bulletList: 'Bullet List',
                    orderedList: 'Ordered List',
                    taskList: 'Task List',
                    advanced: 'Advanced',
                    image: 'Image',
                    code: 'Code',
                    table: 'Table',
                },
            },
        },
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
                    text: this.i18nValue.placeholder,
                    mode: 'doc',
                },
                [Crepe.Feature.LinkTooltip]: {
                    confirmButton: this.i18nValue.link.confirm,
                    inputPlaceholder: this.i18nValue.link.inputPlaceholder,
                },
                [Crepe.Feature.BlockEdit]: {
                    textGroup: {
                        label: this.i18nValue.slashMenu.text,
                        text: { label: this.i18nValue.slashMenu.paragraph },
                        h1: { label: this.i18nValue.slashMenu.h1 },
                        h2: { label: this.i18nValue.slashMenu.h2 },
                        h3: { label: this.i18nValue.slashMenu.h3 },
                        h4: { label: this.i18nValue.slashMenu.h4 },
                        h5: { label: this.i18nValue.slashMenu.h5 },
                        h6: { label: this.i18nValue.slashMenu.h6 },
                        quote: { label: this.i18nValue.slashMenu.quote },
                        divider: { label: this.i18nValue.slashMenu.divider },
                    },
                    listGroup: {
                        label: this.i18nValue.slashMenu.list,
                        bulletList: { label: this.i18nValue.slashMenu.bulletList },
                        orderedList: { label: this.i18nValue.slashMenu.orderedList },
                        taskList: { label: this.i18nValue.slashMenu.taskList },
                    },
                    advancedGroup: {
                        label: this.i18nValue.slashMenu.advanced,
                        image: { label: this.i18nValue.slashMenu.image },
                        codeBlock: { label: this.i18nValue.slashMenu.code },
                        table: { label: this.i18nValue.slashMenu.table },
                    },
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

        this.#crepe.addFeature((editor) => {
            editor.use(trailing);
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
