<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type Option = { value: string | number; label: string };

const props = defineProps<{
    modelValue?: string | number | null;
    options: Option[];
    placeholder?: string;
    id?: string;
    name?: string;
    disabled?: boolean;
    required?: boolean;
    class?: HTMLAttributes['class'];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();

const current = computed(() =>
    props.modelValue === null ||
    props.modelValue === undefined ||
    props.modelValue === ''
        ? undefined
        : String(props.modelValue),
);
</script>

<template>
    <Select
        :model-value="current"
        :name="name"
        :disabled="disabled"
        :required="required"
        @update:model-value="
            (value) => emit('update:modelValue', String(value))
        "
    >
        <SelectTrigger :id="id" :class="cn('w-full', props.class)">
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem
                v-for="option in options"
                :key="option.value"
                :value="String(option.value)"
            >
                {{ option.label }}
            </SelectItem>
        </SelectContent>
    </Select>
</template>
