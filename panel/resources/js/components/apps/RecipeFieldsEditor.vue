<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import NativeSelect from '@/components/admin/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { INPUT_TYPES, OUTPUT_TYPES } from '@/lib/recipes';
import type { RecipeInput, RecipeOutput } from '@/lib/recipes';

/**
 * Rows of a recipe's (or an action's) inputs or outputs: key, type,
 * English and Turkish label and the settings of the type (docs/adr/0018 §5).
 */
const props = defineProps<{
    kind: 'inputs' | 'outputs';
    readonly?: boolean;
}>();

const rows = defineModel<(RecipeInput | RecipeOutput)[]>({ required: true });

const types = (props.kind === 'inputs' ? INPUT_TYPES : OUTPUT_TYPES).map(
    (type) => ({ value: type, label: type }),
);

const add = () => {
    rows.value = [
        ...rows.value,
        {
            key: '',
            type: props.kind === 'inputs' ? 'string' : 'text',
            label: { en: '', tr: '' },
        },
    ];
};

const remove = (index: number) => {
    rows.value = rows.value.filter((_, position) => position !== index);
};

const label = (row: RecipeInput | RecipeOutput) =>
    (row.label ??= { en: '', tr: '' });

const asInput = (row: RecipeInput | RecipeOutput) => row as RecipeInput;
const asOutput = (row: RecipeInput | RecipeOutput) => row as RecipeOutput;

const options = (row: RecipeInput | RecipeOutput) =>
    (asInput(row).options ?? []).join(', ');

const setOptions = (
    row: RecipeInput | RecipeOutput,
    value: string | number,
) => {
    asInput(row).options = String(value)
        .split(',')
        .map((option) => option.trim())
        .filter((option) => option !== '');
};
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="grid gap-2 rounded-lg border p-3 sm:grid-cols-6"
            data-test="recipe-field-row"
        >
            <Input
                v-model="row.key"
                :placeholder="$t('Key')"
                :disabled="readonly"
                class="font-mono sm:col-span-1"
            />
            <NativeSelect
                v-model="row.type"
                :options="types"
                :disabled="readonly"
                class="sm:col-span-1"
            />
            <Input
                v-model="label(row).en"
                :placeholder="$t('Label (English)')"
                :disabled="readonly"
                class="sm:col-span-2"
            />
            <Input
                v-model="label(row).tr"
                :placeholder="$t('Label (Turkish)')"
                :disabled="readonly"
                class="sm:col-span-2"
            />

            <template v-if="kind === 'inputs'">
                <Input
                    v-model="asInput(row).default as string"
                    :placeholder="$t('Default')"
                    :disabled="readonly"
                    class="sm:col-span-2"
                />
                <Input
                    v-if="row.type === 'enum'"
                    :model-value="options(row)"
                    :placeholder="$t('Options, separated by commas')"
                    :disabled="readonly"
                    class="sm:col-span-2"
                    @update:model-value="setOptions(row, $event)"
                />
                <template
                    v-if="['integer', 'port', 'password'].includes(row.type)"
                >
                    <Input
                        v-model.number="asInput(row).min"
                        type="number"
                        :placeholder="$t('Minimum')"
                        :disabled="readonly"
                    />
                    <Input
                        v-model.number="asInput(row).max"
                        type="number"
                        :placeholder="$t('Maximum')"
                        :disabled="readonly"
                    />
                </template>
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="asInput(row).required === true"
                        :disabled="readonly"
                        @update:model-value="
                            asInput(row).required = $event === true
                        "
                    />
                    {{ $t('Required') }}
                </label>
            </template>

            <template v-else>
                <Input
                    v-if="row.type === 'file'"
                    v-model="asOutput(row).filename"
                    :placeholder="$t('File name, e.g. client.conf')"
                    :disabled="readonly"
                    class="font-mono sm:col-span-2"
                />
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="asOutput(row).secret === true"
                        :disabled="readonly"
                        @update:model-value="
                            asOutput(row).secret = $event === true
                        "
                    />
                    {{ $t('Secret') }}
                </label>
            </template>

            <div class="flex justify-end sm:col-start-6">
                <Button
                    v-if="!readonly"
                    variant="ghost"
                    size="icon"
                    :aria-label="$t('Remove')"
                    @click="remove(index)"
                >
                    <Trash2 class="h-4 w-4" />
                </Button>
            </div>
        </div>

        <div v-if="!readonly">
            <Button variant="outline" size="sm" @click="add">
                <Plus class="h-4 w-4" />
                {{ kind === 'inputs' ? $t('Add input') : $t('Add output') }}
            </Button>
        </div>
    </div>
</template>
