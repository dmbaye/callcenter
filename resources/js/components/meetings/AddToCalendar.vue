<script setup lang="ts">
import { t } from '@/lib/i18n';
import { CalendarPlus } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/** Google Calendar, Outlook, or the .ics file (Apple Calendar, Thunderbird…). */
defineProps<{
    calendar: { google: string; outlook: string; ics: string };
    label?: boolean;
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                :size="label ? 'sm' : 'icon-sm'"
                :title="t('Ajouter à mon agenda')"
                :aria-label="t('Ajouter à mon agenda')"
            >
                <CalendarPlus />
                <template v-if="label">{{ t("Ajouter à l'agenda") }}</template>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>{{
                t('Ajouter à mon agenda')
            }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem as-child>
                <a :href="calendar.google" target="_blank" rel="noopener">{{
                    t('Google Agenda')
                }}</a>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <a :href="calendar.outlook" target="_blank" rel="noopener">{{
                    t('Outlook / Microsoft 365')
                }}</a>
            </DropdownMenuItem>
            <DropdownMenuItem as-child>
                <a :href="calendar.ics" download>{{
                    t('Apple Calendar, autres (.ics)')
                }}</a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
