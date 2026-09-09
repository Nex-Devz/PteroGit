<?php

namespace Pterodactyl\Http\Requests\Admin\Settings;

use Pterodactyl\Http\Requests\Admin\AdminFormRequest;

class GithubSettingsFormRequest extends AdminFormRequest
{
    /**
     * Return rules to validate GitHub integration settings POST data against.
     */
    public function rules(): array
    {
        return [
            'pterodactyl:git:enabled' => 'required|in:true,false',
            'pterodactyl:git:oauth:enabled' => 'required|in:true,false',
            'pterodactyl:git:oauth:client_id' => 'nullable|string|max:191',
            'pterodactyl:git:oauth:client_secret' => 'nullable|string|max:255',
            'pterodactyl:git:oauth:redirect_uri' => 'nullable|string|max:500',
        ];
    }

    /**
     * Do not overwrite the existing client secret when the field is left empty.
     * An explicit "!e" value clears the stored secret.
     */
    public function normalize(?array $only = null): array
    {
        $values = parent::normalize($only);

        if (empty($values['pterodactyl:git:oauth:client_secret'] ?? null)) {
            unset($values['pterodactyl:git:oauth:client_secret']);
        } elseif (($values['pterodactyl:git:oauth:client_secret'] ?? null) === '!e') {
            $values['pterodactyl:git:oauth:client_secret'] = '';
        }

        return $values;
    }
}