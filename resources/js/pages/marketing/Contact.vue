<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Mail, MapPin, Phone } from '@lucide/vue';
import { toneClasses } from '@/data/marketing';
import type { Tone } from '@/data/marketing';
import InputError from '@/components/InputError.vue';
import SelectField from '@/components/SelectField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { vReveal } from '@/lib/reveal';

defineProps<{
    status?: string;
}>();

const form = useForm({
    name: '',
    email: '',
    company: '',
    team_size: '',
    message: '',
});

const teamSizes = ['1–5', '6–15', '16–50', '50+'].map((size) => ({
    value: size,
    label: `${size} agents`,
}));

const contacts: {
    icon: typeof Mail;
    label: string;
    value: string;
    tone: Tone;
}[] = [
    {
        icon: Mail,
        label: 'E-mail',
        value: 'contact@mbayelab.com',
        tone: 'blue',
    },
    {
        icon: Phone,
        label: 'Téléphone',
        value: '+223 74 58 43 02',
        tone: 'seafoam',
    },
    { icon: MapPin, label: 'Bureau', value: 'Bamako, Mali', tone: 'orange' },
];

const next = [
    'Nous étudions votre équipe et votre installation actuelle.',
    'Nous vous rappelons pour une démonstration sur vos cas réels.',
    'Votre équipe démarre son essai, accompagnée par nos soins.',
];

function submit(): void {
    form.post('/contact', { preserveScroll: true });
}
</script>

<template>
    <Head title="Contact — Callcenter" />

    <section class="px-4 py-14 sm:px-6 md:py-20">
        <div
            class="mx-auto grid max-w-7xl gap-12 lg:grid-cols-[1fr_1.15fr] lg:gap-16"
        >
            <div>
                <p
                    class="mk-pop text-sm font-bold tracking-wide text-primary uppercase"
                >
                    Contact
                </p>
                <h1
                    class="mk-pop mt-3 text-[2.5rem] leading-[1.05] font-extrabold sm:text-6xl"
                >
                    Parlons de <span class="text-primary">vos appels.</span>
                </h1>
                <p
                    class="mk-pop mt-6 max-w-md text-lg leading-relaxed text-muted-foreground [animation-delay:80ms]"
                >
                    Décrivez-nous votre équipe et votre installation actuelle.
                    Nous vous répondons avec un plan, en général sous un jour
                    ouvré.
                </p>

                <ul class="mt-10 grid gap-3 sm:grid-cols-3 lg:grid-cols-1">
                    <li
                        v-for="(item, index) in contacts"
                        :key="item.label"
                        v-reveal="index * 80"
                        class="flex items-center gap-4 rounded-2xl border bg-card p-4"
                    >
                        <span
                            class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                            :class="toneClasses[item.tone]"
                        >
                            <component :is="item.icon" class="size-5" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-heading">
                                {{ item.label }}
                            </p>
                            <p class="truncate text-sm text-muted-foreground">
                                {{ item.value }}
                            </p>
                        </div>
                    </li>
                </ul>

                <div v-reveal="120" class="mt-10">
                    <h2 class="text-lg font-extrabold">Et ensuite ?</h2>
                    <ol class="mt-4 space-y-4">
                        <li
                            v-for="(item, index) in next"
                            :key="item"
                            class="flex gap-4"
                        >
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-white"
                                >{{ index + 1 }}</span
                            >
                            <p class="pt-0.5 text-[15px] text-muted-foreground">
                                {{ item }}
                            </p>
                        </li>
                    </ol>
                </div>
            </div>

            <div
                v-reveal="100"
                class="mk-stage self-start rounded-[28px] p-3 sm:p-6"
            >
                <div class="mk-mock p-6 sm:p-8">
                    <div
                        v-if="status"
                        class="mb-6 flex items-start gap-3 rounded-xl border-l-4 border-positive bg-positive-subtle p-4 text-sm text-positive-strong"
                        role="status"
                    >
                        <Check class="mt-0.5 size-4 shrink-0" />
                        <p>{{ status }}</p>
                    </div>

                    <h2 class="text-xl font-extrabold">Écrivez-nous</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Tous les champs sont obligatoires sauf mention
                        contraire.
                    </p>

                    <form class="mt-6 space-y-5" @submit.prevent="submit">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="name">Nom complet</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    required
                                    autocomplete="name"
                                />
                                <InputError :message="form.errors.name" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="email">E-mail professionnel</Label>
                                <Input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    required
                                    autocomplete="email"
                                />
                                <InputError :message="form.errors.email" />
                            </div>
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="company"
                                    >Entreprise
                                    <span class="text-muted-foreground"
                                        >(facultatif)</span
                                    ></Label
                                >
                                <Input
                                    id="company"
                                    v-model="form.company"
                                    autocomplete="organization"
                                />
                                <InputError :message="form.errors.company" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="team_size"
                                    >Taille de l'équipe
                                    <span class="text-muted-foreground"
                                        >(facultatif)</span
                                    ></Label
                                >
                                <SelectField
                                    id="team_size"
                                    v-model="form.team_size"
                                    :options="teamSizes"
                                    placeholder="Sélectionner…"
                                />
                                <InputError :message="form.errors.team_size" />
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="message"
                                >Comment pouvons-nous vous aider ?</Label
                            >
                            <Textarea
                                id="message"
                                v-model="form.message"
                                rows="5"
                                required
                            />
                            <InputError :message="form.errors.message" />
                        </div>

                        <Button
                            :disabled="form.processing"
                            size="lg"
                            class="w-full"
                            >Envoyer le message</Button
                        >
                        <p class="text-center text-xs text-muted-foreground">
                            Protégé contre le spam. Nous ne partageons jamais
                            vos informations.
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </section>
</template>
