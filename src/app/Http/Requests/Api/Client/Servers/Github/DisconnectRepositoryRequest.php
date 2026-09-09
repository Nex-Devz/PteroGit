<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class DisconnectRepositoryRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_REPOSITORY;
    }

    public function rules(): array
    {
        return ['confirm' => 'required|accepted'];
    }
}