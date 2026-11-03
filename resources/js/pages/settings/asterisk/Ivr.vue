<script setup lang="ts">
import { t } from '@/lib/i18n';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Phone, Pencil, Plus, Replace, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import DestinationPicker from '@/components/routing/DestinationPicker.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { index } from '@/routes/asterisk/ivr';
import {
    destroy as destroyMenu,
    store as storeMenu,
    update as updateMenu,
} from '@/routes/asterisk/ivr/menus';
import {
    claim as claimNumber,
    destroy as destroyNumber,
    replace as replaceNumber,
    store as storeNumber,
    update as updateNumber,
} from '@/routes/asterisk/ivr/numbers';

type MenuOption = {
    digit: string;
    label: string | null;
    type: string | null;
    target: string | null;
};

type Menu = {
    id: number;
    name: string;
    extension: string;
    greeting_sound: string;
    invalid_sound: string | null;
    timeout: number;
    max_retries: number;
    options: MenuOption[];
    fallback_type: string | null;
    fallback_target: string | null;
};

type InboundNumber = {
    id: number;
    number: string;
    source: 'pool' | 'own';
    label: string | null;
    destination_type: string;
    destination_target: string | null;
};

const props = defineProps<{
    menus: Menu[];
    numbers: InboundNumber[];
    pool: { available: number; hasPoolNumber: boolean };
    queues: { id: number; name: string }[];
    destinations: { value: string; label: string }[];
    inboundContext: string;
    teamContext: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Numéros et SVI', href: index() }],
    },
});

const ivrOptions = computed(() =>
    props.menus.map((m) => ({ id: m.id, name: m.name })),
);

function describe(type: string | null, target: string | null): string {
    if (type === 'hangup') {
        return 'Pas encore routé (créez une file)';
    }

    const label =
        props.destinations.find((d) => d.value === type)?.label ?? type ?? '—';

    if (type === 'queue' || type === 'callback') {
        return `${label} : ${props.queues.find((q) => String(q.id) === target)?.name ?? '?'}`;
    }

    if (type === 'ivr') {
        return `${label} : ${props.menus.find((m) => String(m.id) === target)?.name ?? '?'}`;
    }

    return target ? `${label} : ${target}` : label;
}

/* Numbers */
const editingNumber = ref<number | null>(null);
const editingPoolNumber = computed(
    () =>
        props.numbers.find((n) => n.id === editingNumber.value)?.source ===
        'pool',
);

/* Platform pool: get a number, or swap it for the team's own number. */
const claimForm = useForm({});

function claim() {
    claimForm.post(claimNumber().url, { preserveScroll: true });
}

const replacing = ref<InboundNumber | null>(null);
const replaceForm = useForm({ own_number: '' });

function startReplace(n: InboundNumber) {
    resetNumber();
    replacing.value = n;
    replaceForm.reset();
    replaceForm.clearErrors();
}

function cancelReplace() {
    replacing.value = null;
    replaceForm.reset();
    replaceForm.clearErrors();
}

function saveReplace() {
    if (!replacing.value) {
        return;
    }

    replaceForm.post(replaceNumber(replacing.value.id).url, {
        preserveScroll: true,
        onSuccess: cancelReplace,
    });
}
const numberForm = useForm<{
    number: string;
    label: string;
    destination_type: string | null;
    destination_target: string | null;
}>({
    number: '',
    label: '',
    destination_type: 'queue',
    destination_target: null,
});

function editNumber(n: InboundNumber) {
    cancelReplace();
    editingNumber.value = n.id;
    numberForm.number = n.number;
    numberForm.label = n.label ?? '';
    numberForm.destination_type =
        n.destination_type === 'hangup' ? 'queue' : n.destination_type;
    numberForm.destination_target =
        n.destination_type === 'hangup' ? null : n.destination_target;
}

function resetNumber() {
    editingNumber.value = null;
    numberForm.reset();
    numberForm.clearErrors();
}

function saveNumber() {
    const options = { preserveScroll: true, onSuccess: resetNumber };

    if (editingNumber.value) {
        numberForm.put(updateNumber(editingNumber.value).url, options);
    } else {
        numberForm.post(storeNumber().url, options);
    }
}

function removeNumber(n: InboundNumber) {
    const message =
        n.source === 'pool'
            ? `Rendre le numéro ${n.number} ? Il retourne au stock de la plateforme et pourra être attribué à un autre client.`
            : `Retirer le numéro ${n.number} ? Ses appels ne seront plus routés.`;

    if (confirm(message)) {
        router.delete(destroyNumber(n.id).url, { preserveScroll: true });
    }
}

/* Menus */
const editingMenu = ref<number | null>(null);
const emptyMenu = () => ({
    name: '',
    greeting_sound: '',
    invalid_sound: '',
    timeout: 5,
    max_retries: 3,
    options: [
        { digit: '1', label: 'Ventes', type: 'queue', target: null },
        { digit: '2', label: 'Support', type: 'queue', target: null },
    ] as MenuOption[],
    fallback_type: 'queue' as string | null,
    fallback_target: null as string | null,
});
const menuForm = useForm(emptyMenu());
const menuErrors = computed(() => menuForm.errors as Record<string, string>);

function editMenu(m: Menu) {
    editingMenu.value = m.id;
    menuForm.name = m.name;
    menuForm.greeting_sound = m.greeting_sound;
    menuForm.invalid_sound = m.invalid_sound ?? '';
    menuForm.timeout = m.timeout;
    menuForm.max_retries = m.max_retries;
    menuForm.options = m.options.map((o) => ({ ...o }));
    menuForm.fallback_type = m.fallback_type;
    menuForm.fallback_target = m.fallback_target;
}

function resetMenu() {
    editingMenu.value = null;
    menuForm.defaults(emptyMenu());
    menuForm.reset();
    menuForm.clearErrors();
}

function addOption() {
    const used = menuForm.options.map((o) => o.digit);
    const digit =
        ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '*'].find(
            (d) => !used.includes(d),
        ) ?? '';
    menuForm.options.push({ digit, label: '', type: 'queue', target: null });
}

function saveMenu() {
    const options = { preserveScroll: true, onSuccess: resetMenu };

    if (editingMenu.value) {
        menuForm.put(updateMenu(editingMenu.value).url, options);
    } else {
        menuForm.post(storeMenu().url, options);
    }
}

function removeMenu(m: Menu) {
    if (
        confirm(
            `Supprimer le menu « ${m.name} » ? Ce qui y renvoyait raccrochera.`,
        )
    ) {
        router.delete(destroyMenu(m.id).url, { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="t('Numéros et SVI')" />

    <div class="space-y-10">
        <section class="space-y-4">
            <Heading
                variant="small"
                :title="t('Numéros entrants')"
                :description="`Votre équipe reçoit automatiquement un numéro de la plateforme à la création de sa première file. Vous pouvez le remplacer par votre propre numéro (porté ou renvoyé vers notre opérateur). Le trunk de l'opérateur doit utiliser le contexte ${inboundContext}.`"
            />

            <div
                v-if="!pool.hasPoolNumber"
                class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed p-4"
            >
                <Phone class="size-5 text-muted-foreground" />
                <div class="min-w-0 flex-1 text-sm">
                    <div class="font-medium">
                        {{ t('Numéro de la plateforme') }}
                    </div>
                    <div class="text-muted-foreground">
                        {{
                            pool.available > 0
                                ? t(
                                      'Obtenez un numéro tout de suite, sans rien saisir. Il sera routé vers votre première file.',
                                  )
                                : t(
                                      'Aucun numéro disponible pour le moment. Contactez le support ou ajoutez votre propre numéro ci-dessous.',
                                  )
                        }}
                    </div>
                    <InputError
                        :message="
                            (claimForm.errors as Record<string, string>).pool
                        "
                    />
                </div>
                <Button
                    :disabled="pool.available === 0 || claimForm.processing"
                    @click="claim"
                    ><Plus /> {{ t('Obtenir un numéro') }}</Button
                >
            </div>

            <form
                v-if="replacing"
                class="grid items-start gap-3 rounded-lg border border-primary/40 bg-primary/5 p-4 md:grid-cols-[1fr_14rem_auto]"
                @submit.prevent="saveReplace"
            >
                <div class="text-sm">
                    <div class="font-medium">
                        {{
                            t('Remplacer :p0 par votre numéro', {
                                p0: replacing.number,
                            })
                        }}
                    </div>
                    <div class="text-muted-foreground">
                        {{
                            t(
                                'Même libellé, même routage. Le numéro de la plateforme est rendu. Votre numéro doit être porté ou renvoyé vers notre opérateur pour sonner ici.',
                            )
                        }}
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="own">{{ t('Votre numéro') }}</Label>
                    <Input
                        id="own"
                        v-model="replaceForm.own_number"
                        placeholder="22320201010"
                        required
                    />
                    <InputError :message="replaceForm.errors.own_number" />
                </div>
                <div class="flex gap-2 pt-7">
                    <Button type="submit" :disabled="replaceForm.processing">{{
                        t('Remplacer')
                    }}</Button>
                    <Button
                        type="button"
                        variant="outline"
                        @click="cancelReplace"
                        >{{ t('Annuler') }}</Button
                    >
                </div>
            </form>

            <form
                class="grid items-start gap-3 rounded-lg border p-4 md:grid-cols-[12rem_12rem_1fr_auto]"
                @submit.prevent="saveNumber"
            >
                <div class="grid gap-2">
                    <Label for="did">{{ t('Numéro') }}</Label>
                    <Input
                        id="did"
                        v-model="numberForm.number"
                        :placeholder="t('Votre numéro, ex. 22320201010')"
                        :disabled="editingPoolNumber"
                        :required="!editingPoolNumber"
                    />
                    <InputError :message="numberForm.errors.number" />
                </div>
                <div class="grid gap-2">
                    <Label for="dlabel">{{ t('Libellé') }}</Label>
                    <Input
                        id="dlabel"
                        v-model="numberForm.label"
                        placeholder="Standard"
                    />
                </div>
                <div class="grid gap-2">
                    <Label for="ddest">{{ t('Destination') }}</Label>
                    <DestinationPicker
                        id="ddest"
                        v-model:type="numberForm.destination_type"
                        v-model:target="numberForm.destination_target"
                        :destinations="
                            destinations.filter((d) => d.value !== 'hangup')
                        "
                        :queues="queues"
                        :ivr-menus="ivrOptions"
                    />
                    <InputError
                        :message="
                            numberForm.errors.destination_target ??
                            numberForm.errors.destination_type
                        "
                    />
                </div>
                <div class="flex gap-2 pt-7">
                    <Button type="submit" :disabled="numberForm.processing">{{
                        editingNumber ? 'Enregistrer' : 'Ajouter'
                    }}</Button>
                    <Button
                        v-if="editingNumber"
                        type="button"
                        variant="outline"
                        @click="resetNumber"
                        >{{ t('Annuler') }}</Button
                    >
                </div>
            </form>

            <div class="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{{ t('Numéro') }}</TableHead>
                            <TableHead>{{ t('Libellé') }}</TableHead>
                            <TableHead>{{ t('Destination') }}</TableHead>
                            <TableHead class="w-36" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="n in numbers" :key="n.id">
                            <TableCell class="font-mono text-sm">
                                {{ n.number }}
                                <Badge
                                    :variant="
                                        n.source === 'pool'
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                    class="ml-2 font-sans"
                                    >{{
                                        n.source === 'pool'
                                            ? 'Plateforme'
                                            : t('Votre numéro')
                                    }}</Badge
                                >
                            </TableCell>
                            <TableCell>{{ n.label ?? '—' }}</TableCell>
                            <TableCell class="text-sm">{{
                                describe(
                                    n.destination_type,
                                    n.destination_target,
                                )
                            }}</TableCell>
                            <TableCell class="text-right">
                                <Button
                                    v-if="n.source === 'pool'"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Remplacer ${n.number} par votre numéro`"
                                    :title="t('Remplacer par votre numéro')"
                                    @click="startReplace(n)"
                                    ><Replace
                                /></Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Modifier ${n.number}`"
                                    @click="editNumber(n)"
                                    ><Pencil
                                /></Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Retirer ${n.number}`"
                                    @click="removeNumber(n)"
                                    ><Trash2
                                /></Button>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
                <p
                    v-if="numbers.length === 0"
                    class="p-4 text-center text-sm text-muted-foreground"
                >
                    {{ t("Aucun numéro pour l'instant.") }}
                </p>
            </div>
        </section>

        <section class="space-y-4">
            <Heading
                variant="small"
                :title="t('Menus vocaux (SVI)')"
                :description="
                    t(
                        '« Pour les ventes, tapez 1… ». Sans réponse ou touche invalide, le message est répété, puis l\'appel part vers la destination par défaut.',
                    )
                "
            />

            <ul class="divide-y rounded-lg border">
                <li
                    v-for="m in menus"
                    :key="m.id"
                    class="flex flex-wrap items-center gap-3 px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ m.name }}</div>
                        <div class="text-xs text-muted-foreground">
                            <span
                                v-for="o in m.options"
                                :key="o.digit"
                                class="mr-3"
                                >{{ o.digit }} →
                                {{
                                    o.label || describe(o.type, o.target)
                                }}</span
                            >
                            {{
                                t('· défaut : :p0 ·', {
                                    p0: describe(
                                        m.fallback_type,
                                        m.fallback_target,
                                    ),
                                })
                            }}
                            <span class="font-mono"
                                >{{ teamContext }},{{ m.extension }},1</span
                            >
                        </div>
                    </div>
                    <Button size="sm" variant="outline" @click="editMenu(m)"
                        ><Pencil /> {{ t('Modifier') }}</Button
                    >
                    <Button
                        size="icon"
                        variant="ghost"
                        :aria-label="`Supprimer ${m.name}`"
                        @click="removeMenu(m)"
                        ><Trash2
                    /></Button>
                </li>
                <li
                    v-if="menus.length === 0"
                    class="px-4 py-3 text-sm text-muted-foreground"
                >
                    {{ t('Aucun menu vocal.') }}
                </li>
            </ul>

            <form
                class="space-y-4 rounded-lg border p-4"
                @submit.prevent="saveMenu"
            >
                <h3 class="font-semibold">
                    {{
                        editingMenu ? t('Modifier le menu') : t('Nouveau menu')
                    }}
                </h3>
                <div class="grid gap-4 md:grid-cols-4">
                    <div class="grid gap-2">
                        <Label for="mname">{{ t('Nom') }}</Label>
                        <Input id="mname" v-model="menuForm.name" required />
                        <InputError :message="menuForm.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="greeting">{{
                            t("Message d'accueil")
                        }}</Label>
                        <Input
                            id="greeting"
                            v-model="menuForm.greeting_sound"
                            :placeholder="t('custom/accueil')"
                            required
                        />
                        <InputError :message="menuForm.errors.greeting_sound" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="mtimeout">{{
                            t('Attente touche (s)')
                        }}</Label>
                        <Input
                            id="mtimeout"
                            v-model.number="menuForm.timeout"
                            type="number"
                            min="1"
                            max="30"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="mretries">{{ t('Répétitions') }}</Label>
                        <Input
                            id="mretries"
                            v-model.number="menuForm.max_retries"
                            type="number"
                            min="1"
                            max="5"
                        />
                    </div>
                    <div class="grid gap-2 md:col-span-2">
                        <Label for="invalid">{{
                            t('Message « touche invalide » (facultatif)')
                        }}</Label>
                        <Input
                            id="invalid"
                            v-model="menuForm.invalid_sound"
                            :placeholder="t('custom/choix-invalide')"
                        />
                    </div>
                </div>

                <div class="space-y-2">
                    <Label>{{ t('Touches') }}</Label>
                    <div
                        v-for="(o, i) in menuForm.options"
                        :key="i"
                        class="grid items-start gap-2 md:grid-cols-[4rem_10rem_1fr_auto]"
                    >
                        <select
                            v-model="o.digit"
                            class="h-9 rounded-md border bg-background px-2 text-sm"
                            :aria-label="`Touche ${i + 1}`"
                        >
                            <option
                                v-for="d in [
                                    '1',
                                    '2',
                                    '3',
                                    '4',
                                    '5',
                                    '6',
                                    '7',
                                    '8',
                                    '9',
                                    '0',
                                    '*',
                                ]"
                                :key="d"
                                :value="d"
                            >
                                {{ d }}
                            </option>
                        </select>
                        <Input
                            :model-value="o.label ?? ''"
                            :placeholder="t('Libellé')"
                            :aria-label="`Libellé touche ${o.digit}`"
                            @update:model-value="o.label = String($event)"
                        />
                        <div>
                            <DestinationPicker
                                :id="`opt-${i}`"
                                v-model:type="o.type"
                                v-model:target="o.target"
                                :destinations="destinations"
                                :queues="queues"
                                :ivr-menus="ivrOptions"
                            />
                            <InputError
                                :message="menuErrors[`options.${i}.target`]"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            :aria-label="`Supprimer la touche ${o.digit}`"
                            @click="menuForm.options.splice(i, 1)"
                            ><Trash2
                        /></Button>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="addOption"
                        ><Plus /> {{ t('Ajouter une touche') }}</Button
                    >
                </div>

                <div class="grid max-w-2xl gap-2">
                    <Label for="fallback">{{ t('Sans choix valide') }}</Label>
                    <DestinationPicker
                        id="fallback"
                        v-model:type="menuForm.fallback_type"
                        v-model:target="menuForm.fallback_target"
                        :destinations="destinations"
                        :queues="queues"
                        :ivr-menus="ivrOptions"
                    />
                    <InputError :message="menuForm.errors.fallback_target" />
                </div>

                <div class="flex gap-2">
                    <Button type="submit" :disabled="menuForm.processing">{{
                        editingMenu ? 'Enregistrer' : t('Créer le menu')
                    }}</Button>
                    <Button
                        v-if="editingMenu"
                        type="button"
                        variant="outline"
                        @click="resetMenu"
                        >{{ t('Annuler') }}</Button
                    >
                </div>
            </form>
        </section>
    </div>
</template>
