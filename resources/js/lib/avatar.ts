const palette = [
    'bg-[#e0e9ff] text-[#2440b3]',
    'bg-[#d9f2e3] text-[#0b6b3a]',
    'bg-[#ffe9cc] text-[#8a4a00]',
    'bg-[#f6e0ff] text-[#6b2c91]',
    'bg-[#ffe0e0] text-[#a12121]',
    'bg-[#d9f1f5] text-[#0b5f6b]',
];

/** "Aminata Coulibaly" → "AC". */
export function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/** A soft background + text colour, the same for a name every time. */
export function avatarTone(name: string): string {
    let hash = 0;

    for (const character of name) {
        hash = (hash * 31 + character.charCodeAt(0)) >>> 0;
    }

    return palette[hash % palette.length];
}
