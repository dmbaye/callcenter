<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Coins, PhoneOutgoing } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatMoney } from '@/lib/format';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { platform } from '@/routes/admin';
import { index as asteriskTables } from '@/routes/admin/asterisk-tables';
import { Link } from '@inertiajs/vue3';
import { Database } from '@lucide/vue';
import { rate as saveRate, trunk as saveTrunk } from '@/routes/admin/platform';

type Candidate = { id: string; context: string | null; contact: string | null };

const props = defineProps<{
    trunk: string | null;
    trunkStored: boolean;
    /** Null when the Asterisk database cannot be reached. */
    candidates: Candidate[] | null;
    outboundContext: string;
    dialPrefix: string;
    rate: number;
    rates: { id: number; price: number; from: string; by: string | null }[];
    currency: string;
    month: string;
    months: { value: string; label: string }[];
    /** Null when the Asterisk database cannot be reached. */
    usage:
        | {
              team_id: number;
              team: string;
              calls: number;
              minutes: number;
              billable_minutes: number;
              amount: number;
          }[]
        | null;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [{ title: 'Plateforme', href: platform().url }],
    }),
});

const form = useForm({ trunk: props.trunk ?? '' });

function submit(): void {
    form.put(saveTrunk.url(), { preserveScroll: true });
}

const rateForm = useForm({ price_per_minute: props.rate });

function submitRate(): void {
    rateForm.put(saveRate.url(), { preserveScroll: true });
}

function changeMonth(event: Event): void {
    router.get(
        platform().url,
        { month: (event.target as HTMLSelectElement).value },
        { preserveScroll: true, preserveState: true, only: ['usage', 'month'] },
    );
}

const totals = computed(() => ({
    minutes: (props.usage ?? []).reduce((sum, row) => sum + row.minutes, 0),
    amount: (props.usage ?? []).reduce((sum, row) => sum + row.amount, 0),
}));
</script>

<template>
    <Head :title="t('Plateforme')" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4 md:p-8">
        <Heading
            :title="t('Plateforme')"
            :description="
                t(
                    'Réglages communs à toutes les organisations. Réservé aux administrateurs de la plateforme.',
                )
            "
        />

        <Link
            :href="asteriskTables().url"
            class="flex items-center gap-3 rounded-lg border p-4 text-sm hover:bg-muted/50"
        >
            <Database class="size-5 shrink-0 text-muted-foreground" />
            <span class="space-y-0.5">
                <span class="block font-medium">{{ t('Tables Asterisk') }}</span>
                <span class="block text-muted-foreground">{{
                    t(
                        'Trunks et transports PJSIP, musique d’attente, messagerie vocale, règles de file et journaux (cdr, cel, queue_log).',
                    )
                }}</span>
            </span>
        </Link>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <PhoneOutgoing class="size-4" />
                    {{ t('Trunk sortant') }}
                </CardTitle>
                <CardDescription>
                    {{
                        t(
                            'Tous les appels sortants passent par ce trunk : campagnes et appels composés depuis le softphone.',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <p
                    v-if="trunk && !trunkStored"
                    class="rounded-md border border-dashed p-3 text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Actuellement « :p0 », lu depuis ASTERISK_CAMPAIGN_TRUNK (.env). Enregistrez un choix pour le gérer ici.',
                            { p0: trunk },
                        )
                    }}
                </p>

                <div
                    v-if="candidates === null"
                    class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0 text-destructive" />
                    {{
                        t(
                            'La base Asterisk est injoignable : impossible de lister les trunks.',
                        )
                    }}
                </div>

                <p
                    v-else-if="candidates.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{
                        t(
                            'Aucun trunk dans ps_endpoints. Créez le point de terminaison de votre opérateur, puis revenez ici.',
                        )
                    }}
                </p>

                <form v-else class="space-y-4" @submit.prevent="submit">
                    <fieldset class="space-y-2">
                        <legend class="sr-only">{{ t('Trunk sortant') }}</legend>
                        <label
                            v-for="c in candidates"
                            :key="c.id"
                            class="flex cursor-pointer items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary has-[:checked]:bg-primary/5"
                        >
                            <input
                                v-model="form.trunk"
                                type="radio"
                                name="trunk"
                                :value="c.id"
                                class="mt-1"
                            />
                            <span class="min-w-0 space-y-0.5">
                                <span class="flex items-center gap-2 font-medium">
                                    {{ c.id }}
                                    <span
                                        v-if="c.id === trunk"
                                        class="rounded bg-muted px-1.5 py-0.5 text-xs font-normal text-muted-foreground"
                                        >{{ t('actuel') }}</span
                                    >
                                </span>
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ c.contact ?? t('Aucune adresse (contact) dans son AOR') }}
                                    · {{ t('contexte') }} {{ c.context ?? '—' }}
                                </span>
                            </span>
                        </label>
                    </fieldset>
                    <InputError :message="form.errors.trunk" />

                    <Button
                        type="submit"
                        :disabled="
                            form.processing ||
                            !form.trunk ||
                            (form.trunk === trunk && trunkStored)
                        "
                    >
                        {{ t('Enregistrer') }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="flex items-center gap-2">
                    <Coins class="size-4" />
                    {{ t('Prix des appels sortants') }}
                </CardTitle>
                <CardDescription>
                    {{
                        t(
                            'Facturé par minute commencée. Un nouveau prix s’applique aux appels suivants ; les appels déjà passés gardent le leur.',
                        )
                    }}
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="submitRate"
                >
                    <div class="grid gap-2">
                        <Label for="price">{{ t('Prix par minute') }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="price"
                                v-model.number="rateForm.price_per_minute"
                                type="number"
                                min="0"
                                step="1"
                                class="w-32"
                            />
                            <span class="text-sm text-muted-foreground">{{
                                t('F / min')
                            }}</span>
                        </div>
                    </div>
                    <Button
                        type="submit"
                        :disabled="
                            rateForm.processing ||
                            rateForm.price_per_minute === rate
                        "
                    >
                        {{ t('Enregistrer') }}
                    </Button>
                </form>
                <InputError :message="rateForm.errors.price_per_minute" />

                <ul
                    v-if="rates.length"
                    class="divide-y rounded-md border text-sm"
                >
                    <li
                        v-for="r in rates"
                        :key="r.id"
                        class="flex justify-between gap-3 px-3 py-2"
                    >
                        <span class="font-medium">{{
                            formatMoney(r.price, currency)
                        }}</span>
                        <span class="text-muted-foreground">
                            {{ t('depuis le :p0', { p0: formatDate(r.from) }) }}
                            <template v-if="r.by"> · {{ r.by }}</template>
                        </span>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <Card>
            <CardHeader class="flex flex-row flex-wrap items-start justify-between gap-3">
                <div class="space-y-1.5">
                    <CardTitle>{{ t('Consommation par organisation') }}</CardTitle>
                    <CardDescription>{{
                        t('Appels sortants décrochés, minutes facturables et montant.')
                    }}</CardDescription>
                </div>
                <select
                    :value="month"
                    class="h-9 rounded-md border bg-background px-2 text-sm"
                    :aria-label="t('Mois')"
                    @change="changeMonth"
                >
                    <option v-for="m in months" :key="m.value" :value="m.value">
                        {{ m.label }}
                    </option>
                </select>
            </CardHeader>
            <CardContent>
                <p v-if="usage === null" class="text-sm text-destructive">
                    {{
                        t(
                            'La base Asterisk est injoignable : consommation indisponible.',
                        )
                    }}
                </p>
                <p
                    v-else-if="usage.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('Aucun appel sortant ce mois-ci.') }}
                </p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-2 pr-3 font-medium">
                                    {{ t('Organisation') }}
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    {{ t('Appels') }}
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    {{ t('Minutes') }}
                                </th>
                                <th class="py-2 pr-3 text-right font-medium">
                                    {{ t('Facturables') }}
                                </th>
                                <th class="py-2 text-right font-medium">
                                    {{ t('Montant') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in usage"
                                :key="row.team_id"
                                class="border-b last:border-0"
                            >
                                <td class="py-2 pr-3">{{ row.team }}</td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ row.calls }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ row.minutes }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ row.billable_minutes }}
                                </td>
                                <td class="py-2 text-right tabular-nums">
                                    {{ formatMoney(row.amount, currency) }}
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="font-medium">
                                <td class="pt-3 pr-3">{{ t('Total') }}</td>
                                <td></td>
                                <td class="pt-3 pr-3 text-right tabular-nums">
                                    {{ totals.minutes }}
                                </td>
                                <td></td>
                                <td class="pt-3 text-right tabular-nums">
                                    {{ formatMoney(totals.amount, currency) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>{{ t('Comment les appels sortent') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm text-muted-foreground">
                <p>
                    {{
                        t(
                            'Un numéro externe composé dans le softphone part en 00 + numéro international. Chaque organisation l’envoie (avec son numéro principal comme numéro présenté) vers le contexte partagé :p0, qui appelle le trunk choisi.',
                            { p0: outboundContext },
                        )
                    }}
                </p>
                <p>
                    {{
                        t(
                            'Le trunk reçoit le préfixe « :p0 » suivi du numéro international, comme pour les campagnes.',
                            { p0: dialPrefix },
                        )
                    }}
                </p>
                <p>
                    {{
                        t(
                            'Déclarez :p0 dans extensions.conf une seule fois : php artisan asterisk:contexts-config, puis dialplan reload.',
                            { p0: outboundContext },
                        )
                    }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
