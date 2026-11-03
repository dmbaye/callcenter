import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Organizations either run a call center, or only use the app for
 * internal calls (extension to extension) and video meetings. In the
 * second case every call-center screen is hidden (and closed server side).
 */
export function useTeamMode() {
    const page = usePage();

    const isInternalOnly = computed(
        () => page.props.currentTeam?.usageMode === 'internal',
    );

    return {
        isInternalOnly,
        isCallCenter: computed(() => !isInternalOnly.value),
    };
}
