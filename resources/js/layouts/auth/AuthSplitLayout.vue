<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from '@lucide/vue';
import type { Component } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle } from '@/components/ui/card';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
    icon?: Component;
}>();

const agents = [
    {
        name: 'Awa Diarra',
        state: 'En appel',
        dot: 'bg-primary',
        chip: 'bg-accent text-accent-foreground',
    },
    {
        name: 'Moussa Keita',
        state: 'Disponible',
        dot: 'bg-positive',
        chip: 'bg-positive-subtle text-positive-strong',
    },
    {
        name: 'Fatou Sow',
        state: 'En pause',
        dot: 'bg-notice',
        chip: 'bg-notice-subtle text-notice-strong',
    },
];
</script>

<template>
    <div class="grid min-h-svh lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <!-- Brand panel -->
        <aside
            class="relative hidden flex-col justify-between bg-sidebar p-12 lg:flex xl:p-16"
        >
            <Link :href="home()" class="flex w-fit items-center gap-2.5">
                <span
                    class="flex size-9 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                >
                    <AppLogoIcon class="size-5" />
                </span>
                <span
                    class="font-display text-xl font-semibold tracking-tight"
                    >{{ t('Callcenter') }}</span
                >
            </Link>

            <div class="max-w-md">
                <h2 class="text-4xl leading-[1.1] font-semibold">
                    {{
                        t(
                            'Chaque appel pris en charge. Chaque agent accompagné.',
                        )
                    }}
                </h2>
                <p class="mt-4 text-base leading-relaxed text-muted-foreground">
                    {{
                        t(
                            "Accédez à votre softphone, à vos files et à votre tableau de bord en direct depuis n'importe quel navigateur.",
                        )
                    }}
                </p>

                <Card class="mt-10 gap-0 p-0 shadow-sm" aria-hidden="true">
                    <CardHeader
                        class="flex-row items-center justify-between border-b px-5 py-3"
                    >
                        <CardTitle class="text-sm">{{
                            t('File Support')
                        }}</CardTitle>
                        <p class="text-xs text-muted-foreground">
                            {{ t('2 appelants en attente') }}
                        </p>
                    </CardHeader>
                    <ul class="divide-y">
                        <li
                            v-for="agent in agents"
                            :key="agent.name"
                            class="flex items-center justify-between px-5 py-3"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="size-2 rounded-full"
                                    :class="agent.dot"
                                />
                                <span class="text-sm font-medium">{{
                                    agent.name
                                }}</span>
                            </div>
                            <Badge
                                variant="outline"
                                class="border-transparent"
                                :class="agent.chip"
                                >{{ agent.state }}</Badge
                            >
                        </li>
                    </ul>
                </Card>
            </div>

            <p class="text-xs text-muted-foreground">
                {{ t('© :p0 Callcenter', { p0: new Date().getFullYear() }) }}
            </p>
        </aside>

        <!-- Form column -->
        <main class="flex flex-col bg-background px-6 py-6 sm:px-10">
            <div class="flex items-center justify-between">
                <Link :href="home()" class="flex items-center gap-2 lg:hidden">
                    <span
                        class="flex size-8 items-center justify-center rounded-lg bg-primary text-primary-foreground"
                    >
                        <AppLogoIcon class="size-5" />
                    </span>
                    <span
                        class="font-display text-lg font-semibold tracking-tight"
                        >{{ t('Callcenter') }}</span
                    >
                </Link>
                <Button
                    as-child
                    variant="ghost"
                    size="sm"
                    class="ml-auto text-muted-foreground"
                >
                    <Link :href="home()">
                        <ChevronLeft />
                        {{ t("Retour à l'accueil") }}
                    </Link>
                </Button>
            </div>

            <div class="flex flex-1 items-center justify-center py-10">
                <div
                    class="w-full max-w-[400px] [&_button[type=submit]]:h-11 [&_input:not([data-input-otp])]:h-11"
                >
                    <span
                        v-if="icon"
                        class="mb-5 flex size-12 items-center justify-center rounded-xl bg-accent text-primary"
                    >
                        <component :is="icon" class="size-6" />
                    </span>
                    <h1
                        v-if="title"
                        class="text-3xl leading-tight font-semibold"
                    >
                        {{ title }}
                    </h1>
                    <p
                        v-if="description"
                        class="mt-2 mb-8 text-sm leading-relaxed text-muted-foreground"
                    >
                        {{ description }}
                    </p>
                    <slot />
                </div>
            </div>
        </main>
    </div>
</template>
