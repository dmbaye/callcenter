<script setup lang="ts">
import { t } from '@/lib/i18n';
import { PhoneForwarded } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { requestHeaders } from '@/lib/teamHeader';
import { transfer } from '@/routes/calls';
import { options as queueOptions } from '@/routes/queues';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** `round`: the softphone's circular icon button instead of a labelled one. */
const props = defineProps<{ round?: boolean }>();

const page = usePage();

const teamSlug = computed(
    () => (page.props.currentTeam as { slug?: string } | null)?.slug ?? '',
);

const open = ref(false);
type QueueOption = { name: string; asterisk_name: string };
const queues = ref<QueueOption[]>([]);
const loading = ref(false);
const transferringTo = ref<string | null>(null);
const feedback = ref<{ ok: boolean; message: string } | null>(null);

// Queues belong to the team: reload them after a team switch.
watch(teamSlug, () => {
    queues.value = [];
});

async function onOpenChange(value: boolean): Promise<void> {
    open.value = value;
    feedback.value = null;

    if (value && queues.value.length === 0) {
        loading.value = true;

        try {
            const response = await fetch(queueOptions.url(teamSlug.value), {
                headers: requestHeaders(),
            });

            queues.value = (await response.json()).queues ?? [];
        } catch {
            feedback.value = {
                ok: false,
                message: t('Impossible de charger les files.'),
            };
        } finally {
            loading.value = false;
        }
    }
}

async function transferTo(queue: QueueOption): Promise<void> {
    transferringTo.value = queue.asterisk_name;
    feedback.value = null;

    try {
        const response = await fetch(transfer.url(teamSlug.value), {
            method: 'POST',
            headers: {
                ...requestHeaders(),
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ queue: queue.asterisk_name }),
        });

        const body = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(body.message ?? 'Échec du transfert.');
        }

        feedback.value = {
            ok: true,
            message: body.message ?? 'Appel transféré.',
        };
        open.value = false;
    } catch (error) {
        feedback.value = {
            ok: false,
            message:
                error instanceof Error ? error.message : 'Échec du transfert.',
        };
    } finally {
        transferringTo.value = null;
    }
}
</script>

<template>
    <div class="relative">
        <DropdownMenu :open="open" @update:open="onOpenChange">
            <DropdownMenuTrigger as-child>
                <button
                    v-if="props.round"
                    type="button"
                    class="flex h-10 w-full items-center justify-center rounded-full border-2 border-[var(--btn-outline-border)] text-foreground transition-colors hover:border-[var(--btn-outline-border-hover)] hover:bg-accent data-[state=open]:bg-accent"
                    :title="t('Transférer l\'appel vers une file')"
                    :aria-label="t('Transférer l\'appel vers une file')"
                >
                    <PhoneForwarded class="size-4" />
                </button>
                <Button
                    v-else
                    type="button"
                    variant="outline"
                    size="sm"
                    :title="t('Transférer l\'appel vers une file')"
                >
                    <PhoneForwarded />
                    {{ t('Transférer') }}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent side="top" align="end" class="w-56">
                <DropdownMenuLabel>{{
                    t('Transférer vers une file')
                }}</DropdownMenuLabel>
                <DropdownMenuSeparator />

                <p
                    v-if="loading"
                    class="px-2 py-3 text-center text-xs text-muted-foreground"
                >
                    {{ t('Chargement des files…') }}
                </p>
                <p
                    v-else-if="queues.length === 0"
                    class="px-2 py-3 text-center text-xs text-muted-foreground"
                >
                    {{ t('Aucune file trouvée.') }}
                </p>
                <template v-else>
                    <DropdownMenuItem
                        v-for="queue in queues"
                        :key="queue.asterisk_name"
                        :disabled="transferringTo !== null"
                        @select.prevent="transferTo(queue)"
                    >
                        <span class="truncate">{{ queue.name }}</span>
                        <span
                            v-if="transferringTo === queue.asterisk_name"
                            class="ml-auto text-xs text-muted-foreground"
                        >
                            {{ t('Transfert…') }}
                        </span>
                    </DropdownMenuItem>
                </template>
            </DropdownMenuContent>
        </DropdownMenu>

        <Alert
            v-if="feedback"
            :variant="feedback.ok ? 'default' : 'destructive'"
            class="absolute right-0 bottom-full z-50 mb-2 w-56 px-3 py-2"
        >
            <AlertDescription class="text-xs">{{
                feedback.message
            }}</AlertDescription>
        </Alert>
    </div>
</template>
