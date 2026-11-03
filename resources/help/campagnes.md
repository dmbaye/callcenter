# Créer et piloter une campagne

Les campagnes d'appels sortants sont incluses dans les formules Pro et Corporate. Les superviseurs, administrateurs et le propriétaire les créent et les pilotent depuis le menu **Campagnes**.

## Créer une campagne

Menu **Campagnes**, puis **Nouvelle campagne**. Les réglages sont modifiables à tout moment, même pendant la campagne.

**Général** : nom, objectif interne, script affiché aux agents, agents affectés.

**Mode de numérotation** :

| Mode | Fonctionnement | Pour quoi |
| --- | --- | --- |
| **Aperçu (preview)** | L'agent lit la fiche puis lance l'appel. | Appels à forte valeur, B2B, recouvrement sensible. |
| **Progressif** | Le système compose un numéro dès qu'un agent est libre. | Relances, rappels clients. |
| **Power (multi-lignes)** | Plusieurs numéros par agent libre, le premier qui décroche est passé à l'agent. | Gros volumes, prospection. |

En mode Power, réglez **Lignes par agent libre**, **Lignes simultanées max** et **Taux d'abandon max (%)** : au-delà de ce taux, le système ralentit pour ne pas faire attendre les clients qui décrochent sans agent disponible.

**Ligne sortante** : le **numéro présenté** au client et, si besoin, le préfixe de numérotation.

**Tentatives et horaires** : nombre maximum de tentatives par contact, délai entre deux tentatives, jours et heures d'appel et fuseau horaire. Les appels ne partent jamais en dehors de ces horaires.

## Importer les contacts

Importez un fichier **CSV** (séparé par des virgules ou des points-virgules, export Excel accepté). Les colonnes sont reconnues par leur nom, en français ou en anglais :

| Colonne | Noms reconnus (exemples) |
| --- | --- |
| Téléphone (obligatoire) | `telephone`, `phone`, `numero`, `mobile` |
| Prénom | `prenom`, `first_name` |
| Nom | `nom`, `last_name` |
| Nom complet | `nom_complet`, `name`, `client` |
| Entreprise | `entreprise`, `societe`, `company` |
| E-mail | `email`, `mail`, `courriel` |
| Priorité | `priorite`, `priority` |

Toute autre colonne est conservée sur le contact et utilisable dans le script avec `{nom_de_la_colonne}`. Les doublons, les numéros invalides et les numéros de la liste **Ne pas appeler** sont ignorés ; le résumé de l'import les compte.

## Les codes de qualification

Chaque campagne a ses codes, que l'agent choisit à la fin de l'appel. Chaque code a une action :

| Action | Effet sur le contact |
| --- | --- |
| **Succès** | Terminé, compté comme réussi. |
| **Final** | Terminé, sans succès (refus, mauvais numéro…). |
| **À rappeler (auto)** | Remis dans la liste après le délai prévu. |
| **Rappel planifié** | Rappelé à la date choisie par l'agent. |
| **Ne plus appeler** | Ajouté à la liste *Ne pas appeler*. |

## Le questionnaire

Pour une enquête, une étude de satisfaction ou une qualification de prospects, ajoutez un **questionnaire** à la campagne : bouton **Créer un questionnaire** sur la page de la campagne (superviseurs et administrateurs).

| Type de question | Exemple |
| --- | --- |
| Oui / Non | Êtes-vous satisfait de la livraison ? |
| Choix unique, choix multiples | Par quel canal nous avez-vous connus ? |
| Note (de 0 ou 1 jusqu'à 10) | Sur 5, notez l'accueil en agence |
| Recommandation (NPS 0–10) | Recommanderiez-vous notre banque à un proche ? |
| Nombre, date | Combien de personnes dans le foyer ? |
| Réponse courte ou longue | Que pourrions-nous améliorer ? |

- Cochez **Obligatoire** pour les questions sans lesquelles le questionnaire ne peut pas être terminé.
- Une question peut n'être posée **que selon une réponse précédente** (par exemple « Pourquoi ? » seulement si le client a répondu Non).
- L'**aperçu agent**, à droite, montre le questionnaire tel que l'agent le verra.
- Le **modèle satisfaction client** propose quatre questions prêtes à adapter.

Les résultats (bouton **Résultats**) donnent, pour chaque question, la répartition des réponses, la note moyenne, le score NPS et les dernières réponses libres. L'**export CSV** contient une ligne par contact et une colonne par question.

> **Bon à savoir :** modifier le libellé d'une question ne change pas les réponses déjà données. Supprimer une question ou un choix retire ses réponses des résultats.

## Piloter

- **Démarrer**, **Pause**, **Terminer** : depuis la page de la campagne.
- **Recycler** : remet en jeu les contacts dont les tentatives sont épuisées.
- **Exporter** : télécharge les contacts et leurs résultats.

La page de la campagne suit les contacts par état : *À appeler*, *En appel*, *Rappel*, *Terminé*, *Tentatives épuisées*, *Ne pas appeler*.

## La liste « Ne pas appeler »

**Campagnes → Ne pas appeler** : ces numéros ne sont jamais composés, quelle que soit la campagne. Les contacts qualifiés *Ne plus appeler* y sont ajoutés automatiquement ; vous pouvez aussi en ajouter à la main.

> **Coût des appels :** les appels sortants sont facturés à la minute par l'opérateur, en plus de l'abonnement, sauf minutes incluses dans votre formule.
