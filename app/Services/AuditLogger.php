<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Publication;
use App\Models\User;

class AuditLogger
{
    public static function record(string $action, ?Publication $pub = null, array $details = [], ?User $user = null): void
    {
        AuditEvent::create(['action' => $action, 'publication_id' => $pub?->id, 'user_id' => $user?->id ?? auth()->id(), 'details' => $details]);
    }
}
