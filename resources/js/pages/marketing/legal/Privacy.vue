<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Fill from '@/components/legal/Fill.vue';
import LegalDocument from '@/components/legal/LegalDocument.vue';
import type { LegalInfo, LegalPlan } from '@/components/legal/types';

/**
 * Politique de confidentialité — DRAFT to be reviewed by a lawyer before
 * publication. It follows what the application really does (data stored,
 * retention jobs, sub-processors) and Malian law (Loi n° 2013-015).
 */
defineProps<{ legal: LegalInfo; plans: LegalPlan[] }>();

const sections = [
    { id: 'qui', title: 'Qui sommes-nous' },
    { id: 'roles', title: 'Nos deux rôles' },
    { id: 'donnees', title: 'Les données traitées' },
    { id: 'finalites', title: 'Pourquoi et sur quelle base' },
    { id: 'destinataires', title: 'Qui y a accès' },
    { id: 'localisation', title: 'Où sont les données' },
    { id: 'conservation', title: 'Combien de temps' },
    { id: 'securite', title: 'Sécurité' },
    { id: 'cookies', title: 'Cookies' },
    { id: 'droits', title: 'Vos droits' },
    { id: 'mineurs', title: 'Mineurs' },
    { id: 'modifications', title: 'Modifications' },
    { id: 'contact', title: 'Contact' },
];
</script>

<template>
    <LegalDocument
        title="Politique de confidentialité"
        intro="Comment nous collectons, utilisons et protégeons les données personnelles de nos clients, de leurs équipes et des personnes qu'ils appellent."
        :updated-at="legal.updated_at"
        :sections="sections"
    >
        <h2 id="qui">Qui sommes-nous</h2>
        <p>
            Le service {{ legal.appName }} (centre d'appels, téléphonie interne
            et réunions vidéo dans le navigateur) est édité par
            <strong>{{ legal.company.name }}</strong
            >, <Fill :value="legal.company.form" />, dont le siège est situé
            {{ legal.company.address }} (RCCM
            <Fill :value="legal.company.rccm" />, NIF
            <Fill :value="legal.company.nif" />).
        </p>
        <p>
            Les traitements sont réalisés conformément à la loi malienne n°
            2013-015 du 21 mai 2013 portant protection des données à caractère
            personnel, modifiée, et ont fait l'objet d'une déclaration auprès de
            l'Autorité de protection des données à caractère personnel (APDP)
            sous la référence
            <Fill :value="legal.apdp_reference" />.
        </p>

        <h2 id="roles">Nos deux rôles</h2>
        <p>Selon les données, nous n'avons pas le même rôle :</p>
        <ul>
            <li>
                <strong>Responsable de traitement</strong> pour les données
                nécessaires à la relation avec nos clients : comptes
                utilisateurs, profil de l'organisation, abonnement et paiements,
                messages envoyés par le formulaire de contact.
            </li>
            <li>
                <strong>Sous-traitant</strong> pour les données que nos clients
                traitent avec le service : appels, enregistrements, listes de
                contacts des campagnes, notes et qualifications, réunions.
                Chaque organisation cliente est responsable de ces traitements
                (information de ses propres clients, base légale, déclaration
                éventuelle auprès de l'APDP). Nous ne les utilisons que pour
                fournir le service, selon ses instructions.
            </li>
        </ul>

        <h2 id="donnees">Les données traitées</h2>
        <h3>Compte et profil</h3>
        <ul>
            <li>
                Nom, adresse e-mail, mot de passe (stocké uniquement sous forme
                chiffrée irréversible), clés d'accès et réglages de double
                authentification.
            </li>
            <li>
                Facultatif : photo de profil, genre, fonction, service,
                téléphone, langue de l'interface, préférences de notification.
            </li>
            <li>
                Organisation : nom, raison sociale, secteur, taille,
                coordonnées, RCCM, NIF, origine du contact et accord pour
                recevoir nos offres.
            </li>
        </ul>
        <h3>Utilisation du service</h3>
        <ul>
            <li>
                Journal des appels : numéros appelant et appelé, date, durée,
                résultat, poste et agent concernés.
            </li>
            <li>
                Enregistrements des conversations, uniquement si l'organisation
                les active sur une file et si son offre les inclut.
            </li>
            <li>
                Qualifications et notes saisies par les agents, demandes de
                rappel, notes de satisfaction tapées par les appelants.
            </li>
            <li>
                Campagnes : contacts importés par l'organisation (nom,
                téléphone, entreprise, e-mail et colonnes du fichier),
                tentatives d'appel et liste « Ne pas appeler ».
            </li>
            <li>
                Statuts des agents (prêt, pause et motif, post-appel…) et leur
                historique.
            </li>
            <li>
                Réunions vidéo : titre, participants, horaires, notes partagées
                et, si l'organisation l'active, enregistrement de la réunion. Le
                son et l'image des réunions ne sont pas conservés sans
                enregistrement.
            </li>
        </ul>
        <h3>Abonnement et paiement</h3>
        <p>
            Offre, période, montant, moyen de paiement et référence de la
            transaction. Les numéros de carte et les codes Mobile Money sont
            saisis sur la page de notre prestataire de paiement : nous ne les
            recevons jamais.
        </p>
        <h3>Données techniques</h3>
        <p>
            Adresse IP, navigateur et journaux de connexion, nécessaires au
            fonctionnement et à la sécurité du service.
        </p>

        <h2 id="finalites">Pourquoi et sur quelle base</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Finalité</th>
                        <th>Base</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            Fournir le service : comptes, appels, files,
                            campagnes, réunions, assistance
                        </td>
                        <td>Exécution du contrat</td>
                    </tr>
                    <tr>
                        <td>Facturation, comptabilité</td>
                        <td>Contrat et obligations légales</td>
                    </tr>
                    <tr>
                        <td>Sécurité, prévention des abus et de la fraude</td>
                        <td>Intérêt légitime</td>
                    </tr>
                    <tr>
                        <td>
                            Améliorer le service et adapter l'accompagnement
                            (secteur, taille de l'organisation)
                        </td>
                        <td>Intérêt légitime</td>
                    </tr>
                    <tr>
                        <td>
                            Envoyer nos nouveautés et offres par e-mail, SMS ou
                            WhatsApp
                        </td>
                        <td>
                            Consentement (case du profil de l'organisation,
                            retirable à tout moment)
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p>
            Nous ne vendons pas les données et ne les utilisons pas à des fins
            publicitaires. Nous n'utilisons pas les appels, enregistrements ou
            contacts de nos clients pour nos propres besoins.
        </p>

        <h2 id="destinataires">Qui y a accès</h2>
        <ul>
            <li>
                <strong>Au sein de l'organisation cliente</strong>, selon les
                rôles : un agent voit ses propres appels ; les superviseurs et
                administrateurs voient ceux de l'équipe ; seuls les
                administrateurs gèrent les réglages et l'abonnement.
            </li>
            <li>
                <strong>Notre équipe</strong>, uniquement pour l'assistance, la
                maintenance et la sécurité, dans la limite du nécessaire.
            </li>
            <li>
                <strong>Nos sous-traitants</strong>, chacun pour sa mission :
            </li>
        </ul>
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
        <p>
            Les autorités peuvent obtenir des données dans les cas prévus par la
            loi, sur réquisition.
        </p>

        <h2 id="localisation">Où sont les données</h2>
        <p>
            Les serveurs de l'application, de la téléphonie et des réunions
            vidéo sont hébergés au Mali, chez {{ legal.hosting.name }} ({{
                legal.hosting.address
            }}). Seules les informations nécessaires au paiement sont transmises
            à notre prestataire de paiement hors du Mali (voir le tableau
            ci-dessus), dans les conditions prévues par la loi n° 2013-015 pour
            les transferts vers un pays offrant une protection suffisante.
        </p>

        <h2 id="conservation">Combien de temps</h2>
        <ul>
            <li>
                <strong>Compte et profil</strong> : tant que le compte existe.
                La suppression du compte (Paramètres → Profil) efface le compte
                et sa photo.
            </li>
            <li>
                <strong>Enregistrements d'appels</strong> : supprimés
                automatiquement à la fin de la durée prévue par l'offre (voir
                ci-dessous).
            </li>
            <li>
                <strong>Journal des appels</strong> : consultable dans
                l'application pendant la durée prévue par l'offre ; conservé au
                plus <Fill :value="null" /> pour les besoins de facturation et
                de litige.
            </li>
            <li>
                <strong>Invitations</strong> non acceptées : supprimées
                automatiquement à leur expiration.
            </li>
            <li>
                <strong>Organisation supprimée</strong> : ses postes, files et
                numéros sont retirés immédiatement de la téléphonie ; ses
                données sont effacées sous <Fill :value="null" />.
            </li>
            <li>
                <strong>Paiements et factures</strong> : 10 ans, durée de
                conservation des pièces comptables (Acte uniforme OHADA relatif
                au droit comptable).
            </li>
            <li>
                <strong>Messages du formulaire de contact</strong> : le temps de
                traiter la demande, puis au plus 3 ans.
            </li>
        </ul>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Offre</th>
                        <th>Historique consultable</th>
                        <th>Enregistrements d'appels</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="plan in plans" :key="plan.name">
                        <td>{{ plan.name }}</td>
                        <td>
                            {{
                                plan.historyMonths === null
                                    ? 'Illimité'
                                    : `${plan.historyMonths} mois`
                            }}
                        </td>
                        <td>
                            {{
                                plan.recordingDays
                                    ? `${plan.recordingDays} jours`
                                    : 'Non inclus'
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 id="securite">Sécurité</h2>
        <p>
            Les mesures techniques et organisationnelles sont détaillées dans
            notre <Link href="/confiance">centre de confiance</Link> :
            séparation stricte des organisations, chiffrement des échanges dans
            le navigateur, contrôle d'accès par rôle, double authentification,
            suppression automatique des enregistrements…
        </p>

        <h2 id="cookies">Cookies</h2>
        <p>
            Nous n'utilisons que des cookies nécessaires au fonctionnement du
            service : session de connexion, protection des formulaires (CSRF),
            et mémorisation de vos choix d'affichage (thème clair ou sombre,
            menu replié, langue). Aucun cookie publicitaire ni outil de mesure
            d'audience tiers n'est utilisé.
        </p>

        <h2 id="droits">Vos droits</h2>
        <p>
            Conformément à la loi n° 2013-015, vous pouvez demander l'accès à
            vos données et leur origine, leur rectification, leur mise à jour,
            leur verrouillage ou leur effacement, et vous opposer pour un motif
            légitime à leur traitement, ou sans motif à leur utilisation à des
            fins de prospection.
        </p>
        <p>
            Beaucoup de ces droits s'exercent directement dans l'application
            (profil, préférences de notification, suppression du compte). Sinon,
            écrivez à
            <a :href="`mailto:${legal.privacy_email}`"
                ><Fill :value="legal.privacy_email" /></a
            >. Nous répondons dans un délai d'un mois.
        </p>
        <p>
            Si vous avez été appelé par l'un de nos clients ou si votre appel a
            été enregistré, adressez-vous d'abord à cette organisation,
            responsable du traitement : nous l'aiderons à vous répondre.
        </p>
        <p>
            Vous pouvez aussi saisir l'Autorité de protection des données à
            caractère personnel (APDP), à Bamako.
        </p>

        <h2 id="mineurs">Mineurs</h2>
        <p>
            Le service s'adresse aux entreprises et organisations. Il n'est pas
            destiné aux moins de 18 ans.
        </p>

        <h2 id="modifications">Modifications</h2>
        <p>
            Nous pouvons faire évoluer cette politique, par exemple quand le
            service change. La date de mise à jour figure en haut de la page ;
            les changements importants sont signalés aux administrateurs des
            organisations par e-mail ou dans l'application.
        </p>

        <h2 id="contact">Contact</h2>
        <p>
            {{ legal.company.name }}, {{ legal.company.address }} —
            <a :href="`mailto:${legal.privacy_email}`"
                ><Fill :value="legal.privacy_email" /></a
            >, <Fill :value="legal.company.phone" />. Voir aussi les
            <Link href="/mentions-legales">mentions légales</Link>.
        </p>
    </LegalDocument>
</template>
