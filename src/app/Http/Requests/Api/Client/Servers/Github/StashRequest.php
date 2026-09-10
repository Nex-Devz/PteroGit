<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StashRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_REPOSITORY;
    }

    public function rules(): array
    {
        return [
            'message' => 'sometimes|nullable|string|max:500',
        ];
    }
}
