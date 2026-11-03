<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, Menu } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { solutions } from '@/data/solutions';
import { dashboard, login, register } from '@/routes';

const page = usePage();
const open = ref(false);
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

const links = [
    { title: 'Fonctionnalités', href: '/features' },
    { title: 'Solutions', href: '/solutions' },
    { title: 'Tarifs', href: '/pricing' },
    { title: 'À propos', href: '/about' },
    { title: 'Contact', href: '/contact' },
];

const footer = [
    {
        title: 'Produit',
        links: [
            { title: 'Appels entrants', href: '/features#entrant' },
            { title: 'Campagnes sortantes', href: '/features#campagnes' },
            { title: 'Supervision', href: '/features#supervision' },
            { title: 'Réunions vidéo', href: '/features#video' },
        ],
    },
    {
        title: 'Solutions',
        links: solutions.map((solution) => ({
            title: solution.name,
            href: `/solutions/${solution.slug}`,
        })),
    },
    {
        title: 'Offres',
        links: [
            { title: 'Tarifs', href: '/pricing' },
            { title: 'Essai gratuit', href: register().url },
            { title: 'Se connecter', href: login().url },
        ],
    },
    {
        title: 'Entreprise',
        links: [
            { title: 'À propos', href: '/about' },
            { title: 'Contact', href: '/contact' },
        ],
    },
];

const legalLinks = [
    { title: 'Confidentialité', href: '/confidentialite' },
    { title: 'Mentions légales', href: '/mentions-legales' },
    { title: 'Centre de confiance', href: '/confiance' },
];

const appUrl = computed(() => {
    if (!page.props.auth?.user) {
        return null;
    }

    const team = page.props.currentTeam as { slug: string } | null;

    return team ? dashboard(team.slug).url : '/';
});
</script>

<template>
    <div
        class="theme-marketing min-h-screen bg-background text-foreground antialiased selection:bg-primary/20"
    >
        <a
            href="#contenu"
            class="sr-only z-[60] rounded-full bg-primary px-4 py-2 font-bold text-primary-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3"
            >Aller au contenu</a
        >

        <header
            class="sticky top-0 z-50 border-b bg-background/85 px-4 backdrop-blur-md supports-[backdrop-filter]:bg-background/75 sm:px-6"
        >
            <nav
                class="flex h-16 w-full items-center justify-between gap-6"
                aria-label="Navigation principale"
            >
                <Link href="/" class="flex items-center gap-2.5">
                    <!-- <span
                        class="flex size-9 items-center justify-center rounded-xl bg-primary text-white"
                    >
                        <AppLogoIcon class="size-5" />
                    </span> -->

                    <span class="text-lg font-extrabold text-heading">
                        Callcenter
                    </span>
                </Link>

                <div class="hidden h-full items-center gap-7 lg:flex">
                    <Link
                        v-for="link in links"
                        :key="link.title"
                        :href="link.href"
                        :aria-current="
                            isCurrentOrParentUrl(link.href) ? 'page' : undefined
                        "
                        class="relative flex h-full items-center text-[15px] font-semibold transition-colors after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:rounded-full after:transition-colors hover:text-heading"
                        :class="
                            isCurrentOrParentUrl(link.href)
                                ? 'text-heading after:bg-heading'
                                : 'text-muted-foreground after:bg-transparent'
                        "
                    >
                        {{ link.title }}
                    </Link>
                </div>

                <div class="ml-auto hidden items-center gap-2 lg:flex">
                    <Button v-if="appUrl" as-child>
                        <Link :href="appUrl">Ouvrir le tableau de bord</Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost">
                            <Link :href="login().url">Se connecter</Link>
                        </Button>
                        <Button as-child>
                            <Link :href="register().url">Essai gratuit</Link>
                        </Button>
                    </template>
                </div>

                <Sheet v-model:open="open">
                    <SheetTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="lg:hidden"
                            aria-label="Ouvrir le menu"
                        >
                            <Menu class="size-5" />
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="right" class="theme-marketing w-72">
                        <SheetHeader>
                            <SheetTitle>Menu</SheetTitle>
                        </SheetHeader>
                        <nav
                            class="flex flex-col gap-1 px-4"
                            aria-label="Menu mobile"
                        >
                            <Link
                                v-for="link in links"
                                :key="link.title"
                                :href="link.href"
                                class="rounded-lg px-3 py-2.5 font-semibold hover:bg-accent"
                                :class="
                                    isCurrentOrParentUrl(link.href)
                                        ? 'bg-accent text-heading'
                                        : ''
                                "
                                @click="open = false"
                                >{{ link.title }}</Link
                            >
                            <Separator class="my-3" />
                            <Button v-if="appUrl" as-child>
                                <Link :href="appUrl" @click="open = false"
                                    >Ouvrir le tableau de bord</Link
                                >
                            </Button>
                            <div v-else class="grid gap-2">
                                <Button as-child variant="outline">
                                    <Link
                                        :href="login().url"
                                        @click="open = false"
                                        >Se connecter</Link
                                    >
                                </Button>
                                <Button as-child>
                                    <Link
                                        :href="register().url"
                                        @click="open = false"
                                        >Essai gratuit</Link
                                    >
                                </Button>
                            </div>
                        </nav>
                    </SheetContent>
                </Sheet>
            </nav>
        </header>

        <main id="contenu">
            <slot />
        </main>

        <!-- Closing call to action, shared by every page except Contact -->
        <section v-if="!isCurrentUrl('/contact')" class="px-4 pb-20 sm:px-6">
            <div
                class="relative mx-auto max-w-7xl overflow-hidden rounded-[28px] bg-primary px-6 py-14 text-white sm:px-14 sm:py-16"
            >
                <div
                    class="relative flex flex-col items-start justify-between gap-8 lg:flex-row lg:items-center"
                >
                    <div class="max-w-2xl">
                        <h2
                            class="text-3xl leading-tight font-extrabold !text-white sm:text-4xl"
                        >
                            Vos agents au téléphone dès aujourd'hui.
                        </h2>
                        <p class="mt-3 text-lg text-white/85">
                            Créez votre équipe, invitez vos agents : leurs
                            postes et le softphone sont prêts en quelques
                            minutes.
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-3">
                        <Button
                            as-child
                            size="lg"
                            class="bg-white text-[rgb(19_19_19)] hover:bg-white/90"
                        >
                            <Link :href="register().url">
                                Démarrer l'essai gratuit
                                <ArrowRight />
                            </Link>
                        </Button>
                        <Button
                            as-child
                            size="lg"
                            variant="ghost"
                            class="text-white ring-2 ring-white/60 hover:bg-white/10 hover:text-white"
                        >
                            <Link href="/contact">Parler à l'équipe</Link>
                        </Button>
                    </div>
                </div>
            </div>
        </section>

        <footer class="border-t bg-[var(--mk-layer)] px-4 sm:px-6">
            <div
                class="mx-auto grid max-w-7xl gap-10 py-14 sm:grid-cols-2 md:grid-cols-[1.4fr_1fr_1fr_1fr_1fr]"
            >
                <div>
                    <div class="flex items-center gap-2.5">
                        <span
                            class="flex size-8 items-center justify-center rounded-lg bg-primary text-white"
                        >
                            <AppLogoIcon class="size-4" />
                        </span>
                        <span class="font-extrabold text-heading"
                            >Callcenter</span
                        >
                    </div>
                    <p
                        class="mt-4 max-w-sm text-sm leading-relaxed text-muted-foreground"
                    >
                        Le centre d'appels complet dans le navigateur :
                        softphone, files et SVI, campagnes, supervision et
                        réunions vidéo. Conçu à Bamako par mbayelab.
                    </p>
                </div>
                <div v-for="column in footer" :key="column.title">
                    <h2 class="text-sm font-bold">{{ column.title }}</h2>
                    <ul class="mt-4 space-y-2.5 text-sm text-muted-foreground">
                        <li v-for="link in column.links" :key="link.title">
                            <Link
                                :href="link.href"
                                class="transition-colors hover:text-heading"
                                >{{ link.title }}</Link
                            >
                        </li>
                    </ul>
                </div>
            </div>
            <Separator class="mx-auto max-w-7xl" />
            <div
                class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-3 py-6 text-xs text-muted-foreground"
            >
                <p>
                    © {{ new Date().getFullYear() }} mbayelab · Callcenter. Tous
                    droits réservés.
                </p>
                <nav class="flex flex-wrap gap-x-5 gap-y-2" aria-label="Légal">
                    <Link
                        v-for="link in legalLinks"
                        :key="link.href"
                        :href="link.href"
                        class="transition-colors hover:text-heading"
                        >{{ link.title }}</Link
                    >
                </nav>
            </div>
        </footer>
    </div>
</template>
