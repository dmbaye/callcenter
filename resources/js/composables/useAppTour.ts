import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, ref } from 'vue';

import { complete as completeRoute } from '@/routes/tour';

export type TourStep = {
    /** data-tour anchor; no target = centered card. */
    target?: string;
    title: string;
    body: string;
    /** Who the step is for; omitted = everyone. */
    when?: (context: TourContext) => boolean;
};

export type TourContext = {
    isAgent: boolean;
    canSupervise: boolean;
    /** Supervisors and planners: roster and planning approvals. */
    canPlan: boolean;
    canAdminister: boolean;
};

/**
 * The guided tour of the app. Each step points at an element marked with
 * data-tour="…"; steps whose element is not on screen (role, mobile
 * sidebar closed) are skipped, so one list fits every user.
 */
export const tourSteps: TourStep[] = [
    {
        title: 'Bienvenue 👋',
        body: 'Cette visite de deux minutes vous montre où se trouve chaque chose. Utilisez les flèches du clavier ou les boutons pour avancer, Échap pour quitter.',
    },
    {
        target: 'nav-dashboard',
        title: 'Tableau de bord',
        body: 'Votre point de départ : les chiffres du jour et les appels récents, adaptés à votre rôle.',
    },
    {
        target: 'agent-status',
        title: 'Votre statut',
        body: 'Prêt, vous recevez les appels des files. Non prêt (avec un motif), Post-appel ou Ne pas déranger, ils ne vous sont plus présentés. Raccourcis : Ctrl+Alt+R, N et Z.',
        when: (c) => c.isAgent,
    },
    {
        target: 'softphone',
        title: 'Le softphone',
        body: 'Votre téléphone, dans le navigateur. Le point vert indique que le poste est connecté. Ouvrez-le pour composer un numéro ; les appels entrants s’affichent ici.',
        when: (c) => c.isAgent,
    },
    {
        target: 'notifications',
        title: 'Notifications',
        body: 'Réunions, planning (nouveaux postes, échanges, congés) et abonnement : tout arrive ici, en temps réel. Choisissez ce que vous recevez par e-mail dans Paramètres → Notifications.',
    },
    {
        target: 'theme',
        title: 'Clair ou sombre',
        body: 'Un clic passe du thème clair au thème sombre, puis au réglage de votre appareil.',
    },
    {
        target: 'nav-calls',
        title: 'Historique',
        body: 'Tous les appels, avec recherche par numéro, enregistrements et vos résultats d’appels.',
    },
    {
        target: 'nav-monitoring',
        title: 'Supervision',
        body: 'Les files et les agents en temps réel : appels en attente, agents libres ou en pause. Écoutez ou chuchotez sur un appel en cours.',
        when: (c) => c.canSupervise,
    },
    {
        target: 'nav-agent-logs',
        title: 'Journal des agents',
        body: 'Le temps passé par chaque agent dans chaque statut et par motif de pause.',
        when: (c) => c.canSupervise,
    },
    {
        target: 'nav-campaigns',
        title: 'Campagnes',
        body: 'Les appels sortants : listes de contacts, script et qualification. Les demandes de rappel des clients arrivent aussi ici.',
    },
    {
        target: 'nav-planning',
        title: 'Planning',
        body: 'Votre planning de la semaine, le pointage, les échanges de postes avec vos collègues, votre feuille de temps et vos congés.',
        when: (c) => !c.canPlan,
    },
    {
        target: 'nav-planning',
        title: 'Planning',
        body: 'Le roster de l’équipe en semaine ou en mois, avec la couverture par poste. Vous y validez les échanges, les congés et les feuilles de temps, et exportez en Excel ou PDF.',
        when: (c) => c.canPlan,
    },
    {
        target: 'nav-meetings',
        title: 'Réunions vidéo',
        body: 'Briefings et formations en visio : planifiez, invitez vos collègues et partagez votre écran. La qualité vidéo (jusqu’à 1080p) dépend de votre formule.',
    },
    {
        target: 'team-switcher',
        title: 'Votre organisation',
        body: 'Si vous travaillez pour plusieurs organisations, passez de l’une à l’autre ici.',
    },
    {
        target: 'user-menu',
        title: 'Paramètres',
        body: 'Votre profil (photo, fonction, langue français ou anglais), vos notifications et votre sécurité. Les administrateurs y règlent aussi les membres, les postes, les numéros, le SVI et l’abonnement.',
    },
    {
        target: 'nav-help',
        title: 'Besoin d’aide ?',
        body: 'Le centre d’aide explique chaque fonction pas à pas. Vous pourrez y relancer cette visite à tout moment.',
    },
];

const active = ref(false);
const index = ref(0);
const steps = ref<TourStep[]>([]);

/** The visible element of a step (a hidden sidebar link does not count). */
export function tourTarget(step: TourStep | undefined): HTMLElement | null {
    if (!step?.target) {
        return null;
    }

    const elements = document.querySelectorAll<HTMLElement>(
        `[data-tour="${step.target}"]`,
    );

    for (const element of Array.from(elements)) {
        const rect = element.getBoundingClientRect();

        if (rect.width > 0 && rect.height > 0) {
            return element;
        }
    }

    return null;
}

let saved = false;

/** Remember on the server that the user saw the tour (once per page load). */
function markCompleted() {
    if (saved) {
        return;
    }

    saved = true;
    void axios.post(completeRoute.url()).catch(() => {
        saved = false;
    });
}

export function useAppTour() {
    const page = usePage();

    const context = computed<TourContext>(() => {
        const role = page.props.currentTeam?.role;

        return {
            isAgent: Boolean(page.props.sipCredentials),
            canSupervise:
                !!role && ['owner', 'admin', 'supervisor'].includes(role),
            canPlan:
                !!role &&
                ['owner', 'admin', 'supervisor', 'planner'].includes(role),
            canAdminister: role === 'owner' || role === 'admin',
        };
    });

    const current = computed(() => steps.value[index.value]);

    function start() {
        // Steps without a visible target are dropped, except centered ones.
        steps.value = tourSteps.filter(
            (step) =>
                (!step.when || step.when(context.value)) &&
                (!step.target || tourTarget(step) !== null),
        );
        index.value = 0;
        active.value = steps.value.length > 0;
    }

    function stop() {
        active.value = false;
        markCompleted();
    }

    function next() {
        if (index.value < steps.value.length - 1) {
            index.value++;

            return;
        }

        stop();
    }

    function previous() {
        if (index.value > 0) {
            index.value--;
        }
    }

    return {
        active,
        index,
        steps,
        current,
        start,
        stop,
        next,
        previous,
    };
}
