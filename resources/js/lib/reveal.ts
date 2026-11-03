import type { Directive } from 'vue';

/**
 * Fades an element up when it scrolls into view (marketing pages).
 * Usage: v-reveal or v-reveal="120" for a delay in milliseconds.
 *
 * The hidden state is only added in the browser, so server-rendered HTML
 * and visitors without JavaScript always see the content.
 */
export const vReveal: Directive<HTMLElement, number | undefined> = {
    mounted(el, binding) {
        if (
            typeof IntersectionObserver === 'undefined' ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        el.classList.add('mk-reveal');

        if (binding.value) {
            el.style.setProperty('--mk-delay', `${binding.value}ms`);
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) {
                    el.classList.add('is-visible');
                    observer.disconnect();
                }
            },
            { rootMargin: '0px 0px -10% 0px' },
        );

        observer.observe(el);
        (
            el as HTMLElement & { _revealObserver?: IntersectionObserver }
        )._revealObserver = observer;
    },
    unmounted(el) {
        (
            el as HTMLElement & { _revealObserver?: IntersectionObserver }
        )._revealObserver?.disconnect();
    },
};
