<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property int $github_account_id
 * @property string $repository_id
 * @property string $repository_full_name
 * @property string $remote_url
 * @property string $default_branch
 * @property string|null $current_branch
 * @property string $working_directory
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Pterodactyl\Models\Server $server
 * @property \Pterodactyl\Models\GithubAccount $githubAccount
 */
class ServerGitRepository extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'server_git_repository';

    /**
     * The table associated with the model.
     */
    protected $table = 'server_git_repositories';

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'server_id' => 'int',
        'github_account_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'github_account_id' => 'required|numeric|exists:github_accounts,id',
        'repository_id' => 'required|string|max:191',
        'repository_full_name' => 'required|string|max:191',
        'remote_url' => 'required|string|max:500',
        'default_branch' => 'required|string|max:191',
        'current_branch' => 'nullable|string|max:191',
        'working_directory' => 'required|string|max:500',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function githubAccount(): BelongsTo
    {
        return $this->belongsTo(GithubAccount::class);
    }
}