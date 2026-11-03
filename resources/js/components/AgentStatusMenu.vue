<script setup lang="ts">
import { t } from '@/lib/i18n';
import { usePage } from '@inertiajs/vue3';
import {
    BellOff,
    Check,
    ChevronDown,
    CircleCheck,
    CircleOff,
    CirclePause,
    LogIn,
    LogOut,
    NotebookPen,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuShortcut,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAgentQueue } from '@/composables/useAgentQueue';
import { useWebRTC } from '@/composables/useWebRTC';
import type { AgentStateValue } from '@/types';

/**
 * Agent status menu in the app header: Prêt, Non prêt (with a reason),
 * Traitement post-appel, Ne pas déranger, Connexion / Déconnexion.
 * Ctrl+Alt+R / N / Z switch to Prêt / Non prêt / Post-appel from any page.
 */
const page = usePage();
const { callState } = useWebRTC();
const {
    agentState,
    stateLabel,
    pauseReasonId,
    stateSince,
    pauseReasons,
    loading,
    initialized,
    loadStatus,
    setAgentState,
} = useAgentQueue();

const userName = computed(() => page.props.auth?.user?.name ?? '');
const teamSlug = computed(() => page.props.currentTeam?.slug ?? '');

type Look = { icon: Component; dot: string; text: string };

const looks: Record<AgentStateValue, Look> = {
    ready: {
        icon: CircleCheck,
        dot: 'bg-positive',
        text: 'text-positive',
    },
    not_ready: {
        icon: CirclePause,
        dot: 'bg-notice',
        text: 'text-notice',
    },
    wrap_up: {
        icon: NotebookPen,
        dot: 'bg-informative',
        text: 'text-informative',
    },
    dnd: { icon: BellOff, dot: 'bg-negative', text: 'text-negative' },
    logged_out: {
        icon: CircleOff,
        dot: 'bg-muted-foreground',
        text: 'text-muted-foreground',
    },
};

const look = computed(() => looks[agentState.value]);
const softphoneOnline = computed(() => callState.registered);
const loggedOut = computed(() => agentState.value === 'logged_out');

// Time spent in the current state, refreshed every second.
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | null = null;

const elapsed = computed(() => {
    if (!stateSince.value) {
        return '';
    }

    const seconds = Math.max(
        0,
        Math.floor((now.value - new Date(stateSince.value).getTime()) / 1000),
    );
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${pad(m)}:${pad(s)}`;
});

const isCurrent = (state: AgentStateValue, reasonId: number | null = null) =>
    agentState.value === state &&
    (state !== 'not_ready' || (pauseReasonId.value ?? null) === reasonId);

const choose = (state: AgentStateValue, reasonId: number | null = null) => {
    if (isCurrent(state, reasonId)) {
        return;
    }

    void setAgentState(state, reasonId).catch((error) =>
        console.warn('[Statut] Changement impossible :', error),
    );
};

// Ctrl+Alt+R / N / Z, by physical key so AZERTY and macOS (Option) work alike.
const shortcuts: Record<string, AgentStateValue> = {
    KeyR: 'ready',
    KeyN: 'not_ready',
    KeyZ: 'wrap_up',
};

const onKeydown = (event: KeyboardEvent) => {
    if (!event.ctrlKey || !event.altKey || event.shiftKey || event.metaKey) {
        return;
    }

    const state = shortcuts[event.code];

    if (!state || loggedOut.value) {
        return;
    }

    event.preventDefault();
    choose(state);
};

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    clock = setInterval(() => (now.value = Date.now()), 1000);

    if (!initialized.value) {
        void loadStatus()?.catch(() => undefined);
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);

    if (clock) {
        clearInterval(clock);
    }
});

// Reasons and state belong to the team: reload them after a team switch.
watch(teamSlug, (slug, previous) => {
    if (slug && slug !== previous) {
        void loadStatus()?.catch(() => undefined);
    }
});
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger
            class="flex items-center gap-2.5 rounded-[var(--control-radius)] border border-sidebar-border/70 py-1.5 pr-2 pl-2.5 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:opacity-60"
            :disabled="loading"
            data-test="agent-status-trigger"
        >
            <span class="relative flex size-7 items-center justify-center">
                <component :is="look.icon" class="size-5" :class="look.text" />
                <span
                    v-if="!softphoneOnline"
                    class="absolute -right-0.5 -bottom-0.5 size-2.5 rounded-full border-2 border-background bg-muted-foreground"
                    :title="t('Softphone hors ligne')"
                />
            </span>
            <span class="hidden min-w-0 flex-col leading-tight sm:flex">
                <span
                    class="max-w-40 truncate text-sm font-semibold text-heading"
                >
                    {{ userName }}
                </span>
                <span
                    class="flex max-w-48 items-center gap-1 text-xs text-muted-foreground"
                >
                    <span class="truncate">{{ stateLabel }}</span>
                    <span v-if="elapsed" class="shrink-0 tabular-nums">
                        · {{ elapsed }}
                    </span>
                </span>
            </span>
            <ChevronDown class="size-4 text-muted-foreground" />
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel class="flex flex-col gap-0.5">
                <span class="font-semibold">{{ userName }}</span>
                <span class="text-xs font-normal text-muted-foreground">
                    {{
                        softphoneOnline
                            ? t('Softphone connecté')
                            : t(
                                  'Softphone hors ligne : le statut s’appliquera à la reconnexion',
                              )
                    }}
                </span>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />

            <DropdownMenuItem :disabled="loggedOut" @select="choose('ready')">
                <CircleCheck class="text-positive" />
                {{ t('Prêt') }}
                <Check v-if="isCurrent('ready')" class="size-4" />
                <DropdownMenuShortcut>{{
                    t('Ctrl+Alt+R')
                }}</DropdownMenuShortcut>
            </DropdownMenuItem>

            <DropdownMenuItem
                :disabled="loggedOut"
                @select="choose('not_ready')"
            >
                <CirclePause class="text-notice" />
                {{ t('Non prêt') }}
                <Check v-if="isCurrent('not_ready')" class="size-4" />
                <DropdownMenuShortcut>{{
                    t('Ctrl+Alt+N')
                }}</DropdownMenuShortcut>
            </DropdownMenuItem>

            <div class="max-h-64 overflow-y-auto">
                <DropdownMenuItem
                    v-for="reason in pauseReasons"
                    :key="reason.id"
                    :disabled="loggedOut"
                    @select="choose('not_ready', reason.id)"
                >
                    <CirclePause class="text-notice" />
                    <span class="truncate">{{
                        t('Non prêt – :p0', { p0: reason.name })
                    }}</span>
                    <Check
                        v-if="isCurrent('not_ready', reason.id)"
                        class="ml-auto size-4"
                    />
                </DropdownMenuItem>
            </div>

            <DropdownMenuItem :disabled="loggedOut" @select="choose('wrap_up')">
                <NotebookPen class="text-informative" />
                {{ t('Traitement post-appel') }}
                <Check v-if="isCurrent('wrap_up')" class="size-4" />
                <DropdownMenuShortcut>{{
                    t('Ctrl+Alt+Z')
                }}</DropdownMenuShortcut>
            </DropdownMenuItem>

            <DropdownMenuItem :disabled="loggedOut" @select="choose('dnd')">
                <BellOff class="text-negative" />
                {{ t('Ne pas déranger') }}
                <Check v-if="isCurrent('dnd')" class="ml-auto size-4" />
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <DropdownMenuItem :disabled="!loggedOut" @select="choose('ready')">
                <LogIn />
                {{ t('Connexion') }}
            </DropdownMenuItem>
            <DropdownMenuItem
                :disabled="loggedOut"
                @select="choose('logged_out')"
            >
                <LogOut />
                {{ t('Déconnexion') }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
