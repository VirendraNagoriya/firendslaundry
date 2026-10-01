<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked external account, e.g. one Google sign-in.
 *
 * This is a table rather than a pair of columns on `users` because a person
 * may link more than one provider over time. The unique index on
 * (provider, provider_id) is the actual guarantee that one Google account
 * can only ever belong to one user here: even two simultaneous sign-in
 * attempts cannot attach the same Google identity to two different people.
 */
class OAuthAccount extends Model
{
    use HasFactory;

    /**
     * Stated rather than inferred. The pluraliser would otherwise read
     * "OAuthAccount" as one word starting with a vowel and produce
     * `o_auth_accounts`, which is not the table the migration creates.
     */
    protected $table = 'oauth_accounts';

    protected $fillable = [
        'user_id',
        'provider',
        'provider_id',
        'avatar',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
