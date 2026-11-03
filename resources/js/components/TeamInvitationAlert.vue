<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Info } from '@lucide/vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Connectez-vous' | 'Inscrivez-vous';
};

defineProps<Props>();
</script>

<template>
    <div data-test="team-invitation-alert">
        <Alert
            class="border-informative-border bg-informative-subtle text-informative-strong [&>svg]:text-informative"
        >
            <Info class="size-4" />
            <AlertDescription class="text-informative-strong">
                {{
                    t(":p0 pour rejoindre l'organisation « :p1 ».", {
                        p0: t(action),
                        p1: invitation.teamName,
                    })
                }}
            </AlertDescription>
        </Alert>
    </div>
</template>
