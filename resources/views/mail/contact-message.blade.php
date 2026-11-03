<x-mail::message>
<x-mail::badge tone="informative">Site web · Formulaire de contact</x-mail::badge>

# Nouveau message de {{ $data['name'] }}

Un visiteur du site a rempli le formulaire de contact. Répondez directement à cet e-mail pour lui écrire.

<x-mail::summary :items="[
    'Nom' => $data['name'],
    'E-mail' => $data['email'],
    'Entreprise' => $data['company'] ?? null,
    'Taille de l\'équipe' => ! empty($data['team_size']) ? $data['team_size'].' agents' : null,
]" />

<x-mail::panel>
{!! nl2br(e($data['message']), false) !!}
</x-mail::panel>

<x-mail::button :url="'mailto:'.$data['email']">
Répondre à {{ $data['name'] }}
</x-mail::button>
</x-mail::message>
