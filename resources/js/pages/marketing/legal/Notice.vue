<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Fill from '@/components/legal/Fill.vue';
import LegalDocument from '@/components/legal/LegalDocument.vue';
import type { LegalInfo } from '@/components/legal/types';

/** Mentions légales — DRAFT to be reviewed by a lawyer before publication. */
defineProps<{ legal: LegalInfo }>();

const sections = [
    { id: 'editeur', title: 'Éditeur' },
    { id: 'publication', title: 'Directeur de la publication' },
    { id: 'hebergement', title: 'Hébergement' },
    { id: 'propriete', title: 'Propriété intellectuelle' },
    { id: 'responsabilite', title: 'Responsabilité' },
    { id: 'donnees', title: 'Données personnelles' },
    { id: 'droit', title: 'Droit applicable' },
];
</script>

<template>
    <LegalDocument
        title="Mentions légales"
        intro="Les informations légales sur l'éditeur du site et du service."
        :updated-at="legal.updated_at"
        :sections="sections"
    >
        <h2 id="editeur">Éditeur</h2>
        <p>
            Le site <a :href="legal.appUrl">{{ legal.appUrl }}</a> et le service
            {{ legal.appName }} sont édités par :
        </p>
        <ul>
            <li>
                <strong>{{ legal.company.name }}</strong
                >, <Fill :value="legal.company.form" /> au capital de
                <Fill :value="legal.company.capital" />
            </li>
            <li>Siège : {{ legal.company.address }}</li>
            <li>RCCM : <Fill :value="legal.company.rccm" /></li>
            <li>NIF : <Fill :value="legal.company.nif" /></li>
            <li>Téléphone : <Fill :value="legal.company.phone" /></li>
            <li>
                E-mail :
                <a :href="`mailto:${legal.company.email}`"
                    ><Fill :value="legal.company.email"
                /></a>
            </li>
        </ul>

        <h2 id="publication">Directeur de la publication</h2>
        <p><Fill :value="legal.company.publication_director" /></p>

        <h2 id="hebergement">Hébergement</h2>
        <p>
            {{ legal.hosting.name }} — {{ legal.hosting.address }}.
            {{ legal.hosting.description }}.
        </p>

        <h2 id="propriete">Propriété intellectuelle</h2>
        <p>
            Le site, l'application, leurs textes, interfaces, logos et
            illustrations sont la propriété de {{ legal.company.name }} ou de
            ses partenaires. Toute reproduction ou réutilisation sans
            autorisation écrite est interdite.
        </p>
        <p>
            Les contenus que nos clients déposent dans le service (contacts,
            scripts, sons, enregistrements, notes) restent leur propriété.
        </p>

        <h2 id="responsabilite">Responsabilité</h2>
        <p>
            Nous veillons à l'exactitude des informations publiées sur ce site,
            sans pouvoir garantir l'absence d'erreur. Les tarifs et
            fonctionnalités à jour sont ceux affichés sur la page
            <Link href="/pricing">Tarifs</Link> et dans l'application. Les liens
            vers des sites tiers n'engagent pas notre responsabilité quant à
            leur contenu.
        </p>
        <p>
            Les conditions d'utilisation du service, les engagements de
            disponibilité et la responsabilité de chaque partie sont précisés
            dans les conditions générales signées avec chaque client
            <Fill :value="null" />.
        </p>

        <h2 id="donnees">Données personnelles</h2>
        <p>
            Le traitement des données personnelles est décrit dans notre
            <Link href="/confidentialite">politique de confidentialité</Link>,
            et les mesures de sécurité dans notre
            <Link href="/confiance">centre de confiance</Link>. Déclaration APDP
            : <Fill :value="legal.apdp_reference" />.
        </p>

        <h2 id="droit">Droit applicable</h2>
        <p>
            Le présent site est soumis au droit malien. En cas de litige, et à
            défaut d'accord amiable, les tribunaux compétents de Bamako sont
            seuls compétents.
        </p>
    </LegalDocument>
</template>
