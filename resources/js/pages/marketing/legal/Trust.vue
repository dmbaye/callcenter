<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    Building2,
    CreditCard,
    KeyRound,
    Lock,
    MapPin,
    Mic,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import Fill from '@/components/legal/Fill.vue';
import LegalDocument from '@/components/legal/LegalDocument.vue';
import type { LegalInfo, LegalPlan } from '@/components/legal/types';

/**
 * Centre de confiance — only states what the application does today
 * (see the code references in comments). Items that depend on the
 * operations (backups, availability) stay « à compléter » until set.
 */
defineProps<{ legal: LegalInfo; plans: LegalPlan[] }>();

const sections = [
    { id: 'essentiel', title: "L'essentiel" },
    { id: 'hebergement', title: 'Hébergement' },
    { id: 'isolation', title: 'Séparation des clients' },
    { id: 'acces', title: 'Contrôle des accès' },
    { id: 'chiffrement', title: 'Chiffrement' },
    { id: 'enregistrements', title: 'Enregistrements' },
    { id: 'paiements', title: 'Paiements' },
    { id: 'exploitation', title: 'Exploitation' },
    { id: 'sous-traitants', title: 'Sous-traitants' },
    { id: 'conformite', title: 'Conformité' },
    { id: 'vulnerabilite', title: 'Signaler une faille' },
];

const highlights: { icon: Component; title: string; text: string }[] = [
    {
        icon: MapPin,
        title: 'Données au Mali',
        text: 'Application, téléphonie et vidéo hébergées sur nos serveurs au Mali.',
    },
    {
        icon: Building2,
        title: 'Clients séparés',
        text: 'Chaque organisation a ses propres postes, files, numéros et réunions, isolés des autres.',
    },
    {
        icon: KeyRound,
        title: 'Accès maîtrisés',
        text: 'Rôles, double authentification et clés d’accès (passkeys).',
    },
    {
        icon: Lock,
        title: 'Échanges chiffrés',
        text: 'Navigateur, softphone et réunions vidéo passent par des connexions chiffrées.',
    },
    {
        icon: Mic,
        title: 'Enregistrements limités',
        text: 'Activés file par file, supprimés automatiquement à l’échéance.',
    },
    {
        icon: CreditCard,
        title: 'Aucune donnée de carte',
        text: 'Le paiement se fait chez notre prestataire : nous ne voyons ni carte ni code.',
    },
];
</script>

<template>
    <LegalDocument
        title="Centre de confiance"
        intro="Comment nous protégeons vos appels, vos enregistrements et les données de vos clients."
        :updated-at="legal.updated_at"
        :sections="sections"
    >
        <h2 id="essentiel">L'essentiel</h2>
        <div class="not-prose grid gap-3 sm:grid-cols-2">
            <div
                v-for="item in highlights"
                :key="item.title"
                class="flex gap-3 rounded-xl border p-4"
            >
                <component
                    :is="item.icon"
                    class="mt-0.5 size-5 shrink-0 text-primary"
                />
                <div>
                    <div class="font-semibold">{{ item.title }}</div>
                    <div class="text-sm text-muted-foreground">
                        {{ item.text }}
                    </div>
                </div>
            </div>
        </div>

        <h2 id="hebergement">Hébergement</h2>
        <p>
            Les serveurs de l'application, du système téléphonique (Asterisk) et
            des réunions vidéo (serveur LiveKit auto-hébergé) sont installés au
            Mali, chez {{ legal.hosting.name }} ({{ legal.hosting.address }}).
            Les réunions vidéo ne passent pas par un service de visioconférence
            étranger.
        </p>

        <h2 id="isolation">Séparation des clients</h2>
        <ul>
            <li>
                Chaque organisation reçoit un identifiant technique unique et
                définitif. Ses postes, files d'attente, menus vocaux et numéros
                vivent dans son propre espace du système téléphonique : un poste
                ne peut appeler que les postes de sa propre organisation.
            </li>
            <li>
                Chaque appel est rattaché à son organisation dans le journal des
                appels ; les statistiques, l'historique et les enregistrements
                ne montrent que ceux de l'organisation.
            </li>
            <li>
                Les salles de réunion sont propres à chaque organisation et ne
                s'ouvrent qu'aux membres connectés, avec une autorisation
                personnelle délivrée à chaque entrée.
            </li>
        </ul>

        <h2 id="acces">Contrôle des accès</h2>
        <ul>
            <li>
                Quatre rôles (membre, superviseur, administrateur, propriétaire)
                : chacun ne voit que ce qui le concerne. Un agent voit ses
                propres appels ; l'écoute et le chuchotement sont réservés aux
                superviseurs.
            </li>
            <li>
                Double authentification par application (TOTP) et clés d'accès
                (passkeys) pour se connecter sans mot de passe.
            </li>
            <li>
                Mots de passe stockés uniquement sous forme chiffrée
                irréversible ; en production, ils doivent être longs, variés, et
                ne pas figurer dans les listes de mots de passe compromis
                connues.
            </li>
            <li>
                Les opérations sensibles (connexion, paiement, import de
                contacts, appels de campagne) sont limitées en fréquence contre
                les abus.
            </li>
            <li>
                Les photos de profil ne sont visibles que par les membres des
                mêmes organisations ; leurs métadonnées (dont la position GPS
                des photos de téléphone) sont supprimées à l'envoi.
            </li>
        </ul>

        <h2 id="chiffrement">Chiffrement</h2>
        <ul>
            <li>
                L'application est servie en HTTPS. Le softphone se connecte au
                serveur téléphonique par une connexion sécurisée (WebSocket
                chiffrée) et la voix est chiffrée entre le navigateur et le
                serveur (WebRTC, DTLS-SRTP).
            </li>
            <li>
                Les réunions vidéo utilisent le même chiffrement WebRTC entre
                chaque navigateur et notre serveur vidéo.
            </li>
            <li>
                Les appels vers ou depuis le réseau téléphonique public passent
                par l'opérateur (Orange Mali), selon les standards de la
                téléphonie classique.
            </li>
        </ul>

        <h2 id="enregistrements">Enregistrements et conservation</h2>
        <p>
            Les appels ne sont enregistrés que si un administrateur l'active sur
            une file, et si l'offre l'inclut. Un message peut prévenir
            l'appelant avant l'enregistrement — nous le recommandons, c'est en
            général une obligation légale. Les enregistrements sont supprimés
            automatiquement chaque nuit une fois la durée de l'offre écoulée :
        </p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Offre</th>
                        <th>Enregistrements d'appels</th>
                        <th>Historique consultable</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="plan in plans" :key="plan.name">
                        <td>{{ plan.name }}</td>
                        <td>
                            {{
                                plan.recordingDays
                                    ? `${plan.recordingDays} jours`
                                    : 'Non inclus'
                            }}
                        </td>
                        <td>
                            {{
                                plan.historyMonths === null
                                    ? 'Illimité'
                                    : `${plan.historyMonths} mois`
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p>
            <Video class="mr-1 inline size-4 text-primary" />Les réunions vidéo
            ne sont jamais enregistrées sans action d'un organisateur, et
            seulement sur l'offre qui l'inclut.
        </p>

        <h2 id="paiements">Paiements</h2>
        <p>
            Les paiements (Mobile Money, carte) sont saisis sur la page
            sécurisée de notre prestataire. Nous ne recevons ni numéro de carte
            ni code. Chaque paiement est vérifié directement auprès du
            prestataire avant d'activer l'abonnement : une notification
            falsifiée ou un montant inférieur au prix sont refusés.
        </p>

        <h2 id="exploitation">Exploitation</h2>
        <ul>
            <li>Sauvegardes : <Fill :value="null" /></li>
            <li>Engagement de disponibilité : <Fill :value="null" /></li>
            <li>
                Accès de notre équipe aux serveurs et aux données :
                <Fill :value="null" />
            </li>
            <li>
                Procédure en cas d'incident de sécurité et information des
                clients : <Fill :value="null" />
            </li>
        </ul>

        <h2 id="sous-traitants">Sous-traitants</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Prestataire</th>
                        <th>Mission</th>
                        <th>Pays</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(s, i) in legal.subprocessors" :key="i">
                        <td><Fill :value="s.name" /></td>
                        <td>{{ s.role }}</td>
                        <td><Fill :value="s.country" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 id="conformite">Conformité</h2>
        <p>
            Nous appliquons la loi malienne n° 2013-015 du 21 mai 2013 sur la
            protection des données à caractère personnel. Déclaration auprès de
            l'APDP : <Fill :value="legal.apdp_reference" />. Pour les appels,
            enregistrements et contacts, chaque organisation cliente reste
            responsable de ses traitements ; nous agissons pour son compte (voir
            la
            <Link href="/confidentialite">politique de confidentialité</Link>).
        </p>

        <h2 id="vulnerabilite">Signaler une faille</h2>
        <p>
            Vous pensez avoir trouvé une faille de sécurité ? Écrivez à
            <a :href="`mailto:${legal.security_email}`"
                ><Fill :value="legal.security_email"
            /></a>
            avec une description et les étapes pour la reproduire. Merci de ne
            pas accéder aux données d'autrui ni de perturber le service, et de
            nous laisser le temps de corriger avant toute publication. Nous
            accusons réception sous 5 jours ouvrés.
        </p>
    </LegalDocument>
</template>
