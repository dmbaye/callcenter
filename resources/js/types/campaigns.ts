export type CampaignStatus = 'draft' | 'running' | 'paused' | 'completed';
export type DialModeValue = 'preview' | 'progressive' | 'power';
export type DispositionOutcomeValue =
    | 'success'
    | 'final'
    | 'retry'
    | 'callback'
    | 'dnc';

export type Option<T extends string = string> = { value: T; label: string };

export type DispositionRow = {
    id?: number | null;
    label: string;
    outcome: DispositionOutcomeValue;
    retry_minutes?: number | null;
};

export type CampaignSchedule = {
    days: number[];
    start: string;
    end: string;
};

export type CampaignListItem = {
    id: number;
    name: string;
    kind: 'outbound' | 'callback';
    status: CampaignStatus;
    statusLabel: string;
    mode: DialModeValue;
    modeLabel: string;
    contacts: number;
    open: number;
    attempts: number;
    connected: number;
    withinHours: boolean;
    createdAt: string;
};

export type CampaignDetails = {
    id: number;
    name: string;
    kind: 'outbound' | 'callback';
    description: string | null;
    status: CampaignStatus;
    statusLabel: string;
    dialMode: DialModeValue;
    dialModeLabel: string;
    dialRatio: number;
    maxAbandonRate: number;
    maxLines: number | null;
    callerId: string | null;
    dialPrefix: string | null;
    ringTimeout: number;
    queueTimeout: number;
    wrapupSeconds: number;
    amdEnabled: boolean;
    voicemailSound: string | null;
    abandonSound: string | null;
    maxAttempts: number;
    retryMinutes: Record<string, number>;
    timezone: string;
    schedule: CampaignSchedule;
    script: string | null;
    startedAt: string | null;
    dispositions: DispositionRow[];
    agentIds?: number[];
};

export type ContactCard = {
    id: number;
    phone: string;
    firstName: string | null;
    lastName: string | null;
    name: string;
    company: string | null;
    email: string | null;
    data: Record<string, string>;
    status: string;
    statusLabel: string;
    attempts: number;
    lastResult: string | null;
    lastResultLabel: string | null;
    nextAttemptAt: string | null;
    notes: string | null;
    disposition?: string | null;
};

export type CountRow = { label: string; count: number };

export type CampaignStatsSummary = {
    contacts: {
        total: number;
        byStatus: (CountRow & { status: string })[];
        penetration: number;
        callableNow: number;
    };
    attempts: {
        total: number;
        byResult: (CountRow & { result: string })[];
        connectRate: number;
        conversionRate: number;
        abandonRateHour: number;
        avgTalkSeconds: number | null;
    };
    dispositions: (CountRow & {
        id: number;
        outcome: DispositionOutcomeValue;
    })[];
    live: {
        inFlight: number;
        connected: number;
        effectiveRatio: number;
        withinHours: boolean;
    };
};

export type AgentStat = {
    id: number;
    name: string;
    calls: number;
    connected: number;
    successes: number;
};
