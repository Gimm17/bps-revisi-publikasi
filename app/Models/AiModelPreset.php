<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiModelPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'base_url',
        'model',
        'api_key',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
