<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class RevertRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_REVERT;
    }

    public function rules(): array
    {
        return ['sha' => 'required|string|max:40'];
    }
}