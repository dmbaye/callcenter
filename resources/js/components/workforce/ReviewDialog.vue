<script setup lang="ts">
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { t } from '@/lib/i18n';

/** Reject (reason required) or approve (optional note). */
const props = defineProps<{
    open: boolean;
    approve: boolean;
    title: string;
    description?: string;
    processing?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [note: string];
}>();

const note = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) {
            note.value = '';
        }
    },
);
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    description
                }}</DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="review-note">{{
                    approve ? t('Note (facultatif)') : t('Motif du refus')
                }}</Label>
                <Textarea
                    id="review-note"
                    v-model="note"
                    rows="3"
                    maxlength="500"
                    :placeholder="
                        approve
                            ? t('Visible par l’agent')
                            : t('Expliquez le refus à l’agent')
                    "
                />
            </div>
            <DialogFooter>
                <Button variant="ghost" @click="emit('update:open', false)">{{
                    t('Annuler')
                }}</Button>
                <Button
                    :variant="approve ? 'default' : 'destructive'"
                    :disabled="processing || (!approve && note.trim() === '')"
                    @click="emit('confirm', note.trim())"
                >
                    {{ approve ? t('Approuver') : t('Refuser') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
