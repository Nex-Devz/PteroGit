<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class FilesRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_COMMIT;
    }

    public function rules(): array
    {
        return [
            'files' => 'required|array|min:1|max:500',
            'files.*' => 'required|string|max:500',
        ];
    }
}