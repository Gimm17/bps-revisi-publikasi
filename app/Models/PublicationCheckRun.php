<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PublicationCheckRun extends Model
{
    use HasUuids;

    protected $fillable = [
        'publication_id',
        'requested_by',
        'mode',
        'baseline_rule_version',
        'target_rule_version',
        'prompt_version',
        'pdf_sha256',
        'rules_path',
        'rules_hash',
        'scope',
        'status',
        'input_revision',
        'started_at',
        'completed_at',
        'summary',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'summary' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'input_revision' => 'integer',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PublicationCheck::class, 'check_run_id');
    }

    public function references(): HasMany
    {
        return $this->hasMany(PublicationReference::class, 'check_run_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(FindingAssessment::class, 'check_run_id');
    }
}
