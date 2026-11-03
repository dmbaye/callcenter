/** config/legal.php, shared with the legal pages. */
export type LegalInfo = {
    updated_at: string;
    appName: string;
    appUrl: string;
    company: {
        name: string | null;
        form: string | null;
        capital: string | null;
        address: string | null;
        rccm: string | null;
        nif: string | null;
        phone: string | null;
        email: string | null;
        publication_director: string | null;
    };
    privacy_email: string | null;
    security_email: string | null;
    apdp_reference: string | null;
    hosting: {
        name: string | null;
        address: string | null;
        description: string | null;
    };
    subprocessors: {
        name: string | null;
        role: string;
        country: string | null;
    }[];
};

export type LegalPlan = {
    name: string;
    historyMonths: number | null;
    recordingDays: number | null;
};

/** A value from config/legal.php, or the visible placeholder of a draft. */
export const TODO = '[à compléter]';

export function orTodo(value: string | null | undefined): string {
    return value && value.trim() !== '' ? value : TODO;
}
