<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A code editor for recipe.yaml, recipe scripts (docs/adr/0018 §5) and the
 * guest scripts of OS templates: Monaco Editor with YAML, shell or
 * PowerShell highlighting. Monaco is loaded when the first editor mounts.
 */
const props = withDefaults(
    defineProps<{
        language?: 'yaml' | 'shell' | 'powershell';
        readonly?: boolean;
        minHeight?: string;
        ariaLabel?: string;
        placeholder?: string;
    }>(),
    { language: 'shell', readonly: false, minHeight: '18rem' },
);

const value = defineModel<string>({ required: true });

type Monaco = typeof import('@/lib/monaco').monaco;

const host = ref<HTMLDivElement | null>(null);
const loading = ref(true);
// Grows with the text, from minHeight up to 80% of the window.
const contentHeight = ref(0);
let monaco: Monaco | null = null;
let editor: ReturnType<Monaco['editor']['create']> | null = null;
let themeObserver: MutationObserver | null = null;
let destroyed = false;

const themeName = () =>
    document.documentElement.classList.contains('dark') ? 'vs-dark' : 'vs';

onMounted(async () => {
    ({ monaco } = await import('@/lib/monaco'));

    if (destroyed || host.value === null) {
        return;
    }

    editor = monaco.editor.create(host.value, {
        value: value.value,
        language: props.language,
        readOnly: props.readonly,
        theme: themeName(),
        ariaLabel: props.ariaLabel,
        placeholder: props.placeholder,
        automaticLayout: true,
        minimap: { enabled: false },
        scrollBeyondLastLine: false,
        fontSize: 13,
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        tabSize: 4,
        renderWhitespace: 'boundary',
        fixedOverflowWidgets: true,
    });
    loading.value = false;

    editor.onDidChangeModelContent(() => {
        const text = editor?.getValue() ?? '';

        if (text !== value.value) {
            value.value = text;
        }
    });

    contentHeight.value = editor.getContentHeight();
    editor.onDidContentSizeChange((event) => {
        contentHeight.value = event.contentHeight;
    });

    // The appearance switch toggles the `dark` class on <html>.
    themeObserver = new MutationObserver(() =>
        monaco?.editor.setTheme(themeName()),
    );
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});

// Outside changes (another file, a reload) replace the document.
watch(value, (text) => {
    if (editor !== null && text !== editor.getValue()) {
        editor.setValue(text);
    }
});

watch(
    () => props.language,
    (language) => {
        const model = editor?.getModel();

        if (monaco !== null && model) {
            monaco.editor.setModelLanguage(model, language);
        }
    },
);

watch(
    () => props.readonly,
    (readonly) => editor?.updateOptions({ readOnly: readonly }),
);

onBeforeUnmount(() => {
    destroyed = true;
    themeObserver?.disconnect();
    editor?.getModel()?.dispose();
    editor?.dispose();
});
</script>

<template>
    <div
        class="relative overflow-hidden rounded-lg border bg-background"
        :style="{
            height: `max(${minHeight}, min(${contentHeight}px, 80vh))`,
        }"
        data-test="code-editor"
    >
        <div ref="host" class="h-full w-full" />
        <p
            v-if="loading"
            class="absolute inset-0 grid place-items-center text-sm text-muted-foreground"
        >
            {{ $t('Loading editor…') }}
        </p>
    </div>
</template>
