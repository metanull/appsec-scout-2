<?php

namespace App\Credentials;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Credential extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'owner_user_id',
        'integration_key',
        'value',
        'description',
        'last_tested_at',
        'last_tested_ok',
        'last_tested_error',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'value' => 'encrypted',
        'last_tested_at' => 'datetime',
        'last_tested_ok' => 'boolean',
    ];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /**
     * The decrypted stored value, or null when nothing is stored.
     *
     * @throws CredentialDecryptionException when a stored value cannot be decrypted
     *                                       (typically after an APP_KEY change)
     */
    public function decryptedValue(): ?string
    {
        $encrypted = $this->getRawOriginal('value');

        if (! is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            $decrypted = Crypt::decrypt($encrypted, false);
        } catch (DecryptException) {
            throw CredentialDecryptionException::forKey($this->integration_key);
        }

        return is_string($decrypted) ? $decrypted : null;
    }
}
