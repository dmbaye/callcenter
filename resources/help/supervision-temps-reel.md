# La supervision en temps réel

La page **Supervision** (superviseurs, administrateurs et propriétaire) montre ce qui se passe à l'instant : appels en attente, agents en communication, agents libres ou en pause. Elle se met à jour toute seule, sans recharger la page.

## Les indicateurs

| Indicateur | Ce qu'il mesure |
| --- | --- |
| **En attente** | Appelants dans les files, pas encore décrochés. |
| **Plus longue attente** | Le temps d'attente de l'appelant qui patiente depuis le plus longtemps. Surveillez-le en priorité. |
| **En communication** | Agents en appel. |
| **Agents libres** | Agents *Prêt*, disponibles pour le prochain appel. |
| **En pause** | Agents *Non prêt*, *Post-appel* ou *Ne pas déranger*, avec le motif. |
| **Traités / Abandons** | Appels décrochés et appelants qui ont raccroché avant d'être servis, aujourd'hui. |
| **Niveau de service** | Si l'équipe le suit : part des appels décrochés dans le délai de chaque file, toutes files confondues. Vert à l'objectif ou au-dessus, orange jusqu'à 10 points en dessous, rouge au-delà. |

Le détail par **file d'attente** indique pour chacune les appels en attente, leur position et le nombre d'appels répondus ou manqués, ainsi que son niveau de service quand il est suivi. Ces compteurs sont ceux du serveur téléphonique : ils repartent de zéro quand il redémarre.

## Que faire quand l'attente monte

- Vérifiez les agents **en pause** : un agent resté en *Non prêt – Réunion vidéo* après une réunion peut repasser *Prêt*.
- Ajoutez des agents à la file dans **Paramètres → Files d'attente**, onglet *Agents*.
- Prévoyez un **débordement** (vers une autre file ou une demande de rappel) dans l'onglet *Attente et débordement* de la file.

## Écouter et chuchoter

Sur un agent en communication, deux actions permettent d'accompagner un appel en cours :

- **Écouter** : vous entendez la conversation, ni l'agent ni le client ne vous entendent.
- **Chuchoter** : vous parlez à l'agent seul, le client ne vous entend pas. Idéal pour former un nouvel agent.

Le superviseur doit avoir **son propre poste SIP** dans l'organisation et son softphone connecté : c'est lui qui sonne quand vous lancez l'écoute. Décrochez pour entendre l'appel. Vous ne pouvez pas écouter votre propre appel.

> **Bon à savoir :** informez vos agents que les appels peuvent être écoutés à des fins de formation et de qualité.
