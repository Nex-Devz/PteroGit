<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class CommitHistoryRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_READ;
    }

    public function rules(): array
    {
        return ['limit' => 'sometimes|integer|min:1|max:100'];
    }
}