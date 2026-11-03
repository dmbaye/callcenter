import type { Plan } from '@/types';

const formatNumber = (value: number) =>
    new Intl.NumberFormat('fr-FR').format(value);

/** Outbound calls: bundled minutes, or billed separately when 0 / missing. */
export function outboundCallsText(plan: Plan): string {
    const minutes = plan.limits.outbound_minutes ?? 0;

    return minutes > 0
        ? `${formatNumber(minutes)} min d'appels sortants incluses / mois`
        : 'Appels sortants non inclus, facturés à la minute';
}

/** Call recording, with how long recordings are kept. */
export function recordingText(plan: Plan): string {
    const days = plan.limits.recording_days ?? 0;

    return days > 0
        ? `Enregistrement des appels (conservés ${days} jours)`
        : 'Enregistrement des appels';
}

const unlimited = (value: number | null, one: string, many: string) =>
    value === null
        ? `${many.charAt(0).toUpperCase()}${many.slice(1)} illimités`
        : `${value} ${value > 1 ? many : one}`;

/** One line of a plan card: something the plan includes, or explicitly does not. */
export type PlanLine = {
    key: string;
    text: string;
    included: boolean;
};

/** "40 min", "4 h", "24 h", "1 h 30". */
export function meetingDurationText(
    minutes: number | null | undefined,
): string {
    if (minutes === null || minutes === undefined) {
        return 'sans limite de durée';
    }

    if (minutes < 60) {
        return `${minutes} min`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return rest ? `${hours} h ${rest}` : `${hours} h`;
}

const hasVideo = (plan: Plan) =>
    plan.features.video && !!plan.limits.meeting_participants;

/** Video meetings: participants and longest meeting, like Zoom's plans. */
export function videoText(plan: Plan): string {
    if (!hasVideo(plan)) {
        return 'Réunions vidéo non incluses';
    }

    const quality = plan.features.full_hd_video
        ? ' Full HD (1080p)'
        : plan.features.hd_video
          ? ' HD (720p)'
          : '';

    return `Réunions vidéo${quality} : ${plan.limits.meeting_participants} participants, ${meetingDurationText(plan.limits.meeting_minutes)} max par réunion`;
}

/**
 * Lines for a plan card, from the limits the app enforces. Paid options a
 * plan lacks (outbound minutes, video, call recording, campaigns) are listed
 * as not included, so plans compare line by line.
 */
export function planHighlights(plan: Plan): PlanLine[] {
    const { limits, features } = plan;
    const video = hasVideo(plan);

    const lines: PlanLine[] = [
        {
            key: 'agents',
            text:
                limits.agents === null
                    ? 'Agents illimités'
                    : `${limits.agents} agents inclus` +
                      (plan.extraAgentPrice
                          ? ` (+${formatNumber(plan.extraAgentPrice)} F / agent supp.)`
                          : ''),
            included: true,
        },
        {
            key: 'outbound',
            text: outboundCallsText(plan),
            included: (limits.outbound_minutes ?? 0) > 0,
        },
        {
            key: 'softphone',
            text: 'Softphone navigateur, SVI, horaires et demandes de rappel',
            included: true,
        },
        {
            key: 'supervision',
            text: 'Supervision en direct, écoute et chuchotement',
            included: features.monitoring,
        },
        {
            key: 'queues',
            text: unlimited(limits.queues, "file d'appels", "files d'appels"),
            included: true,
        },
        {
            key: 'supervisors',
            text: unlimited(limits.supervisors, 'superviseur', 'superviseurs'),
            included: true,
        },
        {
            key: 'history',
            text: `Historique des appels : ${limits.history_months} mois`,
            included: true,
        },
        {
            key: 'call-recording',
            text: features.call_recording
                ? recordingText(plan)
                : 'Enregistrement des appels non inclus',
            included: !!features.call_recording,
        },
        {
            key: 'campaigns',
            text: features.campaigns
                ? 'Campagnes sortantes (aperçu, progressif, power)'
                : 'Campagnes sortantes non incluses',
            included: features.campaigns,
        },
        { key: 'video', text: videoText(plan), included: video },
    ];

    if (video) {
        lines.push({
            key: 'meetings-planned',
            text:
                'Réunions planifiées : invitations agenda, rappels et notes partagées' +
                (features.recording ? ', enregistrement' : ''),
            included: true,
        });
    }

    if (features.web_widget) {
        lines.push({
            key: 'widget',
            text: 'Widget web : appels vidéo de vos clients (bientôt)',
            included: true,
        });
    }

    lines.push({
        key: 'support',
        text: `Support : ${plan.support}`,
        included: true,
    });

    return lines;
}

export type ComparisonRow = {
    label: string;
    value: (plan: Plan) => string | boolean;
};

export type ComparisonSection = {
    title: string;
    rows: ComparisonRow[];
};

const limitText = (value: number | null) =>
    value === null ? 'Illimité' : String(value);

const always = () => true;

/** The comparison table, grouped like the product. */
export const comparisonSections: ComparisonSection[] = [
    {
        title: "Centre d'appels",
        rows: [
            {
                label: 'Agents inclus',
                value: (p) =>
                    limitText(p.limits.agents) +
                    (p.extraAgentPrice
                        ? ` (+${formatNumber(p.extraAgentPrice)} F/supp.)`
                        : ''),
            },
            {
                label: 'Appels sortants',
                value: (p) =>
                    (p.limits.outbound_minutes ?? 0) > 0
                        ? `${formatNumber(p.limits.outbound_minutes ?? 0)} min / mois`
                        : 'Facturés à la minute',
            },
            { label: 'Softphone dans le navigateur', value: always },
            {
                label: "Files d'appels",
                value: (p) => limitText(p.limits.queues),
            },
            { label: 'Menus vocaux (SVI) et numéros', value: always },
            { label: "Horaires d'ouverture et jours fériés", value: always },
            { label: 'Demandes de rappel des clients', value: always },
            { label: 'Fiche client à l’arrivée de l’appel', value: always },
            { label: "Qualification et transfert d'appel", value: always },
            {
                label: 'Historique des appels',
                value: (p) => `${p.limits.history_months} mois`,
            },
            {
                label: 'Enregistrement des appels',
                value: (p) =>
                    p.features.call_recording
                        ? (p.limits.recording_days ?? 0) > 0
                            ? `Conservés ${p.limits.recording_days} jours`
                            : true
                        : false,
            },
        ],
    },
    {
        title: 'Supervision',
        rows: [
            {
                label: 'Superviseurs',
                value: (p) => limitText(p.limits.supervisors),
            },
            {
                label: 'Supervision en direct',
                value: (p) => p.features.monitoring,
            },
            {
                label: 'Écoute et chuchotement',
                value: (p) => p.features.monitoring,
            },
            {
                label: 'Statuts agent, motifs de pause et journal',
                value: always,
            },
        ],
    },
    {
        title: 'Campagnes sortantes',
        rows: [
            { label: 'Campagnes', value: (p) => p.features.campaigns },
            {
                label: 'Modes aperçu, progressif et power',
                value: (p) => p.features.campaigns,
            },
            {
                label: 'Import CSV, scripts et codes de qualification',
                value: (p) => p.features.campaigns,
            },
            {
                label: 'Liste « Ne pas appeler »',
                value: (p) => p.features.campaigns,
            },
        ],
    },
    {
        title: 'Réunions vidéo',
        rows: [
            {
                label: 'Qualité vidéo',
                value: (p) =>
                    !hasVideo(p)
                        ? false
                        : p.features.full_hd_video
                          ? 'Full HD 1080p (caméra et écran)'
                          : p.features.hd_video
                            ? 'HD 720p (caméra et écran)'
                            : 'Standard 360p, écran 720p à 5 i/s',
            },
            {
                label: 'Participants par réunion',
                value: (p) =>
                    hasVideo(p)
                        ? `Jusqu'à ${p.limits.meeting_participants}`
                        : false,
            },
            {
                label: 'Durée max par réunion',
                value: (p) =>
                    hasVideo(p)
                        ? meetingDurationText(p.limits.meeting_minutes)
                        : false,
            },
            { label: 'Réunions instantanées et planifiées', value: hasVideo },
            {
                label: 'Invitations e-mail à ajouter à l’agenda',
                value: hasVideo,
            },
            { label: 'Rappels et notifications', value: hasVideo },
            { label: 'Notes de réunion partagées', value: hasVideo },
            { label: "Partage d'écran", value: hasVideo },
            {
                label: 'Enregistrement des réunions',
                value: (p) => hasVideo(p) && p.features.recording,
            },
            {
                label: 'Widget web (vidéo client)',
                value: (p) => (p.features.web_widget ? 'Bientôt' : false),
            },
        ],
    },
    {
        title: 'Accompagnement',
        rows: [
            {
                label: 'Assistant de démarrage, visite guidée, centre d’aide',
                value: always,
            },
            { label: 'Support', value: (p) => p.support },
        ],
    },
];
