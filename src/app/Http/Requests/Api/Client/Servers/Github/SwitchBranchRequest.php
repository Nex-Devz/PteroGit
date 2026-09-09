<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SwitchBranchRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_BRANCHES;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:191'];
    }
}