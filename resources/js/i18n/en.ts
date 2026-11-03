/**
 * English UI texts, keyed by the French source text (see lib/i18n.ts).
 * A key missing here is shown in French: add pages as they are translated.
 */
const en: Record<string, string> = {
    'Tableau de bord': 'Dashboard',
    Historique: 'Call history',
    Supervision: 'Monitoring',
    'Journal des agents': 'Agent log',
    Campagnes: 'Campaigns',
    Réunions: 'Meetings',
    Aide: 'Help',
    'Supprimer le compte': 'Delete account',
    'Supprimez votre compte et toutes ses ressources':
        'Delete your account and all of its resources',
    Attention: 'Warning',
    'Soyez prudent : cette action est irréversible.':
        'Please proceed with caution, this cannot be undone.',
    'Voulez-vous vraiment supprimer votre compte ?':
        'Are you sure you want to delete your account?',
    'Une fois votre compte supprimé, toutes ses ressources et données seront définitivement effacées. Veuillez saisir votre mot de passe pour confirmer la suppression définitive de votre compte.':
        'Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
    'Mot de passe': 'Password',
    Annuler: 'Cancel',
    Paramètres: 'Settings',
    'Se déconnecter': 'Log out',
    'Thème : :current. Passer au thème :next':
        'Theme: :current. Switch to :next',
    'Thème : :current (cliquer pour :next)':
        'Theme: :current (click for :next)',
    'Authentification à deux facteurs': 'Two-factor authentication',
    "Gérez vos paramètres d'authentification à deux facteurs":
        'Manage your two-factor authentication settings',
    "Lorsque vous activez l'authentification à deux facteurs, un code PIN sécurisé vous est demandé à la connexion. Il peut être obtenu depuis une application compatible TOTP sur votre téléphone.":
        'When you enable two-factor authentication, you will be prompted for a secure PIN during login. You can get it from a TOTP-supported application on your phone.',
    'Poursuivre la configuration': 'Continue setup',
    'Activer la 2FA': 'Enable 2FA',
    "Un code PIN aléatoire et sécurisé vous sera demandé à la connexion ; vous pouvez l'obtenir depuis une application compatible TOTP sur votre téléphone.":
        'You will be prompted for a secure, random PIN during login, which you can get from a TOTP-supported application on your phone.',
    'Désactiver la 2FA': 'Disable 2FA',
    Utilisation: 'Usage',
    'Vous pouvez changer à tout moment : files, numéros et historique sont conservés.':
        'You can change this at any time: queues, numbers and history are kept.',
    "Les campagnes en cours seront mises en pause. Les appels vers vos numéros continuent d'arriver dans vos files.":
        'Running campaigns will be paused. Calls to your numbers keep arriving in your queues.',
    Enregistrer: 'Save',
    "Clés d'accès": 'Passkeys',
    "Gérez vos clés d'accès pour une connexion sans mot de passe":
        'Manage your passkeys for passwordless sign-in',
    "Aucune clé d'accès pour l'instant": 'No passkeys yet',
    "Ajoutez une clé d'accès pour vous connecter sans mot de passe":
        'Add a passkey to sign in without a password',
    'Choisir une organisation': 'Select organization',
    Organisations: 'Organizations',
    'Nouvelle organisation': 'New organization',
    'Notifications : :count non lue(s)': 'Notifications: :count unread',
    Notifications: 'Notifications',
    'Tout marquer comme lu': 'Mark all as read',
    'Chargement…': 'Loading…',
    'Aucune notification': 'No notifications',
    'Les invitations et rappels de réunion apparaîtront ici.':
        'Meeting invitations and reminders will show up here.',
    Profil: 'Profile',
    Sécurité: 'Security',
    Organisation: 'Organization',
    Abonnement: 'Subscription',
    "Files d'attente": 'Queues',
    'Numéros et SVI': 'Numbers and IVR',
    "Plan d'appel": 'Dial plan',
    'Postes SIP': 'SIP extensions',
    'Motifs de pause': 'Pause reasons',
    'Gérez votre profil et les paramètres de votre compte':
        'Manage your profile and account settings',
    "Choisissez ce que vous recevez dans l'application (cloche et alertes) et par e-mail.":
        'Choose what you receive in the app (bell and alerts) and by email.',
    Catégorie: 'Category',
    'Les invitations à rejoindre une organisation et les e-mails de sécurité (mot de passe, vérification) sont toujours envoyés.':
        'Invitations to join an organization and security emails (password, verification) are always sent.',
    'Paramètres du profil': 'Profile settings',
    'Retirer votre photo de profil ?': 'Remove your profile photo?',
    'Photo de profil': 'Profile photo',
    "Elle apparaît dans l'annuaire, les réunions et la liste des membres.":
        'It appears in the directory, meetings and the member list.',
    'Changer la photo': 'Change photo',
    'Ajouter une photo': 'Add a photo',
    Retirer: 'Remove',
    'JPG, PNG ou WebP, 4 Mo au plus. Recadrée en carré.':
        'JPG, PNG or WebP, up to 4 MB. Cropped to a square.',
    'Vos informations visibles par vos collègues':
        'Your details, visible to your colleagues',
    Nom: 'Name',
    'Nom complet': 'Full name',
    'Adresse e-mail': 'Email address',
    "Votre adresse e-mail n'est pas vérifiée.":
        'Your email address is unverified.',
    "Cliquez ici pour renvoyer l'e-mail de vérification.":
        'Click here to resend the verification email.',
    'Un nouveau lien de vérification a été envoyé à votre adresse e-mail.':
        'A new verification link has been sent to your email address.',
    Genre: 'Gender',
    'Non renseigné': 'Not specified',
    Téléphone: 'Phone',
    Fonction: 'Job title',
    'ex. Responsable commercial': 'e.g. Sales manager',
    Service: 'Department',
    'ex. Ventes': 'e.g. Sales',
    Langue: 'Language',
    "Langue de l'interface et des e-mails que vous recevez.":
        'Language of the interface and of the emails you receive.',
    'Paramètres de sécurité': 'Security settings',
    'Mettre à jour le mot de passe': 'Update password',
    'Utilisez un mot de passe long et aléatoire pour sécuriser votre compte':
        'Ensure your account is using a long, random password to stay secure',
    'Mot de passe actuel': 'Current password',
    'Nouveau mot de passe': 'New password',
    'Confirmer le mot de passe': 'Confirm password',
    Bonjour: 'Good morning',
    Bonsoir: 'Good evening',
    'En cours': 'Live',
    'Votre poste : :ext': 'Your extension: :ext',
    "Aucun poste ne vous est attribué : demandez à l'administrateur.":
        'No extension is assigned to you: ask your administrator.',
    Composer: 'Dial',
    Annuaire: 'Directory',
    'Appelez un collègue sur son poste, gratuitement.':
        'Call a colleague on their extension, free of charge.',
    'Nom, service, poste…': 'Name, department, extension…',
    'Rechercher un collègue': 'Search for a colleague',
    ':count collègue(s) sans poste ne peuvent pas être appelés.':
        ':count colleague(s) without an extension cannot be called.',
    'Attribuer des postes': 'Assign extensions',
    'Appeler :name': 'Call :name',
    Appeler: 'Call',
    'Aucun collègue ne correspond.': 'No colleague matches.',
    'Invitez vos collègues depuis Paramètres → Organisation.':
        'Invite your colleagues from Settings → Organization.',
    'Réunions du jour': "Today's meetings",
    'Celles où vous êtes invité.': 'The ones you are invited to.',
    'Tout voir': 'See all',
    ':count pers.': ':count people',
    Rejoindre: 'Join',
    "Aucune réunion aujourd'hui.": 'No meetings today.',
    "à l'instant": 'just now',
    'Bonjour :name': 'Hello :name',
    "Centre d'appels": 'Call center',
    "Files d'attente, numéros entrants, SVI, campagnes, supervision et statuts des agents, plus les appels internes et les réunions.":
        'Queues, inbound numbers, IVR, campaigns, monitoring and agent states, plus internal calls and meetings.',
    'Communication interne': 'Internal communication',
    "Appels entre collègues (poste à poste) et réunions vidéo uniquement. Les écrans du centre d'appels sont masqués.":
        'Calls between colleagues (extension to extension) and video meetings only. Call-center screens are hidden.',
    "Dans l'application": 'In the app',
    'Par e-mail': 'By email',
    Clair: 'Light',
    Sombre: 'Dark',
    Système: 'System',
    'Mon organisation': 'My organization',
    'Créer une nouvelle organisation': 'Create a new organization',
    "Créez une nouvelle organisation pour collaborer avec d'autres personnes.":
        'Create a new organization to collaborate with others.',
    "Nom de l'organisation": 'Organization name',
    "Créer l'organisation": 'Create organization',
    "Retirer un membre de l'organisation": 'Remove member from organization',
    'Voulez-vous vraiment retirer': 'Are you sure you want to remove',
    'de cette organisation ?': 'from this organization?',
    'Retirer le membre': 'Remove member',
    "Quitter l'organisation": 'Leave organization',
    'Voulez-vous vraiment quitter': 'Are you sure you want to leave',
    "Transférer l'appel vers une file": 'Transfer the call to a queue',
    Transférer: 'Transfer',
    'Transférer vers une file': 'Transfer to a queue',
    'Chargement des files…': 'Loading queues…',
    'Aucune file trouvée.': 'No queue found.',
    'Transfert…': 'Transferring…',
    'Codes de récupération 2FA': '2FA recovery codes',
    "Les codes de récupération vous permettent de retrouver l'accès si vous perdez votre appareil 2FA. Conservez-les dans un gestionnaire de mots de passe sécurisé.":
        'Recovery codes let you regain access if you lose your 2FA device. Store them in a secure password manager.',
    'Régénérer les codes': 'Regenerate codes',
    "Chaque code de récupération ne peut être utilisé qu'une fois pour accéder à votre compte et sera supprimé après usage. Si vous en avez besoin d'autres, cliquez sur":
        'Each recovery code can be used once to access your account and will be removed after use. If you need more, click',
    'ci-dessus.': 'above.',
    'Quitter la visite': 'Exit tour',
    Précédent: 'Previous',
    Passer: 'Skip',
    "Supprimer la clé d'accès": 'Delete passkey',
    'Sélectionner un rôle': 'Select a role',
    "Inviter un membre de l'organisation": 'Invite an organization member',
    'Envoyez une invitation pour rejoindre cette organisation.':
        'Send an invitation to join this organization.',
    Rôle: 'Role',
    "Envoyer l'invitation": 'Send invitation',
    'Menu de navigation': 'Navigation menu',
    'Invitations en attente': 'Pending invitations',
    "Acceptez ou refusez les organisations que l'on vous a invité à rejoindre.":
        'Accept or decline the organizations you were invited to join.',
    Refuser: 'Decline',
    Accepter: 'Accept',
    "Saisissez le nom de l'organisation": 'Enter the organization name',
    'Êtes-vous sûr ?': 'Are you sure?',
    "Cette action est irréversible. Elle supprimera définitivement l'organisation":
        'This action cannot be undone. It will permanently delete the organization',
    Saisissez: 'Type',
    'pour confirmer': 'to confirm',
    "Supprimer l'organisation": 'Delete organization',
    'Saisissez un numéro': 'Enter a number',
    'Numéro à appeler': 'Number to call',
    'Effacer (double-clic pour tout effacer)': 'Delete (double-click to clear)',
    'Effacer le dernier chiffre': 'Delete the last digit',
    "Durée de l'appel": 'Call duration',
    'Clavier (DTMF)': 'Keypad (DTMF)',
    'Rappeler le': 'Call back on',
    'Note (facultatif)': 'Note (optional)',
    'Afficher le softphone': 'Show the softphone',
    'Ouvrir le softphone': 'Open the softphone',
    Récents: 'Recent',
    'Vos derniers appels apparaîtront ici pour les rappeler en un clic.':
        'Your latest calls will appear here so you can call back in one click.',
    Répondre: 'Answer',
    Raccrocher: 'Hang up',
    'Recherche de la campagne…': 'Looking up the campaign…',
    Valider: 'Submit',
    "Résultat de l'appel enregistré.": 'Call outcome saved.',
    'Softphone hors ligne': 'Softphone offline',
    Prêt: 'Ready',
    'Non prêt': 'Not ready',
    'Traitement post-appel': 'After-call work',
    'Ne pas déranger': 'Do not disturb',
    Connexion: 'Log in',
    Déconnexion: 'Log out',
    'Sélectionner une file': 'Select a queue',
    'Mot de passe SIP': 'SIP password',
    'Créer un membre': 'Create a member',
    'Créez le compte directement et attribuez-lui ses identifiants SIP.':
        'Create the account directly and assign its SIP credentials.',
    "File d'appels": 'Call queue',
    'Poste SIP': 'SIP extension',
    'Secret SIP': 'SIP secret',
    'Créer un utilisateur': 'Create user',
    Plateforme: 'Platform',
    'ex. MacBook Pro, iPhone': 'e.g. MacBook Pro, iPhone',
    "Les clés d'accès ne sont pas prises en charge par ce navigateur.":
        'Passkeys are not supported by this browser.',
    "Ajouter une clé d'accès": 'Add a passkey',
    "Nom de la clé d'accès": 'Passkey name',
    "Un nom vous aide à identifier cette clé d'accès plus tard.":
        'A name helps you identify this passkey later.',
    Renouveler: 'Renew',
    "Contactez l'administrateur de l'équipe.":
        'Contact the team administrator.',
    "Annuler l'invitation": 'Cancel invitation',
    "Voulez-vous vraiment annuler l'invitation de":
        'Are you sure you want to cancel the invitation for',
    "Conserver l'invitation": 'Keep invitation',
    'ou saisissez le code manuellement': 'or enter the code manually',
    Retour: 'Back',
    Confirmer: 'Confirm',
    "Profil de l'entreprise": 'Company profile',
    "Activité, coordonnées et identifiants légaux de l'organisation":
        'Activity, contact details and legal identifiers of the organization',
    'Ex. MbayeLab SARL': 'e.g. MbayeLab SARL',
    'Choisir un secteur': 'Choose an industry',
    "Nombre d'employés": 'Number of employees',
    'Choisir un usage': 'Choose a use case',
    'Ex. 10': 'e.g. 10',
    'Choisir un pays': 'Choose a country',
    'Ex. Bamako': 'e.g. Bamako',
    'Quartier, rue, porte': 'District, street, door',
    'contact@entreprise.ml': 'contact@company.ml',
    'Ex. MA.BKO.2024.B.1234': 'e.g. MA.BKO.2024.B.1234',
    "Numéro d'identification fiscale": 'Tax identification number',
    'Choisir une origine': 'Choose a source',
    Activité: 'Activity',
    'Raison sociale': 'Legal name',
    "Secteur d'activité *": 'Industry *',
    "Taille de l'entreprise *": 'Company size *',
    'Usage principal': 'Main use',
    "Nombre d'agents prévus": 'Planned agents',
    Coordonnées: 'Contact details',
    'Pays *': 'Country *',
    'Ville *': 'City *',
    Adresse: 'Address',
    'Téléphone *': 'Phone *',
    'E-mail de contact': 'Contact email',
    'Site web': 'Website',
    'Identifiants légaux': 'Legal identifiers',
    'Relation commerciale': 'Business relationship',
    'Comment nous avez-vous connus ?': 'How did you hear about us?',
    'Recevoir nos nouveautés et offres par e-mail, SMS ou WhatsApp.':
        'Receive our news and offers by email, SMS or WhatsApp.',
    "Vous pouvez changer d'avis à tout moment ici.":
        'You can change your mind here at any time.',
    'Enregistrer le profil': 'Save profile',
    'Seuls le propriétaire et les administrateurs peuvent modifier le profil.':
        'Only the owner and administrators can edit the profile.',
    'État de la file': 'Queue status',
    Répondus: 'Answered',
    Manqués: 'Missed',
    'Durée de conversation': 'Talk time',
    "Aucun agent n'a encore de poste SIP.": 'No agent has a SIP extension yet.',
    modifier: 'edit',
    'Menu vocal': 'Voice menu',
    'Poste (ex. 7001)': 'Extension (e.g. 7001)',
    'Son Asterisk (ex. custom/fermeture)':
        'Asterisk sound (e.g. custom/fermeture)',
    'Choisir une file': 'Choose a queue',
    'Choisir un menu': 'Choose a menu',
    "Télécharger l'enregistrement": 'Download the recording',
    'Ajouter à mon agenda': 'Add to my calendar',
    "Ajouter à l'agenda": 'Add to calendar',
    'Google Agenda': 'Google Calendar',
    'Apple Calendar, autres (.ics)': 'Apple Calendar, others (.ics)',
    'Micro coupé': 'Microphone muted',
    '— écran': '— screen',
    'Notes de la réunion': 'Meeting notes',
    'a modifié les notes pendant que vous écriviez.':
        'changed the notes while you were typing.',
    'Voir leur version': 'See their version',
    'Garder la mienne': 'Keep mine',
    Afficher: 'Show',
    'Enregistrement…': 'Saving…',
    'Non enregistré.': 'Not saved.',
    Réessayer: 'Retry',
    'Modifications en cours…': 'Unsaved changes…',
    'Partagées avec les participants': 'Shared with participants',
    Copier: 'Copy',
    'Rechercher un membre de l’équipe': 'Search for a team member',
    'Rechercher un membre': 'Search for a member',
    'Tout le monde': 'Everyone',
    Micro: 'Microphone',
    'Micro par défaut': 'Default microphone',
    Caméra: 'Camera',
    'Caméra par défaut': 'Default camera',
    'Personne n’est encore dans la réunion.': 'Nobody is in the meeting yet.',
    'La réunion n’a pas encore commencé : vous pouvez entrer en avance, les participants seront prévenus de votre arrivée.':
        'The meeting has not started yet: you can join early, participants will be told you are there.',
    'Rejoindre la réunion': 'Join the meeting',
    'Sujets à aborder, documents à préparer… (repris dans l’invitation)':
        'Topics to cover, documents to prepare… (included in the invitation)',
    Titre: 'Title',
    'Heure *': 'Time *',
    Durée: 'Duration',
    'Ordre du jour': 'Agenda',
    'Enregistrer et prévenir': 'Save and notify',
    'Planifier et inviter': 'Schedule and invite',
    'Démarrer maintenant': 'Start now',
    'Non lue': 'Unread',
    'Rechercher : transférer, pause, file d’attente…':
        'Search: transfer, pause, queue…',
    Résultats: 'Results',
    'Rechercher dans l’aide': 'Search the help',
    'Aucun article ne correspond. Essayez un autre mot.':
        'No article matches. Try another word.',
    'Chaque appel pris en charge. Chaque agent accompagné.':
        'Every call handled. Every agent supported.',
    "Accédez à votre softphone, à vos files et à votre tableau de bord en direct depuis n'importe quel navigateur.":
        'Reach your softphone, your queues and your live dashboard from any browser.',
    'File Support': 'Support queue',
    '2 appelants en attente': '2 callers waiting',
    "Retour à l'accueil": 'Back to home',
    "Le nom affiché dans l'application, les membres et les identifiants utilisés par Asterisk":
        'The name shown in the app, the members and the identifiers used by Asterisk',
    "Seuls le propriétaire et les administrateurs peuvent modifier l'organisation":
        'Only the owner and administrators can edit the organization',
    'Invitations pas encore acceptées': 'Invitations not accepted yet',
    'Identifiants techniques': 'Technical identifiers',
    "Attribués une fois pour toutes : ils relient l'organisation à ses postes, files et appels dans Asterisk":
        'Assigned once and for all: they link the organization to its extensions, queues and calls in Asterisk',
    'Non modifiable': 'Cannot be changed',
    "Supprimer définitivement l'organisation, ses postes et ses files dans Asterisk":
        'Permanently delete the organization, its extensions and its queues in Asterisk',
    'Mes organisations': 'My organizations',
    "L'adresse des pages suit le nom (actuellement":
        'The page address follows the name (currently',
    '). Les identifiants Asterisk ne changent pas.':
        '). The Asterisk identifiers do not change.',
    Propriétaire: 'Owner',
    'Membres · postes SIP': 'Members · SIP extensions',
    'Créée le': 'Created on',
    'Inviter un membre': 'Invite a member',
    'Les organisations dont vous êtes membre':
        'The organizations you belong to',
    Personnelle: 'Personal',
    "Voir l'organisation": 'View organization',
    "Modifier l'organisation": 'Edit organization',
    "Vous n'appartenez encore à aucune organisation.":
        'You do not belong to any organization yet.',
    "File d'attente": 'Queue',
    'Plus longue attente': 'Longest wait',
    'En communication': 'On a call',
    'Agents libres': 'Free agents',
    'En pause': 'On break',
    Désconnecté: 'Disconnected',
    'Surveillance en temps réel': 'Real-time monitoring',
    Rafraîchir: 'Refresh',
    "Les données Asterisk en temps réel ne sont pas disponibles (AMI non configuré ou inaccessible). La présence dans la file d'attente reste affichée, mais les appels en attente, la détection des appels en cours ainsi que les fonctions d'écoute et de chuchotement nécessitent l'AMI.":
        'Real-time Asterisk data is not available (AMI not configured or unreachable). Queue presence is still shown, but waiting calls, active call detection and listen/whisper need the AMI.',
    'Appels en attente': 'Waiting calls',
    "Appelants en attente d'un agent à l'heure actuelle, par file d'attente.":
        'Callers waiting for an agent right now, per queue.',
    Numéro: 'Number',
    'En attente': 'Waiting',
    "Aucun appelant n'est en attente.": 'No caller is waiting.',
    Traités: 'Handled',
    Abandons: 'Abandoned',
    'Agents en communication': 'Agents on a call',
    "Écoutez en silence ou chuchotez pour guider l'agent sans que l'interlocuteur ne vous entende.":
        'Listen silently or whisper to coach the agent without the other party hearing you.',
    Appelant: 'Caller',
    Écouter: 'Listen',
    Chuchoter: 'Whisper',
    "Personne n'est en communication.": 'Nobody is on a call.',
    "Connecté à la file d'attente et prêt à prendre un appel.":
        'Logged into the queue and ready to take a call.',
    'Aucun agent libre.': 'No free agent.',
    'Pas disponible': 'Unavailable',
    "En attente de réponse, en pause ou déconnecté de la file d'attente.":
        'Ringing, on break or logged out of the queue.',
    'Tout le monde est soit libre, soit en communication.':
        'Everyone is either free or on a call.',
    "Détails de l'appel de l'agent": 'Agent call details',
    'Performances des appels du jour issues du CDR Asterisk. Les appels sont dédoublonnés par ID lié.':
        "Today's call performance from the Asterisk CDR. Calls are deduplicated by linked ID.",
    "Nombre total d'appels": 'Total calls',
    Répondue: 'Answered',
    Manquée: 'Missed',
    DMR: 'ASA',
    DMC: 'ATT',
    DMT: 'AHT',
    "Aucun enregistrement d'appel d'agent trouvé pour aujourd'hui.":
        'No agent call record found for today.',
    'Saisissez le code de récupération': 'Enter the recovery code',
    Continuer: 'Continue',
    ou: 'or',
    "Confirmer avec une clé d'accès": 'Confirm with a passkey',
    'Se connecter': 'Log in',
    'Mot de passe oublié ?': 'Forgot your password?',
    'Se souvenir de moi': 'Remember me',
    "Vous n'avez pas de compte ?": "Don't have an account?",
    "S'inscrire": 'Sign up',
    "Vérification de l'e-mail": 'Email verification',
    "Un nouveau lien de vérification a été envoyé à l'adresse e-mail indiquée lors de l'inscription.":
        'A new verification link has been sent to the email address you provided during registration.',
    "Renvoyer l'e-mail de vérification": 'Resend verification email',
    'Réinitialiser le mot de passe': 'Reset password',
    'E-mail': 'Email',
    Société: 'Company',
    'Créer un compte': 'Create an account',
    'Vous avez déjà un compte ?': 'Already have an account?',
    'Mot de passe oublié': 'Forgot password',
    'Envoyer le lien de réinitialisation': 'Email password reset link',
    'Ou retourner à': 'Or return to',
    'se connecter': 'log in',
    'Rechercher un numéro…': 'Search a number…',
    'Tous les statuts': 'All statuses',
    'Tous les agents': 'All agents',
    Appels: 'Calls',
    "L'historique des appels": 'Call history',
    Statut: 'Status',
    Du: 'From',
    Au: 'To',
    Réinitialiser: 'Reset',
    Enregistrement: 'Recording',
    'Aucun appel ne correspond à ces critères.':
        'No call matches these filters.',
    "Mes résultats d'appels": 'My call outcomes',
    'Les résultats saisis après chaque appel (50 derniers).':
        'The outcomes entered after each call (last 50).',
    'Aucun résultat pour le moment — il apparaîtra ici après votre prochain appel.':
        'No outcome yet — it will appear here after your next call.',
    Sens: 'Direction',
    Résultat: 'Outcome',
    Modifier: 'Edit',
    Supprimer: 'Delete',
    'Durée de la réunion': 'Meeting duration',
    'Informations sur la réunion': 'Meeting information',
    'Fermer le panneau': 'Close the panel',
    'Copier le lien': 'Copy the link',
    'Commandes de la réunion': 'Meeting controls',
    'Inviter des participants': 'Invite participants',
    'Plus d’options pour quitter': 'More options to leave',
    'Besoin de réunions plus longues ?': 'Need longer meetings?',
    'Les offres supérieures permettent des réunions jusqu’à 24 h, avec plus de participants.':
        'Higher plans allow meetings of up to 24 h, with more participants.',
    'Voir les offres': 'See plans',
    'Rejoindre à nouveau': 'Join again',
    'Retour aux réunions': 'Back to meetings',
    'Compte rendu': 'Minutes',
    'Notez les décisions et les actions : les participants les retrouvent dans la page Réunions.':
        'Write down decisions and actions: participants find them on the Meetings page.',
    '· Connexion…': '· Connecting…',
    'Activer le son de la réunion': 'Turn on meeting sound',
    'Vous êtes seul(e) pour le moment. Les invités ont reçu une notification avec le lien.':
        'You are alone for now. Invitees received a notification with the link.',
    'Inviter quelqu’un': 'Invite someone',
    '(vous)': '(you)',
    Horaire: 'Time',
    Organisateur: 'Organizer',
    'Lien de la réunion': 'Meeting link',
    'Réservé aux membres de l’équipe connectés.':
        'Team members only, signed in.',
    Inviter: 'Invite',
    Quitter: 'Leave',
    'Quitter la réunion': 'Leave the meeting',
    'Terminer pour tous': 'End for everyone',
    'Inviter à la réunion': 'Invite to the meeting',
    'Les membres sélectionnés reçoivent une notification avec un bouton « Rejoindre ».':
        'Selected members get a notification with a "Join" button.',
    Envoyer: 'Send',
    "Plus d'actions": 'More actions',
    'Réunissez vos agents et superviseurs en vidéo, depuis le navigateur.':
        'Bring your agents and supervisors together on video, from the browser.',
    Planifier: 'Schedule',
    'Réunion instantanée': 'Instant meeting',
    "Changez d'offre pour réunir votre équipe en vidéo, avec invitations agenda et notes partagées.":
        'Change plan to meet your team on video, with calendar invitations and shared notes.',
    "Changer d'offre": 'Change plan',
    "Demandez à l'administrateur de changer d'offre.":
        'Ask your administrator to change the plan.',
    "Le serveur vidéo (LiveKit) n'est pas encore configuré : vous pouvez planifier des réunions, mais pas encore les rejoindre.":
        'The video server (LiveKit) is not configured yet: you can schedule meetings, but not join them yet.',
    'En direct': 'Live',
    'À venir': 'Upcoming',
    'Aucune réunion planifiée': 'No scheduled meetings',
    'Planifiez un briefing ou une formation : vos participants reçoivent l’invitation par e-mail et un rappel avant le début.':
        'Schedule a briefing or a training: participants get the invitation by email and a reminder before it starts.',
    'Planifier une réunion': 'Schedule a meeting',
    'Préparer des notes': 'Prepare notes',
    'Annuler la réunion': 'Cancel the meeting',
    '30 derniers jours': 'Last 30 days',
    Enregistrée: 'Recorded',
    'Limite de durée atteinte': 'Duration limit reached',
    'Prochaine réunion': 'Next meeting',
    'Rien de prévu': 'Nothing planned',
    'Les réunions planifiées où vous êtes invité(e) apparaîtront ici avec un compte à rebours.':
        'Scheduled meetings you are invited to will appear here with a countdown.',
    'Bon à savoir': 'Good to know',
    'Les invités reçoivent l’invitation par e-mail, compatible Gmail, Outlook et Apple Calendar.':
        'Invitees get the invitation by email, compatible with Gmail, Outlook and Apple Calendar.',
    'Un rappel arrive 10 minutes avant le début, puis une notification quand la réunion commence.':
        'A reminder arrives 10 minutes before the start, then a notification when the meeting starts.',
    'Les agents passent en « Non prêt » pendant la réunion : les appels ne sonnent pas.':
        'Agents go "Not ready" during the meeting: calls do not ring.',
    'Partagées avec les participants de la réunion.':
        'Shared with the meeting participants.',
    'Les participants sont prévenus par notification et par e-mail ; la réunion est retirée de leur agenda.':
        'Participants are notified in the app and by email; the meeting is removed from their calendar.',
    'Garder la réunion': 'Keep the meeting',
    'Tous les états': 'All states',
    'Temps en pause': 'Time on break',
    'Pause moyenne': 'Average break',
    'Plus longue pause': 'Longest break',
    "Chaque changement d'état des agents : pauses et motifs, post-appel, ne pas déranger, déconnexions.":
        'Every agent state change: breaks and reasons, after-call work, do not disturb, logouts.',
    État: 'State',
    'Période :': 'Period:',
    "Aujourd'hui": 'Today',
    '7 jours': '7 days',
    '30 jours': '30 days',
    'Temps par état': 'Time per state',
    'Limité à la période choisie. Cliquez sur un agent pour voir son détail.':
        'Limited to the selected period. Click an agent to see the details.',
    Pauses: 'Breaks',
    'Plus longue': 'Longest',
    'Aucune activité sur cette période.': 'No activity in this period.',
    'Temps passé en « Non prêt » par motif.':
        'Time spent "Not ready" by reason.',
    'Aucune pause sur cette période.': 'No break in this period.',
    "Détail des changements d'état": 'State change details',
    'Du plus récent au plus ancien.': 'Most recent first.',
    Motif: 'Reason',
    Début: 'Start',
    Fin: 'End',
    "Aucun changement d'état ne correspond à ces critères.":
        'No state change matches these filters.',
    'Offre, paiements Mobile Money / carte et utilisation de votre équipe':
        "Plan, Mobile Money / card payments and your team's usage",
    'Période de facturation': 'Billing period',
    'Paiement reçu': 'Payment received',
    'Paiement en attente de confirmation': 'Payment awaiting confirmation',
    "Validez l'opération sur votre téléphone si on vous le demande. La confirmation peut prendre quelques minutes.":
        'Approve the transaction on your phone if asked. Confirmation can take a few minutes.',
    Actualiser: 'Refresh',
    "Le paiement n'a pas abouti": 'The payment did not go through',
    "Aucun montant n'a été débité. Vous pouvez réessayer ci-dessous.":
        'Nothing was charged. You can try again below.',
    'Les invitations en attente comptent dans les places. Propriétaires et administrateurs ne sont pas comptés.':
        'Pending invitations count toward seats. Owners and administrators are not counted.',
    'Choisir et payer une période': 'Choose and pay for a period',
    Actuelle: 'Current',
    '/ mois': '/ month',
    'Le temps restant sur votre offre actuelle sera converti en jours supplémentaires.':
        'The time left on your current plan will be converted into extra days.',
    'Payer avec Mobile Money ou carte': 'Pay with Mobile Money or card',
    "Le paiement en ligne n'est pas encore configuré sur ce serveur.":
        'Online payment is not configured on this server yet.',
    'Historique des paiements': 'Payment history',
    Offre: 'Plan',
    Montant: 'Amount',
    Moyen: 'Method',
    Référence: 'Reference',
    'Les motifs proposés aux agents sous « Non prêt » dans leur menu de statut. Ils apparaissent dans la supervision et dans les rapports de temps de pause.':
        'The reasons offered to agents under "Not ready" in their status menu. They appear in monitoring and in break time reports.',
    'Nouveau motif, ex. Rappel client': 'New reason, e.g. Customer callback',
    'Nouveau motif': 'New reason',
    'Nom du motif': 'Reason name',
    Ajouter: 'Add',
    'Aucun motif : les agents ne verront que « Non prêt ».':
        'No reason: agents will only see "Not ready".',
    '« Prêt », « Non prêt », « Traitement post-appel », « Ne pas déranger » et « Déconnexion » sont toujours proposés. Raccourcis agents : Ctrl+Alt+R (Prêt), Ctrl+Alt+N (Non prêt), Ctrl+Alt+Z (Post-appel).':
        '"Ready", "Not ready", "After-call work", "Do not disturb" and "Logged out" are always offered. Agent shortcuts: Ctrl+Alt+R (Ready), Ctrl+Alt+N (Not ready), Ctrl+Alt+Z (After-call work).',
    "Tout ce qui concerne une file au même endroit : réglages, agents, horaires, ce qui se passe quand personne ne répond, et l'enquête après l'appel.":
        'Everything about a queue in one place: settings, agents, hours, what happens when nobody answers, and the post-call survey.',
    'Support, Ventes…': 'Support, Sales…',
    'Réglages de la file': 'Queue settings',
    '0 = illimité': '0 = unlimited',
    Illimitée: 'Unlimited',
    '0 = jamais': '0 = never',
    'Nouvelle file': 'New queue',
    Créer: 'Create',
    "Aucune file pour l'instant.": 'No queue yet.',
    fermée: 'closed',
    campagne: 'campaign',
    "Qui reçoit l'appel ?": 'Who gets the call?',
    'Sonnerie par agent (s)': 'Ring time per agent (s)',
    "Pause avant l'agent suivant (s)": 'Pause before the next agent (s)',
    'Temps de qualification après un appel (s)':
        'Wrap-up time after a call (s)',
    'Appelants en attente max.': 'Max waiting callers',
    "Musique d'attente": 'Music on hold',
    'Sonner un agent déjà en ligne': 'Ring an agent already on a call',
    Non: 'No',
    Oui: 'Yes',
    'Options avancées': 'Advanced options',
    'Accepter les appels sans agent connecté':
        'Accept calls with no agent logged in',
    "Oui (l'appelant attend)": 'Yes (the caller waits)',
    'Non (débordement immédiat)': 'No (overflow at once)',
    'Faire sortir si plus aucun agent': 'Remove callers when no agent is left',
    'Oui (débordement)': 'Yes (overflow)',
    'Objectif de décroché (s)': 'Service level target (s)',
    'Supprimer la file': 'Delete queue',
    'Les agents « Secours » ne reçoivent un appel que si tous les agents « Principal » sont occupés, et « Dernier recours » seulement après eux.':
        '"Backup" agents only get a call when all "Primary" agents are busy, and "Last resort" agents only after them.',
    "Aucun agent dans l'équipe.": 'No agent in the team.',
    'Enregistrer les agents': 'Save agents',
    "Horaires d'ouverture": 'Opening hours',
    'Aucun jour coché : la file est ouverte 24 h/24.':
        'No day ticked: the queue is open 24/7.',
    'Enregistrer les horaires': 'Save hours',
    'Copier lundi sur toute la semaine (lun–ven)':
        'Copy Monday to the whole week (Mon–Fri)',
    'Jours de fermeture': 'Closure days',
    'Toutes les files': 'All queues',
    'Aucune fermeture prévue.': 'No closure planned.',
    "Fermeture d'urgence": 'Emergency closure',
    'Fermer la file maintenant, quels que soient les horaires':
        'Close the queue now, whatever the hours',
    'Message joué': 'Message played',
    'Si personne ne répond': 'If nobody answers',
    'Attente maximale (s)': 'Maximum wait (s)',
    'Ensuite (ou si la file est pleine / sans agent)':
        'Then (or if the queue is full / has no agent)',
    'Proposer « tapez 1 pour être rappelé » avant cette étape et quand la file est fermée.':
        'Offer "press 1 to be called back" before this step and when the queue is closed.',
    "Pendant l'attente": 'While waiting',
    'Annoncer la position': 'Announce position',
    'Seulement au-delà de 5': 'Only beyond 5',
    "Annoncer le temps d'attente": 'Announce waiting time',
    'Une seule fois': 'Once only',
    'Répéter toutes les (s)': 'Repeat every (s)',
    'Message périodique': 'Periodic message',
    'Toutes les (s)': 'Every (s)',
    "Annonce chuchotée à l'agent": 'Whisper announcement to the agent',
    'Enregistrement des appels': 'Call recording',
    "Inclus dans les offres Pro et Entreprise. Changez d'offre depuis la page Abonnement.":
        'Included in the Pro and Entreprise plans. Change plan on the Subscription page.',
    'Enregistrer les conversations de cette file':
        'Record the conversations of this queue',
    "À réécouter dans l'historique des appels.":
        'Replay them in the call history.',
    'Les appels de campagne utilisant cette file sont aussi enregistrés.':
        'Campaign calls using this queue are recorded too.',
    "Prévenir l'appelant avant l'enregistrement":
        'Tell the caller before recording',
    "Enquête de satisfaction : quand l'agent raccroche, l'appelant note l'appel de 1 à 5.":
        'Satisfaction survey: when the agent hangs up, the caller rates the call from 1 to 5.',
    "Le temps de qualification accordé à l'agent se règle dans l'onglet Général.":
        'The wrap-up time given to the agent is set in the General tab.',
    'Numéros entrants': 'Inbound numbers',
    'Votre numéro, ex. 22320201010': 'Your number, e.g. 22320201010',
    'Remplacer par votre numéro': 'Replace with your number',
    'Menus vocaux (SVI)': 'Voice menus (IVR)',
    "« Pour les ventes, tapez 1… ». Sans réponse ou touche invalide, le message est répété, puis l'appel part vers la destination par défaut.":
        '"For sales, press 1…". With no answer or a wrong key, the message is repeated, then the call goes to the default destination.',
    Libellé: 'Label',
    'Numéro de la plateforme': 'Platform number',
    'Obtenir un numéro': 'Get a number',
    'Même libellé, même routage. Le numéro de la plateforme est rendu. Votre numéro doit être porté ou renvoyé vers notre opérateur pour sonner ici.':
        'Same label, same routing. The platform number is given back. Your number must be ported or forwarded to our carrier to ring here.',
    'Votre numéro': 'Your number',
    Remplacer: 'Replace',
    "Aucun numéro pour l'instant.": 'No number yet.',
    'Aucun menu vocal.': 'No voice menu.',
    "Message d'accueil": 'Welcome message',
    'Attente touche (s)': 'Key timeout (s)',
    Répétitions: 'Repeats',
    'Message « touche invalide » (facultatif)':
        '"Invalid key" message (optional)',
    Touches: 'Keys',
    'Ajouter une touche': 'Add a key',
    'Sans choix valide': 'No valid choice',
    "Ce qui arrive à un appel, du numéro composé jusqu'à l'agent. Généré à partir des pages Files d'attente et Numéros et SVI : rien à écrire ici.":
        'What happens to a call, from the dialled number to the agent. Generated from the Queues and Numbers and IVR pages: nothing to write here.',
    "Aucun numéro entrant n'est configuré.": 'No inbound number is configured.',
    'Ajouter un numéro': 'Add a number',
    'pour que les appels de votre opérateur arrivent dans vos files.':
        "so your carrier's calls reach your queues.",
    'Non reliés à un numéro': 'Not linked to a number',
    'Joignables uniquement par transfert ou depuis un autre menu.':
        'Reachable only by transfer or from another menu.',
    'Mode expert : lignes de dialplan': 'Expert mode: dialplan lines',
    "Pour les besoins non couverts par les pages ci-dessus. Les lignes grisées sont écrites par l'application (appels internes, files, menus) et ne se modifient pas ici. Contexte :":
        'For needs the pages above do not cover. Greyed lines are written by the app (internal calls, queues, menus) and cannot be changed here. Context:',
    Contexte: 'Context',
    Poste: 'Extension',
    Priorité: 'Priority',
    "Géré par l'application": 'Managed by the app',
    'Paramètres PJSIP': 'PJSIP settings',
    'Postes et points de terminaison PJSIP': 'PJSIP extensions and endpoints',
    "Chaque poste de l'équipe a un point de terminaison Asterisk {clé}_{poste} dans le contexte de l'équipe. Attribuez-le à un membre pour qu'il puisse utiliser le softphone.":
        'Each team extension has an Asterisk endpoint {key}_{extension} in the team context. Assign it to a member so they can use the softphone.',
    "La table ps_endpoints n'a pas de colonne accountcode : les appels ne peuvent pas être rattachés à l'équipe dans le CDR (statistiques et historique vides). Ajoutez la colonne (schéma realtime Asterisk récent) puis enregistrez de nouveau les postes.":
        'The ps_endpoints table has no accountcode column: calls cannot be linked to the team in the CDR (empty statistics and history). Add the column (recent Asterisk realtime schema), then save the extensions again.',
    Membre: 'Member',
    'Codecs (autorisés)': 'Codecs (allowed)',
    'Contacts max.': 'Max contacts',
    "Absent d'Asterisk — enregistrez pour le créer":
        'Missing in Asterisk — save to create it',
    'Créé hors application': 'Created outside the app',
    'Articles d’aide': 'Help articles',
    'Article précédent et suivant': 'Previous and next article',
    'Sur cette page': 'On this page',
    'Envie de voir tout cela en vrai ? La visite guidée vous montre chaque zone de l’écran.':
        'Want to see it for real? The guided tour shows you every area of the screen.',
    'Lancer la visite guidée': 'Start the guided tour',
    Suivant: 'Next',
    'Centre d’aide': 'Help center',
    'Tout pour prendre en main l’application : appels, statuts, supervision, campagnes et réglages.':
        'Everything to get started with the app: calls, statuses, monitoring, campaigns and settings.',
    'Pour bien démarrer': 'Getting started',
    Étapes: 'Steps',
    'Nombre d’employés': 'Number of employees',
    'Numéro d’identification fiscale': 'Tax identification number',
    'Bienvenue sur votre centre d’appels': 'Welcome to your call center',
    'Trois étapes rapides : présentez votre entreprise, puis nous vous montrons comment recevoir vos premiers appels.':
        'Three quick steps: tell us about your company, then we show you how to receive your first calls.',
    'Votre entreprise': 'Your company',
    'Ces informations nous aident à vous proposer les bons réglages et l’accompagnement adapté.':
        'This helps us suggest the right settings and the right support.',
    'Comment utiliserez-vous l’application ?': 'How will you use the app?',
    'Nom de l’organisation *': 'Organization name *',
    'Le nom affiché à vos agents dans l’application.':
        'The name your agents see in the app.',
    'Secteur d’activité *': 'Industry *',
    'Taille de l’entreprise *': 'Company size *',
    'Nombre d’agents prévus': 'Planned agents',
    'Passer pour l’instant': 'Skip for now',
    'Pour vous joindre et préparer vos factures.':
        'To reach you and prepare your invoices.',
    'Votre organisation est prête': 'Your organization is ready',
    Fait: 'Done',
    'Faites le tour de l’application': 'Take a tour of the app',
    'Une visite guidée de deux minutes vous attend sur le tableau de bord.':
        'A two-minute guided tour is waiting on the dashboard.',
    'Découvrir l’application': 'Discover the app',
    "Tableau de bord de l'équipe": 'Team dashboard',
    "Appels aujourd'hui": 'Calls today',
    'Durée moy. de conversation': 'Avg. talk time',
    'Satisfaction (7 j)': 'Satisfaction (7 d)',
    'Rappels en attente': 'Waiting callbacks',
    "Les performances de votre centre d'appels, la composition de l'équipe et l'état de la configuration.":
        "Your call center's performance, the team's make-up and the state of the setup.",
    'Supervision en direct': 'Live monitoring',
    'Ma vue agent': 'My agent view',
    "Gérer l'équipe": 'Manage the team',
    '7 derniers jours': 'Last 7 days',
    "Agents aujourd'hui": 'Agents today',
    "Les appels répondus et manqués sont ceux qui ont sonné sur le téléphone de l'agent.":
        "Answered and missed calls are the ones that rang on the agent's phone.",
    Équipe: 'Team',
    Files: 'Queues',
    'Les files auxquelles vos agents répondent.':
        'The queues your agents answer.',
    'À surveiller': 'To watch',
    "Aucun agent n'a encore de poste et de file : aucun appel ne peut être pris.":
        'No agent has an extension and a queue yet: no call can be taken.',
    'Aucun poste SIP': 'No SIP extension',
    'Aucune file assignée': 'No queue assigned',
    "Corriger dans les paramètres de l'équipe": 'Fix it in the team settings',
    'Tableau de bord superviseur': 'Supervisor dashboard',
    'Attente la plus longue': 'Longest wait',
    "L'activité en ce moment et la performance de l'équipe aujourd'hui.":
        "What is happening now and the team's performance today.",
    'Historique des appels': 'Call history',
    'En ce moment': 'Right now',
    'Les données Asterisk en direct sont indisponibles ; les appels en attente et en cours peuvent être incomplets.':
        'Live Asterisk data is unavailable; waiting and ongoing calls may be incomplete.',
    'Qui est connecté et ce que chaque agent a traité.':
        'Who is logged in and what each agent has handled.',
    'Appels à suivre': 'Calls to follow up',
    "Personne n'a répondu à ceux-ci aujourd'hui.":
        'Nobody answered these today.',
    "Aucun appel manqué aujourd'hui.": 'No missed call today.',
    'Temps de conversation': 'Talk time',
    "Votre journée en un coup d'œil : appels, rappels et réunions.":
        'Your day at a glance: calls, callbacks and meetings.',
    "Vue d'équipe": 'Team view',
    'Passer un appel': 'Make a call',
    'Aucune extension attribuée': 'No extension assigned',
    "Vos statistiques d'appels apparaîtront ici dès qu'un administrateur vous aura attribué une extension.":
        'Your call statistics will appear here once an administrator assigns you an extension.',
    'À rappeler': 'To call back',
    Rappeler: 'Call back',
    'Appels récents': 'Recent calls',
    'Les derniers appels reçus sur votre poste.':
        'The latest calls received on your extension.',
    "Aucun appel aujourd'hui": 'No call today',
    'Les appels que vous traitez apparaîtront ici.':
        'The calls you handle will appear here.',
    'Ne pas appeler': 'Do not call',
    'Liste « Ne pas appeler »': '"Do not call" list',
    'Ces numéros ne sont jamais composés, quelle que soit la campagne. Les contacts qui demandent à ne plus être appelés y sont ajoutés automatiquement.':
        'These numbers are never dialled, whatever the campaign. Contacts who ask not to be called again are added automatically.',
    'Rechercher un numéro': 'Search a number',
    'Ajouter des numéros': 'Add numbers',
    'Un numéro par ligne': 'One number per line',
    'Motif (facultatif)': 'Reason (optional)',
    Rechercher: 'Search',
    'Ajouté par': 'Added by',
    'Aucun numéro.': 'No number.',
    'Poste agent — campagnes': 'Agent desk — campaigns',
    'Poste agent': 'Agent desk',
    'Vos campagnes, la fiche du contact, le script et la qualification des appels.':
        'Your campaigns, the contact card, the script and call wrap-up.',
    Qualifiés: 'Qualified',
    "Les campagnes ne sont pas incluses dans l'offre de votre équipe.":
        "Campaigns are not included in your team's plan.",
    'Aucun poste SIP ne vous est attribué : demandez à votre administrateur.':
        'No SIP extension is assigned to you: ask your administrator.',
    'Mes campagnes': 'My campaigns',
    'Restez disponible dans le softphone pour recevoir les appels automatiques.':
        'Stay available in the softphone to receive automatic calls.',
    "Hors horaires d'appel": 'Outside calling hours',
    'Contact suivant': 'Next contact',
    'Les appels arrivent automatiquement sur votre softphone.':
        'Calls reach your softphone automatically.',
    'Aucun appel ne partira tant que :': 'No call will go out until:',
    'Aucune campagne en cours ne vous est affectée.':
        'No running campaign is assigned to you.',
    'Mes rappels': 'My callbacks',
    Entreprise: 'Company',
    Tentatives: 'Attempts',
    'Décrochez votre softphone : le client est appelé dès que vous répondez.':
        'Pick up your softphone: the customer is called as soon as you answer.',
    Qualification: 'Wrap-up',
    'Aucun contact en cours. Choisissez « Contact suivant » dans une campagne en mode aperçu, ou restez disponible pour les appels automatiques.':
        'No current contact. Choose "Next contact" in a preview campaign, or stay available for automatic calls.',
    'Les réglages sont modifiables à tout moment, même pendant la campagne.':
        'Settings can be changed at any time, even during the campaign.',
    Illimité: 'Unlimited',
    'custom/campagne-message (vide = raccrocher)':
        'custom/campagne-message (empty = hang up)',
    Général: 'General',
    'Objectif (interne)': 'Goal (internal)',
    'Mode de numérotation': 'Dialing mode',
    'Comme Genesys et RingCX : aperçu, progressif ou power.':
        'Like Genesys and RingCX: preview, progressive or power.',
    'Lignes par agent libre': 'Lines per free agent',
    "Taux d'abandon max (%)": 'Max abandon rate (%)',
    'Lignes simultanées max': 'Max simultaneous lines',
    'Ligne sortante': 'Outbound line',
    'Les appels sortent par la ligne opérateur de la plateforme.':
        "Calls go out through the platform's carrier line.",
    'Préfixe de numérotation': 'Dial prefix',
    'Ajouté devant le numéro international (ex. 00 → 0022376…).':
        'Added before the international number (e.g. 00 → 0022376…).',
    'Numéro présenté': 'Caller ID',
    'Sonnerie (s)': 'Ring time (s)',
    'Attente agent (s)': 'Agent wait (s)',
    'Détecter les répondeurs (AMD) — modes progressif et power':
        'Detect answering machines (AMD) — progressive and power modes',
    'Message déposé sur répondeur': 'Message left on voicemail',
    'Message si aucun agent libre': 'Message if no agent is free',
    'Temps de qualification (s)': 'Wrap-up time (s)',
    'Tentatives et horaires': 'Attempts and hours',
    "Délai avant de rappeler un contact, selon le résultat de l'appel.":
        'Delay before calling a contact again, by call outcome.',
    'Tentatives max par contact': 'Max attempts per contact',
    "Jours d'appel": 'Calling days',
    De: 'From',
    'Fuseau horaire': 'Time zone',
    'Ils reçoivent les appels de la campagne en plus de leurs files entrantes (mode mixte).':
        "They get the campaign's calls on top of their inbound queues (blended mode).",
    'Codes de qualification': 'Wrap-up codes',
    "Choisis par l'agent après chaque appel. L'action décide de la suite pour le contact.":
        'Chosen by the agent after each call. The action decides what happens next for the contact.',
    'Ajouter un code': 'Add a code',
    'Script agent': 'Agent script',
    'Liste traitée': 'List done',
    'Taux de joignabilité': 'Contact rate',
    'Conversation moy.': 'Avg. talk',
    'Fichier CSV': 'CSV file',
    Prénom: 'First name',
    'Nom, téléphone, entreprise': 'Name, phone, company',
    Lancer: 'Start',
    Terminer: 'Complete',
    'Export CSV': 'CSV export',
    'Résultats des appels': 'Call results',
    "Aucun appel pour l'instant.": 'No call yet.',
    Qualifications: 'Wrap-ups',
    Joints: 'Reached',
    Succès: 'Success',
    'Aucun agent affecté.': 'No agent assigned.',
    'Importer un fichier CSV': 'Import a CSV file',
    'Colonnes reconnues : telephone, prenom, nom, entreprise, email, priorite. Les autres colonnes sont gardées pour le script. Séparateur virgule ou point-virgule.':
        'Recognized columns: telephone, prenom, nom, entreprise, email, priorite. Other columns are kept for the script. Comma or semicolon separator.',
    Importer: 'Import',
    'Ajouter un contact': 'Add a contact',
    Filtrer: 'Filter',
    'Recycler les tentatives épuisées': 'Recycle exhausted attempts',
    'Tent.': 'Att.',
    'Dernier résultat': 'Last result',
    'Prochain appel': 'Next call',
    'Supprimer la campagne': 'Delete campaign',
    'Campagnes sortantes': 'Outbound campaigns',
    'Prospection, relances, enquêtes : listes de contacts, numérotation automatique et qualification des appels.':
        'Prospecting, follow-ups, surveys: contact lists, automatic dialing and call wrap-up.',
    'Nouvelle campagne': 'New campaign',
    'Les campagnes sortantes sont incluses dans les offres Pro et Entreprise.':
        'Outbound campaigns are included in the Pro and Entreprise plans.',
    'Créez une campagne, importez un fichier CSV de contacts, affectez vos agents et lancez la numérotation.':
        'Create a campaign, import a CSV file of contacts, assign your agents and start dialing.',
    Campagne: 'Campaign',
    Avancement: 'Progress',
    'Rappels entrants': 'Inbound callbacks',
    'hors horaires': 'outside hours',
    "Une erreur s'est produite.": 'Something went wrong.',
    'Impossible de charger les files.': 'Could not load the queues.',
    Dépôt: 'Voicemail drop',
    'Choix SVI': 'IVR choice',
    'Dernier appel': 'Last call',
    'Manqués (90 j)': 'Missed (90 d)',
    Résolu: 'Resolved',
    'Rappel planifié': 'Scheduled callback',
    'Pas de vente': 'No sale',
    'Mauvais numéro': 'Wrong number',
    'En appel': 'On a call',
    'Appel sortant': 'Outbound call',
    'Connexion…': 'Connecting…',
    'Hors ligne': 'Offline',
    'Authentification à deux facteurs activée':
        'Two-factor authentication enabled',
    "Vérifier le code d'authentification": 'Verify authentication code',
    "Activer l'authentification à deux facteurs":
        'Enable two-factor authentication',
    'Notes copiées.': 'Notes copied.',
    'Copie impossible.': 'Copy failed.',
    'Ouvrir la fiche': 'Open the record',
    'Identifiant Asterisk': 'Asterisk identifier',
    'Clé unique attribuée à la création.': 'Unique key assigned at creation.',
    'Préfixe des postes SIP': 'SIP extension prefix',
    'Code de compte (CDR)': 'Account code (CDR)',
    "Contexte du plan d'appel": 'Dial plan context',
    "Contexte de tous les postes et files de l'organisation.":
        "Context of all the organization's extensions and queues.",
    "Rattache chaque appel du CDR à l'organisation.":
        'Links every CDR call to the organization.',
    "Identifiant interne de l'application.": 'Internal identifier of the app.',
    'Code de récupération': 'Recovery code',
    "Code d'authentification": 'Authentication code',
    'Connexion à votre compte': 'Log in to your account',
    'Saisissez votre nouveau mot de passe ci-dessous':
        'Enter your new password below',
    'Supprimer ce résultat ?': 'Delete this outcome?',
    Répondu: 'Answered',
    'Sans réponse': 'No answer',
    Occupé: 'Busy',
    Échoué: 'Failed',
    'Statut : Non prêt – Réunion vidéo': 'Status: Not ready – Video meeting',
    'Vous êtes toujours en Non prêt – Réunion vidéo':
        'You are still Not ready – Video meeting',
    'Lien de la réunion copié.': 'Meeting link copied.',
    'Invitations envoyées : une notification avec le lien est arrivée chez chacun.':
        'Invitations sent: everyone received a notification with the link.',
    'Enregistrement arrêté.': 'Recording stopped.',
    'Enregistrement démarré.': 'Recording started.',
    'Repassez en Prêt pour recevoir à nouveau les appels.':
        'Switch back to Ready to receive calls again.',
    'Repasser Prêt': 'Back to Ready',
    'La réunion est terminée': 'The meeting has ended',
    'L’organisateur a terminé la réunion pour tous.':
        'The organizer ended the meeting for everyone.',
    'Tout le monde est parti.': 'Everyone has left.',
    'Vous avez été déconnecté(e)': 'You were disconnected',
    'La connexion au serveur vidéo a été perdue. Vérifiez votre connexion internet, puis rejoignez à nouveau.':
        'The connection to the video server was lost. Check your internet connection, then join again.',
    "Le statut n'a pas pu être modifié.": 'The status could not be changed.',
    "Les invitations n'ont pas pu être envoyées.":
        'The invitations could not be sent.',
    'Copie impossible : sélectionnez le lien dans la réunion.':
        'Copy failed: select the link in the meeting.',
    Déconnecté: 'Logged out',
    "Files d'appels": 'Call queues',
    'Horaires et fermetures': 'Hours and closures',
    'Attente et débordement': 'Waiting and overflow',
    'Enregistrement et enquête': 'Recording and survey',
    'Dernier recours': 'Last resort',
    'Non attribué': 'Unassigned',
    'Premiers pas': 'Getting started',
    'Centre d’appels': 'Call center',
    'Files d’attente, numéros, campagnes, supervision, plus les appels internes et les réunions.':
        'Queues, numbers, campaigns, monitoring, plus internal calls and meetings.',
    'Appels entre collègues et réunions vidéo uniquement. Modifiable plus tard.':
        'Calls between colleagues and video meetings only. Can be changed later.',
    'Créer les postes des agents': "Create the agents' extensions",
    'Chaque agent a un poste (7001, 7002…) pour utiliser le softphone. Le plus simple : « Créer un membre » dans Organisation.':
        'Each agent has an extension (7001, 7002…) to use the softphone. The easiest way: "Create a member" in Organization.',
    'Créer une file d’attente': 'Create a queue',
    'Une file par service (Support, Ventes…) : elle fait patienter les appelants et distribue les appels.':
        'One queue per department (Support, Sales…): it keeps callers waiting and distributes the calls.',
    'Files d’attente': 'Queues',
    'Brancher un numéro': 'Connect a number',
    'Indiquez où arrive chaque numéro de votre opérateur : une file, un menu vocal ou un poste.':
        'Say where each number of your carrier lands: a queue, a voice menu or an extension.',
    'Inviter votre équipe': 'Invite your team',
    'Créez les comptes de vos agents et superviseurs, ou invitez-les par e-mail.':
        'Create the accounts of your agents and supervisors, or invite them by email.',
    'Bienvenue 👋': 'Welcome 👋',
    'Cette visite de deux minutes vous montre où se trouve chaque chose. Utilisez les flèches du clavier ou les boutons pour avancer, Échap pour quitter.':
        'This two-minute tour shows you where everything is. Use the arrow keys or the buttons to move on, Esc to leave.',
    'Votre point de départ : les chiffres du jour et les appels récents, adaptés à votre rôle.':
        "Your starting point: today's figures and recent calls, adapted to your role.",
    'Votre statut': 'Your status',
    'Prêt, vous recevez les appels des files. Non prêt (avec un motif), Post-appel ou Ne pas déranger, ils ne vous sont plus présentés. Raccourcis : Ctrl+Alt+R, N et Z.':
        'Ready, you receive queue calls. Not ready (with a reason), After-call work or Do not disturb, they are no longer offered to you. Shortcuts: Ctrl+Alt+R, N and Z.',
    'Le softphone': 'The softphone',
    'Votre téléphone, dans le navigateur. Le point vert indique que le poste est connecté. Ouvrez-le pour composer un numéro ; les appels entrants s’affichent ici.':
        'Your phone, in the browser. The green dot means the extension is connected. Open it to dial a number; incoming calls show up here.',
    'Réunions, planning (nouveaux postes, échanges, congés) et abonnement : tout arrive ici, en temps réel. Choisissez ce que vous recevez par e-mail dans Paramètres → Notifications.':
        'Meetings, planning (new shifts, swaps, leave) and subscription: everything arrives here, in real time. Choose what you also get by email in Settings → Notifications.',
    'Clair ou sombre': 'Light or dark',
    'Un clic passe du thème clair au thème sombre, puis au réglage de votre appareil.':
        'One click switches from the light theme to the dark one, then to your device setting.',
    'Tous les appels, avec recherche par numéro, enregistrements et vos résultats d’appels.':
        'All calls, with number search, recordings and your call outcomes.',
    'Les files et les agents en temps réel : appels en attente, agents libres ou en pause. Écoutez ou chuchotez sur un appel en cours.':
        'Queues and agents in real time: waiting calls, free or paused agents. Listen or whisper on an ongoing call.',
    'Le temps passé par chaque agent dans chaque statut et par motif de pause.':
        'The time each agent spent in each status and per break reason.',
    'Les appels sortants : listes de contacts, script et qualification. Les demandes de rappel des clients arrivent aussi ici.':
        'Outbound calls: contact lists, script and wrap-up. Customer callback requests also land here.',
    'Réunions vidéo': 'Video meetings',
    'Briefings et formations en visio. En rejoignant une réunion, un agent passe automatiquement en Non prêt.':
        'Video briefings and trainings. When joining a meeting, an agent automatically goes Not ready.',
    'Votre organisation': 'Your organization',
    'Si vous travaillez pour plusieurs organisations, passez de l’une à l’autre ici.':
        'If you work for several organizations, switch from one to the other here.',
    'Votre profil (photo, fonction, langue français ou anglais), vos notifications et votre sécurité. Les administrateurs y règlent aussi les membres, les postes, les numéros, le SVI et l’abonnement.':
        'Your profile (photo, job title, French or English language), your notifications and your security. Administrators also set members, extensions, numbers, the IVR and the subscription here.',
    'Besoin d’aide ?': 'Need help?',
    'Le centre d’aide explique chaque fonction pas à pas. Vous pourrez y relancer cette visite à tout moment.':
        'The help center explains every feature step by step. You can restart this tour there at any time.',
    'Briefings et formations en visio : planifiez, invitez vos collègues et partagez votre écran. La qualité vidéo (jusqu’à 1080p) dépend de votre formule.':
        'Video briefings and trainings: schedule, invite your colleagues and share your screen. Video quality (up to 1080p) depends on your plan.',
    'Votre planning de la semaine, le pointage, les échanges de postes avec vos collègues, votre feuille de temps et vos congés.':
        'Your schedule for the week, clocking in and out, shift swaps with your colleagues, your timesheet and your leave.',
    'Le roster de l’équipe en semaine ou en mois, avec la couverture par poste. Vous y validez les échanges, les congés et les feuilles de temps, et exportez en Excel ou PDF.':
        'The team roster by week or month, with coverage per shift. Approve swaps, leave and timesheets here, and export to Excel or PDF.',
    ":p0 pour rejoindre l'organisation « :p1 ».":
        ':p0 to join the organization ":p1".',
    'Ajoutée :p0': 'Added :p0',
    'Dernière utilisation :p0': 'Last used :p0',
    "Voulez-vous vraiment supprimer la clé d'accès « :p0 » ? Vous ne pourrez plus l'utiliser pour vous connecter.":
        'Are you sure you want to delete the passkey ":p0"? You will no longer be able to use it to sign in.',
    ':p0 vous a invité à rejoindre cette organisation.':
        ':p0 invited you to join this organization.',
    'Poste :p0 · :p1': 'Extension :p0 · :p1',
    'Rappel · :p0': 'Callback · :p0',
    'Sortant · Campagne :p0': 'Outbound · Campaign :p0',
    'Non prêt – :p0': 'Not ready – :p0',
    'Échéance dépassée depuis le :p0. Renouvelez pour éviter la lecture seule.':
        'Payment overdue since :p0. Renew to avoid read-only mode.',
    'Votre abonnement :p0 se termine le :p1.':
        'Your :p0 subscription ends on :p1.',
    'Profil complété le :p0': 'Profile completed on :p0',
    ':p0 vient de modifier les notes.': ':p0 just changed the notes.',
    'et :p0 autre(s)': 'and :p0 other(s)',
    'Offre :p0 : :p1 maximum par réunion ; la salle se ferme à la fin du temps.':
        ':p0 plan: :p1 maximum per meeting; the room closes when the time is up.',
    'Durée maximale : :p0 (offre :p1).': 'Maximum duration: :p0 (:p1 plan).',
    'Appel de campagne : :p0': 'Campaign call: :p0',
    'Modifier :p0': 'Edit :p0',
    'Voir :p0': 'View :p0',
    'Ex. :p07001 pour le poste 7001.': 'e.g. :p07001 for extension 7001.',
    'Appels en attente, agents en communication et agents disponibles. :p0':
        'Waiting calls, agents on a call and available agents. :p0',
    'depuis :p0': 'for :p0',
    ':p0–:p1 sur :p2 appels': ':p0–:p1 of :p2 calls',
    ':p0–:p1 sur :p2 codifications': ':p0–:p1 of :p2 outcomes',
    'Fin dans :p0': 'Ends in :p0',
    'Dans la réunion (:p0)': 'In the meeting (:p0)',
    'Invités, pas encore là (:p0)': 'Invited, not here yet (:p0)',
    'La réunion se termine dans :p0 minutes': 'The meeting ends in :p0 minutes',
    'Durée maximale de l’offre :p0 : :p1.':
        'Maximum duration of the :p0 plan: :p1.',
    "Non inclus dans l'offre :p0": 'Not included in the :p0 plan',
    'Organisée par :p0': 'Organized by :p0',
    'Annuler « :p0 » ?': 'Cancel ":p0"?',
    '(:p0 jours maximum)': '(:p0 days maximum)',
    ':p0–:p1 sur :p2 changements': ':p0–:p1 of :p2 changes',
    ":p0 — votre abonnement est payé jusqu'au :p1.":
        ':p0 — your subscription is paid until :p1.',
    'Offre :p0': ':p0 plan',
    "Essai gratuit jusqu'au :p0.": 'Free trial until :p0.',
    "Payé jusqu'au :p0 (:p1).": 'Paid until :p0 (:p1).',
    'Échéance dépassée le :p0. Renouvelez avant le :p1 pour éviter la lecture seule.':
        'Payment overdue since :p0. Renew before :p1 to avoid read-only mode.',
    "Compte en lecture seule depuis le :p0. Les agents ne peuvent plus prendre d'appels ; vos données sont conservées.":
        'Account read-only since :p0. Agents can no longer take calls; your data is kept.',
    "Inclus dans l'offre :p0": 'Included in the :p0 plan',
    'Agents supplémentaires (+:p0 / agent / mois)':
        'Extra agents (+:p0 / agent / month)',
    "Jusqu'à :p0 participants par réunion":
        'Up to :p0 participants per meeting',
    'Nom Asterisk : :p0': 'Asterisk name: :p0',
    "En dehors de ces horaires, l'appelant entend « :p0 ».":
        'Outside these hours, the caller hears ":p0".',
    'Les demandes arrivent dans le poste agent des agents de la file. Sons : :p0, :p1.':
        "Requests land in the agent desk of the queue's agents. Sounds: :p0, :p1.",
    'Conservés :p0 jours.': 'Kept for :p0 days.',
    'Son : :p0 (« Cet appel peut être enregistré… »). Recommandé : informer vos clients est en général une obligation légale.':
        'Sound: :p0 ("This call may be recorded…"). Recommended: informing your customers is usually a legal obligation.',
    'Sons : :p0, :p1. Résultats sur le tableau de bord.':
        'Sounds: :p0, :p1. Results on the dashboard.',
    'Remplacer :p0 par votre numéro': 'Replace :p0 with your number',
    '· défaut : :p0 ·': '· default: :p0 ·',
    '(:p0 ligne(s) personnalisée(s))': '(:p0 custom line(s))',
    'Mettons :p0 en place': "Let's set up :p0",
    'Voici les 4 étapes pour recevoir vos premiers appels (:p0/4 faites). Vous pouvez les faire maintenant ou plus tard : elles restent disponibles dans les Paramètres.':
        'Here are the 4 steps to receive your first calls (:p0/4 done). Do them now or later: they stay available in Settings.',
    'Votre essai gratuit de la formule :p0 court jusqu’au :p1.':
        'Your free trial of the :p0 plan runs until :p1.',
    ':p0 appels, :p1 % répondus, :p2 manqués.':
        ':p0 calls, :p1 % answered, :p2 missed.',
    ':p0 membres': ':p0 members',
    'poste :p0': 'extension :p0',
    'En direct : :p0 en numérotation, :p1 en conversation, ratio :p2.':
        'Live: :p0 dialing, :p1 talking, ratio :p2.',
    Réduire: 'Minimize',
    'Nouvel appel': 'New call',
    'Réactiver le micro': 'Unmute',
    'Couper le micro': 'Mute',
    'Mettre en attente': 'Hold',
    'Code de qualification': 'Wrap-up code',
    "Résultat de l'appel": 'Call outcome',
    'Softphone connecté': 'Softphone connected',
    'Softphone hors ligne : le statut s’appliquera à la reconnexion':
        'Softphone offline: the status will apply when it reconnects',
    'Masquer le mot de passe': 'Hide password',
    'Afficher le mot de passe': 'Show password',
    'Profil complet': 'Profile complete',
    'Un collègue': 'A colleague',
    'Décisions, actions, qui fait quoi… Les notes sont partagées avec les participants et enregistrées automatiquement.':
        'Decisions, actions, who does what… Notes are shared with participants and saved automatically.',
    'Aucune note pour cette réunion.': 'No notes for this meeting.',
    'Aucun autre membre dans l’équipe.': 'No other member in the team.',
    'Aucun membre ne correspond.': 'No member matches.',
    'Caméra désactivée': 'Camera off',
    'Démarrage de la caméra…': 'Starting the camera…',
    'Réunion en cours': 'Meeting in progress',
    'Prêt à rejoindre ?': 'Ready to join?',
    'Activer le micro': 'Unmute microphone',
    'Couper la caméra': 'Turn off camera',
    'Activer la caméra': 'Turn on camera',
    'Modifier la réunion': 'Edit the meeting',
    'Les participants reçoivent une invitation par e-mail à ajouter à leur agenda, puis un rappel avant le début.':
        'Participants get an email invitation to add to their calendar, then a reminder before the start.',
    'Vous entrez dans la salle tout de suite ; les invités reçoivent une notification avec un bouton « Rejoindre ».':
        'You enter the room right away; invitees get a notification with a "Join" button.',
    'Ex. Revue mensuelle du service client':
        'e.g. Monthly customer service review',
    'Facultatif — « Réunion de … » par défaut':
        'Optional — "Meeting of …" by default',
    'Qui appartient à cette organisation et avec quel rôle':
        'Who belongs to this organization and with which role',
    'Appel en cours': 'Call in progress',
    'Notes de réunion': 'Meeting notes',
    Arrêter: 'Stop',
    'Arrêter le partage': 'Stop sharing',
    'Partager l’écran': 'Share screen',
    'Arrêter l’enregistrement': 'Stop recording',
    'À l’heure': 'On time',
    'Ajouter des notes': 'Add notes',
    Annulée: 'Cancelled',
    Terminée: 'Completed',
    'Nouvelle offre': 'New plan',
    'sans poste': 'no extension',
    Fermé: 'Closed',
    'Obtenez un numéro tout de suite, sans rien saisir. Il sera routé vers votre première file.':
        'Get a number right away, nothing to type. It will be routed to your first queue.',
    'Aucun numéro disponible pour le moment. Contactez le support ou ajoutez votre propre numéro ci-dessous.':
        'No number is available right now. Contact support or add your own number below.',
    'Modifier le menu': 'Edit the menu',
    'Nouveau menu': 'New menu',
    'Créer le menu': 'Create the menu',
    'Ajouter la ligne': 'Add the line',
    'Ajouter le poste': 'Add the extension',
    'Laisser vide pour conserver': 'Leave empty to keep it',
    'Appels manqués aujourd’hui, du plus récent au plus ancien.':
        'Calls missed today, most recent first.',
    'Aucun appel manqué aujourd’hui.': 'No missed call today.',
    Manqué: 'Missed',
    Terminé: 'Done',
    'Numérotation…': 'Dialing…',
    'en cours': 'in progress',
    'Aucun script.': 'No script.',
    'Créer la campagne': 'Create the campaign',
    'aucun poste attribué': 'no extension assigned',
    connecté: 'connected',
    'connexion…': 'connecting…',
    'hors ligne': 'offline',
    'Mise à jour en temps réel.': 'Updated in real time.',
    'Actualisé toutes les 3 secondes.': 'Refreshed every 3 seconds.',
    annuel: 'annual',
    mensuel: 'monthly',
    'Masquer les codes de récupération': 'Hide recovery codes',
    'Afficher les codes de récupération': 'Show recovery codes',
    'ont rejoint la réunion': 'joined the meeting',
    'a rejoint la réunion': 'joined the meeting',
    ':p0 agent(s) en ce moment': ':p0 agent(s) right now',
    'Connectez-vous': 'Log in',
    'Inscrivez-vous': 'Sign up',
    "Se connecter avec une clé d'accès": 'Sign in with a passkey',
    "Ou continuer avec l'e-mail": 'Or continue with email',
    ', enregistrement des réunions': ', meeting recording',
    'Aperçu, progressif et « power »': 'Preview, progressive and power',
    ':p0 min incluses par mois': ':p0 min included per month',
    "Facturés à la minute, en plus de l'abonnement":
        'Billed per minute, on top of the subscription',
    'Appels sortants': 'Outbound calls',
    'Offre gratuite, sans échéance ni paiement.':
        'Free plan, no end date and no payment.',
    '12 mois (:p0 payés)': '12 months (:p0 paid)',
    "Votre offre payée court jusqu'au :p0 : vous pourrez passer à :p1 à son échéance.":
        'Your paid plan runs until :p0: you can switch to :p1 when it ends.',
    "Passer à l'offre :p0": 'Switch to :p0',
    "Inclus dans les offres :plans. Changez d'offre depuis la page Abonnement.":
        'Included in the :plans plans. Change plan on the Subscription page.',
    'Les campagnes sortantes sont incluses dans les offres :plans.':
        'Outbound campaigns are included in the :plans plans.',
    'Non inclus (offres :plans)': 'Not included (:plans plans)',
    Gratuit: 'Free',
    Renouvellement: 'Renewal',
    '12 mois': '12 months',
    '1 mois': '1 month',
    Superviseurs: 'Supervisors',
    'En créant un compte, vous acceptez notre':
        'By creating an account, you accept our',
    'politique de confidentialité': 'privacy policy',
    'Question sans titre': 'Untitled question',
    'Pas du tout probable': 'Not at all likely',
    'Très probable': 'Extremely likely',
    Questionnaire: 'Survey',
    "Les questions que l'agent pose pendant l'appel. Les réponses sont enregistrées pour chaque contact.":
        'The questions the agent asks during the call. Answers are saved for each contact.',
    'Résultats (:count)': 'Results (:count)',
    ':count réponse(s) déjà enregistrée(s). Modifier un libellé est sans risque ; supprimer une question ou un choix retire ses réponses des résultats.':
        ':count response(s) already saved. Editing a label is safe; deleting a question or a choice removes its answers from the results.',
    'Aucune question pour le moment': 'No questions yet',
    'Ajoutez une question ci-dessous, ou partez d’un modèle.':
        'Add a question below, or start from a template.',
    'Modèle : satisfaction client': 'Template: customer satisfaction',
    'Type de question': 'Question type',
    Obligatoire: 'Required',
    Monter: 'Move up',
    Descendre: 'Move down',
    Dupliquer: 'Duplicate',
    'Supprimer la question': 'Delete question',
    'Question posée': 'Question asked',
    'ex. Êtes-vous satisfait de votre livraison ?':
        'e.g. Are you happy with your delivery?',
    "Consigne pour l'agent (facultatif)": 'Note for the agent (optional)',
    'ex. Lire les choix au client': 'e.g. Read the choices to the customer',
    'Réponses proposées': 'Answer choices',
    'Réponse :n': 'Answer :n',
    'Retirer cette réponse': 'Remove this answer',
    'Ajouter une réponse': 'Add an answer',
    À: 'To',
    'Libellé du minimum': 'Lowest label',
    'Pas du tout': 'Not at all',
    'Libellé du maximum': 'Highest label',
    'Tout à fait': 'Completely',
    'Minimum (facultatif)': 'Minimum (optional)',
    'Maximum (facultatif)': 'Maximum (optional)',
    'Poser cette question seulement selon une réponse précédente':
        'Only ask this question depending on an earlier answer',
    'Si la réponse est :': 'If the answer is:',
    'Ajouter une question': 'Add a question',
    'Aperçu agent': 'Agent preview',
    Effacer: 'Clear',
    "L'aperçu montre le questionnaire tel que l'agent le verra.":
        'The preview shows the survey as the agent will see it.',
    'Les questions conditionnelles apparaissent dès que la réponse attendue est choisie.':
        'Conditional questions appear as soon as the expected answer is chosen.',
    'Résultats du questionnaire': 'Survey results',
    'Modifier les questions': 'Edit questions',
    Réponses: 'Responses',
    ':p0 % des contacts': ':p0 % of contacts',
    'Questionnaires terminés': 'Completed surveys',
    ':p0 % des réponses': ':p0 % of responses',
    'Aucune réponse pour le moment : elles apparaîtront ici dès que les agents rempliront le questionnaire.':
        'No responses yet: they will appear here as soon as agents fill in the survey.',
    ':count réponse(s)': ':count response(s)',
    'Score NPS (−100 à +100)': 'NPS score (−100 to +100)',
    ':count promoteurs (9–10)': ':count promoters (9–10)',
    ':count passifs (7–8)': ':count passives (7–8)',
    ':count détracteurs (0–6)': ':count detractors (0–6)',
    'en moyenne': 'on average',
    Moyenne: 'Average',
    'Plusieurs réponses possibles : le total peut dépasser 100 %.':
        'Several answers possible: the total can exceed 100 %.',
    "Les 30 réponses les plus récentes. Toutes les réponses sont dans l'export CSV.":
        'The 30 most recent answers. All answers are in the CSV export.',
    'Mettre à jour les réponses': 'Update answers',
    'Terminer le questionnaire': 'Complete the survey',
    'Enregistrer le brouillon': 'Save draft',
    'Le brouillon est aussi enregistré quand vous qualifiez l’appel. Rappelé plus tard, le contact reprend là où il s’était arrêté.':
        'The draft is also saved when you wrap up the call. Called again later, the contact picks up where they left off.',
    'Questionnaire (:count)': 'Survey (:count)',
    'Créer un questionnaire': 'Create a survey',
    // Workforce planning
    ':n créneau(x) sous l’effectif minimum':
        ':n slot(s) below minimum staffing',
    ':n j': ':n d',
    ':name cède :': ':name gives:',
    ':name propose d’échanger son poste contre le vôtre':
        ':name offers to swap their shift for yours',
    ':name vous propose de reprendre son poste':
        ':name asks you to take over their shift',
    ':time planifiées': ':time scheduled',
    ':used j pris · :pending j en attente':
        ':used d taken · :pending d pending',
    Absences: 'Absences',
    "Absences de l'équipe (60 prochains jours)": 'Team absences (next 60 days)',
    Actif: 'Active',
    'Action impossible.': 'Action not possible.',
    Actions: 'Actions',
    'Affectation refusée.': 'Assignment refused.',
    Affecter: 'Assign',
    'Affecter :n jour(s)': 'Assign :n day(s)',
    'Affecter :name le :date': 'Assign :name on :date',
    'Affecter des postes': 'Assign shifts',
    'Affecter plusieurs jours': 'Assign several days',
    Agent: 'Agent',
    'Ajouter un poste': 'Add a shift',
    'Ajouter un type': 'Add a type',
    Approuver: 'Approve',
    'Approuver automatiquement les échanges': 'Approve swaps automatically',
    'Approuver la correction': 'Approve the correction',
    'Approuver le congé': 'Approve the leave',
    'Approuver l’échange': 'Approve the swap',
    'Approuvé automatiquement': 'Approved automatically',
    Approuvée: 'Approved',
    Arrivée: 'Clock-in',
    "Aucun agent dans l'équipe pour le moment.": 'No agents in the team yet.',
    'Aucun pointage ce jour-là.': 'No clock-in that day.',
    'Aucun poste planifié dans les 45 prochains jours.':
        'No shift scheduled in the next 45 days.',
    'Aucune demande pour le moment.': 'No requests yet.',
    'Autoriser avec un avertissement': 'Allow with a warning',
    'Avec :name': 'With :name',
    C: 'L',
    'Cession simple, sans contrepartie.': 'Plain give-away, nothing in return.',
    'Cette période est passée.': 'This period is over.',
    'Cette semaine': 'This week',
    'Choisir son poste': 'Choose their shift',
    'Choisir un agent': 'Choose an agent',
    'Choisir un collègue': 'Choose a colleague',
    Code: 'Code',
    Collègue: 'Colleague',
    'Commencer une pause': 'Start a break',
    'Congé :time': 'Leave :time',
    Congés: 'Leave',
    'Copier la semaine précédente': 'Copy previous week',
    'Correction en attente': 'Correction pending',
    'Corrections de pointage': 'Timesheet corrections',
    'Corrections demandées': 'Requested corrections',
    'Corriger ce pointage': 'Correct this entry',
    Corrigé: 'Corrected',
    Couleur: 'Colour',
    'Couverture :code': 'Coverage :code',
    Date: 'Date',
    'Demander un congé': 'Request leave',
    'Demander une correction': 'Request a correction',
    Demandes: 'Requests',
    'Demandé :': 'Requested:',
    'Deux postes enchaînés (A puis B) comptent comme un seul bloc.':
        'Two chained shifts (A then B) count as one block.',
    'Décisions récentes': 'Recent decisions',
    'Décompte estimé : :n j': 'Estimated count: :n d',
    Départ: 'Clock-out',
    'Effectif min.': 'Min. staff',
    'Empêche deux journées de 16 h à la suite.':
        'Prevents two 16 h days in a row.',
    'En pause depuis :time': 'On break since :time',
    'En poste depuis :time': 'On shift since :time',
    'En échange de': 'In exchange for',
    'Enregistrement impossible.': 'Could not save.',
    'Enregistrer les postes': 'Save shifts',
    'Enregistrer les règles': 'Save rules',
    'Enregistrer les types': 'Save types',
    'Envoyer la demande': 'Send the request',
    'Envoyer la proposition': 'Send the proposal',
    'Ex. : oubli de pointage au départ': 'E.g. forgot to clock out',
    'Expliquez le refus à l’agent': 'Explain the refusal to the agent',
    'Feuilles de temps': 'Timesheets',
    Heures: 'Hours',
    'Heures d’affilée max.': 'Max. consecutive hours',
    'Heures max. par semaine': 'Max. hours per week',
    Jour: 'Day',
    Jours: 'Days',
    'Jours par an': 'Days per year',
    "L'équipe": 'The team',
    'Laissez le quota vide pour un type sans solde limité (maladie, sans solde…).':
        'Leave the quota empty for a type without a limited balance (sick, unpaid…).',
    'Les chevauchements et les congés approuvés bloquent toujours.':
        'Overlaps and approved leave always block.',
    'Les règles de repos et de durée sont vérifiées pour chaque jour.':
        'Rest and duration rules are checked for each day.',
    'Les échanges, congés et corrections de pointage à valider apparaîtront ici.':
        'Swaps, leave and timesheet corrections to review will show up here.',
    'Max :max h d’affilée · repos :rest h (:long h après un long poste) · :week h/semaine · :mode':
        'Max :max h in a row · rest :rest h (:long h after a long shift) · :week h/week · :mode',
    'Mes demandes': 'My requests',
    'Mes prochains postes': 'My upcoming shifts',
    'Mes soldes :year': 'My balances :year',
    'Mes échanges': 'My swaps',
    'Message (facultatif)': 'Message (optional)',
    Mois: 'Month',
    'Mon planning': 'My schedule',
    'Motif du refus': 'Reason for refusal',
    'Non pointé': 'Not clocked in',
    'Non validée': 'Not approved',
    Pause: 'Break',
    'Pause (min)': 'Break (min)',
    Payé: 'Paid',
    'Personne de planifié ni de pointé cette semaine.':
        'Nobody scheduled or clocked in this week.',
    'Personne en congé.': 'Nobody on leave.',
    Planifié: 'Scheduled',
    Planning: 'Planning',
    'Plusieurs postes le même jour : :hours h par jour, refusé au-delà de :max h d’affilée.':
        'Several shifts on the same day: :hours h per day, refused beyond :max h in a row.',
    Pointage: 'Time clock',
    Pointages: 'Clock entries',
    'Pointer le départ': 'Clock out',
    'Pointer l’arrivée': 'Clock in',
    'Pointé :': 'Clocked:',
    Postes: 'Shifts',
    'Postes raccourcis :': 'Shortened shifts:',
    'Postes retirés du roster :': 'Shifts removed from the roster:',
    'Proposer un échange': 'Propose a swap',
    'Proposé par :name': 'Proposed by :name',
    "Prévenir l'équipe": 'Notify the team',
    'Quand les deux agents sont d’accord, partagent une file et qu’aucune règle n’est dépassée (pas d’heures sup.).':
        'When both agents agree, share a queue and no rule is broken (no overtime).',
    'Refuser la correction': 'Reject the correction',
    'Refuser le congé': 'Reject the leave',
    'Refuser l’affectation': 'Refuse the assignment',
    'Refuser l’échange': 'Reject the swap',
    Refusée: 'Rejected',
    Remarques: 'Notes',
    Repos: 'Off',
    'Repos après un bloc de plus de 8 h': 'Rest after a block over 8 h',
    'Repos minimum (h)': 'Minimum rest (h)',
    Reprendre: 'Resume',
    Retards: 'Late',
    'Retirer ce poste': 'Remove this shift',
    'Rien en échange (céder mon poste)':
        'Nothing in return (give my shift away)',
    'Rien à traiter': 'Nothing to review',
    Roster: 'Roster',
    Rouvrir: 'Reopen',
    'Règle dépassée': 'Rule broken',
    'Règles de travail': 'Labour rules',
    Réglages: 'Settings',
    'Réglages du planning': 'Planning settings',
    Semaine: 'Week',
    'Semaine du :date': 'Week of :date',
    'Semaine précédente': 'Previous week',
    'Semaine suivante': 'Next week',
    "Seul un administrateur de l'équipe peut modifier ces règles.":
        'Only a team administrator can change these rules.',
    'Si une règle est dépassée': 'When a rule is broken',
    'Solde après cette demande : :n j sur :total':
        'Balance after this request: :n d of :total',
    'Tolérance de retard (min)': 'Late tolerance (min)',
    Travaillé: 'Worked',
    'Travaillé : :time': 'Worked: :time',
    Type: 'Type',
    'Types de congé': 'Leave types',
    'Un départ avant l’arrivée compte le lendemain (poste de nuit).':
        'A clock-out before the clock-in counts for the next day (night shift).',
    'Un poste dont la fin précède le début se termine le lendemain. Les changements ne touchent que les nouvelles affectations.':
        'A shift whose end is before its start ends the next day. Changes only apply to new assignments.',
    'Une partie de la journée seulement': 'Part of the day only',
    Validation: 'Approval',
    'Valider la semaine': 'Approve the week',
    Validée: 'Approved',
    'Visible par l’agent': 'Visible to the agent',
    'Votre collègue doit accepter, puis un superviseur valide. Les règles de repos et de chevauchement sont vérifiées pour vous deux.':
        'Your colleague must accept, then a supervisor confirms. Rest and overlap rules are checked for both of you.',
    'Votre superviseur est prévenu. Une fois approuvé, vos postes couverts sont retirés du roster.':
        'Your supervisor is notified. Once approved, the shifts it covers are removed from the roster.',
    'Vous cédez : :shift': 'You give: :shift',
    'Vous prenez : :shift': 'You take: :shift',
    'Vérifiées à chaque affectation, échange et copie de semaine.':
        'Checked on every assignment, swap and week copy.',
    'aucun poste': 'no shift',
    automatique: 'automatic',
    'avant :': 'before:',
    avertissement: 'warning',
    bloquant: 'blocking',
    illimité: 'unlimited',
    j: 'd',
    'j pris': 'd taken',
    'min. :n': 'min. :n',
    'non payé': 'unpaid',
    'pause :n min': 'break :n min',
    payé: 'paid',
    'solde disponible : :n j': 'available balance: :n d',
    'À valider': 'To approve',
    Écart: 'Variance',
    'Échange en cours': 'Swap in progress',
    'Échanges de postes': 'Shift swaps',
    'Échanges proposés par vos collègues': 'Swaps offered by your colleagues',
    'Mes soldes': 'My balances',
    'Aucun superviseur': 'No supervisor',
    'Supervisé par :name': 'Supervised by :name',
    'Superviseur de :name': 'Supervisor of :name',
    'Agents affichés': 'Agents shown',
    'Mes agents': 'My agents',
    "Toute l'équipe": 'Whole team',
    Planificateurs: 'Planners',
    Planificateur: 'Planner',
    "Seuls un planificateur ou un administrateur de l'équipe peuvent modifier ces règles.":
        'Only a planner or a team administrator can change these rules.',
    'Message de fermeture': 'Closed message',
    'Joué en dehors des horaires et les jours de fermeture. Laissez vide pour le message par défaut.':
        'Played outside opening hours and on closure days. Leave empty for the default message.',
    'Fichier audio': 'Audio file',
    'Suivre le niveau de service': 'Track service level',
    'Part des appels décrochés dans le délai de chaque file, affichée en supervision. Les files sans délai reçoivent 20 s.':
        'Share of calls answered within each queue’s threshold, shown in monitoring. Queues without a threshold get 20 s.',
    'Objectif (%)': 'Target (%)',
    'Délai de décroché visé (s)': 'Answer threshold (s)',
    'Niveau de service': 'Service level',
    'Niveau de service (:s s)': 'Service level (:s s)',
    'Appels décrochés en moins de :s s': 'Calls answered within :s s',
    'Objectif : :target % des appels décrochés dans le délai de chaque file':
        'Target: :target % of calls answered within each queue’s threshold',
    'Créez le compte directement et, s’il prend des appels, attribuez-lui ses identifiants SIP.':
        'Create the account directly and, if they take calls, give them their SIP credentials.',
    'Prend aussi des appels': 'Also takes calls',
    'Décochez pour un compte sans poste ni file (supervision, planning, administration). Un poste pourra être ajouté plus tard dans Postes SIP.':
        'Untick for an account with no extension or queue (supervision, planning, administration). An extension can be added later in SIP extensions.',
    'Appel 1 sur :n à qualifier : les suivants s’affichent après celui-ci.':
        'Call 1 of :n to wrap up: the next ones show after this one.',
    ':n appel(s) précédent(s) à qualifier après celui-ci.':
        ':n earlier call(s) to wrap up after this one.',
    Collègues: 'Colleagues',
    'Nom, poste, service…': 'Name, extension, department…',
    'Chercher un collègue': 'Search a colleague',
    "Impossible de charger l'annuaire.": 'Could not load the directory.',
    'Composer le :p0': 'Dial :p0',
    'Statut inconnu': 'Unknown status',
    'Appeler :p0': 'Call :p0',
    'Aucun collègue trouvé.': 'No colleague found.',
    "Aucun collègue n'a encore de poste.": 'No colleague has an extension yet.',
    'Votre correspondant est déjà en ligne.':
        'The person you are calling is already on a call.',
    "Votre correspondant n'est pas connecté pour le moment.":
        'The person you are calling is not connected right now.',
    "Ce numéro n'existe pas.": 'This number does not exist.',
    'Appel refusé.': 'Call declined.',
    "L'appel n'a pas abouti (code :p0).":
        'The call did not go through (code :p0).',
    'Réglages communs à toutes les organisations. Réservé aux administrateurs de la plateforme.':
        'Settings shared by every organization. Platform admins only.',
    'Trunk sortant':
        'Outbound trunk',
    'Tous les appels sortants passent par ce trunk : campagnes et appels composés depuis le softphone.':
        'Every outgoing call goes through this trunk: campaigns and calls dialled from the softphone.',
    'Actuellement « :p0 », lu depuis ASTERISK_CAMPAIGN_TRUNK (.env). Enregistrez un choix pour le gérer ici.':
        'Currently “:p0”, read from ASTERISK_CAMPAIGN_TRUNK (.env). Save a choice to manage it here.',
    'La base Asterisk est injoignable : impossible de lister les trunks.':
        'The Asterisk database cannot be reached: trunks cannot be listed.',
    'Aucun trunk dans ps_endpoints. Créez le point de terminaison de votre opérateur, puis revenez ici.':
        'No trunk in ps_endpoints. Create your carrier endpoint, then come back here.',
    'actuel':
        'current',
    'Aucune adresse (contact) dans son AOR':
        'No address (contact) in its AOR',
    'contexte':
        'context',
    'Comment les appels sortent':
        'How calls go out',
    'Un numéro externe composé dans le softphone part en 00 + numéro international. Chaque organisation l’envoie (avec son numéro principal comme numéro présenté) vers le contexte partagé :p0, qui appelle le trunk choisi.':
        'An outside number dialled in the softphone leaves as 00 + international number. Each organization sends it (with its main number as caller ID) to the shared context :p0, which calls the chosen trunk.',
    'Le trunk reçoit le préfixe « :p0 » suivi du numéro international, comme pour les campagnes.':
        'The trunk receives the prefix “:p0” followed by the international number, as for campaigns.',
    'Déclarez :p0 dans extensions.conf une seule fois : php artisan asterisk:contexts-config, puis dialplan reload.':
        'Declare :p0 in extensions.conf once: php artisan asterisk:contexts-config, then dialplan reload.',
    'Prix des appels sortants':
        'Outside call price',
    'Facturé par minute commencée. Un nouveau prix s’applique aux appels suivants ; les appels déjà passés gardent le leur.':
        'Billed per started minute. A new price applies to the next calls; calls already made keep theirs.',
    'Prix par minute':
        'Price per minute',
    'F / min':
        'F / min',
    'depuis le :p0':
        'since :p0',
    'Consommation par organisation':
        'Usage by organization',
    'Appels sortants décrochés, minutes facturables et montant.':
        'Answered outside calls, billable minutes and amount.',
    'La base Asterisk est injoignable : consommation indisponible.':
        'The Asterisk database cannot be reached: usage unavailable.',
    'Aucun appel sortant ce mois-ci.':
        'No outside calls this month.',
    'Minutes':
        'Minutes',
    'Facturables':
        'Billable',
    'Total':
        'Total',
    'Facturés :p0 par minute commencée, en plus de l’abonnement.':
        'Billed :p0 per started minute, on top of the subscription.',
    'Minutes facturables':
        'Billable minutes',
    ':p0 min incluses dans votre offre, :p1 utilisées.':
        ':p0 min included in your plan, :p1 used.',
    'Inclus':
        'Included',
    'Les :p0 appels les plus récents sont affichés.':
        'The :p0 most recent calls are shown.',
    'Cette table est un journal écrit par Asterisk. La modifier change l’historique des appels et la facturation des appels sortants. Continuer ?':
        'This table is a log written by Asterisk. Changing it changes call history and outside-call billing. Continue?',
    'Supprimer cette ligne ?':
        'Delete this row?',
    'Tables Asterisk':
        'Asterisk tables',
    'Lecture et modification directes de la base temps réel d’Asterisk. Réservé aux administrateurs de la plateforme ; chaque modification est journalisée.':
        'Read and edit the Asterisk realtime database directly. Platform admins only; every change is logged.',
    'La base Asterisk est injoignable :':
        'The Asterisk database cannot be reached:',
    'Tables':
        'Tables',
    'Table':
        'Table',
    'lignes':
        'rows',
    'Rechercher dans toutes les colonnes':
        'Search all columns',
    'Nouvelle ligne':
        'New row',
    'Journal écrit par Asterisk : les modifications changent l’historique des appels, les statistiques et la facturation des appels sortants.':
        'Log written by Asterisk: changes alter call history, statistics and outside-call billing.',
    'Aucune ligne.':
        'No rows.',
    'Page :p0 sur :p1':
        'Page :p0 of :p1',
    'Aucune des tables gérées n’existe dans la base Asterisk.':
        'None of the managed tables exist in the Asterisk database.',
    'Modifier la ligne':
        'Edit row',
    'Vide = NULL quand la colonne l’accepte. * = clé primaire.':
        'Empty = NULL when the column allows it. * = primary key.',
    'Contenu binaire : téléchargeable, non modifiable ici.':
        'Binary content: downloadable, not editable here.',
    'Télécharger':
        'Download',
    'Supprimer la messagerie du poste :p0 et tous ses messages ?':
        'Delete the voicemail of extension :p0 and all its messages?',
    'Supprimer ce message ?':
        'Delete this message?',
    'Messagerie vocale':
        'Voicemail',
    'Un appel vers un poste qui ne répond pas, est occupé ou déconnecté laisse un message quand le poste a une messagerie. Les agents l’écoutent en composant :p0.':
        'A call to an extension that does not answer, is busy or offline leaves a message when the extension has voicemail. Agents listen by dialling :p0.',
    'La messagerie vocale n’est pas disponible sur le serveur Asterisk de la plateforme. Contactez le support.':
        'Voicemail is not available on the platform’s Asterisk server. Contact support.',
    'Messageries par poste':
        'Voicemail by extension',
    'Poste non attribué':
        'Unassigned extension',
    'Activée':
        'On',
    'Activer':
        'Turn on',
    'Supprimer la messagerie':
        'Delete voicemail',
    'Code PIN':
        'PIN',
    'Inchangé':
        'Unchanged',
    '4 à 10 chiffres':
        '4 to 10 digits',
    'Nom annoncé':
        'Announced name',
    'E-mail (message en pièce jointe)':
        'Email (message attached)',
    'Aucun poste dans l’organisation.':
        'No extensions in the organization.',
    'Messages':
        'Messages',
    'Tous les postes':
        'All extensions',
    'Les messages ne sont pas stockés en base sur ce serveur : écoutez-les depuis le softphone avec :p0, ou par e-mail.':
        'Messages are not stored in the database on this server: listen from the softphone with :p0, or by email.',
    'Aucun message.':
        'No messages.',
    'Numéro masqué':
        'Hidden number',
    'Supprimer ce message':
        'Delete this message',
    'Nouveau':
        'New',
    'Écouté':
        'Listened',
    'Urgent':
        'Urgent',
    'Retirer « :p0 » de la musique d’attente ?':
        'Remove “:p0” from the hold music?',
    'Musique d’attente':
        'Hold music',
    'Votre musique est jouée aux appelants qui attendent dans vos files et aux appels qu’un agent met en attente. Sans fichier, la musique de la plateforme est jouée.':
        'Your music plays to callers waiting in your queues and to calls an agent puts on hold. Without a file, the platform music plays.',
    'La musique d’attente personnalisée n’est pas disponible sur le serveur Asterisk de la plateforme. Contactez le support.':
        'Custom hold music is not available on the platform’s Asterisk server. Contact support.',
    'Envoi et conversion…':
        'Uploading and converting…',
    'Ajouter un fichier':
        'Add a file',
    'MP3, WAV, OGG… jusqu’à :p0 Mo, :p1 fichiers au plus. Converti en qualité téléphone (8 kHz mono).':
        'MP3, WAV, OGG… up to :p0 MB, :p1 files at most. Converted to phone quality (8 kHz mono).',
    'Aucun fichier : la musique de la plateforme est jouée.':
        'No file: the platform music plays.',
    'Les fichiers sont joués dans un ordre aléatoire. Choisissez la musique de chaque file dans Files d’attente.':
        'Files play in random order. Choose each queue’s music in Queues.',
    'Musique de la plateforme':
        'Platform music',
    'Notre musique d’attente':
        'Our hold music',
    'Ajoutez votre propre musique dans Paramètres → Musique d’attente.':
        'Add your own music in Settings → Hold music.',
    'Trunks et transports PJSIP, musique d’attente, messagerie vocale, règles de file et journaux (cdr, cel, queue_log).':
        'PJSIP trunks and transports, hold music, voicemail, queue rules and logs (cdr, cel, queue_log).',
    'Journaux':
        'Logs',
    'PJSIP':
        'PJSIP',
    Membres: 'Members',
    Avancé: 'Advanced',
    'Aucune offre': 'No plan',
    'Gérer l’abonnement': 'Manage subscription',
    'Fin de l’essai': 'Trial ends',
    'Expiré le': 'Expired on',
    'Agents': 'Agents',
    'Appels sortants ce mois-ci': 'Outside calls this month',
    ':p0 / min': ':p0 / min',
};

export default en;
