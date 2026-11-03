import { intlLocale, t } from '@/lib/i18n';
/** 75 → "1:15", 3725 → "1:02:05" */
// export function formatDuration(seconds: number | null | undefined): string {
//     if (seconds === null || seconds === undefined) {
//         return '—';
//     }

//     const total = Math.max(0, Math.round(seconds));
//     const h = Math.floor(total / 3600);
//     const m = Math.floor((total % 3600) / 60);
//     const s = total % 60;
//     const pad = (n: number) => String(n).padStart(2, '0');

//     return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
// }

export function formatDuration(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined) {
        return '—';
    }

    const total = Math.max(0, Math.round(seconds));
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => String(n).padStart(2, '0');

    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

export function formatDay(date: string): string {
    return new Intl.DateTimeFormat(intlLocale(), {
        weekday: 'short',
        day: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
}

/** "2026-10-05 14:32:10" (CDR time) → "14:32" */
export function formatTime(value: string): string {
    const date = new Date(value.replace(' ', 'T'));

    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
          }).format(date);
}

/** 25000 → "25 000 F CFA" */
export function formatMoney(amount: number, currency = 'XOF'): string {
    const value = new Intl.NumberFormat(intlLocale(), {
        maximumFractionDigits: 0,
    }).format(amount);

    return currency === 'XOF' ? `${value} F CFA` : `${value} ${currency}`;
}

/** ISO date → "28 oct. 2026" */
export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? '—'
        : new Intl.DateTimeFormat(intlLocale(), {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          }).format(date);
}

/** "il y a 3 jours", "hier", "à l'instant" — for screen pops and history hints. */
export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return '—';
    }

    const date = new Date(
        value.includes('T') ? value : value.replace(' ', 'T'),
    );

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const rtf = new Intl.RelativeTimeFormat(intlLocale().slice(0, 2), {
        numeric: 'auto',
    });
    const units: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 31_536_000],
        ['month', 2_592_000],
        ['week', 604_800],
        ['day', 86_400],
        ['hour', 3_600],
        ['minute', 60],
    ];

    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return rtf.format(Math.round(seconds / size), unit);
        }
    }

    return t("à l'instant");
}
