export type HelpArticleLink = {
    slug: string;
    title: string;
    summary: string;
    audience: 'all' | 'agent' | 'supervisor' | 'admin';
};

export type HelpSection = {
    key: string;
    title: string;
    articles: HelpArticleLink[];
};

export type HelpSearchEntry = { slug: string; text: string };

/** Lowercase without accents, so "prêt" matches "pret". */
export function normalize(value: string): string {
    return value
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase();
}

/** Articles whose title, summary or text contain every word of the query. */
export function searchArticles(
    sections: HelpSection[],
    index: HelpSearchEntry[],
    query: string,
): HelpArticleLink[] {
    const words = normalize(query).split(/\s+/).filter(Boolean);

    if (words.length === 0) {
        return [];
    }

    const texts = new Map(index.map((entry) => [entry.slug, entry.text]));

    return sections
        .flatMap((section) => section.articles)
        .map((article) => {
            const head = normalize(`${article.title} ${article.summary}`);
            const body = normalize(texts.get(article.slug) ?? '');

            if (!words.every((word) => head.includes(word) || body.includes(word))) {
                return null;
            }

            // Title and summary matches first.
            const score = words.filter((word) => head.includes(word)).length;

            return { article, score };
        })
        .filter((hit): hit is { article: HelpArticleLink; score: number } => hit !== null)
        .sort((a, b) => b.score - a.score)
        .map((hit) => hit.article);
}
