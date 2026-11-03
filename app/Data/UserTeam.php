<?php

namespace App\Data;

readonly class UserTeam
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public ?string $role,
        public ?string $roleLabel,
        public ?bool $isCurrent = null,
        /** 'call_center' or 'internal': the front end hides call-center screens for internal teams. */
        public string $usageMode = 'call_center',
    ) {
        //
    }
}
