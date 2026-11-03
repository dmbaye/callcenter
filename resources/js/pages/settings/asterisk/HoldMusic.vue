<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Music, Trash2, Upload } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { formatDate, formatDuration } from '@/lib/format';
import { destroy, index, play, store } from '@/routes/asterisk/hold-music';

type Track = {
    id: number;
    name: string;
    size: number;
    duration: number | null;
    uploadedAt: string | null;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: "Musique d'attente", href: index() }],
    },
});

const props = defineProps<{
    available: boolean;
    className: string;
    tracks: Track[];
    maxTracks: number;
    maxUploadKb: number;
}>();

const input = ref<HTMLInputElement | null>(null);
const form = useForm<{ file: File | null }>({ file: null });

function pick(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    if (!file) {
        return;
    }

    form.file = file;
    form.post(store().url, {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => {
            form.reset();

            if (input.value) {
                input.value.value = '';
            }
        },
    });
}

function remove(track: Track): void {
    if (
        !window.confirm(
            t('Retirer « :p0 » de la musique d’attente ?', { p0: track.name }),
        )
    ) {
        return;
    }

    router.delete(destroy(track.id).url, { preserveScroll: true });
}

const full = () => props.tracks.length >= props.maxTracks;
</script>

<template>
    <Head :title="t('Musique d’attente')" />

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('Musique d’attente')"
            :description="
                t(
                    'Votre musique est jouée aux appelants qui attendent dans vos files et aux appels qu’un agent met en attente. Sans fichier, la musique de la plateforme est jouée.',
                )
            "
        />

        <Alert v-if="!available" variant="destructive">
            <AlertDescription>
                {{
                    t(
                        'La musique d’attente personnalisée n’est pas disponible sur le serveur Asterisk de la plateforme. Contactez le support.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <input
                    ref="input"
                    type="file"
                    accept=".wav,.mp3,.ogg,.oga,.m4a,.aac,.flac,audio/*"
                    class="hidden"
                    @change="pick"
                />
                <Button
                    :disabled="form.processing || full()"
                    @click="input?.click()"
                >
                    <Upload class="size-4" />
                    {{
                        form.processing
                            ? t('Envoi et conversion…')
                            : t('Ajouter un fichier')
                    }}
                </Button>
                <p class="text-xs text-muted-foreground">
                    {{
                        t(
                            'MP3, WAV, OGG… jusqu’à :p0 Mo, :p1 fichiers au plus. Converti en qualité téléphone (8 kHz mono).',
                            {
                                p0: Math.round(maxUploadKb / 1024),
                                p1: maxTracks,
                            },
                        )
                    }}
                </p>
            </div>
            <InputError :message="form.errors.file" />

            <ul v-if="tracks.length" class="divide-y rounded-lg border">
                <li
                    v-for="track in tracks"
                    :key="track.id"
                    class="flex flex-wrap items-center gap-3 p-3 text-sm"
                >
                    <Music class="size-4 shrink-0 text-muted-foreground" />
                    <div class="min-w-40 space-y-0.5">
                        <p class="font-medium">{{ track.name }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ track.duration ? formatDuration(track.duration) : '—' }}
                            <template v-if="track.uploadedAt">
                                · {{ formatDate(track.uploadedAt) }}</template
                            >
                        </p>
                    </div>
                    <audio
                        controls
                        preload="none"
                        class="h-9 max-w-full flex-1"
                        :src="play(track.id).url"
                    />
                    <Button
                        size="sm"
                        variant="ghost"
                        :aria-label="t('Retirer')"
                        @click="remove(track)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    t(
                        'Aucun fichier : la musique de la plateforme est jouée.',
                    )
                }}
            </p>

            <p v-if="tracks.length" class="text-xs text-muted-foreground">
                {{
                    t(
                        'Les fichiers sont joués dans un ordre aléatoire. Choisissez la musique de chaque file dans Files d’attente.',
                    )
                }}
            </p>
        </template>
    </div>
</template>
