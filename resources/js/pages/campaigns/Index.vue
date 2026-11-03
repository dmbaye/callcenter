<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ban, Headset, Lock, Megaphone, Pause, Play, Plus } from '@lucide/vue';
import { computed } from 'vue';
import CampaignStatusBadge from '@/components/campaigns/CampaignStatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index as billing } from '@/routes/billing';
import { create, index, show, transition, workspace } from '@/routes/campaigns';
import { index as dnc } from '@/routes/campaigns/dnc';
import type { CampaignListItem, Team } from '@/types';

defineProps<{
    enabled: boolean;
    planName: string;
    canManage: boolean;
    campaigns: CampaignListItem[];
}>();

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Campagnes',
                href: props.currentTeam ? index(props.currentTeam.slug) : '/',
            },
        ],
    }),
});

const page = usePage();
const slug = computed(() => page.props.currentTeam?.slug ?? '');
const startError = computed(
    () => (page.props.errors as Record<string, string> | undefined)?.campaign,
);

function progress(c: CampaignListItem): number {
    return c.contacts > 0
        ? Math.round(((c.contacts - c.open) * 100) / c.contacts)
        : 0;
}

function act(c: CampaignListItem, action: 'start' | 'pause') {
    router.post(
        transition({ current_team: slug.value, campaign: c.id, action }).url,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Campagnes" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                :title="t('Campagnes sortantes')"
                :description="
                    t(
                        'Prospection, relances, enquêtes : listes de contacts, numérotation automatique et qualification des appels.',
                    )
                "
            />
            <div class="flex flex-wrap gap-2">
                <Button as-child variant="outline">
                    <Link :href="workspace(slug).url"
                        ><Headset /> {{ t('Poste agent') }}</Link
                    >
                </Button>
                <Button as-child variant="outline">
                    <Link :href="dnc(slug).url"
                        ><Ban /> {{ t('Ne pas appeler') }}</Link
                    >
                </Button>
                <Button v-if="canManage && enabled" as-child>
                    <Link :href="create(slug).url"
                        ><Plus /> {{ t('Nouvelle campagne') }}</Link
                    >
                </Button>
            </div>
        </div>

        <p
            v-if="startError"
            class="rounded-md bg-negative/10 p-3 text-sm text-negative-strong"
            role="alert"
        >
            {{ startError }}
        </p>

        <Card v-if="!enabled" class="max-w-2xl">
            <CardHeader>
                <CardTitle class="flex items-center gap-2"
                    ><Lock class="size-4" />
                    {{
                        t("Non inclus dans l'offre :p0", { p0: planName })
                    }}</CardTitle
                >
                <CardDescription>{{
                    t(
                        'Les campagnes sortantes sont incluses dans les offres :plans.',
                        { plans: $page.props.billing?.includedIn?.campaigns },
                    )
                }}</CardDescription>
            </CardHeader>
            <CardContent v-if="canManage">
                <Button as-child>
                    <Link :href="billing().url">{{
                        t("Changer d'offre")
                    }}</Link>
                </Button>
            </CardContent>
        </Card>

        <Card v-if="campaigns.length === 0 && enabled">
            <CardContent
                class="flex flex-col items-center gap-3 py-12 text-center"
            >
                <Megaphone class="size-10 text-muted-foreground" />
                <p class="max-w-md text-sm text-muted-foreground">
                    {{
                        t(
                            'Créez une campagne, importez un fichier CSV de contacts, affectez vos agents et lancez la numérotation.',
                        )
                    }}
                </p>
                <Button v-if="canManage" as-child>
                    <Link :href="create(slug).url"
                        ><Plus /> {{ t('Nouvelle campagne') }}</Link
                    >
                </Button>
            </CardContent>
        </Card>

        <div v-if="campaigns.length" class="overflow-x-auto rounded-lg border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>{{ t('Campagne') }}</TableHead>
                        <TableHead>{{ t('Mode') }}</TableHead>
                        <TableHead>{{ t('Statut') }}</TableHead>
                        <TableHead class="min-w-40">{{
                            t('Avancement')
                        }}</TableHead>
                        <TableHead class="text-right">{{
                            t('Appels')
                        }}</TableHead>
                        <TableHead class="text-right">{{
                            t('Joints')
                        }}</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="c in campaigns" :key="c.id">
                        <TableCell class="font-medium">
                            <Link
                                :href="
                                    show({ current_team: slug, campaign: c.id })
                                        .url
                                "
                                class="hover:underline"
                                >{{ c.name }}</Link
                            >
                            <Badge
                                v-if="c.kind === 'callback'"
                                variant="outline"
                                class="ml-2"
                                >{{ t('Rappels entrants') }}</Badge
                            >
                        </TableCell>
                        <TableCell class="text-sm">{{ c.modeLabel }}</TableCell>
                        <TableCell>
                            <CampaignStatusBadge
                                :status="c.status"
                                :label="c.statusLabel"
                            />
                            <span
                                v-if="c.status === 'running' && !c.withinHours"
                                class="ml-2 text-xs text-muted-foreground"
                                >{{ t('hors horaires') }}</span
                            >
                        </TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <div
                                    class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"
                                >
                                    <div
                                        class="h-full rounded-full bg-primary"
                                        :style="{ width: `${progress(c)}%` }"
                                    />
                                </div>
                                <span
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{ c.contacts - c.open }}/{{
                                        c.contacts
                                    }}</span
                                >
                            </div>
                        </TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            c.attempts
                        }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{
                            c.connected
                        }}</TableCell>
                        <TableCell class="text-right">
                            <Button
                                v-if="
                                    enabled &&
                                    (c.status === 'draft' ||
                                        c.status === 'paused')
                                "
                                size="sm"
                                variant="outline"
                                @click="act(c, 'start')"
                                ><Play /> {{ t('Lancer') }}</Button
                            >
                            <Button
                                v-else-if="c.status === 'running'"
                                size="sm"
                                variant="outline"
                                @click="act(c, 'pause')"
                                ><Pause /> {{ t('Pause') }}</Button
                            >
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
