<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Github;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ConnectRepositoryRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_GIT_MANAGE_REPOSITORY;
    }

    public function rules(): array
    {
        return [
            'account_id' => 'required|integer',
            'initialize' => 'sometimes|boolean',
            'repository_id' => 'required_if:initialize,false|string',
            'repository_full_name' => 'required_if:initialize,false|string|max:191',
            'remote_url' => 'required_if:initialize,false|url|max:500',
            'default_branch' => 'required_if:initialize,false|string|max:191',
            'branch' => 'nullable|string|max:191',
            'working_directory' => 'nullable|string|max:500',
            'mode' => 'required_without:initialize|in:clone,pull',
            'confirm_replace' => 'sometimes|boolean',
        ];
    }
}