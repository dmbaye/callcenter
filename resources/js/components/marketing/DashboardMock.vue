<script setup lang="ts">
import {
    BarChart3,
    Headphones,
    LayoutGrid,
    ListOrdered,
    Megaphone,
    Settings,
    Video,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import StatusLight from '@/components/StatusLight.vue';
import MockAvatar from './MockAvatar.vue';

/**
 * Supervisor dashboard mockup. `step` (0–3) follows the hero storyboard:
 * 0 a call rings, 1–2 it is answered, 3 the agent is in wrap-up.
 */
const props = withDefaults(defineProps<{ step?: number; tick?: number }>(), {
    step: 1,
    tick: 0,
});

type Light = 'positive' | 'informative' | 'notice' | 'neutral';

const agents = computed(() => {
    const awa: [Light, string] =
        props.step === 0
            ? ['positive', 'Disponible']
            : props.step === 3
              ? ['notice', 'Post-appel']
              : ['informative', 'En appel · 0:' + (12 + props.step * 9)];

    return [
        {
            name: 'Awa Diarra',
            ext: '7001',
            initials: 'AD',
            tone: 'blue' as const,
            light: awa,
        },
        {
            name: 'Moussa Keïta',
            ext: '7002',
            initials: 'MK',
            tone: 'seafoam' as const,
            light: ['informative', 'En appel · 4:51'] as [Light, string],
        },
        {
            name: 'Fatou Sow',
            ext: '7003',
            initials: 'FS',
            tone: 'orange' as const,
            light: ['notice', 'Pause · Déjeuner'] as [Light, string],
        },
        {
            name: 'Ibrahim Traoré',
            ext: '7004',
            initials: 'IT',
            tone: 'purple' as const,
            light: (props.step === 2
                ? ['informative', 'En appel · 0:04']
                : ['positive', 'Disponible']) as [Light, string],
        },
    ];
});

const kpis = computed(() => [
    { label: 'Appels du jour', value: String(184 + props.tick) },
    { label: 'Taux de réponse', value: '96 %' },
    { label: 'Attente moyenne', value: '0:24' },
    {
        label: 'En file',
        value: String([3, 2, 2, 1][props.step % 4]),
        accent: true,
    },
]);

const nav = [
    { icon: LayoutGrid, active: true },
    { icon: Headphones },
    { icon: ListOrdered },
    { icon: Megaphone },
    { icon: Video },
    { icon: BarChart3 },
    { icon: Settings },
];

// Calls per hour, 08:00 → 18:00.
const points = [12, 22, 34, 41, 30, 26, 38, 45, 36, 28, 18];
const chart = computed(() => {
    const max = 50;
    const step = 300 / (points.length - 1);
    const coords = points.map(
        (value, index) => [index * step, 90 - (value / max) * 80] as const,
    );
    const line = coords
        .map(
            ([x, y], index) =>
                `${index ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)}`,
        )
        .join(' ');

    return { line, area: `${line} L300,90 L0,90 Z` };
});
</script>

<template>
    <div class="flex text-left text-foreground">
        <!-- App side navigation -->
        <aside
            class="hidden w-14 shrink-0 flex-col items-center gap-2 border-r bg-sidebar py-3 sm:flex"
        >
            <span
                class="mb-2 flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
            >
                <AppLogoIcon class="size-4" />
            </span>
            <span
                v-for="(item, index) in nav"
                :key="index"
                class="flex size-8 items-center justify-center rounded-lg"
                :class="
                    item.active
                        ? 'bg-sidebar-accent text-heading'
                        : 'text-muted-foreground'
                "
            >
                <component :is="item.icon" class="size-4" />
            </span>
        </aside>

        <div class="min-w-0 flex-1 space-y-3 p-3 sm:p-4">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[11px] text-muted-foreground">
                        Démo · Service client
                    </p>
                    <p class="text-sm font-extrabold text-heading">
                        Supervision en direct
                    </p>
                </div>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-positive-subtle px-2.5 py-1 text-[11px] font-semibold text-positive-strong"
                >
                    <span
                        class="size-1.5 animate-pulse rounded-full bg-positive"
                    />
                    Temps réel
                </span>
            </div>

            <div class="grid grid-cols-2 gap-2 lg:grid-cols-4">
                <div
                    v-for="kpi in kpis"
                    :key="kpi.label"
                    class="rounded-lg border bg-card px-3 py-2"
                >
                    <p class="truncate text-[10px] text-muted-foreground">
                        {{ kpi.label }}
                    </p>
                    <p
                        :key="kpi.value"
                        class="mk-pop text-lg font-extrabold tabular-nums"
                        :class="kpi.accent ? 'text-primary' : 'text-heading'"
                    >
                        {{ kpi.value }}
                    </p>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-[1.1fr_1fr]">
                <div class="rounded-lg border bg-card p-3">
                    <div class="flex items-center justify-between">
                        <p class="text-xs font-bold text-heading">
                            Appels par heure
                        </p>
                        <span class="text-[10px] text-muted-foreground"
                            >Aujourd'hui</span
                        >
                    </div>
                    <svg
                        viewBox="0 0 300 96"
                        class="mt-2 h-24 w-full"
                        preserveAspectRatio="none"
                    >
                        <path
                            :d="chart.area"
                            fill="var(--chart-1)"
                            fill-opacity="0.12"
                        />
                        <path
                            :d="chart.line"
                            fill="none"
                            stroke="var(--chart-1)"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                            pathLength="1"
                            class="[animation:mk-draw_2.2s_0.3s_ease-out_forwards] [stroke-dasharray:1] [stroke-dashoffset:1]"
                        />
                    </svg>
                </div>

                <ul class="divide-y rounded-lg border bg-card">
                    <li
                        v-for="agent in agents"
                        :key="agent.ext"
                        class="flex items-center gap-2.5 px-3 py-2"
                    >
                        <MockAvatar
                            :initials="agent.initials"
                            :tone="agent.tone"
                            size="sm"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-xs font-semibold">
                                {{ agent.name }}
                            </p>
                            <p class="text-[10px] text-muted-foreground">
                                Poste {{ agent.ext }}
                            </p>
                        </div>
                        <StatusLight
                            :key="agent.light[1]"
                            size="s"
                            :variant="agent.light[0]"
                            class="mk-pop text-[11px]"
                            >{{ agent.light[1] }}</StatusLight
                        >
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
