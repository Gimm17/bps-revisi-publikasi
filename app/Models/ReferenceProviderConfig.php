<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferenceProviderConfig extends Model
{
    protected $fillable = [
        'provider',
        'endpoint',
        'api_key',
        'is_ready',
        'tested_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_ready' => 'boolean',
            'tested_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
