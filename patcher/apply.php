<?php

/**
 * GitHub Integration – panel source patcher.
 *
 * Applies the required route/config/view-composer/router changes onto any
 * Pterodactyl Panel 1.15.x install. Every operation is idempotent and safe to
 * run multiple times. Anchors are matched flexibly (leading whitespace and
 * internal whitespace runs are ignored, CRLF tolerated) so custom themes and
 * panels patched by older revisions are handled without false "anchor not
 * found" failures; duplicate insertions from previous botched runs are removed.
 *
 * This version uses multi-anchor fallback strategies to work reliably with
 * customised panels. Each operation tries multiple anchor patterns from most
 * specific to most generic, including structural fallbacks (find-group-end,
 * last-occurrence). Panels with custom themes, reordered routes, or added
 * methods will still be patched correctly.
 *
 * Usage: php apply.php <absolute-path-to-panel>
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$panel = rtrim((string) ($argv[1] ?? ''), '/');
if ($panel === '' || !file_exists($panel . '/artisan') || !file_exists($panel . '/config/pterodactyl.php')) {
    fwrite(STDERR, "Usage: php apply.php <path-to-pterodactyl-panel>\n");
    exit(1);
}

/* ──────────────────────────────────────────────────────────────────── helpers */

/**
 * Builds a whitespace-tolerant regular expression from an anchor string.
 *
 * The panel files may differ from the shipped defaults (custom themes, prior
 * patch runs, alternate indentation or CRLF line endings). To stay safe on
 * real-world panels every needle is matched while ignoring indentation and
 * collapsing arbitrary whitespace runs inside a line to a single space.
 */
function tolerant_pattern(string $needle): string
{
    $lines = preg_split('/\R/', $needle);
    $parts = [];
    foreach ($lines as $line) {
        $norm = trim(preg_replace('/[ \t]+/', ' ', $line));
        $esc = preg_quote($norm, '~');
        $esc = str_replace(' ', '\h+', $esc);
        $parts[] = '^\h*' . $esc . '$';
    }

    return '(?m)' . implode("\n", $parts);
}

/**
 * Finds the byte position just past the end of a brace-delimited group
 * (e.g. a Route::group closure). The search starts at $startPos and counts
 * braces until the matching close-brace is found, then includes the optional
 * closing ');' or '});'.
 */
function find_group_end(string $content, int $startPos): int
{
    $depth = 0;
    $foundOpen = false;
    $len = strlen($content);
    for ($i = $startPos; $i < $len; $i++) {
        $ch = $content[$i];
        if ($ch === '{') {
            $depth++;
            $foundOpen = true;
        } elseif ($ch === '}' && $foundOpen) {
            $depth--;
            if ($depth === 0) {
                $rest = substr($content, $i);
                if (preg_match('/^\}\s*\)\s*;/', $rest, $m)) {
                    return $i + strlen($m[0]);
                }
                return $i + 1;
            }
        }
    }

    return $startPos;
}

/**
 * Returns the byte position right after the *last* occurrence of $needle
 * in $content (fuzzy), or null if not found at all.
 */
function find_after_last(string $content, string $needle): ?int
{
    $pos = strrpos($content, $needle);
    if ($pos !== false) {
        return $pos + strlen($needle);
    }
    $pattern = tolerant_pattern($needle);
    if (preg_match_all('~' . $pattern . '~', $content, $matches, PREG_OFFSET_CAPTURE) >= 1) {
        $last = end($matches[0]);

        return $last[1] + strlen($last[0]);
    }

    return null;
}

/**
 * Returns the byte position right *before* the first occurrence of $needle
 * in $content (fuzzy), or null if not found.
 */
function find_before_first(string $content, string $needle): ?int
{
    $pos = strpos($content, $needle);
    if ($pos !== false) {
        return $pos;
    }
    $pattern = tolerant_pattern($needle);
    if (preg_match('~' . $pattern . '~', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
        return (int) $m[0][1];
    }

    return null;
}

/* ──────────────────────────────────────── multi-anchor patch engine */

/**
 * Runs a patch operation for a file trying multiple anchor candidates.
 *
 * Each element of $anchors is an associative array with:
 *   needle  – anchor text (whitespace-tolerant matching)
 *   insert  – replacement/insertion text
 *   mode    – (optional) "after" | "before" | "replace" | "after_group_end"
 *             | "after_last" | "before_first"  (defaults to $defaultMode)
 *
 * @param string   $relPath     relative path under the panel root
 * @param string   $defaultMode fallback mode when an anchor omits 'mode'
 * @param array    $anchors     ordered list of candidate anchors
 * @param string   $check       marker substring; patch is skipped if present
 */
function patch_multi(string $relPath, string $defaultMode, array $anchors, string $check): array
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

    // Normalise line endings to LF for matching/check/dedup so that patches
    // authored on Windows (CRLF) behave identically on Linux panels (LF) and
    // never miss the idempotency check. The file keeps its original dominant
    // EOL on write.
    $originalEol = str_contains($content, "\r\n") ? "\r\n" : "\n";
    $content = str_replace("\r\n", "\n", $content);
    $check = str_replace("\r\n", "\n", $check);

    // Already applied?
    if (str_contains($content, $check)) {
        $result['status'] = 'skipped';
        $result['detail'] = 'already applied';

        return $result;
    }

    foreach ($anchors as $anchor) {
        $needle = $anchor['needle'];
        $insert = $anchor['insert'];
        $mode = $anchor['mode'] ?? $defaultMode;

        // Locate the anchor (exact first, then whitespace-tolerant).
        $pos = strpos($content, $needle);
        $matched = $needle;
        $tolerant = false;
        if ($pos === false) {
            $pattern = tolerant_pattern($needle);
            if (preg_match('~' . $pattern . '~', $content, $m, PREG_OFFSET_CAPTURE) === 1) {
                $pos = (int) $m[0][1];
                $matched = $m[0][0];
                $tolerant = true;
            }
        }
        if ($pos === false) {
            continue; // try next anchor
        }

        $len = strlen($matched);

        switch ($mode) {
            case 'after':
                $newContent = substr($content, 0, $pos + $len) . $insert . substr($content, $pos + $len);
                break;
            case 'before':
                $newContent = substr($content, 0, $pos) . $insert . substr($content, $pos);
                break;
            case 'replace':
                $newContent = substr($content, 0, $pos) . $insert . substr($content, $pos + $len);
                break;
            case 'after_group_end':
                $groupEnd = find_group_end($content, $pos);
                $newContent = substr($content, 0, $groupEnd) . $insert . substr($content, $groupEnd);
                break;
            case 'after_last':
                $lastPos = find_after_last($content, $needle);
                if ($lastPos === null) {
                    continue 2;
                }
                $newContent = substr($content, 0, $lastPos) . $insert . substr($content, $lastPos);
                break;
            case 'before_first':
                $firstPos = find_before_first($content, $needle);
                if ($firstPos === null) {
                    continue 2;
                }
                $newContent = substr($content, 0, $firstPos) . $insert . substr($content, $firstPos);
                break;
            default:
                continue 2;
        }

        // Never leave duplicate insertions behind (covers prior botched runs).
        $count = substr_count($newContent, $insert);
        if ($count > 1) {
            $first = strpos($newContent, $insert);
            $head = substr($newContent, 0, $first + strlen($insert));
            $tail = substr($newContent, $first + strlen($insert));
            $newContent = $head . str_replace($insert, '', $tail);
        }

        // Always work in LF; re-apply the file's original dominant EOL.
        $newContent = str_replace("\r\n", "\n", $newContent);
        if ($originalEol === "\r\n") {
            $newContent = str_replace("\n", "\r\n", $newContent);
        }

        if (file_put_contents($file, $newContent) === false) {
            $result['status'] = 'error';
            $result['detail'] = 'write failed';

            return $result;
        }

        $result['status'] = 'patched';
        $result['detail'] = $tolerant ? 'ok (fuzzy anchor)' : 'ok';

        return $result;
    }

    $result['status'] = 'error';
    $result['detail'] = 'anchor not found (tried ' . count($anchors) . ' patterns)';

    return $result;
}

/* ──────────────────────────────── insert-text blocks (shared anchors) */

$accountGithubRoutes = <<<'PHP'

    Route::prefix('/github')->group(function () {
        Route::get('/', Client\Account\GithubAccountController::class . '@index');
        Route::post('/', Client\Account\GithubAccountController::class . '@store');
        Route::delete('/{account}', Client\Account\GithubAccountController::class . '@destroy');
        Route::get('/repositories', Client\Account\GithubAccountController::class . '@repositories');
    });
PHP;

$serverGithubRoutes = <<<'PHP'
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

        Route::get('/commit', [Client\Servers\GithubController::class, 'commitDetail']);
        Route::get('/stash', [Client\Servers\GithubController::class, 'stashList']);
        Route::post('/stash', [Client\Servers\GithubController::class, 'stashPush']);
        Route::post('/stash/pop', [Client\Servers\GithubController::class, 'stashPop']);
        Route::post('/stash/drop', [Client\Servers\GithubController::class, 'stashDrop']);
    });

PHP;

$oauthCallbackRoutes = <<<'PHP'
Route::get('/account/github/oauth/begin', [Pterodactyl\Http\Controllers\Auth\GithubOAuthController::class, 'begin'])
    ->name('github.oauth.begin');
Route::get('/account/github/oauth/callback', [Pterodactyl\Http\Controllers\Auth\GithubOAuthController::class, 'callback'])
    ->name('github.oauth.callback');

PHP;

$githubAccountInsert = <<<'PHP'
            'git' => [
                'enabled' => (bool) config('pterodactyl.git.enabled', true),
            ],
PHP;

/* ──────────────────────────────────────────────────── operations */

$ops = [
    // ============================================================ routes

    // ---- account-level github routes (after ssh-keys group) ----
    [
        'file' => 'routes/api-client.php',
        'mode' => 'after',
        'anchors' => [
            // Primary: full ssh-keys block
            [
                'needle' => <<<'PHP'
    Route::prefix('/ssh-keys')->group(function () {
        Route::get('/', [Client\SSHKeyController::class, 'index']);
        Route::post('/', [Client\SSHKeyController::class, 'store']);
        Route::post('/remove', [Client\SSHKeyController::class, 'delete']);
    });
PHP,
                'insert' => $accountGithubRoutes,
            ],
            // Fallback: ssh-keys prefix (insert after group end)
            [
                'needle' => "Route::prefix('/ssh-keys')->group(function () {",
                'insert' => $accountGithubRoutes,
                'mode' => 'after_group_end',
            ],
            // Fallback: any SSHKeyController reference (insert after group end)
            [
                'needle' => "SSHKeyController::class, 'delete'",
                'insert' => $accountGithubRoutes,
                'mode' => 'after_group_end',
            ],
            // Fallback: SSHKeyController index
            [
                'needle' => "SSHKeyController::class, 'index'",
                'insert' => $accountGithubRoutes,
                'mode' => 'after_group_end',
            ],
            // Fallback: any ssh-keys route
            [
                'needle' => "'/ssh-keys'",
                'insert' => $accountGithubRoutes,
                'mode' => 'after_group_end',
            ],
        ],
        'check' => "Client\\Account\\GithubAccountController",
    ],

    // ---- server-level github routes (before subusers group) ----
    [
        'file' => 'routes/api-client.php',
        'mode' => 'before',
        'anchors' => [
            // Primary: full subusers block head
            [
                'needle' => <<<'PHP'
    Route::group(['prefix' => '/users'], function () {
        Route::get('/', [Client\Servers\SubuserController::class, 'index']);
PHP,
                'insert' => $serverGithubRoutes,
            ],
            // Fallback: subusers prefix
            [
                'needle' => "Route::group(['prefix' => '/users']",
                'insert' => $serverGithubRoutes,
            ],
            // Fallback: SubuserController index
            [
                'needle' => "SubuserController::class, 'index'",
                'insert' => $serverGithubRoutes,
                'mode' => 'before_first',
            ],
            // Fallback: SubuserController store
            [
                'needle' => "SubuserController::class, 'store'",
                'insert' => $serverGithubRoutes,
                'mode' => 'before_first',
            ],
            // Fallback: any subusers route
            [
                'needle' => "'/users'",
                'insert' => $serverGithubRoutes,
                'mode' => 'before_first',
            ],
        ],
        'check' => "Client\\Servers\\GithubController::class, 'show'",
    ],

    // ---- admin settings GET route ----
    [
        'file' => 'routes/admin.php',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "    Route::get('/advanced', [Admin\\Settings\\AdvancedController::class, 'index'])->name('admin.settings.advanced');",
                'insert' => "\n    Route::get('/github', [Admin\\Settings\\GithubController::class, 'index'])->name('admin.settings.github');",
            ],
            // Fallback: shorter anchor
            [
                'needle' => "Route::get('/advanced', [Admin\\Settings\\AdvancedController::class, 'index'])",
                'insert' => "\n    Route::get('/github', [Admin\\Settings\\GithubController::class, 'index'])->name('admin.settings.github');",
            ],
            // Fallback: AdvancedController index
            [
                'needle' => "AdvancedController::class, 'index'",
                'insert' => "\n    Route::get('/github', [Admin\\Settings\\GithubController::class, 'index'])->name('admin.settings.github');",
            ],
            // Fallback: any advanced settings route
            [
                'needle' => "'/advanced'",
                'insert' => "\n    Route::get('/github', [Admin\\Settings\\GithubController::class, 'index'])->name('admin.settings.github');",
                'mode' => 'after_last',
            ],
        ],
        'check' => "name('admin.settings.github')",
    ],

    // ---- admin settings PATCH route ----
    [
        'file' => 'routes/admin.php',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "    Route::patch('/advanced', [Admin\\Settings\\AdvancedController::class, 'update']);",
                'insert' => "\n    Route::patch('/github', [Admin\\Settings\\GithubController::class, 'update']);",
            ],
            // Fallback
            [
                'needle' => "Route::patch('/advanced', [Admin\\Settings\\AdvancedController::class, 'update'])",
                'insert' => "\n    Route::patch('/github', [Admin\\Settings\\GithubController::class, 'update']);",
            ],
            // Fallback: AdvancedController update
            [
                'needle' => "AdvancedController::class, 'update'",
                'insert' => "\n    Route::patch('/github', [Admin\\Settings\\GithubController::class, 'update']);",
            ],
        ],
        'check' => "Route::patch('/github'",
    ],

    // ---- admin audit log GET route ----
    [
        'file' => 'routes/admin.php',
        'mode' => 'after',
        'anchors' => [
            [
                'needle' => "Route::patch('/github'",
                'insert' => "\n    Route::get('/github/audit-log', [Admin\\Settings\\GithubController::class, 'auditLog'])->name('admin.github.audit-log');",
            ],
        ],
        'check' => "name('admin.github.audit-log')",
    ],

    // ---- base routes: OAuth callbacks (before catch-all React route) ----
    [
        'file' => 'routes/base.php',
        'mode' => 'before',
        'anchors' => [
            // Primary: full catch-all
            [
                'needle' => <<<'PHP'
Route::get('/{react}', [Base\IndexController::class, 'index'])
    ->where('react', '^(?!(\/)?(api|auth|admin|daemon)).+');
PHP,
                'insert' => $oauthCallbackRoutes,
            ],
            // Fallback: where clause
            [
                'needle' => "->where('react', '^(?!(\\/)?(api|auth|admin|daemon)).+')",
                'insert' => $oauthCallbackRoutes,
            ],
            // Fallback: IndexController catch-all
            [
                'needle' => "IndexController::class, 'index'",
                'insert' => $oauthCallbackRoutes,
                'mode' => 'before_first',
            ],
            // Fallback: the react regex pattern
            [
                'needle' => "(api|auth|admin|daemon)",
                'insert' => $oauthCallbackRoutes,
                'mode' => 'before_first',
            ],
        ],
        'check' => 'github.oauth.begin',
    ],

    // ================================================= config/pterodactyl.php
    // (handled specially below – config-append mode)

    // ============================================= ViewComposer (site settings)
    [
        'file' => 'app/Http/ViewComposers/AssetComposer.php',
        'mode' => 'replace',
        'anchors' => [
            // Primary: full recaptcha block
            [
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
            ],
            // Fallback: recaptcha key
            [
                'needle' => "'recaptcha' => [",
                'insert' => $githubAccountInsert . "\n",
                'mode' => 'after',
            ],
            // Fallback: siteKey line (insert after it)
            [
                'needle' => "'siteKey' => config('recaptcha.website_key')",
                'insert' => ",\n            " . $githubAccountInsert,
                'mode' => 'after',
            ],
            // Fallback: toArray (insert before it)
            [
                'needle' => "->toArray()",
                'insert' => "            " . $githubAccountInsert . ",\n",
                'mode' => 'before',
            ],
        ],
        'check' => "'enabled' => (bool) config('pterodactyl.git.enabled', true)",
    ],

    // ======================================================== state settings
    [
        'file' => 'resources/scripts/state/settings.ts',
        'mode' => 'replace',
        'anchors' => [
            // Primary: full recaptcha block + interface close
            [
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
            ],
            // Fallback: siteKey line
            [
                'needle' => "siteKey: string;\n    };",
                'insert' => "siteKey: string;\n    };\n    git: {\n        enabled: boolean;\n    };",
                'mode' => 'replace',
            ],
            // Fallback: just before closing of interface
            [
                'needle' => "siteKey: string;",
                'insert' => "\n    git: {\n        enabled: boolean;\n    };",
                'mode' => 'after',
            ],
        ],
        'check' => 'git: {',
    ],

    // ============================================================== routes.ts

    // ---- imports ----
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "import ServerActivityLogContainer from '@/components/server/ServerActivityLogContainer';",
                'insert' => "\nimport GithubContainer from '@/components/server/git/GitContainer';\nimport AccountGithubContainer from '@/components/dashboard/GithubContainer';",
            ],
            // Fallback: ServerActivityLogContainer
            [
                'needle' => "ServerActivityLogContainer",
                'insert' => "\nimport GithubContainer from '@/components/server/git/GitContainer';\nimport AccountGithubContainer from '@/components/dashboard/GithubContainer';",
                'mode' => 'after_last',
            ],
            // Fallback: ActivityLogContainer (other import)
            [
                'needle' => "ActivityLogContainer",
                'insert' => "\nimport GithubContainer from '@/components/server/git/GitContainer';\nimport AccountGithubContainer from '@/components/dashboard/GithubContainer';",
                'mode' => 'after_last',
            ],
        ],
        'check' => "import AccountGithubContainer",
    ],

    // ---- dashboard route ----
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'anchors' => [
            // Primary: activity route block
            [
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
            ],
            // Fallback: ActivityLogContainer component reference
            [
                'needle' => "component: ActivityLogContainer,",
                'insert' => <<<'PHP'
        {
            path: '/github',
            name: 'GitHub',
            component: AccountGithubContainer,
            feature: 'git',
        },
PHP,
                'mode' => 'after',
            ],
            // Fallback: activity path
            [
                'needle' => "path: '/activity'",
                'insert' => <<<'PHP'
        {
            path: '/github',
            name: 'GitHub',
            component: AccountGithubContainer,
            feature: 'git',
        },
PHP,
                'mode' => 'after_last',
            ],
        ],
        'check' => 'component: AccountGithubContainer',
    ],

    // ---- server route ----
    [
        'file' => 'resources/scripts/routers/routes.ts',
        'mode' => 'after',
        'anchors' => [
            // Primary: server activity route block
            [
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
            ],
            // Fallback: ServerActivityLogContainer component ref
            [
                'needle' => "component: ServerActivityLogContainer,",
                'insert' => <<<'PHP'
        {
            path: '/git',
            permission: 'git.*',
            name: 'GitHub',
            component: GithubContainer,
            feature: 'git',
        },
PHP,
                'mode' => 'after',
            ],
            // Fallback: permission: 'activity.*'
            [
                'needle' => "permission: 'activity.*'",
                'insert' => <<<'PHP'
        {
            path: '/git',
            permission: 'git.*',
            name: 'GitHub',
            component: GithubContainer,
            feature: 'git',
        },
PHP,
                'mode' => 'after_last',
            ],
        ],
        'check' => "path: '/git',",
    ],

    // =========================================================== ServerRouter

    // ---- gitEnabled state ----
    [
        'file' => 'resources/scripts/routers/ServerRouter.tsx',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);",
                'insert' => "\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
            ],
            // Fallback: rootAdmin
            [
                'needle' => "state.user.data!.rootAdmin",
                'insert' => "\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
                'mode' => 'after_last',
            ],
            // Fallback: useStoreState (last occurrence before route rendering)
            [
                'needle' => "useStoreState",
                'insert' => "\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
                'mode' => 'after_last',
            ],
        ],
        'check' => 'state.settings.data?.git?.enabled',
    ],

    // ---- feature filter (before .map) ----
    [
        'file' => 'resources/scripts/routers/ServerRouter.tsx',
        'mode' => 'replace',
        'anchors' => [
            // Primary
            [
                'needle' => <<<'PHP'
                                    {routes.server.map(({ path, permission, component: Component }) => (
PHP,
                'insert' => <<<'PHP'
                                    {routes.server
                                        .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))
                                        .map(({ path, permission, component: Component }) => (
PHP,
            ],
            // Fallback: routes.server.map
            [
                'needle' => "routes.server.map(({ path, permission, component: Component }) => (",
                'insert' => "routes.server\n                                        .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))\n                                        .map(({ path, permission, component: Component }) => (",
                'mode' => 'replace',
            ],
            // Fallback: component: Component
            [
                'needle' => "component: Component })",
                'insert' => "component: Component })",
            ],
        ],
        'check' => "                                        .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))",
    ],

    // ======================================================= DashboardRouter

    // ---- import useStoreState ----
    [
        'file' => 'resources/scripts/routers/DashboardRouter.tsx',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "import { useLocation } from 'react-router';",
                'insert' => "\nimport { useStoreState } from 'easy-peasy';",
            ],
            // Fallback: useLocation
            [
                'needle' => "useLocation",
                'insert' => "\nimport { useStoreState } from 'easy-peasy';",
                'mode' => 'before_first',
            ],
        ],
        'check' => "import { useStoreState }",
    ],

    // ---- gitEnabled state ----
    [
        'file' => 'resources/scripts/routers/DashboardRouter.tsx',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "    const location = useLocation();",
                'insert' => "\n    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
            ],
            // Fallback: location = useLocation
            [
                'needle' => "const location = useLocation()",
                'insert' => "\n    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
            ],
            // Fallback: useLocation() call
            [
                'needle' => "useLocation()",
                'insert' => "\n    const rootAdmin = useStoreState((state) => state.user.data!.rootAdmin);\n    const gitEnabled = useStoreState((state) => state.settings.data?.git?.enabled);",
                'mode' => 'after_last',
            ],
        ],
        'check' => 'state.settings.data?.git?.enabled',
    ],

    // ---- feature filter ----
    [
        'file' => 'resources/scripts/routers/DashboardRouter.tsx',
        'mode' => 'replace',
        'anchors' => [
            // Primary
            [
                'needle' => <<<'PHP'
                            .filter((route) => !!route.name)
                            .map(({ path, name, exact = false }) => (
PHP,
                'insert' => <<<'PHP'
                            .filter((route) => !!route.name)
                            .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))
                            .map(({ path, name, exact = false }) => (
PHP,
            ],
            // Fallback: .map with destructuring
            [
                'needle' => ".map(({ path, name, exact = false }) => (",
                'insert' => ".filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))\n                            .map(({ path, name, exact = false }) => (",
                'mode' => 'before',
            ],
            // Fallback: !!route.name
            [
                'needle' => ".filter((route) => !!route.name)",
                'insert' => "\n                            .filter((route) => !(route.feature === 'git' && !gitEnabled && !rootAdmin))",
                'mode' => 'after_last',
            ],
        ],
        'check' => "route.feature === 'git'",
    ],

    // ========================================================= User model
    [
        'file' => 'app/Models/User.php',
        'mode' => 'after',
        'anchors' => [
            // Primary: sshKeys method
            [
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
            ],
            // Fallback: sshKeys method signature
            [
                'needle' => "public function sshKeys(): HasMany",
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
            ],
            // Fallback: UserSSHKey::class
            [
                'needle' => "UserSSHKey::class",
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
            ],
            // Fallback: sshKeys
            [
                'needle' => "function sshKeys()",
                'insert' => <<<'PHP'

    /**
     * Returns all the GitHub accounts linked to this user.
     */
    public function githubAccounts(): HasMany
    {
        return $this->hasMany(GithubAccount::class);
    }
PHP,
            ],
        ],
        'check' => 'public function githubAccounts(): HasMany',
    ],

    // ============================================== Permission model (git group)
    [
        'file' => 'app/Models/Permission.php',
        'mode' => 'replace',
        'anchors' => [
            // Primary: full activity group
            [
                'needle' => <<<'PHP'
        'activity' => [
            'description' => 'Permissions that control a user\'s access to the server activity logs.',
            'keys' => [
                'read' => 'Allows a user to view the activity logs for the server.',
            ],
        ],
    ];
PHP,
                'insert' => <<<'PHP'
        'activity' => [
            'description' => 'Permissions that control a user\'s access to the server activity logs.',
            'keys' => [
                'read' => 'Allows a user to view the activity logs for the server.',
            ],
        ],

        'git' => [
            'description' => 'Permissions that control a user\'s access to the GitHub integration for this server.',
            'keys' => [
                'read' => 'Allows a user to view the GitHub integration, repository status, changes, branches, history and diffs.',
                'pull' => 'Allows a user to pull changes from GitHub into the server.',
                'push' => 'Allows a user to commit and push changes to GitHub.',
                'commit' => 'Allows a user to stage, unstage, discard and commit local changes.',
                'manage-branches' => 'Allows a user to create, switch, merge and delete branches.',
                'manage-repository' => 'Allows a user to connect, initialize, disconnect and reset the repository.',
                'manage-gitignore' => 'Allows a user to view and edit the .gitignore file.',
                'revert' => 'Allows a user to revert commits.',
                'force-reset' => 'Allows a user to perform a destructive hard reset of the repository.',
            ],
        ],
    ];
PHP,
            ],
            // Fallback: activity group (shorter)
            [
                'needle' => <<<'PHP'
        'activity' => [
            'description' => 'Permissions that control a user\'s access to the server activity logs.',
            'keys' => [
                'read' => 'Allows a user to view the activity logs for the server.',
            ],
        ],
PHP,
                'insert' => <<<'PHP'

        'git' => [
            'description' => 'Permissions that control a user\'s access to the GitHub integration for this server.',
            'keys' => [
                'read' => 'Allows a user to view the GitHub integration, repository status, changes, branches, history and diffs.',
                'pull' => 'Allows a user to pull changes from GitHub into the server.',
                'push' => 'Allows a user to commit and push changes to GitHub.',
                'commit' => 'Allows a user to stage, unstage, discard and commit local changes.',
                'manage-branches' => 'Allows a user to create, switch, merge and delete branches.',
                'manage-repository' => 'Allows a user to connect, initialize, disconnect and reset the repository.',
                'manage-gitignore' => 'Allows a user to view and edit the .gitignore file.',
                'revert' => 'Allows a user to revert commits.',
                'force-reset' => 'Allows a user to perform a destructive hard reset of the repository.',
            ],
        ],
    ];
PHP,
                'mode' => 'replace',
            ],
            // Fallback: activity description
            [
                'needle' => "Permissions that control a user\\'s access to the server activity logs.",
                'insert' => "\n\n        'git' => [\n            'description' => 'Permissions that control a user\\'s access to the GitHub integration for this server.',\n            'keys' => [\n                'read' => 'Allows a user to view the GitHub integration, repository status, changes, branches, history and diffs.',\n                'pull' => 'Allows a user to pull changes from GitHub into the server.',\n                'push' => 'Allows a user to commit and push changes to GitHub.',\n                'commit' => 'Allows a user to stage, unstage, discard and commit local changes.',\n                'manage-branches' => 'Allows a user to create, switch, merge and delete branches.',\n                'manage-repository' => 'Allows a user to connect, initialize, disconnect and reset the repository.',\n                'manage-gitignore' => 'Allows a user to view and edit the .gitignore file.',\n                'revert' => 'Allows a user to revert commits.',\n                'force-reset' => 'Allows a user to perform a destructive hard reset of the repository.',\n            ],\n        ],",
                'mode' => 'after',
            ],
            // Fallback: just the closing ];
            [
                'needle' => "    ];",
                'insert' => "\n\n        'git' => [\n            'description' => 'Permissions that control a user\\'s access to the GitHub integration for this server.',\n            'keys' => [\n                'read' => 'Allows a user to view the GitHub integration, repository status, changes, branches, history and diffs.',\n                'pull' => 'Allows a user to pull changes from GitHub into the server.',\n                'push' => 'Allows a user to commit and push changes to GitHub.',\n                'commit' => 'Allows a user to stage, unstage, discard and commit local changes.',\n                'manage-branches' => 'Allows a user to create, switch, merge and delete branches.',\n                'manage-repository' => 'Allows a user to connect, initialize, disconnect and reset the repository.',\n                'manage-gitignore' => 'Allows a user to view and edit the .gitignore file.',\n                'revert' => 'Allows a user to revert commits.',\n                'force-reset' => 'Allows a user to perform a destructive hard reset of the repository.',\n            ],\n        ],",
                'mode' => 'before_first',
            ],
        ],
        'check' => "'git' => [",
    ],

    // Add the ACTION_GIT_* permission constants referenced by the server Git
    // request classes during authorization (ViewGithubRequest, PullRequest, ...).
    // Without these, every server git route throws "Undefined constant
    // Pterodactyl\Models\Permission::ACTION_GIT_*".
    [
        'file' => 'app/Models/Permission.php',
        'mode' => 'after',
        'anchors' => [
            // Primary: after the activity read constant
            [
                'needle' => "public const ACTION_ACTIVITY_READ = 'activity.read';",
                'insert' => <<<'PHP'

    public const ACTION_GIT_READ = 'git.read';
    public const ACTION_GIT_PULL = 'git.pull';
    public const ACTION_GIT_PUSH = 'git.push';
    public const ACTION_GIT_COMMIT = 'git.commit';
    public const ACTION_GIT_MANAGE_BRANCHES = 'git.manage-branches';
    public const ACTION_GIT_MANAGE_REPOSITORY = 'git.manage-repository';
    public const ACTION_GIT_MANAGE_GITIGNORE = 'git.manage-gitignore';
    public const ACTION_GIT_REVERT = 'git.revert';
    public const ACTION_GIT_FORCE_RESET = 'git.force-reset';
PHP,
            ],
            // Fallback: the activity read constant name
            [
                'needle' => "ACTION_ACTIVITY_READ",
                'insert' => <<<'PHP'

    public const ACTION_GIT_READ = 'git.read';
    public const ACTION_GIT_PULL = 'git.pull';
    public const ACTION_GIT_PUSH = 'git.push';
    public const ACTION_GIT_COMMIT = 'git.commit';
    public const ACTION_GIT_MANAGE_BRANCHES = 'git.manage-branches';
    public const ACTION_GIT_MANAGE_REPOSITORY = 'git.manage-repository';
    public const ACTION_GIT_MANAGE_GITIGNORE = 'git.manage-gitignore';
    public const ACTION_GIT_REVERT = 'git.revert';
    public const ACTION_GIT_FORCE_RESET = 'git.force-reset';
PHP,
            ],
        ],
        'check' => "ACTION_GIT_READ",
    ],

    // ================================================= admin nav partial
    [
        'file' => 'resources/views/partials/admin/settings/nav.blade.php',
        'mode' => 'after',
        'anchors' => [
            // Primary
            [
                'needle' => "<li @if(\$activeTab === 'advanced')class=\"active\"@endif><a href=\"{{ route('admin.settings.advanced') }}\">Advanced</a></li>",
                'insert' => "\n                    <li @if(\$activeTab === 'github')class=\"active\"@endif><a href=\"{{ route('admin.settings.github') }}\">GitHub</a></li>",
            ],
            // Fallback: advanced link
            [
                'needle' => "route('admin.settings.advanced')",
                'insert' => "\n                    <li @if(\$activeTab === 'github')class=\"active\"@endif><a href=\"{{ route('admin.settings.github') }}\">GitHub</a></li>",
                'mode' => 'after_last',
            ],
            // Fallback: any admin.settings route
            [
                'needle' => "admin.settings.advanced",
                'insert' => "\n                    <li @if(\$activeTab === 'github')class=\"active\"@endif><a href=\"{{ route('admin.settings.github') }}\">GitHub</a></li>",
                'mode' => 'after_last',
            ],
            // Fallback: 'advanced' activeTab
            [
                'needle' => "'advanced'",
                'insert' => "\n                    <li @if(\$activeTab === 'github')class=\"active\"@endif><a href=\"{{ route('admin.settings.github') }}\">GitHub</a></li>",
                'mode' => 'after_last',
            ],
        ],
        'check' => "route('admin.settings.github')",
    ],

    // ================================================= SettingsServiceProvider
    // Register the git settings keys so admin-saved values merge into
    // config('pterodactyl.git.*') on boot (mirrors how SMTP settings work).
    [
        'file' => 'app/Providers/SettingsServiceProvider.php',
        'mode' => 'after',
        'anchors' => [
            // Primary: after the allocations range_end key
            [
                'needle' => "'pterodactyl:client_features:allocations:range_end',",
                'insert' => "\n        'pterodactyl:git:enabled',\n        'pterodactyl:git:oauth:enabled',\n        'pterodactyl:git:oauth:client_id',\n        'pterodactyl:git:oauth:client_secret',\n        'pterodactyl:git:oauth:redirect_uri',",
            ],
            // Fallback: allocations range_start
            [
                'needle' => "'pterodactyl:client_features:allocations:range_start',",
                'insert' => "\n        'pterodactyl:git:enabled',\n        'pterodactyl:git:oauth:enabled',\n        'pterodactyl:git:oauth:client_id',\n        'pterodactyl:git:oauth:client_secret',\n        'pterodactyl:git:oauth:redirect_uri',",
            ],
            // Fallback: the auth 2fa key
            [
                'needle' => "'pterodactyl:auth:2fa_required',",
                'insert' => "\n        'pterodactyl:git:enabled',\n        'pterodactyl:git:oauth:enabled',\n        'pterodactyl:git:oauth:client_id',\n        'pterodactyl:git:oauth:client_secret',\n        'pterodactyl:git:oauth:redirect_uri',",
            ],
            // Fallback: the $keys array opening
            [
                'needle' => "protected array \$keys = [",
                'insert' => "\n        'pterodactyl:git:enabled',\n        'pterodactyl:git:oauth:enabled',\n        'pterodactyl:git:oauth:client_id',\n        'pterodactyl:git:oauth:client_secret',\n        'pterodactyl:git:oauth:redirect_uri',",
            ],
        ],
        'check' => "'pterodactyl:git:enabled'",
    ],

    // Register the OAuth client secret as an encrypted key so it is stored
    // encrypted in the database and decrypted transparently when loaded.
    // NOTE: a simple "after" insert here would collide with op 1 above, whose
    // combined insert block already contains the client_secret line; the shared
    // duplicate-cleaner would then delete op 2's own insertion. Using a full
    // block replace keeps the inserted text unique.
    [
        'file' => 'app/Providers/SettingsServiceProvider.php',
        'mode' => 'replace',
        'anchors' => [
            // Primary: the full encrypted keys array (stock layout)
            [
                'needle' => <<<'PHP'
    protected static array $encrypted = [
        'mail:mailers:smtp:password',
    ];
PHP,
                'insert' => <<<'PHP'
    protected static array $encrypted = [
        'mail:mailers:smtp:password',
        'pterodactyl:git:oauth:client_secret',
    ];
PHP,
            ],
            // Fallback: the encrypted array opening line
            [
                'needle' => "static array \$encrypted = [",
                'insert' => "\n        'pterodactyl:git:oauth:client_secret',",
            ],
        ],
        'check' => "'mail:mailers:smtp:password',\n        'pterodactyl:git:oauth:client_secret',",
    ],
];

/* ──────────────────────────────────────────────── execution */

$failed = 0;
$skipped = 0;
$patched = 0;

foreach ($ops as $op) {
    $result = patch_multi($op['file'], $op['mode'], $op['anchors'], $op['check']);
    $status = strtoupper($result['status']);

    if ($result['status'] === 'error') {
        $failed++;
    } elseif ($result['status'] === 'skipped') {
        $skipped++;
    } else {
        $patched++;
    }

    printf("%-6s %-60s %s\n", $status, $result['file'], $result['detail']);
}

// ---- config/pterodactyl.php (special: append before ];) ----
$configFile = $panel . '/config/pterodactyl.php';
if (file_exists($configFile)) {
    $configContent = (string) file_get_contents($configFile);
    if (str_contains($configContent, "'git' => [")) {
        echo "SKIP   config/pterodactyl.php                              already applied\n";
        $skipped++;
    } else {
        $trimmed = rtrim($configContent);
        // Handle both ]);\n and ];\n endings
        if (str_ends_with($trimmed, '];') || str_ends_with($trimmed, ']);')) {
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
            // Insert before the ]; or ]);
            if (str_ends_with($trimmed, ']);')) {
                $configContent = substr($configContent, 0, -3) . $gitBlock . ']);' . "\n";
            } else {
                $configContent = substr($configContent, 0, -2) . $gitBlock . '];' . "\n";
            }
            if (file_put_contents($configFile, $configContent) === false) {
                echo "ERROR  config/pterodactyl.php                              write failed\n";
                $failed++;
            } else {
                echo "PATCH  config/pterodactyl.php                              ok\n";
                $patched++;
            }
        } else {
            // Fallback: try to find last ];
            $lastClose = strrpos($configContent, '];');
            if ($lastClose !== false) {
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
                $configContent = substr($configContent, 0, $lastClose) . $gitBlock . '];' . "\n";
                if (file_put_contents($configFile, $configContent) === false) {
                    echo "ERROR  config/pterodactyl.php                              write failed\n";
                    $failed++;
                } else {
                    echo "PATCH  config/pterodactyl.php                              ok\n";
                    $patched++;
                }
            } else {
                echo "ERROR  config/pterodactyl.php                              closing array not found\n";
                $failed++;
            }
        }
    }
} else {
    echo "ERROR  config/pterodactyl.php                              file missing\n";
    $failed++;
}

// ---- report ----
if ($failed > 0) {
    fwrite(STDERR, "\n$failed operation(s) could not be applied. The panel may have heavily customised files.\n");
    fwrite(STDERR, "Patches applied: $patched | Skipped (already applied): $skipped | Failed: $failed\n\n");
    fwrite(STDERR, "You can manually add the missing code. The installer will continue for remaining steps.\n");
    // Exit 0 so the installer can continue with remaining steps
    exit(0);
}

echo "\nAll patches applied successfully. ($patched patched, $skipped skipped)\n";
