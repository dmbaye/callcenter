<script setup lang="ts">
import { t } from '@/lib/i18n';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { Check, Copy, Download, LoaderCircle, NotebookPen } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { getEcho } from '@/lib/echo';
import { formatRelative } from '@/lib/format';
import { notes as notesRoute } from '@/routes/meetings';

/**
 * Shared meeting notes: anyone invited can write before, during or after
 * the meeting. Saves on its own a moment after typing stops; a colleague's
 * save shows up live, and a clash is never overwritten silently.
 */
const props = defineProps<{
    slug: string;
    meetingUuid: string;
    meetingTitle: string;
    notes: string | null;
    updatedAt: string | null;
    updatedBy: string | null;
    canEdit: boolean;
    /** Taller editor (dialog) vs side panel. */
    rows?: number;
}>();

const emit = defineEmits<{
    saved: [
        payload: {
            notes: string;
            updatedAt: string | null;
            updatedBy: string | null;
        },
    ];
}>();

type Payload = {
    notes: string;
    updatedAt: string | null;
    updatedBy: string | null;
    updatedById?: number | null;
};

const page = usePage();
const text = ref(props.notes ?? '');
const savedText = ref(props.notes ?? '');
/** Version this editor is based on (sent as `base`). */
const base = ref<string | null>(props.updatedAt);
const lastBy = ref(props.updatedBy);
const lastAt = ref(props.updatedAt);
const status = ref<'idle' | 'saving' | 'saved' | 'error'>('idle');
const conflict = ref<Payload | null>(null);
const remote = ref<Payload | null>(null);

const dirty = computed(() => text.value !== savedText.value);

let timer: ReturnType<typeof setTimeout> | undefined;

async function save(force = false) {
    clearTimeout(timer);

    if (!props.canEdit || (!dirty.value && !force)) {
        return;
    }

    status.value = 'saving';
    const sending = text.value;

    try {
        const { data } = await axios.put<Payload>(
            notesRoute({ current_team: props.slug, meeting: props.meetingUuid })
                .url,
            { notes: sending, base: base.value, force },
        );
        savedText.value = sending;
        base.value = data.updatedAt;
        lastAt.value = data.updatedAt;
        lastBy.value = data.updatedBy;
        conflict.value = null;
        remote.value = null;
        status.value = 'saved';
        emit('saved', {
            notes: data.notes,
            updatedAt: data.updatedAt,
            updatedBy: data.updatedBy,
        });
    } catch (exception) {
        if (
            axios.isAxiosError(exception) &&
            exception.response?.status === 409
        ) {
            conflict.value = exception.response.data as Payload;
            status.value = 'idle';

            return;
        }

        status.value = 'error';
    }
}

watch(text, () => {
    if (!props.canEdit) {
        return;
    }

    status.value = 'idle';
    clearTimeout(timer);
    timer = setTimeout(() => void save(), 1200);
});

/** Take the colleague's version (theirs replaces mine). */
function useTheirs(payload: Payload) {
    text.value = payload.notes;
    savedText.value = payload.notes;
    base.value = payload.updatedAt;
    lastAt.value = payload.updatedAt;
    lastBy.value = payload.updatedBy;
    conflict.value = null;
    remote.value = null;
    clearTimeout(timer);
    status.value = 'saved';
}

function keepMine() {
    void save(true);
}

/* Live: someone else saved. */
let channel: string | null = null;

function onRemote(payload: Payload) {
    if (payload.updatedById === page.props.auth?.user?.id) {
        return;
    }

    // Nothing pending here: just show their text.
    if (!dirty.value) {
        useTheirs(payload);

        return;
    }

    remote.value = payload;
}

onMounted(() => {
    const echo = getEcho();

    if (echo) {
        channel = `meeting.${props.meetingUuid}`;
        echo.private(channel).listen('.meeting.notes', onRemote);
    }
});

onBeforeUnmount(() => {
    if (dirty.value) {
        void save();
    }

    clearTimeout(timer);

    if (channel) {
        getEcho()?.private(channel).stopListening('.meeting.notes', onRemote);
    }
});

async function copy() {
    try {
        await navigator.clipboard.writeText(text.value);
        toast.success(t('Notes copiées.'));
    } catch {
        toast.error(t('Copie impossible.'));
    }
}

function download() {
    const blob = new Blob([`${props.meetingTitle}\n\n${text.value}\n`], {
        type: 'text/plain;charset=utf-8',
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `notes-${
        props.meetingTitle
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '') || 'reunion'
    }.txt`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <div class="flex h-full flex-col gap-3">
        <div
            v-if="conflict"
            class="space-y-2 rounded-lg border border-notice-border bg-notice-subtle p-3 text-sm text-notice-strong"
            role="alert"
        >
            <p>
                <strong>{{ conflict.updatedBy ?? t('Un collègue') }}</strong>
                {{ t('a modifié les notes pendant que vous écriviez.') }}
            </p>
            <div class="flex flex-wrap gap-2">
                <button
                    type="button"
                    class="rounded-full bg-background px-3 py-1 text-xs font-semibold text-foreground"
                    @click="useTheirs(conflict)"
                >
                    {{ t('Voir leur version') }}
                </button>
                <button
                    type="button"
                    class="rounded-full px-3 py-1 text-xs font-semibold underline"
                    @click="keepMine"
                >
                    {{ t('Garder la mienne') }}
                </button>
            </div>
        </div>

        <div
            v-else-if="remote"
            class="flex flex-wrap items-center gap-2 rounded-lg bg-informative-subtle px-3 py-2 text-xs text-informative-strong"
        >
            {{
                t(':p0 vient de modifier les notes.', {
                    p0: remote.updatedBy ?? t('Un collègue'),
                })
            }}
            <button
                type="button"
                class="font-semibold underline"
                @click="useTheirs(remote)"
            >
                {{ t('Afficher') }}
            </button>
        </div>

        <textarea
            v-model="text"
            :readonly="!canEdit"
            :rows="rows ?? 12"
            maxlength="50000"
            class="min-h-40 w-full flex-1 resize-none rounded-lg border border-input bg-[var(--field-bg)] px-3 py-2.5 text-sm leading-relaxed placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :placeholder="
                canEdit
                    ? t(
                          'Décisions, actions, qui fait quoi… Les notes sont partagées avec les participants et enregistrées automatiquement.',
                      )
                    : t('Aucune note pour cette réunion.')
            "
            :aria-label="t('Notes de la réunion')"
            @blur="save()"
        />

        <div
            class="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5" aria-live="polite">
                <template v-if="status === 'saving'">
                    <LoaderCircle class="size-3.5 animate-spin" />
                    {{ t('Enregistrement…') }}
                </template>
                <template v-else-if="status === 'error'">
                    <span class="text-negative-strong">{{
                        t('Non enregistré.')
                    }}</span>
                    <button
                        type="button"
                        class="font-semibold underline"
                        @click="save(true)"
                    >
                        {{ t('Réessayer') }}
                    </button>
                </template>
                <template v-else-if="dirty">
                    <NotebookPen class="size-3.5" />
                    {{ t('Modifications en cours…') }}
                </template>
                <template v-else-if="lastAt">
                    <Check class="size-3.5 text-positive" />
                    {{ lastBy ? `${lastBy}, ` : ''
                    }}{{ formatRelative(lastAt) }}
                </template>
                <template v-else>
                    <NotebookPen class="size-3.5" />
                    {{ t('Partagées avec les participants') }}
                </template>
            </span>
            <span class="flex items-center gap-1">
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 hover:bg-accent hover:text-foreground"
                    :disabled="!text"
                    @click="copy"
                >
                    <Copy class="size-3.5" /> {{ t('Copier') }}
                </button>
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md px-2 py-1 hover:bg-accent hover:text-foreground"
                    :disabled="!text"
                    @click="download"
                >
                    <Download class="size-3.5" /> {{ t('.txt') }}
                </button>
            </span>
        </div>
    </div>
</template>
