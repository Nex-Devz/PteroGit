<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property string|null $repository
 * @property string $operation
 * @property string|null $branch
 * @property string|null $commit_sha
 * @property string $status
 * @property string|null $output
 * @property string|null $error
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Pterodactyl\Models\Server $server
 * @property \Pterodactyl\Models\User $user
 */
class GitOperation extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'git_operations';

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
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'user_id' => 'required|numeric|exists:users,id',
        'operation' => 'required|string|max:191',
        'branch' => 'nullable|string|max:191',
        'commit_sha' => 'nullable|string|max:191',
        'status' => 'required|string|max:32',
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