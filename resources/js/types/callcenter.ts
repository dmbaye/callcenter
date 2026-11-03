export type Call = {
    accountcode: string;
    src: string;
    dst: string;
    dcontext: string;
    clid: string;
    channel: string;
    dstchannel: string;
    lastapp: string;
    lastdata: string;
    start: string;
    answer: string | null;
    end: string;
    duration: number;
    billsec: number;
    disposition: string;
    amaflags: string;
    userfield: string;
    uniqueid: string;
    linkedid: string;
    peeraccount: string;
    sequence: number;
    agent?: string | null;
    agent_name?: string | null;
    /** Uploaded recording of this call (Pro / Entreprise, recording queues). */
    recording?: { url: string; duration: number | null } | null;
};

export type SipCredentials = {
    username: string;
    pjsip_name?: string;
    extension?: string;
    password?: string;
    domain?: string;
    host?: string;
    wsServer?: string | null;
    iceServers?: RTCIceServer[];
};

export type CallStatus =
    | 'idle'
    | 'registering'
    | 'registered'
    | 'calling'
    | 'ringing'
    | 'connecting'
    | 'active'
    | 'ended';

export type AgentAvailability =
    | 'offline'
    | 'available'
    | 'paused'
    | 'busy'
    | 'ringing';

export type QueueStatus = Extract<
    AgentAvailability,
    'offline' | 'available' | 'paused'
>;

/** What the agent chose in the status menu. */
export type AgentStateValue =
    | 'ready'
    | 'not_ready'
    | 'wrap_up'
    | 'dnd'
    | 'logged_out';

export type PauseReason = {
    id: number;
    name: string;
};

export type Paginated<T> = {
    data: T[];
    path: string;
    current_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    last_page: number;
};

export type DashboardCall = {
    id: string;
    name: string;
    number: string;
    started_at: string;
    callType: 'received' | 'missed';
};

export type Filters = {
    search?: string;
    status?: string;
    from?: string;
    to?: string;
    agent?: string;
};

export type Agent = {
    name: string;
    extension: string;
};

export type SupervisorAgent = {
    id: number;
    name: string;
    email: string;
    extension: string;
    queue: string;
    queues: string[];
    status: 'offline' | 'available' | 'paused' | 'busy' | 'ringing';
    state: AgentStateValue;
    stateLabel: string;
    stateSince: string | null;
    call: {
        channel: string | null;
        state: string | null;
        caller: string | null;
        duration: number | null;
    } | null;
};

export type WaitingCall = {
    queue: string;
    position: number;
    caller: string;
    name: string;
    wait: number;
};

export type QueueSummary = {
    name: string;
    asterisk_name: string;
    waiting: number;
    longest_wait: number;
    free: number;
    in_call: number;
    paused: number;
    offline: number;
    completed: number;
    abandoned: number;
    /** Only when the team tracks its service level. */
    service_level?: { percent: number; threshold: number } | null;
};

export type ServiceLevelSummary = {
    target: number;
    percent: number | null;
};

export type MonitoringSummary = {
    agents: number;
    waiting: number;
    longest_wait: number;
    free: number;
    in_call: number;
    ringing: number;
    paused: number;
    offline: number;
};

export type AgentCallDetails = {
    id: number;
    name: string;
    extension: string;
    total_calls: number;
    answered: number;
    missed: number;
    avg_answer_seconds: number | null;
    total_duration_seconds: number;
    avg_duration_seconds: number | null;
};

export type MonitoringSnapshot = {
    live: boolean;
    agents: SupervisorAgent[];
    agentCallDetails: AgentCallDetails[];
    waiting: WaitingCall[];
    queues: QueueSummary[];
    summary: MonitoringSummary;
    serviceLevel?: ServiceLevelSummary | null;
};

export type CallSummary = {
    total: number;
    answered: number;
    missed: number;
    answer_rate: number;
    avg_talk_seconds: number | null;
};

export type RosterAgent = {
    id: number;
    name: string;
    extension: string;
    role: string;
    answered: number;
    missed: number;
    talk_seconds: number;
    presence: 'offline' | 'available' | 'paused';
};

export type DailyPoint = {
    date: string;
    total: number;
    answered: number;
    missed: number;
};

export type DialplanRow = {
    context: string;
    exten: string;
    priority: number;
    app: string;
    appdata: string | null;
    /** Written by the app (internal calls, queue entry, voice menu): read-only here. */
    managed?: boolean;
};

export type CallFlowNode = {
    kind: string;
    label: string;
    details: string[];
    children: CallFlowNode[];
    link: 'queues' | 'ivr' | 'pjsip' | null;
    warning: boolean;
};

export type QueueRow = {
    id: number;
    name: string;
    asterisk_name: string;
    [column: string]: string | number | null;
};

export type PjsipEndpoint = {
    id: string;
    extension: string;
    transport?: string | null;
    context?: string | null;
    allow?: string | null;
    aors?: string | null;
    auth?: string | null;
    max_contacts?: number | null;
    user_id: number | null;
    user_name: string | null;
    /** A phone_extensions row exists for this endpoint. */
    linked: boolean;
    /** The extension exists in the app but not (yet) in Asterisk. */
    missing?: boolean;
};

export type QueueHourRow = {
    id: number;
    call_queue_id: number;
    weekday: number;
    opens_at: string;
    closes_at: string;
};
