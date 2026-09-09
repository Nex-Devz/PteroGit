<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class PullRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_PULL;
    }

    public function rules(): array
    {
        return [];
    }
}