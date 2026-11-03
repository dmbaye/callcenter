import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import MarketingLayout from '@/layouts/marketing/MarketingLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeI18n } from '@/lib/i18n';
import { initializeTeamHeader } from '@/lib/teamHeader';

const appName = import.meta.env.VITE_APP_NAME || 'Callcenter';

// Interface language (French by default, English per user).
initializeI18n();

// Tag requests with the team the page belongs to (see EnsureCurrentTeamMatches)...
initializeTeamHeader();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('marketing/'):
                return MarketingLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('onboarding/'):
                return OnboardingLayout;
            case name.startsWith('settings/'):
            case name.startsWith('teams/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#2447E0',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
