<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $github_user_id
 * @property string $username
 * @property string|null $avatar_url
 * @property string $access_token_encrypted
 * @property string|null $refresh_token_encrypted
 * @property \Carbon\Carbon|null $token_expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Pterodactyl\Models\User $user
 * @property \Pterodactyl\Models\ServerGitRepository[] $repositories
 */
class GithubAccount extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'github_account';

    /**
     * Maximum number of GitHub accounts a single user may link.
     */
    public const MAX_ACCOUNTS_PER_USER = 5;

    /**
     * The table associated with the model.
     */
    protected $table = 'github_accounts';

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'user_id' => 'int',
        'github_user_id' => 'string',
        'token_expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public static array $validationRules = [
        'user_id' => 'required|numeric|exists:users,id',
        'provider' => 'required|string|max:32',
        'github_user_id' => 'required|string|max:64',
        'username' => 'required|string|max:191',
        'avatar_url' => 'nullable|string',
        'access_token_encrypted' => 'required|string',
        'refresh_token_encrypted' => 'nullable|string',
        'token_expires_at' => 'nullable',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function repositories(): HasMany
    {
        return $this->hasMany(ServerGitRepository::class, 'github_account_id');
    }

    /**
     * Returns the decrypted access token for this account.
     */
    public function accessToken(): string
    {
        return Crypt::decryptString($this->access_token_encrypted);
    }
}