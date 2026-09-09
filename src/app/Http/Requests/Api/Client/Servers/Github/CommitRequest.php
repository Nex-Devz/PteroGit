<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class CommitRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_COMMIT;
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|max:500',
            'push' => 'sometimes|boolean',
            'all' => 'sometimes|boolean',
        ];
    }
}