<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExaminerLearning extends Model
{
    protected $fillable = [
        'key',
        'rule_code',
        'section',
        'kind',
        'source',
        'status',
        'topic',
        'observed_example',
        'reason',
        'instruction',
        'finding_id',
        'publication_id',
        'hits',
        'created_by_name',
        'created_by',
    ];

    protected $attributes = [
        'hits' => 0,
        'kind' => 'false_positive',
        'source' => 'default',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'hits' => 'integer',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(Publication::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
