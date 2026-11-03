<script setup lang="ts">
import { intlLocale, t } from '@/lib/i18n';
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, Clock, Video } from '@lucide/vue';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import MemberPicker from '@/components/meetings/MemberPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { store, update } from '@/routes/meetings';
import type { MeetingRow } from '@/types';

/**
 * One dialog for the three cases: start an instant meeting, plan one, or
 * change a planned one. Planned meetings send invitations by e-mail with
 * a calendar file; instant ones notify the invitees right away.
 */
const props = defineProps<{
    mode: 'instant' | 'scheduled';
    meeting?: MeetingRow | null;
    members: { id: number; name: string; role?: string }[];
    durations: number[];
    /** The plan's longest meeting (null = no limit). */
    meetingMinutes?: number | null;
    planName?: string;
    slug: string;
    disabled?: boolean;
}>();

const open = defineModel<boolean>('open', { default: false });

const pad = (value: number) => String(value).padStart(2, '0');
const localDate = (date: Date) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
const localTime = (date: Date) =>
    `${pad(date.getHours())}:${pad(date.getMinutes())}`;

/** Next half hour, at least 15 minutes from now. */
function nextSlot(): Date {
    const date = new Date(Date.now() + 15 * 60_000);
    date.setMinutes(date.getMinutes() <= 30 ? 30 : 60, 0, 0);

    return date;
}

const form = useForm({
    mode: props.mode,
    title: '',
    description: '',
    date: '',
    time: '',
    duration_minutes: 30,
    invitees: [] as number[],
});

function reset() {
    const meeting = props.meeting;
    const start = meeting?.scheduledAt
        ? new Date(meeting.scheduledAt)
        : nextSlot();

    form.clearErrors();
    form.mode = props.mode;
    form.title = meeting?.title ?? '';
    form.description = meeting?.description ?? '';
    form.date = localDate(start);
    form.time = localTime(start);
    form.duration_minutes =
        meeting?.durationMinutes ??
        (props.durations.includes(30) ? 30 : (props.durations[0] ?? 30));
    form.invitees = meeting?.participants.map((p) => p.id) ?? [];
}

watch(open, (isOpen) => isOpen && reset(), { immediate: true });

const editing = computed(() => !!props.meeting);
const scheduled = computed(() => props.mode === 'scheduled');

const startsAt = computed(() => {
    if (!form.date || !form.time) {
        return null;
    }

    const date = new Date(`${form.date}T${form.time}:00`);

    return Number.isNaN(date.getTime()) ? null : date;
});

const summary = computed(() => {
    if (!startsAt.value) {
        return '';
    }

    const end = new Date(
        startsAt.value.getTime() + form.duration_minutes * 60_000,
    );
    const day = new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).format(startsAt.value);
    const hour = new Intl.DateTimeFormat(intlLocale(), {
        hour: '2-digit',
        minute: '2-digit',
    });

    return `${day.charAt(0).toUpperCase()}${day.slice(1)}, ${hour.format(startsAt.value)} – ${hour.format(end)}`;
});

function durationLabel(minutes: number): string {
    return minutes < 60
        ? `${minutes} min`
        : `${Math.floor(minutes / 60)} h${minutes % 60 ? ` ${minutes % 60}` : ''}`;
}

function submit() {
    const payload = form.transform((data) => ({
        mode: data.mode,
        title: data.title,
        description: data.description || null,
        invitees: data.invitees,
        ...(scheduled.value
            ? {
                  scheduled_at: startsAt.value?.toISOString() ?? null,
                  duration_minutes: data.duration_minutes,
              }
            : {}),
    }));
    const options = {
        onSuccess: () => (open.value = false),
        preserveScroll: true,
    };

    if (props.meeting) {
        payload.put(
            update({ current_team: props.slug, meeting: props.meeting.uuid })
                .url,
            options,
        );

        return;
    }

    payload.post(store(props.slug).url, options);
}

// The server validates scheduled_at (built from the date and time fields).
const dateError = computed(
    () => (form.errors as Record<string, string | undefined>).scheduled_at,
);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92svh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                        :class="
                            scheduled
                                ? 'bg-informative-subtle text-informative-strong'
                                : 'bg-positive-subtle text-positive-strong'
                        "
                    >
                        <CalendarClock v-if="scheduled" class="size-5" />
                        <Video v-else class="size-5" />
                    </span>
                    <div>
                        <DialogTitle>{{
                            editing
                                ? t('Modifier la réunion')
                                : scheduled
                                  ? t('Planifier une réunion')
                                  : t('Réunion instantanée')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            scheduled
                                ? t(
                                      'Les participants reçoivent une invitation par e-mail à ajouter à leur agenda, puis un rappel avant le début.',
                                  )
                                : t(
                                      'Vous entrez dans la salle tout de suite ; les invités reçoivent une notification avec un bouton « Rejoindre ».',
                                  )
                        }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <form id="meeting-form" class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="meeting-title"
                        >{{ t('Titre') }}<span v-if="scheduled"> *</span></Label
                    >
                    <Input
                        id="meeting-title"
                        v-model="form.title"
                        maxlength="120"
                        :required="scheduled"
                        :placeholder="
                            scheduled
                                ? t('Ex. Revue mensuelle du service client')
                                : t('Facultatif — « Réunion de … » par défaut')
                        "
                    />
                    <InputError :message="form.errors.title" />
                </div>

                <template v-if="scheduled">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="meeting-date">{{ t('Date *') }}</Label>
                            <Input
                                id="meeting-date"
                                v-model="form.date"
                                type="date"
                                :min="localDate(new Date())"
                                required
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="meeting-time">{{ t('Heure *') }}</Label>
                            <Input
                                id="meeting-time"
                                v-model="form.time"
                                type="time"
                                step="300"
                                required
                            />
                        </div>
                    </div>
                    <InputError :message="dateError" />

                    <div class="grid gap-2">
                        <Label id="meeting-duration-label">{{
                            t('Durée')
                        }}</Label>
                        <div
                            class="flex flex-wrap gap-1.5"
                            role="radiogroup"
                            aria-labelledby="meeting-duration-label"
                        >
                            <button
                                v-for="minutes in durations"
                                :key="minutes"
                                type="button"
                                role="radio"
                                :aria-checked="
                                    form.duration_minutes === minutes
                                "
                                class="rounded-full border px-3 py-1 text-sm transition-colors"
                                :class="
                                    form.duration_minutes === minutes
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'hover:bg-accent'
                                "
                                @click="form.duration_minutes = minutes"
                            >
                                {{ durationLabel(minutes) }}
                            </button>
                        </div>
                        <p
                            v-if="meetingMinutes"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'Offre :p0 : :p1 maximum par réunion ; la salle se ferme à la fin du temps.',
                                    {
                                        p0: planName,
                                        p1: durationLabel(meetingMinutes),
                                    },
                                )
                            }}
                        </p>
                        <InputError :message="form.errors.duration_minutes" />
                    </div>

                    <p
                        v-if="summary"
                        class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                    >
                        <Clock class="size-4 text-muted-foreground" />
                        {{ summary }}
                    </p>

                    <div class="grid gap-2">
                        <Label for="meeting-description">{{
                            t('Ordre du jour')
                        }}</Label>
                        <textarea
                            id="meeting-description"
                            v-model="form.description"
                            rows="3"
                            maxlength="2000"
                            class="w-full rounded-[var(--control-radius)] border border-input bg-[var(--field-bg)] px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :placeholder="
                                t(
                                    'Sujets à aborder, documents à préparer… (repris dans l’invitation)',
                                )
                            "
                        />
                        <InputError :message="form.errors.description" />
                    </div>
                </template>

                <p
                    v-if="!scheduled && meetingMinutes"
                    class="flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-sm"
                >
                    <Clock class="size-4 text-muted-foreground" />
                    {{
                        t('Durée maximale : :p0 (offre :p1).', {
                            p0: durationLabel(meetingMinutes),
                            p1: planName,
                        })
                    }}
                </p>

                <div class="grid gap-2">
                    <Label>{{ t('Participants') }}</Label>
                    <MemberPicker v-model="form.invitees" :members="members" />
                    <InputError :message="form.errors.invitees" />
                </div>
            </form>

            <DialogFooter class="gap-2">
                <Button
                    variant="secondary"
                    type="button"
                    @click="open = false"
                    >{{ t('Annuler') }}</Button
                >
                <Button
                    type="submit"
                    form="meeting-form"
                    :disabled="form.processing || disabled"
                >
                    <template v-if="editing">{{
                        t('Enregistrer et prévenir')
                    }}</template>
                    <template v-else-if="scheduled"
                        ><CalendarClock />
                        {{ t('Planifier et inviter') }}</template
                    >
                    <template v-else
                        ><Video /> {{ t('Démarrer maintenant') }}</template
                    >
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
