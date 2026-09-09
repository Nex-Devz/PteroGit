<?php

namespace Pterodactyl\Http\Controllers\Admin\Settings;

use Prologue\Alerts\AlertsMessageBag;
use Illuminate\View\View;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\RedirectResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Providers\SettingsServiceProvider;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\Http\Requests\Admin\Settings\GithubSettingsFormRequest;

class GithubController extends Controller
{
    /**
     * GithubController constructor.
     */
    public function __construct(
        private ConfigRepository $config,
        private Encrypter $encrypter,
        private Kernel $kernel,
        private SettingsRepositoryInterface $settings,
        private AlertsMessageBag $alert,
    ) {
    }

    /**
     * Render the GitHub integration settings page.
     */
    public function index(): View
    {
        return view('admin.settings.github', [
            'enabled' => (bool) $this->config->get('pterodactyl.git.enabled', true),
            'oauthEnabled' => (bool) $this->config->get('pterodactyl.git.oauth.enabled', false),
            'clientId' => (string) $this->config->get('pterodactyl.git.oauth.client_id', ''),
            'clientSecret' => (string) $this->config->get('pterodactyl.git.oauth.client_secret', ''),
            'redirectUri' => (string) $this->config->get('pterodactyl.git.oauth.redirect_uri', ''),
        ]);
    }

    /**
     * Persist the GitHub integration settings to the database.
     */
    public function update(GithubSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->normalize() as $key => $value) {
            if (in_array($key, SettingsServiceProvider::getEncryptedKeys()) && !empty($value)) {
                $value = $this->encrypter->encrypt($value);
            }

            $this->settings->set('settings::' . $key, $value);
        }

        $this->kernel->call('queue:restart');
        $this->alert->success('GitHub integration settings have been updated successfully and the queue worker was restarted to apply these changes.')->flash();

        return redirect()->route('admin.settings.github');
    }
}