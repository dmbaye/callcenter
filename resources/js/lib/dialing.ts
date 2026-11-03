/**
 * What the softphone sends to Asterisk for a typed number.
 *
 *  - a colleague's extension: unchanged (internal call, `_X.`);
 *  - an outside number: 00 + international number, which the team context
 *    routes to the platform trunk (`_00X.` → cc-outbound).
 *
 * National numbers get the platform country code: 76 12 34 56 → 0022376123456.
 */
export type DialingRules = {
    countryCode: string;
    nationalLength: number;
};

export function dialTarget(
    raw: string,
    extensions: Set<string>,
    rules: DialingRules,
): string {
    const typed = raw.replace(/[\s.()-]/g, '');

    if (extensions.has(typed)) {
        return typed;
    }

    if (/^\+\d+$/.test(typed)) {
        return '00' + typed.slice(1);
    }

    if (!/^\d+$/.test(typed) || typed.startsWith('00')) {
        return typed;
    }

    const { countryCode, nationalLength } = rules;

    if (typed.length === nationalLength) {
        return '00' + countryCode + typed;
    }

    if (
        countryCode !== '' &&
        typed.startsWith(countryCode) &&
        typed.length === countryCode.length + nationalLength
    ) {
        return '00' + typed;
    }

    // Anything else (an unknown extension, a short code): sent as typed.
    return typed;
}
