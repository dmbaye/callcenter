import { intlLocale, t } from '@/lib/i18n';

/** Tailwind classes per shift colour (kept literal so Tailwind sees them). */
export const SHIFT_COLORS: Record<string, { chip: string; dot: string }> = {
    blue: {
        chip: 'bg-blue-100 text-blue-900 border-blue-200 dark:bg-blue-500/20 dark:text-blue-100 dark:border-blue-400/30',
        dot: 'bg-blue-500',
    },
    violet: {
        chip: 'bg-violet-100 text-violet-900 border-violet-200 dark:bg-violet-500/20 dark:text-violet-100 dark:border-violet-400/30',
        dot: 'bg-violet-500',
    },
    green: {
        chip: 'bg-emerald-100 text-emerald-900 border-emerald-200 dark:bg-emerald-500/20 dark:text-emerald-100 dark:border-emerald-400/30',
        dot: 'bg-emerald-500',
    },
    orange: {
        chip: 'bg-orange-100 text-orange-900 border-orange-200 dark:bg-orange-500/20 dark:text-orange-100 dark:border-orange-400/30',
        dot: 'bg-orange-500',
    },
    pink: {
        chip: 'bg-pink-100 text-pink-900 border-pink-200 dark:bg-pink-500/20 dark:text-pink-100 dark:border-pink-400/30',
        dot: 'bg-pink-500',
    },
    teal: {
        chip: 'bg-teal-100 text-teal-900 border-teal-200 dark:bg-teal-500/20 dark:text-teal-100 dark:border-teal-400/30',
        dot: 'bg-teal-500',
    },
    gray: {
        chip: 'bg-muted text-foreground border-border',
        dot: 'bg-muted-foreground',
    },
};

export function shiftColor(color: string | null | undefined) {
    return SHIFT_COLORS[color ?? 'gray'] ?? SHIFT_COLORS.gray;
}

/** 455 → "7 h 35", -30 → "-0 h 30". */
export function hours(minutes: number | null | undefined): string {
    const value = Math.round(minutes ?? 0);
    const sign = value < 0 ? '-' : '';
    const abs = Math.abs(value);

    return `${sign}${Math.floor(abs / 60)} h ${String(abs % 60).padStart(2, '0')}`;
}

function parse(date: string): Date {
    // "2026-10-31" or "2026-10-31 08:00": wall-clock values, read as local.
    const [day, time = '00:00'] = date.split(' ');
    const [y, m, d] = day.split('-').map(Number);
    const [hh, mm] = time.split(':').map(Number);

    return new Date(y, m - 1, d, hh, mm);
}

export function formatDay(
    date: string,
    options: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    },
): string {
    return parse(date).toLocaleDateString(intlLocale(), options);
}

export function formatTime(dateTime: string | null | undefined): string {
    return dateTime ? dateTime.slice(11, 16) : '—';
}

export function shiftLabel(
    shift: {
        name: string | null;
        code: string;
        date: string;
        window: string;
    } | null,
): string {
    if (!shift) {
        return t('aucun poste');
    }

    return `${shift.name ?? shift.code} · ${formatDay(shift.date)} · ${shift.window}`;
}

export function leavePeriod(leave: {
    startDate: string;
    endDate: string;
    partial: boolean;
    startTime: string | null;
    endTime: string | null;
}): string {
    if (leave.partial) {
        return `${formatDay(leave.startDate)} · ${leave.startTime}–${leave.endTime}`;
    }

    if (leave.startDate === leave.endDate) {
        return formatDay(leave.startDate, {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });
    }

    return `${formatDay(leave.startDate)} → ${formatDay(leave.endDate)}`;
}

export const STATUS_TONES: Record<string, string> = {
    pending: 'bg-notice-subtle text-notice-strong border-notice-border',
    pending_counterparty:
        'bg-notice-subtle text-notice-strong border-notice-border',
    pending_supervisor:
        'bg-notice-subtle text-notice-strong border-notice-border',
    approved: 'bg-positive-subtle text-positive-strong border-positive-border',
    declined: 'bg-negative-subtle text-negative-strong border-negative-border',
    rejected: 'bg-negative-subtle text-negative-strong border-negative-border',
    cancelled: 'bg-muted text-muted-foreground border-border',
    expired: 'bg-muted text-muted-foreground border-border',
};

export function planningUrl(
    slug: string,
    path = '',
    query: Record<string, string | number | null | undefined> = {},
): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(query)) {
        if (value !== null && value !== undefined && value !== '') {
            params.set(key, String(value));
        }
    }

    const qs = params.toString();

    return `/${slug}/planning${path ? `/${path}` : ''}${qs ? `?${qs}` : ''}`;
}
