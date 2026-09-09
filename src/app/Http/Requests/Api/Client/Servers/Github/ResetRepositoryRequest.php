<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ResetRepositoryRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_FORCE_RESET;
    }

    public function rules(): array
    {
        return [
            'branch' => 'required|string|max:191',
            'confirm' => 'required|accepted',
        ];
    }
}