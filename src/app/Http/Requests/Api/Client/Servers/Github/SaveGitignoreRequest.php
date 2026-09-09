<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class SaveGitignoreRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_GITIGNORE;
    }

    public function rules(): array
    {
        return ['content' => 'sometimes|string'];
    }
}