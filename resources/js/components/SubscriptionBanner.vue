<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { formatDate } from '@/lib/format';
import { index as billing } from '@/routes/billing';

const page = usePage();

const summary = computed(() => page.props.billing ?? null);

const canManage = computed(() => {
    const role = page.props.currentTeam?.role;

    return role === 'owner' || role === 'admin';
});

const daysLeft = computed(() => {
    if (!summary.value) {
        return null;
    }

    return Math.ceil(
        (new Date(summary.value.endsAt).getTime() - Date.now()) / 86_400_000,
    );
});

/** Only shown when there is something to do: trial ending, grace, read-only. */
const message = computed(() => {
    const s = summary.value;

    if (!s) {
        return null;
    }

    if (s.state === 'expired') {
        return "Abonnement expiré : l'équipe est en lecture seule et les agents ne peuvent plus prendre d'appels.";
    }

    if (s.state === 'grace') {
        return t(
            'Échéance dépassée depuis le :p0. Renouvelez pour éviter la lecture seule.',
            { p0: formatDate(s.endsAt) },
        );
    }

    if (daysLeft.value !== null && daysLeft.value <= 7) {
        return s.onTrial
            ? `Votre essai gratuit se termine dans ${daysLeft.value} jour${daysLeft.value > 1 ? 's' : ''}.`
            : t('Votre abonnement :p0 se termine le :p1.', {
                  p0: s.planName,
                  p1: formatDate(s.endsAt),
              });
    }

    return null;
});

const tone = computed(() =>
    summary.value?.state === 'expired'
        ? 'bg-negative text-white'
        : 'bg-notice text-foreground',
);
</script>

<template>
    <div
        v-if="message"
        class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-sm"
        :class="tone"
        role="status"
    >
        <AlertTriangle class="size-4 shrink-0" />
        <span>{{ message }}</span>
        <Link
            v-if="canManage"
            :href="billing().url"
            class="font-semibold underline underline-offset-2"
            >{{ t('Renouveler') }}</Link
        >
        <span v-else class="opacity-80">{{
            t("Contactez l'administrateur de l'équipe.")
        }}</span>
    </div>
</template>
