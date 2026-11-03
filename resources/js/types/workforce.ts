export type WorkforceShell = {
    isSupervisor: boolean;
    isAdmin: boolean;
    timezone: string;
    today: string;
    pending: { swaps: number; leave: number; adjustments: number } | null;
    incomingSwaps: number;
};

export type WorkforceDay = {
    date: string;
    weekday: string;
    day: number;
    month: string;
    today: boolean;
    weekend: boolean;
};

export type ShiftTemplateCard = {
    id: number;
    code: string;
    name: string;
    startTime: string;
    endTime: string;
    window: string;
    hours: number;
    breakMinutes: number;
    minAgents: number;
    color: string;
    active: boolean;
};

export type ShiftCard = {
    id: number;
    userId: number;
    userName: string | null;
    templateId: number | null;
    code: string;
    name: string | null;
    color: string;
    date: string;
    startsAt: string;
    endsAt: string;
    window: string;
    note: string | null;
    warnings: string[];
};

export type SwapCard = {
    id: number;
    status: string;
    statusLabel: string;
    requester: { id: number; name: string | null };
    counterparty: { id: number; name: string | null };
    give: ShiftCard | null;
    take: ShiftCard | null;
    message: string | null;
    reviewNote: string | null;
    reviewer: string | null;
    autoApproved: boolean;
    createdAt: string | null;
};

export type LeaveCard = {
    id: number;
    user: { id: number; name: string | null };
    type: { id: number; name: string | null; paid: boolean | null };
    startDate: string;
    endDate: string;
    startTime: string | null;
    endTime: string | null;
    partial: boolean;
    days: number | string;
    reason: string | null;
    status: string;
    statusLabel: string;
    reviewer: string | null;
    reviewNote: string | null;
    createdAt: string | null;
};

export type AdjustmentCard = {
    id: number;
    user: { id: number; name: string | null };
    entryId: number | null;
    date: string;
    clockIn: string;
    clockOut: string;
    breakMinutes: number | null;
    reason: string;
    previous: {
        clock_in_at?: string;
        clock_out_at?: string | null;
        break_minutes?: number;
    } | null;
    status: string;
    reviewer: string | null;
    reviewNote: string | null;
    createdAt: string | null;
};

export type LeaveTypeCard = {
    id: number;
    code: string;
    name: string;
    paid: boolean;
    annualDays: number | string | null;
    active: boolean;
};

export type LeaveBalance = {
    type: { id: number; name: string; paid: boolean };
    allowance: number | string | null;
    used: number;
    pending: number;
    remaining: number | null;
};

export type SheetTotals = {
    scheduled: number;
    worked: number;
    breaks: number;
    leave: number;
    variance: number;
    late: number;
    absences: number;
};

export type SheetDay = {
    date: string;
    shifts: ShiftCard[];
    entries: {
        id: number;
        clockIn: string;
        clockOut: string | null;
        breakMinutes: number;
        workedMinutes: number;
        source: string;
    }[];
    leaveMinutes: number;
    scheduledMinutes: number;
    expectedMinutes: number;
    workedMinutes: number;
    breakMinutes: number;
    varianceMinutes: number;
    flags: { key: string; label: string }[];
    pendingAdjustments: number;
};

export type Timesheet = {
    days: SheetDay[];
    totals: SheetTotals;
    approval: { by: string | null; at: string } | null;
};
