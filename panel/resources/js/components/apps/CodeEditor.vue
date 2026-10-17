<script setup lang="ts">
import { yaml } from '@codemirror/lang-yaml';
import { StreamLanguage } from '@codemirror/language';
import { powerShell } from '@codemirror/legacy-modes/mode/powershell';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { Compartment, EditorState } from '@codemirror/state';
import { EditorView } from '@codemirror/view';
import { basicSetup } from 'codemirror';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A code editor for recipe.yaml and recipe scripts (docs/adr/0018 §5),
 * CodeMirror 6 with YAML, shell or PowerShell highlighting.
 */
const props = withDefaults(
    defineProps<{
        language?: 'yaml' | 'shell' | 'powershell';
        readonly?: boolean;
        minHeight?: string;
    }>(),
    { language: 'shell', readonly: false, minHeight: '18rem' },
);

const value = defineModel<string>({ required: true });

const host = ref<HTMLDivElement | null>(null);
let view: EditorView | null = null;
const languageSlot = new Compartment();
const readonlySlot = new Compartment();

const languageFor = (language: typeof props.language) =>
    language === 'yaml'
        ? yaml()
        : StreamLanguage.define(language === 'powershell' ? powerShell : shell);

const theme = EditorView.theme({
    '&': {
        fontSize: '13px',
        backgroundColor: 'transparent',
        color: 'inherit',
    },
    '.cm-scroller': {
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, monospace',
        minHeight: props.minHeight,
    },
    '.cm-gutters': {
        backgroundColor: 'transparent',
        color: 'var(--muted-foreground)',
        borderRight: '1px solid var(--border)',
    },
    '.cm-activeLine, .cm-activeLineGutter': {
        backgroundColor: 'color-mix(in oklab, var(--muted) 60%, transparent)',
    },
    '&.cm-focused': { outline: 'none' },
});

onMounted(() => {
    if (host.value === null) {
        return;
    }

    view = new EditorView({
        parent: host.value,
        state: EditorState.create({
            doc: value.value,
            extensions: [
                basicSetup,
                theme,
                languageSlot.of(languageFor(props.language)),
                readonlySlot.of(EditorState.readOnly.of(props.readonly)),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) {
                        value.value = update.state.doc.toString();
                    }
                }),
            ],
        }),
    });
});

// Outside changes (another file, a reload) replace the document.
watch(value, (text) => {
    if (view !== null && text !== view.state.doc.toString()) {
        view.dispatch({
            changes: { from: 0, to: view.state.doc.length, insert: text },
        });
    }
});

watch(
    () => props.language,
    (language) =>
        view?.dispatch({
            effects: languageSlot.reconfigure(languageFor(language)),
        }),
);

watch(
    () => props.readonly,
    (readonly) =>
        view?.dispatch({
            effects: readonlySlot.reconfigure(
                EditorState.readOnly.of(readonly),
            ),
        }),
);

onBeforeUnmount(() => view?.destroy());
</script>

<template>
    <div
        ref="host"
        class="overflow-hidden rounded-lg border bg-background"
        data-test="code-editor"
    />
</template>
