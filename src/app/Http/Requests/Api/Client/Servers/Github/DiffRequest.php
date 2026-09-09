<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class DiffRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_READ;
    }

    public function rules(): array
    {
        return [
            'file' => 'sometimes|string|max:500',
            'root' => 'sometimes|string|max:500',
        ];
    }
}