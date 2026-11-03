import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';
import type { BillingSummary } from '@/types/billing';
import type { SipCredentials } from '@/types/callcenter';
import type { Team } from '@/types/teams';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            sipCredentials?: SipCredentials | null;
            /** The current team's extensions: calls with them are internal. */
            teamExtensions?: string[];
            /** How the softphone turns a national number into 00 + international. */
            dialing?: { countryCode: string; nationalLength: number };
            /** Interface language (SetLocale middleware). */
            locale?: 'fr' | 'en';
            presenceHeartbeat?: number;
            unreadNotifications?: number;
            billing?: BillingSummary | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
