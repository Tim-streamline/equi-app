<script>
    import { onMount } from 'svelte';
    import DOMPurify from 'dompurify';
    import 'easymde/dist/easymde.min.css';
    import 'font-awesome/css/font-awesome.min.css';

    let { value = $bindable(''), label = 'Body (markdown)' } = $props();
    let element;
    let editor = $state.raw(null);
    let loadError = $state('');

    onMount(() => {
        let disposed = false;
        const syncValue = () => { value = editor.value(); };

        import('easymde').then(({ default: EasyMDE }) => {
            if (disposed) return;
            editor = new EasyMDE({
                element,
                initialValue: value,
                autoDownloadFontAwesome: false,
                spellChecker: false,
                nativeSpellcheck: true,
                autosave: { enabled: false },
                forceSync: true,
                minHeight: '320px',
                inputStyle: 'contenteditable',
                toolbar: [
                    'bold', 'italic', 'strikethrough', 'heading', '|',
                    'quote', 'unordered-list', 'ordered-list', '|',
                    'link', 'image', 'table', '|',
                    'preview', 'side-by-side', 'fullscreen', '|', 'undo', 'redo',
                ],
                renderingConfig: {
                    sanitizerFunction: (html) => DOMPurify.sanitize(html, { USE_PROFILES: { html: true } }),
                },
            });
            const input = editor.codemirror.getInputField();
            input.setAttribute('role', 'textbox');
            input.setAttribute('aria-multiline', 'true');
            input.setAttribute('aria-label', label);
            editor.codemirror.on('change', syncValue);
        }).catch(() => {
            if (!disposed) loadError = 'De markdown-editor kon niet laden. Je kunt de tekst hieronder blijven bewerken.';
        });

        return () => {
            disposed = true;
            if (!editor) return;
            editor.codemirror.off('change', syncValue);
            if (editor.isFullscreenActive()) editor.toggleFullScreen();
            editor.toTextArea();
            editor.cleanup();
            editor = null;
        };
    });

    $effect(() => {
        if (editor && editor.value() !== value) editor.value(value ?? '');
    });

    export function insertText(text) {
        if (editor) {
            if (editor.isPreviewActive()) editor.togglePreview();
            editor.codemirror.replaceSelection(text, 'end');
            editor.codemirror.focus();
            return;
        }

        const start = element?.selectionStart ?? value.length;
        const end = element?.selectionEnd ?? start;
        value = value.slice(0, start) + text + value.slice(end);
        if (element) {
            element.value = value;
            element.focus();
            element.setSelectionRange(start + text.length, start + text.length);
        }
    }
</script>

<div class="markdown-editor min-w-0">
    {#if loadError}<p role="alert" class="mb-2 text-sm text-destructive">{loadError}</p>{/if}
    <textarea bind:this={element} bind:value aria-label={label} class="min-h-80 w-full rounded-md border border-input bg-background p-3 font-mono text-sm"></textarea>
</div>

<style>
    :global(.markdown-editor .CodeMirror),
    :global(.markdown-editor .editor-toolbar),
    :global(.markdown-editor .editor-preview-side) {
        border-color: var(--input);
        background: var(--background);
        color: var(--foreground);
    }
    :global(.markdown-editor .CodeMirror) { font-size: 0.875rem; }
    :global(.markdown-editor .editor-toolbar) { opacity: 1; }
    :global(.markdown-editor .editor-toolbar button) { color: var(--foreground); }
    :global(.markdown-editor .editor-toolbar button:hover),
    :global(.markdown-editor .editor-toolbar button.active) { background: var(--muted); }
    :global(.markdown-editor .editor-statusbar) { color: var(--muted-foreground); }
    :global(.markdown-editor .CodeMirror-fullscreen) { z-index: 60; }
    :global(.markdown-editor .editor-toolbar.fullscreen) { z-index: 61; }
    :global(.markdown-editor .editor-preview-side) { z-index: 61; }
    :global(.markdown-editor .editor-preview) { background: var(--background); color: var(--foreground); }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h1) { font-size: 2rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h2) { font-size: 1.5rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h3) { font-size: 1.25rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h1),
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h2),
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) h3) { font-weight: 700; margin: 1rem 0 0.5rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) p) { margin-bottom: 0.75rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) ul) { list-style: disc; padding-left: 1.5rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) ol) { list-style: decimal; padding-left: 1.5rem; }
    :global(.markdown-editor :is(.editor-preview, .editor-preview-side) a) { color: var(--primary); text-decoration: underline; }
</style>
