<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Trash2, Voicemail as VoicemailIcon } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatDuration } from '@/lib/format';
import { index } from '@/routes/asterisk/voicemail';
import { destroy as destroyBox, save as saveBox } from '@/routes/asterisk/voicemail/boxes';
import {
    destroy as destroyMessage,
    play,
} from '@/routes/asterisk/voicemail/messages';

type Box = {
    mailbox: string;
    fullname: string | null;
    email: string | null;
    has_pin: boolean;
};
type ExtensionRow = {
    extension: string;
    user: string | null;
    userEmail: string | null;
    box: Box | null;
};
type Message = {
    id: string;
    mailbox: string;
    folder: string;
    msgnum: number;
    callerid: string;
    received_at: string | null;
    duration: number;
    size: number;
};

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Messagerie vocale', href: index() }],
    },
});

const props = defineProps<{
    available: boolean;
    messagesAvailable: boolean;
    extensions: ExtensionRow[];
    messages: Message[];
    accessCode: string;
}>();

/* --- Boxes ----------------------------------------------------------- */

const editing = ref<string | null>(null);
const boxForm = useForm({ pin: '', fullname: '', email: '' });

function edit(row: ExtensionRow): void {
    editing.value = row.extension;
    boxForm.clearErrors();
    boxForm.pin = '';
    boxForm.fullname = row.box?.fullname ?? row.user ?? '';
    boxForm.email = row.box?.email ?? '';
}

function submitBox(row: ExtensionRow): void {
    boxForm.put(saveBox(row.extension).url, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

function removeBox(row: ExtensionRow): void {
    if (
        !window.confirm(
            t(
                'Supprimer la messagerie du poste :p0 et tous ses messages ?',
                { p0: row.extension },
            ),
        )
    ) {
        return;
    }

    router.delete(destroyBox(row.extension).url, { preserveScroll: true });
}

/* --- Messages -------------------------------------------------------- */

const filter = ref('');
const shown = computed(() =>
    filter.value
        ? props.messages.filter((m) => m.mailbox === filter.value)
        : props.messages,
);

const folderLabels: Record<string, string> = {
    INBOX: 'Nouveau',
    Old: 'Écouté',
    Urgent: 'Urgent',
};

function removeMessage(message: Message): void {
    if (!window.confirm(t('Supprimer ce message ?'))) {
        return;
    }

    router.delete(destroyMessage().url, {
        data: { id: message.id },
        preserveScroll: true,
    });
}

const extensionsWithBox = computed(() =>
    props.extensions.filter((row) => row.box),
);
</script>

<template>
    <Head :title="t('Messagerie vocale')" />

    <div class="space-y-8">
        <Heading
            variant="small"
            :title="t('Messagerie vocale')"
            :description="
                t(
                    'Un appel vers un poste qui ne répond pas, est occupé ou déconnecté laisse un message quand le poste a une messagerie. Les agents l’écoutent en composant :p0.',
                    { p0: accessCode },
                )
            "
        />

        <Alert v-if="!available" variant="destructive">
            <AlertDescription>
                {{
                    t(
                        'La messagerie vocale n’est pas disponible sur le serveur Asterisk de la plateforme. Contactez le support.',
                    )
                }}
            </AlertDescription>
        </Alert>

        <template v-else>
            <!-- Boxes -->
            <section class="space-y-3">
                <h2 class="font-semibold">{{ t('Messageries par poste') }}</h2>
                <ul class="divide-y rounded-lg border">
                    <li
                        v-for="row in extensions"
                        :key="row.extension"
                        class="space-y-3 p-3 text-sm"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <VoicemailIcon
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="font-mono font-medium">{{
                                row.extension
                            }}</span>
                            <span class="text-muted-foreground">{{
                                row.user ?? t('Poste non attribué')
                            }}</span>
                            <Badge v-if="row.box" variant="secondary">{{
                                t('Activée')
                            }}</Badge>
                            <span
                                v-if="row.box?.email"
                                class="truncate text-xs text-muted-foreground"
                                >→ {{ row.box.email }}</span
                            >
                            <div class="ml-auto flex gap-2">
                                <Button
                                    v-if="editing !== row.extension"
                                    size="sm"
                                    variant="outline"
                                    @click="edit(row)"
                                >
                                    {{ row.box ? t('Modifier') : t('Activer') }}
                                </Button>
                                <Button
                                    v-if="row.box"
                                    size="sm"
                                    variant="ghost"
                                    :aria-label="t('Supprimer la messagerie')"
                                    @click="removeBox(row)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <form
                            v-if="editing === row.extension"
                            class="grid gap-3 sm:grid-cols-3"
                            @submit.prevent="submitBox(row)"
                        >
                            <div class="grid gap-1">
                                <Label :for="`pin-${row.extension}`">{{
                                    t('Code PIN')
                                }}</Label>
                                <Input
                                    :id="`pin-${row.extension}`"
                                    v-model="boxForm.pin"
                                    inputmode="numeric"
                                    autocomplete="off"
                                    :placeholder="
                                        row.box?.has_pin
                                            ? t('Inchangé')
                                            : t('4 à 10 chiffres')
                                    "
                                />
                                <InputError :message="boxForm.errors.pin" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`name-${row.extension}`">{{
                                    t('Nom annoncé')
                                }}</Label>
                                <Input
                                    :id="`name-${row.extension}`"
                                    v-model="boxForm.fullname"
                                />
                                <InputError :message="boxForm.errors.fullname" />
                            </div>
                            <div class="grid gap-1">
                                <Label :for="`email-${row.extension}`">{{
                                    t('E-mail (message en pièce jointe)')
                                }}</Label>
                                <Input
                                    :id="`email-${row.extension}`"
                                    v-model="boxForm.email"
                                    type="email"
                                    :placeholder="row.userEmail ?? ''"
                                />
                                <InputError :message="boxForm.errors.email" />
                            </div>
                            <div class="flex gap-2 sm:col-span-3">
                                <Button
                                    type="submit"
                                    size="sm"
                                    :disabled="boxForm.processing"
                                    >{{ t('Enregistrer') }}</Button
                                >
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    @click="editing = null"
                                    >{{ t('Annuler') }}</Button
                                >
                            </div>
                        </form>
                    </li>
                    <li
                        v-if="extensions.length === 0"
                        class="p-3 text-sm text-muted-foreground"
                    >
                        {{ t('Aucun poste dans l’organisation.') }}
                    </li>
                </ul>
            </section>

            <!-- Messages -->
            <section class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="font-semibold">{{ t('Messages') }}</h2>
                    <select
                        v-if="extensionsWithBox.length > 1"
                        v-model="filter"
                        class="h-9 rounded-md border bg-background px-2 text-sm"
                        :aria-label="t('Poste')"
                    >
                        <option value="">{{ t('Tous les postes') }}</option>
                        <option
                            v-for="row in extensionsWithBox"
                            :key="row.extension"
                            :value="row.extension"
                        >
                            {{ row.extension }}
                            {{ row.user ? `· ${row.user}` : '' }}
                        </option>
                    </select>
                </div>

                <p
                    v-if="!messagesAvailable"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Les messages ne sont pas stockés en base sur ce serveur : écoutez-les depuis le softphone avec :p0, ou par e-mail.',
                            { p0: accessCode },
                        )
                    }}
                </p>
                <p
                    v-else-if="shown.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Aucun message.') }}
                </p>
                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="m in shown"
                        :key="m.id"
                        class="flex flex-wrap items-center gap-3 p-3 text-sm"
                    >
                        <div class="min-w-40 space-y-0.5">
                            <p class="font-medium">
                                {{ m.callerid || t('Numéro masqué') }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ t('Poste') }} {{ m.mailbox }} ·
                                {{ m.received_at ? formatDate(m.received_at) : '—' }}
                                · {{ formatDuration(m.duration) }}
                            </p>
                        </div>
                        <Badge
                            :variant="m.folder === 'INBOX' ? 'default' : 'secondary'"
                            >{{ t(folderLabels[m.folder] ?? m.folder) }}</Badge
                        >
                        <audio
                            v-if="m.size > 0"
                            controls
                            preload="none"
                            class="h-9 max-w-full flex-1"
                            :src="play({ query: { id: m.id } }).url"
                        />
                        <Button
                            size="sm"
                            variant="ghost"
                            :aria-label="t('Supprimer ce message')"
                            @click="removeMessage(m)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
