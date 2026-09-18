<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A Steam user who has logged in, keyed by steam id; auth lives in the session, not a Laravel guard. */
class User extends Model
{
    protected $primaryKey = 'steam_id';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    /** Casts the Steam timestamps to dates and the privacy flag to a boolean. */
    protected function casts(): array
    {
        return [
            'steam_created_at' => 'datetime',
            'last_login_at' => 'datetime',
            'library_synced_at' => 'datetime',
            'library_private' => 'boolean',
        ];
    }
}
