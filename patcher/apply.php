<?php

/**
 * GitHub Integration – panel source patcher.
 *
 * Applies the required route/config/view-composer/router changes onto any
 * Pterodactyl Panel 1.15.x install. Every operation is idempotent and uses
 * exact anchor strings, so it is safe to run multiple times on the same panel.
 *
 * Usage: php apply.php <absolute-path-to-panel>
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$panel = rtrim((string) ($argv[1] ?? ''), '/');
if ($panel === '' || !file_exists($panel . '/artisan') || !file_exists($panel . '/config/pterodactyl.php')) {
    fwrite(STDERR, "Usage: php apply.php <path-to-pterodactyl-panel>\n");
    exit(1);
}

/**
 * Runs a single patch operation for a file.
 *
 * @param string   $relPath   relative path under the panel root
 * @param string   $mode      "after" | "before" | "replace"
 * @param string   $needle    exact anchor string found in the file
 * @param string   $insert    replacement/insertion text
 * @param string   $check     marker substring; patch is skipped if already present
 */
function patch(string $relPath, string $mode, string $needle, string $insert, string $check): array
{
    global $panel;
    $file = $panel . '/' . $relPath;
    $result = ['file' => $relPath, 'status' => 'skipped', 'detail' => ''];

    clearstatcache(true, $file);
    if (!file_exists($file)) {
        $result['status'] = 'error';
        $result['detail'] = 'file missing';

        return $result;
    }

    $content = (string) file_get_contents($file);

    // Already applied?
    if (str_contains($content, $check)) {
        $result['status'] = 'skipped';
        $result['detail'] = 'already applied';

        return $result;
    }

    $pos = strpos($content, $needle);
    if ($pos === false) {
        $result['status'] = 'error';
        $result['detail'] = 'anchor not found';

        return $result;
    }

    switch ($mode) {
        case 'after':
            $content = substr($content, 0, $pos + strlen($needle)) . $insert . substr($content, $pos + strlen($needle));
            break;
        case 'before':
            $content = substr($content, 0, $pos) . $insert . substr($content, $pos);
            break;
        case 'replace':
            $content = str_replace($needle, $insert, $content);
            break;
        default:
            $result['status'] = 'error';
            $result['detail'] = "unknown mode {$mode}";

            return $result;
    }

    if (file_put_contents($file, $content) === false) {
        $result['status'] = 'error';
        $result['detail'] = 'write failed';

        return $result;
    }

    $result['status'] = 'patched';
    $result['detail'] = 'ok';

    return $result;
}

$ops = [
    // ---------------------------------------------------------------- routes
    [
        'file' => 'routes/api-client.php',
        'mode' => 'after',
        'needle' => <<<'PHP'
    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });
PHP,
        'insert' => <<<'PHP'

    Route::prefix('/github')->group(function () {
        Route::get('/', Client\Account\GithubAccountController::class . '@index');
        Route::post('/', Client\Account\GithubAccountController::class . '@store');
        Route::delete('/{account}', Client\Account\GithubAccountController::class . '@destroy');
        Route::get('/repositories', Client\Account\GithubAccountController::class . '@repositories');
    });
PHP,
        'check' => "Client\\Account\\GithubAccountController",
    ],
    [
        'file' => 'routes/api-client.php',
        'mode' => 'before',
        'needle' => <<<'PHP'
    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\Servers\SubuserController::class, 'index']);
PHP,
        'insert' => <<<'PHP'
    Route::group(['prefix' => '/github'], function () {
        Route::get('/', [Client\Servers\GithubController::class, 'show']);
        Route::post('/connect', [Client\Servers\GithubController::class, 'connect']);
        Route::post('/disconnect', [Client\Servers\GithubController::class, 'disconnect']);
        Route::post('/reset', [Client\Servers\GithubController::class, 'reset']);

        Route::get('/changes', [Client\Servers\GithubController::class, 'changes']);
        Route::get('/diff', [Client\Servers\GithubController::class, 'diff']);

        Route::post('/stage', [Client\Servers\GithubController::class, 'stage']);
        Route::post('/unstage', [Client\Servers\GithubController::class, 'unstage']);
        Route::post('/discard', [Client\Servers\GithubController::class, 'discard']);
        Route::post('/commit', [Client\Servers\GithubController::class, 'commit']);
        Route::post('/pull', [Client\Servers\GithubController::class, 'pull']);
        Route::post('/push', [Client\Servers\GithubController::class, 'push']);

        Route::get('/branches', [Client\Servers\GithubController::class, 'branches']);
        Route::post('/branches', [Client\Servers\GithubController::class, 'createBranch']);
        Route::post('/branches/switch', [Client\Servers\GithubController::class, 'switchBranch']);
        Route::delete('/branches/{name}', [Client\Servers\GithubController::class, 'deleteBranch']);

        Route::get('/history', [Client\Servers\GithubController::class, 'history']);
        Route::post('/revert', [Client\Servers\GithubController::class, 'revert']);

        Route::get('/gitignore', [Client\Servers\GithubController::class, 'gitignore']);
        Route::put('/gitignore', [Client\Servers\GithubController::class, 'saveGitignore']);

        Route::get('/identity', [Client\Servers\GithubController::class, 'identity']);
        Route::post('/identity', [Client\Servers\GithubController::class, 'saveIdentity']);

        Route::get('/remote', [Client\Servers\GithubController::class, 'remote']);
    });

PHP,
        'check' => "Client\\Servers\\GithubController::class, 'show'",
    ],
    [
        'file' => 'routes/admin.php',
        'mode' => 'after',
        'needle' => "        Route::get('/advanced', [Admin\\Settings\\AdvancedController::class, 'index'])->name('admin.settings.advanced');",
        'insert' => "\n        Route::get('/github', [Admin\\Settings\\GithubController::class, 'index'])->name('admin.settings.github');",
        'check' => "name('admin.settings.github')",
    ],
    [
        'file' => 'routes/admin.php',
        'mode' => 'after',
        'needle' => "        Route::patch('/advanced', [Admin\\Settings\\AdvancedController::class, 'update']);",
        'insert' => "\n        Route::patch('/github', [Admin\\Settings\\GithubController::class, 'update']);",
        'check' => "Route::patch('/github'",
    ],
    [
        'file' => 'routes/base.php',
        'mode' => 'before',
        'needle' => <<<'PHP'
Route::get('/{react}', [Base\IndexController::class, 'index'])
    ->where('react', '^(?!(\/)?(api|auth|admin|daemon)).+');
PHP,
        'insert' => <<<'PHP'
Route::get('/account/github/oauth/begin', [Pterodactyl\Http\Controllers\Auth\GithubOAuthController::class, 'begin'])
    ->name('github.oauth.begin');
Route::get('/account/github/oauth/callback', [Pterodactyl\Http\Controllers\Auth\GithubOAuthController::class, 'callback'])
    ->name('github.oauth.callback');

PHP,
        'check' => 'github.oauth.begin',
    ],

    // ------------------------------------------------- config/pterodactyl.php
    [
        'file' => 'config/pterodactyl.php',
        'mode' => 'config-append',
        'needle' => '',
        'insert' => '',
        'check' => "'git' => [",
    ],

    // ------------------------------------------- ViewComposer (site settings)
    [
        'file' => 'app/Http/ViewComposers/AssetComposer.php',
        'mode' => 'replace',
        'needle' => <<<'PHP'
            'recaptcha' => [
                'enabled' => config('recaptcha.enabled', false),
                'siteKey' => config('recaptcha.website_key') ?? '',
            ],
        ]);
PHP,
        'insert' => <<<'PHP'
            'recaptcha' => [
                'enabled' => config('recaptcha.enabled', false),
                'siteKey' => config('recaptcha.website_key') ?? '',
            ],
            'git' => [
                'enabled' => (bool) config('pterodactyl.git.enabled', true),
            ],
        ]);
PHP,
        'check' => "'enabled' => (bool) config('pterodactyl.git.enabled', true)",
    ],

    // ---------------------------------------------------------- state settings
    [
        'file' => 'resources/scripts/state/settings.ts',
        'mode' => 'replace',
        'needle' => <<<'PHP'
    recaptcha: {
        enabled: boolean;
        siteKey: string;
    };
}
PHP,
        'insert' => <<<'PHP'
    recaptcha: {
        enabled: boolean;
        siteKey: string;
    };
    git: {
        enabled: boolean;
    };
}
PHP,
        'check' => 'git: {',
    ],

    // -------------------------------------------------------------- routes.ts
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'needle' => "import ServerActivityLogContainer from '@/components/server/ServerActivityLogContainer';",
        'insert' => "\nimport GithubContainer from '@/components/server/git/GitContainer';\nimport AccountGithubContainer from '@/components/dashboard/GithubContainer';",
        'check' => "import AccountGithubContainer",
    ],
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'needle' => <<<'PHP'
        {
            path: '/activity',
            name: 'Activity',
            component: ActivityLogContainer,
        },
PHP,
        'insert' => <<<'PHP'
        {
            path: '/github',
            name: 'GitHub',
            component: AccountGithubContainer,
            feature: 'git',
        },
PHP,
        'check' => 'component: AccountGithubContainer',
    ],
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'needle' => <<<'PHP'
    {
        path: '/activity',
        permission: 'activity.*',
        name: 'Activity',
        component: ServerActivityLogContainer,
    },
PHP,
        'insert' => <<<'PHP'
    {
        path: '/git',
        permission: 'git.*',
        name: 'GitHub',
        component: GithubContainer,
        feature: 'git',
    },
PHP,
        'check' => "path: '/git',",
    ],

    // ---------------------------------------------------------- ServerRouter
    [
        'file' => 'resources/scripts/routers/ServerRouter.tsx',
        'mode' => 'after',
        'needle' => "const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);",
        'insert' => "\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
        'check' => 'state.settings.data?.git?.enabled',
    ],
    [
        'file' => 'resources/scripts/routers/ServerRouter.tsx',
        'mode' => 'after',
        'needle' => <<<'PHP'
                                .filter((route) => !!route.name)
                                .map((route) =>
PHP,
        'insert' => "\n                                    .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))",
        'check' => "!(route.feature === 'git' && !gitEnabled && !rootAdmin)",
    ],
    [
        'file' => 'resources/scripts/routers/ServerRouter.tsx',
        'mode' => 'replace',
        'needle' => <<<'PHP'
                                     {routes.server
                                         .map(({ path, permission, component: Component }) => (
PHP,
        'insert' => <<<'PHP'
                                     {routes.server
                                         .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))
                                         .map(({ path, permission, component: Component }) => (
PHP,
        'check' => 'route.feature === \'git\'',
    ],

    // ------------------------------------------------------ DashboardRouter
    [
        'file' => 'resources/scripts/routers/DashboardRouter.tsx',
        'mode' => 'after',
        'needle' => "const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);",
        'insert' => "\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
        'check' => 'state.settings.data?.git?.enabled',
    ],
    [
        'file' => 'resources/scripts/routers/DashboardRouter.tsx',
        'mode' => 'replace',
        'needle' => <<<'PHP'
                            .filter((route) => !!route.name)
                            .map(({ path, name, exact = false }) => (
PHP,
        'insert' => <<<'PHP'
                            .filter((route) => !!route.name)
                            .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))
                            .map(({ path, name, exact = false }) => (
PHP,
        'check' => 'route.feature === \'git\'',
    ],

    // ------------------------------------------------------------ User model
    [
        'file' => 'app/Models/User.php',
        'mode' => 'after',
        'needle' => <<<'PHP'
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Pterodactyl\Models\UserSSHKey, $this>
     */
    public function sshKeys(): HasMany
    {
        return $this->hasMany(UserSSHKey::class);
    }
PHP,
        'insert' => <<<'PHP'

    /**
     * Returns all the GitHub accounts linked to this user.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Pterodactyl\Models\GithubAccount, $this>
     */
    public function githubAccounts(): HasMany
    {
        return $this->hasMany(GithubAccount::class);
    }
PHP,
        'check' => 'public function githubAccounts(): HasMany',
    ],

    // ------------------------------------------------------ admin nav partial
    [
        'file' => 'resources/views/partials/admin/settings/nav.blade.php',
        'mode' => 'after',
        'needle' => "<li @if(\$activeTab === 'advanced')class=\"active\"@endif><a href=\"{{ route('admin.settings.advanced') }}\">Advanced</a></li>",
        'insert' => "\n                    <li @if(\$activeTab === 'github')class=\"active\"@endif><a href=\"{{ route('admin.settings.github') }}\">GitHub</a></li>",
        'check' => "'github',
                    <li @if" === '' ? 'github-nav' : 'github-nav',
    ],
];

// Use a stable idempotency marker for the admin nav partial.
foreach ($ops as $i => $op) {
    if ($op['file'] === 'resources/views/partials/admin/settings/nav.blade.php') {
        $ops[$i]['check'] = "route('admin.settings.github')";
    }
}

$failed = 0;
foreach ($ops as $op) {
    // special case: config append (no simple anchor)
    if (($op['mode'] ?? '') === 'config-append') {
        $file = $panel . '/' . $op['file'];
        $content = (string) file_get_contents($file);
        if (str_contains($content, $op['check'])) {
            echo "SKIP   {$op['file']}  (already applied)\n";
            continue;
        }
        if (!str_ends_with(trim($content), '];')) {
            echo "ERROR  {$op['file']}  (config file missing closing array)\n";
            $failed++;
            continue;
        }
        $gitBlock = <<<'PHP'

    'git' => [
        'data_directory' => env('PTERODACTYL_GIT_DATA_DIRECTORY', '/var/lib/pterodactyl'),
        'enabled' => (bool) env('PTERODACTYL_GIT_ENABLED', true),
        'oauth' => [
            'enabled' => (bool) env('GITHUB_OAUTH_ENABLED', false),
            'client_id' => (string) env('GITHUB_OAUTH_CLIENT_ID', ''),
            'client_secret' => (string) env('GITHUB_OAUTH_CLIENT_SECRET', ''),
            'redirect_uri' => (string) env('GITHUB_OAUTH_REDIRECT_URI', rtrim(env('APP_URL', ''), '/') . '/account/github/oauth/callback'),
        ],
    ],
PHP;
        $content = rtrim($content);
        $content = substr($content, 0, -2) . $gitBlock . '];' . "\n";
        if (file_put_contents($file, $content) === false) {
            echo "ERROR  {$op['file']}  (write failed)\n";
            $failed++;
        } else {
            echo "PATCH  {$op['file']}  ok\n";
        }
        continue;
    }

    $result = patch($op['file'], $op['mode'], $op['needle'], $op['insert'], $op['check']);
    $status = strtoupper($result['status']);
    if ($result['status'] === 'error') {
        $failed++;
    }
    printf("%-6s %-60s %s\n", $status, $result['file'], $result['detail']);
}

if ($failed > 0) {
    fwrite(STDERR, "\n$failed operation(s) could not be applied. Check the anchors above – your panel may have customised files.\n");
    exit(1);
}

echo "\nAll patches applied successfully.\n";