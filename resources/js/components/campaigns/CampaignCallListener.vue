<script setup lang="ts">
import { t } from '@/lib/i18n';
import { router, usePage } from '@inertiajs/vue3';
import { onUnmounted, watch } from 'vue';
import { toast } from 'vue-sonner';
import { getEcho } from '@/lib/echo';

type Connected = { campaign: string; contact: string; url: string };

const page = usePage();
let channel: string | null = null;

/** Screen pop on any page: the workspace reloads by itself, elsewhere a toast opens it. */
function onConnected(event: Connected) {
    if (page.component === 'campaigns/Workspace') {
        return;
    }

    toast.info(t('Appel de campagne : :p0', { p0: event.contact }), {
        description: event.campaign,
        duration: 30_000,
        action: {
            label: t('Ouvrir la fiche'),
            onClick: () => router.visit(event.url),
        },
    });
}

function unsubscribe() {
    if (channel) {
        getEcho()
            ?.private(channel)
            .stopListening('.campaign.connected', onConnected);
        channel = null;
    }
}

watch(
    () => page.props.auth?.user?.id,
    (userId) => {
        unsubscribe();

        const echo = getEcho();

        if (!echo || !userId) {
            return;
        }

        channel = `users.${userId}`;
        echo.private(channel).listen('.campaign.connected', onConnected);
    },
    { immediate: true },
);

onUnmounted(unsubscribe);
</script>

<template>
    <span class="hidden" />
</template>
