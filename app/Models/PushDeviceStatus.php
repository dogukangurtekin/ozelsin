<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PushDeviceStatus extends Model
{
    protected $fillable = [
        'user_id',
        'endpoint',
        'permission',
        'platform',
        'is_pwa',
        'user_agent',
        'last_seen_at',
    ];

    protected $casts = [
        'is_pwa' => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

