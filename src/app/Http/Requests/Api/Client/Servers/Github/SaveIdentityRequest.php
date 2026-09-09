<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SaveIdentityRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_REPOSITORY;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'email' => 'required|string|email|max:191',
        ];
    }
}