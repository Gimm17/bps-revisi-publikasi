<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FindingAssessment extends Model
{
    protected $fillable = [
        'finding_id',
        'check_run_id',
        'proposed_decision',
        'reason',
        'evidences',
        'reviewer_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'evidences' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function checkRun(): BelongsTo
    {
        return $this->belongsTo(PublicationCheckRun::class, 'check_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
