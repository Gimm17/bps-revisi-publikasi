<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'publication_page_id',
        'ai_agent_id',
        'status',
        'result',
        'agreement',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'result' => 'array',
            'agreement' => 'array',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(PublicationPage::class, 'publication_page_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
