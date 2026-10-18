<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationReference extends Model
{
    protected $fillable = [
        'check_run_id',
        'series',
        'jurisdiction',
        'year',
        'source_url',
        'metadata',
        'provenance',
        'access_status',
        'read_status',
        'scope',
        'artifact_manifest',
        'fetched_at',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'artifact_manifest' => 'array',
            'year' => 'integer',
            'fetched_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(PublicationCheckRun::class, 'check_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
