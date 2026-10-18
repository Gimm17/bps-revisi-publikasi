<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PublicationCheck extends Model
{
    protected $fillable = [
        'check_run_id',
        'check_type',
        'input_hash',
        'execution_status',
        'decision',
        'result',
        'evidences',
        'attempts',
        'lease_until',
        'error_code',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'evidences' => 'array',
            'attempts' => 'integer',
            'lease_until' => 'datetime',
        ];
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(PublicationCheckRun::class, 'check_run_id');
    }
}
