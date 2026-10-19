/**
 * Monaco Editor with only what the panel's code editors need: the editor
 * features, YAML, shell and PowerShell highlighting and the base worker.
 * Bundled locally (no CDN) and imported on demand by CodeEditor.vue, so
 * pages without an editor do not load it.
 */
import 'monaco-editor/features/register.all';
import 'monaco-editor/languages/definitions/powershell/register';
import 'monaco-editor/languages/definitions/shell/register';
import 'monaco-editor/languages/definitions/yaml/register';
import EditorWorker from 'monaco-editor/editor/editor.worker?worker';
import * as monaco from 'monaco-editor/editor/editor.api';

self.MonacoEnvironment = {
    getWorker: () => new EditorWorker(),
};

export { monaco };
