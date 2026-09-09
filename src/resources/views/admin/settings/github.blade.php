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
    <div class="row">
        <div class="col-xs-12">
            <form action="{{ route('admin.settings.github') }}" method="POST">
                <div class="box">
                    <div class="box-header with-border">
                        <h3 class="box-title">Module Status</h3>
                    </div>
                    <div class="box-body">
                        <div class="row">
                            <div class="form-group col-md-6">
                                <label class="control-label">GitHub Integration</label>
                                <div>
                                    <select class="form-control" name="pterodactyl:git:enabled">
                                        <option value="true">Enabled</option>
                                        <option value="false" @if(!old('pterodactyl:git:enabled', $enabled)) selected @endif>Disabled</option>
                                    </select>
                                    <p class="text-muted small">When disabled, the GitHub module is completely hidden from non-admin users. Root administrators will still see the navigation with a maintenance notice so settings can be verified and re-enabled.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">OAuth2 Sign-In</label>
                                <div>
                                    <select class="form-control" name="pterodactyl:git:oauth:enabled">
                                        <option value="false">Disabled</option>
                                        <option value="true" @if(old('pterodactyl:git:oauth:enabled', $oauthEnabled)) selected @endif>Enabled</option>
                                    </select>
                                    <p class="text-muted small">Allow users to link their GitHub account using a 1-click OAuth2 browser login in addition to personal access tokens. Requires an OAuth App registered on GitHub.</p>
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
                            <div class="col-xs-12">
                                <p class="text-muted">Register an OAuth App in GitHub under <strong>Settings &rarr; Developer settings &rarr; OAuth Apps &rarr; New OAuth App</strong>, then paste the Client ID and Secret below.</p>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Client ID</label>
                                <div>
                                    <input type="text" class="form-control" name="pterodactyl:git:oauth:client_id" value="{{ old('pterodactyl:git:oauth:client_id', $clientId) }}" placeholder="e.g. Ov23li..." />
                                    <p class="text-muted small">The Client ID provided by GitHub for your OAuth App.</p>
                                </div>
                            </div>
                            <div class="form-group col-md-6">
                                <label class="control-label">Client Secret</label>
                                <div>
                                    <input type="password" class="form-control" name="pterodactyl:git:oauth:client_secret" placeholder="{{ $clientSecret ? '•••••••••••••••• (saved)' : 'Enter client secret' }}" />
                                    <p class="text-muted small">The Client Secret is stored encrypted. Leave blank to keep the current secret, or enter <code>!e</code> to clear it.</p>
                                </div>
                            </div>
                            <div class="form-group col-xs-12">
                                <label class="control-label">Authorization Callback URL</label>
                                <div>
                                    <input type="text" class="form-control" name="pterodactyl:git:oauth:redirect_uri" value="{{ old('pterodactyl:git:oauth:redirect_uri', $redirectUri) }}" />
                                    <p class="text-muted small">Register this exact URL as the <strong>Authorization callback URL</strong> in your GitHub OAuth App settings.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="box box-primary">
                    <div class="box-footer">
                        {{ csrf_field() }}
                        <button type="submit" name="_method" value="PATCH" class="btn btn-sm btn-primary pull-right">Save</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
@endsection