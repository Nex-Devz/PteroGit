<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks the personal git worktree allocated to a sub-user of a server.
 *
 * Every sub-user who interacts with the GitHub integration gets their own git
 * worktree (separate checkout on their own branch) so their working directory
 * and branch are isolated from the server owner's shared checkout and from
 * other sub-users.
 *
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property string $branch
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Pterodactyl\Models\Server $server
 * @property \Pterodactyl\Models\User $user
 */
class GitWorktree extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'git_worktree';

    /**
     * The table associated with the model.
     */
    protected $table = 'git_worktrees';

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'server_id' => 'int',
        'user_id' => 'int',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'user_id' => 'required|numeric|exists:users,id',
        'branch' => 'required|string|max:191',
    ];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}