# Membres et rôles

La gestion des membres se fait dans **Paramètres → Organisation** (propriétaire et administrateurs).

## Créer un agent avec son poste

Le bouton **Créer un membre** crée en une seule étape le compte et le poste téléphonique :

| Champ | Exemple | À savoir |
| --- | --- | --- |
| Nom | Awa Diarra | Affiché dans la supervision et l'historique. |
| Adresse e-mail | awa@entreprise.ml | Identifiant de connexion. |
| Mot de passe | — | Communiquez-le à l'agent, il pourra le changer dans **Paramètres → Sécurité**. |
| Rôle | Membre | Voir le tableau ci-dessous. |
| Poste | 7001 | Le numéro interne de l'agent. |
| Secret SIP | — | Le mot de passe du poste, utilisé par le softphone. L'agent n'a pas à le connaître. |
<!-- centre-appels -->
| File d'appels | Support | Facultatif : ajoute directement l'agent à une file. |
<!-- /centre-appels -->

Dès sa première connexion, l'agent voit le softphone et le menu de statut.

Pour un **superviseur**, un **planificateur** ou un **administrateur** qui ne prend pas d'appels, décochez **Prend aussi des appels** : le compte est créé sans poste ni file, et les champs SIP disparaissent. Un poste pourra lui être attribué plus tard dans **Paramètres → Postes SIP**. Un membre (agent) a toujours un poste.

## Inviter un collègue

**Inviter** envoie une invitation à une adresse e-mail, avec un rôle. La personne l'accepte depuis son compte (ou en créant un compte). Les invitations en attente peuvent être annulées. Un membre invité n'a pas de poste : attribuez-lui en un dans **Paramètres → Postes SIP** s'il doit prendre des appels.

## Les rôles

| | Membre | Planificateur | Superviseur | Administrateur | Propriétaire |
| --- | --- | --- | --- | --- | --- |
| Prendre des appels, campagnes, réunions | ✓ | ✓ | ✓ | ✓ | ✓ |
<!-- centre-appels -->
| Supervision, écoute, journal des agents | | | ✓ | ✓ | ✓ |
<!-- /centre-appels -->
<!-- centre-appels -->
| Créer et piloter les campagnes | | | ✓ | ✓ | ✓ |
<!-- /centre-appels -->
| Roster, échanges, congés et feuilles de temps | | ✓ (tous) | ✓ (ses agents) | ✓ (tous) | ✓ (tous) |
| Postes, règles de travail et types de congé du planning | | ✓ | postes seulement | ✓ | ✓ |
| Membres, files, numéros, SVI, motifs de pause | | | | ✓ | ✓ |
| Abonnement et profil de l'organisation | | | | ✓ | ✓ |
| Supprimer l'organisation | | | | | ✓ |

Le **planificateur** est en charge du planning de toute l'équipe, sans accès à la supervision des appels ni aux campagnes : il construit le roster, traite les échanges, congés et corrections de **tous** les agents (qu'ils aient un superviseur ou non), valide les feuilles de temps et règle les postes, les règles de travail et les types de congé. Il occupe une place de superviseur dans la formule.

## Changer un rôle, retirer un membre

Dans la liste des membres, changez le rôle avec le menu de la ligne, ou retirez le membre. Un membre retiré perd l'accès à l'organisation et sort des files ; son poste n'est plus utilisable.

## Attribuer un superviseur

Chaque membre peut être placé sous la responsabilité d'un **superviseur** (ou d'un administrateur, ou du propriétaire) : menu **Superviseur** sur sa ligne, dans la liste des membres (propriétaire et administrateurs). Ce responsable :

- reçoit ses demandes d'**échange de poste**, de **congé** et de **correction de pointage**, et les approuve ou les refuse dans **Planning › Demandes** ;
- voit et **valide ses feuilles de temps** de la semaine ;
- retrouve ses agents d'un clic avec le filtre **Mes agents** du roster.

Les autres superviseurs ne voient plus ces demandes. Un membre **sans superviseur** reste traité par tous les superviseurs. Les administrateurs et le propriétaire peuvent toujours tout traiter, et personne n'approuve ses propres demandes (sauf le propriétaire) : celles d'un superviseur vont à son propre responsable, ou aux administrateurs.

Si un superviseur redevient simple membre ou quitte l'organisation, ses agents repassent automatiquement **sans superviseur**.

## Plusieurs organisations

Une même personne peut appartenir à plusieurs organisations (un prestataire qui travaille pour deux clients, par exemple). Elle passe de l'une à l'autre avec le sélecteur en haut du menu ; le softphone se connecte au poste de l'organisation choisie.
