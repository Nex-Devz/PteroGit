@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'github'])

@section('title')
    GitHub Integration Settings
@endsection

@section('content-header')
    <h1>GitHub Integration<small>Configure the built-in GitHub module and OAuth2 sign-in for your panel.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.settings') }}">Settings</a></li>
        <li class="active">GitHub</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')

    @if(session('success'))
        <div class="alert alert-success alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger alert-dismissible">
            <button type="button" class="close" data-dismiss="alert">&times;</button>
            <strong>Validation Error:</strong>
            <ul style="margin-bottom: 0;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row">
        <div class="col-md-8">
            <form action="{{ route('admin.settings.github') }}" method="POST">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Module Status</h3>
                        <div class="box-tools pull-right">
                            <a href="{{ route('admin.github.audit-log') }}" class="btn btn-xs btn-default"><i class="fa fa-list"></i> Audit Log</a>
                        </div>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">GitHub Integration</label>
                                <div>
                                    <select class="form-control" name="pterodactyl:git:enabled">
                                        <option value="true" @if(old('pterodactyl:git:enabled', $enabled)) selected @endif>Enabled</option>
                                        <option value="false" @if(!old('pterodactyl:git:enabled', $enabled)) selected @endif>Disabled</option>
                                    </select>
                                    <p class="text-muted small">When disabled, the GitHub module is completely hidden from non-admin users. Root administrators will still see the navigation with a maintenance notice.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">OAuth2 Sign-In</label>
                                <div>
                                    <select class="form-control" name="pterodactyl:git:oauth:enabled">
                                        <option value="false" @if(!old('pterodactyl:git:oauth:enabled', $oauthEnabled)) selected @endif>Disabled</option>
                                        <option value="true" @if(old('pterodactyl:git:oauth:enabled', $oauthEnabled)) selected @endif>Enabled</option>
                                    </select>
                                    <p class="text-muted small">Allow users to link their GitHub account via 1-click OAuth2 login. Requires an OAuth App on GitHub.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">OAuth2 Application</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">Client ID</label>
                                <div>
                                    <input type="text" class="form-control" name="pterodactyl:git:oauth:client_id" value="{{ old('pterodactyl:git:oauth:client_id', $clientId) }}" placeholder="e.g. Ov23li..." />
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Client Secret</label>
                                <div>
                                    <input type="password" class="form-control" name="pterodactyl:git:oauth:client_secret" placeholder="{{ $clientSecret ? '•••••••••••••••• (saved)' : 'Enter client secret' }}" />
                                    <p class="text-muted small">Leave blank to keep current. Enter <code>!e</code> to clear.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-12">
                                <label class="control-label">Authorization Callback URL</label>
                                <div>
                                    <input type="text" class="form-control" name="pterodactyl:git:oauth:redirect_uri" value="{{ old('pterodactyl:git:oauth:redirect_uri', $redirectUri) }}" placeholder="{{ url('/account/github/oauth/callback') }}" />
                                    <p class="text-muted small">Paste this exact URL as the <strong>Authorization callback URL</strong> in your GitHub OAuth App settings.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box box-primary">
                    <div class="box-footer">
                        {{ csrf_field() }}
                        <button type="submit" name="_method" value="PATCH" class="btn btn-sm btn-primary pull-right">Save Settings</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-md-4">
            <div class="box box-info">
                <div class="box-header with-border">
                    <h3 class="box-title"><i class="fa fa-book"></i> Setup Guide</h3>
                </div>
                <div class="box-body">
                    <h4>1. Prerequisites</h4>
                    <ul>
                        <li>Pterodactyl Panel <code>1.15.x</code></li>
                        <li>PHP <code>^8.2</code></li>
                        <li>Git installed on the panel host server</li>
                        <li>The <code>pterodactyl</code> system user must own the server data directories</li>
                    </ul>

                    <h4>2. Installation</h4>
                    <p>Run the installer as root on your panel host:</p>
                    <pre style="background:#1a1a2e;color:#e0e0e0;padding:10px;border-radius:4px;font-size:11px;word-wrap:break-word;white-space:pre-wrap;">bash &lt;(curl -sSL https://raw.githubusercontent.com/Nex-Devz/PteroGit/main/install.sh)</pre>
                    <p class="text-muted small">This backs up all modified files, patches routes/models/frontend, runs the migration, and rebuilds the frontend.</p>

                    <h4>3. GitHub Personal Access Token</h4>
                    <p>Users connect their GitHub account using a PAT with <code>repo</code> scope:</p>
                    <ol>
                        <li>Go to <a href="https://github.com/settings/tokens" target="_blank">github.com/settings/tokens</a></li>
                        <li>Click <strong>Generate new token (classic)</strong></li>
                        <li>Select the <code>repo</code> scope (full control of private repositories)</li>
                        <li>Copy the token and paste it in the panel under <strong>User &rarr; GitHub</strong> (<code>{{ url('/account/github') }}</code>)</li>
                        <li class="text-muted small">If your panel uses a third-party theme that replaces the account navigation, the <em>User &rarr; GitHub</em> menu item may not render. The page is still available at <code>{{ url('/account/github') }}</code>, and server operators can reach it from the <strong>GitHub</strong> tab of any server via the "Connect GitHub" button when no account is linked.</li>
                    </ol>

                    <h4>4. GitHub OAuth2 (Optional)</h4>
                    <p>To enable 1-click sign-in for users:</p>
                    <ol>
                        <li>Go to <a href="https://github.com/settings/developers" target="_blank">github.com/settings/developers</a></li>
                        <li>Click <strong>OAuth Apps &rarr; New OAuth App</strong></li>
                        <li><strong>Application name:</strong> Your panel name</li>
                        <li><strong>Homepage URL:</strong> <code>{{ config('app.url', 'https://your-panel.com') }}</code></li>
                        <li><strong>Authorization callback URL:</strong> <code>{{ url('/account/github/oauth/callback') }}</code></li>
                        <li>Copy the <strong>Client ID</strong> and <strong>Client Secret</strong> into the fields on the left</li>
                        <li>Set <strong>OAuth2 Sign-In</strong> to <em>Enabled</em> and save</li>
                    </ol>

                    <h4>5. User Permissions</h4>
                    <p>After installation, a <code>git.*</code> permission group is auto-registered. Go to <strong>Admin &rarr; Locations &rarr; [Node] &rarr; [Allocation]</strong> or <strong>Server &rarr; Users</strong> to grant specific users Git permissions:</p>
                    <ul>
                        <li><code>git.read</code> &mdash; View status, history, diffs</li>
                        <li><code>git.pull</code> &mdash; Pull from remote</li>
                        <li><code>git.push</code> &mdash; Push to remote</li>
                        <li><code>git.commit</code> &mdash; Stage, unstage, commit, discard</li>
                        <li><code>git.manage-branches</code> &mdash; Create, switch, delete branches</li>
                        <li><code>git.manage-repository</code> &mdash; Connect/disconnect repos, manage stash</li>
                        <li><code>git.manage-gitignore</code> &mdash; Edit .gitignore</li>
                        <li><code>git.revert</code> &mdash; Revert commits</li>
                        <li><code>git.force-reset</code> &mdash; Hard reset to remote</li>
                    </ul>

                    <h4>6. How It Works</h4>
                    <ol>
                        <li>A user links their GitHub account (PAT or OAuth)</li>
                        <li>They open a server and click the <strong>GitHub</strong> tab</li>
                        <li>They connect a repository (clone or keep existing files)</li>
                        <li>They can then stage, commit, push, pull, manage branches, and view history &mdash; all from the panel</li>
                    </ol>

                    <h4>7. Environment Variables</h4>
                    <table class="table table-condensed" style="font-size:12px;">
                        <tr><td><code>PTERODACTYL_GIT_ENABLED</code></td><td>Master toggle (default: <code>true</code>)</td></tr>
                        <tr><td><code>PTERODACTYL_GIT_DATA_DIRECTORY</code></td><td>Git data root (default: <code>/var/lib/pterodactyl</code>)</td></tr>
                        <tr><td><code>GITHUB_OAUTH_ENABLED</code></td><td>OAuth toggle (default: <code>false</code>)</td></tr>
                        <tr><td><code>GITHUB_OAUTH_CLIENT_ID</code></td><td>OAuth App client ID</td></tr>
                        <tr><td><code>GITHUB_OAUTH_CLIENT_SECRET</code></td><td>OAuth App client secret</td></tr>
                        <tr><td><code>GITHUB_OAUTH_REDIRECT_URI</code></td><td>OAuth callback URL</td></tr>
                    </table>

                    <h4>8. Troubleshooting</h4>
                    <ul>
                        <li><strong>Git tab not showing?</strong> &mdash; Ensure <code>git.enabled</code> is true and the user has <code>git.*</code> permissions.</li>
                        <li><strong>Clone fails?</strong> &mdash; Check the <a href="{{ route('admin.github.audit-log') }}">Audit Log</a> for error details.</li>
                        <li><strong>Push/Pull fails?</strong> &mdash; The PAT may have expired or lack <code>repo</code> scope. Ask the user to reconnect their GitHub account.</li>
                        <li><strong>Sudoers error?</strong> &mdash; Re-run the installer with <code>--yes-sudoers</code> to re-create the sudoers rule.</li>
                        <li><strong>No "GitHub" link under User?</strong> &mdash; Themed panels (e.g. Arix) replace the stock account navigation and may not show route-derived links. Navigate to <code>{{ url('/account/github') }}</code> directly or add a custom nav item pointing to that URL.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
