export type BillingCycle = 'monthly' | 'annual';

export type PlanLimits = {
    agents: number | null;
    supervisors: number | null;
    queues: number | null;
    history_months: number | null;
    meeting_participants: number | null;
    /** Longest video meeting, in minutes (null = no limit). */
    meeting_minutes?: number | null;
    /** Outbound minutes bundled per month; 0 = billed separately. */
    outbound_minutes?: number | null;
    /** Days call recordings are kept (call_recording feature). */
    recording_days?: number | null;
};

export type PlanFeatures = {
    monitoring: boolean;
    campaigns: boolean;
    video: boolean;
    /** Recording of video meetings. */
    recording: boolean;
    call_recording?: boolean;
    /** 720p camera and screen sharing in meetings. */
    hd_video?: boolean;
    /** 1080p camera and screen sharing in meetings. */
    full_hd_video?: boolean;
    web_widget: boolean;
};

export type Plan = {
    code: string;
    name: string;
    tagline: string;
    monthlyPrice: number;
    annualPrice: number;
    highlighted: boolean;
    /** Price 0: chosen without payment, never expires. */
    isFree?: boolean;
    limits: PlanLimits;
    extraAgentPrice: number | null;
    features: PlanFeatures;
    support: string;
    blockers?: string[];
};

export type SubscriptionState = 'trialing' | 'active' | 'grace' | 'expired';

/** Shared on every page (HandleInertiaRequests). */
export type BillingSummary = {
    plan: string;
    planName: string;
    state: SubscriptionState;
    stateLabel: string;
    onTrial: boolean;
    endsAt: string;
    features: PlanFeatures;
    /** Free plan: never expires, no payment. */
    isFree?: boolean;
    /** Plan names that include each feature, e.g. { campaigns: 'Pro et Corporate' }. */
    includedIn?: Record<string, string>;
};

export type SubscriptionDetails = {
    plan: string;
    planName: string;
    cycle: BillingCycle;
    extraAgents: number;
    onTrial: boolean;
    isFree?: boolean;
    state: SubscriptionState;
    stateLabel: string;
    endsAt: string;
    graceEndsAt: string | null;
};

export type PaymentRow = {
    transactionId: string;
    planName: string;
    cycle: BillingCycle;
    extraAgents: number;
    amount: number;
    currency: string;
    status: 'pending' | 'accepted' | 'refused';
    statusLabel: string;
    method: string | null;
    paidBy: string | null;
    createdAt: string;
    periodEndsAt: string | null;
};

export type MeetingParticipant = { id: number; name: string; joined: boolean };

export type MeetingRow = {
    uuid: string;
    title: string;
    description: string | null;
    status: 'live' | 'upcoming' | 'ended' | 'cancelled';
    kind: 'instant' | 'scheduled';
    creator: string | null;
    isOrganizer: boolean;
    canManage: boolean;
    participants: MeetingParticipant[];
    maxParticipants: number;
    scheduledAt: string | null;
    durationMinutes: number | null;
    endsAt: string | null;
    createdAt: string;
    startedAt: string | null;
    endedAt: string | null;
    endedReason: 'host' | 'limit' | 'empty' | null;
    /** Plan time limit fixed at the start (null: not started or no limit). */
    timeLimitMinutes: number | null;
    limitEndsAt: string | null;
    notes: string | null;
    notesUpdatedAt: string | null;
    notesUpdatedBy: string | null;
    canEditNotes: boolean;
    recording: boolean;
    hasRecording: boolean;
    url: string;
    calendar: { google: string; outlook: string; ics: string } | null;
};
