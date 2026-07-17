<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlocklistTerm extends Model
{
    protected $fillable = ['user_id', 'term', 'match_type'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
