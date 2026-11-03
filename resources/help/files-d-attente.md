# Les files d'attente

Une file d'attente reçoit les appels d'un service (*Support*, *Ventes*…), les fait patienter et les distribue aux agents disponibles. Tout se règle dans **Paramètres → Files d'attente** (administrateurs), file par file, sur une seule page à onglets.

## Créer une file

Cliquez sur **Nouvelle file**, donnez-lui un nom et choisissez **Qui reçoit l'appel ?** :

| Stratégie | Fonctionnement |
| --- | --- |
| Chacun son tour (reprend au suivant) | Les appels tournent entre les agents. Le choix par défaut, équitable. |
| Faire sonner tous les agents libres | Tous sonnent, le premier qui décroche prend l'appel. Pour les petites équipes. |
| Agent libre depuis le plus longtemps | Équilibre le temps de repos. |
| Agent ayant pris le moins d'appels | Équilibre le nombre d'appels. |
| Au hasard / Au hasard, en tenant compte du niveau | Répartition aléatoire. |

## Onglet Général

Les réglages de base : sonnerie par agent, pause avant l'agent suivant, et **Sonner un agent déjà en ligne** (à laisser désactivé en général). Si le niveau de service est suivi, le **délai de décroché visé** de la file s'affiche aussi ici.

## Le niveau de service (facultatif)

Le niveau de service mesure la part des appels décrochés **dans un délai donné**, par exemple « 80 % des appels décrochés en moins de 20 secondes ». Il ne sert qu'aux équipes qui ont un engagement de ce type ; il est donc désactivé par défaut.

En haut de la page **Files d'attente**, cochez **Suivre le niveau de service** et indiquez l'**objectif** en pourcentage (80 % par défaut), puis enregistrez. Chaque file a son propre **délai de décroché visé** (onglet *Général*) ; les files qui n'en avaient pas reçoivent 20 secondes. Le résultat s'affiche en [supervision](/help/supervision-temps-reel).

## Onglet Agents

Cochez les agents qui reçoivent les appels de la file et réglez leur **niveau**. Les agents du niveau le plus bas sont sollicités en premier ; ceux d'un niveau supérieur ne reçoivent l'appel que si les premiers sont tous occupés. Pratique pour garder des renforts.

Seuls les agents **Prêt** et dont le softphone est connecté reçoivent des appels.

## Onglet Attente et débordement

- **Pendant l'attente** : musique d'attente, annonce de la position et du temps d'attente, message périodique.
- **Si personne ne répond** : attente maximale, nombre maximal d'appelants, puis la destination de débordement (autre file, menu vocal, poste, demande de rappel ou message).
- **Faire sortir si plus aucun agent** : l'appelant est redirigé au lieu d'attendre dans une file vide.

## Onglet Horaires et fermetures

- **Horaires d'ouverture** : jour par jour.
- **Jours de fermeture** : jours fériés et fermetures exceptionnelles (Tabaski, inventaire…).
- **Fermeture d'urgence** : ferme la file immédiatement (coupure, événement imprévu).
- **Message de fermeture** : le fichier audio joué en dehors des horaires et les jours de fermeture (vide = message par défaut).
- **Message joué** pendant une fermeture d'urgence (vide = message de fermeture).

## Onglet Enregistrement et enquête

- **Enregistrer** les appels de la file (formules Croissance, Pro et Corporate), avec un message d'information à l'appelant.
- **Enquête de satisfaction** après l'appel.

> **Astuce :** après chaque modification, faites un appel test vers la file et vérifiez-le dans la [supervision](/{team}/monitoring).
