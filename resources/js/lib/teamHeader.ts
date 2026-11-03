import { router } from '@inertiajs/vue3';
import axios from 'axios';

/**
 * Every request carries the slug of the team the current page was rendered
 * for (X-Team). Routes acting on the "current team" (settings/asterisk/*)
 * refuse writes when another tab switched the current team meanwhile.
 */
let renderedTeam: string | null = null;

export function currentPageTeam(): string | null {
    return renderedTeam;
}

export function initializeTeamHeader(): void {
    // Also fired for the initial page load.
    router.on('navigate', (event) => {
        renderedTeam =
            (event.detail.page.props.currentTeam as { slug?: string } | null)
                ?.slug ?? null;
    });

    router.on('before', (event) => {
        if (renderedTeam) {
            event.detail.visit.headers = {
                ...event.detail.visit.headers,
                'X-Team': renderedTeam,
            };
        }
    });

    axios.interceptors.request.use((config) => {
        if (renderedTeam) {
            config.headers.set('X-Team', renderedTeam);
        }

        return config;
    });
}

/**
 * Headers for fetch() calls that need CSRF protection and the team tag.
 * Uses the XSRF-TOKEN cookie (refreshed on every response, so it survives
 * the session regeneration at login), not the meta tag rendered once.
 */
export function requestHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='));
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    if (cookie) {
        headers['X-XSRF-TOKEN'] = decodeURIComponent(
            cookie.slice('XSRF-TOKEN='.length),
        );
    }

    if (renderedTeam) {
        headers['X-Team'] = renderedTeam;
    }

    return headers;
}
