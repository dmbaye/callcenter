# Planning, échanges, pointage et congés

Le menu **Planning** réunit le roster de l'équipe, les échanges de postes, le pointage, les feuilles de temps et les congés. Les agents y voient leur propre planning ; les superviseurs, administrateurs et le propriétaire voient en plus le roster, les demandes à traiter et les réglages.

## Les postes

Par défaut, deux postes de 8 h couvrent 16 h par jour :

| Code | Poste | Horaires |
| --- | --- | --- |
| **A** | Matin / journée | 08:00 – 16:00 |
| **B** | Soir / nuit | 16:00 – 00:00 |

Un poste dont la fin précède le début (ou finit à minuit) se termine le lendemain. Les superviseurs peuvent renommer les postes, changer leurs horaires, la pause prévue, la couleur et l'**effectif minimum**, ou en ajouter d'autres dans **Planning › Réglages**. Une modification ne touche que les nouvelles affectations : les postes déjà au roster gardent leurs horaires.

## Le roster (superviseurs)

**Planning › Roster** affiche une ligne par agent et une colonne par jour, en vue **Semaine** ou **Mois**.

- Survolez une case et cliquez sur **+** pour affecter A, B, ou **A + B** (16 h) ce jour-là.
- Cliquez sur le nom d'un agent, ou sur **Affecter**, pour affecter plusieurs jours d'un coup.
- La croix sur un poste le retire (jours à venir seulement).
- **Copier la semaine précédente** reprend la semaine d'avant ; les postes qui enfreignent une règle ou tombent sur un congé ne sont pas copiés et sont listés.
- **Prévenir l'équipe** envoie à chaque agent son planning de la semaine affichée.
- Les lignes **Couverture** en bas du tableau comptent les agents par poste ; une case rouge est sous l'effectif minimum.
- Les congés approuvés apparaissent hachurés, les congés en attente en pointillés avec un « ? ».

Un agent est prévenu (notification, e-mail selon ses préférences) quand un poste des 7 prochains jours lui est ajouté ou retiré. Pour les semaines plus lointaines, utilisez **Prévenir l'équipe**.

Si des agents vous sont attribués (voir [Attribuer un superviseur](/help/membres-et-roles#attribuer-un-superviseur)), le roster s'ouvre sur **Mes agents** ; **Toute l'équipe** affiche tout le monde, avec le nom du superviseur de chacun. La couverture compte toujours toute l'équipe.

## Qui traite les demandes

Les échanges, congés, corrections et validations de feuilles de temps d'un agent sont traités par **son superviseur** quand il en a un, sinon par n'importe quel superviseur. Les **planificateurs**, les administrateurs et le propriétaire peuvent tout traiter. Personne n'approuve ses propres demandes, sauf le propriétaire. Pour un échange entre deux agents de superviseurs différents, l'un ou l'autre peut décider.

## Les règles de repos et de durée

Chaque affectation, échange ou copie est vérifié :

- **Chevauchement** : un agent ne peut pas avoir deux postes en même temps. Toujours bloquant.
- **Congé approuvé** : on ne peut pas planifier un agent en congé. Toujours bloquant.
- **Heures d'affilée** (16 h par défaut) : des postes qui s'enchaînent sans pause comptent comme un seul bloc. A + B le même jour fait 16 h, c'est permis ; ajouter un poste de plus est refusé.
- **Repos minimum** entre deux blocs (8 h par défaut), porté à **12 h après un bloc de plus de 8 h**. Deux journées de 16 h à la suite sont donc impossibles : B finit à minuit et A recommence à 8 h, soit 8 h de repos seulement.
- **Heures par semaine** (48 h par défaut).

Un planificateur ou un administrateur choisit dans les réglages si ces trois dernières règles **refusent** l'affectation ou l'**autorisent avec un avertissement** (triangle orange sur le poste).

## Échanger un poste (agents)

Dans **Planning › Mon planning**, à côté d'un poste à venir, cliquez sur **Proposer un échange** :

1. Choisissez un collègue, puis l'un de ses postes en échange, ou **Rien en échange** pour lui céder le vôtre.
2. Le collègue reçoit la proposition et l'**accepte** ou la **refuse**.
3. S'il accepte, votre superviseur (ou celui du collègue) l'**approuve** ou la **refuse** avec un motif dans **Planning › Demandes**.
4. Une fois approuvé, le roster est mis à jour et les deux agents sont prévenus.

L'échange est refusé d'emblée s'il crée un chevauchement, tombe sur un congé ou enfreint une règle bloquante, pour l'un ou l'autre des agents.
<!-- centre-appels -->
Si vous êtes membre d'une file, le collègue doit aussi avoir un poste SIP et répondre à au moins une de vos files, pour que les appels soient toujours pris.
<!-- /centre-appels -->

Si un planificateur ou un administrateur a activé l'**approbation automatique**, un échange accepté par le collègue est validé sans superviseur quand aucune règle n'est dépassée (pas d'heures supplémentaires, repos respecté). Un échange encore ouvert expire quand le poste commence.

## Le pointage

En haut de **Mon planning** : **Pointer l'arrivée**, **Commencer une pause** / **Reprendre**, **Pointer le départ**. Le pointage est relié au poste en cours ou qui commence dans les 2 heures.

## Les feuilles de temps

**Planning › Feuilles de temps** montre la semaine jour par jour : postes prévus, pointages, pauses, temps travaillé et **écart** avec le prévu (déduction faite des congés). Les retards (au-delà de la tolérance), départs anticipés, absences et départs non pointés sont signalés.

- **Agent** : le crayon permet de **demander une correction** (heures d'arrivée, de départ, pause et motif). Le superviseur l'approuve ou la refuse ; l'historique garde les anciennes valeurs.
- **Superviseur** : la vue d'équipe résume chaque agent ; cliquez sur un nom pour ouvrir sa semaine, puis **Valider la semaine**. Une semaine validée n'accepte plus de correction tant qu'elle n'est pas **rouverte**.
- **Exports** : **CSV** (séparateur point-virgule, s'ouvre dans Excel), **Excel** (.xlsx) et **PDF** (page d'impression, choisissez « Enregistrer en PDF »). Depuis la vue d'équipe, l'export contient tous les agents.

## Les congés

**Planning › Congés** affiche vos soldes de l'année : congés payés (30 j), maladie, sans solde, exceptionnel / urgence (5 j). Les jours comptés vont du lundi au samedi ; une demande sur une partie de journée compte en heures (8 h = 1 jour). Les demandes en attente sont déjà déduites du solde.

1. **Demander un congé** : type, dates (ou une plage horaire pour une partie de journée), motif.
2. Le superviseur voit dans **Demandes** le solde restant, les postes qui seront retirés et les créneaux qui passeraient **sous l'effectif minimum**.
3. À l'approbation, les postes entièrement couverts sont retirés du roster et une **alerte de couverture** prévient les superviseurs si un créneau manque d'agents. Un poste couvert en partie reste au roster ; la feuille de temps déduit les heures de congé.

Vous pouvez annuler une demande en attente, ou un congé approuvé qui n'a pas commencé ; les superviseurs sont alors prévenus.

## Notifications

Les notifications du planning (nouveaux postes, échanges, congés, corrections, alertes de couverture) arrivent dans la cloche, en temps réel, et par e-mail. Chacun choisit ses canaux dans **Profil › Notifications**, catégorie **Planning**.
