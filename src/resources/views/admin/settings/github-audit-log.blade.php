@extends('layouts.admin')
@include('partials/admin.settings.nav', ['activeTab' => 'github'])

@section('title')
    Git Operation Audit Log
@endsection

@section('content-header')
    <h1>Git Audit Log<small>View all Git operations performed across every server.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.settings') }}">Settings</a></li>
        <li><a href="{{ route('admin.settings.github') }}">GitHub</a></li>
        <li class="active">Audit Log</li>
    </ol>
@endsection

@section('content')
    @yield('settings::nav')
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Operations</h3>
                    <div class="box-tools pull-right">
                        <a href="{{ route('admin.settings.github') }}" class="btn btn-sm btn-default">Back to Settings</a>
                    </div>
                </div>
                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Server</th>
                                <th>User</th>
                                <th>Operation</th>
                                <th>Branch</th>
                                <th>Repository</th>
                                <th>Status</th>
                                <th>Commit</th>
                                <th>Started</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($operations as $op)
                                <tr class="@if($op->status === 'failed') danger @elseif($op->status === 'success') success @endif">
                                    <td>{{ $op->id }}</td>
                                    <td>
                                        @if($op->server)
                                            <a href="{{ route('admin.servers.show', $op->server_id) }}">{{ $op->server->name ?? 'Server #' . $op->server_id }}</a>
                                        @else
                                            <span class="text-muted">#{{ $op->server_id }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $op->user->username ?? 'User #' . $op->user_id }}</td>
                                    <td><code>{{ $op->operation }}</code></td>
                                    <td>{{ $op->branch ?? '—' }}</td>
                                    <td>{{ $op->repository ?? '—' }}</td>
                                    <td>
                                        @if($op->status === 'success')
                                            <span class="label label-success">Success</span>
                                        @elseif($op->status === 'failed')
                                            <span class="label label-danger">Failed</span>
                                        @else
                                            <span class="label label-warning">Running</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if($op->commit_sha)
                                            <code>{{ substr($op->commit_sha, 0, 7) }}</code>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $op->started_at?->diffForHumans() ?? '—' }}</td>
                                    <td>
                                        @if($op->started_at && $op->completed_at)
                                            {{ $op->started_at->diffInSeconds($op->completed_at) }}s
                                        @else
                                            —
                                        @endif
                                    </td>
                                </tr>
                                @if($op->error)
                                    <tr class="danger">
                                        <td colspan="10" style="padding-left: 2em;">
                                            <small class="text-red"><strong>Error:</strong> {{ Str::limit($op->error, 300) }}</small>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="10" class="text-center text-muted">No operations recorded yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="box-footer clearfix">
                    {{ $operations->links() }}
                </div>
            </div>
        </div>
    </div>
@endsection
