# Numéros et menus vocaux (SVI)

La page **Paramètres → Numéros et SVI** (administrateurs) décide où arrive chaque appel entrant.

## Le numéro de la plateforme

Votre organisation reçoit **automatiquement un numéro** de la plateforme quand vous créez votre première file d'attente : il est routé vers cette file, sans rien saisir. Si votre organisation n'en a pas (aucun numéro n'était libre à ce moment-là, par exemple), le bouton **Obtenir un numéro** en haut de la page vous en attribue un dès qu'il y en a de disponibles.

Vous préférez garder **votre propre numéro** (porté chez notre opérateur ou renvoyé vers lui) ? Cliquez sur **Remplacer par votre numéro** à côté du numéro de la plateforme et saisissez le vôtre : il reprend exactement la même destination, et le numéro de la plateforme est rendu. Les numéros de la plateforme ne peuvent pas être saisis à la main : ils sont toujours attribués automatiquement.

## Les numéros entrants

Chaque numéro (celui de la plateforme ou les vôtres, appelés SDA) est déclaré ici, avec sa **destination** :

| Destination | Effet |
| --- | --- |
| File d'attente | L'appel rejoint la file et est distribué aux agents. |
| Menu vocal (SVI) | L'appelant entend un menu « tapez 1, tapez 2 ». |
| Poste | L'appel sonne directement chez un agent. |
| Demande de rappel | L'appelant laisse sa demande ; elle arrive chez les agents comme un contact à rappeler. |
| Message puis raccrocher | Un message est joué, puis l'appel se termine. |
| Raccrocher | L'appel est refusé. |

Utilisez **Modifier** pour changer la destination d'un numéro et **Retirer** pour le supprimer.

## Les menus vocaux

Un menu vocal accueille l'appelant et l'oriente :

1. **Message d'accueil** : par exemple « Bienvenue chez … Pour les ventes, tapez 1. Pour le service client, tapez 2. »
2. **Touches** : pour chaque touche, un libellé et une destination (file, autre menu, poste…).
3. **Attente touche** et **Répétitions** : combien de temps attendre un choix et combien de fois répéter le message.
4. **Sans choix valide** : la destination par défaut quand l'appelant ne tape rien ou se trompe trop souvent. Un message « touche invalide » peut être joué entre deux essais.

> **Conseil :** limitez-vous à 4 ou 5 choix et mettez le plus demandé en touche 1. Un menu trop long fait raccrocher les appelants.

Les messages sont des fichiers audio installés sur le serveur téléphonique (par exemple `custom/accueil`). Demandez à votre prestataire de déposer vos enregistrements.
