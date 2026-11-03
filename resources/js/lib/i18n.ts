import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import en from '@/i18n/en';

/**
 * UI translations. The interface is written in French, so the French text
 * is the key: `t('Tableau de bord')` shows « Tableau de bord » in French and
 * "Dashboard" in English (resources/js/i18n/en.ts). A text missing from the
 * dictionary stays in French, so pages can be translated one at a time.
 *
 * Placeholders: `t('Bonjour :name', { name })`.
 */
export type Locale = 'fr' | 'en';

export const LOCALES: { value: Locale; label: string }[] = [
    { value: 'fr', label: 'Français' },
    { value: 'en', label: 'English' },
];

const dictionaries: Record<Locale, Record<string, string>> = { fr: {}, en };

export const locale = ref<Locale>('fr');

export function setLocale(value: unknown): void {
    const next: Locale = value === 'en' ? 'en' : 'fr';

    locale.value = next;

    if (typeof document !== 'undefined') {
        document.documentElement.lang = next;
    }
}

export function t(
    text: string,
    replacements: Record<string, string | number | null | undefined> = {},
): string {
    let out = dictionaries[locale.value][text] ?? text;

    for (const [key, value] of Object.entries(replacements)) {
        out = out.replaceAll(`:${key}`, String(value ?? ''));
    }

    return out;
}

/** BCP 47 tag for Intl / toLocaleString. */
export function intlLocale(): string {
    return locale.value === 'en' ? 'en-GB' : 'fr-FR';
}

/** Follow the user's language on the first page and after each visit. */
export function initializeI18n(): void {
    if (typeof document === 'undefined') {
        return;
    }

    // The server renders <html lang> in the user's language (SetLocale middleware).
    setLocale(document.documentElement.lang.slice(0, 2));

    // beforeUpdate runs for every server response (also "replace" visits such
    // as the redirect back after switching language), before the new page
    // renders; navigate covers back/forward through the history.
    const follow = (props: unknown) => {
        const next = (props as { locale?: string } | undefined)?.locale;

        if (next && next !== locale.value) {
            setLocale(next);
        }
    };

    router.on('beforeUpdate', (event) => follow(event.detail.page.props));
    router.on('navigate', (event) => follow(event.detail.page.props));
}

export function useI18n() {
    return { t, locale, setLocale, intlLocale };
}
