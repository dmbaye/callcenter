<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Check,
    ChevronDown,
    Copy,
    Lock,
    Mail,
    UserPlus,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CancelInvitationModal from '@/components/CancelInvitationModal.vue';
import CreateTeamMemberModal from '@/components/CreateTeamMemberModal.vue';
import DeleteTeamModal from '@/components/DeleteTeamModal.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import InviteMemberModal from '@/components/InviteMemberModal.vue';
import OrganizationProfileForm from '@/components/OrganizationProfileForm.vue';
import type {
    OrganizationProfile,
    OrganizationProfileOptions,
} from '@/components/OrganizationProfileForm.vue';
import RemoveMemberModal from '@/components/RemoveMemberModal.vue';
import SelectField from '@/components/SelectField.vue';
import UsageModeSelector from '@/components/UsageModeSelector.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useInitials } from '@/composables/useInitials';
import { formatDate, formatMoney } from '@/lib/format';
import { index as billing } from '@/routes/billing';
import { edit, index, update } from '@/routes/teams';
import { update as updateMember } from '@/routes/teams/members';
import type {
    RoleOption,
    Team,
    TeamInvitation,
    TeamMember,
    TeamPermissions,
} from '@/types';

type Organization = {
    uuid: string | null;
    asteriskKey: string;
    context: string;
    endpointPrefix: string;
    accountcode: string;
    owner: string | null;
    createdAt: string | null;
    extensionsCount: number;
};

type SubscriptionSummary = {
    planName: string | null;
    state: 'trialing' | 'active' | 'grace' | 'expired' | null;
    stateLabel: string | null;
    onTrial: boolean;
    isFree: boolean;
    endsAt: string | null;
    agents: number;
    agentsLimit: number | null;
    outboundAmount: number | null;
    outboundRate: number;
    currency: string;
};

type Props = {
    team: Team;
    organization: Organization;
    profile: OrganizationProfile;
    profileOptions: OrganizationProfileOptions;
    members: TeamMember[];
    invitations: TeamInvitation[];
    permissions: TeamPermissions;
    availableRoles: RoleOption[];
    queueOptions: string[];
    /** Owners and admins only. */
    subscription: SubscriptionSummary | null;
};

const props = defineProps<Props>();

const subscriptionTone = computed(
    () =>
        ({
            trialing: 'bg-informative text-white',
            active: 'bg-positive text-white',
            grace: 'bg-notice text-foreground',
            expired: 'bg-negative text-white',
        })[props.subscription?.state ?? 'active'],
);

defineOptions({
    layout: (props: { team: Team }) => ({
        breadcrumbs: [
            {
                title: 'Organisations',
                href: index(),
            },
            {
                title: props.team.name,
                href: edit(props.team.slug),
            },
        ],
    }),
});

const { getInitials } = useInitials();

/*
 * One tab at a time. ?tab= links to a tab; the last tab is also kept for the
 * browser session, so saving a member or the profile (the server redirects
 * to the plain page) comes back to the same tab.
 */
const tabs = ['general', 'profile', 'members', 'advanced'] as const;
type Tab = (typeof tabs)[number];
const tabKey = `organization.tab.${props.team.slug}`;

function isTab(value: string | null): value is Tab {
    return (tabs as readonly string[]).includes(value ?? '');
}

function initialTab(): Tab {
    if (typeof window === 'undefined') {
        return 'general';
    }

    const wanted = new URLSearchParams(window.location.search).get('tab');

    if (isTab(wanted)) {
        return wanted;
    }

    try {
        const last = window.sessionStorage.getItem(tabKey);

        return isTab(last) ? last : 'general';
    } catch {
        return 'general';
    }
}

const tab = ref<string | number>(initialTab());

watch(tab, (value) => {
    try {
        window.sessionStorage.setItem(tabKey, String(value));
    } catch {
        // Private mode: the tab is just not remembered.
    }

    const url = new URL(window.location.href);
    url.searchParams.set('tab', String(value));
    window.history.replaceState(window.history.state, '', url);
});

const inviteDialogOpen = ref(false);
const createMemberDialogOpen = ref(false);
const deleteDialogOpen = ref(false);
const removeMemberDialogOpen = ref(false);
const memberToRemove = ref<TeamMember | null>(null);
const cancelInvitationDialogOpen = ref(false);
const invitationToCancel = ref<TeamInvitation | null>(null);

const pageTitle = computed(() =>
    props.permissions.canUpdateTeam
        ? t('Modifier :p0', { p0: props.team.name })
        : t('Voir :p0', { p0: props.team.name }),
);

const updateMemberRole = (member: TeamMember, newRole: string) => {
    router.visit(updateMember([props.team.slug, member.id]), {
        data: { role: newRole },
        preserveScroll: true,
    });
};

/* Supervisor in charge (Planning approvals): owner, admins and supervisors. */
const supervisorChoices = computed(() =>
    props.members.filter((m) =>
        ['owner', 'admin', 'supervisor'].includes(m.role),
    ),
);

const supervisorOptions = (member: TeamMember) => [
    { value: 'none', label: t('Aucun superviseur') },
    ...supervisorChoices.value
        .filter((m) => m.id !== member.id)
        .map((m) => ({ value: m.id, label: m.name })),
];

const supervisorName = (member: TeamMember) =>
    props.members.find((m) => m.id === member.supervisor_id)?.name ?? null;

const assignSupervisor = (member: TeamMember, value: string) => {
    router.patch(
        `/settings/teams/${props.team.slug}/members/${member.id}/supervisor`,
        { supervisor_id: value === 'none' ? null : Number(value) },
        { preserveScroll: true },
    );
};

const confirmRemoveMember = (member: TeamMember) => {
    memberToRemove.value = member;
    removeMemberDialogOpen.value = true;
};

const identifiers = computed(() => [
    {
        label: t('Identifiant Asterisk'),
        value: props.organization.asteriskKey,
        hint: t('Clé unique attribuée à la création.'),
    },
    {
        label: t("Contexte du plan d'appel"),
        value: props.organization.context,
        hint: t("Contexte de tous les postes et files de l'organisation."),
    },
    {
        label: t('Préfixe des postes SIP'),
        value: props.organization.endpointPrefix,
        hint: t('Ex. :p07001 pour le poste 7001.', {
            p0: props.organization.endpointPrefix,
        }),
    },
    {
        label: t('Code de compte (CDR)'),
        value: props.organization.accountcode,
        hint: t("Rattache chaque appel du CDR à l'organisation."),
    },
    ...(props.organization.uuid
        ? [
              {
                  label: 'UUID',
                  value: props.organization.uuid,
                  hint: t("Identifiant interne de l'application."),
              },
          ]
        : []),
]);

const copied = ref<string | null>(null);

async function copy(label: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = label;
        setTimeout(() => {
            if (copied.value === label) {
                copied.value = null;
            }
        }, 1500);
    } catch {
        // Clipboard unavailable (insecure context): the value stays selectable.
    }
}

const confirmCancelInvitation = (invitation: TeamInvitation) => {
    invitationToCancel.value = invitation;
    cancelInvitationDialogOpen.value = true;
};
</script>

<template>
    <Head :title="pageTitle" />

    <h1 class="sr-only">{{ pageTitle }}</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                :title="team.name"
                :description="
                    permissions.canUpdateTeam
                        ? t(
                              'Le nom affiché dans l\'application, les membres et les identifiants utilisés par Asterisk',
                          )
                        : t(
                              'Seuls le propriétaire et les administrateurs peuvent modifier l\'organisation',
                          )
                "
            />
            <Button variant="outline" size="sm" as-child>
                <Link :href="index()" data-test="all-organizations-link">
                    <ArrowLeftRight /> {{ t('Mes organisations') }}
                </Link>
            </Button>
        </div>

        <Tabs v-model="tab" class="gap-6">
            <TabsList class="w-full justify-start overflow-x-auto sm:w-fit">
                <TabsTrigger value="general">{{ t('Général') }}</TabsTrigger>
                <TabsTrigger value="profile">{{ t('Profil') }}</TabsTrigger>
                <TabsTrigger value="members">
                    {{ t('Membres') }}
                    <Badge variant="secondary" class="ml-1.5 tabular-nums">{{
                        members.length + invitations.length
                    }}</Badge>
                </TabsTrigger>
                <TabsTrigger value="advanced">{{ t('Avancé') }}</TabsTrigger>
            </TabsList>

            <!-- General: name, summary, how the organization uses the app -->
            <TabsContent value="general" class="space-y-10">
                <div v-if="permissions.canUpdateTeam" class="space-y-6">
                    <Form
                        v-bind="update.form(team.slug)"
                        class="space-y-6"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">{{ t("Nom de l'organisation") }}</Label>
                            <Input
                                id="name"
                                name="name"
                                data-test="team-name-input"
                                :default-value="team.name"
                                required
                            />
                            <InputError :message="errors.name" />
                            <p class="text-xs text-muted-foreground">
                                {{ t("L'adresse des pages suit le nom (actuellement") }}
                                <span class="font-mono">/{{ team.slug }}/…</span
                                >{{
                                    t('). Les identifiants Asterisk ne changent pas.')
                                }}
                            </p>
                        </div>

                        <div class="flex items-center gap-4">
                            <Button
                                type="submit"
                                data-test="team-save-button"
                                :disabled="processing"
                            >
                                {{ t('Enregistrer') }}
                            </Button>
                        </div>
                    </Form>
                </div>

                <dl class="grid gap-4 text-sm sm:grid-cols-3">
                    <div class="grid gap-0.5">
                        <dt class="text-muted-foreground">{{ t('Propriétaire') }}</dt>
                        <dd class="font-medium">{{ organization.owner ?? '—' }}</dd>
                    </div>
                    <div class="grid gap-0.5">
                        <dt class="text-muted-foreground">
                            {{ t('Membres · postes SIP') }}
                        </dt>
                        <dd class="font-medium tabular-nums">
                            {{ members.length }} · {{ organization.extensionsCount }}
                        </dd>
                    </div>
                    <div class="grid gap-0.5">
                        <dt class="text-muted-foreground">{{ t('Créée le') }}</dt>
                        <dd class="font-medium">
                            {{ formatDate(organization.createdAt) }}
                        </dd>
                    </div>
                </dl>

                <!-- Subscription at a glance; managed in Settings → Abonnement -->
                <div
                    v-if="subscription"
                    class="space-y-4 rounded-lg border p-5"
                    data-test="subscription-summary"
                >
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="text-sm text-muted-foreground">
                                {{ t('Abonnement') }}
                            </p>
                            <p class="flex flex-wrap items-center gap-2 font-semibold">
                                {{ subscription.planName ?? t('Aucune offre') }}
                                <span
                                    v-if="subscription.stateLabel"
                                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                                    :class="subscriptionTone"
                                    >{{ subscription.stateLabel }}</span
                                >
                            </p>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="billing()">{{
                                t('Gérer l’abonnement')
                            }}</Link>
                        </Button>
                    </div>

                    <dl class="grid gap-4 text-sm sm:grid-cols-3">
                        <div v-if="subscription.endsAt && !subscription.isFree" class="grid gap-0.5">
                            <dt class="text-muted-foreground">
                                {{
                                    subscription.onTrial
                                        ? t('Fin de l’essai')
                                        : subscription.state === 'expired'
                                          ? t('Expiré le')
                                          : t('Renouvellement')
                                }}
                            </dt>
                            <dd class="font-medium">
                                {{ formatDate(subscription.endsAt) }}
                            </dd>
                        </div>
                        <div class="grid gap-0.5">
                            <dt class="text-muted-foreground">{{ t('Agents') }}</dt>
                            <dd class="font-medium tabular-nums">
                                {{ subscription.agents }}
                                <template v-if="subscription.agentsLimit !== null">
                                    / {{ subscription.agentsLimit }}</template
                                >
                            </dd>
                        </div>
                        <div class="grid gap-0.5">
                            <dt class="text-muted-foreground">
                                {{ t('Appels sortants ce mois-ci') }}
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    subscription.outboundAmount === null
                                        ? '—'
                                        : formatMoney(
                                              subscription.outboundAmount,
                                              subscription.currency,
                                          )
                                }}
                                <span class="text-xs font-normal text-muted-foreground">
                                    ·
                                    {{
                                        t(':p0 / min', {
                                            p0: formatMoney(
                                                subscription.outboundRate,
                                                subscription.currency,
                                            ),
                                        })
                                    }}
                                </span>
                            </dd>
                        </div>
                    </dl>
                </div>

                <UsageModeSelector
                    :team-slug="team.slug"
                    :mode="team.usageMode ?? 'call_center'"
                    :can-update="permissions.canUpdateTeam"
                />
            </TabsContent>

            <!-- Profile: company details -->
            <TabsContent value="profile">
                <OrganizationProfileForm
                    :team-slug="team.slug"
                    :profile="profile"
                    :options="profileOptions"
                    :can-update="permissions.canUpdateTeam"
                />
            </TabsContent>

            <!-- Members and pending invitations -->
            <TabsContent value="members" class="space-y-10">
                <!-- Members Section -->
                <div class="space-y-6">
                    <div class="flex items-center justify-between">
                        <Heading
                            variant="small"
                            title="Membres"
                            :description="
                                permissions.canCreateInvitation
                                    ? t(
                                          'Qui appartient à cette organisation et avec quel rôle',
                                      )
                                    : ''
                            "
                        />

                        <div class="flex items-center gap-2">
                            <Button
                                v-if="permissions.canAddMember"
                                data-test="create-member-button"
                                @click="createMemberDialogOpen = true"
                            >
                                <UserPlus /> {{ t('Créer un utilisateur') }}
                            </Button>

                            <Button
                                v-if="permissions.canCreateInvitation"
                                data-test="invite-member-button"
                                variant="outline"
                                @click="inviteDialogOpen = true"
                            >
                                <Mail /> {{ t('Inviter un membre') }}
                            </Button>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <div
                            v-for="member in members"
                            :key="member.id"
                            data-test="member-row"
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div class="flex items-center gap-4">
                                <Avatar class="h-10 w-10">
                                    <AvatarImage
                                        v-if="member.avatar"
                                        :src="member.avatar"
                                        :alt="member.name"
                                    />
                                    <AvatarFallback>{{
                                        getInitials(member.name)
                                    }}</AvatarFallback>
                                </Avatar>
                                <div>
                                    <div class="font-medium">
                                        {{ member.name }}
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ member.email }}
                                    </div>
                                    <div
                                        v-if="
                                            !permissions.canUpdateMember &&
                                            supervisorName(member)
                                        "
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{
                                            t('Supervisé par :name', {
                                                name: supervisorName(member),
                                            })
                                        }}
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <div
                                    v-if="
                                        member.role !== 'owner' &&
                                        permissions.canUpdateMember
                                    "
                                    data-test="member-supervisor"
                                >
                                    <label
                                        :for="`supervisor-${member.id}`"
                                        class="sr-only"
                                        >{{
                                            t('Superviseur de :name', {
                                                name: member.name,
                                            })
                                        }}</label
                                    >
                                    <SelectField
                                        :id="`supervisor-${member.id}`"
                                        class="h-8 w-48"
                                        :model-value="member.supervisor_id ?? 'none'"
                                        :options="supervisorOptions(member)"
                                        @update:model-value="
                                            assignSupervisor(member, $event)
                                        "
                                    />
                                </div>
                                <DropdownMenu
                                    v-if="
                                        member.role !== 'owner' &&
                                        permissions.canUpdateMember
                                    "
                                >
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            data-test="member-role-trigger"
                                            variant="outline"
                                            size="sm"
                                        >
                                            {{ member.role_label }}
                                            <ChevronDown
                                                class="ml-2 h-4 w-4 opacity-50"
                                            />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent>
                                        <DropdownMenuItem
                                            v-for="role in availableRoles"
                                            :key="role.value"
                                            data-test="member-role-option"
                                            @click="
                                                updateMemberRole(member, role.value)
                                            "
                                        >
                                            {{ role.label }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <Badge v-else variant="secondary">
                                    {{ member.role_label }}
                                </Badge>

                                <TooltipProvider
                                    v-if="
                                        member.role !== 'owner' &&
                                        permissions.canRemoveMember
                                    "
                                >
                                    <Tooltip>
                                        <TooltipTrigger as-child>
                                            <Button
                                                data-test="member-remove-button"
                                                variant="ghost"
                                                size="sm"
                                                @click="confirmRemoveMember(member)"
                                            >
                                                <X class="h-4 w-4" />
                                            </Button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>{{ t('Retirer le membre') }}</p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pending Invitations Section -->
                <div v-if="invitations.length > 0" class="space-y-6">
                    <Heading
                        variant="small"
                        :title="t('Invitations en attente')"
                        :description="t('Invitations pas encore acceptées')"
                    />

                    <div class="space-y-3">
                        <div
                            v-for="invitation in invitations"
                            :key="invitation.code"
                            data-test="invitation-row"
                            class="flex items-center justify-between rounded-lg border p-4"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-muted"
                                >
                                    <Mail class="h-5 w-5 text-muted-foreground" />
                                </div>
                                <div>
                                    <div class="font-medium">
                                        {{ invitation.email }}
                                    </div>
                                    <div class="text-sm text-muted-foreground">
                                        {{ invitation.role_label }}
                                    </div>
                                </div>
                            </div>

                            <TooltipProvider v-if="permissions.canCancelInvitation">
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <Button
                                            data-test="invitation-cancel-button"
                                            variant="ghost"
                                            size="sm"
                                            @click="confirmCancelInvitation(invitation)"
                                        >
                                            <X class="h-4 w-4" />
                                        </Button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>{{ t("Annuler l'invitation") }}</p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </div>
                </div>
            </TabsContent>

            <!-- Advanced: Asterisk identifiers, deletion -->
            <TabsContent value="advanced" class="space-y-10">
                <!-- Organization: technical identifiers (read-only) -->
                <div class="space-y-6">
                    <Heading
                        variant="small"
                        :title="t('Identifiants techniques')"
                        :description="
                            t(
                                'Attribués une fois pour toutes : ils relient l\'organisation à ses postes, files et appels dans Asterisk',
                            )
                        "
                    />

                    <div
                        class="divide-y rounded-[var(--card-radius)] border"
                        data-test="organization-identifiers"
                    >
                        <div
                            v-for="item in identifiers"
                            :key="item.label"
                            class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div class="min-w-0">
                                <div
                                    class="flex items-center gap-1.5 text-sm font-semibold text-heading"
                                >
                                    <Lock
                                        class="size-3.5 text-muted-foreground"
                                        :aria-label="t('Non modifiable')"
                                    />
                                    {{ item.label }}
                                </div>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.hint }}
                                </p>
                            </div>
                            <div class="flex min-w-0 items-center gap-2">
                                <code
                                    class="truncate rounded-[var(--control-radius)] bg-muted px-2 py-1 font-mono text-sm select-all"
                                    >{{ item.value }}</code
                                >
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Copier ${item.label}`"
                                    @click="copy(item.label, item.value)"
                                >
                                    <Check
                                        v-if="copied === item.label"
                                        class="size-4 text-positive"
                                    />
                                    <Copy v-else class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Danger Zone -->
                <div
                    v-if="permissions.canDeleteTeam && !team.isPersonal"
                    class="space-y-6"
                >
                    <Heading
                        variant="small"
                        :title="t('Supprimer l\'organisation')"
                        :description="
                            t(
                                'Supprimer définitivement l\'organisation, ses postes et ses files dans Asterisk',
                            )
                        "
                    />
                    <div
                        class="space-y-4 rounded-lg border border-negative-subtle bg-negative-subtle p-4"
                    >
                        <div class="relative space-y-0.5 text-negative">
                            <p class="font-medium">{{ t('Attention') }}</p>
                            <p class="text-sm">
                                {{
                                    t('Soyez prudent : cette action est irréversible.')
                                }}
                            </p>
                        </div>
                        <Button
                            data-test="delete-team-button"
                            variant="destructive"
                            @click="deleteDialogOpen = true"
                            >{{ t("Supprimer l'organisation") }}</Button
                        >
                    </div>
                </div>
            </TabsContent>
        </Tabs>
    </div>

    <CreateTeamMemberModal
        v-if="permissions.canAddMember"
        :team="team"
        :queue-options="props.queueOptions"
        :available-roles="availableRoles"
        :open="createMemberDialogOpen"
        @update:open="createMemberDialogOpen = $event"
    />

    <InviteMemberModal
        v-if="permissions.canCreateInvitation"
        :team="team"
        :available-roles="availableRoles"
        :open="inviteDialogOpen"
        @update:open="inviteDialogOpen = $event"
    />

    <RemoveMemberModal
        :team="team"
        :member="memberToRemove"
        :open="removeMemberDialogOpen"
        @update:open="removeMemberDialogOpen = $event"
    />

    <CancelInvitationModal
        :team="team"
        :invitation="invitationToCancel"
        :open="cancelInvitationDialogOpen"
        @update:open="cancelInvitationDialogOpen = $event"
    />

    <DeleteTeamModal
        v-if="permissions.canDeleteTeam && !team.isPersonal"
        :team="team"
        :open="deleteDialogOpen"
        @update:open="deleteDialogOpen = $event"
    />
</template>
